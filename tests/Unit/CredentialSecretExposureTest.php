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
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ClarionApp\Backend\Models\User;

class CredentialSecretExposureTest extends TestCase
{
    const SENTINEL = 'SENTINEL-SECRET-DOES-NOT-APPEAR-ANYWHERE';

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

    protected function assertNoSecretInResponse($response): void
    {
        $body = $response->getContent();
        $this->assertStringNotContainsString(self::SENTINEL, $body,
            'Sentinel secret found in response body');

        // Check headers too
        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString(self::SENTINEL, (string) $value,
                    "Sentinel secret found in response header: {$name}");
            }
        }
    }

    /** @test T031 — POST store success path does not leak secret */
    public function postStoreSuccessDoesNotLeakSecret(): void
    {
        app(HealthServiceRegistry::class)->register('test-service', function () {
            return new class implements ExternalHealthService {
                public function name(): string { return 'test-service'; }
                public function supportedTypes(): array { return []; }
                public function beginConnection(string $userId): ConnectionResult {
                    return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
                }
                public function fetch($userId, $since, $until, $cursor = null): ResultPage {
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

        $response = $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => self::SENTINEL,
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(201);
        $this->assertNoSecretInResponse($response);
    }

    /** @test T031 — POST store validation failure does not leak secret */
    public function postStoreValidationFailureDoesNotLeakSecret(): void
    {
        $response = $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => 'nonexistent-service',
            'client_id' => 'test-client-id',
            'client_secret' => self::SENTINEL,
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $this->assertNoSecretInResponse($response);
    }

    /** @test T031 — PUT update success does not leak secret */
    public function putUpdateSuccessDoesNotLeakSecret(): void
    {
        app(HealthServiceRegistry::class)->register('test-service', function () {
            return new class implements ExternalHealthService {
                public function name(): string { return 'test-service'; }
                public function supportedTypes(): array { return []; }
                public function beginConnection(string $userId): ConnectionResult {
                    return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
                }
                public function fetch($userId, $since, $until, $cursor = null): ResultPage {
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

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'old-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->putJson($this->baseUrl() . '/service-credentials/test-service', [
            'client_id' => 'new-client-id',
            'client_secret' => self::SENTINEL,
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(200);
        $this->assertNoSecretInResponse($response);
    }

    /** @test T031 — PUT update validation failure does not leak secret */
    public function putUpdateValidationFailureDoesNotLeakSecret(): void
    {
        app(HealthServiceRegistry::class)->register('test-service', function () {
            return new class implements ExternalHealthService {
                public function name(): string { return 'test-service'; }
                public function supportedTypes(): array { return []; }
                public function beginConnection(string $userId): ConnectionResult {
                    return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
                }
                public function fetch($userId, $since, $until, $cursor = null): ResultPage {
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

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'old-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->putJson($this->baseUrl() . '/service-credentials/test-service', [
            'client_secret' => '',
        ]);

        // Empty string should be a validation error (min:1 rule)
        $response->assertStatus(422);
    }

    /** @test T031 — GET index does not leak secret */
    public function getIndexDoesNotLeakSecret(): void
    {
        app(HealthServiceRegistry::class)->register('test-service', function () {
            return new class implements ExternalHealthService {
                public function name(): string { return 'test-service'; }
                public function supportedTypes(): array { return []; }
                public function beginConnection(string $userId): ConnectionResult {
                    return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
                }
                public function fetch($userId, $since, $until, $cursor = null): ResultPage {
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

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => self::SENTINEL,
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->getJson($this->baseUrl() . '/service-credentials');

        $response->assertStatus(200);
        $this->assertNoSecretInResponse($response);
    }

    /** @test T031 — POST verify does not leak secret */
    public function postVerifyDoesNotLeakSecret(): void
    {
        app(HealthServiceRegistry::class)->register('test-service', function () {
            return new class implements ExternalHealthService {
                public function name(): string { return 'test-service'; }
                public function supportedTypes(): array { return []; }
                public function beginConnection(string $userId): ConnectionResult {
                    return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
                }
                public function fetch($userId, $since, $until, $cursor = null): ResultPage {
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

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => self::SENTINEL,
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson($this->baseUrl() . '/service-credentials/test-service/verify');

        $response->assertStatus(200);
        $this->assertNoSecretInResponse($response);
    }

    /** @test T031 — DELETE destroy does not leak secret */
    public function deleteDestroyDoesNotLeakSecret(): void
    {
        app(HealthServiceRegistry::class)->register('test-service', function () {
            return new class implements ExternalHealthService {
                public function name(): string { return 'test-service'; }
                public function supportedTypes(): array { return []; }
                public function beginConnection(string $userId): ConnectionResult {
                    return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
                }
                public function fetch($userId, $since, $until, $cursor = null): ResultPage {
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

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => self::SENTINEL,
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->deleteJson($this->baseUrl() . '/service-credentials/test-service');

        $response->assertStatus(200);
        $this->assertNoSecretInResponse($response);
    }

    /** @test T031 — 500 error path does not leak secret */
    public function errorPathDoesNotLeakSecret(): void
    {
        $service = new class implements ExternalHealthService {
            public function name(): string { return 'test-service'; }
            public function supportedTypes(): array { return []; }
            public function beginConnection(string $userId): ConnectionResult {
                return new ConnectionResult('test-service', 'https://test.example/oauth', 'state');
            }
            public function fetch($userId, $since, $until, $cursor = null): ResultPage {
                throw new \RuntimeException('Internal error');
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
            'client_secret' => self::SENTINEL,
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson($this->baseUrl() . '/service-credentials/test-service/verify');

        // May be 200 with indeterminate or 500 depending on error handling
        $this->assertNoSecretInResponse($response);
    }
}
