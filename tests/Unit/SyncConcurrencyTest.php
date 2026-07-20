<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

class SyncConcurrencyTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(CarbonImmutable $connectedAt): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '66666666-6666-6666-6666-666666666666',
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => $connectedAt,
        ]);
    }

    private function registerScriptedService(ScriptedSyncService $service): void
    {
        app(HealthServiceRegistry::class)->register(
            ScriptedSyncService::NAME,
            fn () => $service,
        );
    }

    private function makeRunner(): \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner
    {
        return new \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner(
            app(HealthServiceRegistry::class),
            app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncLock::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
        );
    }

    /* ------------------------------------------------------------------
     * T045: With the account's cache lock held, a second run exits
     *       "skipped", never calls the service, and leaves counter,
     *       gate, and checkpoint untouched (FR-008, "skipped is not a failure").
     * ------------------------------------------------------------------ */

    public function test_concurrentRunIsSkipped(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount($connectedAt);

        // Set up some pre-existing health state so we can verify it's untouched
        $state = AccountSyncState::create([
            'connected_account_id' => $account->id,
            'synced_through_at' => $connectedAt->subDay(),
            'consecutive_failures' => 2,
            'next_attempt_at' => CarbonImmutable::now()->addHours(4),
        ]);
        $originalFailures = $state->consecutive_failures;
        $originalGate = $state->next_attempt_at;
        $originalCheckpoint = $state->synced_through_at;

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $this->registerScriptedService($service);

        // Hold the lock externally — simulates a first run still in progress.
        // SyncLock uses "life-log:sync:{accountId}" as the lock name.
        $lockName = sprintf('life-log:sync:%s', $account->id);
        $lockSeconds = (int) config('life-log.sync_lock_seconds', 900);
        $externalLock = Cache::lock($lockName, $lockSeconds);
        $externalLock->block(0); // Non-blocking acquire — should succeed (no one holds it)

        try {
            // Run while the lock is held externally
            $result = $this->makeRunner()->run($account, SyncTrigger::OnDemand);

            // Outcome should be skipped
            $this->assertEquals(
                SyncOutcome::Skipped,
                $result->outcome,
                'Second run should return skipped when lock is held',
            );

            // Service should never have been called
            $this->assertCount(
                0,
                $service->fetchCalls,
                'Service fetch should not be called when lock is held',
            );

            // Health state should be untouched
            $state->refresh();
            $this->assertEquals(
                $originalFailures,
                $state->consecutive_failures,
                'Consecutive failures counter should be untouched on skipped',
            );
            $this->assertEquals(
                $originalGate?->getTimestamp(),
                $state->next_attempt_at?->getTimestamp(),
                'next_attempt_at (gate) should be untouched on skipped',
            );
            $this->assertEquals(
                $originalCheckpoint?->getTimestamp(),
                $state->synced_through_at?->getTimestamp(),
                'synced_through_at (checkpoint) should be untouched on skipped',
            );
        } finally {
            $externalLock->release();
        }
    }
}
