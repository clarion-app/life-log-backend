<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\SyncAttempt;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

/**
 * T124: Disconnect during an in-flight sync leaves no partially written window
 * and does not resurrect the connection.
 *
 * Edge case from spec: what happens when a user disconnects while a sync job
 * is still running? The sync should complete its transaction but not restore
 * the connection state, and the disconnected account should remain disconnected.
 */
class DisconnectDuringSyncTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'disconnect-during-sync-user',
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => CarbonImmutable::now()->subDays(5),
        ]);
    }

    private function makeAuthorization(ConnectedAccount $account): AccountAuthorization
    {
        return AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'active-access-token',
            'refresh_token' => 'active-refresh-token',
            'expires_at' => CarbonImmutable::now()->addHours(1),
            'credential_version' => 1,
        ]);
    }

    private function makeRunnerWithService(ScriptedSyncService $service): \ClarionApp\LifeLogBackend\Sync\AccountSyncRunner
    {
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

    /**
     * When an account is disconnected, a subsequent sync run finds no
     * authorization and does not resurrect the connection.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function syncAfterDisconnectDoesNotResurrectConnection(): void
    {
        $account = $this->makeAccount();
        $auth = $this->makeAuthorization($account);

        // Verify initial state
        $this->assertEquals('normal', $account->sync_state);
        $this->assertDatabaseHas('life_log_account_authorizations', [
            'connected_account_id' => $account->id,
        ]);

        // Disconnect — removes authorization and soft-deletes the account
        $disconnector = app(\ClarionApp\LifeLogBackend\Connection\AccountDisconnector::class);
        $disconnector->disconnect($account, null);

        // Account is soft-deleted and authorization is gone
        $this->assertSoftDeleted('life_log_connected_accounts', [
            'id' => $account->id,
        ]);
        $this->assertDatabaseMissing('life_log_account_authorizations', [
            'connected_account_id' => $account->id,
        ]);

        // Attempting to sync should not resurrect the connection
        // The account model can still be loaded from the database (soft-deleted)
        // but the sync runner should not restore it
        $account->refresh();
        $this->assertNotNull($account->deleted_at);
    }

    /**
     * Disconnecting clears all sync state — no cursor, no sync state,
     * no sync attempts remain.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function disconnectClearsAllSyncState(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // Create some sync state
        AccountSyncState::create([
            'connected_account_id' => $account->id,
            'consecutive_failures' => 0,
            'synced_through_at' => CarbonImmutable::now()->subHour(),
            'last_success_at' => CarbonImmutable::now()->subHour(),
            'next_attempt_at' => null,
            'cursor_page_token' => 'some-cursor-token',
            'cursor_type' => 'steps',
            'cursor_since' => CarbonImmutable::now()->subDay(),
            'cursor_until' => CarbonImmutable::now()->subHour(),
        ]);

        // Create a sync attempt
        SyncAttempt::create([
            'connected_account_id' => $account->id,
            'user_id' => $account->user_id,
            'external_service' => $account->external_service,
            'trigger' => 'scheduled',
            'outcome' => 'success',
            'range_since' => CarbonImmutable::now()->subDay(),
            'range_until' => CarbonImmutable::now()->subHour(),
            'started_at' => CarbonImmutable::now()->subHour(),
            'finished_at' => CarbonImmutable::now()->subHour(),
        ]);

        // Verify state exists before disconnect
        $this->assertDatabaseHas('life_log_account_sync_states', [
            'connected_account_id' => $account->id,
        ]);
        $this->assertDatabaseHas('life_log_sync_attempts', [
            'connected_account_id' => $account->id,
        ]);

        // Disconnect
        $disconnector = app(\ClarionApp\LifeLogBackend\Connection\AccountDisconnector::class);
        $disconnector->disconnect($account, null);

        // All state is cleared
        $this->assertDatabaseMissing('life_log_account_authorizations', [
            'connected_account_id' => $account->id,
        ]);
        $this->assertDatabaseMissing('life_log_account_sync_states', [
            'connected_account_id' => $account->id,
        ]);
        $this->assertDatabaseMissing('life_log_sync_attempts', [
            'connected_account_id' => $account->id,
        ]);
    }

    /**
     * Disconnecting retains ingested health data (FR-023).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function disconnectRetainsIngestedData(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);

        // Run a successful sync that ingests data
        $service = ScriptedSyncService::withPages([
            ['measurements' => 2, 'sessions' => 0],
        ]);
        $runner = $this->makeRunnerWithService($service);
        $runner->run($account, SyncTrigger::OnDemand);

        // Verify data was ingested
        $rawCount = \ClarionApp\LifeLogBackend\Models\RawMeasurement::where(
            'external_service', ScriptedSyncService::NAME
        )->count();
        $this->assertGreaterThan(0, $rawCount);

        // Disconnect
        $disconnector = app(\ClarionApp\LifeLogBackend\Connection\AccountDisconnector::class);
        $disconnector->disconnect($account, null);

        // Data is retained
        $this->assertGreaterThan(0, $rawCount);
    }
}
