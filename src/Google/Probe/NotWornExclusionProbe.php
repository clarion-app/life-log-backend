<?php

namespace ClarionApp\LifeLogBackend\Google\Probe;

use ClarionApp\LifeLogBackend\Google\Api\GoogleHealthClient;
use ClarionApp\LifeLogBackend\Google\Mapping\DataTypeMap;
use ClarionApp\LifeLogBackend\Google\Mapping\UnitMap;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;

/**
 * Live comparison: ProviderRollUp vs LocalRollup for one account and type.
 *
 * Fetches `rollUp?windowSize=3600` via GoogleHealthClient (ProviderRollUp path),
 * derives the same hours from the intraday list (LocalRollup path), and returns
 * a per-hour diff.
 *
 * This is the ONLY place `rollUp?windowSize=3600` may be named — it lives in
 * `src/Google/` per the containment rule.
 *
 * @see NotWornExclusionResult
 */
final class NotWornExclusionProbe
{
    public function __construct(
        private GoogleHealthClient $rollUpClient,
        private GoogleHealthClient $listClient,
    ) {
    }

    /**
     * Run the probe for a single type over a time window.
     *
     * @return list<NotWornExclusionResult>
     */
    public function run(
        ConnectedAccount $account,
        MeasurementType $type,
        CarbonImmutable $since,
        CarbonImmutable $until,
    ): array {
        $googleType = DataTypeMap::toGoogleType($type);

        // Fetch via ProviderRollUp path (rollUp?windowSize=3600)
        $rollUpData = $this->fetchPaged(
            $this->rollUpClient,
            $account->external_user_id,
            $type,
            $since,
            $until,
        );

        // Fetch via LocalRollup path (intraday list)
        $listData = $this->fetchPaged(
            $this->listClient,
            $account->external_user_id,
            $type,
            $since,
            $until,
        );

        // Derive per-hour aggregates from both sources
        $rollUpHours = $this->aggregateByHour($rollUpData, $type);
        $listHours = $this->aggregateByHourFromList($listData, $type);

        // Build per-hour diff
        $results = [];
        $allHours = array_unique(array_merge(array_keys($rollUpHours), array_keys($listHours)));
        sort($allHours);

        foreach ($allHours as $hourKey) {
            $rollUpValue = $rollUpHours[$hourKey] ?? null;
            $listValue = $listHours[$hourKey] ?? null;

            $results[] = new NotWornExclusionResult(
                hour: CarbonImmutable::parse($hourKey),
                providerRollUpValue: $rollUpValue,
                localRollUpValue: $listValue,
                match: ($rollUpValue === null && $listValue === null)
                    || ($rollUpValue !== null && $listValue !== null && bccomp((string) $rollUpValue, (string) $listValue, 4) === 0),
            );
        }

        return $results;
    }

    /**
     * Fetch all pages of data for a type.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchPaged(
        GoogleHealthClient $client,
        string $userId,
        MeasurementType $type,
        CarbonImmutable $since,
        CarbonImmutable $until,
    ): array {
        $allDataPoints = [];
        $pageToken = null;

        do {
            $response = $client->fetchMeasurements(
                $userId,
                $type,
                $since,
                $until,
                $pageToken,
            );

            foreach ($response['dataPoints'] as $dp) {
                $allDataPoints[] = $dp;
            }

            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return $allDataPoints;
    }

    /**
     * Aggregate rollUp data by hour (already hourly from the provider).
     *
     * @param  list<array<string, mixed>>  $dataPoints
     * @return array<string, string>  hour key → value string
     */
    private function aggregateByHour(array $dataPoints, MeasurementType $type): array
    {
        $hours = [];

        foreach ($dataPoints as $dp) {
            $startTime = $dp['startTime'] ?? $dp['datapoint'][0]['nanos'] ?? null;
            $value = $dp['dataPoint'][0]['value'][0] ?? null;

            if ($startTime === null || $value === null) {
                continue;
            }

            // startTime is in nanoseconds for rollUp
            $hourKey = CarbonImmutable::createFromTimestampMs(intval($startTime / 1_000_000))
                ->startOfHour()
                ->format('Y-m-d H:00');

            $canonicalValue = UnitMap::toCanonical($type, $value);
            $hours[$hourKey] = $canonicalValue;
        }

        return $hours;
    }

    /**
     * Aggregate intraday list data by hour (local rollup).
     *
     * @param  list<array<string, mixed>>  $dataPoints
     * @return array<string, string>  hour key → value string
     */
    private function aggregateByHourFromList(array $dataPoints, MeasurementType $type): array
    {
        $hourlyBuckets = [];

        foreach ($dataPoints as $dp) {
            $nanos = $dp['nanos'] ?? null;
            $value = $dp['value'][0] ?? null;

            if ($nanos === null || $value === null) {
                continue;
            }

            $hourKey = CarbonImmutable::createFromTimestampMs(intval($nanos / 1_000_000))
                ->startOfHour()
                ->format('Y-m-d H:00');

            $canonicalValue = UnitMap::toCanonical($type, $value);

            if (!isset($hourlyBuckets[$hourKey])) {
                $hourlyBuckets[$hourKey] = [];
            }
            $hourlyBuckets[$hourKey][] = $canonicalValue;
        }

        // Aggregate each hour by the type's rollup rule
        $hours = [];
        foreach ($hourlyBuckets as $hourKey => $values) {
            $hours[$hourKey] = $this->aggregateValues($type, $values);
        }

        return $hours;
    }

    /**
     * Aggregate a list of values for a type.
     *
     * @param  list<string>  $values
     */
    private function aggregateValues(MeasurementType $type, array $values): string
    {
        if (empty($values)) {
            return '0';
        }

        return match ($type) {
            MeasurementType::Steps,
            MeasurementType::CaloriesBurned,
            MeasurementType::Distance,
            MeasurementType::ActiveMinutes => $this->sumValues($values),
            MeasurementType::HeartRate => $this->avgValues($values),
            MeasurementType::Weight => end($values) ?? '0',
        };
    }

    /**
     * Sum an array of bcmath-compatible string values.
     *
     * @param  list<string>  $values
     */
    private function sumValues(array $values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, $v, 4);
        }
        return $sum;
    }

    /**
     * Average an array of bcmath-compatible string values.
     *
     * @param  list<string>  $values
     */
    private function avgValues(array $values): string
    {
        $sum = $this->sumValues($values);
        return bcdiv($sum, (string) count($values), 4);
    }
}
