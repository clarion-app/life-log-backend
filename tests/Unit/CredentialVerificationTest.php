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
use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ClarionApp\Backend\Models\User;

class CredentialVerificationTest extends TestCase
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

    protected function baseUrl(): string
    {
        return '/api/clarion-app/life-log';
    }

    /** @test T029 — successful provider call → accepted */
    public function successfulProviderCallReturnsAccepted(): void
    {
        $service = new class implements ExternalHealthService {
            public bool $verifyCalled = false;
            public function name(): string { return 'test-service'; }
            public function supportedTypes(): array { return []; }
            public function beginConnection(string $userId): ConnectionResult {
                return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
            }
            public function maxWindow(\ClarionApp\LifeLogBackend\Vocabulary\MeasurementType|\ClarionApp\LifeLogBackend\Vocabulary\SessionType $type): ?\DateInterval { return null; }
                public function fetch(string $userId, \Carbon\CarbonImmutable $since, \Carbon\CarbonImmutable $until, ?\ClarionApp\LifeLogBackend\External\PageCursor $cursor = null, ?array $types = null): ResultPage {
                // Successful fetch means credentials are valid
                $this->verifyCalled = true;
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

        app(HealthServiceRegistry::class)->register('test-service', fn () => $service);

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson($this->baseUrl() . '/service-credentials/test-service/verify');

        $response->assertStatus(200);
        $response->assertJson([
            'outcome' => 'accepted',
        ]);
        $body = $response->getContent();
        $this->assertStringNotContainsString('client_secret', $body);

        // Verify outcome was persisted
        $credential = ServiceCredential::liveByService('test-service')->first();
        $this->assertSame('accepted', $credential->last_verification_outcome);
        $this->assertNotNull($credential->last_verified_at);
    }

    /** @test T029 — CredentialsRejected → rejected */
    public function credentialsRejectedReturnsRejected(): void
    {
        $service = new class implements ExternalHealthService {
            public function name(): string { return 'test-service'; }
            public function supportedTypes(): array { return []; }
            public function beginConnection(string $userId): ConnectionResult {
                return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
            }
            public function maxWindow(\ClarionApp\LifeLogBackend\Vocabulary\MeasurementType|\ClarionApp\LifeLogBackend\Vocabulary\SessionType $type): ?\DateInterval { return null; }
                public function fetch(string $userId, \Carbon\CarbonImmutable $since, \Carbon\CarbonImmutable $until, ?\ClarionApp\LifeLogBackend\External\PageCursor $cursor = null, ?array $types = null): ResultPage {
                throw HealthServiceFailure::credentialsRejected('bad credentials');
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

        app(HealthServiceRegistry::class)->register('test-service', fn () => $service);

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson($this->baseUrl() . '/service-credentials/test-service/verify');

        $response->assertStatus(200);
        $response->assertJson(['outcome' => 'rejected']);

        // Persisted
        $credential = ServiceCredential::liveByService('test-service')->first();
        $this->assertSame('rejected', $credential->last_verification_outcome);
    }

    /** @test T029 — ServiceUnavailable → indeterminate */
    public function serviceUnavailableReturnsIndeterminate(): void
    {
        $service = new class implements ExternalHealthService {
            public function name(): string { return 'test-service'; }
            public function supportedTypes(): array { return []; }
            public function beginConnection(string $userId): ConnectionResult {
                return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
            }
            public function maxWindow(\ClarionApp\LifeLogBackend\Vocabulary\MeasurementType|\ClarionApp\LifeLogBackend\Vocabulary\SessionType $type): ?\DateInterval { return null; }
                public function fetch(string $userId, \Carbon\CarbonImmutable $since, \Carbon\CarbonImmutable $until, ?\ClarionApp\LifeLogBackend\External\PageCursor $cursor = null, ?array $types = null): ResultPage {
                throw HealthServiceFailure::serviceUnavailable('service is down');
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

        app(HealthServiceRegistry::class)->register('test-service', fn () => $service);

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson($this->baseUrl() . '/service-credentials/test-service/verify');

        $response->assertStatus(200);
        $response->assertJson(['outcome' => 'indeterminate']);

        $credential = ServiceCredential::liveByService('test-service')->first();
        $this->assertSame('indeterminate', $credential->last_verification_outcome);
    }

    /** @test T029 — RateLimited → indeterminate */
    public function rateLimitedReturnsIndeterminate(): void
    {
        $service = new class implements ExternalHealthService {
            public function name(): string { return 'test-service'; }
            public function supportedTypes(): array { return []; }
            public function beginConnection(string $userId): ConnectionResult {
                return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
            }
            public function maxWindow(\ClarionApp\LifeLogBackend\Vocabulary\MeasurementType|\ClarionApp\LifeLogBackend\Vocabulary\SessionType $type): ?\DateInterval { return null; }
                public function fetch(string $userId, \Carbon\CarbonImmutable $since, \Carbon\CarbonImmutable $until, ?\ClarionApp\LifeLogBackend\External\PageCursor $cursor = null, ?array $types = null): ResultPage {
                throw HealthServiceFailure::rateLimited(60, 'rate limited');
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

        app(HealthServiceRegistry::class)->register('test-service', fn () => $service);

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson($this->baseUrl() . '/service-credentials/test-service/verify');

        $response->assertStatus(200);
        $response->assertJson(['outcome' => 'indeterminate']);
    }

    /** @test T029 — transport error → indeterminate */
    public function transportErrorReturnsIndeterminate(): void
    {
        $service = new class implements ExternalHealthService {
            public function name(): string { return 'test-service'; }
            public function supportedTypes(): array { return []; }
            public function beginConnection(string $userId): ConnectionResult {
                return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
            }
            public function maxWindow(\ClarionApp\LifeLogBackend\Vocabulary\MeasurementType|\ClarionApp\LifeLogBackend\Vocabulary\SessionType $type): ?\DateInterval { return null; }
                public function fetch(string $userId, \Carbon\CarbonImmutable $since, \Carbon\CarbonImmutable $until, ?\ClarionApp\LifeLogBackend\External\PageCursor $cursor = null, ?array $types = null): ResultPage {
                throw new \GuzzleHttp\Exception\ConnectException('Connection refused', new \stdClass());
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

        app(HealthServiceRegistry::class)->register('test-service', fn () => $service);

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson($this->baseUrl() . '/service-credentials/test-service/verify');

        $response->assertStatus(200);
        $response->assertJson(['outcome' => 'indeterminate']);
    }

    /** @test T029 — result body carries no secret */
    public function resultBodyCarriesNoSecret(): void
    {
        $service = new class implements ExternalHealthService {
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

        app(HealthServiceRegistry::class)->register('test-service', fn () => $service);

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'super-secret-value',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson($this->baseUrl() . '/service-credentials/test-service/verify');

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringNotContainsString('super-secret-value', $body);
        $this->assertStringNotContainsString('client_secret', $body);
    }
}
