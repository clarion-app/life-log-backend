<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Google\Oauth\ScopeBundle;
use ClarionApp\LifeLogBackend\Google\Oauth\GoogleOauthFlow;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * FR-003a as a negative requirement: the connection payload exposes
 * granted bundles each naming its implied types, and NO per-type grant
 * map appears in any response.
 */
class ScopeGranularityGuardTest extends TestCase
{
    /** @test T038 — AuthorizationGrant.scopes contains bundle names, not per-type map */
    public function authorizationGrantScopesAreBundleNames(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token'  => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in'    => 3600,
                'scope'         => implode(' ', collect(ScopeBundle::all())->map(fn ($b) => $b->scopeString())->all()),
            ])),
        ]);

        $stack = HandlerStack::create($mock);
        $client = new Client(['handler' => $stack]);

        $flow = new GoogleOauthFlow(new ServiceCredentialProvider(), $client);
        $service = new GoogleHealthService($flow);

        // Create a credential so ServiceCredentialProvider can find it
        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => GoogleHealthService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/callback',
            'version' => 1,
        ]);

        $grant = $service->completeConnection('user-1', 'test-code', 'https://example.com/callback');

        // The scopes field should contain bundle names (comma-separated), not a per-type map
        $this->assertNotNull($grant->scopes);
        $this->assertNotJson($grant->scopes, 'Scopes should be a simple string, not JSON (which would imply per-type structure)');

        $bundleNames = explode(',', $grant->scopes);
        foreach ($bundleNames as $name) {
            $this->assertContains(trim($name), array_map(fn ($b) => $b->value, ScopeBundle::cases()));
        }
    }

    /** @test T038 — ScopeBundle.covers() returns types, not a grant map */
    public function scopeBundleCoversReturnsTypes(): void
    {
        foreach (ScopeBundle::cases() as $bundle) {
            $covers = $bundle->covers();
            foreach ($covers as $type) {
                $this->assertTrue(
                    is_object($type) && ($type instanceof \BackedEnum),
                    "covers() must return enum instances, not a grant map. Got: " . gettype($type),
                );
            }
        }
    }

    /** @test T038 — no per-type grant structure exists on ScopeBundle */
    public function noPerTypeGrantStructureExists(): void
    {
        // ScopeBundle should not have a method that returns a per-type boolean map
        $methods = get_class_methods(ScopeBundle::class);
        $this->assertNotContains('grantMap', $methods);
        $this->assertNotContains('typeGrants', $methods);
        $this->assertNotContains('perTypeGrants', $methods);
    }

    /**
     * Assert that a string is not valid JSON (to ensure no per-type map structure).
     */
    private function assertNotJson(string $value, string $message = ''): void
    {
        json_decode($value);
        $this->assertTrue(
            json_last_error() !== JSON_ERROR_NONE,
            $message ?: "Expected non-JSON string, but '{$value}' is valid JSON.",
        );
    }
}
