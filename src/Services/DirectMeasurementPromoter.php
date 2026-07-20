<?php

namespace ClarionApp\LifeLogBackend\Services;

use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\ReplicationMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Promotes Direct-mode raw measurements to the permanent, chain-replicated
 * HealthMetric tier — one permanent record per raw reading, forever.
 *
 * Mirrors SessionPromoter: scan promoted_at IS NULL, model save() (not
 * upsert()) so bridge events fire, idempotent on unchanged rows.
 *
 * Unlike the measurement rollup: there is no hour bucketing and no
 * aggregation. A Direct-mode measurement already is the unit of meaning
 * (weight, heart rate snapshot), so promotion is a one-for-one copy.
 */
class DirectMeasurementPromoter
{
    protected int $chunkSize;

    public function __construct(?int $chunkSize = null)
    {
        $this->chunkSize = $chunkSize ?? (int) config('life-log.rollup_batch_size', 500);
    }

    /**
     * Promote every Direct-mode raw measurement awaiting promotion.
     *
     * @return array{promoted: int, skipped: int}
     */
    public function run(): array
    {
        $promoted = 0;
        $skipped = 0;

        do {
            $batch = RawMeasurement::pendingPromotion()
                ->orderBy('id')
                ->limit($this->chunkSize)
                ->get();

            foreach ($batch as $raw) {
                $mode = $this->getReplicationMode($raw->type);

                if ($mode !== ReplicationMode::Direct) {
                    // Rollup-mode type — skip, it goes through the rollup pipeline.
                    // Mark as promoted so we don't revisit it.
                    $this->markPromoted($raw);
                    $skipped++;
                    continue;
                }

                if ($this->promote($raw)) {
                    $promoted++;
                } else {
                    $skipped++;
                }
            }
        } while ($batch->count() === $this->chunkSize && $batch->isNotEmpty());

        return ['promoted' => $promoted, 'skipped' => $skipped];
    }

    /**
     * Promote one raw measurement, returning whether it reached permanent history.
     */
    protected function promote(RawMeasurement $raw): bool
    {
        $metric = HealthMetric::firstOrNew([
            'user_id' => $raw->user_id,
            'external_service' => $raw->external_service,
            'type' => $raw->type,
            'unit' => $raw->unit,
            'recorded_at' => $raw->recorded_at,
        ]);

        // Only assign if changed to avoid dirty model / unnecessary publish
        if ((string) $metric->value !== (string) $raw->value) {
            $metric->value = $raw->value;
        }

        // Set source only if not already set
        if (empty($metric->source)) {
            $metric->source = $raw->external_service;
        }

        // An unchanged record is not dirty, so save() issues no UPDATE, so the
        // bridge publishes nothing and updated_at stays put.
        $metric->save();

        $this->markPromoted($raw);

        return true;
    }

    /**
     * Stamp promoted_at without disturbing updated_at — the raw row's content
     * did not change, only its promotion state.
     */
    protected function markPromoted(RawMeasurement $raw): void
    {
        RawMeasurement::withoutTimestamps(function () use ($raw) {
            $raw->promoted_at = CarbonImmutable::now();
            $raw->save();
        });
    }

    /**
     * Get the replication mode for a measurement type.
     *
     * @return ReplicationMode
     */
    protected function getReplicationMode(string $type): ReplicationMode
    {
        try {
            return MeasurementType::from($type)->replicationMode();
        } catch (\ValueError) {
            // Unknown type — treat as Rollup (deferred by rollup as unclassified).
            // Direct is opt-in via the vocabulary enum.
            return ReplicationMode::Rollup;
        }
    }
}
