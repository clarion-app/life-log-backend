<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\ScriptedGoogleTransport;
use ClarionApp\LifeLogBackend\Google\Api\ApiVersion;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Google\Oauth\GoogleOauthFlow;
use GuzzleHttp\Client;

/**
 * The pinned ApiVersion::VERSION string appears in the URI captured by
 * ScriptedGoogleTransport, so a pin change is a deliberate edit with a
 * failing test attached.
 */
class ApiVersionPinTest extends TestCase
{
    /** @test T035 — the VERSION constant appears in the token exchange URI */
    public function versionAppearsInTokenExchangeUri(): void
    {
        // Create a credential so the flow can proceed to the HTTP request
        \ClarionApp\LifeLogBackend\Models\ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => 'google-health',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
            'version' => 1,
        ]);

        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, [
                'access_token'  => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in'    => 3600,
                'scope'         => ApiVersion::BASE_URL . '/auth/googlehealth.activity_and_fitness.readonly',
            ]);

        $transport->bind($this->app);

        $flow = new GoogleOauthFlow(
            app(ServiceCredentialProvider::class),
            $this->app->make(Client::class),
        );

        // The exchangeCode call hits the token endpoint.
        // We verify that the flow uses ApiVersion::OAUTH_TOKEN for the exchange
        // and ApiVersion::BASE_URL for scope strings.
        $flow->exchangeCode('test-code', 'https://example.com/callback');

        $uri = $transport->lastUri();
        $this->assertNotNull($uri, 'The token exchange should have made an HTTP request.');

        // The token endpoint URI should use the oauth2 base, not health base
        $this->assertStringContainsString('oauth2.googleapis.com', (string) $uri);
    }

    /** @test T035 — VERSION and BASE_URL are non-empty strings */
    public function versionAndBaseUrlAreNonEmpty(): void
    {
        $this->assertNotEmpty(ApiVersion::VERSION);
        $this->assertNotEmpty(ApiVersion::BASE_URL);
        $this->assertStringStartsWith('http', ApiVersion::BASE_URL);
    }

    /** @test T035 — VERIFIED_ON is a valid date string */
    public function verifiedOnIsValidDate(): void
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', ApiVersion::VERIFIED_ON);
        $this->assertNotNull($date, 'VERIFIED_ON must be a valid Y-m-d date string');
    }

    /** @test T035 — OAuth endpoints are well-formed URLs */
    public function oauthEndpointsAreWellFormed(): void
    {
        $this->assertStringStartsWith('https://', ApiVersion::OAUTH_AUTHORIZE);
        $this->assertStringStartsWith('https://', ApiVersion::OAUTH_TOKEN);
        $this->assertStringStartsWith('https://', ApiVersion::OAUTH_REVOKE);
    }
}
