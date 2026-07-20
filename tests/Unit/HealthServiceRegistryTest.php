<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\FakeSpanService;
use Tests\Support\FakeStepService;
use ClarionApp\LifeLogBackend\Exceptions\DuplicateServiceRegistrationException;
use ClarionApp\LifeLogBackend\Exceptions\UnknownServiceException;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use InvalidArgumentException;

/**
 * The single place services are named.
 *
 * The registry's one interesting decision is that a duplicate registration
 * throws instead of winning. Every other registry in this codebase is
 * last-writer-wins, which is harmless when the thing being replaced is a
 * strategy that produces the same answers. Here it is not: a silently replaced
 * health service reroutes a user's readings through a different mapping, and the
 * mis-mapped values land in permanent, replicated history where nothing
 * downstream can tell them apart from correct ones.
 */
class HealthServiceRegistryTest extends TestCase
{
    private function registry(): HealthServiceRegistry
    {
        return new HealthServiceRegistry();
    }

    /** @test */
    public function aRegisteredServiceResolvesByName(): void
    {
        $registry = $this->registry();
        $registry->register(FakeStepService::NAME, fn () => new FakeStepService());

        $service = $registry->resolve(FakeStepService::NAME);

        $this->assertInstanceOf(FakeStepService::class, $service);
        $this->assertSame(FakeStepService::NAME, $service->name());
    }

    /** @test  the factory is not called until something asks for the service */
    public function registrationIsLazy(): void
    {
        $built = 0;

        $registry = $this->registry();
        $registry->register(FakeStepService::NAME, function () use (&$built) {
            $built++;

            return new FakeStepService();
        });

        $this->assertSame(0, $built, 'Registering must not construct the service.');

        $registry->resolve(FakeStepService::NAME);

        $this->assertSame(1, $built);
    }

    /**
     * @test  resolve() returns the same instance every time
     *
     * A service carries in-flight state — the generation a cursor was issued
     * under, an access token refreshed mid-backfill. Handing out a fresh
     * instance per call would invalidate a cursor the caller had just been
     * given, and the backfill would fail on the resume path only.
     */
    public function resolvingTwiceReturnsTheSameInstance(): void
    {
        $registry = $this->registry();
        $registry->register(FakeStepService::NAME, fn () => new FakeStepService());

        $this->assertSame(
            $registry->resolve(FakeStepService::NAME),
            $registry->resolve(FakeStepService::NAME),
        );
    }

    /** @test */
    public function namesListsEveryRegisteredServiceInRegistrationOrder(): void
    {
        $registry = $this->registry();

        $this->assertSame([], $registry->names());

        $registry->register(FakeStepService::NAME, fn () => new FakeStepService());
        $registry->register(FakeSpanService::NAME, fn () => new FakeSpanService());

        $this->assertSame([FakeStepService::NAME, FakeSpanService::NAME], $registry->names());
    }

    /** @test  FR-029, D3 — a duplicate name throws and names the conflict */
    public function registeringTheSameNameTwiceThrows(): void
    {
        $registry = $this->registry();
        $registry->register(FakeStepService::NAME, fn () => new FakeStepService());

        try {
            $registry->register(FakeStepService::NAME, fn () => new FakeSpanService());
            $this->fail('A duplicate registration must not be accepted.');
        } catch (DuplicateServiceRegistrationException $e) {
            $this->assertSame(FakeStepService::NAME, $e->name);
            $this->assertStringContainsString(FakeStepService::NAME, $e->getMessage());
        }
    }

    /** @test  the first registration survives the rejected second one */
    public function aRejectedDuplicateLeavesTheOriginalRegistrationIntact(): void
    {
        $registry = $this->registry();
        $registry->register(FakeStepService::NAME, fn () => new FakeStepService());

        try {
            $registry->register(FakeStepService::NAME, fn () => new FakeSpanService());
        } catch (DuplicateServiceRegistrationException) {
            // expected
        }

        $this->assertInstanceOf(FakeStepService::class, $registry->resolve(FakeStepService::NAME));
        $this->assertSame([FakeStepService::NAME], $registry->names());
    }

    /** @test */
    public function resolvingAnUnknownNameThrows(): void
    {
        $registry = $this->registry();
        $registry->register(FakeStepService::NAME, fn () => new FakeStepService());

        try {
            $registry->resolve('never-registered');
            $this->fail('An unknown service name must not resolve.');
        } catch (UnknownServiceException $e) {
            $this->assertSame('never-registered', $e->name);
            // The message lists what *is* registered, because the usual cause is
            // a provider that never booted rather than a misspelling.
            $this->assertStringContainsString(FakeStepService::NAME, $e->getMessage());
        }
    }

    /** @test */
    public function hasReportsRegistrationWithoutConstructing(): void
    {
        $registry = $this->registry();
        $registry->register(FakeStepService::NAME, function () {
            $this->fail('has() must not construct the service.');
        });

        $this->assertTrue($registry->has(FakeStepService::NAME));
        $this->assertFalse($registry->has('never-registered'));
    }

    /** @test  an empty name is unresolvable by construction */
    public function anEmptyNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->register('', fn () => new FakeStepService());
    }

    /**
     * @test  a factory that returns something else fails at resolve, not later
     *
     * Without this the wrong object escapes the registry and fails at whatever
     * call site happens to touch it first, arbitrarily far from the bad
     * registration.
     */
    public function aFactoryThatDoesNotProduceAServiceThrows(): void
    {
        $registry = $this->registry();
        $registry->register('acme-band', fn () => new \stdClass());

        $this->expectException(InvalidArgumentException::class);

        $registry->resolve('acme-band');
    }

    /**
     * @test  the resolved service must agree with the name it was registered under
     *
     * The contract calls name() the stable registry name. If the two disagree,
     * every row this service writes carries an external_service value that
     * cannot be resolved back to a service, and dedup against those rows breaks.
     */
    public function aServiceRegisteredUnderTheWrongNameThrows(): void
    {
        $registry = $this->registry();
        $registry->register('not-fake-step', fn () => new FakeStepService());

        $this->expectException(InvalidArgumentException::class);

        $registry->resolve('not-fake-step');
    }

    /** @test  the container hands out one shared registry */
    public function theRegistryIsASingletonInTheContainer(): void
    {
        $this->assertSame(
            $this->app->make(HealthServiceRegistry::class),
            $this->app->make(HealthServiceRegistry::class),
        );
    }
}
