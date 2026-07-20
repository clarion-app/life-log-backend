<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Illuminate\Support\Str;
use Tests\Support\ScriptedSyncService;
use Tests\TestCase;

/**
 * FR-022: when the provider cannot confirm revocation — because it throws,
 * or because it is simply unreachable — local teardown still completes in
 * full, and the caller is told the revocation was not confirmed rather than
 * being left with a connection that neither works nor goes away.
 */
class DisconnectionRevocationFailureTest extends TestCase
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
        ]);
    }

    protected function disconnectUrl(): string
    {
        return '/api/clarion-app/life-log/connected-accounts/' . $this->account->id;
    }

    /** @test */
    public function aThrownFailureStillCompletesLocalTeardown()
    {
        $this->service->disconnectThrows(HealthServiceFailure::serviceUnavailable('unreachable'));

        $response = $this->deleteJson($this->disconnectUrl());

        $response->assertStatus(200);
        $response->assertExactJson([
            'disconnected' => true,
            'revocation_confirmed' => false,
        ]);

        $this->assertNull(ConnectedAccount::find($this->account->id));
        $this->assertSame(0, AccountAuthorization::where('connected_account_id', $this->account->id)->count());
        $this->assertSame(0, AccountSyncState::where('connected_account_id', $this->account->id)->count());
    }

    /** @test */
    public function anUnreachableProviderResultStillCompletesLocalTeardown()
    {
        $this->service->disconnectReturns(DisconnectResult::localOnly());

        $response = $this->deleteJson($this->disconnectUrl());

        $response->assertStatus(200);
        $response->assertExactJson([
            'disconnected' => true,
            'revocation_confirmed' => false,
        ]);

        $this->assertNull(ConnectedAccount::find($this->account->id));
        $this->assertSame(0, AccountAuthorization::where('connected_account_id', $this->account->id)->count());
    }

    /** @test */
    public function anUnexpectedThrowableIsAlsoTreatedAsUnconfirmed()
    {
        $this->service->disconnectThrows(new \RuntimeException('connection reset by peer'));

        $response = $this->deleteJson($this->disconnectUrl());

        $response->assertStatus(200);
        $response->assertExactJson([
            'disconnected' => true,
            'revocation_confirmed' => false,
        ]);

        $this->assertNull(ConnectedAccount::find($this->account->id));
    }
}
