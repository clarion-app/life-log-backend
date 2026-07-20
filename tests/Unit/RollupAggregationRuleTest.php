<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Tests\TestCase;

/**
 * FR-024a: Each type rolls up by the rule the vocabulary declares.
 *
 * No new aggregation semantics are introduced — the rollup reads the
 * classification table, and the vocabulary enum declares the mapping.
 * This test asserts the end-to-end path: vocabulary → classification → rollup value.
 */
class RollupAggregationRuleTest extends TestCase
{
    protected HourlyMeasurementRollup $rollup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rollup = new HourlyMeasurementRollup();
        $this->syncVocabulary();
    }

    /**
     * @test Cumulative types (steps, calories_burned, distance, active_minutes) use SUM.
     *
     * Three readings of 100 each in one hour → rollup value is 300.
     */
    public function testCumulativeTypesUseSum(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        foreach (['steps', 'calories_burned', 'distance', 'active_minutes'] as $type) {
            $this->seedThreeReadings($userId, $type);
        }

        $this->rollup->run();

        foreach (['steps', 'calories_burned', 'distance', 'active_minutes'] as $type) {
            $metric = HealthMetric::where('type', $type)->first();
            $this->assertNotNull($metric, "{$type} should have a rollup row");
            $this->assertEquals(
                '300.0000',
                (string) $metric->value,
                "{$type} is cumulative and should SUM to 300.",
            );
        }
    }

    /**
     * @test Point-in-time types (heart_rate, weight) use average.
     *
     * Three readings of 100, 200, 300 in one hour → rollup value is 200 (average).
     */
    public function testPointInTimeTypesUseAverage(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // heart_rate: 100, 200, 300 → average 200
        $this->seedReadingsWithValues($userId, 'heart_rate', [100.0, 200.0, 300.0]);

        $this->rollup->run();

        $metric = HealthMetric::where('type', 'heart_rate')->first();
        $this->assertNotNull($metric);
        $this->assertEquals(
            '200.0000',
            (string) $metric->value,
            'heart_rate is point-in-time and should average to 200.',
        );
    }

    /**
     * @test Each MeasurementType enum case matches the classification table aggregation.
     *
     * Structural check: the vocabulary declares the rule, the classification
     * table stores it, and the rollup reads it. No drift between layers.
     */
    public function testVocabularyMatchesClassificationTable(): void
    {
        foreach (MeasurementType::cases() as $type) {
            $expected = $type->aggregation();
            $classification = MeasurementTypeClassification::where('type', $type->value)->first();

            $this->assertNotNull(
                $classification,
                "{$type->value} should have a classification row after syncVocabulary.",
            );
            $this->assertSame(
                $expected,
                $classification->aggregation,
                "{$type->value} classification aggregation should match vocabulary.",
            );
        }
    }

    /**
     * @test Single reading: cumulative type passes through, point-in-time type passes through.
     *
     * With one reading, SUM and AVG are the same value — the rollup is a no-op.
     */
    public function testSingleReadingPassesThroughForBothTypes(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $this->seedReadingsWithValues($userId, 'steps', [42.0]);
        $this->seedReadingsWithValues($userId, 'heart_rate', [72.0]);

        $this->rollup->run();

        $steps = HealthMetric::where('type', 'steps')->first();
        $hr = HealthMetric::where('type', 'heart_rate')->first();

        $this->assertEquals('42.0000', (string) $steps->value);
        $this->assertEquals('72.0000', (string) $hr->value);
    }

    /* ------------------------------------------------------------------ */

    protected function seedThreeReadings(string $userId, string $type): void
    {
        $this->seedReadingsWithValues($userId, $type, [100.0, 100.0, 100.0]);
    }

    protected function seedReadingsWithValues(string $userId, string $type, array $values): void
    {
        $unit = MeasurementType::from($type)->canonicalUnit();
        $bucketHour = '2026-07-19 09:00:00';

        foreach ($values as $i => $value) {
            RawMeasurement::create([
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => "{$type}-{$i}",
                'type' => $type,
                'value' => $value,
                'unit' => $unit,
                'recorded_at' => sprintf('2026-07-19 09:%02d:00 UTC', $i * 10),
                'bucket_hour' => $bucketHour,
            ]);
        }

        MeasurementRollupQueue::updateOrCreate(
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => $type,
                'unit' => $unit,
                'bucket_hour' => $bucketHour,
            ],
            [],
        );
    }
}
