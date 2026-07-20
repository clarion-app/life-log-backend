<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;
use Tests\TestCase;

/**
 * SC-010/FR-026: Hourly rollup is idempotent under re-ingestion.
 *
 * An hour re-ingested in any order, with corrections and late arrivals mixed
 * in, yields one rollup per type per hour with values equal to a single clean
 * ingest. The Google external id is a pure function of (type, instant), so a
 * correction collides with the row it corrects via upsert.
 */
class HourlyRollupIdempotencyTest extends TestCase
{
    protected HourlyMeasurementRollup $rollup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rollup = new HourlyMeasurementRollup();
        $this->syncVocabulary();
    }

    /**
     * @test Re-ingesting the same hour in reverse order yields the same rollup value.
     *
     * The raw tier uses upsert on (external_service, external_id), so a
     * re-ingest replaces in place. The rollup then sees the same set.
     */
    public function testReIngestSameHourReverseOrderYieldsSameRollup(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // First ingest: 5 readings in ascending order
        $readingsA = [];
        for ($i = 0; $i < 5; $i++) {
            $readingsA[] = [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => "steps-{$i}",
                'type' => 'steps',
                'value' => 100.0 + $i * 10,
                'unit' => 'count',
                'recorded_at' => sprintf('2026-07-19 09:%02d:00 UTC', $i * 10),
            ];
        }
        $this->seedAndQueue($readingsA);

        $this->rollup->run();
        $metricA = HealthMetric::first();
        $this->assertNotNull($metricA);
        $valueA = (string) $metricA->value;

        // Second ingest: same 5 readings in descending order (simulating re-fetch)
        $readingsB = array_reverse($readingsA);
        $this->seedAndQueue($readingsB);

        // Re-run rollup
        $this->rollup->run();
        $metricB = HealthMetric::first();

        // Same value — order of ingest doesn't matter
        $this->assertEquals($valueA, (string) $metricB->value);
        // Still one rollup row
        $this->assertSame(1, HealthMetric::count());
    }

    /**
     * @test Late arrival after rollup: re-queue and re-rollup picks it up.
     *
     * A reading arrives late (after the hour was already rolled up). The queue
     * is re-marked, the rollup re-aggregates, and the value changes to include
     * the late reading.
     */
    public function testLateArrivalAfterRollupIsIncludedOnReRollup(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Initial 3 readings
        $initial = [];
        for ($i = 0; $i < 3; $i++) {
            $initial[] = [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => "steps-{$i}",
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'count',
                'recorded_at' => sprintf('2026-07-19 09:%02d:00 UTC', $i * 10),
            ];
        }
        $this->seedAndQueue($initial);

        $this->rollup->run();
        $metric1 = HealthMetric::first();
        $this->assertEquals('300.0000', (string) $metric1->value);

        // Late arrival — a new reading for the same hour
        $late = [
            'user_id' => $userId,
            'external_service' => 'fitbit',
            'external_id' => 'steps-late',
            'type' => 'steps',
            'value' => 50.0,
            'unit' => 'count',
            'recorded_at' => '2026-07-19 09:55:00 UTC',
        ];
        $this->seedAndQueue([$late]);

        $this->rollup->run();
        $metric2 = HealthMetric::first();
        // Now includes the late reading
        $this->assertEquals('350.0000', (string) $metric2->value);
        $this->assertSame(1, HealthMetric::count());
    }

    /**
     * @test Correction of a reading: upsert replaces in place, rollup reflects new value.
     *
     * The Google external id is deterministic, so a correction has the same
     * external_id and upserts over the old row.
     */
    public function testCorrectionReplacesInPlaceAndRollupReflectsChange(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Original reading
        $original = [
            'user_id' => $userId,
            'external_service' => 'fitbit',
            'external_id' => 'steps-0',
            'type' => 'steps',
            'value' => 100.0,
            'unit' => 'count',
            'recorded_at' => '2026-07-19 09:05:00 UTC',
        ];
        $this->seedAndQueue([$original]);

        $this->rollup->run();
        $metric1 = HealthMetric::first();
        $this->assertEquals('100.0000', (string) $metric1->value);

        // Correction: same external_id, different value
        $corrected = $original;
        $corrected['value'] = 250.0;
        $this->seedAndQueue([$corrected]);

        // Raw row count stays 1 (upsert)
        $this->assertSame(1, RawMeasurement::count());

        $this->rollup->run();
        $metric2 = HealthMetric::first();
        $this->assertEquals('250.0000', (string) $metric2->value);
        $this->assertSame(1, HealthMetric::count());
    }

    /**
     * @test Multiple types in the same hour each get one rollup row.
     */
    public function testMultipleTypesSameHourEachGetOneRollup(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [];
        foreach (['steps', 'calories_burned'] as $type) {
            for ($i = 0; $i < 3; $i++) {
                $readings[] = [
                    'user_id' => $userId,
                    'external_service' => 'fitbit',
                    'external_id' => "{$type}-{$i}",
                    'type' => $type,
                    'value' => 100.0 + $i * 10,
                    'unit' => '',
                    'recorded_at' => sprintf('2026-07-19 09:%02d:00 UTC', $i * 10),
                ];
            }
        }
        $this->seedAndQueue($readings);

        $this->rollup->run();

        $this->assertSame(2, HealthMetric::count());
        $steps = HealthMetric::where('type', 'steps')->first();
        $calories = HealthMetric::where('type', 'calories_burned')->first();
        $this->assertNotNull($steps);
        $this->assertNotNull($calories);
        $this->assertEquals('330.0000', (string) $steps->value);
        $this->assertEquals('330.0000', (string) $calories->value);
    }

    /* ------------------------------------------------------------------ */

    protected function seedAndQueue(array $readings): void
    {
        foreach ($readings as $r) {
            $bucketHour = MeasurementBucket::for($r['recorded_at']);
            $unit = $r['unit'] ?? '';

            RawMeasurement::updateOrCreate(
                [
                    'external_service' => $r['external_service'],
                    'external_id' => $r['external_id'],
                ],
                [
                    'user_id' => $r['user_id'],
                    'type' => $r['type'],
                    'value' => $r['value'],
                    'unit' => $unit,
                    'recorded_at' => MeasurementBucket::normalize($r['recorded_at']),
                    'bucket_hour' => $bucketHour,
                ],
            );

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
}
