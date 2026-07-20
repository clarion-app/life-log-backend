<?php

namespace ClarionApp\LifeLogBackend\Commands;

use ClarionApp\LifeLogBackend\Jobs\BackfillConnectedAccountJob;
use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sweep for accounts with incomplete backfill types and dispatch backfill jobs.
 *
 * Runs on a separate cadence from sync-accounts (every 15 minutes, jittered,
 * withoutOverlapping). Selects accounts that have at least one granted type
 * that is not yet marked complete.
 */
class BackfillAccountsCommand extends Command
{
    protected $signature = 'life-log:backfill-accounts';

    protected $description = 'Sweep for accounts needing backfill and dispatch backfill jobs';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $jitterSeconds = (int) config('life-log.backfill_jitter_seconds', 600);
        $dispatched = 0;

        // Find accounts that have at least one granted type that is incomplete
        // This is the union of: accounts with no backfill state at all, OR
        // accounts with at least one incomplete backfill state
        $accountIds = ConnectedAccount::query()
            ->where('sync_state', '!=', 'disconnected')
            ->where(function ($q) {
                // Has at least one incomplete backfill state
                $q->whereHas('backfillStates', function ($q) {
                    $q->whereNull('complete_at');
                });
            })
            ->pluck('id');

        // Also include accounts with NO backfill state (first-time backfill)
        $noStateIds = ConnectedAccount::query()
            ->where('sync_state', '!=', 'disconnected')
            ->doesntHave('backfillStates')
            ->pluck('id');

        $allAccountIds = $accountIds->merge($noStateIds)->unique();

        $allAccountIds->each(function ($accountId) use ($jitterSeconds, &$dispatched) {
            // Deterministic jitter: crc32(account_id) % jitter_seconds
            $delay = abs(crc32($accountId)) % $jitterSeconds;

            BackfillConnectedAccountJob::dispatch($accountId)
                ->delay($delay);

            $dispatched++;
        });

        $this->info(sprintf('Dispatched %d backfill jobs.', $dispatched));

        return self::SUCCESS;
    }
}
