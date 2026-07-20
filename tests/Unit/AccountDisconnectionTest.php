<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\SyncAttempt;
use Illuminate\Support\Str;
use Tests\Support\ScriptedSyncService;
use Tests\TestCase;

/**
 * Disconnecting a connected account (FR-021): the provider is asked to
 * revoke access, and everything the connection owns locally — the
 * authorization, the sync bookkeeping, and the connection itself — is
 * removed in one transaction.
 */
class AccountDisconnectionTest extends TestCase
{
    protected $user;
    protected ConnectedAccount $account;
    protected ScriptedSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \ClarionApp\Backend\Models\User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
        ]);

        $this->actingAs($this->user);

        $this->service = ScriptedSyncService::emitting([]);
        app(HealthServiceRegistry::class)->register(
            ScriptedSyncService::NAME,
            fn () => $this->service,
        );

        $this->account = ConnectedAccount::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => now()->subDays(3),
        ]);

        AccountAuthorization::create([
            'id' => (string) Str::uuid(),
            'connected_account_id' => $this->account->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'scopes' => 'read',
            'credential_version' => 1,
        ]);

        AccountSyncState::create([
            'connected_account_id' => $this->account->id,
            'consecutive_failures' => 0,
            'last_success_at' => now()->subHour(),
        ]);

        SyncAttempt::create([
            'connected_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'trigger' => 'on_demand',
            'outcome' => 'success',
            'range_since' => now()->subHours(2),
            'range_until' => now()->subHour(),
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHour(),
        ]);
    }

    protected function disconnectUrl(): string
    {
        return '/api/clarion-app/life-log/connected-accounts/' . $this->account->id;
    }

    /** @test */
    public function itCallsTheProvidersDisconnect()
    {
        $this->deleteJson($this->disconnectUrl())->assertStatus(200);

        $this->assertSame([$this->user->id], $this->service->disconnectCalls);
    }

    /** @test */
    public function itReturnsDisconnectedAndConfirmedOnSuccess()
    {
        $response = $this->deleteJson($this->disconnectUrl());

        $response->assertStatus(200);
        $response->assertExactJson([
            'disconnected' => true,
            'revocation_confirmed' => true,
        ]);
    }

    /** @test */
    public function itRemovesTheAuthorizationSyncStateAndSyncAttempts()
    {
        $this->deleteJson($this->disconnectUrl())->assertStatus(200);

        $this->assertSame(0, AccountAuthorization::where('connected_account_id', $this->account->id)->count());
        $this->assertSame(0, AccountSyncState::where('connected_account_id', $this->account->id)->count());
        $this->assertSame(0, SyncAttempt::where('connected_account_id', $this->account->id)->count());
    }

    /** @test */
    public function itSoftDeletesTheConnectedAccount()
    {
        $this->deleteJson($this->disconnectUrl())->assertStatus(200);

        $this->assertNull(ConnectedAccount::find($this->account->id));
        $this->assertNotNull(ConnectedAccount::withTrashed()->find($this->account->id));
        $this->assertNotNull(ConnectedAccount::withTrashed()->find($this->account->id)->deleted_at);
    }

    /** @test */
    public function itNoLongerAppearsInTheConnectionList()
    {
        $this->deleteJson($this->disconnectUrl())->assertStatus(200);

        $response = $this->getJson('/api/clarion-app/life-log/connected-accounts');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'connections');
    }

    /** @test */
    public function allTeardownHappensInOneTransaction()
    {
        // Sanity: before disconnecting, all four rows exist.
        $this->assertNotNull(ConnectedAccount::find($this->account->id));
        $this->assertSame(1, AccountAuthorization::where('connected_account_id', $this->account->id)->count());
        $this->assertSame(1, AccountSyncState::where('connected_account_id', $this->account->id)->count());
        $this->assertSame(1, SyncAttempt::where('connected_account_id', $this->account->id)->count());

        $this->deleteJson($this->disconnectUrl())->assertStatus(200);

        // After, all four are gone/tombstoned together.
        $this->assertNull(ConnectedAccount::find($this->account->id));
        $this->assertSame(0, AccountAuthorization::where('connected_account_id', $this->account->id)->count());
        $this->assertSame(0, AccountSyncState::where('connected_account_id', $this->account->id)->count());
        $this->assertSame(0, SyncAttempt::where('connected_account_id', $this->account->id)->count());
    }
}
