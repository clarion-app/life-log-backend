<?php

namespace ClarionApp\LifeLogBackend\Google\Mapping;

use ClarionApp\LifeLogBackend\External\TranslatedMeasurement;
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use ClarionApp\LifeLogBackend\Support\RecordedAtValidator;
use ClarionApp\LifeLogBackend\Support\UnitConverter;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Translates a Google Health API measurement page into TranslatedMeasurement objects.
 *
 * For each item in the page:
 *  1. Map the Google data type to a MeasurementType (skip if unmapped).
 *  2. Convert the value to the canonical unit (skip if unconvertible).
 *  3. Validate the timestamp (skip if implausible).
 *  4. Generate the external id as "{shortCode}:{epochSeconds}".
 *
 * Nothing is guessed. An unmapped type or unconvertible unit goes to
 * UnmappedTypeRecorder and is omitted; the rest of the page still returns.
 */
final class MeasurementTranslator
{
    private const SERVICE = 'google-health';

    /**
     * Translate a page of Google measurement data points.
     *
     * @param  list<array<string, mixed>>  $dataPoints  Raw data points from Google API
     * @param  string  $userId  The user id to attribute to
     * @return list<TranslatedMeasurement>
     */
    public function translate(
        array $dataPoints,
        string $userId,
        UnmappedTypeRecorder $unmappedRecorder,
    ): array {
        $results = [];
        $converter = new UnitConverter();
        $validator = new RecordedAtValidator();

        foreach ($dataPoints as $dp) {
            try {
                $translated = $this->translateOne(
                    $dp,
                    $userId,
                    $converter,
                    $validator,
                    $unmappedRecorder,
                );

                if ($translated !== null) {
                    $results[] = $translated;
                }
            } catch (\Throwable $e) {
                // A translation failure for one item must not fail the page.
                Log::warning(
                    "Skipping Google measurement data point: {$e->getMessage()}"
                );
            }
        }

        return $results;
    }

    /**
     * Translate a single data point, or null if it should be skipped.
     */
    private function translateOne(
        array $dp,
        string $userId,
        UnitConverter $converter,
        RecordedAtValidator $validator,
        UnmappedTypeRecorder $unmappedRecorder,
    ): ?TranslatedMeasurement {
        $dataType = $dp['dataType'] ?? $dp['data_type'] ?? null;

        if ($dataType === null || !is_string($dataType)) {
            return null;
        }

        // Step 1: Map Google data type to vocabulary type
        $vocabularyType = DataTypeMap::resolve($dataType);

        if ($vocabularyType === null || !$vocabularyType instanceof MeasurementType) {
            // Session types or unmapped types — skip silently for measurement translator
            if ($vocabularyType === null) {
                $unmappedRecorder->record(
                    self::SERVICE,
                    $dataType,
                    $dp['value'] ?? null,
                    $dp['unit'] ?? null,
                );
            }

            return null;
        }

        // Step 2: Convert value to canonical unit
        $rawValue = self::scalarValue($dp['value'] ?? null);
        $sourceUnit = $dp['unit'] ?? null;

        if ($rawValue === null || !is_string($sourceUnit)) {
            $unmappedRecorder->record(
                self::SERVICE,
                $dataType,
                is_scalar($rawValue) ? (string) $rawValue : null,
                is_string($sourceUnit) ? $sourceUnit : null,
            );

            return null;
        }

        // The provider's spelling of the unit, resolved onto one this
        // integration accepts. An unrecognised pairing is recorded and
        // skipped rather than guessed at (FR-011).
        $normalisedUnit = UnitMap::normaliseSource($dataType, $sourceUnit);

        if ($normalisedUnit === null) {
            $unmappedRecorder->record(self::SERVICE, $dataType, (string) $rawValue, $sourceUnit);

            return null;
        }

        try {
            $value = $converter->toCanonical(
                (string) $rawValue,
                $normalisedUnit,
                $vocabularyType,
            );
        } catch (\Throwable) {
            $unmappedRecorder->record(
                self::SERVICE,
                $dataType,
                (string) $rawValue,
                $sourceUnit,
            );

            return null;
        }

        // Step 3: Validate timestamp. A point is stamped at its start — the
        // instant the external id is derived from, so this choice is what
        // makes a corrected reading collide with the row it corrects.
        $timestampMs = $dp['startedAtMs']
            ?? $dp['timestampMs']
            ?? $dp['timestamp']
            ?? null;

        if ($timestampMs === null) {
            $unmappedRecorder->record(
                self::SERVICE,
                $dataType,
                (string) $rawValue,
                $sourceUnit,
            );

            return null;
        }

        $recordedAt = null;

        try {
            // Google timestamps are in milliseconds since epoch
            $epochMs = is_numeric($timestampMs) ? (int) $timestampMs : 0;
            $recordedAt = CarbonImmutable::createFromTimestampUTC($epochMs / 1000.0);
            $validator->assertPlausible($recordedAt);
        } catch (\Throwable) {
            $unmappedRecorder->record(
                self::SERVICE,
                $dataType,
                (string) $rawValue,
                $sourceUnit,
            );

            return null;
        }

        // Step 4: Generate external id
        $shortCode = DataTypeMap::shortCode($dataType);
        $epochSeconds = (int) ($epochMs / 1000);
        $externalId = $shortCode ? "{$shortCode}:{$epochSeconds}" : "unknown:{$epochSeconds}";

        return new TranslatedMeasurement(
            userId: $userId,
            type: $vocabularyType,
            value: $value,
            unit: $vocabularyType->canonicalUnit(),
            recordedAt: $recordedAt,
            externalId: $externalId,
            externalService: self::SERVICE,
        );
    }

    /**
     * The scalar reading out of a point's `value`.
     *
     * Google wraps a point's value in a list of typed cells (`intVal`,
     * `fpVal`) rather than serving a bare number. Casting that list to string
     * yields "Array", which fails conversion and drops the reading — so the
     * unwrapping happens here, once, instead of in every caller.
     *
     * @return scalar|null null when there is no usable reading
     */
    private static function scalarValue(mixed $raw): string|int|float|bool|null
    {
        if (is_scalar($raw)) {
            return $raw;
        }

        if (!is_array($raw)) {
            return null;
        }

        $cell = $raw[0] ?? $raw;

        if (is_scalar($cell)) {
            return $cell;
        }

        if (!is_array($cell)) {
            return null;
        }

        foreach (['intVal', 'fpVal', 'value'] as $key) {
            if (isset($cell[$key]) && is_scalar($cell[$key])) {
                return $cell[$key];
            }
        }

        return null;
    }
}
