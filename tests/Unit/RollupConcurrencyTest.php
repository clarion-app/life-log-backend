<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use Illuminate\Support\Collection;
use Carbon\CarbonImmutable;

class RollupConcurrencyTest extends TestCase
{
    protected function classify(string $type, string $aggregation): void
    {
        MeasurementTypeClassification::updateOrCreate(
            ['type' => $type],
            ['aggregation' => $aggregation],
        );
    }

    protected function seedReadingsAndQueue(array $readings): void
    {
        foreach ($readings as $r) {
            $bucketHour = \ClarionApp\LifeLogBackend\Support\MeasurementBucket::for($r['recorded_at']);
            $unit = $r['unit'] ?? '';

            RawMeasurement::create([
                'user_id' => $r['user_id'],
                'external_service' => $r['external_service'],
                'external_id' => $r['external_id'] ?? ('auto-' . uniqid()),
                'type' => $r['type'],
                'value' => $r['value'],
                'unit' => $unit,
                'recorded_at' => \ClarionApp\LifeLogBackend\Support\MeasurementBucket::normalize($r['recorded_at']),
                'bucket_hour' => $bucketHour,
            ]);

            MeasurementRollupQueue::updateOrCreate(
                [
                    'user_id' => $r['user_id'],
                    'external_service' => $r['external_service'],
                    'type' => $r['type'],
                    'unit' => $unit,
                    'bucket_hour' => $bucketHour,
                ],
                [],
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* T044 - Concurrency tests                                            */
    /* ------------------------------------------------------------------ */

    /** @test */
    public function test_twoRollupInstancesProduceOneEntryPerBucket(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed 3 hours of data
        for ($h = 0; $h < 3; $h++) {
            $this->seedReadingsAndQueue([
                [
                    'user_id' => $userId,
                    'external_service' => 'fitbit',
                    'type' => 'steps',
                    'value' => 100.0,
                    'unit' => 'steps',
                    'recorded_at' => sprintf('2026-07-19 %02d:30:00 UTC', $h),
                ],
            ]);
        }

        // First run processes all
        $rollup1 = new HourlyMeasurementRollup();
        $result1 = $rollup1->run();
        $this->assertEquals(3, $result1['written']);
        $this->assertEquals(3, HealthMetric::count());

        // Second run finds nothing to process
        $rollup2 = new HourlyMeasurementRollup();
        $result2 = $rollup2->run();
        $this->assertEquals(0, $result2['written']);

        // Still exactly 3 entries (no doubles)
        $this->assertEquals(3, HealthMetric::count());
        $entries = HealthMetric::all();
        foreach ($entries as $entry) {
            $this->assertEquals('100.0000', $entry->value);
        }
    }

    /** @test */
    public function test_secondRunnerMidDrainClaimsNoTakenRow(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed 5 hours
        for ($h = 0; $h < 5; $h++) {
            $this->seedReadingsAndQueue([
                [
                    'user_id' => $userId,
                    'external_service' => 'fitbit',
                    'type' => 'steps',
                    'value' => 100.0 + $h,
                    'unit' => 'steps',
                    'recorded_at' => sprintf('2026-07-19 %02d:30:00 UTC', $h),
                ],
            ]);
        }

        // Runner 1: processes first 2 buckets then stops (simulates mid-drain pause)
        $runner1 = new class extends HourlyMeasurementRollup {
            public int $bucketsProcessed = 0;
            public int $stopAfter = 2;

            public function processBatch(Collection $queueRows, Collection $aggregateResults): array
            {
                $this->bucketsProcessed++;
                if ($this->bucketsProcessed >= $this->stopAfter) {
                    throw new \RuntimeException('Simulated pause');
                }
                return parent::processBatch($queueRows, $aggregateResults);
            }
        };

        try {
            $runner1->run();
        } catch (\RuntimeException $e) {
            // expected - simulates mid-drain stop
        }

        // Runner 1 should have written some entries
        $entriesAfterRunner1 = HealthMetric::count();
        $this->assertGreaterThan(0, $entriesAfterRunner1);

        // Remaining queue rows should exist (transaction rolled back for those)
        // Note: In SQLite, lockForUpdate is a no-op, so the first runner's transaction
        // rollback means queue rows are still there. The key test is that runner 2
        // completes without double-writing.

        // Runner 2: completes remaining work
        $runner2 = new HourlyMeasurementRollup();
        $result2 = $runner2->run();

        // Total entries should be 5 (all hours)
        $this->assertEquals(5, HealthMetric::count());

        // Each entry should have the correct value (no double-counting)
        $entries = HealthMetric::orderBy('bucket_hour')->get();
        foreach ($entries as $i => $entry) {
            $expectedValue = (100.0 + $i);
            $this->assertEquals(
                sprintf('%.4F', $expectedValue),
                $entry->value,
                "Entry at hour {$i} should not be double-counted"
            );
        }
    }
}
