<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;

/**
 * Splits a time range into per-type segments, respecting each service's
 * maxWindow limit.
 *
 * A type with a null limit gets exactly one segment covering the full range.
 * A type whose limit is narrower than the range gets several segments,
 * ordered newest first so the backfill engine processes recent data first.
 *
 * This is the fix for the latent incremental bug: without per-type splitting,
 * a 20-day range against a 14-day heart-rate limit would silently fail or
 * return stale data. With splitting, the engine makes two requests:
 * [day 6..20] and [day 0..6], each within the limit.
 */
class WindowPlanner
{
    /**
     * Plan segments for a time range, per type.
     *
     * @param  list<MeasurementType|SessionType>  $types
     * @return list<TypeSegment>
     */
    public function plan(
        CarbonImmutable $since,
        CarbonImmutable $until,
        ExternalHealthService $service,
        array $types,
    ): array {
        // Empty range or empty type set → no segments
        if ($since->gte($until) || $types === []) {
            return [];
        }

        $segments = [];

        foreach ($types as $type) {
            $maxWindow = $service->maxWindow($type);

            if ($maxWindow === null) {
                // No limit — one segment covering the full range
                $segments[] = new TypeSegment($type, $since, $until);
            } else {
                // Split into segments that fit within maxWindow, newest first
                $splitSegments = $this->splitByLimit($since, $until, $maxWindow, $type);
                $segments = array_merge($segments, $splitSegments);
            }
        }

        return $segments;
    }

    /**
     * Split a range into segments that fit within the maxWindow limit.
     *
     * Segments are ordered newest first so the backfill engine processes
     * recent data before historical data.
     *
     * @return list<TypeSegment>
     */
    private function splitByLimit(
        CarbonImmutable $since,
        CarbonImmutable $until,
        \DateInterval $maxWindow,
        MeasurementType|SessionType $type,
    ): array {
        $segments = [];
        $currentUntil = $until;

        while ($currentUntil->gt($since)) {
            $segmentSince = $currentUntil->copy()->sub($maxWindow);

            if ($segmentSince->lt($since)) {
                // Last segment — clamp to the original since
                $segmentSince = $since;
            }

            $segments[] = new TypeSegment($type, $segmentSince, $currentUntil);
            $currentUntil = $segmentSince;

            if ($currentUntil->lte($since)) {
                break;
            }
        }

        return $segments;
    }
}
