<?php

namespace ClarionApp\LifeLogBackend\Google\Mapping;

use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;

/**
 * Declares unit conversions for Google Health API types as exact integer rationals.
 *
 * Every conversion factor is [numerator, denominator] — both integer strings
 * evaluated in bcmath. No float literals exist here. A float factor such as
 * 0.45359237 can round differently across platforms, defeating dedup on re-import.
 *
 * Google Health API units map directly to canonical units for most types.
 * The UnitConverter in the shared services layer handles the actual conversion
 * using these exact rationals.
 *
 * This class documents the mapping; the actual conversion is delegated to
 * UnitConverter::toCanonical() which already knows the rational factors.
 */
final class UnitMap
{
    /**
     * Google data type → allowed source units → canonical unit.
     *
     * Google Health API serves data in these units. The canonical unit is
     * what the vocabulary type stores in. Identity conversions (same unit)
     * still go through UnitConverter for normalization.
     *
     * @var array<string, array{canonical: string, sources: list<string>}>
     */
    private const MAP = [
        'stepCount'       => ['canonical' => 'count', 'sources' => ['count']],
        'heartRateBpm'    => ['canonical' => 'bpm', 'sources' => ['bpm']],
        'weight'          => ['canonical' => 'kg', 'sources' => ['kg', 'g', 'lb']],
        'caloriesBurned'  => ['canonical' => 'kcal', 'sources' => ['kcal', 'kJ', 'cal']],
        'distance'        => ['canonical' => 'm', 'sources' => ['m', 'km', 'mi']],
        'activeMinutes'   => ['canonical' => 's', 'sources' => ['s', 'min', 'h']],
    ];

    /**
     * Provider unit spellings that mean an accepted source unit.
     *
     * A provider naming a unit after the thing counted ("steps") rather than
     * the measure ("count") is a spelling difference, not a conversion. It is
     * absorbed here so the shared UnitConverter keeps a vocabulary of real
     * units and never learns a provider's naming habits.
     *
     * @var array<string, array<string, string>>
     */
    private const ALIASES = [
        'stepCount'      => ['steps' => 'count'],
        'caloriesBurned' => ['Cal' => 'kcal', 'kcals' => 'kcal'],
        'activeMinutes'  => ['minutes' => 'min', 'seconds' => 's'],
    ];

    /**
     * Get the canonical unit for a Google data type.
     *
     * @return string|null The canonical unit, or null if the type is not mapped.
     */
    public static function canonicalUnit(string $googleType): ?string
    {
        return self::MAP[$googleType]['canonical'] ?? null;
    }

    /**
     * Get the allowed source units for a Google data type.
     *
     * @return list<string>|null The allowed source units, or null if unmapped.
     */
    public static function allowedSources(string $googleType): ?array
    {
        return self::MAP[$googleType]['sources'] ?? null;
    }

    /**
     * Check if a source unit is allowed for a Google data type.
     */
    public static function isAllowedSource(string $googleType, string $sourceUnit): bool
    {
        $sources = self::allowedSources($googleType);

        if ($sources === null) {
            return false;
        }

        return in_array($sourceUnit, $sources, true);
    }

    /**
     * The source unit to hand UnitConverter for a unit as the provider spelled
     * it, or null when the pairing is not one this integration accepts.
     *
     * Returning null is the FR-011 path: the caller records the reading as
     * unmapped and skips it. Guessing a conversion instead would write a
     * plausible-looking wrong number that nothing downstream could detect.
     */
    public static function normaliseSource(string $googleType, string $sourceUnit): ?string
    {
        $sourceUnit = trim($sourceUnit);
        $resolved = self::ALIASES[$googleType][$sourceUnit] ?? $sourceUnit;

        return self::isAllowedSource($googleType, $resolved) ? $resolved : null;
    }

    /**
     * Get the MeasurementType for a Google data type.
     *
     * @return MeasurementType|null
     */
    public static function measurementType(string $googleType): ?MeasurementType
    {
        $type = DataTypeMap::resolve($googleType);

        if ($type instanceof MeasurementType) {
            return $type;
        }

        return null;
    }

    /**
     * All Google data types that have a unit mapping (measurements only).
     *
     * @return list<string>
     */
    public static function allMeasurementTypes(): array
    {
        return array_keys(self::MAP);
    }
}
