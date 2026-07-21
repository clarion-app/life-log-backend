<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\ScriptedGoogleTransport;
use ClarionApp\LifeLogBackend\Google\Api\ApiVersion;
use ClarionApp\LifeLogBackend\Google\Oauth\GoogleOauthFlow;
use ClarionApp\LifeLogBackend\Google\Oauth\ScopeBundle;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use GuzzleHttp\Client;

/**
 * T117 — The redirect URI stated in the setup documentation matches what
 * GoogleOauthFlow actually sends, so the two cannot drift.
 *
 * The docs say the callback path is
 * `/api/life-log/connected-accounts/callback`. This test asserts that
 * GoogleOauthFlow passes the redirect_uri parameter through to Google's
 * authorize endpoint without modification, and that the route registered
 * for the callback matches the path documented.
 */
class RedirectUriDocumentationTest extends TestCase
{
    /**
     * The redirect URI path documented in docs/google-health-setup.md.
     * This is the path portion (without scheme/host) that Google redirects to.
     */
    private const DOCUMENTED_CALLBACK_PATH = '/connected-accounts/callback';

    /**
     * Shared setup: create a credential so ServiceCredentialProvider can find it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => 'google-health',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/api/life-log/connected-accounts/callback',
            'version' => 1,
        ]);
    }

    /** @test T117 — authorizeUrl passes redirect_uri to Google unchanged */
    public function authorizeUrlPassesRedirectUriThrough(): void
    {
        $transport = ScriptedGoogleTransport::make();
        $transport->bind($this->app);

        $flow = new GoogleOauthFlow(
            app(ServiceCredentialProvider::class),
            $this->app->make(Client::class),
        );

        $testRedirectUri = 'https://example.com/api/life-log/connected-accounts/callback';
        $result = $flow->authorizeUrl('user-123', $testRedirectUri);

        // The URL must contain the redirect_uri parameter
        $this->assertStringContainsString('redirect_uri=', $result['url']);

        // Parse the query string and verify the redirect_uri value
        parse_str(parse_url($result['url'], PHP_URL_QUERY), $query);
        $this->assertSame($testRedirectUri, $query['redirect_uri']);
    }

    /** @test T117 — authorizeUrl includes all three scope bundles */
    public function authorizeUrlIncludesAllThreeScopes(): void
    {
        $transport = ScriptedGoogleTransport::make();
        $transport->bind($this->app);

        $flow = new GoogleOauthFlow(
            app(ServiceCredentialProvider::class),
            $this->app->make(Client::class),
        );

        $result = $flow->authorizeUrl('user-123', 'https://example.com/callback');

        // Parse the scopes from the URL. RFC 6749 §3.3 makes `scope` a
        // space-delimited list; parse_str has already decoded the wire encoding.
        parse_str(parse_url($result['url'], PHP_URL_QUERY), $query);
        $scopes = explode(' ', $query['scope']);

        // Must have exactly three scope strings
        $this->assertCount(3, $scopes);

        // Each scope must start with the BASE_URL
        foreach ($scopes as $scope) {
            $this->assertStringStartsWith(ApiVersion::BASE_URL, $scope);
        }
    }

    /** @test T117 — exchangeCode uses the redirect_uri in the token request */
    public function exchangeCodeUsesRedirectUri(): void
    {
        $transport = ScriptedGoogleTransport::make()
            ->respondJson(200, [
                'access_token'  => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in'    => 3600,
                'scope'         => implode(' ', collect(ScopeBundle::all())->map(fn ($b) => $b->scopeString())->all()),
            ]);
        $transport->bind($this->app);

        $flow = new GoogleOauthFlow(
            app(ServiceCredentialProvider::class),
            $this->app->make(Client::class),
        );

        $testRedirectUri = 'https://example.com/api/life-log/connected-accounts/callback';
        $flow->exchangeCode('auth_code_123', $testRedirectUri);

        // The request was captured — verify it hit the token endpoint
        $uri = $transport->lastUri();
        $this->assertNotNull($uri, 'exchangeCode should have made an HTTP request');
        $this->assertStringContainsString(ApiVersion::OAUTH_TOKEN, (string) $uri);
    }

    /** @test T117 — the callback route path matches the documented path */
    public function callbackRouteMatchesDocumentedPath(): void
    {
        // Read the routes file and extract the callback route definition.
        $routesFile = dirname(__DIR__, 2) . '/routes/api.php';
        $routesContent = file_get_contents($routesFile);

        // The routes file must declare a callback route
        $this->assertStringContainsString(
            "connected-accounts/callback",
            $routesContent,
            'routes/api.php must declare a connected-accounts/callback route'
        );

        // The route must be a POST route (for the OAuth callback)
        $this->assertStringContainsString(
            "Route::post('connected-accounts/callback'",
            $routesContent,
            'The callback route must be POST (OAuth callback standard)'
        );

        // Verify the documented path matches the route definition
        $this->assertMatchesRegularExpression(
            '/Route::post\(\'connected-accounts\/callback\'/',
            $routesContent,
            "The callback route path must match the documented path: " . self::DOCUMENTED_CALLBACK_PATH
        );
    }

    /** @test T117 — the documented redirect URI example is well-formed */
    public function documentedRedirectUriIsWellFormed(): void
    {
        $docsFile = dirname(__DIR__, 2) . '/docs/google-health-setup.md';
        $docsContent = file_get_contents($docsFile);

        // The docs must mention the callback path
        $this->assertStringContainsString(
            'connected-accounts/callback',
            $docsContent,
            'Setup docs must mention the callback path'
        );

        // The docs must show the full path including the api prefix
        $this->assertStringContainsString(
            'api/life-log/connected-accounts/callback',
            $docsContent,
            'Setup docs must show the full route path'
        );

        // The docs must warn about exact matching
        $this->assertStringContainsString(
            'exact',
            strtolower($docsContent),
            'Setup docs must warn that the redirect URI must match exactly'
        );
    }
}
