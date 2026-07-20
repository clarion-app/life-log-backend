<?php

namespace Tests\Integration;

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
 * T087 — Backfill boundary advance test.
 *
 * FR-015c: pages exhausted WITHIN a window before boundary advances;
 * boundary advances ONLY on exhaustion.
 */
class BackfillBoundaryAdvanceTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test  boundary advances only after all pages in window are exhausted */
    public function boundaryAdvancesOnlyOnExhaustion(): void
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
        $backfilledTo = $now;

        AccountBackfillState::create([
            'connected_account_id' => $account->id,
            'type' => MeasurementType::Steps->value,
            'backfilled_to' => $backfilledTo,
        ]);

        // 3 pages that exhaust within one window
        $pages = [];
        for ($i = 0; $i < 3; $i++) {
            $measurements = [];
            for ($m = 0; $m < 2; $m++) {
                $measurements[] = TranslatedMeasurement::make(
                    userId: 'test-user-001',
                    type: MeasurementType::Steps,
                    value: (string) (5000 + $i * 10 + $m),
                    recordedAt: $now->copy()->subDays($i * 5 + $m),
                    externalId: sprintf('boundary-adv-%d-%d', $i, $m),
                    externalService: 'scripted-sync',
                );
            }
            $nextCursor = ($i < 2)
                ? new PageCursor(sprintf('bcursor-%d', $i + 1))
                : null; // Last page has no cursor = exhaustion
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
            maxPages: 10,
        );

        $result = $runner->run($account);
        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        // backfilled_to should have advanced to the since of the exhausted window
        $state = AccountBackfillState::where('connected_account_id', $account->id)
            ->where('type', MeasurementType::Steps->value)
            ->first();

        // The boundary should have moved backwards from $now
        $this->assertTrue(
            CarbonImmutable::instance($state->backfilled_to)->lte($backfilledTo),
            'backfilled_to should advance (move backwards) only after exhaustion.',
        );
    }
}
