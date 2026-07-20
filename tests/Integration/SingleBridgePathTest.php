<?php

namespace Tests\Integration;

use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\ReplicationMode;
use Tests\Support\RecordingMultiChain;
use Tests\TestCase;

/**
 * FR-027: No reading reaches the chain by more than one path.
 *
 * The ReplicationMode partition ensures each type has exactly one write path:
 * Rollup types go raw → queue → rollup → bridge.
 * Direct types go raw → promote → bridge.
 *
 * This test asserts that a Rollup-mode type produces exactly one bridge event
 * per hour (the rollup path), and that the raw write itself produces none.
 */
class SingleBridgePathTest extends TestCase
{
    protected RecordingMultiChain $spy;
    protected RawMeasurementWriter $writer;
    protected HourlyMeasurementRollup $rollup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = $this->enableRecordingBridge();
        $this->writer = new RawMeasurementWriter();
        $this->rollup = new HourlyMeasurementRollup();
        $this->syncVocabulary();
    }

    /**
     * @test Rollup-mode type: raw write produces no bridge events, rollup produces one per hour.
     *
     * Steps (Rollup) readings across 2 hours → 0 publishes on write, 2 on rollup.
     */
    public function testRollupModeTypeSinglePath(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [];
        // Hour 1: 3 readings
        for ($i = 0; $i < 3; $i++) {
            $readings[] = [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'count',
                'recorded_at' => sprintf('2026-07-19 09:%02d:00 UTC', $i * 10),
            ];
        }
        // Hour 2: 2 readings
        for ($i = 0; $i < 2; $i++) {
            $readings[] = [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 150.0,
                'unit' => 'count',
                'recorded_at' => sprintf('2026-07-19 10:%02d:00 UTC', $i * 15),
            ];
        }

        // Raw write: zero bridge events
        $this->spy->published = [];
        $this->writer->write($readings);
        $this->assertCount(
            0,
            $this->spy->published,
            'Raw write of Rollup-mode type must produce zero bridge events.',
        );

        // Rollup: one bridge event per hour
        $this->spy->published = [];
        $this->rollup->run();
        $this->assertCount(
            2,
            $this->spy->published,
            'Rollup must produce exactly one bridge event per dirty hour.',
        );

        // Both events are for HealthMetric stream
        foreach ($this->spy->published as $p) {
            $this->assertSame('life_log_health_metrics', $p['stream']);
        }

        // Two HealthMetric rows (one per hour)
        $this->assertSame(2, HealthMetric::count());
    }

    /**
     * @test Direct-mode type: raw write produces no bridge events.
     *
     * Weight (Direct) readings → 0 publishes on write. After T077-T078,
     * the promoter will bridge them. This test runs before promotion.
     *
     * The key property: the raw write path never bridges, regardless of mode.
     * The only difference is what happens after (rollup vs promoter).
     */
    public function testDirectModeTypeRawWriteNotBridged(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [];
        for ($i = 0; $i < 3; $i++) {
            $readings[] = [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'weight',
                'value' => 70.0 + $i * 0.5,
                'unit' => 'kg',
                'recorded_at' => sprintf('2026-07-19 09:%02d:00 UTC', $i * 10),
            ];
        }

        $this->spy->published = [];
        $this->writer->write($readings);

        // Raw write never bridges, regardless of replication mode
        $this->assertCount(
            0,
            $this->spy->published,
            'Raw write of Direct-mode type must produce zero bridge events.',
        );
    }

    /**
     * @test The ReplicationMode partition is total — every type has exactly one mode.
     *
     * This is the structural guarantee that makes "single path" checkable.
     */
    public function testReplicationModePartitionIsTotal(): void
    {
        $modes = [];
        foreach (MeasurementType::cases() as $type) {
            $mode = $type->replicationMode();
            $this->assertInstanceOf(
                ReplicationMode::class,
                $mode,
                "{$type->value} must return a ReplicationMode.",
            );
            $modes[$type->value] = $mode;
        }

        // Every type has a mode
        $this->assertCount(count(MeasurementType::cases()), $modes);

        // Both modes are represented
        $rollupCount = count(array_filter($modes, fn ($m) => $m === ReplicationMode::Rollup));
        $directCount = count(array_filter($modes, fn ($m) => $m === ReplicationMode::Direct));
        $this->assertGreaterThan(0, $rollupCount, 'At least one type must be Rollup mode.');
        $this->assertGreaterThan(0, $directCount, 'At least one type must be Direct mode.');
    }

    /**
     * @test Rollup is idempotent: re-running after all queue rows are drained publishes nothing new.
     *
     * Even though the rollup "writes" (firstOrNew + save), the model is not dirty
     * and the bridge publishes nothing. This is the other half of FR-027:
     * the single path is also the only path — no accidental double-publish.
     */
    public function testRollupIdempotentNoDoublePublish(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'count',
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
        ];

        $this->writer->write($readings);

        $this->spy->published = [];
        $this->rollup->run();
        $firstRunPublishes = count($this->spy->published);
        $this->assertSame(1, $firstRunPublishes);

        // Re-queue the same hour (simulating a late-arrival re-mark)
        MeasurementRollupQueue::updateOrCreate(
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'unit' => 'count',
                'bucket_hour' => '2026-07-19 09:00:00',
            ],
            [],
        );

        // Second rollup: no data changed → no publish
        $this->spy->published = [];
        $this->rollup->run();
        $this->assertCount(
            0,
            $this->spy->published,
            'Re-running rollup on unchanged data must not publish.',
        );
    }
}
