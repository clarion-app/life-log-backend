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

class RollupAggregationTest extends TestCase
{
    protected HourlyMeasurementRollup $rollup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rollup = new HourlyMeasurementRollup();
    }

    /**
     * Helper: seed a classification row.
     */
    protected function classify(string $type, string $aggregation): void
    {
        MeasurementTypeClassification::updateOrCreate(
            ['type' => $type],
            ['aggregation' => $aggregation],
        );
    }

    /**
     * Helper: seed raw readings and queue rows.
     */
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
    /* T040 - Aggregation tests                                            */
    /* ------------------------------------------------------------------ */

    /** @test 3.1: cumulative SUM — steps 100+250+300 → 650.0000 */
    public function cumulativeTypeSumsValues(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 250.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:30:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 300.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:50:00 UTC',
            ],
        ]);

        $result = $this->rollup->run();

        $this->assertEquals(1, $result['written']);
        $entry = HealthMetric::first();
        $this->assertNotNull($entry);
        $this->assertEquals('650.0000', $entry->value);
        $this->assertEquals('fitbit', $entry->external_service);
        $this->assertEquals('steps', $entry->type);
        $this->assertEquals('steps', $entry->unit);
        $this->assertEquals('2026-07-19 09:00:00', $entry->bucket_hour->format('Y-m-d H:i:s'));
    }

    /** @test 3.2: point_in_time AVG — heart rates 60/70/80 → 70.0000 */
    public function pointInTimeTypeAveragesValues(): void
    {
        $this->classify('heart_rate', MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'heart_rate',
                'value' => 60.0,
                'unit' => 'bpm',
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'heart_rate',
                'value' => 70.0,
                'unit' => 'bpm',
                'recorded_at' => '2026-07-19 09:30:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'heart_rate',
                'value' => 80.0,
                'unit' => 'bpm',
                'recorded_at' => '2026-07-19 09:50:00 UTC',
            ],
        ]);

        $result = $this->rollup->run();

        $this->assertEquals(1, $result['written']);
        $entry = HealthMetric::first();
        $this->assertNotNull($entry);
        $this->assertEquals('70.0000', $entry->value);
    }

    /** @test 3.3: readings spanning two hours → two entries */
    public function readingsSpanningTwoHoursProduceTwoEntries(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:30:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 200.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 10:30:00 UTC',
            ],
        ]);

        $result = $this->rollup->run();

        $this->assertEquals(2, $result['written']);
        $entries = HealthMetric::orderBy('bucket_hour')->get();
        $this->assertEquals('100.0000', $entries[0]->value);
        $this->assertEquals('2026-07-19 09:00:00', $entries[0]->bucket_hour->format('Y-m-d H:i:s'));
        $this->assertEquals('200.0000', $entries[1]->value);
        $this->assertEquals('2026-07-19 10:00:00', $entries[1]->bucket_hour->format('Y-m-d H:i:s'));
    }

    /** @test 3.4: same type/hour from two services → two uncombined entries */
    public function sameTypeHourFromTwoServicesProducesTwoEntries(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'garmin',
                'type' => 'steps',
                'value' => 200.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:20:00 UTC',
            ],
        ]);

        $result = $this->rollup->run();

        $this->assertEquals(2, $result['written']);
        $entries = HealthMetric::orderBy('external_service')->get();
        $this->assertEquals('fitbit', $entries[0]->external_service);
        $this->assertEquals('100.0000', $entries[0]->value);
        $this->assertEquals('garmin', $entries[1]->external_service);
        $this->assertEquals('200.0000', $entries[1]->value);
    }

    /** @test 3.5: summary entry carries recorded_at, source, external_service, unit */
    public function summaryEntryCarriesProvenanceFields(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 500.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:30:00 UTC',
            ],
        ]);

        $this->rollup->run();

        $entry = HealthMetric::first();
        $this->assertNotNull($entry);
        $this->assertEquals('fitbit', $entry->source);
        $this->assertEquals('fitbit', $entry->external_service);
        $this->assertEquals('steps', $entry->unit);
        // recorded_at should be at the hour start
        $this->assertEquals('2026-07-19 09:00:00', $entry->recorded_at->format('Y-m-d H:i:s'));
    }

    /** @test 3.6: same type/hour in two units → one entry per unit */
    public function sameTypeHourInTwoUnitsProducesOneEntryPerUnit(): void
    {
        $this->classify('weight', MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'weight',
                'value' => 70.0,
                'unit' => 'kg',
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'weight',
                'value' => 154.0,
                'unit' => 'lbs',
                'recorded_at' => '2026-07-19 09:20:00 UTC',
            ],
        ]);

        $result = $this->rollup->run();

        $this->assertEquals(2, $result['written']);
        $entries = HealthMetric::orderBy('unit')->get();
        $this->assertEquals('70.0000', $entries[0]->value);
        $this->assertEquals('kg', $entries[0]->unit);
        $this->assertEquals('154.0000', $entries[1]->value);
        $this->assertEquals('lbs', $entries[1]->unit);
    }

    /** @test: queued bucket with no raw rows writes no entry */
    public function queuedBucketWithNoRawRowsWritesNoEntry(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed a queue row but no raw measurement
        MeasurementRollupQueue::create([
            'user_id' => $userId,
            'external_service' => 'fitbit',
            'type' => 'steps',
            'unit' => 'steps',
            'bucket_hour' => CarbonImmutable::parse('2026-07-19 09:00:00 UTC'),
        ]);

        $result = $this->rollup->run();

        $this->assertEquals(0, $result['written']);
        $this->assertEquals(0, HealthMetric::count());
        // Queue row should be drained (no raw data = no entry, but drained)
        $this->assertEquals(0, MeasurementRollupQueue::count());
    }

    /** @test: single reading yields its own value under both aggregations */
    public function singleReadingYieldsOwnValueUnderCumulative(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 42.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:30:00 UTC',
            ],
        ]);

        $this->rollup->run();

        $entry = HealthMetric::first();
        $this->assertNotNull($entry);
        $this->assertEquals('42.0000', $entry->value);
    }

    public function singleReadingYieldsOwnValueUnderPointInTime(): void
    {
        $this->classify('heart_rate', MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'heart_rate',
                'value' => 72.0,
                'unit' => 'bpm',
                'recorded_at' => '2026-07-19 09:30:00 UTC',
            ],
        ]);

        $this->rollup->run();

        $entry = HealthMetric::first();
        $this->assertNotNull($entry);
        $this->assertEquals('72.0000', $entry->value);
    }

    /* ------------------------------------------------------------------ */
    /* T043 - Interrupted run test                                         */
    /* ------------------------------------------------------------------ */

    /** @test: interrupted run leaves remaining queue entries intact */
    public function interruptedRunLeavesRemainingQueueEntriesIntact(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed 5 queue rows across 5 hours
        for ($h = 0; $h < 5; $h++) {
            $this->seedReadingsAndQueue([
                [
                    'user_id' => $userId,
                    'external_service' => 'fitbit',
                    'type' => 'steps',
                    'value' => 100.0 + $h * 10,
                    'unit' => 'steps',
                    'recorded_at' => sprintf('2026-07-19 %02d:30:00 UTC', $h),
                ],
            ]);
        }

        $this->assertEquals(5, MeasurementRollupQueue::count());

        // Run with a mock that throws after first bucket
        $failingRollup = new class extends HourlyMeasurementRollup {
            public int $bucketsProcessed = 0;

            public function processBatch(Collection $queueRows, Collection $aggregateResults): array
            {
                $this->bucketsProcessed++;
                if ($this->bucketsProcessed >= 2) {
                    throw new \RuntimeException('Simulated failure');
                }
                return parent::processBatch($queueRows, $aggregateResults);
            }
        };

        try {
            $failingRollup->run();
        } catch (\RuntimeException $e) {
            // expected
        }

        // Some entries should be written, some queue rows remain
        $written = HealthMetric::count();
        $remainingQueue = MeasurementRollupQueue::count();

        $this->assertGreaterThan(0, $written, 'Some entries should be written before failure');
        $this->assertGreaterThan(0, $remainingQueue, 'Some queue rows should remain after failure');

        // Next run completes remaining entries
        $result = $this->rollup->run();
        $finalEntries = HealthMetric::count();

        // Total should be 5 (all hours processed eventually)
        $this->assertEquals(5, $finalEntries);
        $this->assertEquals(0, MeasurementRollupQueue::count(), 'All queue rows should be drained');
    }

    /** @test: next run after interruption does not double-count */
    public function nextRunAfterInterruptionDoesNotDoubleCount(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

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

        // Failing rollup processes 1 then throws
        $failingRollup = new class extends HourlyMeasurementRollup {
            public int $bucketsProcessed = 0;

            public function processBatch(Collection $queueRows, Collection $aggregateResults): array
            {
                $this->bucketsProcessed++;
                if ($this->bucketsProcessed >= 1) {
                    throw new \RuntimeException('Simulated failure');
                }
                return parent::processBatch($queueRows, $aggregateResults);
            }
        };

        try {
            $failingRollup->run();
        } catch (\RuntimeException $e) {
            // expected
        }

        // Check that the first entry was written
        $firstEntries = HealthMetric::count();
        $this->assertGreaterThan(0, $firstEntries);

        // Complete run
        $this->rollup->run();

        // Each entry should have value 100.0000 (not doubled)
        $entries = HealthMetric::all();
        $this->assertEquals(3, $entries->count());
        foreach ($entries as $entry) {
            $this->assertEquals('100.0000', $entry->value, "Entry at {$entry->bucket_hour} should not be double-counted");
        }
    }
}
