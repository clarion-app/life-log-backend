<?php

namespace ClarionApp\LifeLogBackend\Services;

use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;
use Carbon\CarbonImmutable;

class RawMeasurementWriter
{
    /**
     * Write a batch of readings to the raw store.
     *
     * Uses upsert() keyed on (external_service, external_id) for dedup.
     * Sets recorded_at to UTC, bucket_hour via MeasurementBucket,
     * and defaults an absent unit to ''.
     *
     * @param array $readings Array of reading arrays
     * @return int Number of rows written/updated
     */
    public function write(array $readings): int
    {
        if (empty($readings)) {
            return 0;
        }

        $rows = [];
        $dirtyBuckets = [];

        foreach ($readings as $reading) {
            // Normalize recorded_at to UTC
            $recordedAt = MeasurementBucket::normalize($reading['recorded_at']);
            $bucketHour = MeasurementBucket::for($reading['recorded_at']);

            // Default unit to '' if absent (FR-002)
            $unit = $reading['unit'] ?? '';

            // Derive external_id if not supplied (FR-015)
            $externalId = $reading['external_id'] ?? $this->deriveExternalId(
                $reading['user_id'],
                $reading['external_service'],
                $reading['type'],
                $recordedAt
            );

            $rows[] = [
                'external_service' => $reading['external_service'],
                'external_id' => $externalId,
                'user_id' => $reading['user_id'],
                'type' => $reading['type'],
                'value' => $reading['value'],
                'unit' => $unit,
                'recorded_at' => $recordedAt,
                'bucket_hour' => $bucketHour,
                'metadata' => isset($reading['metadata']) ? json_encode($reading['metadata']) : null,
            ];

            // Track dirty bucket for queue marking
            $bucketKey = $reading['user_id'] . '|' . $reading['external_service'] . '|' . $reading['type'] . '|' . $unit . '|' . $bucketHour;
            $dirtyBuckets[$bucketKey] = [
                'user_id' => $reading['user_id'],
                'external_service' => $reading['external_service'],
                'type' => $reading['type'],
                'unit' => $unit,
                'bucket_hour' => $bucketHour,
            ];
        }

        // Bulk upsert — bypasses events, no bridge overhead (research.md §4)
        RawMeasurement::upsert($rows, ['external_service', 'external_id'], [
            'user_id', 'type', 'value', 'unit', 'recorded_at', 'bucket_hour', 'metadata', 'updated_at',
        ]);

        // Mark dirty hours — upsert one queue row per distinct bucket (plan D3)
        $queueRows = [];
        foreach ($dirtyBuckets as $bucket) {
            $queueRows[] = [
                'user_id' => $bucket['user_id'],
                'external_service' => $bucket['external_service'],
                'type' => $bucket['type'],
                'unit' => $bucket['unit'],
                'bucket_hour' => $bucket['bucket_hour'],
            ];
        }

        if (!empty($queueRows)) {
            MeasurementRollupQueue::upsert($queueRows, [
                'user_id', 'external_service', 'type', 'unit', 'bucket_hour',
            ], ['updated_at']);
        }

        return count($rows);
    }

    /**
     * Derive a deterministic external_id from reading attributes (FR-015).
     *
     * @return string
     */
    public function deriveExternalId(string $userId, string $service, string $type, $recordedAtUtc): string
    {
        $iso8601 = is_string($recordedAtUtc) ? $recordedAtUtc : $recordedAtUtc->toISOString();
        return 'derived:' . hash('sha256', implode('|', [$userId, $service, $type, $iso8601]));
    }
}
