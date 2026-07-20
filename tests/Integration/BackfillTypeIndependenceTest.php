<?php

namespace Tests\Integration;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\Sync\BackfillRunner;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncLock;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Tests\Support\ScriptedSyncService;

/**
 * T089 — Backfill type independence test.
 *
 * SC-013/FR-015a: heart rate complete while steps is not ⇒ steps keeps backfilling;
 * null window is normal outcome, not end condition for account.
 */
class BackfillTypeIndependenceTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test  heart rate complete while steps not — steps keeps backfilling */
    public function completeTypeDoesNotStallIncompleteTypes(): void
    {
        $account = ConnectedAccount::create([
            'user_id' => 'test-user-001',
            'external_service' => 'scripted-sync',
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(120),
        ]);

        AccountSyncState::create([
            'connected_account_id' => $account->id,
        ]);

        $now = CarbonImmutable::now();

        // Heart rate is complete
        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::HeartRate->value,
            'backfilled_to' => $now->copy()->subDays(100),
            'complete_at' => $now,
            'completeness_determined_at' => $now,
        ]);

        // Steps is NOT complete
        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now->copy()->subDays(30),
            'complete_at' => null,
        ]);

        // Service returns empty for both types (heart rate complete, steps will get a window)
        $service = ScriptedSyncService::emitting([new ResultPage()]);
        $service->setMaxWindow(MeasurementType::HeartRate, 'P14D');
        $service->setMaxWindow(MeasurementType::Steps, 'P90D');
        $service->setSupportedTypes([MeasurementType::HeartRate, MeasurementType::Steps]);

        $registry = app(\ClarionApp\LifeLogBackend\External\HealthServiceRegistry::class);
        $registry->register('scripted-sync', fn () => $service);

        $runner = new BackfillRunner(
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

        $result = $runner->run($account);
        // Should succeed — heart rate is skipped (complete), steps gets processed
        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        // Steps should have been queried (it's incomplete)
        $stepsCalls = array_filter($service->fetchCalls, fn ($call) =>
            in_array('steps', $call['types'] ?? [])
        );
        $this->assertNotEmpty($stepsCalls, 'Steps should be queried even when heart rate is complete.');

        // Heart rate should NOT have been queried (it's complete)
        $hrCalls = array_filter($service->fetchCalls, fn ($call) =>
            in_array('heart_rate', $call['types'] ?? [])
        );
        $this->assertEmpty($hrCalls, 'Heart rate should be skipped when already complete.');
    }
}
