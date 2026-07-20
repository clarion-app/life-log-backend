<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\FakeSpanService;
use Tests\Support\FakeStepService;
use Tests\Support\StubBandService;
use Tests\Support\StubBandServiceProvider;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;

/**
 * SC-001 — a service joins the system without any file in this package changing.
 *
 * The claim is easy to state and easy to quietly break: someone adds a match on
 * service names, or a config array every service must be listed in, and adding
 * the fourth service silently becomes an edit to shared code plus a coordinated
 * release. This test fails the moment that happens, because StubBandService
 * arrives through nothing but its own provider and the container.
 */
class ExternalServiceRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // The stub provider loads exactly the way a third-party wearable package
        // would: appended after this package, referenced by nothing inside it.
        return [...parent::getPackageProviders($app), StubBandServiceProvider::class];
    }

    /** @test */
    public function aServiceRegisteredFromAnExternalProviderIsResolvable(): void
    {
        $registry = $this->app->make(HealthServiceRegistry::class);

        $this->assertContains(StubBandService::NAME, $registry->names());
        $this->assertInstanceOf(StubBandService::class, $registry->resolve(StubBandService::NAME));
    }

    /** @test  and it is usable through the interface alone, with no cast and no special case */
    public function theExternallyRegisteredServiceAnswersTheContract(): void
    {
        $service = $this->app->make(HealthServiceRegistry::class)->resolve(StubBandService::NAME);

        $this->assertInstanceOf(ExternalHealthService::class, $service);
        $this->assertSame([MeasurementType::HeartRate], $service->supportedTypes());

        $page = $service->fetch(
            'ffffffff-0000-4000-8000-00000000beef',
            CarbonImmutable::parse('2026-01-01T00:00:00Z'),
            CarbonImmutable::parse('2026-01-02T00:00:00Z'),
        );

        $this->assertNotEmpty($page->measurements());
        $this->assertSame(StubBandService::NAME, $page->measurements()[0]->externalService);
        $this->assertSame('bpm', $page->measurements()[0]->unit);
    }

    /**
     * @test  the new service does not disturb the ones already there
     *
     * The independent test for this story: after adding a service, the first
     * service's behavior is unchanged. Registration is additive, so a service
     * that registered earlier resolves to exactly what it always did.
     */
    public function addingAServiceLeavesTheExistingOnesAlone(): void
    {
        $registry = $this->app->make(HealthServiceRegistry::class);
        $registry->register(FakeStepService::NAME, fn () => new FakeStepService());

        $before = $registry->names();
        $earlier = $registry->resolve(FakeStepService::NAME);

        $registry->register(FakeSpanService::NAME, fn () => new FakeSpanService());

        $this->assertSame($before, array_slice($registry->names(), 0, count($before)));
        $this->assertSame(
            $earlier,
            $registry->resolve(FakeStepService::NAME),
            'A later registration must not disturb a service already resolved and in use.',
        );
    }
}
