<?php

namespace ClarionApp\LifeLogBackend\Google\Mapping;

use ClarionApp\LifeLogBackend\External\TranslatedSession;
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use ClarionApp\LifeLogBackend\Support\Decimal4;
use ClarionApp\LifeLogBackend\Support\RecordedAtValidator;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Translates a Google Health API session page into TranslatedSession objects.
 *
 * For each session in the page:
 *  1. Map the Google session type to a SessionType.
 *  2. Validate start/end timestamps.
 *  3. Convert summary values to canonical units.
 *  4. Keep the provider-supplied session id as the external id.
 *
 * Sessions keep their provider-supplied session id — identified by identity,
 * not by instant.
 */
final class SessionTranslator
{
    private const SERVICE = 'google-health';

    /**
     * Google session type → vocabulary session type.
     *
     * @var array<string, SessionType>
     */
    private const SESSION_MAP = [
        'sleepSession'    => SessionType::Sleep,
        'exerciseSession' => SessionType::Workout,
    ];

    /**
     * Translate a page of Google session data.
     *
     * @param  list<array<string, mixed>>  $sessions  Raw session objects from Google API
     * @param  string  $userId  The user id to attribute to
     * @return list<TranslatedSession>
     */
    public function translate(
        array $sessions,
        string $userId,
        UnmappedTypeRecorder $unmappedRecorder,
    ): array {
        $results = [];
        $validator = new RecordedAtValidator();

        foreach ($sessions as $session) {
            try {
                $translated = $this->translateOne(
                    $session,
                    $userId,
                    $validator,
                    $unmappedRecorder,
                );

                if ($translated !== null) {
                    $results[] = $translated;
                }
            } catch (\Throwable $e) {
                Log::warning(
                    "Skipping Google session: {$e->getMessage()}"
                );
            }
        }

        return $results;
    }

    /**
     * Translate a single session, or null if it should be skipped.
     */
    private function translateOne(
        array $session,
        string $userId,
        RecordedAtValidator $validator,
        UnmappedTypeRecorder $unmappedRecorder,
    ): ?TranslatedSession {
        $dataType = $session['dataType'] ?? $session['data_type'] ?? null;

        if ($dataType === null || !is_string($dataType)) {
            return null;
        }

        // Step 1: Map Google session type to vocabulary type
        $vocabularyType = self::SESSION_MAP[$dataType] ?? null;

        if ($vocabularyType === null) {
            // Check if it's a measurement type (wrong translator) or truly unmapped
            $maybeMeasurement = DataTypeMap::resolve($dataType);

            if ($maybeMeasurement === null) {
                $unmappedRecorder->record(
                    self::SERVICE,
                    $dataType,
                );
            }

            return null;
        }

        // Step 2: Validate timestamps
        $startedAt = null;
        $endedAt = null;

        try {
            $startMs = $session['startedAtMs'] ?? $session['start_time_ms'] ?? null;
            $endMs = $session['endedAtMs'] ?? $session['end_time_ms'] ?? null;

            if ($startMs === null || $endMs === null) {
                $unmappedRecorder->record(
                    self::SERVICE,
                    $dataType,
                    null,
                    null,
                );

                return null;
            }

            $startEpoch = is_numeric($startMs) ? (int) $startMs : 0;
            $endEpoch = is_numeric($endMs) ? (int) $endMs : 0;

            $startedAt = CarbonImmutable::createFromTimestampUTC($startEpoch / 1000.0);
            $endedAt = CarbonImmutable::createFromTimestampUTC($endEpoch / 1000.0);

            $validator->assertPlausible($startedAt);
            $validator->assertPlausible($endedAt);
        } catch (\Throwable) {
            $unmappedRecorder->record(
                self::SERVICE,
                $dataType,
            );

            return null;
        }

        // Step 3: Convert summary values to canonical units
        $summaryValues = $this->convertSummaryValues(
            $session,
            $vocabularyType,
            $unmappedRecorder,
            $dataType,
        );

        // Step 4: External id — keep provider-supplied session id
        $externalId = $session['id'] ?? $session['session_id'] ?? null;

        if ($externalId === null || !is_string($externalId) || $externalId === '') {
            // Fallback: generate from type and start time
            $externalId = "session:{$startEpoch}";
        }

        return new TranslatedSession(
            userId: $userId,
            type: $vocabularyType,
            startedAt: $startedAt,
            endedAt: $endedAt,
            summaryValues: $summaryValues,
            externalId: $externalId,
            externalService: self::SERVICE,
        );
    }

    /**
     * Convert session summary values to canonical units.
     *
     * @param  array<string, mixed>  $session  Raw session data
     * @return array<string, string>  key → 4dp string in canonical unit
     */
    private function convertSummaryValues(
        array $session,
        SessionType $vocabularyType,
        UnmappedTypeRecorder $unmappedRecorder,
        string $dataType,
    ): array {
        $summaryValues = [];
        $declared = $vocabularyType->summaryValues();

        // Google may nest summary values in different places
        $rawSummary = $session['summaryValues'] ?? $session['summary'] ?? $session['metrics'] ?? [];

        if (!is_array($rawSummary)) {
            return $summaryValues;
        }

        // Map Google summary field names to vocabulary keys
        $googleFieldMap = [
            'duration'           => 'duration',
            'durationMs'         => 'duration',
            'asleepDuration'     => 'asleep_duration',
            'asleepDurationMs'   => 'asleep_duration',
            'distance'           => 'distance',
            'distanceMeters'     => 'distance',
            'energy'             => 'energy',
            'energyKcal'         => 'energy',
            'calories'           => 'energy',
            'caloriesBurned'     => 'energy',
        ];

        foreach ($rawSummary as $gKey => $gValue) {
            $vocabKey = $googleFieldMap[$gKey] ?? null;

            if ($vocabKey === null || !array_key_exists($vocabKey, $declared)) {
                continue;
            }

            // Convert milliseconds to seconds for duration fields
            $value = $gValue;
            if (str_ends_with((string) $gKey, 'Ms') && $declared[$vocabKey] === 's') {
                $value = is_numeric($gValue) ? (int) $gValue / 1000 : 0;
            }

            // Normalize to 4dp string
            $summaryValues[$vocabKey] = Decimal4::round((string) $value);
        }

        return $summaryValues;
    }
}
