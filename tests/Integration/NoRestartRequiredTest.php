<?php

namespace Tests\Integration;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;

/**
 * SC-001: A credential stored via /service-credentials is picked up by a
 * connection begun in the same process, with no config file edit and no
 * application restart.
 */
class NoRestartRequiredTest extends TestCase
{
    /** @test T038a — credential stored is picked up by beginConnection in same process */
    public function credentialStoredIsPickedUpWithoutRestart(): void
    {
        // No credential exists yet
        $provider = app(ServiceCredentialProvider::class);
        $this->assertFalse($provider->isConfigured(GoogleHealthService::NAME));

        // Store a credential (simulating /service-credentials endpoint)
        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => GoogleHealthService::NAME,
            'client_id' => 'app-123',
            'client_secret' => 'secret-value',
            'redirect_uri' => 'https://example.com/callback',
            'version' => 1,
        ]);

        // The same ServiceCredentialProvider instance (scoped, memoised)
        // should now find the credential — no restart needed
        $this->assertTrue($provider->isConfigured(GoogleHealthService::NAME));

        $credential = $provider->require(GoogleHealthService::NAME);
        $this->assertSame('app-123', $credential->client_id);
        $this->assertSame('https://example.com/callback', $credential->redirect_uri);
    }

    /** @test T038a — ServiceCredentialProvider memoisation is per-instance */
    public function serviceCredentialProviderHasPerInstanceMemoisation(): void
    {
        // Create a credential
        $credential = ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => GoogleHealthService::NAME,
            'client_id' => 'app-123',
            'client_secret' => 'secret-value',
            'redirect_uri' => 'https://example.com/callback',
            'version' => 1,
        ]);

        $provider = new ServiceCredentialProvider();

        // First call caches the result
        $result1 = $provider->find(GoogleHealthService::NAME);
        $this->assertSame($credential->id, $result1->id);

        // Delete the credential
        $credential->delete();

        // Same instance still returns the cached (now stale) result
        $result2 = $provider->find(GoogleHealthService::NAME);
        $this->assertSame($credential->id, $result2->id,
            'Per-instance memoisation should return cached result even after deletion.');

        // A fresh instance sees the deletion
        $freshProvider = new ServiceCredentialProvider();
        $this->assertNull($freshProvider->find(GoogleHealthService::NAME));
    }

    /** @test T038a — beginConnection uses the credential from ServiceCredentialProvider */
    public function beginConnectionUsesCredentialFromProvider(): void
    {
        ServiceCredential::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => GoogleHealthService::NAME,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://myapp.example/oauth/callback',
            'version' => 1,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $result = $service->beginConnection('user-123');

        // The authorization URL should contain the redirect_uri from the credential
        $this->assertStringContainsString(
            urlencode('https://myapp.example/oauth/callback'),
            $result->authorizationUrl,
        );

        // The authorization URL should contain the client_id from the credential
        $this->assertStringContainsString(
            'test-client-id',
            $result->authorizationUrl,
        );
    }
}
