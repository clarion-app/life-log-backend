<?php

namespace ClarionApp\LifeLogBackend\Jobs;

use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\BackfillRunner;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Backfill a single connected account.
 *
 * ShouldBeUnique with account-scoped uniqueId — one backfill per account at a time.
 * Uses the shared SyncLock (same as SyncConnectedAccountJob), so incremental and
 * backfill cadences never run concurrently for the same account.
 */
class BackfillConnectedAccountJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int TIMEOUT = 1200; // seconds; backfill can take longer than incremental

    public int $tries = 1;       // retry is the persisted ladder, not the queue
    public int $timeout = self::TIMEOUT;

    /**
     * @param  string  $connectedAccountId  UUID of the ConnectedAccount
     * @param  string|null  $type  Optional single type to backfill
     */
    public function __construct(
        public readonly string $connectedAccountId,
        public readonly ?string $type = null,
    ) {
    }

    /**
     * Unique ID for the job — prevents duplicate in-flight jobs for the same account.
     */
    public function uniqueId(): string
    {
        return 'backfill:' . $this->connectedAccountId;
    }

    public function handle(BackfillRunner $runner): void
    {
        $account = ConnectedAccount::find($this->connectedAccountId);

        if (!$account) {
            // Account was deleted or soft-deleted; nothing to backfill.
            return;
        }

        $type = null;
        if ($this->type !== null) {
            // Resolve type from string
            if (enum_exists(MeasurementType::class)) {
                foreach (MeasurementType::cases() as $case) {
                    if ($case->value === $this->type) {
                        $type = $case;
                        break;
                    }
                }
            }
            if ($type === null && enum_exists(SessionType::class)) {
                foreach (SessionType::cases() as $case) {
                    if ($case->value === $this->type) {
                        $type = $case;
                        break;
                    }
                }
            }
        }

        $runner->run($account, $type);
    }
}
