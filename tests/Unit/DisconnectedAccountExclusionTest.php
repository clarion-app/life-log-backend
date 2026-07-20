<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Sync\AccountSyncRunner;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ScriptedSyncService;
use Tests\TestCase;

/**
 * FR-021: once disconnected, an account is inert. It is skipped by the
 * scheduled sweep and by on-demand sync, an in-flight job cannot recreate
 * what disconnection tore down, and reconnecting the same service starts a
 * fresh connection through the ordinary consent flow rather than failing on
 * a leftover row.
 */
class DisconnectedAccountExclusionTest extends TestCase
{
    protected $user;
    protected ConnectedAccount $account;

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
        ]);
    }

    protected function disconnect(): void
    {
        $this->deleteJson('/api/clarion-app/life-log/connected-accounts/' . $this->account->id)
            ->assertStatus(200);
    }

    /** @test */
    public function theHourlySweepSkipsADisconnectedAccount()
    {
        $this->disconnect();

        Queue::fake();

        $this->artisan('life-log:sync-accounts')->run();

        Queue::assertNotPushed(
            SyncConnectedAccountJob::class,
            fn ($job) => $job->connectedAccountId === $this->account->id,
        );
    }

    /** @test */
    public function onDemandSyncReturns404ForADisconnectedAccount()
    {
        $this->disconnect();

        Queue::fake();

        $response = $this->postJson(
            '/api/clarion-app/life-log/connected-accounts/' . $this->account->id . '/sync'
        );

        $response->assertStatus(404);
        Queue::assertNothingPushed();
    }

    /** @test */
    public function anInFlightJobDoesNotRecreateADisconnectedAccountsBookkeeping()
    {
        $accountId = $this->account->id;

        $this->disconnect();

        // The job was queued before disconnection landed and only runs now.
        // It must find nothing to act on rather than resurrecting rows.
        $job = new SyncConnectedAccountJob($accountId, SyncTrigger::OnDemand);
        $job->handle(app(AccountSyncRunner::class));

        $this->assertNull(ConnectedAccount::find($accountId));
        $this->assertSame(0, AccountSyncState::where('connected_account_id', $accountId)->count());
        $this->assertSame(0, AccountAuthorization::where('connected_account_id', $accountId)->count());
    }

    /** @test */
    public function reconnectingTheSameServiceEstablishesAFreshConnection()
    {
        $this->disconnect();

        $scriptedService = ScriptedSyncService::emitting([]);
        $grant = new AuthorizationGrant(
            accessToken: 'fresh-access-token',
            refreshToken: 'fresh-refresh-token',
            scopes: 'read',
        );
        $scriptedService->completeConnectionWith($grant);

        app(HealthServiceRegistry::class)->register(
            ScriptedSyncService::NAME,
            fn () => $scriptedService,
        );

        ServiceCredential::create([
            'id' => (string) Str::uuid(),
            'external_service' => ScriptedSyncService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
            'version' => 1,
        ]);

        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/clarion-app/life-log/connected-accounts/callback', [
            'external_service' => ScriptedSyncService::NAME,
            'state' => $state,
            'code' => 'authorization-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(201);

        // Exactly one live connection for this (user, service) — the
        // disconnected row was restored, not duplicated.
        $accounts = ConnectedAccount::where('user_id', $this->user->id)
            ->where('external_service', ScriptedSyncService::NAME)
            ->get();
        $this->assertCount(1, $accounts);

        $fresh = $accounts->first();
        $this->assertNull($fresh->deleted_at);
        $this->assertEquals('normal', $fresh->sync_state);

        // It carries a live authorization and appears in the list again.
        $this->assertSame(1, AccountAuthorization::where('connected_account_id', $fresh->id)->count());

        $listResponse = $this->getJson('/api/clarion-app/life-log/connected-accounts');
        $listResponse->assertJsonCount(1, 'connections');
    }
}
