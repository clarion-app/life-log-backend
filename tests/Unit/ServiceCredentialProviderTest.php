<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Exceptions\ServiceNotConfiguredException;

class ServiceCredentialProviderTest extends TestCase
{
    private function createCredential(string $service = 'test-service'): ServiceCredential
    {
        return ServiceCredential::create([
            'external_service' => $service,
            'client_id' => 'app-123',
            'client_secret' => 'secret-value',
            'redirect_uri' => 'https://example.com/callback',
        ]);
    }

    /** @test T011 — require() returns the credential when it exists */
    public function requireReturnsCredentialWhenExists(): void
    {
        $credential = $this->createCredential();
        $provider = new ServiceCredentialProvider();

        $result = $provider->require('test-service');
        $this->assertInstanceOf(ServiceCredential::class, $result);
        $this->assertSame($credential->id, $result->id);
    }

    /** @test T011 — require() throws ServiceNotConfiguredException when absent */
    public function requireThrowsWhenAbsent(): void
    {
        $provider = new ServiceCredentialProvider();

        $this->expectException(ServiceNotConfiguredException::class);
        $provider->require('nonexistent-service');
    }

    /** @test T011 — find() returns the credential when it exists */
    public function findReturnsCredentialWhenExists(): void
    {
        $credential = $this->createCredential();
        $provider = new ServiceCredentialProvider();

        $result = $provider->find('test-service');
        $this->assertInstanceOf(ServiceCredential::class, $result);
        $this->assertSame($credential->id, $result->id);
    }

    /** @test T011 — find() returns null when absent */
    public function findReturnsNullWhenAbsent(): void
    {
        $provider = new ServiceCredentialProvider();

        $result = $provider->find('nonexistent-service');
        $this->assertNull($result);
    }

    /** @test T011 — isConfigured() reflects presence */
    public function isConfiguredReflectsPresence(): void
    {
        $this->createCredential();
        $provider = new ServiceCredentialProvider();

        $this->assertTrue($provider->isConfigured('test-service'));
        $this->assertFalse($provider->isConfigured('nonexistent-service'));
    }

    /** @test T011 — soft-deleted credential counts as absent */
    public function softDeletedCredentialCountsAsAbsent(): void
    {
        $credential = $this->createCredential();
        $credential->delete();

        $provider = new ServiceCredentialProvider();

        $this->assertFalse($provider->isConfigured('test-service'));
        $this->assertNull($provider->find('test-service'));

        $this->expectException(ServiceNotConfiguredException::class);
        $provider->require('test-service');
    }

    /** @test T011 — memoisation is per-instance, not shared across instances */
    public function memoisationIsPerInstanceNotShared(): void
    {
        $credential = $this->createCredential();
        $provider1 = new ServiceCredentialProvider();
        $provider2 = new ServiceCredentialProvider();

        // Only provider1 caches the credential
        $result1 = $provider1->find('test-service');
        $this->assertSame($credential->id, $result1->id);

        // Delete the credential
        $credential->delete();

        // provider1 has it in cache (still returns it)
        $cached1 = $provider1->find('test-service');
        $this->assertNotNull($cached1);

        // provider2 does NOT have it cached, so it sees the deletion
        $cached2 = $provider2->find('test-service');
        $this->assertNull($cached2);
    }

    /** @test T011 — memoisation caches within a single instance */
    public function memoisationCachesWithinInstance(): void
    {
        $credential = $this->createCredential();
        $provider = new ServiceCredentialProvider();

        $result1 = $provider->find('test-service');
        $result2 = $provider->find('test-service');

        // Same instance returns cached result
        $this->assertSame($result1, $result2);

        // Delete the credential — cached result still returned
        $credential->delete();
        $cached = $provider->find('test-service');
        $this->assertNotNull($cached);
        $this->assertSame($credential->id, $cached->id);
    }
}
