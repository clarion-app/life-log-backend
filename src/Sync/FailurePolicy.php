<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Maps a HealthServiceFailure to a FailureResponse.
 *
 * Uses an exhaustive match() over FailureKind (no default arm) so that a
 * seventh case added upstream raises UnhandledMatchError rather than falling
 * into handling written before it existed.
 */
class FailurePolicy
{
    public function __construct(
        private ServiceCredentialProvider $credentials,
    ) {
    }

    /**
     * Apply the policy to a failure.
     *
     * @return FailureResponse what the runner should do next
     */
    public function apply(
        AccountSyncState $state,
        HealthServiceFailure $failure,
        CarbonImmutable $now,
    ): FailureResponse {
        // Pre-increment: the current failure count is the index into the ladder
        // for *this* failure attempt. After counting, the state will have one
        // more failure, so the next attempt uses the next rung.
        $ladderIndex = $state->consecutive_failures ?? 0;
        $ladderMinutes = $this->ladderMinutes($ladderIndex);
        $maxFailures = (int) config('life-log.sync_max_consecutive_failures', 5);

        $response = match ($failure->kind) {
            FailureKind::ServiceUnavailable => $this->serviceUnavailable(
                $state, $now, $ladderMinutes, $maxFailures
            ),
            FailureKind::RateLimited => $this->rateLimited(
                $state, $failure, $now, $ladderMinutes, $maxFailures
            ),
            FailureKind::AccessExpired => $this->accessExpired($state),
            FailureKind::AccessRevoked => $this->accessRevoked(),
            FailureKind::CredentialsRejected => $this->credentialsRejected(
                $state, $now, $ladderMinutes, $maxFailures
            ),
            FailureKind::InvalidRequest => $this->invalidRequest(
                $state, $now, $ladderMinutes, $maxFailures
            ),
        };

        // CredentialsRejected logs at error — our credentials, not the user's.
        if ($failure->kind === FailureKind::CredentialsRejected) {
            Log::error(
                sprintf(
                    'Health service credentials rejected for service [%s] on account [%s]. '
                    . 'This affects all users of this service — operator action required.',
                    $state->connectedAccount?->external_service ?? 'unknown',
                    $state->connected_account_id,
                )
            );
        }

        return $response;
    }

    private function serviceUnavailable(
        AccountSyncState $state,
        CarbonImmutable $now,
        int $ladderMinutes,
        int $maxFailures,
    ): FailureResponse {
        $countsAsFailure = true;
        $flagsImmediately = ($state->consecutive_failures + 1) >= $maxFailures;

        return new FailureResponse(
            countsAsFailure: $countsAsFailure,
            flagsImmediately: $flagsImmediately,
            clearsCursor: false,
            attemptsRenewal: false,
            nextAttemptAt: $now->addMinutes($ladderMinutes),
        );
    }

    private function rateLimited(
        AccountSyncState $state,
        HealthServiceFailure $failure,
        CarbonImmutable $now,
        int $ladderMinutes,
        int $maxFailures,
    ): FailureResponse {
        // Use max(ladder[n], retryAfterSeconds) as a floor
        $waitSeconds = $ladderMinutes * 60;
        if ($failure->retryAfterSeconds !== null && $failure->retryAfterSeconds > $waitSeconds) {
            $waitSeconds = $failure->retryAfterSeconds;
        }

        // FR-018: rate limiting is a yield, not a connection failure. It does
        // not advance the ladder and it never flags the connection — the
        // provider is telling us to come back later, which says nothing about
        // whether the connection is healthy. Counting it would let a busy
        // instance talk itself into asking the user to reconnect a connection
        // that was working the whole time.
        return new FailureResponse(
            countsAsFailure: false,
            flagsImmediately: false,
            clearsCursor: false,
            attemptsRenewal: false,
            nextAttemptAt: $now->addSeconds($waitSeconds),
        );
    }

    private function accessExpired(AccountSyncState $state): FailureResponse
    {
        // Request renewal; on success the page is retried and no failure is counted.
        // On decline (handled by the runner), it will flag.
        return new FailureResponse(
            countsAsFailure: false,
            flagsImmediately: false,
            clearsCursor: false,
            attemptsRenewal: true,
            nextAttemptAt: null, // retry immediately after renewal
        );
    }

    private function accessRevoked(): FailureResponse
    {
        // Terminal: flag immediately, clear cursor, no ladder. The grant can
        // no longer be renewed — retrying is futile, so this skips the
        // ladder the same way a stale credential version does (FR-017).
        return new FailureResponse(
            countsAsFailure: false,
            flagsImmediately: true,
            clearsCursor: true,
            attemptsRenewal: false,
            nextAttemptAt: null,
            needsAttentionReason: NeedsAttentionReason::AuthorizationUnrenewable,
        );
    }

    private function credentialsRejected(
        AccountSyncState $state,
        CarbonImmutable $now,
        int $ladderMinutes,
        int $maxFailures,
    ): FailureResponse {
        // A rejection under a credential_version older than the service's
        // current one is not an ordinary failure — the secret was rotated
        // out from under this connection. Burning the backoff ladder here
        // would take ~13 hours to surface something a version comparison
        // already knows on the first attempt (research §10).
        if ($this->isStaleCredentialVersion($state)) {
            return new FailureResponse(
                countsAsFailure: false,
                flagsImmediately: true,
                clearsCursor: false,
                attemptsRenewal: false,
                nextAttemptAt: null,
                needsAttentionReason: NeedsAttentionReason::CredentialRotated,
            );
        }

        $countsAsFailure = true;
        $flagsImmediately = ($state->consecutive_failures + 1) >= $maxFailures;

        return new FailureResponse(
            countsAsFailure: $countsAsFailure,
            flagsImmediately: $flagsImmediately,
            clearsCursor: false,
            attemptsRenewal: false,
            nextAttemptAt: $now->addMinutes($ladderMinutes),
        );
    }

    /**
     * Whether this account's authorization was granted under a credential
     * version older than the service's current one.
     *
     * Fetches the credential through ServiceCredentialProvider on every
     * call rather than caching it on $this — the same obligation 7 rule
     * implementers of ExternalHealthService follow, for the same reason:
     * this object can outlive a single credential rotation.
     */
    private function isStaleCredentialVersion(AccountSyncState $state): bool
    {
        $account = $state->connectedAccount;

        if ($account === null) {
            return false;
        }

        $authorization = $account->authorization;

        if ($authorization === null) {
            return false;
        }

        $credential = $this->credentials->find($account->external_service);

        if ($credential === null) {
            return false;
        }

        return $authorization->credential_version < $credential->version;
    }

    private function invalidRequest(
        AccountSyncState $state,
        CarbonImmutable $now,
        int $ladderMinutes,
        int $maxFailures,
    ): FailureResponse {
        $countsAsFailure = true;
        $flagsImmediately = ($state->consecutive_failures + 1) >= $maxFailures;

        return new FailureResponse(
            countsAsFailure: $countsAsFailure,
            flagsImmediately: $flagsImmediately,
            clearsCursor: true,
            attemptsRenewal: false,
            nextAttemptAt: $now->addMinutes($ladderMinutes),
        );
    }

    /**
     * Get the backoff minutes for a given ladder index.
     *
     * Uses the pre-increment counter: consecutive_failures is the index.
     * Clamps to the last element for any higher count.
     */
    private function ladderMinutes(int $index): int
    {
        $ladder = config('life-log.sync_backoff_minutes', [60, 240, 240, 240]);
        $maxIndex = count($ladder) - 1;

        return (int) $ladder[min($index, $maxIndex)];
    }
}
