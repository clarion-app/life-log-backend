<?php

namespace ClarionApp\LifeLogBackend\Services;

use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class HourlyMeasurementRollup
{
    /**
     * Run the rollup: drain queue, aggregate raw readings, write bridged entries.
     *
     * @return array {written: int, deferred: int}
     */
    public function run(): array
    {
        $batchSize = (int) config('life-log.rollup_batch_size', 500);
        $totalWritten = 0;
        $totalDeferred = 0;

        do {
            $result = $this->drainAndProcess($batchSize);
            $totalWritten += $result['written'];
            $totalDeferred += $result['deferred'];
        } while ($result['written'] > 0 || $result['deferred'] > 0);

        return ['written' => $totalWritten, 'deferred' => $totalDeferred];
    }

    /**
     * Drain a batch of queue rows and process them.
     *
     * @return array {written: int, deferred: int}
     */
    protected function drainAndProcess(int $batchSize): array
    {
        return DB::transaction(function () use ($batchSize) {
            // Lock queue rows for update to prevent concurrent runners from claiming the same rows
            $queueRows = MeasurementRollupQueue::whereNull('deferred_reason')
                ->orderBy('bucket_hour')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            if ($queueRows->isEmpty()) {
                return ['written' => 0, 'deferred' => 0];
            }

            // Collect distinct (user_id, external_service, type, unit, bucket_hour) tuples
            $bucketKeys = $queueRows->mapWithKeys(function ($row) {
                $key = $row->user_id . '|' . $row->external_service . '|' . $row->type . '|' . $row->unit . '|' . $row->bucket_hour;
                return [$key => $row];
            });

            // Build aggregate query - one grouped query for all buckets
            $aggregateResults = $this->fetchAggregates($bucketKeys);

            // Process batch
            return $this->processBatch($queueRows, $aggregateResults);
        });
    }

    /**
     * Fetch aggregated values for all bucket keys in a single grouped query.
     *
     * Returns a collection keyed by "user_id|service|type|unit|bucket_hour"
     * with ['sum' => string, 'count' => int].
     */
    protected function fetchAggregates(Collection $bucketKeys): Collection
    {
        $results = collect();

        // Extract distinct (user_id, external_service, type, unit, bucket_hour) values
        $conditions = $bucketKeys->map->only(['user_id', 'external_service', 'type', 'unit', 'bucket_hour'])->values();

        if ($conditions->isEmpty()) {
            return $results;
        }

        // Build OR groups for each bucket - rows matching ANY bucket condition
        $query = RawMeasurement::query();

        $query->where(function ($q) use ($conditions) {
            foreach ($conditions as $condition) {
                $q->orWhere(function ($qq) use ($condition) {
                    $qq->where('user_id', $condition['user_id'])
                        ->where('external_service', $condition['external_service'])
                        ->where('type', $condition['type'])
                        ->where('unit', $condition['unit'])
                        ->where('bucket_hour', $condition['bucket_hour']);
                });
            }
        });

        // Grouped aggregate - SUM(value) and COUNT(*) grouped by bucket identity
        $aggregates = $query->selectRaw(
            'user_id, external_service, type, unit, bucket_hour, SUM(value) as sum_value, COUNT(*) as cnt'
        )
            ->groupBy('user_id', 'external_service', 'type', 'unit', 'bucket_hour')
            ->get();

        foreach ($aggregates as $agg) {
            $key = $agg->user_id . '|' . $agg->external_service . '|' . $agg->type . '|' . $agg->unit . '|' . $agg->bucket_hour;
            $results->put($key, [
                'sum' => (string) $agg->sum_value,
                'count' => (int) $agg->cnt,
            ]);
        }

        return $results;
    }

    /**
     * Process a batch of queue rows with their aggregate results.
     *
     * This method is public to allow subclassing for testing interrupted runs.
     *
     * @param Collection $queueRows The queue rows to process
     * @param Collection $aggregateResults The aggregated values keyed by bucket key
     * @return array {written: int, deferred: int}
     */
    public function processBatch(Collection $queueRows, Collection $aggregateResults): array
    {
        $written = 0;
        $deferred = 0;
        $valueMax = config('life-log.value_max', '999999999999.9999');

        foreach ($queueRows as $queueRow) {
            $key = $queueRow->user_id . '|' . $queueRow->external_service . '|' . $queueRow->type . '|' . $queueRow->unit . '|' . $queueRow->bucket_hour;

            // Check classification
            $classification = MeasurementTypeClassification::where('type', $queueRow->type)->first();

            if (!$classification) {
                // Unclassified type - defer
                $queueRow->update(['deferred_reason' => 'unclassified_type']);
                Log::info('Measurement type unclassified, deferring rollup', [
                    'type' => $queueRow->type,
                    'user_id' => $queueRow->user_id,
                    'bucket_hour' => $queueRow->bucket_hour,
                ]);
                $deferred++;
                continue;
            }

            // Get aggregate data
            $agg = $aggregateResults->get($key);

            if (!$agg || $agg['count'] === 0) {
                // No raw data found - drain queue row but write no entry
                $queueRow->delete();
                continue;
            }

            // Compute value based on aggregation type
            $sum = bcadd($agg['sum'], '0', 4);
            $count = $agg['count'];

            if ($classification->aggregation === MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME) {
                // SUM / COUNT divided in PHP, rounded half-up to 4dp
                // Use round() for rounding since bcdiv doesn't support rounding mode in PHP 8.4
                $value = sprintf('%.4F', round((float) bcdiv($sum, (string) $count, 10), 4));
            } else {
                // Cumulative: use SUM directly
                $value = $sum;
            }

            // Overflow guard
            if (bccomp($value, $valueMax, 4) > 0) {
                $queueRow->update(['deferred_reason' => 'overflow']);
                Log::warning('Measurement aggregate overflow, deferring rollup', [
                    'type' => $queueRow->type,
                    'user_id' => $queueRow->user_id,
                    'bucket_hour' => $queueRow->bucket_hour,
                    'value' => $value,
                    'max' => $valueMax,
                ]);
                $deferred++;
                continue;
            }

            // Write bridged entry using firstOrNew + save() (no upsert - events needed for replication)
            $metric = HealthMetric::firstOrNew([
                'user_id' => $queueRow->user_id,
                'external_service' => $queueRow->external_service,
                'type' => $queueRow->type,
                'unit' => $queueRow->unit,
                'bucket_hour' => $queueRow->bucket_hour,
            ]);

            // Only assign if changed to avoid dirty model / unnecessary publish
            // (T060: idempotency - re-saving unchanged data should not trigger events)
            if ((string) $metric->value !== $value) {
                $metric->value = $value;
            }

            // Set source only if not already set (new entries or entries missing source)
            if (empty($metric->source)) {
                $metric->source = $queueRow->external_service;
            }

            // Set recorded_at only if not already set
            if (!$metric->recorded_at) {
                $metric->recorded_at = $queueRow->bucket_hour;
            }

            $metric->save();
            $written++;

            // Delete queue row only after successful save
            $queueRow->delete();
        }

        return ['written' => $written, 'deferred' => $deferred];
    }
}
