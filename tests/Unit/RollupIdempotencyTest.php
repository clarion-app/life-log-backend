<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;

class RollupIdempotencyTest extends TestCase
{
    protected HourlyMeasurementRollup $rollup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rollup = new HourlyMeasurementRollup();
    }

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
            $bucketHour = MeasurementBucket::for($r['recorded_at']);
            $unit = $r['unit'] ?? '';

            RawMeasurement::create([
                'user_id' => $r['user_id'],
                'external_service' => $r['external_service'],
                'external_id' => $r['external_id'] ?? ('auto-' . uniqid()),
                'type' => $r['type'],
                'value' => $r['value'],
                'unit' => $unit,
                'recorded_at' => MeasurementBucket::normalize($r['recorded_at']),
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
    /* T054 - Idempotency tests                                            */
    /* ------------------------------------------------------------------ */

    /** @test 4.2: second run over unchanged data rewrites nothing */
    public function secondRunOverUnchangedDataRewritesNothing(): void
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
        ]);

        // First run
        $result1 = $this->rollup->run();
        $this->assertEquals(1, $result1['written']);

        $entry = HealthMetric::first();
        $firstValue = $entry->value;
        $firstUpdatedAt = $entry->updated_at;
        $entryCount = HealthMetric::count();

        // Re-seed the queue (simulating dirty-hour marking by a new raw write that didn't change data)
        MeasurementRollupQueue::updateOrCreate(
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'unit' => 'steps',
                'bucket_hour' => '2026-07-19 09:00:00',
            ],
            [],
        );

        // Second run — should rewrite the entry but value should be identical
        $result2 = $this->rollup->run();

        // Entry count unchanged
        $this->assertEquals($entryCount, HealthMetric::count());

        // Value unchanged
        $entry->refresh();
        $this->assertEquals($firstValue, $entry->value);
    }

    /** @test 4.4: repeated runs stay stable across three iterations */
    public function repeatedRunsStayStableAcrossThreeIterations(): void
    {
        $this->classify('heart_rate', MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'garmin',
                'type' => 'heart_rate',
                'value' => 60.0,
                'unit' => 'bpm',
                'recorded_at' => '2026-07-19 10:10:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'garmin',
                'type' => 'heart_rate',
                'value' => 80.0,
                'unit' => 'bpm',
                'recorded_at' => '2026-07-19 10:30:00 UTC',
            ],
        ]);

        $values = [];

        for ($i = 0; $i < 3; $i++) {
            // Re-seed queue for each iteration
            MeasurementRollupQueue::updateOrCreate(
                [
                    'user_id' => $userId,
                    'external_service' => 'garmin',
                    'type' => 'heart_rate',
                    'unit' => 'bpm',
                    'bucket_hour' => '2026-07-19 10:00:00',
                ],
                [],
            );

            $this->rollup->run();

            $entry = HealthMetric::first();
            $values[] = $entry->value;
        }

        // All three runs produce the same value
        $this->assertEquals($values[0], $values[1]);
        $this->assertEquals($values[1], $values[2]);
        $this->assertEquals('70.0000', $values[0]);
    }
}
