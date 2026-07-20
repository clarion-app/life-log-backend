<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;

/**
 * One type-specific time segment from a window plan.
 *
 * The planner splits a wide (since, until) range into smaller segments
 * per type, respecting each service's maxWindow limit. A segment with a
 * null limit gets the full range; one with a narrow limit gets several.
 *
 * Segments are ordered newest first within each type, so the backfill
 * engine processes the most recent data first — the incremental sync
 * path gets its data without waiting for history to finish.
 */
final readonly class TypeSegment
{
    public function __construct(
        public MeasurementType|SessionType $type,
        public CarbonImmutable $since,
        public CarbonImmutable $until,
    ) {
    }
}
