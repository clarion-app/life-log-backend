<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use ClarionApp\LifeLogBackend\Vocabulary\Vocabulary;

/**
 * Freezes the vocabulary's storage names.
 *
 * The string value of every case is the permanent storage name: it is written
 * into life_log_raw_measurements.type, life_log_health_metrics.type,
 * life_log_measurement_type_classifications.type and
 * life_log_raw_health_sessions.session_type. Rows already written carry the old
 * name forever, so renaming a case — or removing one, or reusing a retired
 * string for a different meaning — silently changes what stored history means.
 *
 * Growth is additive only. Adding a case is expected and passes; changing or
 * dropping one must fail here first.
 */
class VocabularyStabilityTest extends TestCase
{
    /**
     * Frozen as released. Never edit an entry — only append.
     */
    private const FROZEN_MEASUREMENT_TYPES = [
        'steps',
        'heart_rate',
        'weight',
        'calories_burned',
        'distance',
        'active_minutes',
    ];

    private const FROZEN_SESSION_TYPES = [
        'sleep',
        'workout',
    ];

    /**
     * Canonical units are equally frozen: the unit is not stored beside every
     * value, so changing one reinterprets every row already written under it.
     */
    private const FROZEN_CANONICAL_UNITS = [
        'steps'           => 'count',
        'heart_rate'      => 'bpm',
        'weight'          => 'kg',
        'calories_burned' => 'kcal',
        'distance'        => 'm',
        'active_minutes'  => 's',
    ];

    private const FROZEN_SUMMARY_VALUES = [
        'sleep'   => ['duration' => 's', 'asleep_duration' => 's'],
        'workout' => ['duration' => 's', 'distance' => 'm', 'energy' => 'kcal'],
    ];

    private const CORRUPTION_NOTICE = 'Vocabulary names are permanent storage names. '
        . 'Rows already written carry the old name forever, so renaming or removing a case '
        . 'would silently change the meaning of stored history. Append new cases instead.';

    /** @test */
    public function everyFrozenMeasurementTypeStillExists(): void
    {
        $live = array_map(fn (MeasurementType $t) => $t->value, MeasurementType::cases());

        foreach (self::FROZEN_MEASUREMENT_TYPES as $frozen) {
            $this->assertContains(
                $frozen,
                $live,
                "MeasurementType '{$frozen}' was renamed or removed. " . self::CORRUPTION_NOTICE,
            );
        }
    }

    /** @test */
    public function everyFrozenSessionTypeStillExists(): void
    {
        $live = array_map(fn (SessionType $t) => $t->value, SessionType::cases());

        foreach (self::FROZEN_SESSION_TYPES as $frozen) {
            $this->assertContains(
                $frozen,
                $live,
                "SessionType '{$frozen}' was renamed or removed. " . self::CORRUPTION_NOTICE,
            );
        }
    }

    /** @test  additive growth is legal — new cases do not fail this test */
    public function growthIsAdditiveOnly(): void
    {
        $live = array_map(fn (MeasurementType $t) => $t->value, MeasurementType::cases());

        $this->assertGreaterThanOrEqual(count(self::FROZEN_MEASUREMENT_TYPES), count($live));
        $this->assertSame(
            self::FROZEN_MEASUREMENT_TYPES,
            array_slice($live, 0, count(self::FROZEN_MEASUREMENT_TYPES)),
            'Released cases must keep their order and values; append new ones after them. '
            . self::CORRUPTION_NOTICE,
        );
    }

    /** @test */
    public function canonicalUnitsAreFrozen(): void
    {
        foreach (self::FROZEN_CANONICAL_UNITS as $type => $unit) {
            $this->assertSame(
                $unit,
                MeasurementType::from($type)->canonicalUnit(),
                "Canonical unit for '{$type}' changed. Stored values were written under the old unit "
                . 'and would be reinterpreted. ' . self::CORRUPTION_NOTICE,
            );
        }
    }

    /** @test */
    public function summaryValuesAreFrozen(): void
    {
        foreach (self::FROZEN_SUMMARY_VALUES as $type => $expected) {
            $this->assertSame(
                $expected,
                SessionType::from($type)->summaryValues(),
                "Summary values for session type '{$type}' changed. " . self::CORRUPTION_NOTICE,
            );
        }
    }

    /** @test  aggregation must resolve to a value the rollup already understands */
    public function everyAggregationIsAKnownClassification(): void
    {
        $known = [
            MeasurementTypeClassification::AGGREGATION_CUMULATIVE,
            MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME,
        ];

        foreach (MeasurementType::cases() as $type) {
            $this->assertContains($type->aggregation(), $known, "Unknown aggregation for {$type->value}");
        }
    }

    /** @test  no case value is reused across the two enums */
    public function caseValuesAreUniqueWithinEachEnum(): void
    {
        $measurements = array_map(fn (MeasurementType $t) => $t->value, MeasurementType::cases());
        $sessions = array_map(fn (SessionType $t) => $t->value, SessionType::cases());

        $this->assertSame($measurements, array_values(array_unique($measurements)));
        $this->assertSame($sessions, array_values(array_unique($sessions)));
    }

    /** @test  the façade enumerates, and only enumerates (FR-005) */
    public function vocabularyFacadeReportsTheFullVocabulary(): void
    {
        $this->assertSame(MeasurementType::cases(), Vocabulary::measurementTypes());
        $this->assertSame(SessionType::cases(), Vocabulary::sessionTypes());

        $all = Vocabulary::all();
        $this->assertArrayHasKey('measurement_types', $all);
        $this->assertArrayHasKey('session_types', $all);
        $this->assertSame(self::FROZEN_MEASUREMENT_TYPES, array_slice(array_keys($all['measurement_types']), 0, 6));
        $this->assertSame('kg', $all['measurement_types']['weight']['canonical_unit']);
        $this->assertSame(self::FROZEN_SUMMARY_VALUES['sleep'], $all['session_types']['sleep']['summary_values']);

        // Enumeration only — no registration or mutation surface (FR-005).
        $methods = get_class_methods(Vocabulary::class);
        sort($methods);
        $this->assertSame(['all', 'measurementTypes', 'sessionTypes'], $methods);
    }
}
