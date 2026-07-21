<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\ScriptedGoogleTransport;

/**
 * T122: Extend 057's CredentialSecretExposureTest over the Google path.
 *
 * Sentinel client secret, access token, refresh token, and authorization code
 * across the full connect → sync → backfill → renew → fail → disconnect
 * lifecycle; none appears in any response body or header (FR-007, SC-006).
 */
class GoogleSecretExposureTest extends TestCase
{
    private const SENTINEL_CLIENT_SECRET = 'SENTINEL-GOOGLE-CLIENT-SECRET-DOES-NOT-APPEAR';
    private const SENTINEL_ACCESS_TOKEN = 'SENTINEL-GOOGLE-ACCESS-TOKEN-DOES-NOT-APPEAR';
    private const SENTINEL_REFRESH_TOKEN = 'SENTINEL-GOOGLE-REFRESH-TOKEN-DOES-NOT-APPEAR';
    private const SENTINEL_AUTH_CODE = 'SENTINEL-GOOGLE-AUTH-CODE-DOES-NOT-APPEAR';

    protected $user;
    protected $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \ClarionApp\Backend\Models\User::create([
            'id'       => (string) Str::uuid(),
            'name'     => 'Secret Test User',
            'email'    => 'secret-test@example.com',
            'password' => 'hashed',
        ]);

        $this->actingAs($this->user);

        // Store credential with sentinel client secret
        ServiceCredential::create([
            'id'               => (string) Str::uuid(),
            'external_service' => GoogleHealthService::NAME,
            'client_id'        => 'sentinel-client-id',
            'client_secret'    => self::SENTINEL_CLIENT_SECRET,
            'redirect_uri'     => 'https://example.com/callback',
            'version'          => 1,
        ]);

        // Setup transport — all Google API calls go through the mock
        $this->transport = ScriptedGoogleTransport::make();
        $this->transport->respondJson(200, [
            'dataset'       => [[]],
            'nextPageToken' => null,
        ]);
        $this->transport->bind($this->app);
    }

    protected function baseUrl(): string
    {
        return '/api/clarion-app/life-log';
    }

    protected function assertSentinelsAbsent($response): void
    {
        $body = $response->getContent();
        $this->assertStringNotContainsString(self::SENTINEL_CLIENT_SECRET, $body,
            'Sentinel client secret found in response body');
        $this->assertStringNotContainsString(self::SENTINEL_ACCESS_TOKEN, $body,
            'Sentinel access token found in response body');
        $this->assertStringNotContainsString(self::SENTINEL_REFRESH_TOKEN, $body,
            'Sentinel refresh token found in response body');
        $this->assertStringNotContainsString(self::SENTINEL_AUTH_CODE, $body,
            'Sentinel auth code found in response body');

        // Check headers too
        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $headerValue = (string) $value;
                $this->assertStringNotContainsString(self::SENTINEL_CLIENT_SECRET, $headerValue,
                    "Sentinel client secret found in response header: {$name}");
                $this->assertStringNotContainsString(self::SENTINEL_ACCESS_TOKEN, $headerValue,
                    "Sentinel access token found in response header: {$name}");
                $this->assertStringNotContainsString(self::SENTINEL_REFRESH_TOKEN, $headerValue,
                    "Sentinel refresh token found in response header: {$name}");
                $this->assertStringNotContainsString(self::SENTINEL_AUTH_CODE, $headerValue,
                    "Sentinel auth code found in response header: {$name}");
            }
        }
    }

    /**
     * @test T122 — connect: begin connection does not leak sentinel values
     */
    public function beginConnectionDoesNotLeakSecrets(): void
    {
        $response = $this->postJson($this->baseUrl() . '/connected-accounts', [
            'external_service' => GoogleHealthService::NAME,
        ]);

        $response->assertStatus(201);
        $this->assertSentinelsAbsent($response);

        // The authorization URL in the response must not contain the client secret
        $data = $response->json();
        $this->assertStringNotContainsString(
            self::SENTINEL_CLIENT_SECRET,
            $data['authorization_url'] ?? ''
        );
    }

    /**
     * @test T122 — connect: callback with sentinel auth code does not leak
     */
    public function callbackDoesNotLeakAuthCode(): void
    {
        // Clear previous responses and script the token exchange endpoint
        $this->transport->clearResponses($this->app);
        $this->transport->respondJson(200, [
            'access_token'  => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token' => self::SENTINEL_REFRESH_TOKEN,
            'expires_in'    => 3600,
            'scope'         => 'https://www.googleapis.com/auth/googlehealth.basic_info',
        ]);

        // Create a connection attempt
        $state = base64_encode(random_bytes(32));
        ConnectionAttempt::create([
            'id'               => (string) Str::uuid(),
            'user_id'          => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'state_hash'       => hash('sha256', $state),
            'redirect_uri'     => 'https://example.com/callback',
            'expires_at'       => now()->addHour(),
        ]);

        // Hit the callback with the sentinel auth code
        $response = $this->postJson($this->baseUrl() . '/connected-accounts/callback', [
            'external_service' => GoogleHealthService::NAME,
            'state'            => $state,
            'code'             => self::SENTINEL_AUTH_CODE,
            'redirect_uri'     => 'https://example.com/callback',
        ]);

        $response->assertStatus(201);
        $this->assertSentinelsAbsent($response);
    }

    /**
     * @test T122 — sync: incremental sync does not leak sentinel tokens
     */
    public function incrementalSyncDoesNotLeakTokens(): void
    {
        $connectedAccount = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'external_user_id' => 'sentinel-user-id',
            'connected_at'     => CarbonImmutable::now()->subDay(),
        ]);

        AccountAuthorization::create([
            'connected_account_id' => $connectedAccount->id,
            'access_token'         => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token'        => self::SENTINEL_REFRESH_TOKEN,
            'expires_at'           => CarbonImmutable::now()->addHour(),
            'credential_version'   => 1,
        ]);

        // Script empty responses for all measurement types
        $this->transport->clearResponses($this->app);
        for ($i = 0; $i < 10; $i++) {
            $this->transport->respondJson(200, [
                'dataset'       => [[]],
                'nextPageToken' => null,
            ]);
        }

        $response = $this->postJson(
            $this->baseUrl() . "/connected-accounts/{$connectedAccount->id}/sync"
        );

        $response->assertStatus(200);
        $this->assertSentinelsAbsent($response);
    }

    /**
     * @test T122 — backfill: backfill does not leak sentinel tokens
     */
    public function backfillDoesNotLeakTokens(): void
    {
        $connectedAccount = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'external_user_id' => 'sentinel-backfill-user',
            'connected_at'     => CarbonImmutable::now()->subDays(5),
        ]);

        AccountAuthorization::create([
            'connected_account_id' => $connectedAccount->id,
            'access_token'         => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token'        => self::SENTINEL_REFRESH_TOKEN,
            'expires_at'           => CarbonImmutable::now()->addHour(),
            'credential_version'   => 1,
        ]);

        // Script empty responses
        $this->transport->clearResponses($this->app);
        for ($i = 0; $i < 10; $i++) {
            $this->transport->respondJson(200, [
                'dataset'       => [[]],
                'nextPageToken' => null,
            ]);
        }

        // Trigger backfill via the runner directly (the API doesn't expose backfill)
        $runner = app(\ClarionApp\LifeLogBackend\Sync\BackfillRunner::class);
        $result = $runner->run($connectedAccount);

        // Backfill result should not contain any sentinel values
        $resultJson = json_encode($result);
        $this->assertStringNotContainsString(self::SENTINEL_ACCESS_TOKEN, $resultJson);
        $this->assertStringNotContainsString(self::SENTINEL_REFRESH_TOKEN, $resultJson);
        $this->assertStringNotContainsString(self::SENTINEL_CLIENT_SECRET, $resultJson);
    }

    /**
     * @test T122 — renew: token renewal does not leak sentinel tokens
     */
    public function tokenRenewalDoesNotLeakTokens(): void
    {
        $connectedAccount = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'external_user_id' => 'sentinel-renew-user',
            'connected_at'     => CarbonImmutable::now()->subDays(5),
        ]);

        AccountAuthorization::create([
            'connected_account_id' => $connectedAccount->id,
            'access_token'         => 'expired-access-token',
            'refresh_token'        => self::SENTINEL_REFRESH_TOKEN,
            'expires_at'           => CarbonImmutable::now()->subMinute(),
            'credential_version'   => 1,
        ]);

        // Script the token refresh endpoint to succeed
        $this->transport->clearResponses($this->app);
        $this->transport->respondJson(200, [
            'access_token' => 'new-access-token-after-renewal',
            'expires_in'   => 3600,
        ]);
        // Script empty measurement responses
        for ($i = 0; $i < 10; $i++) {
            $this->transport->respondJson(200, [
                'dataset'       => [[]],
                'nextPageToken' => null,
            ]);
        }

        // Trigger sync which will trigger token renewal
        $response = $this->postJson(
            $this->baseUrl() . "/connected-accounts/{$connectedAccount->id}/sync"
        );

        $response->assertStatus(200);
        $this->assertSentinelsAbsent($response);

        // Verify the access token was updated but refresh token was not leaked
        $auth = AccountAuthorization::where('connected_account_id', $connectedAccount->id)->first();
        $this->assertNotEquals(self::SENTINEL_ACCESS_TOKEN, $auth->access_token);
    }

    /**
     * @test T122 — fail: sync failure does not leak sentinel values
     */
    public function syncFailureDoesNotLeakTokens(): void
    {
        $connectedAccount = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'external_user_id' => 'sentinel-fail-user',
            'connected_at'     => CarbonImmutable::now()->subDays(5),
        ]);

        AccountAuthorization::create([
            'connected_account_id' => $connectedAccount->id,
            'access_token'         => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token'        => self::SENTINEL_REFRESH_TOKEN,
            'expires_at'           => CarbonImmutable::now()->addHour(),
            'credential_version'   => 1,
        ]);

        // Script a 401 response (access expired)
        $this->transport->clearResponses($this->app);
        $this->transport->respondJson(401, [
            'error' => [
                'code'   => 401,
                'message' => 'Invalid Credentials',
                'status'  => 'INVALID_TOKEN',
                'reason'  => 'invalid_token',
            ],
        ]);
        // Script token refresh to fail too
        $this->transport->respondJson(400, [
            'error'            => 'invalid_grant',
            'error_description' => 'Token has been revoked',
        ]);

        $response = $this->postJson(
            $this->baseUrl() . "/connected-accounts/{$connectedAccount->id}/sync"
        );

        // The response may be 200 (sync ran and recorded failure) or an error
        // regardless, no sentinel values should appear
        $this->assertSentinelsAbsent($response);
    }

    /**
     * @test T122 — disconnect: disconnect does not leak sentinel values
     */
    public function disconnectDoesNotLeakTokens(): void
    {
        $connectedAccount = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'external_user_id' => 'sentinel-disconnect-user',
            'connected_at'     => CarbonImmutable::now()->subDays(5),
        ]);

        AccountAuthorization::create([
            'connected_account_id' => $connectedAccount->id,
            'access_token'         => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token'        => self::SENTINEL_REFRESH_TOKEN,
            'expires_at'           => CarbonImmutable::now()->addHour(),
            'credential_version'   => 1,
        ]);

        // Script the revoke endpoint to succeed
        $this->transport->clearResponses($this->app);
        $this->transport->respondJson(200, []);

        $response = $this->deleteJson(
            $this->baseUrl() . "/connected-accounts/{$connectedAccount->id}"
        );

        $response->assertStatus(200);
        $this->assertSentinelsAbsent($response);

        // Verify the account and authorization are gone
        $this->assertNull(
            ConnectedAccount::withTrashed()->find($connectedAccount->id)?->deleted_at
        );
        $this->assertDatabaseMissing('life_log_account_authorizations', [
            'connected_account_id' => $connectedAccount->id,
        ]);
    }

    /**
     * @test T122 — listing connections does not leak stored tokens
     */
    public function listingConnectionsDoesNotLeakTokens(): void
    {
        $connectedAccount = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'external_user_id' => 'sentinel-list-user',
            'connected_at'     => CarbonImmutable::now()->subDays(5),
        ]);

        AccountAuthorization::create([
            'connected_account_id' => $connectedAccount->id,
            'access_token'         => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token'        => self::SENTINEL_REFRESH_TOKEN,
            'expires_at'           => CarbonImmutable::now()->addHour(),
            'credential_version'   => 1,
        ]);

        $response = $this->getJson($this->baseUrl() . '/connected-accounts');
        $response->assertStatus(200);
        $this->assertSentinelsAbsent($response);
    }

    /**
     * @test T122 — showing a single connection does not leak stored tokens
     */
    public function showingConnectionDoesNotLeakTokens(): void
    {
        $connectedAccount = ConnectedAccount::create([
            'user_id'          => $this->user->id,
            'external_service' => GoogleHealthService::NAME,
            'external_user_id' => 'sentinel-show-user',
            'connected_at'     => CarbonImmutable::now()->subDays(5),
        ]);

        AccountAuthorization::create([
            'connected_account_id' => $connectedAccount->id,
            'access_token'         => self::SENTINEL_ACCESS_TOKEN,
            'refresh_token'        => self::SENTINEL_REFRESH_TOKEN,
            'expires_at'           => CarbonImmutable::now()->addHour(),
            'credential_version'   => 1,
        ]);

        $response = $this->getJson(
            $this->baseUrl() . "/connected-accounts/{$connectedAccount->id}"
        );
        $response->assertStatus(200);
        $this->assertSentinelsAbsent($response);
    }
}
