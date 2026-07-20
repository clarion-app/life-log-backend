<?php

namespace ClarionApp\LifeLogBackend\Commands;

use ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Illuminate\Console\Command;

class SyncAccountCommand extends Command
{
    protected $signature = 'life-log:sync-account {id : Connected account UUID} {--reset : Reset sync health before dispatching}';

    protected $description = 'Trigger an on-demand sync for a single connected account';

    public function handle(): int
    {
        $id = $this->argument('id');
        $reset = $this->option('reset');

        $account = ConnectedAccount::find($id);

        if (! $account) {
            $this->error("Connected account {$id} not found.");

            return self::FAILURE;
        }

        if ($reset) {
            $account->loadMissing('syncState');
            $account->resetSyncHealth();
            $this->info("Reset sync health for account {$id}.");
        }

        // Dispatch with no jitter delay (on-demand)
        SyncConnectedAccountJob::dispatch($id, SyncTrigger::OnDemand);

        $this->info("Queued on-demand sync for account {$id}.");

        return self::SUCCESS;
    }
}
