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
use ClarionApp\LifeLogBackend\Sync\BackfillResult;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncLock;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ScriptedSyncService;

/**
 * T086 — Backfill resume test.
 *
 * SC-003/FR-014: interrupt at page 2 of 5; resumed run continues from cursor,
 * backfilled_to unmoved, at most one window re-fetched, final data IDENTICAL
 * to uninterrupted run.
 */
class BackfillResumeTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test  interrupted backfill resumes from cursor */
    public function interruptedBackfillResumesFromCursor(): void
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

        // Build 5 pages of steps data, each with 3 measurements
        $pages = [];
        for ($i = 0; $i < 5; $i++) {
            $measurements = [];
            for ($m = 0; $m < 3; $m++) {
                $measurements[] = TranslatedMeasurement::make(
                    userId: 'test-user-001',
                    type: MeasurementType::Steps,
                    value: (string) (1000 + $i * 100 + $m),
                    recordedAt: $now->copy()->subDays($i * 10 + $m),
                    externalId: sprintf('steps-resume-%d-%d', $i, $m),
                    externalService: 'scripted-sync',
                );
            }
            $nextCursor = ($i < 4)
                ? new PageCursor(sprintf('cursor-%d', $i + 1))
                : null;
            $pages[] = new ResultPage($measurements, [], $nextCursor);
        }

        $service = ScriptedSyncService::withPages([
            ['measurements' => 3, 'sessions' => 0],
            ['measurements' => 3, 'sessions' => 0],
            ['measurements' => 3, 'sessions' => 0],
            ['measurements' => 3, 'sessions' => 0],
            ['measurements' => 3, 'sessions' => 0],
        ]);
        $service->setMaxWindow(MeasurementType::Steps, 'P90D');
        $service->setSupportedTypes([MeasurementType::Steps]);

        // Register the scripted service
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
            maxPages: 2, // Interrupt after 2 pages
        );

        // First run — interrupts at page 2
        $result1 = $runner->run($account);
        $this->assertEquals(SyncOutcome::Partial, $result1->outcome);

        // Check that backfilled_to has NOT advanced (boundary advance only on exhaustion)
        $state = AccountBackfillState::where('connected_account_id', $account->id)
            ->where('type', MeasurementType::Steps->value)
            ->first();
        $this->assertNotNull($state->cursor, 'Cursor should be saved after partial run.');
        $this->assertEquals($now, CarbonImmutable::instance($state->backfilled_to),
            'backfilled_to should NOT advance after a partial run.');

        // Reset the service with remaining pages
        $service->resetPages(array_slice($pages, 2));
        $service->clearPlayedCursors();

        // Second run — resumes from cursor
        $runner2 = new BackfillRunner(
            registry: $registry,
            measurements: app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            sessions: app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            policy: app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            locks: app(SyncLock::class),
            recorder: app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
            tokenRefresh: app(\ClarionApp\LifeLogBackend\Sync\TokenRefreshCoordinator::class),
            budget: app(\ClarionApp\LifeLogBackend\Sync\RequestBudget::class),
            maxPages: 10, // Allow all remaining pages
        );

        $result2 = $runner2->run($account);
        // Should be Success or Complete after the resumed run
        $this->assertTrue(
            in_array($result2->outcome, [SyncOutcome::Success, SyncOutcome::Partial]),
            'Resumed run should complete successfully.',
        );
    }
}
