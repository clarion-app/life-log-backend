<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

class OverlapDedupTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(CarbonImmutable $connectedAt): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '77777777-7777-7777-7777-777777777777',
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

    private function makeRunner(int $maxPages = 200): \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner
    {
        return new \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner(
            app(HealthServiceRegistry::class),
            app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncLock::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
            $maxPages,
        );
    }

    /* ------------------------------------------------------------------
     * T051 Scenario 1: Re-running a covered range adds zero rows
     * ------------------------------------------------------------------ */

    public function test_rerunCoveredRangeAddsZeroRows(): void
    {
        $now = CarbonImmutable::parse('2026-07-20 12:00:00 UTC');
        CarbonImmutable::setTestNow($now);

        try {
            $connectedAt = $now->subDays(10);
            $account = $this->makeAccount($connectedAt);

            $userId = $account->user_id;
            $svc = ScriptedSyncService::NAME;

            // Build two pages with 3 measurements total
            $meas1 = \ClarionApp\LifeLogBackend\External\TranslatedMeasurement::make(
                $userId, MeasurementType::Steps, '10000', $now->subHours(2), 'dedup-meas-1', $svc
            );
            $meas2 = \ClarionApp\LifeLogBackend\External\TranslatedMeasurement::make(
                $userId, MeasurementType::Steps, '11000', $now->subHours(1.5), 'dedup-meas-2', $svc
            );
            $meas3 = \ClarionApp\LifeLogBackend\External\TranslatedMeasurement::make(
                $userId, MeasurementType::Steps, '12000', $now->subHours(1), 'dedup-meas-3', $svc
            );

            $firstPages = [
                new ResultPage([$meas1, $meas2], [], new PageCursor('cursor-1')),
                new ResultPage([$meas3], [], null),
            ];

            $service = ScriptedSyncService::emitting($firstPages);
            $this->registerScriptedService($service);

            // First sync
            $result1 = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
            $this->assertEquals(SyncOutcome::Success, $result1->outcome);

            $rowCountAfterFirst = RawMeasurement::count();
            $this->assertEquals(3, $rowCountAfterFirst, 'First sync should write 3 measurements');

            // Advance time slightly so the second sync has a non-empty window
            $later = $now->addMinute();
            CarbonImmutable::setTestNow($later);

            // Reset with identical pages (same external IDs) — simulates re-fetch
            $secondPages = [
                new ResultPage([$meas1, $meas2], [], new PageCursor('cursor-1')),
                new ResultPage([$meas3], [], null),
            ];
            $service->resetPages($secondPages);

            // Second sync — overlap window re-fetches the same range
            $result2 = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
            $this->assertEquals(SyncOutcome::Success, $result2->outcome);

            // Row count should NOT increase — upsert idempotence
            $this->assertEquals(
                $rowCountAfterFirst,
                RawMeasurement::count(),
                'Re-running a covered range should add zero rows (upsert idempotence)',
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /* ------------------------------------------------------------------
     * T051 Scenario 2: Late reading appears exactly once
     * ------------------------------------------------------------------ */

    public function test_lateReadingAppearsExactlyOnce(): void
    {
        $now = CarbonImmutable::parse('2026-07-20 12:00:00 UTC');
        CarbonImmutable::setTestNow($now);

        try {
            $connectedAt = $now->subDays(10);
            $account = $this->makeAccount($connectedAt);

            $userId = $account->user_id;
            $service = ScriptedSyncService::NAME;

            // First pass: only one measurement (meas-a)
            $measA = \ClarionApp\LifeLogBackend\External\TranslatedMeasurement::make(
                $userId,
                MeasurementType::Steps,
                '10000',
                $now->subHours(1),
                'meas-a',
                $service,
            );

            $firstPages = [
                new ResultPage([$measA], [], null),
            ];

            $syncService = ScriptedSyncService::emitting($firstPages);
            $this->registerScriptedService($syncService);

            // First sync
            $result1 = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
            $this->assertEquals(SyncOutcome::Success, $result1->outcome);
            $this->assertEquals(1, RawMeasurement::count(), 'First sync should write 1 measurement');

            // Advance time so the second sync has a non-empty window
            $later = $now->addMinute();
            CarbonImmutable::setTestNow($later);

            // Second pass: meas-a (same as before) + meas-b (late reading)
            $measB = \ClarionApp\LifeLogBackend\External\TranslatedMeasurement::make(
                $userId,
                MeasurementType::Steps,
                '12000',
                $now->subHours(0.5),
                'meas-b',
                $service,
            );

            $secondPages = [
                new ResultPage([$measA, $measB], [], null),
            ];
            $syncService->resetPages($secondPages);

            // Second sync
            $result2 = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
            $this->assertEquals(SyncOutcome::Success, $result2->outcome);

            // Exactly 2 rows: meas-a (updated in place) + meas-b (new)
            $this->assertEquals(
                2,
                RawMeasurement::count(),
                'Late reading should appear exactly once; meas-a should be upserted in place',
            );

            // Verify meas-b exists
            $measBRow = RawMeasurement::where('external_id', 'meas-b')->first();
            $this->assertNotNull($measBRow, 'Late reading meas-b should exist');
            $this->assertEquals('12000.0000', $measBRow->value);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /* ------------------------------------------------------------------
     * T051 Scenario 3: Corrected value replaces in place
     * ------------------------------------------------------------------ */

    public function test_correctedValueReplacesInPlace(): void
    {
        $now = CarbonImmutable::parse('2026-07-20 12:00:00 UTC');
        CarbonImmutable::setTestNow($now);

        try {
            $connectedAt = $now->subDays(10);
            $account = $this->makeAccount($connectedAt);

            $userId = $account->user_id;
            $service = ScriptedSyncService::NAME;

            // First pass: two measurements
            $measA = \ClarionApp\LifeLogBackend\External\TranslatedMeasurement::make(
                $userId,
                MeasurementType::Steps,
                '10000',
                $now->subHours(1),
                'meas-x',
                $service,
            );
            $measB = \ClarionApp\LifeLogBackend\External\TranslatedMeasurement::make(
                $userId,
                MeasurementType::Steps,
                '11000',
                $now->subHours(0.5),
                'meas-y',
                $service,
            );

            $firstPages = [
                new ResultPage([$measA, $measB], [], null),
            ];

            $syncService = ScriptedSyncService::emitting($firstPages);
            $this->registerScriptedService($syncService);

            // First sync
            $result1 = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
            $this->assertEquals(SyncOutcome::Success, $result1->outcome);
            $this->assertEquals(2, RawMeasurement::count());

            // Advance time so the second sync has a non-empty window
            $later = $now->addMinute();
            CarbonImmutable::setTestNow($later);

            // Second pass: meas-x corrected to 10500, meas-y unchanged
            $measXCorrected = \ClarionApp\LifeLogBackend\External\TranslatedMeasurement::make(
                $userId,
                MeasurementType::Steps,
                '10500',
                $now->subHours(1),
                'meas-x',
                $service,
            );

            $secondPages = [
                new ResultPage([$measXCorrected, $measB], [], null),
            ];
            $syncService->resetPages($secondPages);

            // Second sync
            $result2 = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
            $this->assertEquals(SyncOutcome::Success, $result2->outcome);

            // Still exactly 2 rows — no duplicates
            $this->assertEquals(
                2,
                RawMeasurement::count(),
                'Corrected value should replace in place, not add a sibling',
            );

            // meas-x should have the corrected value
            $measXRow = RawMeasurement::where('external_id', 'meas-x')->first();
            $this->assertNotNull($measXRow);
            $this->assertEquals(
                '10500.0000',
                $measXRow->value,
                'Corrected value should replace the original',
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /* ------------------------------------------------------------------
     * T051 Scenario 4: Window never reaches before connected_at
     * ------------------------------------------------------------------ */

    public function test_windowNeverReachesBeforeConnectedAt(): void
    {
        // Use a short overlap so we can advance time without waiting forever
        config(['life-log.sync_overlap_hours' => 2]);

        $now = CarbonImmutable::parse('2026-07-20 12:00:00 UTC');
        CarbonImmutable::setTestNow($now);

        try {
            // Account connected 3 hours ago — overlap (2h) < connected duration (3h)
            // After first sync, if we advance time by 3 hours, the overlap window
            // would try to reach back 2 hours before synced_through_at, which
            // would be before connected_at. It should be clamped.
            $connectedAt = $now->subHours(3);
            $account = $this->makeAccount($connectedAt);

            $userId = $account->user_id;
            $service = ScriptedSyncService::NAME;

            // First page: one measurement
            $measA = \ClarionApp\LifeLogBackend\External\TranslatedMeasurement::make(
                $userId,
                MeasurementType::Steps,
                '9000',
                $now->subHours(1),
                'meas-clamp',
                $service,
            );

            $firstPages = [
                new ResultPage([$measA], [], null),
            ];

            $syncService = ScriptedSyncService::emitting($firstPages);
            $this->registerScriptedService($syncService);

            // First sync
            $result1 = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
            $this->assertEquals(SyncOutcome::Success, $result1->outcome);

            $state = AccountSyncState::where('connected_account_id', $account->id)->first();
            $this->assertNotNull($state);
            $this->assertNotNull($state->synced_through_at);

            // Advance time by 3 hours — well past the overlap window
            $later = $now->addHours(3);
            CarbonImmutable::setTestNow($later);

            // Second pass: same measurement (to verify the window is used)
            $secondPages = [
                new ResultPage([$measA], [], null),
            ];
            $syncService->resetPages($secondPages);

            // Second sync
            $result2 = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
            $this->assertEquals(SyncOutcome::Success, $result2->outcome);

            // The service's since should be clamped to connected_at, not before it
            // The second sync's fetch calls start after the first sync's calls
            $run2Calls = array_slice($syncService->fetchCalls, 1);
            $this->assertCount(1, $run2Calls);

            $since = CarbonImmutable::parse($run2Calls[0]['since']);

            // since should be >= connected_at (clamped)
            $this->assertGreaterThanOrEqual(
                $connectedAt->getTimestamp(),
                $since->getTimestamp(),
                'Window since should never be before connected_at (FR-005)',
            );
        } finally {
            CarbonImmutable::setTestNow();
            config(['life-log.sync_overlap_hours' => 72]);
        }
    }
}
