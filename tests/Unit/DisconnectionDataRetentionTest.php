<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\HealthSession;
use ClarionApp\LifeLogBackend\Models\RawHealthSession;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use Illuminate\Support\Str;
use Tests\Support\ScriptedSyncService;
use Tests\TestCase;

/**
 * FR-023, SC-005: disconnecting removes the connection, its authorization,
 * and its sync bookkeeping — never the health data already ingested from it.
 * That data belongs to the user, not the connection, and outlives it.
 */
class DisconnectionDataRetentionTest extends TestCase
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

        app(HealthServiceRegistry::class)->register(
            ScriptedSyncService::NAME,
            fn () => ScriptedSyncService::emitting([]),
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

        HealthMetric::create([
            'user_id' => $this->user->id,
            'type' => 'steps',
            'value' => '1000.0000',
            'recorded_at' => now()->subHours(2),
            'source' => ScriptedSyncService::NAME,
            'unit' => 'steps',
            'external_service' => ScriptedSyncService::NAME,
            'bucket_hour' => now()->subHours(2)->startOfHour(),
        ]);

        RawMeasurement::create([
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'external_id' => 'raw-meas-1',
            'type' => 'steps',
            'value' => '1000.0000',
            'unit' => 'steps',
            'recorded_at' => now()->subHours(2),
            'bucket_hour' => now()->subHours(2)->startOfHour(),
        ]);

        HealthSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'external_id' => 'sess-1',
            'session_type' => 'sleep',
            'started_at' => now()->subHours(10),
            'ended_at' => now()->subHours(2),
            'summary_values' => ['duration' => '28800.0000'],
            'source' => ScriptedSyncService::NAME,
        ]);

        RawHealthSession::create([
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'external_id' => 'raw-sess-1',
            'session_type' => 'sleep',
            'started_at' => now()->subHours(10),
            'ended_at' => now()->subHours(2),
            'summary_values' => ['duration' => '28800.0000'],
        ]);
    }

    protected function disconnectUrl(): string
    {
        return '/api/clarion-app/life-log/connected-accounts/' . $this->account->id;
    }

    /** @test */
    public function everyIngestedRecordSurvivesDisconnection()
    {
        $this->deleteJson($this->disconnectUrl())->assertStatus(200);

        $this->assertSame(1, HealthMetric::where('user_id', $this->user->id)->count());
        $this->assertSame(1, RawMeasurement::where('user_id', $this->user->id)->count());
        $this->assertSame(1, HealthSession::where('user_id', $this->user->id)->count());
        $this->assertSame(1, RawHealthSession::where('user_id', $this->user->id)->count());
    }

    /** @test */
    public function theConnectionAndItsOwnRecordsAreStillRemoved()
    {
        $this->deleteJson($this->disconnectUrl())->assertStatus(200);

        $this->assertNull(ConnectedAccount::find($this->account->id));
        $this->assertSame(0, AccountAuthorization::where('connected_account_id', $this->account->id)->count());
    }
}
