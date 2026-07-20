<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use Carbon\CarbonImmutable;

/**
 * The outcome of a single sync run, consumed by SyncAttemptRecorder::record().
 *
 * Final readonly value object — the shape is fixed and nothing downstream
 * mutates it after construction.
 */
final readonly class SyncResult
{
    public function __construct(
        public SyncOutcome $outcome,
        public CarbonImmutable $since,
        public CarbonImmutable $until,
        public int $pagesFetched = 0,
        public int $measurementsWritten = 0,
        public int $sessionsWritten = 0,
        public ?FailureKind $failureKind = null,
        public ?string $errorMessage = null,
        public CarbonImmutable $startedAt = new CarbonImmutable(),
        public CarbonImmutable $finishedAt = new CarbonImmutable(),
    ) {
    }
}
