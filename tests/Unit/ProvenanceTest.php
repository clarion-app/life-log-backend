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
use Illuminate\Support\Facades\DB;

class ProvenanceTest extends TestCase
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
    /* T064 - Provenance tests                                             */
    /* ------------------------------------------------------------------ */

    /** @test 5.1: manual create with no stated origin stores source='manual' */
    public function manualCreateWithNoStatedOriginStoresManualSource(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Create via query builder (omitting source) to test column default
        DB::table('life_log_health_metrics')->insert([
            'id' => $userId,
            'user_id' => $userId,
            'type' => 'blood_pressure',
            'value' => '120.0000',
            'recorded_at' => '2026-07-19 09:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $metric = HealthMetric::first();
        // Column default should be 'manual'
        $this->assertEquals('manual', $metric->source);
    }

    /** @test 5.2: row inserted without source reads back as manual */
    public function rowInsertedWithoutSourceReadsAsManual(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Simulate a pre-feature row inserted without source column
        // (omit 'source' to test column default)
        DB::table('life_log_health_metrics')->insert([
            'id' => $userId,
            'user_id' => $userId,
            'type' => 'legacy_metric',
            'value' => '100.0000',
            'recorded_at' => '2026-06-01 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $metric = HealthMetric::first();
        // Column default should be 'manual'
        $this->assertEquals('manual', $metric->source);
    }

    /** @test 5.3: rollup entry reports its service as origin */
    public function rollupEntryReportsServiceAsOrigin(): void
    {
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'garmin',
                'type' => 'steps',
                'value' => 1000.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 09:10:00 UTC',
            ],
        ]);

        $this->rollup->run();

        $metric = HealthMetric::first();
        $this->assertEquals('garmin', $metric->source);
        $this->assertEquals('garmin', $metric->external_service);
    }

    /** @test 5.4: listing containing both kinds distinguishes by source */
    public function listingDistinguishesManualAndRollupBySource(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Create a manual entry
        HealthMetric::create([
            'user_id' => $userId,
            'type' => 'blood_pressure',
            'value' => '120.0000',
            'recorded_at' => '2026-07-19 09:00:00',
            'source' => 'manual',
        ]);

        // Create a rollup entry
        $this->classify('steps', MeasurementTypeClassification::AGGREGATION_CUMULATIVE);
        $this->seedReadingsAndQueue([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 500.0,
                'unit' => 'steps',
                'recorded_at' => '2026-07-19 10:10:00 UTC',
            ],
        ]);
        $this->rollup->run();

        $metrics = HealthMetric::where('user_id', $userId)->get();

        $manualMetrics = $metrics->where('source', 'manual');
        $rollupMetrics = $metrics->where('source', 'fitbit');

        $this->assertEquals(1, $manualMetrics->count());
        $this->assertEquals(1, $rollupMetrics->count());
    }

    /* ------------------------------------------------------------------ */
    /* T065 - D6 backfill test                                             */
    /* ------------------------------------------------------------------ */

    /** @test: query-builder update leaves updated_at untouched and fires no event */
    public function queryBuilderUpdateLeavesUpdatedAtUntouched(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // Create a metric
        $metric = HealthMetric::create([
            'user_id' => $userId,
            'type' => 'test_metric',
            'value' => '100.0000',
            'recorded_at' => '2026-07-19 09:00:00',
            'source' => 'manual',
        ]);

        $originalUpdatedAt = $metric->updated_at;

        // Small delay to detect any timestamp change
        usleep(10000);

        // Query-builder update (simulating migration backfill)
        DB::table('life_log_health_metrics')
            ->where('id', $metric->id)
            ->update(['source' => 'manual']);

        // updated_at should be unchanged
        $metric->refresh();
        $this->assertEquals($originalUpdatedAt, $metric->updated_at);

        // And zero publishes in the spy (query-builder doesn't fire Eloquent events)
        // The spy should have recorded the initial create, but not the query-builder update
        $publishCount = count($this->spy->published);
        $this->assertEquals(1, $publishCount); // Only the initial create
    }

    /* ------------------------------------------------------------------ */
    /* T066 - Additive request field tests                                 */
    /* ------------------------------------------------------------------ */

    /** @test: request supplying source and unit stores them */
    public function requestWithSourceAndUnitStoresThem(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $metric = HealthMetric::create([
            'user_id' => $userId,
            'type' => 'temperature',
            'value' => '37.5000',
            'recorded_at' => '2026-07-19 09:00:00',
            'source' => 'apple_health',
            'unit' => 'celsius',
        ]);

        $this->assertEquals('apple_health', $metric->source);
        $this->assertEquals('celsius', $metric->unit);
    }

    /** @test: request omitting source and unit still succeeds with defaults */
    public function requestWithoutSourceAndUnitSucceedsWithDefaults(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $metric = HealthMetric::create([
            'user_id' => $userId,
            'type' => 'weight',
            'value' => '70.0000',
            'recorded_at' => '2026-07-19 09:00:00',
        ]);

        // source should default to 'manual'
        $this->assertEquals('manual', $metric->source);
        // unit should be null (FR-018: manual entries may have no unit)
        $this->assertNull($metric->unit);
    }
}
