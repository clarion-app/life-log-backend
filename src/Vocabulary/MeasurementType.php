<?php

namespace ClarionApp\LifeLogBackend\Vocabulary;

use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;

/**
 * The fixed vocabulary of point and cumulative measurement types.
 *
 * The backed value is the permanent storage name — it is written into
 * life_log_raw_measurements.type, life_log_health_metrics.type and
 * life_log_measurement_type_classifications.type. Once released, a case value
 * is never renamed and never reused for a different meaning: rows already
 * written keep the old name forever, so a rename would silently reinterpret
 * stored history. Growth is additive only.
 *
 * Retiring a type means adding the replacement as a new case and leaving the
 * retired value readable. There is no runtime deprecation mechanism — the
 * vocabulary is fixed at release so two replicating instances cannot disagree.
 */
enum MeasurementType: string
{
    case Steps          = 'steps';
    case HeartRate      = 'heart_rate';
    case Weight         = 'weight';
    case CaloriesBurned = 'calories_burned';
    case Distance       = 'distance';
    case ActiveMinutes  = 'active_minutes';

    /**
     * The single unit every value of this type is stored in.
     *
     * The unit is not carried beside each stored value on the permanent side,
     * so this is as permanent as the case name itself.
     */
    public function canonicalUnit(): string
    {
        return match ($this) {
            self::Steps          => 'count',
            self::HeartRate      => 'bpm',
            self::Weight         => 'kg',
            self::CaloriesBurned => 'kcal',
            self::Distance       => 'm',
            self::ActiveMinutes  => 's',
        };
    }

    /**
     * How the hourly rollup combines readings of this type.
     *
     * Returns one of the MeasurementTypeClassification::AGGREGATION_* values —
     * the same strings the classification table stores and the rollup reads.
     */
    public function aggregation(): string
    {
        return match ($this) {
            self::Steps,
            self::CaloriesBurned,
            self::Distance,
            self::ActiveMinutes  => MeasurementTypeClassification::AGGREGATION_CUMULATIVE,

            self::HeartRate,
            self::Weight         => MeasurementTypeClassification::AGGREGATION_POINT_IN_TIME,
        };
    }
}
