<?php

namespace ClarionApp\LifeLogBackend\Connection;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\SyncAttempt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Disconnects a connected account: best-effort revoke at the provider, then
 * unconditional local teardown (FR-021, FR-022).
 *
 * The two steps are deliberately sequenced and deliberately independent.
 * Revocation is attempted first, but whatever happens to it — success,
 * refusal, an unreachable provider, a thrown exception — never blocks the
 * local teardown that follows. A user who asks to disconnect gets their
 * connection gone locally regardless of what the provider does; the only
 * thing that varies is whether the caller can be told revocation was
 * confirmed.
 *
 * Local teardown removes AccountAuthorization, AccountSyncState, and
 * SyncAttempt rows outright and soft-deletes the ConnectedAccount itself
 * (it is bridged, so a tombstone rather than a hard delete). Health data
 * already ingested — HealthMetric, RawMeasurement, HealthSession,
 * RawHealthSession — is keyed by user and service, not by connection, and
 * this class never touches it (FR-023).
 */
final class AccountDisconnector
{
    /**
     * @param  ?ExternalHealthService  $service  null when the service is not
     *         registered on this node — revocation is skipped and treated
     *         the same as an unreachable provider.
     */
    public function disconnect(ConnectedAccount $account, ?ExternalHealthService $service): DisconnectResult
    {
        $remoteReachable = false;

        if ($service !== null) {
            try {
                $remoteReachable = $service->disconnect($account->user_id)->remoteReachable;
            } catch (Throwable) {
                // Best-effort revocation: an unreachable or misbehaving
                // provider never blocks local teardown (FR-022).
                $remoteReachable = false;
            }
        }

        DB::transaction(function () use ($account) {
            AccountAuthorization::query()
                ->where('connected_account_id', $account->id)
                ->delete();

            SyncAttempt::query()
                ->where('connected_account_id', $account->id)
                ->delete();

            AccountSyncState::query()
                ->where('connected_account_id', $account->id)
                ->delete();

            $account->delete();
        });

        return new DisconnectResult(true, $remoteReachable);
    }
}
