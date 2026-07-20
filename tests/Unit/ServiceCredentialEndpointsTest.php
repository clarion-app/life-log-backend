<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ClarionApp\Backend\Models\User;

class ServiceCredentialEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpUser();
    }

    protected function setUpUser(): void
    {
        $this->user = User::forceCreate([
            'id' => Str::uuid()->toString(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($this->user, 'api');
    }

    protected function registerTestService(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $registry->register('test-service', function () {
            return new class implements ExternalHealthService {
                public function name(): string { return 'test-service'; }
                public function supportedTypes(): array { return []; }
                public function beginConnection(string $userId): ConnectionResult {
                    return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
                }
                public function maxWindow(\ClarionApp\LifeLogBackend\Vocabulary\MeasurementType|\ClarionApp\LifeLogBackend\Vocabulary\SessionType $type): ?\DateInterval { return null; }
                public function fetch(string $userId, \Carbon\CarbonImmutable $since, \Carbon\CarbonImmutable $until, ?\ClarionApp\LifeLogBackend\External\PageCursor $cursor = null, ?array $types = null): ResultPage {
                    return new ResultPage();
                }
                public function renewAccess(string $userId): RenewalResult {
                    return RenewalResult::renewed(null);
                }
                public function disconnect(string $userId): DisconnectResult {
                    return DisconnectResult::confirmed();
                }
                public function completeConnection(string $userId, string $code, string $redirectUri): AuthorizationGrant {
                    return new AuthorizationGrant('access-token', 'refresh-token');
                }
            };
        });
    }

    protected function baseUrl(): string
    {
        return '/api/clarion-app/life-log';
    }

    // ── POST /service-credentials ──

    /** @test T028 — POST 201 with documented body */
    public function postCreatesCredential(): void
    {
        $this->registerTestService();

        $response = $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'external_service',
            'client_id',
            'redirect_uri',
            'has_secret',
            'secret_updated_at',
            'last_verified_at',
            'last_verification_outcome',
            'version',
        ]);
        $response->assertJson([
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'has_secret' => true,
            'version' => 1,
        ]);
        $responseData = $response->json();
        $this->assertArrayNotHasKey('client_secret', $responseData);

        // Verify the credential was stored
        $this->assertDatabaseHas('life_log_service_credentials', [
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
        ]);
    }

    /** @test T028 — POST on already-configured service → 409 */
    public function postOnAlreadyConfiguredReturns409(): void
    {
        $this->registerTestService();

        // First POST — success
        $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // Second POST — conflict
        $response = $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'test-service',
            'client_id' => 'test-client-id-2',
            'client_secret' => 'test-secret-2',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(409);
        $response->assertJson(['error' => 'already_configured']);
    }

    /** @test T028 — POST on unregistered service → 422 */
    public function postOnUnregisteredServiceReturns422(): void
    {
        $response = $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'nonexistent-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'unknown_service']);
    }

    // ── PUT /service-credentials/{service} ──

    /** @test T028 — PUT with client_secret replaces and increments version */
    public function putWithSecretReplacesAndIncrementsVersion(): void
    {
        $this->registerTestService();

        // Create credential first
        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'old-client-id',
            'client_secret' => 'old-secret',
            'redirect_uri' => 'https://example.com/callback',
            'version' => 1,
        ]);

        $response = $this->putJson($this->baseUrl() . '/service-credentials/test-service', [
            'client_id' => 'new-client-id',
            'client_secret' => 'new-secret',
            'redirect_uri' => 'https://example.com/new-callback',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'external_service' => 'test-service',
            'client_id' => 'new-client-id',
            'version' => 2,
        ]);
        $responseData = $response->json();
        $this->assertArrayNotHasKey('client_secret', $responseData);

        // Verify the update
        $credential = ServiceCredential::liveByService('test-service')->first();
        $this->assertSame('new-secret', $credential->client_secret);
        $this->assertSame(2, $credential->version);
    }

    /** @test T028 — PUT without client_secret preserves secret and version */
    public function putWithoutSecretPreservesSecretAndVersion(): void
    {
        $this->registerTestService();

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'old-client-id',
            'client_secret' => 'original-secret',
            'redirect_uri' => 'https://example.com/callback',
            'version' => 3,
        ]);

        $response = $this->putJson($this->baseUrl() . '/service-credentials/test-service', [
            'client_id' => 'new-client-id',
            'redirect_uri' => 'https://example.com/new-callback',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'client_id' => 'new-client-id',
            'version' => 3,
        ]);

        // Secret should be preserved
        $credential = ServiceCredential::liveByService('test-service')->first();
        $this->assertSame('original-secret', $credential->client_secret);
        $this->assertSame(3, $credential->version);
    }

    /** @test T028 — PUT with empty client_secret → validation error */
    public function putWithEmptySecretReturnsValidationError(): void
    {
        $this->registerTestService();

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->putJson($this->baseUrl() . '/service-credentials/test-service', [
            'client_secret' => '',
        ]);

        $response->assertStatus(422);
        $responseData = $response->json();
        $this->assertArrayNotHasKey('client_secret', $responseData);
    }

    /** @test T028 — PUT on unconfigured service → 404 */
    public function putOnUnconfiguredServiceReturns404(): void
    {
        $this->registerTestService();

        $response = $this->putJson($this->baseUrl() . '/service-credentials/test-service', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(404);
    }

    // ── GET /service-credentials ──

    /** @test T028 — GET lists all registered services including unconfigured */
    public function getListsAllRegisteredServicesIncludingUnconfigured(): void
    {
        $this->registerTestService();

        // Create one credential
        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // Register a second service (unconfigured)
        app(HealthServiceRegistry::class)->register('another-service', function () {
            return new class implements ExternalHealthService {
                public function name(): string { return 'another-service'; }
                public function supportedTypes(): array { return []; }
                public function beginConnection(string $userId): ConnectionResult {
                    return new ConnectionResult('another-service', 'https://test.example/oauth', 'state');
                }
                public function maxWindow(\ClarionApp\LifeLogBackend\Vocabulary\MeasurementType|\ClarionApp\LifeLogBackend\Vocabulary\SessionType $type): ?\DateInterval { return null; }
                public function fetch(string $userId, \Carbon\CarbonImmutable $since, \Carbon\CarbonImmutable $until, ?\ClarionApp\LifeLogBackend\External\PageCursor $cursor = null, ?array $types = null): ResultPage {
                    return new ResultPage();
                }
                public function renewAccess(string $userId): RenewalResult {
                    return RenewalResult::renewed(null);
                }
                public function disconnect(string $userId): DisconnectResult {
                    return DisconnectResult::confirmed();
                }
                public function completeConnection(string $userId, string $code, string $redirectUri): AuthorizationGrant {
                    return new AuthorizationGrant('access-token');
                }
            };
        });

        $response = $this->getJson($this->baseUrl() . '/service-credentials');

        $response->assertStatus(200);
        $response->assertJsonStructure(['services']);

        $services = $response->json('services');
        // google-health is registered by LifeLogBackendServiceProvider + 2 test services
        $this->assertCount(3, $services);

        // Find each service
        $testService = collect($services)->firstWhere('external_service', 'test-service');
        $anotherService = collect($services)->firstWhere('external_service', 'another-service');
        $googleService = collect($services)->firstWhere('external_service', 'google-health');

        $this->assertTrue($testService['configured']);
        $this->assertSame('test-client-id', $testService['client_id']);

        $this->assertFalse($anotherService['configured']);
        $this->assertArrayNotHasKey('client_id', $anotherService);
    }
}
