<?php

namespace ClarionApp\LifeLogBackend\Google\Probe;

use Carbon\CarbonImmutable;

/**
 * Per-hour comparison result from NotWornExclusionProbe.
 *
 * Compares the provider's rollUp value against the locally derived rollup
 * for a single hour. The `match` field indicates whether the values agree
 * (within 4 decimal places).
 */
final class NotWornExclusionResult
{
    /**
     * @param  string|null  $providerRollUpValue  Value from rollUp?windowSize=3600 (null if no data)
     * @param  string|null  $localRollUpValue  Value derived from intraday list (null if no data)
     * @param  bool  $match  True if both values are null or agree within 4 decimal places
     */
    public function __construct(
        public readonly CarbonImmutable $hour,
        public readonly ?string $providerRollUpValue,
        public readonly ?string $localRollUpValue,
        public readonly bool $match,
    ) {
    }
}
