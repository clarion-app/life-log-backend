<?php

namespace ClarionApp\LifeLogBackend\Support;

use Carbon\CarbonImmutable;

class MeasurementBucket
{
    /**
     * Get the UTC hour bucket for a recorded-at timestamp.
     *
     * e.g. 09:59:59.999 → 09:00:00 UTC
     *      10:00:00.000 → 10:00:00 UTC
     */
    public static function for($recordedAt): CarbonImmutable
    {
        return CarbonImmutable::parse($recordedAt)->utc()->startOfHour();
    }

    /**
     * Normalize a recorded-at timestamp to UTC.
     */
    public static function normalize($recordedAt): CarbonImmutable
    {
        return CarbonImmutable::parse($recordedAt)->utc();
    }
}
