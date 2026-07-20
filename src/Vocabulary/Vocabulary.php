<?php

namespace ClarionApp\LifeLogBackend\Vocabulary;

/**
 * Reports the full vocabulary — measurement types and session types alike.
 *
 * Enumeration only. There is deliberately no registration, extension or
 * mutation surface: the vocabulary is fixed in code at release so two
 * replicating instances can never disagree about what a stored name means.
 * Adding a type means adding an enum case and shipping it.
 */
final class Vocabulary
{
    /** @return list<MeasurementType> */
    public static function measurementTypes(): array
    {
        return MeasurementType::cases();
    }

    /** @return list<SessionType> */
    public static function sessionTypes(): array
    {
        return SessionType::cases();
    }

    /**
     * The whole vocabulary in a shape suitable for reporting or serialization.
     *
     * @return array{
     *     measurement_types: array<string, array{canonical_unit: string, aggregation: string}>,
     *     session_types: array<string, array{summary_values: array<string, string>}>
     * }
     */
    public static function all(): array
    {
        $measurementTypes = [];

        foreach (self::measurementTypes() as $type) {
            $measurementTypes[$type->value] = [
                'canonical_unit' => $type->canonicalUnit(),
                'aggregation' => $type->aggregation(),
            ];
        }

        $sessionTypes = [];

        foreach (self::sessionTypes() as $type) {
            $sessionTypes[$type->value] = [
                'summary_values' => $type->summaryValues(),
            ];
        }

        return [
            'measurement_types' => $measurementTypes,
            'session_types' => $sessionTypes,
        ];
    }
}
