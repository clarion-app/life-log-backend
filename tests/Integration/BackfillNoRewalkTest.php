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
 * T093 — Backfill no rewalk test.
 *
 * US3 scenario 6: range already covered not re-walked;
 * --reset is ONLY path that re-walks history.
 */
class BackfillNoRewalkTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test  already-covered range is not re-walked */
    public function alreadyCoveredRangeNotRewalked(): void
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

        // Backfill state is already at a point that means the range is covered
        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $now->copy()->subDays(90),
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

        // Should succeed but not query the service (type is complete)
        $this->assertEquals(SyncOutcome::Success, $result->outcome);
        $this->assertTrue(
            empty($service->fetchCalls),
            'Already-covered range should not be re-walked.',
        );
    }
}
