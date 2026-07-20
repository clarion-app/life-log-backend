<?php

namespace ClarionApp\LifeLogBackend\External;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Exceptions\DuplicateServiceRegistrationException;
use ClarionApp\LifeLogBackend\Exceptions\UnknownServiceException;
use InvalidArgumentException;

/**
 * The one place external health services are named (FR-029).
 *
 * A service package registers itself from its own provider's boot() and touches
 * no file here:
 *
 *     $this->app->make(HealthServiceRegistry::class)->register(
 *         'acme-band',
 *         fn () => new AcmeBandService(...),
 *     );
 *
 * That indirection is the whole point of the class. Without it, adding the
 * fourth service means editing a shared list in this package, which turns an
 * additive change into a coordinated release and grows the cost of each new
 * service instead of holding it flat.
 *
 * Registration is a factory rather than an instance so that boot() stays cheap:
 * a provider registering a service must not construct HTTP clients or read
 * credentials for a service this request will never touch.
 *
 * Two decisions differ from `llm-client`'s ProviderRegistry, which this
 * otherwise follows:
 *
 *  - **A duplicate name throws** instead of last-writer-wins. See
 *    DuplicateServiceRegistrationException for why silence is dangerous here
 *    specifically.
 *  - **resolve() memoizes.** A service carries in-flight state — the generation
 *    a cursor was issued under, a token refreshed mid-backfill. Handing out a
 *    fresh instance per call would invalidate a cursor the caller was just
 *    given, and the failure would appear only on the resume path.
 */
class HealthServiceRegistry
{
    /** @var array<string, callable(): ExternalHealthService> */
    protected array $factories = [];

    /** @var array<string, ExternalHealthService> */
    protected array $resolved = [];

    /**
     * @param  callable(): ExternalHealthService  $factory  called at most once, on first resolve
     *
     * @throws DuplicateServiceRegistrationException if $name is already taken
     */
    public function register(string $name, callable $factory): void
    {
        if ($name === '') {
            throw new InvalidArgumentException(
                'A health service must be registered under a non-empty name — the name is written into '
                . 'every row the service produces and is how those rows are resolved back to it.'
            );
        }

        if (isset($this->factories[$name])) {
            throw new DuplicateServiceRegistrationException($name);
        }

        $this->factories[$name] = $factory;
    }

    /** Whether a service is registered, without constructing it. */
    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /**
     * @throws UnknownServiceException  nothing is registered under $name
     * @throws InvalidArgumentException the factory produced something that is not
     *                                  this service — a registration bug, caught
     *                                  here rather than at some later call site
     */
    public function resolve(string $name): ExternalHealthService
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (!isset($this->factories[$name])) {
            throw new UnknownServiceException($name, $this->names());
        }

        $service = ($this->factories[$name])();

        if (!$service instanceof ExternalHealthService) {
            throw new InvalidArgumentException(
                "The factory registered for '{$name}' produced a " . get_debug_type($service)
                . ', not an ExternalHealthService.'
            );
        }

        // The interface calls name() the stable registry name. If the two
        // disagree, the service writes rows under one name while being resolved
        // under another, and dedup on (external_service, external_id) stops
        // matching the rows it is meant to.
        if ($service->name() !== $name) {
            throw new InvalidArgumentException(
                "The service registered as '{$name}' reports its name as '{$service->name()}'. The two "
                . 'must agree: the registry name is what gets written into every row the service '
                . 'produces, and dedup resolves those rows back through it.'
            );
        }

        return $this->resolved[$name] = $service;
    }

    /**
     * Every registered name, in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->factories);
    }
}
