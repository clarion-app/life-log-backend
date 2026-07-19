<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;
use Tests\Support\RecordingMultiChain;

class BridgeWriteMinimizationTest extends TestCase
{
    protected HourlyMeasurementRollup $rollup;
    protected RecordingMultiChain $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = $this->enableRecordingBridge();
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
    /* T055 - Bridge write minimization tests                              */
    /* ------------------------------------------------------------------ */

    /** @test first run records exactly one publish, second unchanged run records zero */
    public function test_firstRunPublishesOnceAndSecondUnchangedRunPublishesZero(): void
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

        // First run — should publish exactly once
        $this->spy->published = [];
        $result1 = $this->rollup->run();
        $this->assertEquals(1, $result1['written']);
        $this->assertEquals(1, count($this->spy->published));

        // Re-seed the queue (simulating dirty-hour marking)
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

        // Second run — no data changed, should publish zero times
        $this->spy->published = [];
        $result2 = $this->rollup->run();

        // The rollup "writes" the entry (firstOrNew + save), but because value is unchanged,
        // the model is not dirty and no publish should occur
        $this->assertEquals(0, count($this->spy->published));
    }

    /** @test rollup write does publish (proving bridged path, not upsert) */
    public function test_rollupWriteDoesPublish(): void
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
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
        ]);

        $this->spy->published = [];
        $result = $this->rollup->run();

        // First run on new data should publish
        $this->assertEquals(1, count($this->spy->published));
        $this->assertEquals('life_log_health_metrics', $this->spy->published[0]['stream']);
    }

    /* ------------------------------------------------------------------ */
    /* T056 - Framework mechanism test                                     */
    /* ------------------------------------------------------------------ */

    /** @test re-saving an unchanged HealthMetric fires no updated event and leaves updated_at untouched */
    public function test_reSavingUnchangedHealthMetricFiresNoEvent(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $metric = HealthMetric::create([
            'user_id' => $userId,
            'type' => 'steps',
            'value' => '650.0000',
            'recorded_at' => '2026-07-19 09:00:00',
            'source' => 'fitbit',
            'unit' => 'steps',
            'external_service' => 'fitbit',
            'bucket_hour' => '2026-07-19 09:00:00',
        ]);

        $originalUpdatedAt = $metric->updated_at;
        $originalCreatedAt = $metric->created_at;

        // Small delay to detect any timestamp change
        usleep(10000);

        // Re-save with same attributes
        $metric->save();
        $metric->refresh();

        // updated_at should be unchanged (no dirty attributes means no update)
        $this->assertEquals($originalUpdatedAt, $metric->updated_at);
        $this->assertEquals($originalCreatedAt, $metric->created_at);

        // And zero publishes in the spy
        $this->assertEquals(1, count($this->spy->published)); // Only the initial create
    }
}
