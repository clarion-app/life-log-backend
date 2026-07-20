<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Services\DirectMeasurementPromoter;
use Tests\Support\RecordingMultiChain;
use Tests\TestCase;

/**
 * FR-025/SC-011: Direct-mode measurements bypass the rollup pipeline.
 *
 * Two weigh-ins in one hour produce two bridged HealthMetric rows, not one
 * (the exact data loss the rollup would cause). Promotion is idempotent on
 * unchanged rows and fires bridge events.
 */
class DirectMeasurementPromotionTest extends TestCase
{
    protected RecordingMultiChain $spy;
    protected RawMeasurementWriter $writer;
    protected DirectMeasurementPromoter $promoter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = $this->enableRecordingBridge();
        $this->writer = new RawMeasurementWriter();
        $this->promoter = new DirectMeasurementPromoter();
    }

    /**
     * @test Two weigh-ins in one hour produce two bridged HealthMetric rows.
     *
     * The rollup would aggregate these into one (SUM or AVG), losing the
     * individual readings. Direct mode promotes each raw row as a separate
     * HealthMetric — the exact value is preserved.
     */
    public function testTwoWeighInsInOneHourProduceTwoBridgedRows(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'google-health',
                'external_id' => 'weight:1721419200',
                'type' => 'weight',
                'value' => 70.5000,
                'unit' => 'kg',
                'recorded_at' => '2026-07-19 09:00:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'google-health',
                'external_id' => 'weight:1721419800',
                'type' => 'weight',
                'value' => 70.3000,
                'unit' => 'kg',
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
        ];

        $this->writer->write($readings);

        // Two raw rows
        $this->assertSame(2, RawMeasurement::count());

        // Promote
        $this->spy->published = [];
        $result = $this->promoter->run();

        $this->assertSame(2, $result['promoted']);
        $this->assertSame(0, $result['skipped']);

        // Two HealthMetric rows — each weigh-in preserved
        $this->assertSame(2, HealthMetric::count());

        $metrics = HealthMetric::orderBy('recorded_at')->get();
        $this->assertEquals('70.5000', (string) $metrics[0]->value);
        $this->assertEquals('70.3000', (string) $metrics[1]->value);

        // Two bridge events (one per promoted row)
        $this->assertCount(2, $this->spy->published);
        foreach ($this->spy->published as $p) {
            $this->assertSame('life_log_health_metrics', $p['stream']);
        }

        // Both raw rows are now promoted
        $this->assertSame(0, RawMeasurement::whereNull('promoted_at')->count());
    }

    /**
     * @test Promotion is idempotent on unchanged rows.
     *
     * Re-promoting the same data produces no new bridge events and no new rows.
     */
    public function testPromotionIsIdempotentOnUnchangedRows(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $reading = [
            'user_id' => $userId,
            'external_service' => 'google-health',
            'external_id' => 'weight:1721419200',
            'type' => 'weight',
            'value' => 70.5000,
            'unit' => 'kg',
            'recorded_at' => '2026-07-19 09:00:00 UTC',
        ];

        $this->writer->write([$reading]);

        // First promotion
        $this->spy->published = [];
        $this->promoter->run();
        $this->assertCount(1, $this->spy->published);
        $this->assertSame(1, HealthMetric::count());

        // Reset the promoted_at to simulate a re-ingest
        RawMeasurement::where('external_id', 'weight:1721419200')
            ->update(['promoted_at' => null]);

        // Second promotion — same data, no change
        $this->spy->published = [];
        $this->promoter->run();

        // Still one HealthMetric row — the value didn't change, no new publish
        $this->assertSame(1, HealthMetric::count());
        // No publish because the HealthMetric value was unchanged
        $this->assertCount(0, $this->spy->published);
    }

    /**
     * @test Promoting a corrected reading updates the HealthMetric in place.
     *
     * The raw row is rewritten (same external_id), promoted_at reset, and
     * re-promotion updates the existing HealthMetric.
     */
    public function testCorrectedReadingUpdatesInPlace(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $reading = [
            'user_id' => $userId,
            'external_service' => 'google-health',
            'external_id' => 'weight:1721419200',
            'type' => 'weight',
            'value' => 70.5000,
            'unit' => 'kg',
            'recorded_at' => '2026-07-19 09:00:00 UTC',
        ];

        $this->writer->write([$reading]);
        $this->promoter->run();

        $metric = HealthMetric::first();
        $originalId = $metric->id;
        $this->assertEquals('70.5000', (string) $metric->value);

        // Correction: same external_id, different value
        $corrected = $reading;
        $corrected['value'] = 71.2000;
        $this->writer->write([$corrected]);

        // Raw row updated in place (upsert)
        $this->assertSame(1, RawMeasurement::count());
        // promoted_at reset by upsert
        $this->assertSame(1, RawMeasurement::whereNull('promoted_at')->count());

        // Re-promote
        $this->spy->published = [];
        $this->promoter->run();

        // One publish for the update
        $this->assertCount(1, $this->spy->published);
        // Same HealthMetric row
        $this->assertSame(1, HealthMetric::count());
        $this->assertSame($originalId, HealthMetric::first()->id);
        $this->assertEquals('71.2000', (string) HealthMetric::first()->value);
    }

    /**
     * @test HeartRate (also Direct) promotes the same way as Weight.
     */
    public function testHeartRateAlsoDirectMode(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'google-health',
                'external_id' => 'hr:1721419200',
                'type' => 'heart_rate',
                'value' => 72.0,
                'unit' => 'bpm',
                'recorded_at' => '2026-07-19 09:00:00 UTC',
            ],
            [
                'user_id' => $userId,
                'external_service' => 'google-health',
                'external_id' => 'hr:1721419800',
                'type' => 'heart_rate',
                'value' => 85.0,
                'unit' => 'bpm',
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
        ];

        $this->writer->write($readings);
        $this->spy->published = [];
        $result = $this->promoter->run();

        $this->assertSame(2, $result['promoted']);
        $this->assertSame(2, HealthMetric::where('type', 'heart_rate')->count());
        $this->assertCount(2, $this->spy->published);
    }

    /**
     * @test Rollup-mode types are NOT promoted by DirectMeasurementPromoter.
     *
     * Steps (Rollup) readings should be left alone — they go through the rollup,
     * not the direct promoter.
     */
    public function testRollupModeTypesAreNotPromoted(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 'steps-1',
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'count',
                'recorded_at' => '2026-07-19 09:05:00 UTC',
            ],
        ];

        $this->writer->write($readings);

        $this->spy->published = [];
        $result = $this->promoter->run();

        // Steps are Rollup mode — promoter skips them
        $this->assertSame(0, $result['promoted']);
        $this->assertSame(0, HealthMetric::count());
        $this->assertCount(0, $this->spy->published);
    }
}
