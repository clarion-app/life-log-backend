<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\BackfillRunner;
use ClarionApp\LifeLogBackend\Sync\BackfillResult;
use ClarionApp\LifeLogBackend\Sync\RequestBudget;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncLock;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Tests\Support\ScriptedSyncService;

/**
 * T091 — Backfill yields on budget denial.
 *
 * FR-018: denied budget reservation returns Partial and touches neither
 * consecutive_failures nor sync_state, never marks connection as needing attention.
 */
class BackfillYieldsOnDenialTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test  budget denial returns Partial without affecting sync health */
    public function budgetDenialReturnsPartialWithoutFailure(): void
    {
        $account = ConnectedAccount::create([
            'user_id' => 'test-user-001',
            'external_service' => 'scripted-sync',
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(120),
        ]);

        $syncState = AccountSyncState::create([
            'connected_account_id' => $account->id,
            'consecutive_failures' => 0,
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

        // Create a budget that always denies backfill
        $denyingBudget = new class extends RequestBudget {
            public function reserveBackfill(string $service, int $n = 1): bool
            {
                return false; // Always deny
            }
        };

        $runner = new BackfillRunner(
            registry: $registry,
            measurements: app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            sessions: app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            policy: app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            locks: app(SyncLock::class),
            recorder: app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
            tokenRefresh: app(\ClarionApp\LifeLogBackend\Sync\TokenRefreshCoordinator::class),
            budget: $denyingBudget,
            maxPages: 10,
        );

        $result = $runner->run($account);

        // Should be Partial (budget denied)
        $this->assertEquals(SyncOutcome::Partial, $result->outcome,
            'Budget denial should return Partial.');

        // Sync state should NOT be affected
        $syncState->refresh();
        $this->assertEquals(0, $syncState->consecutive_failures,
            'consecutive_failures should not increment on budget denial.');
        $this->assertEquals('normal', $account->sync_state,
            'sync_state should remain normal on budget denial.');
        $this->assertNull($account->needsAttentionReason(),
            'Connection should not need attention on budget denial.');
    }
}
