<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;

class VocabularySyncTest extends TestCase
{
    private const USER = '00000000-0000-0000-0000-000000000001';

    /** @test  every vocabulary type gets a classification row */
    public function syncProjectsTheWholeVocabulary(): void
    {
        $this->artisan('life-log:sync-vocabulary')->assertExitCode(0);

        $rows = MeasurementTypeClassification::pluck('aggregation', 'type')->all();

        foreach (MeasurementType::cases() as $type) {
            $this->assertArrayHasKey($type->value, $rows);
            $this->assertSame($type->aggregation(), $rows[$type->value]);
        }

        $this->assertSame(count(MeasurementType::cases()), MeasurementTypeClassification::count());
    }

    /** @test  running it twice leaves the table byte-identical */
    public function syncIsIdempotent(): void
    {
        $this->artisan('life-log:sync-vocabulary')->assertExitCode(0);
        $before = $this->snapshot();

        $this->artisan('life-log:sync-vocabulary')->assertExitCode(0);
        $this->artisan('life-log:sync-vocabulary')->assertExitCode(0);

        $this->assertSame($before, $this->snapshot());
    }

    /** @test  pre-seeded rows that already agree are not rewritten */
    public function preSeededRowsAreUntouched(): void
    {
        $seeded = [
            'steps' => MeasurementTypeClassification::AGGREGATION_CUMULATIVE,
            'heart_rate' => MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME,
        ];

        foreach ($seeded as $type => $aggregation) {
            MeasurementTypeClassification::create(['type' => $type, 'aggregation' => $aggregation]);
        }

        $before = MeasurementTypeClassification::whereIn('type', array_keys($seeded))
            ->get(['id', 'type', 'aggregation', 'created_at', 'updated_at'])->toArray();

        $this->artisan('life-log:sync-vocabulary')->assertExitCode(0);

        $after = MeasurementTypeClassification::whereIn('type', array_keys($seeded))
            ->get(['id', 'type', 'aggregation', 'created_at', 'updated_at'])->toArray();

        $this->assertSame($before, $after);
    }

    /** @test  a drifted aggregation is corrected to the vocabulary's */
    public function aDriftedAggregationIsCorrected(): void
    {
        MeasurementTypeClassification::create([
            'type' => 'weight',
            'aggregation' => MeasurementTypeClassification::AGGREGATION_CUMULATIVE,
        ]);

        $this->artisan('life-log:sync-vocabulary')->assertExitCode(0);

        $this->assertSame(
            MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME,
            MeasurementTypeClassification::where('type', 'weight')->value('aggregation'),
        );
    }

    /** @test  free-form manual types stay unclassified and still defer exactly as today */
    public function freeFormTypesAbsentFromTheVocabularyStillDefer(): void
    {
        $this->artisan('life-log:sync-vocabulary')->assertExitCode(0);

        $bucketHour = MeasurementBucket::for('2026-07-19 09:10:00 UTC');

        RawMeasurement::create([
            'user_id' => self::USER,
            'external_service' => 'acme-band',
            'external_id' => 'freeform-1',
            'type' => 'mood_score',
            'value' => '7.0000',
            'unit' => '',
            'recorded_at' => MeasurementBucket::normalize('2026-07-19 09:10:00 UTC'),
            'bucket_hour' => $bucketHour,
        ]);

        MeasurementRollupQueue::create([
            'user_id' => self::USER,
            'external_service' => 'acme-band',
            'type' => 'mood_score',
            'unit' => '',
            'bucket_hour' => $bucketHour,
        ]);

        $result = (new HourlyMeasurementRollup())->run();

        $this->assertSame(0, $result['written']);
        $this->assertSame(1, $result['deferred']);
        $this->assertSame(0, HealthMetric::count());
        $this->assertSame('unclassified_type', MeasurementRollupQueue::first()->deferred_reason);

        // The sync never invents a classification for a type outside the vocabulary.
        $this->assertNull(MeasurementTypeClassification::where('type', 'mood_score')->first());
    }

    /** @return list<array<string,mixed>> */
    private function snapshot(): array
    {
        return MeasurementTypeClassification::orderBy('type')
            ->get(['id', 'type', 'aggregation', 'created_at', 'updated_at'])
            ->toArray();
    }
}
