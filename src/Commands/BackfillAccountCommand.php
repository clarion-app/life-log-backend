<?php

namespace ClarionApp\LifeLogBackend\Commands;

use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\BackfillRunner;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Illuminate\Console\Command;

/**
 * Backfill a single connected account from the CLI.
 *
 * Supports --type to target a specific type and --reset to clear backfill
 * state (the only path that re-walks history).
 */
class BackfillAccountCommand extends Command
{
    protected $signature = 'life-log:backfill-account {account} {--type=} {--reset}';

    protected $description = 'Backfill historical data for a connected account';

    public function handle(BackfillRunner $runner): int
    {
        $account = ConnectedAccount::find($this->argument('account'));

        if (!$account) {
            $this->error("Account {$this->argument('account')} not found.");
            return self::FAILURE;
        }

        if ($this->option('reset')) {
            // Clear backfill state for this account (or specific type)
            $type = $this->option('type');
            if ($type) {
                AccountBackfillState::where([
                    'connected_account_id' => $account->id,
                    'type' => $type,
                ])->delete();
                $this->info("Cleared backfill state for type '{$type}'.");
            } else {
                AccountBackfillState::where(
                    'connected_account_id',
                    $account->id,
                )->delete();
                $this->info('Cleared all backfill state for this account.');
            }
        }

        // Resolve type if specified
        $type = null;
        $typeString = $this->option('type');
        if ($typeString) {
            if (enum_exists(MeasurementType::class)) {
                foreach (MeasurementType::cases() as $case) {
                    if ($case->value === $typeString) {
                        $type = $case;
                        break;
                    }
                }
            }
            if ($type === null && enum_exists(SessionType::class)) {
                foreach (SessionType::cases() as $case) {
                    if ($case->value === $typeString) {
                        $type = $case;
                        break;
                    }
                }
            }
            if ($type === null) {
                $this->error("Unknown type: {$typeString}");
                return self::FAILURE;
            }
        }

        $this->info("Starting backfill for account {$account->id}...");
        $result = $runner->run($account, $type);

        $this->info(sprintf(
            'Backfill %s: %d pages, %d requests in %s',
            match ($result->outcome) {
                SyncOutcome::Success => 'succeeded',
                SyncOutcome::Partial => 'partially complete',
                SyncOutcome::Skipped => 'skipped',
                SyncOutcome::Failure => 'failed',
            },
            $result->pagesFetched,
            $result->requestsUsed,
            $result->startedAt->diffForHumans($result->finishedAt),
        ));

        foreach ($result->typeOutcomes as $typeValue => $outcome) {
            if ($outcome->skipped) {
                $this->info("  {$typeValue}: skipped (already complete)");
            } elseif ($outcome->completed) {
                $this->info("  {$typeValue}: completed ({$outcome->pagesFetched} pages)");
            } else {
                $this->info("  {$typeValue}: {$outcome->pagesFetched} pages fetched");
            }
        }

        return $result->outcome === SyncOutcome::Failure ? self::FAILURE : self::SUCCESS;
    }
}
