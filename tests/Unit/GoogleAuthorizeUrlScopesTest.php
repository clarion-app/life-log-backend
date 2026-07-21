<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\ScriptedGoogleTransport;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Google\Oauth\GoogleOauthFlow;
use ClarionApp\LifeLogBackend\Google\Oauth\ScopeBundle;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

/**
 * The authorize URL must ask Google for three separate scopes.
 *
 * OAuth 2.0 (RFC 6749 §3.3) defines `scope` as a space-delimited list. Google
 * splits on whitespace and matches each token against the scopes registered for
 * the client. A separator that survives into the decoded value as anything other
 * than a space produces ONE unrecognised scope string rather than three, so the
 * consent screen cannot offer the three bundles independently — and every
 * partial-grant outcome the feature specifies becomes unreachable.
 *
 * These tests assert the decoded value, not the encoded one, because either
 * `+` or `%20` is a correct wire encoding of a space and the code is free to
 * pick either. What it is not free to do is emit a separator that decodes to
 * something that is not a space.
 */
class GoogleAuthorizeUrlScopesTest extends TestCase
{
    private const REDIRECT_URI = 'https://node.example/clarion-app/life-log/connected-services/callback/google-health';

    protected function setUp(): void
    {
        parent::setUp();

        ServiceCredential::create([
            'id'               => (string) Str::uuid(),
            'external_service' => 'google-health',
            'client_id'        => 'test-client-id',
            'client_secret'    => 'test-secret',
            'redirect_uri'     => self::REDIRECT_URI,
            'version'          => 1,
        ]);
    }

    private function authorizeUrl(): string
    {
        ScriptedGoogleTransport::make()->bind($this->app);

        $flow = new GoogleOauthFlow(
            app(ServiceCredentialProvider::class),
            $this->app->make(Client::class),
        );

        return $flow->authorizeUrl('user-123', self::REDIRECT_URI)['url'];
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /** @test the decoded scope parameter is a space-delimited list of three scopes */
    public function scopeParameterDecodesToThreeSpaceDelimitedScopes(): void
    {
        $query = $this->query($this->authorizeUrl());

        $requested = explode(' ', $query['scope']);
        $expected  = collect(ScopeBundle::all())->map(fn ($b) => $b->scopeString())->all();

        $this->assertSame(
            $expected,
            $requested,
            'Google must receive three space-delimited scopes, in bundle order.'
        );
    }

    /** @test no scope token carries a stray separator */
    public function noScopeTokenContainsASeparatorCharacter(): void
    {
        $query = $this->query($this->authorizeUrl());

        foreach (explode(' ', $query['scope']) as $scope) {
            $this->assertStringNotContainsString(
                '+',
                $scope,
                "The scope '{$scope}' contains a literal '+'. A '+' joined into the value "
                . "before url-encoding survives as '%2B', which decodes back to '+' and not "
                . 'to a space — so Google sees one malformed scope, not three.'
            );
        }
    }

    /** @test each requested scope is one Google recognises as a bundle */
    public function everyRequestedScopeResolvesToAKnownBundle(): void
    {
        $query = $this->query($this->authorizeUrl());

        foreach (explode(' ', $query['scope']) as $scope) {
            $this->assertNotNull(
                ScopeBundle::fromScopeString($scope),
                "Google would not recognise the requested scope '{$scope}'."
            );
        }
    }

    /** @test the encoded URL never percent-encodes a plus into the scope value */
    public function encodedUrlDoesNotContainAnEscapedPlusInTheScopeValue(): void
    {
        $url = $this->authorizeUrl();

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $rawScope = null;

        foreach (explode('&', parse_url($url, PHP_URL_QUERY)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key === 'scope') {
                $rawScope = $value;
            }
        }

        $this->assertNotNull($rawScope, 'the authorize URL must carry a scope parameter');
        $this->assertStringNotContainsString(
            '%2B',
            $rawScope,
            'The scope value contains %2B — a url-encoded literal plus, not a separator.'
        );
    }
}
