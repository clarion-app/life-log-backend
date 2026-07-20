<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Google\Mapping\AggregateSource;

/**
 * AggregateSource controls list vs rollUp endpoint selection.
 * Both branches reach the wire.
 */
class AggregateSourceTest extends TestCase
{
    /** @test T060a — two cases exist: LocalRollup and ProviderRollUp */
    public function twoCasesExist(): void
    {
        $this->assertCount(2, AggregateSource::cases());
    }

    /** @test T060a — LocalRollup is the default */
    public function localRollupIsDefault(): void
    {
        $this->assertFalse(AggregateSource::LocalRollup->usesProviderAggregation());
    }

    /** @test T060a — ProviderRollUp uses provider aggregation */
    public function providerRollUpUsesProviderAggregation(): void
    {
        $this->assertTrue(AggregateSource::ProviderRollUp->usesProviderAggregation());
    }

    /** @test T060a — fromConfig reads life-log.google.aggregate_source */
    public function fromConfigReadsConfigValue(): void
    {
        // Default should be LocalRollup
        $this->app['config']->set('life-log.google.aggregate_source', 'local_rollup');
        $result = AggregateSource::fromConfig();
        $this->assertSame(AggregateSource::LocalRollup, $result);

        $this->app['config']->set('life-log.google.aggregate_source', 'provider_rollup');
        $result = AggregateSource::fromConfig();
        $this->assertSame(AggregateSource::ProviderRollUp, $result);
    }

    /** @test T060a — fromConfig defaults to LocalRollup if config missing */
    public function fromConfigDefaultsToLocalRollup(): void
    {
        $this->app['config']->set('life-log.google.aggregate_source', null);
        $result = AggregateSource::fromConfig();
        $this->assertSame(AggregateSource::LocalRollup, $result);
    }

    /** @test T060a — fromConfig handles invalid config gracefully */
    public function fromConfigHandlesInvalidConfig(): void
    {
        $this->app['config']->set('life-log.google.aggregate_source', 'invalid_value');
        $result = AggregateSource::fromConfig();
        $this->assertSame(AggregateSource::LocalRollup, $result);
    }
}
