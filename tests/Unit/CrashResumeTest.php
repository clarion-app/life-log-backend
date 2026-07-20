<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

class CrashResumeTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(CarbonImmutable $connectedAt): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '55555555-5555-5555-5555-555555555555',
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
     * T027: Crash-resume and page cap
     * ------------------------------------------------------------------ */

    public function test_crashResumePreservesCursorAndResumesWithStoredWindow(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount($connectedAt);

        // Service that throws on fetch #2 (1-indexed) — simulates crash mid-range
        // After the throw, it resumes normal emission (for the second run)
        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],  // page 0
            ['measurements' => 1, 'sessions' => 0],  // page 1
            ['measurements' => 1, 'sessions' => 0],  // page 2
            ['measurements' => 1, 'sessions' => 0],  // page 3 (final)
        ]);
        $service->throwServiceUnavailableOn(2); // Throw on the 2nd fetch call
        $this->registerScriptedService($service);

        // Run 1: should fail on page 2 (throws ServiceUnavailable)
        $result1 = $this->makeRunner()->run($account, SyncTrigger::Scheduled);
        $this->assertEquals(SyncOutcome::Failure, $result1->outcome);

        // Clear played cursors for run 2 (simulates fresh sync run - cursor was NOT
        // consumed in run 1 because the fetch threw before returning the page)
        $service->clearPlayedCursors();

        // Cursor should be persisted from page 1's transaction
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertNotNull($state->cursor, 'Cursor should be persisted after page 1 succeeds');
        $this->assertNotNull($state->cursor_since, 'cursor_since should be persisted');
        $this->assertNotNull($state->cursor_until, 'cursor_until should be persisted');

        $storedSince = $state->cursor_since;
        $storedUntil = $state->cursor_until;

        // Run 2: fresh runner, same service (which now resumes normal emission)
        // The runner should adopt the stored window verbatim
        $result2 = $this->makeRunner()->run($account, SyncTrigger::Scheduled);

        // The second run should use the stored window (cursor_since, cursor_until)
        // Assert from the fake's recorded arguments
        // Run 1 had 2 fetch calls (page 0 succeeded, page 1 threw)
        $run2Calls = array_slice($service->fetchCalls, 2); // Skip the first run's calls
        $this->assertCount(3, $run2Calls); // Pages 1, 2, 3

        // The first call of run 2 should use the stored window
        $resumeCall = $run2Calls[0];
        // Compare timestamps to avoid microsecond precision differences
        $this->assertEquals(
            $storedSince->getTimestamp(),
            CarbonImmutable::parse($resumeCall['since'])->getTimestamp(),
            'Resume should adopt cursor_since verbatim',
        );
        $this->assertEquals(
            $storedUntil->getTimestamp(),
            CarbonImmutable::parse($resumeCall['until'])->getTimestamp(),
            'Resume should adopt cursor_until verbatim',
        );

        // Final result should be success
        $this->assertEquals(SyncOutcome::Success, $result2->outcome);

        // Union of stored rows should have no duplicates
        $measurements = RawMeasurement::where('external_service', ScriptedSyncService::NAME)
            ->orderBy('external_id')
            ->pluck('external_id')
            ->toArray();
        $this->assertEquals($measurements, array_unique($measurements), 'No duplicate measurements');
    }

    public function test_pageCapReturnsPartialWithCursorKept(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount($connectedAt);

        // Service with 5 pages
        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
            ['measurements' => 1, 'sessions' => 0],
            ['measurements' => 1, 'sessions' => 0],
            ['measurements' => 1, 'sessions' => 0],
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $this->registerScriptedService($service);

        // Runner with page cap of 2
        $runner = $this->makeRunner(2);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        // Should return partial
        $this->assertEquals(SyncOutcome::Partial, $result->outcome);

        // Cursor should be kept
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertNotNull($state->cursor, 'Cursor should be kept after partial run');

        // Checkpoint should NOT have advanced
        $this->assertNull($state->synced_through_at, 'Checkpoint should not advance on partial');

        // Exactly 2 pages were fetched
        $this->assertEquals(2, $result->pagesFetched);
    }
}
