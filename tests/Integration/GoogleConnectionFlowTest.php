<?php

namespace Tests\Integration;

use Tests\TestCase;
use Tests\Support\ScriptedGoogleTransport;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Google\Oauth\ScopeBundle;
use ClarionApp\LifeLogBackend\Google\Api\ApiVersion;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use GuzzleHttp\Client;

/**
 * US1 scenarios 1-5: authorize URL, approved return, partial grant,
 * denial/cancel, and re-completing an existing connection.
 */
class GoogleConnectionFlowTest extends TestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \ClarionApp\Backend\Models\User::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
        ]);

        $this->actingAs($this->user);

        // Store credential for google-health
        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => GoogleHealthService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
            'version' => 1,
        ]);
    }

    /** @test T037 — US1 scenario 1: authorize URL requests exactly three scopes */
    public function authorizeUrlRequestsExactlyThreeScopes(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $this->assertTrue($registry->has(GoogleHealthService::NAME));

        $service = $registry->resolve(GoogleHealthService::NAME);
        $result = $service->beginConnection($this->user->id);

        $this->assertSame(GoogleHealthService::NAME, $result->externalService);
        $this->assertNotEmpty($result->authorizationUrl);
        $this->assertNotEmpty($result->state);

        // Parse the authorization URL and check scopes
        $parsed = parse_url($result->authorizationUrl);
        parse_str($parsed['query'], $query);

        // Space-delimited per RFC 6749 §3.3. parse_str has already decoded the
        // wire encoding, so splitting on anything but a space would pass while
        // Google was being sent one malformed scope.
        $requestedScopes = explode(' ', $query['scope']);
        $expectedScopes = collect(ScopeBundle::all())->map(fn ($b) => $b->scopeString())->sort()->values()->all();
        sort($requestedScopes);

        $this->assertCount(3, $requestedScopes);
        $this->assertEquals($expectedScopes, $requestedScopes);

        // Verify offline access and consent prompt
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('code', $query['response_type']);
    }

    /** @test T037 — US1 scenario 2: approved return stores authorization and makes account sync-eligible */
    public function approvedReturnStoresAuthorization(): void
    {
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, [
                'access_token'  => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in'    => 3600,
                'scope'         => implode(' ', collect(ScopeBundle::all())->map(fn ($b) => $b->scopeString())->all()),
            ]);

        $transport->bind($this->app);

        // Create a connection attempt
        $state = bin2hex(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        // Complete the connection via callback
        $response = $this->postJson('/api/clarion-app/life-log/connected-accounts/callback', [
            'external_service' => GoogleHealthService::NAME,
            'state' => $state,
            'code' => 'authorization-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertFalse($data['reconnected']);
        $this->assertSame('healthy', $data['status']);

        // ConnectedAccount exists and is sync-eligible
        $account = ConnectedAccount::where('user_id', $this->user->id)
            ->where('external_service', GoogleHealthService::NAME)
            ->first();
        $this->assertNotNull($account);
        $this->assertSame('normal', $account->sync_state);

        // AccountAuthorization exists with scopes
        $auth = AccountAuthorization::where('connected_account_id', $account->id)->first();
        $this->assertNotNull($auth);
        $this->assertNotNull($auth->scopes);

        // Attempt was consumed
        $this->assertDatabaseMissing('life_log_connection_attempts', [
            'user_id' => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'consumed_at' => null,
        ]);
    }

    /** @test T037 — US1 scenario 3: partial grant records only granted bundles */
    public function partialGrantRecordsOnlyGrantedBundles(): void
    {
        // Simulate a partial grant — only ActivityAndFitness and Sleep
        $partialScopes = [
            ScopeBundle::ActivityAndFitness->scopeString(),
            ScopeBundle::Sleep->scopeString(),
        ];

        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, [
                'access_token'  => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in'    => 3600,
                'scope'         => implode(' ', $partialScopes),
            ]);

        $transport->bind($this->app);

        $state = bin2hex(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/clarion-app/life-log/connected-accounts/callback', [
            'external_service' => GoogleHealthService::NAME,
            'state' => $state,
            'code' => 'authorization-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        $accountId = $data['id'];

        $auth = AccountAuthorization::where('connected_account_id', $accountId)->first();
        $grantedBundles = explode(',', $auth->scopes);

        $this->assertContains('activity_and_fitness', $grantedBundles);
        $this->assertContains('sleep', $grantedBundles);
        $this->assertNotContains('health_metrics', $grantedBundles);
    }

    /** @test T037 — US1 scenario 4: denial/cancel is byte-identical to any other failure */
    public function denialCreatesNoConnection(): void
    {
        // Simulate Google rejecting the code (user denied/cancelled)
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(400, [
                'error' => 'invalid_grant',
                'error_description' => 'Bad Request',
            ]);

        $transport->bind($this->app);

        $state = bin2hex(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/clarion-app/life-log/connected-accounts/callback', [
            'external_service' => GoogleHealthService::NAME,
            'state' => $state,
            'code' => 'authorization-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        // Byte-identical to any other failure
        $response->assertStatus(422);
        $response->assertExactJson(['error' => 'connection_not_completed']);

        // No ConnectedAccount was created
        $this->assertDatabaseCount('life_log_connected_accounts', 0);

        // No AccountAuthorization was created
        $this->assertDatabaseCount('life_log_account_authorizations', 0);
    }

    /** @test T037 — US1 scenario 5: re-completing updates existing connection */
    public function reconnectUpdatesExistingConnection(): void
    {
        // First connection
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, [
                'access_token'  => 'first-access-token',
                'refresh_token' => 'first-refresh-token',
                'expires_in'    => 3600,
                'scope'         => implode(' ', collect(ScopeBundle::all())->map(fn ($b) => $b->scopeString())->all()),
            ]);

        $transport->bind($this->app);

        $state1 = bin2hex(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'state_hash' => hash('sha256', $state1),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $response1 = $this->postJson('/api/clarion-app/life-log/connected-accounts/callback', [
            'external_service' => GoogleHealthService::NAME,
            'state' => $state1,
            'code' => 'first-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response1->assertStatus(201);
        $firstData = $response1->json();
        $this->assertFalse($firstData['reconnected']);
        $accountId = $firstData['id'];

        // Second connection (reconnect). Queued on the same transport rather
        // than a second one: GoogleOauthFlow is a singleton and already holds
        // the client from the first bind(), so rebinding would install a
        // transport nothing asks for and leave this exchange with an empty
        // queue.
        $transport->respondJson(200, [
            'access_token'  => 'second-access-token',
            'refresh_token' => 'second-refresh-token',
            'expires_in'    => 3600,
            'scope'         => implode(' ', collect(ScopeBundle::all())->map(fn ($b) => $b->scopeString())->all()),
        ]);

        $state2 = bin2hex(random_bytes(32));
        ConnectionAttempt::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'state_hash' => hash('sha256', $state2),
            'redirect_uri' => 'https://example.com/callback',
            'expires_at' => now()->addHour(),
        ]);

        $response2 = $this->postJson('/api/clarion-app/life-log/connected-accounts/callback', [
            'external_service' => GoogleHealthService::NAME,
            'state' => $state2,
            'code' => 'second-code',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $response2->assertStatus(201);
        $secondData = $response2->json();
        $this->assertTrue($secondData['reconnected']);
        $this->assertSame($accountId, $secondData['id']);

        // Still only one ConnectedAccount — reconnecting must not create a
        // second. (assertDatabaseCount's third argument is the connection
        // name, not a failure message.)
        $this->assertDatabaseCount('life_log_connected_accounts', 1);

        // Authorization was replaced
        $auths = AccountAuthorization::where('connected_account_id', $accountId)->get();
        $this->assertCount(1, $auths);
    }
}
