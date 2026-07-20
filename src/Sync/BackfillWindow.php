<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;

/**
 * The time window for a single backfill run.
 *
 * Unlike SyncWindow, this class does NOT clamp at connected_at.
 * Backfill walks backwards from the last known boundary until the
 * provider stops serving data. The walk stops when the provider
 * returns no more data, not at a date the system chose.
 *
 * Derivation rules:
 *   until := state.backfilled_to
 *   since := until - maxWindow(type)  (or very far back when maxWindow is null)
 *
 * Returns null when the type is already marked complete.
 */
final readonly class BackfillWindow
{
    public function __construct(
        public CarbonImmutable $since,
        public CarbonImmutable $until,
    ) {
    }

    /**
     * Derive the next backfill window for a type.
     *
     * Returns null if the type is already complete.
     */
    public static function next(
        AccountBackfillState $state,
        ExternalHealthService $service,
    ): ?self {
        // Skip if already complete
        if ($state->isComplete()) {
            return null;
        }

        // until := backfilled_to
        $until = $state->backfilled_to instanceof CarbonImmutable
            ? $state->backfilled_to
            : CarbonImmutable::createFromInterface($state->backfilled_to);

        // since := until - maxWindow(type)
        $type = self::resolveType($state->type);
        $maxWindow = $service->maxWindow($type);

        if ($maxWindow === null) {
            // No limit known — walk back very far (100 years is "the beginning"
            // for practical purposes; the provider will stop serving data long before)
            $since = $until->copy()->subYears(100);
        } else {
            $since = $until->copy()->sub($maxWindow);
        }

        // No floor — walk stops when provider stops serving data (FR-013)
        return new self($since, $until);
    }

    /**
     * Resolve a type string to a MeasurementType or SessionType enum.
     */
    private static function resolveType(string $typeValue): MeasurementType|SessionType
    {
        foreach (MeasurementType::cases() as $case) {
            if ($case->value === $typeValue) {
                return $case;
            }
        }
        foreach (SessionType::cases() as $case) {
            if ($case->value === $typeValue) {
                return $case;
            }
        }
        throw new \InvalidArgumentException("Unknown type value: {$typeValue}");
    }
}
