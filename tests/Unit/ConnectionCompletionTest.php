<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Support\ScriptedSyncService;

class ConnectionCompletionTest extends TestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \ClarionApp\Backend\Models\User::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
        ]);

        $this->actingAs($this->user);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test */
    public function happyPathCreatesConnectedAccountAndAccountAuthorization()
    {
        $scriptedService = ScriptedSyncService::emitting([]);
        $grant = new AuthorizationGrant(
            accessToken: 'test-access-token',
            refreshToken: 'test-refresh-token',
            expiresAt: CarbonImmutable::now()->addHours(2),
            scopes: 'activity',
            externalAccountId: 'ext-123',
        );
        $scriptedService->completeConnectionWith($grant);

        app(\ClarionApp\LifeLogBackend\External\HealthServiceRegistry::class)->register(
            ScriptedSyncService::NAME,
            fn () => $scriptedService,
        );

        $credential = ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => ScriptedSyncService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
            'version' => 1,
        ]);

        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => ScriptedSyncService::NAME,
            'state' => $state,
            'code' => 'authorization-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'id',
            'external_service',
            'status',
            'reconnected',
        ]);

        $data = $response->json();
        $this->assertEquals(ScriptedSyncService::NAME, $data['external_service']);
        $this->assertEquals('healthy', $data['status']);
        $this->assertFalse($data['reconnected']);

        // ConnectedAccount exists
        $account = ConnectedAccount::where('user_id', $this->user->id)
            ->where('external_service', ScriptedSyncService::NAME)
            ->first();
        $this->assertNotNull($account);
        $this->assertEquals('normal', $account->sync_state);

        // AccountAuthorization exists with credential_version
        $auth = AccountAuthorization::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($auth);
        $this->assertEquals($credential->version, $auth->credential_version);
    }

    /** @test */
    public function reconnectUpdatesExistingAccount()
    {
        $scriptedService = ScriptedSyncService::emitting([]);
        $grant = new AuthorizationGrant(
            accessToken: 'new-access-token',
            refreshToken: 'new-refresh-token',
            expiresAt: CarbonImmutable::now()->addHours(2),
            scopes: 'activity',
            externalAccountId: 'ext-456',
        );
        $scriptedService->completeConnectionWith($grant);

        app(\ClarionApp\LifeLogBackend\External\HealthServiceRegistry::class)->register(
            ScriptedSyncService::NAME,
            fn () => $scriptedService,
        );

        $credential = ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => ScriptedSyncService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
            'version' => 1,
        ]);

        // Create an existing connected account
        $existingAccount = ConnectedAccount::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'needs_attention',
            'connected_at' => now()->subDays(5),
        ]);

        // Create an existing authorization with old credential version
        AccountAuthorization::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'connected_account_id' => $existingAccount->id,
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
            'expires_at' => now()->addHour(),
            'scopes' => 'old_scopes',
            'credential_version' => 0,
        ]);

        // Create an existing sync state with failures
        AccountSyncState::create([
            'connected_account_id' => $existingAccount->id,
            'consecutive_failures' => 3,
            'last_failure_kind' => 'service_unavailable',
            'needs_attention_reason' => 'sync_failures',
        ]);

        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => ScriptedSyncService::NAME,
            'state' => $state,
            'code' => 'authorization-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertTrue($data['reconnected']);

        // Account should still be the same one (not duplicated)
        $accounts = ConnectedAccount::where('user_id', $this->user->id)
            ->where('external_service', ScriptedSyncService::NAME)
            ->get();
        $this->assertEquals(1, $accounts->count());

        // Account sync health should be reset
        $existingAccount->refresh();
        $this->assertEquals('normal', $existingAccount->sync_state);

        $syncState = AccountSyncState::where('connected_account_id', $existingAccount->id)->first();
        $this->assertEquals(0, $syncState->consecutive_failures);
        $this->assertNull($syncState->needs_attention_reason);

        // Authorization should be replaced
        $auth = AccountAuthorization::where('connected_account_id', $existingAccount->id)->first();
        $this->assertNotNull($auth);
        $this->assertEquals($credential->version, $auth->credential_version);
    }

    /** @test */
    public function callbackConsumesAttempt()
    {
        $scriptedService = ScriptedSyncService::emitting([]);
        $grant = new AuthorizationGrant(
            accessToken: 'test-access-token',
            refreshToken: 'test-refresh-token',
        );
        $scriptedService->completeConnectionWith($grant);

        app(\ClarionApp\LifeLogBackend\External\HealthServiceRegistry::class)->register(
            ScriptedSyncService::NAME,
            fn () => $scriptedService,
        );

        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => ScriptedSyncService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $state = base64_encode(random_bytes(32));
        $attempt = ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson("/api/clarion-app/life-log/connected-accounts/callback", [
            'external_service' => ScriptedSyncService::NAME,
            'state' => $state,
            'code' => 'authorization-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $attempt->refresh();
        $this->assertNotNull($attempt->consumed_at);
    }
}
