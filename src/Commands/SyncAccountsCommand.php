<?php

namespace ClarionApp\LifeLogBackend\Commands;

use ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class SyncAccountsCommand extends Command
{
    protected $signature = 'life-log:sync-accounts';

    protected $description = 'Sweep for due connected accounts and dispatch sync jobs';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $jitterSeconds = (int) config('life-log.sync_jitter_seconds', 900);
        $dispatched = 0;

        // Due-account query: sync_state = normal, AND (no state row OR gate null/past)
        // The OR arms are explicitly grouped (load-bearing per data-model.md)
        ConnectedAccount::query()
            ->where('sync_state', 'normal')
            ->where(function ($q) use ($now) {
                $q->whereDoesntHave('syncState')
                    ->orWhereHas('syncState', function ($q) use ($now) {
                        $q->where(function ($q) use ($now) {
                            $q->whereNull('next_attempt_at')
                                ->orWhere('next_attempt_at', '<=', $now);
                        });
                    });
            })
            ->chunkById(100, function ($accounts) use ($jitterSeconds, &$dispatched) {
                foreach ($accounts as $account) {
                    // Deterministic jitter: crc32(account_id) % jitter_seconds
                    $delay = abs(crc32($account->id)) % $jitterSeconds;

                    $job = new SyncConnectedAccountJob($account->id, SyncTrigger::Scheduled);
                    $job->delay = $delay;
                    Queue::push($job);

                    $dispatched++;
                }
            }, 'id');

        $this->info(sprintf('Dispatched %d sync jobs.', $dispatched));

        return self::SUCCESS;
    }
}
