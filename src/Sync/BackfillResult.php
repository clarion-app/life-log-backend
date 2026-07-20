<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;

/**
 * The outcome of a single backfill run.
 *
 * Carries the per-type outcome set, which SyncResult has no shape for.
 * Backfill processes multiple types per run, each with its own window and
 * cursor state, so the result tracks which types were processed and their
 * individual outcomes.
 */
final readonly class BackfillResult
{
    /**
     * @param  array<string, BackfillTypeOutcome>  $typeOutcomes  Per-type outcomes keyed by type value
     */
    public function __construct(
        public SyncOutcome $outcome,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        public int $pagesFetched = 0,
        public int $requestsUsed = 0,
        public array $typeOutcomes = [],
    ) {
    }

    /**
     * Whether any type was marked complete during this run.
     */
    public function anyTypeCompleted(): bool
    {
        foreach ($this->typeOutcomes as $outcome) {
            if ($outcome->completed) {
                return true;
            }
        }
        return false;
    }

    /**
     * List of types that were skipped (already complete).
     *
     * @return list<string>
     */
    public function skippedTypes(): array
    {
        return array_keys(array_filter(
            $this->typeOutcomes,
            fn ($o) => $o->skipped,
        ));
    }
}
