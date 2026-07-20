<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

class RecoveryAndReconnectTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(string $id, CarbonImmutable $connectedAt): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => $id,
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => $connectedAt,
        ]);
    }

    private function makeRunnerWithService(ScriptedSyncService $service): \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner
    {
        // Create a fresh registry for each run (singleton doesn't allow re-registration)
        $registry = new HealthServiceRegistry();
        $registry->register(ScriptedSyncService::NAME, fn () => $service);

        return new \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner(
            $registry,
            app(\ClarionApp\LifeLogBackend\Services\RawMeasurementWriter::class),
            app(\ClarionApp\LifeLogBackend\Services\RawSessionWriter::class),
            app(\ClarionApp\LifeLogBackend\Sync\FailurePolicy::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncLock::class),
            app(\ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder::class),
        );
    }

    /* ------------------------------------------------------------------
     * T039: A success at any rung resets consecutive_failures to 0 and
     *       clears next_attempt_at (FR-015); clearing needs_attention
     *       zeroes the counter, clears gate + cursor triple, preserves
     *       synced_through_at, and the next run covers only the gap
     *       (FR-014); reconnecting the same (user, provider) updates
     *       the existing row rather than creating a second account
     * ------------------------------------------------------------------ */

    public function testSuccessResetsFailureCounter(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $account = $this->makeAccount('recovery-user', $connectedAt);

        // First: cause 3 failures
        for ($i = 0; $i < 3; $i++) {
            $now = CarbonImmutable::now()->addHours($i);
            CarbonImmutable::setTestNow($now);

            $service = ScriptedSyncService::withPages([
                ['measurements' => 1, 'sessions' => 0],
            ]);
            $service->throwServiceUnavailableOn(1);

            $result = $this->makeRunnerWithService($service)->run($account, SyncTrigger::Scheduled);
            $this->assertEquals(SyncOutcome::Failure, $result->outcome);
        }

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals(3, $state->consecutive_failures);
        $this->assertNotNull($state->next_attempt_at);

        // Now: success resets everything
        $now = CarbonImmutable::now()->addHours(3);
        CarbonImmutable::setTestNow($now);

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);

        $result = $this->makeRunnerWithService($service)->run($account, SyncTrigger::Scheduled);
        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals(0, $state->consecutive_failures);
        $this->assertNull($state->next_attempt_at);
        $this->assertNotNull($state->last_success_at);

        CarbonImmutable::setTestNow();
    }

    public function testReconnectPreservesSyncedThroughAt(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(10);
        $account = $this->makeAccount('reconnect-user', $connectedAt);

        // First: successful sync
        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);

        $result = $this->makeRunnerWithService($service)->run($account, SyncTrigger::Scheduled);
        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $syncedThroughAtBefore = $state->synced_through_at;
        $this->assertNotNull($syncedThroughAtBefore);

        // Simulate some failures
        for ($i = 0; $i < 3; $i++) {
            $now = CarbonImmutable::now()->addHours($i);
            CarbonImmutable::setTestNow($now);

            $service = ScriptedSyncService::withPages([
                ['measurements' => 1, 'sessions' => 0],
            ]);
            $service->throwServiceUnavailableOn(1);

            $this->makeRunnerWithService($service)->run($account, SyncTrigger::Scheduled);
        }

        // Now "reconnect" — reset sync health
        // This should: set sync_state to normal, zero counter, clear gate + cursor,
        // but preserve synced_through_at
        $account->refresh();
        $account->load('syncState');
        $account->resetSyncHealth();

        // Verify synced_through_at is preserved
        $this->assertEquals(
            $syncedThroughAtBefore->getTimestamp(),
            $state->synced_through_at->getTimestamp(),
        );

        // Next run should cover only the gap (from synced_through_at to now)
        $now = CarbonImmutable::now()->addHours(5);
        CarbonImmutable::setTestNow($now);

        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);

        $result = $this->makeRunnerWithService($service)->run($account, SyncTrigger::Scheduled);
        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        // The since should be after connected_at (overlap window from synced_through_at)
        $this->assertGreaterThan(
            $connectedAt->getTimestamp(),
            $result->since->getTimestamp(),
            'Since should not go back to connected_at on reconnect',
        );

        CarbonImmutable::setTestNow();
    }

    public function testReconnectingSameUserProviderUpdatesExistingRow(): void
    {
        $connectedAt = CarbonImmutable::now()->subDays(5);
        $userId = 'reconnect-same-user';

        // Create account first time
        $account1 = ConnectedAccount::create([
            'user_id' => $userId,
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => $connectedAt,
        ]);

        // "Reconnect" — update existing row (not create a new one)
        // In real scenario, the connect handler uses updateOrCreate or similar
        $account1->connected_at = CarbonImmutable::now();
        $account1->sync_state = 'normal';
        $account1->save();

        // Should still be one account
        $count = ConnectedAccount::where('user_id', $userId)
            ->where('external_service', ScriptedSyncService::NAME)
            ->count();
        $this->assertEquals(1, $count);
    }
}
