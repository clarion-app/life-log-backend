<?php

namespace Tests\Support;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for the service provider a brand-new wearable package would ship.
 *
 * It exists to prove one thing: a service joins the system entirely from
 * outside. This class is not referenced by any file in `src/`, is not listed in
 * any config, and registers itself with nothing but the container. If adding a
 * service ever required editing a shared file, this provider would be unable to
 * do its job without that edit — which is what makes it a test rather than a
 * fixture.
 */
class StubBandServiceProvider extends ServiceProvider
{
    public const NAME = 'stub-band';

    public function boot(): void
    {
        $this->app->make(HealthServiceRegistry::class)->register(
            self::NAME,
            fn () => new StubBandService(),
        );
    }
}
