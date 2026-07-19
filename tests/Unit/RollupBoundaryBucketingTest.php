<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use Carbon\CarbonImmutable;

class RollupBoundaryBucketingTest extends TestCase
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
    /* T041 - Boundary bucketing tests                                     */
    /* ------------------------------------------------------------------ */

    /** @test */
    public function test_readingAtExactHourStartLandsInThatHour(): void
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
                'recorded_at' => '2026-07-19 10:00:00.000 UTC',
            ],
        ]);

        $this->rollup->run();

        $entry = HealthMetric::first();
        $this->assertNotNull($entry);
        $this->assertEquals('2026-07-19 10:00:00', $entry->bucket_hour->format('Y-m-d H:i:s'));
        $this->assertEquals('100.0000', $entry->value);
    }

    /** @test */
    public function test_readingJustBeforeHourStartLandsInPreviousHour(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 200.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:59:59.999 UTC',
            ],
        ]);

        $this->rollup->run();

        $entry = HealthMetric::first();
        $this->assertNotNull($entry);
        $this->assertEquals('2026-07-19 09:00:00', $entry->bucket_hour->format('Y-m-d H:i:s'));
        $this->assertEquals('200.0000', $entry->value);
    }

    /** @test */
    public function test_offsetBearingInputAggregatesIntoUtcBucket(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        // 15:30:00 +05:00 = 10:30:00 UTC → bucket 10:00
        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 300.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 15:30:00+05:00',
            ],
        ]);

        $this->rollup->run();

        $entry = HealthMetric::first();
        $this->assertNotNull($entry);
        $this->assertEquals('2026-07-19 10:00:00', $entry->bucket_hour->format('Y-m-d H:i:s'));
        $this->assertEquals('300.0000', $entry->value);
    }
}
