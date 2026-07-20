<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncResult;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

class FirstSyncCheckpointTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(CarbonImmutable $connectedAt): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => '33333333-3333-3333-3333-333333333333',
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

    private function makeRunner(): \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner
    {
        return new \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner(
            app(HealthServiceRegistry::class),
            app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncLock::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
        );
    }

    /* ------------------------------------------------------------------
     * T025: Null checkpoint → since = connected_at; on success synced_through_at
     * ------------------------------------------------------------------ */

    public function test_nullCheckpointUsesConnectedAtAsSince(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount($connectedAt);

        // No sync state row — first sync
        $this->assertDatabaseMissing('life_log_account_sync_states', [
            'connected_account_id' => $account->id,
        ]);

        $service = ScriptedSyncService::withPages([
            ['measurements' => 2, 'sessions' => 0],
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $this->registerScriptedService($service);

        $result = $this->makeRunner()->run($account, SyncTrigger::Scheduled);

        // Service was called with since = connected_at
        $this->assertCount(2, $service->fetchCalls);
        $firstCall = $service->fetchCalls[0];
        // Compare timestamps to avoid microsecond precision differences
        $this->assertEquals(
            $connectedAt->getTimestamp(),
            CarbonImmutable::parse($firstCall['since'])->getTimestamp(),
        );

        // Outcome is success
        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        // Sync state row was created with synced_through_at
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertNotNull($state->synced_through_at);
        // synced_through_at should equal the first call's until (run-start until)
        $this->assertEquals(
            CarbonImmutable::parse($firstCall['until'])->getTimestamp(),
            $state->synced_through_at->getTimestamp(),
        );

        // Cursor triple is null after successful exhaustion
        $this->assertNull($state->cursor);
        $this->assertNull($state->cursor_since);
        $this->assertNull($state->cursor_until);
    }

    public function test_syncedThroughAtIsRunStartUntilNotNow(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(10);
        $now = CarbonImmutable::now();
        $account = $this->makeAccount($connectedAt);

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $this->registerScriptedService($service);

        $result = $this->makeRunner()->run($account, SyncTrigger::Scheduled);

        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);

        // synced_through_at should be the window's until (≈ now at run start),
        // not a later "now()" at finalize time.
        // The since should be connected_at for a first sync.
        $this->assertEquals(
            $connectedAt->getTimestamp(),
            CarbonImmutable::parse($service->fetchCalls[0]['since'])->getTimestamp(),
        );
        // synced_through_at = until from the fetch call
        $this->assertEquals(
            CarbonImmutable::parse($service->fetchCalls[0]['until'])->getTimestamp(),
            $state->synced_through_at->getTimestamp(),
        );
    }
}
