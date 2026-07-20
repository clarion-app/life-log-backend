<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Credentials\VerificationOutcome;
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

class CredentialRemovalTest extends TestCase
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

    /** @test T030 — DELETE soft-deletes the credential */
    public function deleteSoftDeletesCredential(): void
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

        $credentialId = Str::uuid()->toString();
        ServiceCredential::create([
            'id' => $credentialId,
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->deleteJson($this->baseUrl() . '/service-credentials/test-service');

        $response->assertStatus(200);
        $response->assertJson(['removed' => true]);

        // Verify soft-deleted (assert by external_service since the bridge trait may generate its own UUID)
        $this->assertSoftDeleted('life_log_service_credentials', [
            'external_service' => 'test-service',
        ]);

        // Service is unavailable for new connections
        $this->assertFalse(
            app(\ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider::class)
                ->isConfigured('test-service'),
        );
    }

    /** @test T030 — existing ConnectedAccount rows are NOT deleted */
    public function deleteDoesNotDeleteConnectedAccounts(): void
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

        ServiceCredential::create([
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // Create connected account (id generated by trait, not mass-assignable)
        $account = ConnectedAccount::create([
            'user_id' => $this->user->id,
            'external_service' => 'test-service',
        ]);
        $accountId = $account->id;

        // Create sync state
        AccountSyncState::create([
            'connected_account_id' => $accountId,
            'consecutive_failures' => 0,
        ]);

        // Create authorization (id generated by trait, not mass-assignable)
        AccountAuthorization::create([
            'connected_account_id' => $accountId,
            'access_token' => 'access-token-123',
            'refresh_token' => 'refresh-token-123',
            'credential_version' => 1,
        ]);

        $response = $this->deleteJson($this->baseUrl() . '/service-credentials/test-service');

        $response->assertStatus(200);

        // ConnectedAccount still exists
        $account = ConnectedAccount::withTrashed()->find($accountId);
        $this->assertNotNull($account);
        $this->assertNull($account->deleted_at); // Not soft-deleted

        // Account is marked needs_attention with credential_removed
        $this->assertSame('needs_attention', $account->sync_state);

        $syncState = AccountSyncState::where('connected_account_id', $accountId)->first();
        $this->assertSame('credential_removed', $syncState->needs_attention_reason);

        // AccountAuthorization still exists
        $auth = AccountAuthorization::where('connected_account_id', $accountId)->first();
        $this->assertNotNull($auth);

        // Response reports connections_marked_needing_attention count
        $response->assertJson(['connections_marked_needing_attention' => 1]);
    }

    /** @test T030 — response reports connections_marked_needing_attention count */
    public function deleteReportsConnectionsMarkedNeedingAttentionCount(): void
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

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // Create 3 connected accounts (each with unique user to satisfy UNIQUE(user_id, external_service))
        for ($i = 0; $i < 3; $i++) {
            $loopUser = User::forceCreate([
                'id' => Str::uuid()->toString(),
                'name' => "Test User $i",
                'email' => "test$i@example.com",
                'password' => bcrypt('password'),
            ]);
            $account = ConnectedAccount::create([
                'user_id' => $loopUser->id,
                'external_service' => 'test-service',
            ]);
            AccountSyncState::create([
                'connected_account_id' => $account->id,
                'consecutive_failures' => 0,
            ]);
        }

        $response = $this->deleteJson($this->baseUrl() . '/service-credentials/test-service');

        $response->assertStatus(200);
        $response->assertJson(['removed' => true, 'connections_marked_needing_attention' => 3]);
    }

    /** @test T030 — when no connections exist, count is 0 */
    public function deleteWithNoConnectionsReportsZero(): void
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

        ServiceCredential::create([
            'id' => Str::uuid()->toString(),
            'external_service' => 'test-service',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response = $this->deleteJson($this->baseUrl() . '/service-credentials/test-service');

        $response->assertStatus(200);
        $response->assertJson(['removed' => true, 'connections_marked_needing_attention' => 0]);
    }
}
