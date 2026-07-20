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

class InstanceWideCredentialAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestService();
    }

    protected function registerTestService(): void
    {
        app(HealthServiceRegistry::class)->register('test-service', function () {
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
                    return new AuthorizationGrant('access-token');
                }
            };
        });
    }

    protected function createAndActAsUser(string $email): User
    {
        $user = User::forceCreate([
            'id' => Str::uuid()->toString(),
            'name' => 'User ' . $email,
            'email' => $email,
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($user, 'api');
        return $user;
    }

    protected function baseUrl(): string
    {
        return '/api/clarion-app/life-log';
    }

    /** @test T032 — user B may read a credential user A stored */
    public function userBMayReadCredentialStoredByUserA(): void
    {
        // User A stores credential
        $this->createAndActAsUser('userA@example.com');
        $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'user-a-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // User B reads credentials
        $this->createAndActAsUser('userB@example.com');
        $response = $this->getJson($this->baseUrl() . '/service-credentials');

        $response->assertStatus(200);
        $services = $response->json('services');
        $testService = collect($services)->firstWhere('external_service', 'test-service');

        $this->assertNotNull($testService);
        $this->assertTrue($testService['configured']);
        $this->assertSame('test-client-id', $testService['client_id']);
    }

    /** @test T032 — user B may replace a credential user A stored */
    public function userBMayReplaceCredentialStoredByUserA(): void
    {
        // User A stores credential
        $this->createAndActAsUser('userA@example.com');
        $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'test-service',
            'client_id' => 'user-a-client-id',
            'client_secret' => 'user-a-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // User B replaces credential
        $this->createAndActAsUser('userB@example.com');
        $response = $this->putJson($this->baseUrl() . '/service-credentials/test-service', [
            'client_id' => 'user-b-client-id',
            'client_secret' => 'user-b-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'client_id' => 'user-b-client-id',
            'version' => 2,
        ]);
    }

    /** @test T032 — user B may verify a credential user A stored */
    public function userBMayVerifyCredentialStoredByUserA(): void
    {
        // User A stores credential
        $this->createAndActAsUser('userA@example.com');
        $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'user-a-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // User B verifies credential
        $this->createAndActAsUser('userB@example.com');
        $response = $this->postJson($this->baseUrl() . '/service-credentials/test-service/verify');

        $response->assertStatus(200);
        $response->assertJsonStructure(['outcome', 'verified_at']);
    }

    /** @test T032 — user B may remove a credential user A stored */
    public function userBMayRemoveCredentialStoredByUserA(): void
    {
        // User A stores credential
        $this->createAndActAsUser('userA@example.com');
        $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'user-a-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // User B removes credential
        $this->createAndActAsUser('userB@example.com');
        $response = $this->deleteJson($this->baseUrl() . '/service-credentials/test-service');

        $response->assertStatus(200);
        $response->assertJson(['removed' => true]);

        // Verify the credential is gone for everyone
        $this->createAndActAsUser('userC@example.com');
        $getResponse = $this->getJson($this->baseUrl() . '/service-credentials');
        $services = $getResponse->json('services');
        $testService = collect($services)->firstWhere('external_service', 'test-service');

        $this->assertFalse($testService['configured']);
    }

    /** @test T032 — assert permissive behaviour explicitly (no role check) */
    public function credentialsAreInstanceWideNotOwnerScoped(): void
    {
        // This test explicitly documents that credentials are instance-wide.
        // Any authenticated user may manage any credential — no role check,
        // no ownership predicate. This is intentional per FR-025/FR-026.

        // User A creates
        $this->createAndActAsUser('userA@example.com');
        $storeResponse = $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);
        $storeResponse->assertStatus(201);

        // User B can read, update, verify, and delete
        $this->createAndActAsUser('userB@example.com');

        $getResponse = $this->getJson($this->baseUrl() . '/service-credentials');
        $getResponse->assertStatus(200);

        $putResponse = $this->putJson($this->baseUrl() . '/service-credentials/test-service', [
            'client_id' => 'updated-client-id',
        ]);
        $putResponse->assertStatus(200);

        $verifyResponse = $this->postJson($this->baseUrl() . '/service-credentials/test-service/verify');
        $verifyResponse->assertStatus(200);

        $deleteResponse = $this->deleteJson($this->baseUrl() . '/service-credentials/test-service');
        $deleteResponse->assertStatus(200);
    }
}
