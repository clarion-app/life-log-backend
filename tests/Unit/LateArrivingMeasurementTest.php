<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;

class LateArrivingMeasurementTest extends TestCase
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
    /* T057 - Late arrival tests                                           */
    /* ------------------------------------------------------------------ */

    /** @test 4.1: late reading adds to existing summary */
    public function lateReadingAddsToExistingSummary(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed initial readings for 09:00 hour
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

        // First run — should produce 650
        $result1 = $this->rollup->run();
        $this->assertEquals(1, $result1['written']);

        $entry = HealthMetric::first();
        $this->assertEquals('650.0000', $entry->value);

        // Now add a late reading for the same hour
        $bucketHour = MeasurementBucket::for('2026-07-19 09:40:00 UTC');
        RawMeasurement::create([
            'user_id' => $userId,
            'external_service' => 'fitbit',
            'external_id' => 'late-reading-001',
            'type' => 'steps',
            'value' => 50.0,
            'unit' => 'steps',
            'recorded_at' => MeasurementBucket::normalize('2026-07-19 09:40:00 UTC'),
            'bucket_hour' => $bucketHour,
        ]);

        // Re-mark the bucket as dirty
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

        // Second run — should update to 700
        $result2 = $this->rollup->run();

        $entry->refresh();
        $this->assertEquals('700.0000', $entry->value);

        // Still only one entry (no duplicate)
        $this->assertEquals(1, HealthMetric::count());
    }

    /** @test 4.3: corrected raw value re-summarizes */
    public function correctedRawValueReSummarizes(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed initial readings
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
        $this->rollup->run();
        $entry = HealthMetric::first();
        $this->assertEquals('350.0000', $entry->value);

        // Correct the first reading from 100 to 200 (use the known external_id)
        $raw = RawMeasurement::orderBy('id')->first();
        $raw->value = 200.0;
        $raw->save();

        // Re-mark bucket dirty
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

        // Re-run — should update to 450
        $this->rollup->run();
        $entry->refresh();
        $this->assertEquals('450.0000', $entry->value);
    }

    /** @test FR-011: arbitrary age late reading still updates */
    public function arbitraryAgeLateReadingUpdates(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed a reading from weeks ago
        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 500.0,
                'unit' => 'steps',
                'recorded_at' => '2026-06-01 10:10:00 UTC',
            ],
        ]);

        // First run
        $this->rollup->run();
        $entry = HealthMetric::first();
        $this->assertEquals('500.0000', $entry->value);

        // Add a late reading for the same (old) hour
        $bucketHour = MeasurementBucket::for('2026-06-01 10:30:00 UTC');
        RawMeasurement::create([
            'user_id' => $userId,
            'external_service' => 'fitbit',
            'external_id' => 'late-ancient-001',
            'type' => 'steps',
            'value' => 100.0,
            'unit' => 'steps',
            'recorded_at' => MeasurementBucket::normalize('2026-06-01 10:30:00 UTC'),
            'bucket_hour' => $bucketHour,
        ]);

        // Re-mark bucket dirty
        MeasurementRollupQueue::updateOrCreate(
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'unit' => 'steps',
                'bucket_hour' => '2026-06-01 10:00:00',
            ],
            [],
        );

        // Re-run — should update the old entry
        $this->rollup->run();
        $entry->refresh();
        $this->assertEquals('600.0000', $entry->value);
    }

    /* ------------------------------------------------------------------ */
    /* T059 - Deletion re-marks bucket dirty                               */
    /* ------------------------------------------------------------------ */

    /** @test: deleting a raw reading re-marks bucket dirty and recomputes downward */
    public function deletingRawReadingRecomputesDownward(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed readings
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

        // First run — 650
        $this->rollup->run();
        $entry = HealthMetric::first();
        $this->assertEquals('650.0000', $entry->value);

        // Delete one raw reading (300) — should re-mark bucket dirty via deleted event
        $rawToDelete = RawMeasurement::where('value', '300.0000')->first();
        $this->assertNotNull($rawToDelete);
        $rawToDelete->delete();

        // The deleted event should have re-marked the bucket
        $queueRow = MeasurementRollupQueue::where('user_id', $userId)
            ->where('external_service', 'fitbit')
            ->where('type', 'steps')
            ->where('unit', 'steps')
            ->where('bucket_hour', '2026-07-19 09:00:00')
            ->first();
        $this->assertNotNull($queueRow, 'Bucket should be re-marked dirty after raw reading deletion');

        // Re-run — should recompute to 350 (100 + 250)
        $this->rollup->run();
        $entry->refresh();
        $this->assertEquals('350.0000', $entry->value);
    }
}
