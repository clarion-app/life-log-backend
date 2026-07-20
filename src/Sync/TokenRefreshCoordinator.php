<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Serialises renewAccess() calls against every other refresh for the same
 * account, so a scheduled sync and a user-triggered action never both call
 * the provider at once.
 *
 * Most providers issue single-use refresh tokens and rotate on each use.
 * Two concurrent refreshes mean one succeeds and the other presents an
 * already-consumed token, which many providers answer by revoking the whole
 * grant — the race becomes permanent data loss, not a wasted call. That is
 * why this lock blocks (research §9) where SyncLock deliberately does not:
 * the loser here needs the token the winner is about to produce, rather than
 * duplicating wasted work.
 *
 * Distinct from SyncLock: that one is held for a whole run (up to 900s) and
 * never blocks. This one is held for one HTTP call (30s) and blocks for up
 * to 10s — reusing SyncLock would either make a user-triggered refresh wait
 * behind a running sync, or deadlock a sync against its own lock when it
 * needs to refresh mid-run.
 */
class TokenRefreshCoordinator
{
    /**
     * Renew access for the given account, deduplicating against any other
     * caller currently refreshing the same account.
     *
     * @throws HealthServiceFailure ServiceUnavailable if no other holder
     *         releases the lock within the wait window
     */
    public function renew(ConnectedAccount $account, ExternalHealthService $service): RenewalResult
    {
        $lockName = sprintf('life-log:token-refresh:%s', $account->id);
        $lockSeconds = (int) config('life-log.token_refresh_lock_seconds', 30);
        $waitSeconds = (int) config('life-log.token_refresh_wait_seconds', 10);

        $lock = Cache::lock($lockName, $lockSeconds);

        try {
            return $lock->block($waitSeconds, function () use ($account, $service) {
                // Re-read under the lock — another holder may have just
                // refreshed while this caller was waiting. Using their
                // result instead of refreshing again is the whole point.
                $authorization = AccountAuthorization::where('connected_account_id', $account->id)->first();

                if ($authorization !== null && $authorization->isUsable(CarbonImmutable::now()->addSeconds(60))) {
                    return RenewalResult::renewed(
                        $authorization->expires_at !== null
                            ? CarbonImmutable::instance($authorization->expires_at)
                            : null,
                    );
                }

                $result = $service->renewAccess($account->user_id);

                if ($result->renewed && $authorization !== null) {
                    DB::transaction(function () use ($authorization, $result) {
                        $authorization->expires_at = $result->expiresAt;
                        $authorization->refreshed_at = CarbonImmutable::now();
                        $authorization->save();
                    });
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            throw HealthServiceFailure::serviceUnavailable(
                'Timed out waiting for another token refresh to finish for this account.'
            );
        }
    }
}
