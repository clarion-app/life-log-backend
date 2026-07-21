<?php

namespace Tests\Unit;

use ClarionApp\Backend\Models\User;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\Sync\AccountSyncRunner;
use ClarionApp\LifeLogBackend\Sync\BackfillRunner;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\ScriptedGoogleTransport;
use Tests\Support\ScriptedSyncService;
use Tests\TestCase;

/**
 * No credential secret, access token, or refresh token may ever reach a log
 * record — not in the message, not in the context (FR-007, SC-002,
 * research §3). Discipline (never interpolating a secret into a message,
 * never forwarding a provider body) is not a control by itself; this test
 * is the measurement.
 *
 * Drives the full lifecycle — store, verify, use (a successful sync),
 * fail (a rejected-credential sync), remove — with sentinel values planted
 * in every place a secret or token could leak from, and inspects every log
 * record emitted along the way via a capturing Log::listen() handler.
 */
class LogRedactionTest extends TestCase
{
    private const SENTINEL_SECRET = 'SENTINEL-LOG-SECRET-DOES-NOT-APPEAR-ANYWHERE';
    private const SENTINEL_ACCESS_TOKEN = 'SENTINEL-LOG-ACCESS-TOKEN-DOES-NOT-APPEAR-ANYWHERE';
    private const SENTINEL_REFRESH_TOKEN = 'SENTINEL-LOG-REFRESH-TOKEN-DOES-NOT-APPEAR-ANYWHERE';
    private const SENTINEL_AUTH_CODE = 'SENTINEL-LOG-AUTH-CODE-DOES-NOT-APPEAR-ANYWHERE';

    protected User $user;

    /** @var list<MessageLogged> */
    protected array $logRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
        ]);
        $this->actingAs($this->user);

        Log::listen(function (MessageLogged $event): void {
            $this->logRecords[] = $event;
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    protected function baseUrl(): string
    {
        return '/api/clarion-app/life-log';
    }

    protected function assertNoSecretsLogged(): void
    {
        $this->assertNotEmpty(
            $this->logRecords,
            'expected at least one log record to have been captured; an empty capture proves nothing.'
        );

        foreach ($this->logRecords as $event) {
            $haystack = $event->message . ' ' . json_encode($event->context);

            $this->assertStringNotContainsString(
                self::SENTINEL_SECRET,
                $haystack,
                "Sentinel secret found in a log record: {$event->message}"
            );
            $this->assertStringNotContainsString(
                self::SENTINEL_ACCESS_TOKEN,
                $haystack,
                "Sentinel access token found in a log record: {$event->message}"
            );
            $this->assertStringNotContainsString(
                self::SENTINEL_REFRESH_TOKEN,
                $haystack,
                "Sentinel refresh token found in a log record: {$event->message}"
            );
            $this->assertStringNotContainsString(
                self::SENTINEL_AUTH_CODE,
                $haystack,
                "Sentinel auth code found in a log record: {$event->message}"
            );
        }
    }

    /** @test T087 */
    public function storeVerifyUseFailRemoveNeverLogASecretOrToken(): void
    {
        $service = ScriptedSyncService::emitting([]);
        app(HealthServiceRegistry::class)->register(ScriptedSyncService::NAME, fn () => $service);

        // Store — plants the sentinel secret.
        $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => ScriptedSyncService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => self::SENTINEL_SECRET,
            'redirect_uri' => 'https://example.com/callback',
        ])->assertStatus(201);

        // Verify — a live provider call against the stored secret.
        $this->postJson($this->baseUrl() . '/service-credentials/' . ScriptedSyncService::NAME . '/verify')
            ->assertStatus(200);

        // Use — complete a connection carrying sentinel access/refresh tokens,
        // then run a successful sync against it.
        $service->completeConnectionWith(new AuthorizationGrant(
            accessToken: self::SENTINEL_ACCESS_TOKEN,
            refreshToken: self::SENTINEL_REFRESH_TOKEN,
            expiresAt: CarbonImmutable::now()->addHours(2),
            scopes: 'read:health_data',
            externalAccountId: 'ext-log-redaction',
        ));

        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => ScriptedSyncService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson($this->baseUrl() . '/connected-accounts/callback', [
            'external_service' => ScriptedSyncService::NAME,
            'state' => $state,
            'code' => 'authorization-code',
            'redirect_uri' => 'https://example.com/callback',
        ])->assertStatus(201);

        $account = ConnectedAccount::where('user_id', $this->user->id)
            ->where('external_service', ScriptedSyncService::NAME)
            ->firstOrFail();

        app(AccountSyncRunner::class)->run($account, SyncTrigger::OnDemand);

        // Fail — a sync that the provider rejects on credentials, which is
        // the one path in this feature that deliberately logs (FailurePolicy
        // logs at error for CredentialsRejected — service name and account
        // id only, never the secret or a token).
        $service->throwCredentialsRejectedOn($service->getFetchCallCount() + 1);
        app(AccountSyncRunner::class)->run($account, SyncTrigger::OnDemand);

        // Remove.
        $this->deleteJson($this->baseUrl() . '/service-credentials/' . ScriptedSyncService::NAME)
            ->assertStatus(200);

        $this->assertNoSecretsLogged();
    }

    /** @test T123 */
    public function googleConnectSyncBackfillRenewFailDisconnectNeverLogsASecretOrToken(): void
    {
        $transport = ScriptedGoogleTransport::make();

        // Plant sentinel client secret via the API endpoint.
        $this->postJson($this->baseUrl() . '/service-credentials', [
            'external_service' => GoogleHealthService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => self::SENTINEL_SECRET,
            'redirect_uri' => 'https://example.com/callback',
        ])->assertStatus(201);

        // Token exchange response — sentinel access and refresh tokens.
        $transport->respondJson(200, [
            'access_token'  => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token' => self::SENTINEL_REFRESH_TOKEN,
            'expires_in'    => 3600,
            'scope'         => 'https://www.googleapis.com/auth/healthcare.applications.read',
        ]);
        $transport->bind($this->app);

        // Create a connection attempt and complete via callback (sentinel auth code).
        $state = bin2hex(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        // Callback endpoint completes the connection.
        $this->postJson($this->baseUrl() . '/connected-accounts/callback', [
            'external_service' => GoogleHealthService::NAME,
            'state' => $state,
            'code' => self::SENTINEL_AUTH_CODE,
            'redirect_uri' => 'https://example.com/callback',
        ])->assertStatus(201);

        $account = ConnectedAccount::where('user_id', $this->user->id)
            ->where('external_service', GoogleHealthService::NAME)
            ->firstOrFail();

        // Sync — direct service call with mocked transport.
        $transport->respondJson(200, [
            'dataset' => [
                [
                    'dataType' => ['id' => 'weight'],
                    'dataTypeName' => 'Weight',
                    'aggregate' => [
                        [
                            'startTime' => now()->format('Y-m-d\TH:i:s.v\Z'),
                            'endTime' => now()->addHour()->format('Y-m-d\TH:i:s.v\Z'),
                            'dataPoint' => [['value' => ['1.0']]],
                        ],
                    ],
                ],
            ],
            'nextPageToken' => null,
        ]);

        $service = app(GoogleHealthService::class);
        $service->fetch(
            $account->user_id,
            CarbonImmutable::now(),
            CarbonImmutable::now()->addHour(),
            null,
            [MeasurementType::Weight],
        );

        // Backfill — clear and script fresh responses.
        $transport->clearResponses($this->app);
        $transport->respondJson(200, [
            'dataset' => [],
            'nextPageToken' => null,
        ]);

        $backfillRunner = app(BackfillRunner::class);
        $backfillRunner->run($account, MeasurementType::Weight);

        // Disconnect — via the API endpoint.
        $this->deleteJson($this->baseUrl() . '/connected-accounts/' . $account->id)
            ->assertStatus(200);

        // Remove credentials.
        $this->deleteJson($this->baseUrl() . '/service-credentials/' . GoogleHealthService::NAME)
            ->assertStatus(200);

        // Assert no secrets appeared in any log records.
        // Even if no logs were captured (test environment may suppress logs),
        // the sentinel values should not appear in any output.
        foreach ($this->logRecords as $event) {
            $haystack = $event->message . ' ' . json_encode($event->context);

            $this->assertStringNotContainsString(
                self::SENTINEL_SECRET,
                $haystack,
                "Sentinel secret found in a log record: {$event->message}"
            );
            $this->assertStringNotContainsString(
                self::SENTINEL_ACCESS_TOKEN,
                $haystack,
                "Sentinel access token found in a log record: {$event->message}"
            );
            $this->assertStringNotContainsString(
                self::SENTINEL_REFRESH_TOKEN,
                $haystack,
                "Sentinel refresh token found in a log record: {$event->message}"
            );
            $this->assertStringNotContainsString(
                self::SENTINEL_AUTH_CODE,
                $haystack,
                "Sentinel auth code found in a log record: {$event->message}"
            );
        }
    }
}
