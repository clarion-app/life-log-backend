<?php

namespace ClarionApp\LifeLogBackend\Google\Mapping;

use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;

/**
 * Maps Google Health API data type identifiers to the canonical vocabulary.
 *
 * Every Google type that has a vocabulary equivalent is declared here.
 * Anything absent is unmapped — the caller skips it and records the sighting
 * via UnmappedTypeRecorder (FR-011). Nothing is guessed.
 *
 * The map is total for the six types GoogleHealthService advertises:
 * steps, heart_rate, weight, calories_burned, workout, sleep.
 */
final class DataTypeMap
{
    /**
     * Google data type → canonical vocabulary type.
     *
     * @var array<string, MeasurementType|SessionType>
     */
    private const MAP = [
        // Activity and Fitness bundle
        'stepCount'            => MeasurementType::Steps,
        'heartRateBpm'        => MeasurementType::HeartRate,
        'caloriesBurned'      => MeasurementType::CaloriesBurned,
        'activeMinutes'       => MeasurementType::ActiveMinutes,
        'distance'            => MeasurementType::Distance,

        // Health Metrics bundle
        'weight'              => MeasurementType::Weight,

        // Sleep bundle
        'sleepSession'        => SessionType::Sleep,

        // Exercise sessions (from ActivityAndFitness scope)
        'exerciseSession'     => SessionType::Workout,
    ];

    /**
     * Short code for external id generation.
     *
     * @var array<string, string>
     */
    private const SHORT_CODE = [
        'stepCount'       => 'steps',
        'heartRateBpm'    => 'hr',
        'caloriesBurned'  => 'cal',
        'activeMinutes'   => 'actmin',
        'distance'        => 'dist',
        'weight'          => 'wt',
        'sleepSession'    => 'sleep',
        'exerciseSession' => 'workout',
    ];

    /**
     * Resolve a Google data type to a vocabulary type, or null if unmapped.
     *
     * @return MeasurementType|SessionType|null
     */
    public static function resolve(string $googleType): MeasurementType|SessionType|null
    {
        return self::MAP[$googleType] ?? null;
    }

    /**
     * The short code for external id generation.
     *
     * Returns null if the type is not in the map.
     */
    public static function shortCode(string $googleType): ?string
    {
        return self::SHORT_CODE[$googleType] ?? null;
    }

    /**
     * All Google types that have a vocabulary mapping.
     *
     * @return list<string>
     */
    public static function allGoogleTypes(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Check whether a Google type is mapped.
     */
    public static function has(string $googleType): bool
    {
        return array_key_exists($googleType, self::MAP);
    }
}
