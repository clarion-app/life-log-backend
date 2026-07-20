<?php

namespace ClarionApp\LifeLogBackend\Jobs;

use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\AccountSyncRunner;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncConnectedAccountJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int TIMEOUT = 600; // seconds; must stay under sync_lock_seconds

    public int $tries = 1;       // retry is the persisted ladder, not the queue
    public int $timeout = self::TIMEOUT;

    /**
     * @param  string  $connectedAccountId  UUID of the ConnectedAccount
     * @param  SyncTrigger  $trigger  scheduled or on_demand
     */
    public function __construct(
        public readonly string $connectedAccountId,
        public readonly SyncTrigger $trigger,
    ) {
    }

    /**
     * Unique ID for the job — prevents duplicate in-flight jobs for the same account.
     */
    public function uniqueId(): string
    {
        return $this->connectedAccountId;
    }

    public function handle(AccountSyncRunner $runner): void
    {
        $account = ConnectedAccount::find($this->connectedAccountId);

        if (! $account) {
            // Account was deleted or soft-deleted; nothing to sync.
            return;
        }

        $runner->run($account, $this->trigger);
    }
}
