<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;
use Tests\Support\RecordingMultiChain;
use Tests\TestCase;

/**
 * SC-009/FR-023: The raw tier is deliberately non-bridged.
 *
 * A full multi-hour intraday ingest must produce zero bridge activity for
 * raw rows. The only path to the chain is the rollup (for Rollup-mode types)
 * or the direct promoter (for Direct-mode types).
 */
class RawTierNotBridgedTest extends TestCase
{
    protected RecordingMultiChain $spy;
    protected RawMeasurementWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = $this->enableRecordingBridge();
        $this->writer = new RawMeasurementWriter();
    }

    /**
     * @test Multi-hour ingest of raw measurements produces zero bridge activity.
     *
     * RawMeasurement has no EloquentMultiChainBridge trait — upsert() bypasses
     * events entirely. Even if the bridge were accidentally added, the upsert
     * path would not fire events. This test asserts the property holds.
     */
    public function testMultiHourIngestProducesZeroBridgeActivity(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Seed classification for the types we ingest
        MeasurementTypeClassification::updateOrCreate(
            ['type' => 'steps'],
            ['aggregation' => MeasurementTypeClassification::AGGREGATION_CUMULATIVE],
        );
        MeasurementTypeClassification::updateOrCreate(
            ['type' => 'heart_rate'],
            ['aggregation' => MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME],
        );

        // Build readings across 3 hours, multiple types — a realistic intraday ingest
        $readings = [];
        for ($hour = 0; $hour < 3; $hour++) {
            for ($minute = 0; $minute < 60; $minute += 10) {
                $ts = sprintf('2026-07-19 %02d:%02d:00 UTC', $hour + 8, $minute);
                $readings[] = [
                    'user_id' => $userId,
                    'external_service' => 'google-health',
                    'type' => 'steps',
                    'value' => 100.0 + $minute,
                    'unit' => 'count',
                    'recorded_at' => $ts,
                ];
                $readings[] = [
                    'user_id' => $userId,
                    'external_service' => 'google-health',
                    'type' => 'heart_rate',
                    'value' => 72.0 + ($minute % 20),
                    'unit' => 'bpm',
                    'recorded_at' => $ts,
                ];
            }
        }

        // Clear any publishes from setup
        $this->spy->published = [];

        // Write all readings in a single batch
        $count = $this->writer->write($readings);
        $this->assertGreaterThan(0, $count, 'Readings should be written');

        // Assert zero bridge activity — raw tier is non-bridged
        $this->assertCount(
            0,
            $this->spy->published,
            'Raw tier ingest must produce zero bridge activity. '
            . 'RawMeasurement is non-bridged (constitution §III) and uses upsert() which bypasses events.',
        );

        // Verify raw rows exist
        $this->assertSame(count($readings), RawMeasurement::count());

        // Verify queue rows exist (for rollup types) — but no bridge events
        $stepsQueue = MeasurementRollupQueue::where('type', 'steps')->count();
        $hrQueue = MeasurementRollupQueue::where('type', 'heart_rate')->count();
        $this->assertGreaterThan(0, $stepsQueue, 'Steps should have queue entries');
        // HeartRate is Direct mode — should NOT have queue entries after T076
        // For now (before T076), it will have entries. This test runs before T076.
    }

    /**
     * @test RawMeasurement model does not use EloquentMultiChainBridge.
     *
     * Structural check: the trait is absent from the model class.
     */
    public function testRawMeasurementModelHasNoBridgeTrait(): void
    {
        $traits = class_uses(RawMeasurement::class);
        $this->assertNotContains(
            \ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge::class,
            $traits ?? [],
            'RawMeasurement must not use EloquentMultiChainBridge.',
        );
    }

    /**
     * @test MeasurementRollupQueue is also non-bridged.
     *
     * The queue is bookkeeping — it must never reach the chain.
     */
    public function testMeasurementRollupQueueHasNoBridgeTrait(): void
    {
        $traits = class_uses(MeasurementRollupQueue::class);
        $this->assertNotContains(
            \ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge::class,
            $traits ?? [],
            'MeasurementRollupQueue must not use EloquentMultiChainBridge.',
        );
    }
}
