<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

class RollupPerformanceTest extends TestCase
{
    protected function classify(string $type, string $aggregation): void
    {
        MeasurementTypeClassification::updateOrCreate(
            ['type' => $type],
            ['aggregation' => $aggregation],
        );
    }

    /* ------------------------------------------------------------------ */
    /* T045 - Performance test                                             */
    /* ------------------------------------------------------------------ */

    /** @test SC-009: wall-clock budget for one user-day rollup */
    public function rollupWallClockStaysUnderBudget(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Classify several types
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $this->classify('heart_rate', MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME);
        $this->classify('calories', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $this->classify('sleep_duration', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);

        // Seed one user-day: 24 buckets × 4 types × 2 services × ~5 readings = ~960 raw rows
        $types = ['steps', 'heart_rate', 'calories', 'sleep_duration'];
        $services = ['fitbit', 'garmin'];
        $readingsPerBucket = 5;
        $readings = [];

        foreach ($services as $service) {
            foreach ($types as $type) {
                for ($h = 0; $h < 24; $h++) {
                    for ($r = 0; $r < $readingsPerBucket; $r++) {
                        $readings[] = [
                            'user_id' => $userId,
                            'external_service' => $service,
                            'external_id' => sprintf('%s-%s-%02d-%02d', $service, $type, $h, $r),
                            'type' => $type,
                            'value' => 100.0 + rand(0, 500),
                            'unit' => $type === 'steps' ? 'steps' : ($type === 'heart_rate' ? 'bpm' : ($type === 'calories' ? 'kcal' : 'minutes')),
                            'recorded_at' => sprintf('2026-07-19 %02d:%02d:00 UTC', $h, rand(0, 59)),
                        ];
                    }
                }
            }
        }

        // Write raw readings
        foreach ($readings as $r) {
            $bucketHour = \ClarionApp\LifeLogBackend\Support\MeasurementBucket::for($r['recorded_at']);
            RawMeasurement::create([
                'user_id' => $r['user_id'],
                'external_service' => $r['external_service'],
                'external_id' => $r['external_id'],
                'type' => $r['type'],
                'value' => $r['value'],
                'unit' => $r['unit'],
                'recorded_at' => \ClarionApp\LifeLogBackend\Support\MeasurementBucket::normalize($r['recorded_at']),
                'bucket_hour' => $bucketHour,
            ]);

            MeasurementRollupQueue::updateOrCreate(
                [
                    'user_id' => $r['user_id'],
                    'external_service' => $r['external_service'],
                    'type' => $r['type'],
                    'unit' => $r['unit'],
                    'bucket_hour' => $bucketHour,
                ],
                [],
            );
        }

        $rawCount = RawMeasurement::count();
        $this->assertGreaterThan(500, $rawCount, 'Should have substantial raw data');

        // Run rollup and measure wall-clock
        $startMicrotime = microtime(true);
        $rollup = new HourlyMeasurementRollup();
        $result = $rollup->run();
        $elapsedSeconds = microtime(true) - $startMicrotime;

        // Budget: 30 seconds (generous margin against hourly interval)
        $this->assertLessThan(30.0, $elapsedSeconds, sprintf('Rollup should complete under 30s budget, took %.2fs', $elapsedSeconds));

        // Verify correctness
        // 24 hours × 4 types × 2 services = 192 entries (one per unique combination)
        // But each type has different units, so:
        // steps: 24 × 2 services = 48
        // heart_rate: 24 × 2 services = 48
        // calories: 24 × 2 services = 48
        // sleep_duration: 24 × 2 services = 48
        // Total: 192
        $expectedEntries = 24 * count($types) * count($services);
        $this->assertEquals($expectedEntries, $result['written']);
        $this->assertEquals($expectedEntries, HealthMetric::count());
    }

    /** @test FR-006: grouped read issues bounded query count */
    public function groupedReadIssuesBoundedQueryCount(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);

        // Seed many readings in a single bucket
        $numReadings = 100;
        for ($i = 0; $i < $numReadings; $i++) {
            RawMeasurement::create([
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => sprintf('perf-step-%04d', $i),
                'type' => 'steps',
                'value' => 10.0,
                'unit' => 'steps',
                'recorded_at' => \ClarionApp\LifeLogBackend\Support\MeasurementBucket::normalize('2026-07-19 09:' . sprintf('%02d', $i % 60) . ':00 UTC'),
                'bucket_hour' => CarbonImmutable::parse('2026-07-19 09:00:00 UTC'),
            ]);

            MeasurementRollupQueue::updateOrCreate(
                [
                    'user_id' => $userId,
                    'external_service' => 'fitbit',
                    'type' => 'steps',
                    'unit' => 'steps',
                    'bucket_hour' => CarbonImmutable::parse('2026-07-19 09:00:00 UTC'),
                ],
                [],
            );
        }

        $this->assertEquals($numReadings, RawMeasurement::count());

        // Enable query detection
        DB::enableQueryLog();

        $rollup = new HourlyMeasurementRollup();
        $rollup->run();

        $queries = DB::getQueryLog();
        $queryCount = count($queries);

        // Query count should NOT scale with raw row count
        // It should be bounded: a few queries for queue drain, aggregate read, write, delete
        // We allow generous margin (20 queries) but it should NOT be proportional to 100 readings
        $this->assertLessThan(20, $queryCount, sprintf('Query count (%d) should be bounded, not scale with %d raw rows', $queryCount, $numReadings));

        // Verify result
        $entry = HealthMetric::first();
        $this->assertNotNull($entry);
        $this->assertEquals('1000.0000', $entry->value); // 100 × 10.0
    }
}
