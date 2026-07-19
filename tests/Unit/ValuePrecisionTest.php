<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;

class ValuePrecisionTest extends TestCase
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
    /* T058 - Precision and overflow tests                                 */
    /* ------------------------------------------------------------------ */

    /** @test SC-008: value round-trips through both stores with decimal:4 cast */
    public function largeValueRoundTripsAsString(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // Write a value to raw store - decimal:4 cast ensures 4dp string format
        $rawValue = '1234567.8901';
        $bucketHour = MeasurementBucket::for('2026-07-19 09:10:00 UTC');

        RawMeasurement::create([
            'user_id' => $userId,
            'external_service' => 'fitbit',
            'external_id' => 'large-value-001',
            'type' => 'steps',
            'value' => $rawValue,
            'unit' => 'steps',
            'recorded_at' => MeasurementBucket::normalize('2026-07-19 09:10:00 UTC'),
            'bucket_hour' => $bucketHour,
        ]);

        MeasurementRollupQueue::updateOrCreate(
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'unit' => 'steps',
                'bucket_hour' => $bucketHour,
            ],
            [],
        );

        // Run rollup
        $this->rollup->run();

        // Check raw store value (as string via decimal:4 cast)
        $raw = RawMeasurement::first();
        $this->assertEquals($rawValue, (string) $raw->value);

        // Check bridged store value - should match via decimal:4 cast
        $metric = HealthMetric::first();
        $this->assertNotNull($metric);
        $this->assertEquals($rawValue, (string) $metric->value);
    }

    /** @test: decimal:4 cast preserves precision on HealthMetric create/read */
    public function decimalCastPreservesPrecision(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Create with 4dp value
        $metric = HealthMetric::create([
            'user_id' => $userId,
            'type' => 'test_metric',
            'value' => '12345.6789',
            'recorded_at' => '2026-07-19 09:00:00',
            'source' => 'manual',
        ]);

        // Read back - should preserve 4dp
        $metric->refresh();
        $this->assertEquals('12345.6789', (string) $metric->value);

        // Update to another 4dp value
        $metric->value = '98765.4321';
        $metric->save();
        $metric->refresh();
        $this->assertEquals('98765.4321', (string) $metric->value);
    }

    /** @test: pre-existing value reads back byte-identical after widening */
    public function preExistingValueReadsBackIdentical(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Create a HealthMetric directly (simulating a pre-existing row)
        $metric = HealthMetric::create([
            'user_id' => $userId,
            'type' => 'manual_test',
            'value' => '123456789012.3456',
            'recorded_at' => '2026-07-19 09:00:00',
            'source' => 'manual',
        ]);

        // Read back
        $metric->refresh();
        $this->assertEquals('123456789012.3456', (string) $metric->value);
    }

    /** @test: sum beyond decimal(16,4) writes no entry and defers with overflow reason */
    public function overflowSumDefersWithOverflowReason(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed readings that sum beyond value_max (999999999999.9999)
        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => '500000000000.0000',
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => '500000000000.0000',
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:30:00 UTC',
            ],
        ]);

        // Run rollup
        $result = $this->rollup->run();

        // Should be deferred, not written
        $this->assertEquals(0, $result['written']);
        $this->assertEquals(1, $result['deferred']);

        // No HealthMetric entry created
        $this->assertEquals(0, HealthMetric::count());

        // Queue row should have deferred_reason = 'overflow'
        $queueRow = MeasurementRollupQueue::first();
        $this->assertNotNull($queueRow);
        $this->assertEquals('overflow', $queueRow->deferred_reason);
    }
}
