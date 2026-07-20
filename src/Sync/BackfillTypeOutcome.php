<?php

namespace ClarionApp\LifeLogBackend\Sync;

/**
 * Per-type outcome within a backfill run.
 */
final readonly class BackfillTypeOutcome
{
    public function __construct(
        public string $type,
        public bool $skipped = false,
        public bool $completed = false,
        public int $pagesFetched = 0,
        public int $measurementsWritten = 0,
        public int $sessionsWritten = 0,
    ) {
    }
}
