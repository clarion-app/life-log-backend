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
 * T088 — Backfill completeness test.
 *
 * FR-015: exhausted window with no data and no cursor sets complete_at
 * and completeness_determined_at; type NEVER queried again;
 * account-level completeness is conjunction over granted types.
 */
class BackfillCompletenessTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test  exhausted window with no data marks type as complete */
    public function exhaustedWindowMarksComplete(): void
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
        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now->copy()->subDays(30),
        ]);

        // Empty page with no cursor = exhaustion with no data
        $service = ScriptedSyncService::emitting([new ResultPage()]);
        $service->setMaxWindow(MeasurementType::Steps, 'P90D');
        $service->setSupportedTypes([MeasurementType::Steps]);

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
        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        $state = AccountBackfillState::where('connected_account_id', $account->id)
            ->where('type', MeasurementType::Steps->value)
            ->first();

        $this->assertNotNull($state->complete_at, 'complete_at should be set on exhausted empty window.');
        $this->assertNotNull($state->completeness_determined_at,
            'completeness_determined_at should be set.');
        $this->assertTrue($state->isComplete());
    }

    /** @test  complete type is never queried again */
    public function completeTypeNeverQueriedAgain(): void
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
        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now->copy()->subDays(30),
            'complete_at' => $now,
            'completeness_determined_at' => $now,
        ]);

        $service = ScriptedSyncService::emitting([new ResultPage()]);
        $service->setMaxWindow(MeasurementType::Steps, 'P90D');
        $service->setSupportedTypes([MeasurementType::Steps]);

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

        // The service should NOT have been called for the complete type
        $this->assertTrue(
            empty($service->fetchCalls) || $service->requestCount() === 0,
            'Complete type should not be queried again.',
        );
    }

    /** @test  account with 5 complete types and 1 incomplete is NOT complete */
    public function accountLevelCompletenessIsConjunction(): void
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

        // Create 5 complete types
        $completeTypes = [
            MeasurementType::Weight,
            MeasurementType::Distance,
            MeasurementType::CaloriesBurned,
            MeasurementType::ActiveMinutes,
            \ClarionApp\LifeLogBackend\Vocabulary\SessionType::Sleep,
        ];

        foreach ($completeTypes as $type) {
            AccountBackfillState::create([
                'connected_account_id' => $account->id,
                'type' => $type->value,
                'backfilled_to' => $now->copy()->subDays(100),
                'complete_at' => $now,
                'completeness_determined_at' => $now,
            ]);
        }

        // One incomplete type
        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now->copy()->subDays(30),
            'complete_at' => null,
        ]);

        $allStates = $account->backfillStates;
        $allComplete = true;
        foreach ($allStates as $state) {
            if (!$state->isComplete()) {
                $allComplete = false;
                break;
            }
        }

        $this->assertFalse($allComplete,
            'Account with one incomplete type should NOT be considered fully complete.');
    }
}
