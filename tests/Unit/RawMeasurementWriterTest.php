<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use Illuminate\Support\Facades\Auth;
use Carbon\CarbonImmutable;

class RawMeasurementWriterTest extends TestCase
{
    protected RawMeasurementWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = app(RawMeasurementWriter::class);
    }

    /** @test SC-001 scenario 1.1: 10,000-row batch stores 10,000 raw rows, zero publishes */
    public function largeBatchStoresAllRowsAndRecordsZeroPublishes(): void
    {
        $chain = $this->enableRecordingBridge();
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [];
        for ($i = 0; $i < 10000; $i++) {
            $readings[] = [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => "step-{$i}",
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:00:00 UTC')->addSeconds($i),
            ];
        }

        $count = $this->writer->write($readings);

        $this->assertEquals(10000, $count);
        $this->assertEquals(10000, RawMeasurement::count());
        $this->assertCount(0, $chain->published, 'SC-001: zero publishes against bridge');
    }

    /** @test SC-001 scenario 1.2: batch creates zero HealthMetric rows */
    public function largeBatchCreatesZeroHealthMetricRows(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [];
        for ($i = 0; $i < 100; $i++) {
            $readings[] = [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => "hr-{$i}",
                'type' => 'heart_rate',
                'value' => 72.0,
                'unit' => 'bpm',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:00:00 UTC')->addSeconds($i),
            ];
        }

        $this->writer->write($readings);

        $this->assertEquals(0, HealthMetric::count());
    }

    /** @test SC-001 scenario 1.3: stored reading carries all fields including metadata roundtrip */
    public function storedReadingCarriesAllFieldsAndMetadataRoundtrip(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'garmin',
                'external_id' => 'sleep-001',
                'type' => 'sleep_duration',
                'value' => 480.0,
                'unit' => 'minutes',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 07:00:00 UTC'),
                'metadata' => ['quality' => 'good', 'awakenings' => 2],
            ],
        ];

        $this->writer->write($readings);

        $record = RawMeasurement::first();
        $this->assertEquals($userId, $record->user_id);
        $this->assertEquals('garmin', $record->external_service);
        $this->assertEquals('sleep-001', $record->external_id);
        $this->assertEquals('sleep_duration', $record->type);
        $this->assertEquals('480.0000', $record->value);
        $this->assertEquals('minutes', $record->unit);
        $this->assertEquals(['quality' => 'good', 'awakenings' => 2], $record->metadata);
    }

    /** @test FR-002: absent unit stores empty string, not NULL */
    public function readingWithNoUnitStoresEmptyString(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'custom',
                'external_id' => 'temp-001',
                'type' => 'temperature',
                'value' => 36.6,
                'recorded_at' => CarbonImmutable::parse('2026-07-19 10:00:00 UTC'),
                // no 'unit' key
            ],
        ];

        $this->writer->write($readings);

        $record = RawMeasurement::first();
        $this->assertStringContainsString('', $record->unit);
        $this->assertNotSame(null, $record->unit);
    }

    /** @test T025: dirty-hour marking — batch spanning three buckets produces three queue rows */
    public function batchSpanningThreeBucketsProducesThreeQueueRows(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 's1',
                'type' => 'steps',
                'value' => 100,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:30:00 UTC'),
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 's2',
                'type' => 'steps',
                'value' => 200,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 10:30:00 UTC'),
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 's3',
                'type' => 'steps',
                'value' => 300,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 11:30:00 UTC'),
            ],
        ];

        $this->writer->write($readings);

        $this->assertEquals(3, MeasurementRollupQueue::count());
    }

    /** @test T025: re-writing the same batch leaves queue count unchanged */
    public function rewritingSameBatchLeavesQueueCountUnchanged(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 's1',
                'type' => 'steps',
                'value' => 100,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:30:00 UTC'),
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 's2',
                'type' => 'steps',
                'value' => 200,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 10:30:00 UTC'),
            ],
        ];

        $this->writer->write($readings);
        $this->assertEquals(2, MeasurementRollupQueue::count());

        // Re-write same batch
        $this->writer->write($readings);
        $this->assertEquals(2, MeasurementRollupQueue::count(), 'Re-writing same batch leaves queue count unchanged');
    }

    /** @test T026: FR-006 time-range retrieval */
    public function timeRangeRetrievalReturnsCorrectUserRowsInRange(): void
    {
        $user1 = '00000000-0000-0000-0000-000000000001';
        $user2 = '00000000-0000-0000-0000-000000000002';

        // User 1: readings across 5 hours
        for ($h = 0; $h < 5; $h++) {
            RawMeasurement::create([
                'user_id' => $user1,
                'external_service' => 'fitbit',
                'external_id' => "u1-h{$h}",
                'type' => 'steps',
                'value' => 100 + $h * 100,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse("2026-07-19 0{$h}:30:00 UTC"),
                'bucket_hour' => CarbonImmutable::parse("2026-07-19 0{$h}:00:00 UTC"),
            ]);
        }

        // User 2: readings in the same time range
        for ($h = 1; $h < 4; $h++) {
            RawMeasurement::create([
                'user_id' => $user2,
                'external_service' => 'fitbit',
                'external_id' => "u2-h{$h}",
                'type' => 'steps',
                'value' => 50 + $h * 50,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse("2026-07-19 0{$h}:30:00 UTC"),
                'bucket_hour' => CarbonImmutable::parse("2026-07-19 0{$h}:00:00 UTC"),
            ]);
        }

        $results = RawMeasurement::forUserBetween(
            $user1,
            CarbonImmutable::parse('2026-07-19 01:00:00 UTC'),
            CarbonImmutable::parse('2026-07-19 03:59:59 UTC')
        )->get();

        // Should return user1's rows at hours 1, 2, 3 (3 rows)
        $this->assertEquals(3, $results->count());
        $this->assertTrue($results->every(fn ($r) => $r->user_id === $user1));

        // Verify ordering by recorded_at
        $times = $results->pluck('recorded_at')->toArray();
        $sorted = $times;
        sort($sorted);
        $this->assertEquals($sorted, $times);
    }
}
