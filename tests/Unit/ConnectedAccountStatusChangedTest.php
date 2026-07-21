<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Events\ConnectedAccountStatusChanged;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Broadcast;

/**
 * T046: FR-021 broadcast event contract tests.
 *
 * The event implements ShouldBroadcastNow, broadcasts on the owning
 * user's private channel, carries the same payload as the index entry,
 * and contains no secrets.
 */
class ConnectedAccountStatusChangedTest extends \Tests\TestCase
{
    private string $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Register a unique service per test run
        $this->service = 'fake-' . Str::uuid();
        $registry = app(HealthServiceRegistry::class);
        $serviceName = $this->service;
        $registry->register($this->service, function () use ($serviceName) {
            return new class ($serviceName) implements ExternalHealthService {
                public function __construct(private string $name) {}
                public function name(): string { return $this->name; }
                public function supportedTypes(): array {
                    return [MeasurementType::Steps];
                }
                public function maxWindow(MeasurementType|SessionType $type): ?\DateInterval { return null; }
                public function beginConnection(string $userId): \ClarionApp\LifeLogBackend\External\ConnectionResult {
                    throw new \RuntimeException('not implemented');
                }
                public function completeConnection(string $userId, string $code, string $redirectUri): \ClarionApp\LifeLogBackend\External\AuthorizationGrant {
                    throw new \RuntimeException('not implemented');
                }
                public function fetch(string $userId, \Carbon\CarbonImmutable $since, \Carbon\CarbonImmutable $until, ?\ClarionApp\LifeLogBackend\External\PageCursor $cursor = null, ?array $types = null): \ClarionApp\LifeLogBackend\External\ResultPage {
                    throw new \RuntimeException('not implemented');
                }
                public function renewAccess(string $userId): \ClarionApp\LifeLogBackend\External\RenewalResult {
                    throw new \RuntimeException('not implemented');
                }
                public function disconnect(string $userId): \ClarionApp\LifeLogBackend\External\DisconnectResult {
                    throw new \RuntimeException('not implemented');
                }
            };
        });
    }

    /** @test */
    public function implementsShouldBroadcastNow()
    {
        $event = new ConnectedAccountStatusChanged(
            $this->makeAccount()
        );

        $this->assertInstanceOf(\Illuminate\Contracts\Broadcasting\ShouldBroadcastNow::class, $event);
    }

    /** @test */
    public function broadcastsOnOwningUserChannel()
    {
        $account = $this->makeAccount();
        $event = new ConnectedAccountStatusChanged($account);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-User.{$account->user_id}", $channels[0]->name);
    }

    /** @test */
    public function broadcastPayloadMatchesIndexEntry()
    {
        $account = $this->makeAccount();
        $event = new ConnectedAccountStatusChanged($account);

        $payload = $event->broadcastWith();

        // All index fields present
        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('external_service', $payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('last_successful_sync_at', $payload);
        $this->assertArrayHasKey('connected_at', $payload);
        $this->assertArrayHasKey('needs_attention_reason', $payload);
        $this->assertArrayHasKey('granted_scopes', $payload);
        $this->assertArrayHasKey('granted_types', $payload);
        $this->assertArrayHasKey('missing_types', $payload);
    }

    /** @test */
    public function broadcastPayloadContainsNoSecrets()
    {
        $account = $this->makeAccount(
            accessToken: 'SENTINEL_ACCESS_TOKEN_12345',
            refreshToken: 'SENTINEL_REFRESH_TOKEN_67890',
            scopes: 'activity_and_fitness'
        );

        $event = new ConnectedAccountStatusChanged($account);
        $payload = $event->broadcastWith();
        $payloadJson = json_encode($payload);

        $this->assertStringNotContainsString('SENTINEL_ACCESS_TOKEN_12345', $payloadJson);
        $this->assertStringNotContainsString('SENTINEL_REFRESH_TOKEN_67890', $payloadJson);
        $this->assertStringNotContainsString('access_token', $payloadJson);
        $this->assertStringNotContainsString('refresh_token', $payloadJson);
        $this->assertStringNotContainsString('credential_version', $payloadJson);
    }

    /** @test */
    public function broadcastPayloadIsFullSnapshot()
    {
        $account = $this->makeAccount(scopes: 'activity_and_fitness');
        $event = new ConnectedAccountStatusChanged($account);

        $payload = $event->broadcastWith();

        // Payload is a full snapshot, not a delta
        $this->assertSame($account->id, $payload['id']);
        $this->assertSame($account->external_service, $payload['external_service']);
        $this->assertSame(['activity_and_fitness'], $payload['granted_scopes']);
        $this->assertSame(['steps'], $payload['granted_types']);
    }

    private function makeAccount(
        string $accessToken = 'tok_abc',
        string $refreshToken = 'ref_xyz',
        ?string $scopes = 'activity_and_fitness',
    ): ConnectedAccount {
        $userId = (string) Str::uuid();
        $accountId = (string) Str::uuid();

        $account = new ConnectedAccount();
        $account->id = $accountId;
        $account->user_id = $userId;
        $account->external_service = $this->service;
        $account->sync_state = 'normal';
        $account->connected_at = now();
        $account->save();

        if ($scopes !== null) {
            $auth = new AccountAuthorization();
            $auth->connected_account_id = $accountId;
            $auth->access_token = $accessToken;
            $auth->refresh_token = $refreshToken;
            $auth->scopes = $scopes;
            $auth->credential_version = 1;
            $auth->save();
        }

        return $account;
    }
}
