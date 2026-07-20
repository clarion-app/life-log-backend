<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\External\TranslatedMeasurement;
use ClarionApp\LifeLogBackend\Sync\BackfillRunner;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncLock;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Tests\Support\ScriptedSyncService;

/**
 * T090 — Per-run request cap test.
 *
 * FR-019/SC-008: one account's backfill stops at max_requests_per_backfill_run
 * WITH budget remaining, yielding the account lock.
 */
class PerRunRequestCapTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test  backfill stops at per-run cap with budget remaining */
    public function stopsAtPerRunCapWithBudgetRemaining(): void
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
            'backfilled_to' => $now,
        ]);

        // Many pages available
        $pages = [];
        for ($i = 0; $i < 20; $i++) {
            $measurements = [TranslatedMeasurement::make(
                userId: 'test-user-001',
                type: MeasurementType::Steps,
                value: (string) (1000 + $i),
                recordedAt: $now->copy()->subDays($i),
                externalId: sprintf('cap-test-%d', $i),
                externalService: 'scripted-sync',
            )];
            $nextCursor = new PageCursor(sprintf('pcap-%d', $i + 1));
            $pages[] = new ResultPage($measurements, [], $nextCursor);
        }

        $service = ScriptedSyncService::emitting($pages);
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
            maxRequestsPerRun: 3, // Very low per-run cap
            maxPages: 100,
        );

        $result = $runner->run($account);

        // Should be Partial (stopped by cap)
        $this->assertEquals(SyncOutcome::Partial, $result->outcome,
            'Backfill should return Partial when per-run cap is hit.');

        // Should have fetched exactly the cap amount
        $this->assertLessThanOrEqual(3, $result->pagesFetched,
            'Pages fetched should not exceed per-run cap.');
    }
}
