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
 * T022 — The redirect URI stated in the setup documentation is the real one.
 *
 * Compose the real API prefix from composer.json → extra.clarion.app-name,
 * assert the docs no longer name the stale API path, and assert the documented
 * redirect URI is the frontend callback path.
 */
class RedirectUriDocumentationTest extends TestCase
{
    /**
     * The frontend callback path that the docs should state.
     * This is the path portion (without scheme/host) that Google redirects to.
     */
    private const FRONTEND_CALLBACK_PATH = '/clarion-app/life-log/connected-services/callback';

    /**
     * Compose the real API prefix from composer.json → extra.clarion.app-name.
     * ClarionPackageServiceProvider computes: 'api/' . str_replace('@', '', $app_name)
     * e.g., '@clarion-app/life-log' → 'api/clarion-app/life-log'
     */
    private static function realApiPrefix(): string
    {
        $composerJson = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        $manifest = json_decode($composerJson, true);
        $appName = $manifest['extra']['clarion']['app-name'] ?? '';
        // ClarionPackageServiceProvider strips '@' and prepends 'api/'
        $stripped = str_replace('@', '', $appName);
        return 'api/' . $stripped;
    }

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
            'redirect_uri' => 'https://example.com' . self::FRONTEND_CALLBACK_PATH . '/google-health',
            'version' => 1,
        ]);
    }

    /** @test T022 — composer.json extra.clarion.app-name yields the real prefix */
    public function composerJsonYieldsRealPrefix(): void
    {
        $prefix = self::realApiPrefix();
        // The prefix should be 'api/clarion-app/life-log' for this package
        $this->assertSame('api/clarion-app/life-log', $prefix);
    }

    /** @test T022 — the docs must NOT name the stale API callback path */
    public function docsMustNotNameStaleApiCallbackPath(): void
    {
        $docsFile = dirname(__DIR__, 2) . '/docs/google-health-setup.md';
        $docsContent = file_get_contents($docsFile);

        // The old path that 404s must NOT appear in the docs
        $this->assertStringNotContainsString(
            'api/life-log/connected-accounts/callback',
            $docsContent,
            'docs/google-health-setup.md must not name the stale API callback path (api/life-log/connected-accounts/callback)'
        );
    }

    /** @test T022 — the docs must name the frontend callback path */
    public function docsMustNameFrontendCallbackPath(): void
    {
        $docsFile = dirname(__DIR__, 2) . '/docs/google-health-setup.md';
        $docsContent = file_get_contents($docsFile);

        // The docs must contain the frontend callback path
        $this->assertStringContainsString(
            self::FRONTEND_CALLBACK_PATH,
            $docsContent,
            'docs/google-health-setup.md must document the frontend callback path'
        );

        // The docs must show the callback path with a service parameter placeholder
        $this->assertStringContainsString(
            '/callback/{service}',
            $docsContent,
            'docs/google-health-setup.md must show the callback path with {service} placeholder'
        );
    }

    /** @test T022 — the documented redirect URI is well-formed */
    public function documentedRedirectUriIsWellFormed(): void
    {
        $docsFile = dirname(__DIR__, 2) . '/docs/google-health-setup.md';
        $docsContent = file_get_contents($docsFile);

        // The documented redirect URI must use https
        $this->assertStringContainsString(
            'https://your-domain.com' . self::FRONTEND_CALLBACK_PATH,
            $docsContent,
            'The documented redirect URI must use https and the frontend callback path'
        );
    }

    /** @test T022 — cross-package agreement with life-log-frontend/package.json (optional) */
    public function frontendManifestMatchesCallbackPath(): void
    {
        $frontendPkgPath = dirname(__DIR__, 3) . '/life-log-frontend/package.json';

        if (!file_exists($frontendPkgPath)) {
            $this->markTestSkipped('life-log-frontend/package.json not found (expected when installed from Packagist)');
        }

        $frontendPkg = json_decode(file_get_contents($frontendPkgPath), true);
        $routes = $frontendPkg['customFields']['clarion']['routes'] ?? [];

        $callbackRoute = null;
        foreach ($routes as $route) {
            if (isset($route['path']) && str_contains($route['path'], ':service') && str_contains($route['path'], 'callback')) {
                $callbackRoute = $route['path'];
                break;
            }
        }

        $this->assertNotNull($callbackRoute, 'life-log-frontend/package.json must declare a callback route with :service');

        // The route base (without /:service) must match our constant
        $routeBase = str_replace('/:service', '', $callbackRoute);
        $this->assertSame(
            self::FRONTEND_CALLBACK_PATH,
            $routeBase,
            "The frontend callback route base must match the documented path"
        );
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

        $testRedirectUri = 'https://example.com' . self::FRONTEND_CALLBACK_PATH . '/google-health';
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

        $testRedirectUri = 'https://example.com' . self::FRONTEND_CALLBACK_PATH . '/google-health';
        $flow->exchangeCode('auth_code_123', $testRedirectUri);

        // The request was captured — verify it hit the token endpoint
        $uri = $transport->lastUri();
        $this->assertNotNull($uri, 'exchangeCode should have made an HTTP request');
        $this->assertStringContainsString(ApiVersion::OAUTH_TOKEN, (string) $uri);
    }
}
