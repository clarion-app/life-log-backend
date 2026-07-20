<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\StubBandService;
use Tests\Support\StubBandServiceProvider;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;

/**
 * FR-001 invariant: Google is resolved through HealthServiceRegistry, not
 * referenced directly by any engine class, and StubBandServiceProvider's
 * out-of-package registration still works alongside it.
 */
class RegistryRegistrationPathTest extends TestCase
{
    /** @test T038b — Google is resolved through HealthServiceRegistry */
    public function googleResolvedThroughRegistry(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $this->assertTrue($registry->has(GoogleHealthService::NAME));

        $service = $registry->resolve(GoogleHealthService::NAME);
        $this->assertInstanceOf(GoogleHealthService::class, $service);
        $this->assertSame(GoogleHealthService::NAME, $service->name());
    }

    /** @test T038b — StubBandServiceProvider out-of-package registration still works */
    public function stubBandRegistrationWorksAlongsideGoogle(): void
    {
        // Register StubBand manually (simulating StubBandServiceProvider boot)
        $registry = app(HealthServiceRegistry::class);
        $registry->register(
            StubBandServiceProvider::NAME,
            fn () => new StubBandService(),
        );

        // Both services are resolvable
        $this->assertTrue($registry->has(GoogleHealthService::NAME));
        $this->assertTrue($registry->has(StubBandServiceProvider::NAME));

        $google = $registry->resolve(GoogleHealthService::NAME);
        $band = $registry->resolve(StubBandServiceProvider::NAME);

        $this->assertInstanceOf(GoogleHealthService::class, $google);
        $this->assertInstanceOf(StubBandService::class, $band);

        // They are different instances
        $this->assertNotSame($google, $band);
    }

    /** @test T038b — Google service name matches registry name */
    public function googleServiceNameMatchesRegistryName(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $this->assertSame(GoogleHealthService::NAME, $service->name());
    }

    /** @test T038b — Google is registered under 'google-health' name */
    public function googleRegisteredUnderCorrectName(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $this->assertTrue($registry->has('google-health'));
    }
}
