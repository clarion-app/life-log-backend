<?php

namespace ClarionApp\LifeLogBackend\Google\Mapping;

/**
 * Which aggregation path the Google client uses for intraday data.
 *
 * Default is LocalRollup: fetch intraday list points and let the local
 * rollup pipeline aggregate them. ProviderRollUp uses Google's rollUp
 * endpoint with windowSize=3600 to get hourly aggregates from the provider.
 *
 * Research §2: whether hourly rollUp inherits the not-worn exclusion that
 * dailyRollUp documents is an open question. Flipping is a config change,
 * not rework — both branches exist behind this enum.
 */
enum AggregateSource: string
{
    case LocalRollup    = 'local_rollup';
    case ProviderRollUp = 'provider_rollup';

    /**
     * Resolve from config key. Defaults to LocalRollup.
     */
    public static function fromConfig(): self
    {
        $value = config('life-log.google.aggregate_source', 'local_rollup');

        try {
            return self::from($value);
        } catch (\ValueError) {
            return self::LocalRollup;
        }
    }

    /**
     * Whether this mode fetches raw intraday points (local rollup)
     * or provider-aggregated hourly values.
     */
    public function usesProviderAggregation(): bool
    {
        return $this === self::ProviderRollUp;
    }
}
