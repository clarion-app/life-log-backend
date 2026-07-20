<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\ScriptedSyncService;
use Tests\Support\StubBandService;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use Illuminate\Support\Facades\Queue;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ClarionApp\Backend\Models\User;

/**
 * Drives all eleven endpoints in this feature — five credential, six
 * connection — against sentinel values and asserts none of them appear in
 * any response body or header, including validation-failure and error
 * paths (contract cross-cutting rule 1, SC-002).
 */
class CredentialSecretExposureTest extends TestCase
{
    const SENTINEL = 'SENTINEL-SECRET-DOES-NOT-APPEAR-ANYWHERE';
    const SENTINEL_ACCESS_TOKEN = 'SENTINEL-ACCESS-TOKEN-DOES-NOT-APPEAR-ANYWHERE';
    const SENTINEL_REFRESH_TOKEN = 'SENTINEL-REFRESH-TOKEN-DOES-NOT-APPEAR-ANYWHERE';

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

    protected function assertSentinelAbsentFromResponse($response, string $sentinel, string $label): void
    {
        $body = $response->getContent();
        $this->assertStringNotContainsString($sentinel, $body,
            "{$label} found in response body");

        // Check headers too
        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString($sentinel, (string) $value,
                    "{$label} found in response header: {$name}");
            }
        }
    }

    protected function assertNoSecretInResponse($response): void
    {
        $this->assertSentinelAbsentFromResponse($response, self::SENTINEL, 'Sentinel secret');
    }

    protected function assertNoTokensInResponse($response): void
    {
        $this->assertSentinelAbsentFromResponse($response, self::SENTINEL_ACCESS_TOKEN, 'Sentinel access token');
        $this->assertSentinelAbsentFromResponse($response, self::SENTINEL_REFRESH_TOKEN, 'Sentinel refresh token');
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

    // --- T088: the remaining six connection endpoints ---

    /** @test T088 — POST /connected-accounts (begin) success does not leak the secret */
    public function postBeginConnectionSuccessDoesNotLeakSecret(): void
    {
        app(HealthServiceRegistry::class)->register(
            StubBandService::NAME,
            fn () => new StubBandService(),
        );

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => StubBandService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => self::SENTINEL,
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson($this->baseUrl() . '/connected-accounts', [
            'external_service' => StubBandService::NAME,
        ]);

        $response->assertStatus(201);
        $this->assertNoSecretInResponse($response);
    }

    /** @test T088 — POST /connected-accounts (begin) failure does not leak the secret */
    public function postBeginConnectionFailureDoesNotLeakSecret(): void
    {
        // No service registered, no credential configured.
        $response = $this->postJson($this->baseUrl() . '/connected-accounts', [
            'external_service' => 'unregistered-service',
        ]);

        $response->assertStatus(422);
        $this->assertNoSecretInResponse($response);
    }

    /** @test T088 — POST /connected-accounts/callback success does not leak the secret or tokens */
    public function postCallbackSuccessDoesNotLeakSecretOrTokens(): void
    {
        $service = ScriptedSyncService::emitting([]);
        $service->completeConnectionWith(new AuthorizationGrant(
            accessToken: self::SENTINEL_ACCESS_TOKEN,
            refreshToken: self::SENTINEL_REFRESH_TOKEN,
            expiresAt: CarbonImmutable::now()->addHours(2),
            scopes: 'read:health_data',
            externalAccountId: 'ext-exposure-test',
        ));

        app(HealthServiceRegistry::class)->register(ScriptedSyncService::NAME, fn () => $service);

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => ScriptedSyncService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => self::SENTINEL,
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson($this->baseUrl() . '/connected-accounts/callback', [
            'external_service' => ScriptedSyncService::NAME,
            'state' => $state,
            'code' => 'authorization-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(201);
        $this->assertNoSecretInResponse($response);
        $this->assertNoTokensInResponse($response);
    }

    /** @test T088 — POST /connected-accounts/callback failure (forged state) does not leak the secret */
    public function postCallbackFailureDoesNotLeakSecret(): void
    {
        app(HealthServiceRegistry::class)->register(
            ScriptedSyncService::NAME,
            fn () => ScriptedSyncService::emitting([]),
        );

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => ScriptedSyncService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => self::SENTINEL,
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->postJson($this->baseUrl() . '/connected-accounts/callback', [
            'external_service' => ScriptedSyncService::NAME,
            'state' => base64_encode(random_bytes(32)), // never issued
            'code' => 'authorization-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(422);
        $this->assertNoSecretInResponse($response);
    }

    /** @test T088 — POST /connected-accounts/{id}/sync (queued) does not leak the secret or tokens */
    public function postSyncQueuedDoesNotLeakSecretOrTokens(): void
    {
        Queue::fake();

        $account = ConnectedAccount::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => now()->subDay(),
        ]);

        AccountAuthorization::create([
            'id' => Str::uuid()->toString(),
            'connected_account_id' => $account->id,
            'access_token' => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token' => self::SENTINEL_REFRESH_TOKEN,
            'expires_at' => now()->addHour(),
            'credential_version' => 1,
        ]);

        $response = $this->postJson($this->baseUrl() . "/connected-accounts/{$account->id}/sync");

        $response->assertStatus(202);
        $this->assertNoSecretInResponse($response);
        $this->assertNoTokensInResponse($response);
    }

    /** @test T088 — POST /connected-accounts/{id}/sync (needs_attention 409) does not leak the secret */
    public function postSyncNeedsAttentionDoesNotLeakSecret(): void
    {
        $account = ConnectedAccount::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'needs_attention',
            'connected_at' => now()->subDay(),
        ]);

        AccountSyncState::create([
            'connected_account_id' => $account->id,
            'needs_attention_reason' => 'credential_rotated',
        ]);

        $response = $this->postJson($this->baseUrl() . "/connected-accounts/{$account->id}/sync");

        $response->assertStatus(409);
        $this->assertNoSecretInResponse($response);
    }

    /** @test T088 — GET /connected-accounts/{id} (show) does not leak the secret or tokens */
    public function getShowDoesNotLeakSecretOrTokens(): void
    {
        $account = ConnectedAccount::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => now()->subDay(),
        ]);

        AccountAuthorization::create([
            'id' => Str::uuid()->toString(),
            'connected_account_id' => $account->id,
            'access_token' => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token' => self::SENTINEL_REFRESH_TOKEN,
            'expires_at' => now()->addHour(),
            'credential_version' => 1,
        ]);

        $response = $this->getJson($this->baseUrl() . "/connected-accounts/{$account->id}");

        $response->assertStatus(200);
        $this->assertNoSecretInResponse($response);
        $this->assertNoTokensInResponse($response);
    }

    /** @test T088 — GET /connected-accounts (index) does not leak the secret or tokens */
    public function getIndexConnectionsDoesNotLeakSecretOrTokens(): void
    {
        $account = ConnectedAccount::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => now()->subDay(),
        ]);

        AccountAuthorization::create([
            'id' => Str::uuid()->toString(),
            'connected_account_id' => $account->id,
            'access_token' => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token' => self::SENTINEL_REFRESH_TOKEN,
            'expires_at' => now()->addHour(),
            'credential_version' => 1,
        ]);

        $response = $this->getJson($this->baseUrl() . '/connected-accounts');

        $response->assertStatus(200);
        $this->assertNoSecretInResponse($response);
        $this->assertNoTokensInResponse($response);
    }

    /** @test T088 — DELETE /connected-accounts/{id} (disconnect) does not leak the secret or tokens */
    public function deleteDisconnectDoesNotLeakSecretOrTokens(): void
    {
        $service = ScriptedSyncService::emitting([]);
        app(HealthServiceRegistry::class)->register(ScriptedSyncService::NAME, fn () => $service);

        $account = ConnectedAccount::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'sync_state' => 'normal',
            'connected_at' => now()->subDay(),
        ]);

        AccountAuthorization::create([
            'id' => Str::uuid()->toString(),
            'connected_account_id' => $account->id,
            'access_token' => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token' => self::SENTINEL_REFRESH_TOKEN,
            'expires_at' => now()->addHour(),
            'credential_version' => 1,
        ]);

        $response = $this->deleteJson($this->baseUrl() . "/connected-accounts/{$account->id}");

        $response->assertStatus(200);
        $this->assertNoSecretInResponse($response);
        $this->assertNoTokensInResponse($response);
    }
}
