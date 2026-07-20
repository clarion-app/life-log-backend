<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Connection\ConnectionAttemptFactory;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Exceptions\ServiceNotConfiguredException;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use Tests\TestCase;
use Tests\Support\StubBandService;

class BeginConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(
            \ClarionApp\Backend\Models\User::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'hashed',
            ]),
        );

        // Register a test service
        app(HealthServiceRegistry::class)->register(
            StubBandService::NAME,
            fn () => new StubBandService(),
        );
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /** @test */
    public function postConnectedAccountsReturns201WithAuthorizationUrlAndExpiresAt()
    {
        // Configure a credential for the service
        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => StubBandService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts", [
            'external_service' => StubBandService::NAME,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'authorization_url',
            'expires_at',
        ]);

        $data = $response->json();
        $this->assertNotEmpty($data['authorization_url']);
        $this->assertNotEmpty($data['expires_at']);

        // Verify a ConnectionAttempt was created
        $this->assertDatabaseCount('life_log_connection_attempts', 1);
        $attempt = ConnectionAttempt::first();
        $this->assertEquals(auth()->id(), $attempt->user_id);
        $this->assertEquals(StubBandService::NAME, $attempt->external_service);
    }

    /** @test */
    public function plaintextStateOnlyInUrl()
    {
        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => StubBandService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts", [
            'external_service' => StubBandService::NAME,
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        // The authorization_url contains the state
        $this->assertStringContainsString('state=', $data['authorization_url']);

        // Parse the state from the URL
        parse_str(parse_url($data['authorization_url'], PHP_URL_QUERY) ?? '', $queryParams);
        $state = $queryParams['state'] ?? '';

        // The state should NOT appear as a separate field in the response
        $this->assertArrayNotHasKey('state', $data);

        // The state hash should be in the database (not plaintext)
        $attempt = ConnectionAttempt::first();
        $this->assertNotEquals($state, $attempt->state_hash);
        $this->assertEquals(hash('sha256', $state), $attempt->state_hash);
    }

    /** @test */
    public function unconfiguredServiceReturns422ServiceUnavailable()
    {
        // No credential configured for the service
        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts", [
            'external_service' => StubBandService::NAME,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'service_unavailable');

        // No ConnectionAttempt created
        $this->assertDatabaseCount('life_log_connection_attempts', 0);
    }

    /** @test */
    public function serviceUnavailableMessageDisclosesNoReason()
    {
        $response = $this->postJson("/api/clarion-app/life-log/connected-accounts", [
            'external_service' => StubBandService::NAME,
        ]);

        $response->assertStatus(422);
        $data = $response->json();

        // The message should not disclose why the service is unavailable
        // (e.g., should not mention "no credential" or "missing secret")
        if (isset($data['message'])) {
            $message = strtolower($data['message']);
            $this->assertStringNotContainsString('credential', $message);
            $this->assertStringNotContainsString('secret', $message);
        }
    }
}
