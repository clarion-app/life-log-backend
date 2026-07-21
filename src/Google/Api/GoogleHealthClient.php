<?php

namespace ClarionApp\LifeLogBackend\Google\Api;

use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Google\Mapping\AggregateSource;
use ClarionApp\LifeLogBackend\Google\Mapping\DataTypeMap;
use ClarionApp\LifeLogBackend\Google\Paging\GoogleCursor;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;

/**
 * THE ONLY PLACE a URL is constructed for the Google Health API.
 *
 * Reads the version pin from ApiVersion and honours AggregateSource selection.
 * Every HTTP call goes through this client; nothing else in the package
 * constructs a Google URL.
 *
 * Supports:
 *  - Intraday list endpoint (LocalRollup path)
 *  - RollUp endpoint with windowSize=3600 (ProviderRollUp path)
 *  - Session endpoints (sleep, exercise)
 *  - Page-token paging
 */
final class GoogleHealthClient
{
    /** Page size cap — Google allows up to 10,000 data points per page. */
    private const PAGE_SIZE = 10000;

    public function __construct(
        private Client $http,
        private string $accessToken,
        private AggregateSource $aggregateSource = AggregateSource::LocalRollup,
    ) {
    }

    /**
     * Fetch intraday measurements for a given type.
     *
     * @return array{dataPoints: list<array<string, mixed>>, nextPageToken: string|null}
     *
     * @throws HealthServiceFailure
     */
    public function fetchMeasurements(
        string $userId,
        MeasurementType $type,
        CarbonImmutable $since,
        CarbonImmutable $until,
        ?string $pageToken = null,
    ): array {
        $googleType = $this->measurementGoogleType($type);

        if ($this->aggregateSource->usesProviderAggregation()) {
            return $this->fetchRollUp($userId, $googleType, $since, $until, $pageToken);
        }

        return $this->fetchList($userId, $googleType, $since, $until, $pageToken);
    }

    /**
     * Fetch session data for a given session type.
     *
     * @return array{sessions: list<array<string, mixed>>, nextPageToken: string|null}
     *
     * @throws HealthServiceFailure
     */
    public function fetchSessions(
        string $userId,
        SessionType $type,
        CarbonImmutable $since,
        CarbonImmutable $until,
        ?string $pageToken = null,
    ): array {
        $endpoint = match ($type) {
            SessionType::Sleep   => 'sleepSessions',
            SessionType::Workout => 'exerciseSessions',
        };

        $url = sprintf(
            '%s/%s/users/%s/%s:readList',
            ApiVersion::BASE_URL,
            ApiVersion::VERSION,
            $userId,
            $endpoint,
        );

        $params = [
            'startTime' => $since->getTimestampMs(),
            'endTime'   => $until->getTimestampMs(),
            'pageSize'  => self::PAGE_SIZE,
        ];

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        $result = $this->request($url, $params);

        // request() names its payload 'dataPoints' for every endpoint; sessions
        // are handed on under the key SessionTranslator's caller reads.
        return [
            'sessions'      => $result['dataPoints'],
            'nextPageToken' => $result['nextPageToken'],
        ];
    }

    /**
     * Intraday list endpoint: raw data points.
     *
     * @return array{dataPoints: list<array<string, mixed>>, nextPageToken: string|null}
     */
    private function fetchList(
        string $userId,
        string $googleType,
        CarbonImmutable $since,
        CarbonImmutable $until,
        ?string $pageToken,
    ): array {
        $url = sprintf(
            '%s/%s/users/%s/dataTypes/%s:readList',
            ApiVersion::BASE_URL,
            ApiVersion::VERSION,
            $userId,
            $googleType,
        );

        $params = [
            'startTime' => $since->getTimestampMs(),
            'endTime'   => $until->getTimestampMs(),
            'pageSize'  => self::PAGE_SIZE,
        ];

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        return $this->request($url, $params);
    }

    /**
     * Provider rollUp endpoint: hourly aggregates.
     *
     * @return array{dataPoints: list<array<string, mixed>>, nextPageToken: string|null}
     */
    private function fetchRollUp(
        string $userId,
        string $googleType,
        CarbonImmutable $since,
        CarbonImmutable $until,
        ?string $pageToken,
    ): array {
        $url = sprintf(
            '%s/%s/users/%s/dataTypes/%s:rollUp',
            ApiVersion::BASE_URL,
            ApiVersion::VERSION,
            $userId,
            $googleType,
        );

        $params = [
            'startTime'  => $since->getTimestampMs(),
            'endTime'    => $until->getTimestampMs(),
            'windowSize' => 3600,
            'pageSize'   => self::PAGE_SIZE,
        ];

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        return $this->request($url, $params);
    }

    /**
     * Make an authenticated request to the Google Health API.
     *
     * @param  array<string, mixed>  $params  Query parameters
     * @return array{dataPoints: list<array<string, mixed>>|list<array<string, mixed>>, nextPageToken: string|null}
     *
     * @throws HealthServiceFailure
     */
    private function request(string $url, array $params): array
    {
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        try {
            $response = $this->http->get($fullUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Accept'        => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!is_array($body)) {
                throw HealthServiceFailure::serviceUnavailable(
                    'Google API returned malformed JSON response.'
                );
            }

            // Extract data points and page token from response
            $dataPoints = $body['dataset'][0]['point'] ?? $body['datasets'][0]['point'] ?? [];
            $nextPageToken = $body['nextPageToken'] ?? null;

            // For session endpoints, the structure may differ
            if (empty($dataPoints) && isset($body['sleepSessions'])) {
                $dataPoints = $body['sleepSessions'];
            }

            if (empty($dataPoints) && isset($body['exerciseSessions'])) {
                $dataPoints = $body['exerciseSessions'];
            }

            return [
                'dataPoints'    => is_array($dataPoints) ? $dataPoints : [],
                'nextPageToken' => $nextPageToken,
            ];
        } catch (RequestException $e) {
            $failure = GoogleErrorMapper::map($e);

            if ($failure !== null) {
                throw $failure;
            }

            // null means ACCESS_TOKEN_SCOPE_INSUFFICIENT — skip signal
            // The service layer catches this to skip the type without failing.
            throw new ScopeInsufficientException($url);
        }
    }

    /**
     * Map a MeasurementType to its Google data type identifier.
     */
    private function measurementGoogleType(MeasurementType $type): string
    {
        $map = [
            MeasurementType::Steps->value          => 'stepCount',
            MeasurementType::HeartRate->value      => 'heartRateBpm',
            MeasurementType::Weight->value         => 'weight',
            MeasurementType::CaloriesBurned->value => 'caloriesBurned',
            MeasurementType::Distance->value       => 'distance',
            MeasurementType::ActiveMinutes->value  => 'activeMinutes',
        ];

        $googleType = $map[$type->value] ?? null;

        if ($googleType === null) {
            throw new InvalidArgumentException(
                "MeasurementType '{$type->value}' has no Google data type mapping."
            );
        }

        return $googleType;
    }
}
