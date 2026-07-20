<?php

namespace Tests\Integration;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\AccountSyncRunner;
use ClarionApp\LifeLogBackend\Sync\BackfillRunner;
use ClarionApp\LifeLogBackend\Sync\SyncLock;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Tests\Support\ScriptedSyncService;

/**
 * T092 — Cross-cadence lock test.
 *
 * FR-017/FR-030: backfill holding SHARED SyncLock ⇒ incremental run returns Skipped,
 * not corruption. One lock, not second mechanism.
 */
class CrossCadenceLockTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test  backfill holding lock makes incremental return Skipped */
    public function backfillHoldingLockSkipsIncremental(): void
    {
        $account = ConnectedAccount::create([
            'user_id' => 'test-user-001',
            'external_service' => 'scripted-sync',
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(60),
        ]);

        AccountSyncState::create([
            'connected_account_id' => $account->id,
            'synced_through_at' => CarbonImmutable::now()->subDays(1),
        ]);

        $now = CarbonImmutable::now();
        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now,
        ]);

        $service = ScriptedSyncService::emitting([]);
        $service->setSupportedTypes([MeasurementType::Steps]);

        $registry = app(\ClarionApp\LifeLogBackend\External\HealthServiceRegistry::class);
        $registry->register('scripted-sync', fn () => $service);

        $syncRunner = new AccountSyncRunner(
            registry: $registry,
            measurements: app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            sessions: app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            policy: app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            locks: app(SyncLock::class),
            recorder: app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
        );

        $backfillRunner = new BackfillRunner(
            registry: $registry,
            measurements: app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            sessions: app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            policy: app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            locks: app(SyncLock::class),
            recorder: app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
            tokenRefresh: app(\ClarionApp\LifeLogBackend\Sync\TokenRefreshCoordinator::class),
            budget: app(\ClarionApp\LifeLogBackend\Sync\RequestBudget::class),
            maxPages: 10,
        );

        // Acquire the lock manually (simulating backfill holding it)
        $lock = app(SyncLock::class);
        $lockResult = $lock->attempt($account->id, function () use ($backfillRunner, $account) {
            // While holding the lock, try incremental sync
            $syncResult = $syncRunner->run($account, SyncTrigger::Scheduled);
            return $syncResult;
        });

        $this->assertNotNull($lockResult);
        $this->assertEquals(SyncOutcome::Skipped, $lockResult->outcome,
            'Incremental sync should be Skipped when backfill holds the lock.');
    }
}
