<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use Carbon\CarbonImmutable;

class RollupUnclassifiedTypeTest extends TestCase
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
    /* T042 - Unclassified type tests                                      */
    /* ------------------------------------------------------------------ */

    /** @test 3.7: unclassified type → zero entries, raw rows remain, queue deferred */
    public function unclassifiedTypeProducesZeroEntriesAndDefersQueue(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed readings of a type with NO classification
        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'unknown_metric',
                'value' => 42.0,
                'unit' => 'units',
                'recorded_at' => '2026-07-19 09:30:00 UTC',
            ],
        ]);

        $rawCountBefore = RawMeasurement::count();
        $this->assertEquals(1, $rawCountBefore);
        $this->assertEquals(1, MeasurementRollupQueue::count());

        $result = $this->rollup->run();

        // No entries written
        $this->assertEquals(0, $result['written']);
        $this->assertEquals(0, HealthMetric::count());

        // Raw rows remain
        $this->assertEquals($rawCountBefore, RawMeasurement::count());

        // Queue row remains with deferred_reason
        $queueRow = MeasurementRollupQueue::first();
        $this->assertNotNull($queueRow);
        $this->assertEquals('unclassified_type', $queueRow->deferred_reason);
    }

    /** @test 3.8: classifying the type and re-running summarizes correctly */
    public function classifyingTypeThenReRunningSummarizesCorrectly(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed readings of an unclassified type
        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'unknown_metric',
                'value' => 100.0,
                'unit' => 'units',
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'unknown_metric',
                'value' => 200.0,
                'unit' => 'units',
                'recorded_at' => '2026-07-19 09:40:00 UTC',
            ],
        ]);

        // First run: type unclassified → deferred
        $result1 = $this->rollup->run();
        $this->assertEquals(0, $result1['written']);
        $this->assertEquals(0, HealthMetric::count());

        $queueRow = MeasurementRollupQueue::first();
        $this->assertEquals('unclassified_type', $queueRow->deferred_reason);

        // Now classify the type. nothing else is required of the operator —
        // the deferred queue entry is itself the retry, so the next run picks it up.
        $this->classify('unknown_metric', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);

        // Second run: type now classified → summarized
        $result2 = $this->rollup->run();
        $this->assertEquals(1, $result2['written']);
        $this->assertEquals(0, MeasurementRollupQueue::count(), 'queue entry drained once written');

        $entry = HealthMetric::first();
        $this->assertNotNull($entry);
        $this->assertEquals('300.0000', $entry->value);
        $this->assertEquals('2026-07-19 09:00:00', $entry->bucket_hour->format('Y-m-d H:i:s'));
    }
}
