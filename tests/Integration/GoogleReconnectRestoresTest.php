<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncOutcome;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

/**
 * T107: Completing flow again clears attention state, retains data.
 */
class GoogleReconnectRestoresTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    private function makeAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'user_id' => 'reconnect-user',
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'needs_attention',
            'connected_at' => CarbonImmutable::now()->subDays(10),
        ]);
    }

    private function makeAuthorization(ConnectedAccount $account): AccountAuthorization
    {
        return AccountAuthorization::create([
            'connected_account_id' => $account->id,
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
            'expires_at' => CarbonImmutable::now()->subMinute(),
            'credential_version' => 1,
        ]);
    }

    private function makeSyncState(ConnectedAccount $account): AccountSyncState
    {
        return AccountSyncState::create([
            'connected_account_id' => $account->id,
            'consecutive_failures' => 0,
            'needs_attention_reason' => 'authorization_unrenewable',
            'synced_through_at' => null,
            'last_success_at' => CarbonImmutable::now()->subDays(1),
            'next_attempt_at' => null,
            'cursor_page_token' => null,
            'cursor_type' => null,
            'cursor_since' => null,
            'cursor_until' => null,
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
     * Reconnect the way the callback does.
     *
     * The service only exchanges the code — persisting the grant, stamping the
     * credential version and resetting sync health is ConnectionCompleter's
     * job (057). Calling completeConnection() directly exercises the fake and
     * none of the behaviour these tests are about.
     */
    private function reconnect(ScriptedSyncService $service, string $accessToken = 'new-access-token'): void
    {
        \ClarionApp\LifeLogBackend\Models\ServiceCredential::firstOrCreate(
            ['external_service' => ScriptedSyncService::NAME],
            [
                'client_id' => 'scripted-client-id',
                'client_secret' => 'scripted-client-secret',
                'redirect_uri' => 'http://localhost/callback',
                'version' => 2,
            ],
        );

        $service->completeConnectionWith(new AuthorizationGrant(
            accessToken: $accessToken,
            refreshToken: 'new-refresh-token',
            expiresAt: CarbonImmutable::now()->addHours(8),
        ));

        app(\ClarionApp\LifeLogBackend\Connection\ConnectionCompleter::class)->complete(
            'reconnect-user',
            ScriptedSyncService::NAME,
            'auth-code',
            'http://localhost/callback',
            $service,
        );
    }

    /**
     * Completing the OAuth flow again for a needs_attention account
     * clears the attention state and restores the connection.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function reconnectClearsAttentionState(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);
        $this->makeSyncState($account);

        // Verify initial state.
        $account->refresh();
        $this->assertEquals('needs_attention', $account->sync_state);

        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals('authorization_unrenewable', $state->needs_attention_reason);

        // Complete the connection again (simulates re-authorizing).
        $service = ScriptedSyncService::emitting([]);
        $this->reconnect($service);

        // Authorization was updated with new tokens.
        $auth = AccountAuthorization::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($auth);
        $this->assertEquals('new-access-token', $auth->access_token);
        $this->assertEquals('new-refresh-token', $auth->refresh_token);

        // Credential version was incremented.
        $this->assertEquals(2, $auth->credential_version);

        // Sync health was reset.
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);
        $this->assertNull($state->needs_attention_reason);
        $this->assertEquals(0, $state->consecutive_failures);

        // Account is back to normal.
        $account->refresh();
        $this->assertEquals('normal', $account->sync_state);
    }

    /**
     * Reconnecting does not delete previously ingested data.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function reconnectRetainsIngestedData(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);
        $this->makeSyncState($account);

        // Simulate some previously synced data (cursor is set).
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $state->update([
            'synced_through_at' => CarbonImmutable::now()->subDays(1),
            'cursor_page_token' => 'some-cursor',
        ]);

        // Reconnect.
        $service = ScriptedSyncService::emitting([]);
        $this->reconnect($service);

        // Cursor is cleared on reconnect (sync starts fresh from cursor).
        // But the synced_through_at timestamp is preserved (data is retained).
        $state = AccountSyncState::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($state);

        // synced_through_at is preserved — we know where we left off.
        $this->assertNotNull($state->synced_through_at);
    }

    /**
     * After reconnect, a sync run succeeds with the new tokens.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function syncSucceedsAfterReconnect(): void
    {
        $account = $this->makeAccount();
        $this->makeAuthorization($account);
        $this->makeSyncState($account);

        // Reconnect.
        $service = ScriptedSyncService::withPages([
            ['measurements' => 1, 'sessions' => 0],
        ]);
        $this->reconnect($service);

        // Run sync — should succeed with new tokens.
        $runner = $this->makeRunnerWithService($service);
        $result = $runner->run($account, SyncTrigger::Scheduled);

        $this->assertEquals(SyncOutcome::Success, $result->outcome);

        // Account is in normal state.
        $account->refresh();
        $this->assertEquals('normal', $account->sync_state);
    }
}
