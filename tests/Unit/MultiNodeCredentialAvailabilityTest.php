<?php

namespace Tests\Unit;

use ClarionApp\Backend\Models\User;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\StubBandService;
use Tests\TestCase;

/**
 * SC-002a: a credential configured on one node must be usable for new
 * connections on a second, without a restart or a per-node configuration
 * step (FR-008, FR-009).
 *
 * Two things have to hold together for that to be true, and this test
 * checks both, using the bridge harness pattern from BridgeHarnessTest:
 *
 *  1. The credential actually leaves the configuring node — ServiceCredential
 *     is bridged, so saving one publishes it to the chain stream a second
 *     node's listener would subscribe to. (BridgeExclusionTest already
 *     proves the model is bridged in isolation; this test drives it through
 *     the real store endpoint instead of a bare model save.)
 *  2. Reading it back requires nothing that only exists on the configuring
 *     node's request. ServiceCredentialProvider is scoped, not singleton
 *     (research §10), so a brand-new instance with forgetScopedInstances()
 *     — the same "next job/request" simulation Phase 8 established in
 *     CredentialRotationTest — must find it and begin a connection with it
 *     purely from what is in the database.
 */
class MultiNodeCredentialAvailabilityTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test T090 */
    public function aCredentialConfiguredOnOneNodeIsUsableForANewConnectionOnAnother(): void
    {
        $chain = $this->enableRecordingBridge();

        DB::table('data_stream_registries')->insertOrIgnore([
            [
                'class_name' => ServiceCredential::class,
                'data_stream' => 'life_log_service_credentials',
            ],
        ]);

        app(HealthServiceRegistry::class)->register(
            StubBandService::NAME,
            fn () => new StubBandService(),
        );

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
        ]);
        $this->actingAs($user);

        // Node A: configure the credential through the real endpoint.
        $this->postJson('/api/clarion-app/life-log/service-credentials', [
            'external_service' => StubBandService::NAME,
            'client_id' => 'node-a-client-id',
            'client_secret' => 'node-a-secret',
            'redirect_uri' => 'https://example.com/callback',
        ])->assertStatus(201);

        // The replication mechanism a second node depends on actually fired.
        // (User::create() above is itself bridged to its own stream, so
        // filter rather than assume this is the only publish.)
        $credentialPublishes = array_values(array_filter(
            $chain->published,
            fn (array $p) => $p['stream'] === 'life_log_service_credentials',
        ));
        $this->assertCount(1, $credentialPublishes);

        // Node B: an independent, un-memoised request. forgetScopedInstances()
        // is what a queue worker (or, here, an unrelated later request) calls
        // between units of work — it is what stands in for "a different node"
        // when the test harness has only one process and one database to
        // work with, per the pattern CredentialRotationTest established for
        // rotation staleness.
        $this->app->forgetScopedInstances();

        $provider = app(ServiceCredentialProvider::class);
        $this->assertTrue($provider->isConfigured(StubBandService::NAME));

        $credential = $provider->require(StubBandService::NAME);
        $this->assertEquals('node-a-secret', $credential->client_secret);

        // And it is not merely readable — it is usable to begin a fresh
        // connection, end to end through the real endpoint, with nothing
        // left over from node A's request.
        $begin = $this->postJson('/api/clarion-app/life-log/connected-accounts', [
            'external_service' => StubBandService::NAME,
        ]);

        $begin->assertStatus(201);
        $this->assertDatabaseCount('life_log_connection_attempts', 1);

        $attempt = ConnectionAttempt::first();
        $this->assertEquals($user->id, $attempt->user_id);
        $this->assertEquals(StubBandService::NAME, $attempt->external_service);
    }
}
