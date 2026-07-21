<?php

namespace ClarionApp\LifeLogBackend\Google;

use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\Google\Api\ApiVersion;
use ClarionApp\LifeLogBackend\Google\Api\GoogleHealthClient;
use ClarionApp\LifeLogBackend\Google\Api\ScopeInsufficientException;
use ClarionApp\LifeLogBackend\Google\Mapping\AggregateSource;
use ClarionApp\LifeLogBackend\Google\Mapping\MeasurementTranslator;
use ClarionApp\LifeLogBackend\Google\Mapping\SessionTranslator;
use ClarionApp\LifeLogBackend\Google\Oauth\GoogleOauthFlow;
use ClarionApp\LifeLogBackend\Google\Oauth\ScopeBundle;
use ClarionApp\LifeLogBackend\Google\Paging\GoogleCursor;
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Google Health API implementation of ExternalHealthService.
 *
 * Supports six measurement/session types across three scope bundles.
 * Uses per-type window sizing: 14 days for heart rate, 90 days for everything else.
 *
 * completeConnection() takes no budget reservation — a denial would break
 * a consent flow the user is standing in front of.
 */
final class GoogleHealthService implements ExternalHealthService
{
    public const NAME = 'google-health';

    /**
     * @var list<MeasurementType|SessionType>
     */
    private array $supported;

    public function __construct(
        private GoogleOauthFlow $oauthFlow,
        private ?Client $httpClient = null,
        private ?UnmappedTypeRecorder $unmappedRecorder = null,
    ) {
        $this->supported = ScopeBundle::typesFor(
            ScopeBundle::all(),
            $this->typeList(),
        );

        if ($this->unmappedRecorder === null) {
            $this->unmappedRecorder = app(UnmappedTypeRecorder::class);
        }
    }

    public function name(): string
    {
        return self::NAME;
    }

    /** @return list<MeasurementType|SessionType> */
    public function supportedTypes(): array
    {
        return $this->supported;
    }

    /**
     * @param  MeasurementType|SessionType  $type
     */
    public function maxWindow(MeasurementType|SessionType $type): ?\DateInterval
    {
        // 14 days for heart rate; 90 days for everything else
        if ($type === MeasurementType::HeartRate) {
            return new \DateInterval('P14D');
        }
        return new \DateInterval('P90D');
    }

    public function beginConnection(string $userId): ConnectionResult
    {
        // The redirect URI comes from the credential stored for this service.
        // We need to read it here to pass to the OAuth flow.
        $credential = app(\ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider::class)
            ->require(self::NAME);

        $result = $this->oauthFlow->authorizeUrl($userId, $credential->redirect_uri);

        return new ConnectionResult(
            externalService: self::NAME,
            authorizationUrl: $result['url'],
            state: $result['state'],
        );
    }

    /**
     * @param  list<MeasurementType|SessionType>  $types  Types to fetch; required.
     *
     * @throws HealthServiceFailure
     */
    public function fetch(
        string $userId,
        CarbonImmutable $since,
        CarbonImmutable $until,
        ?PageCursor $cursor = null,
        ?array $types = null,
    ): ResultPage {
        // Obligation 9: Honour $types exactly — no extra types returned
        if ($types === null || empty($types)) {
            $types = $this->supported;
        }

        $measurementTypes = [];
        $sessionTypes = [];

        foreach ($types as $type) {
            if ($type instanceof MeasurementType) {
                $measurementTypes[] = $type;
            } elseif ($type instanceof SessionType) {
                $sessionTypes[] = $type;
            }
        }

        // Restore GoogleCursor from PageCursor if present
        $googleCursor = null;

        if ($cursor !== null) {
            $googleCursor = GoogleCursor::fromPageCursor($cursor);
        }

        $accessToken = $this->oauthFlow->getAccessToken($userId);

        $client = new GoogleHealthClient(
            $this->httpClient ?? new Client(),
            $accessToken,
            AggregateSource::fromConfig(),
        );

        $translator = new MeasurementTranslator();
        $sessionTranslator = new SessionTranslator();

        $allMeasurements = [];
        $allSessions = [];
        $lastPageToken = null;

        // Fetch measurements
        foreach ($measurementTypes as $type) {
            $pageToken = null;
            $done = false;

            // Use cursor's page token if it matches this type
            if ($googleCursor !== null && $googleCursor->matches($type, $since, $until)) {
                $pageToken = $googleCursor->pageToken();
            }

            while ($done === false) {
                try {
                    $result = $client->fetchMeasurements(
                        $userId,
                        $type,
                        $since,
                        $until,
                        $pageToken,
                    );
                } catch (ScopeInsufficientException) {
                    // This measurement type is not covered by the current
                    // OAuth scope grant. Skip it — the sync stays healthy.
                    $done = true;
                    continue;
                }

                $translated = $translator->translate(
                    $result['dataPoints'],
                    $userId,
                    $this->unmappedRecorder,
                );

                $allMeasurements = array_merge($allMeasurements, $translated);

                if ($result['nextPageToken'] === null) {
                    $done = true;
                } else {
                    $pageToken = $result['nextPageToken'];
                    $lastPageToken = $pageToken;
                }
            }
        }

        // Fetch sessions
        foreach ($sessionTypes as $type) {
            $pageToken = null;
            $done = false;

            if ($googleCursor !== null && $googleCursor->matches($type, $since, $until)) {
                $pageToken = $googleCursor->pageToken();
            }

            while ($done === false) {
                try {
                    $result = $client->fetchSessions(
                        $userId,
                        $type,
                        $since,
                        $until,
                        $pageToken,
                    );
                } catch (ScopeInsufficientException) {
                    // This session type is not covered by the current
                    // OAuth scope grant. Skip it — the sync stays healthy.
                    $done = true;
                    continue;
                }

                $translated = $sessionTranslator->translate(
                    $result['sessions'],
                    $userId,
                    $this->unmappedRecorder,
                );

                $allSessions = array_merge($allSessions, $translated);

                if ($result['nextPageToken'] === null) {
                    $done = true;
                } else {
                    $pageToken = $result['nextPageToken'];
                    $lastPageToken = $pageToken;
                }
            }
        }

        // nextCursor is null only at true exhaustion (no more data points)
        $nextCursor = null;

        if ($lastPageToken !== null) {
            // Use the last measurement type for cursor context
            $lastType = end($measurementTypes) ?? (end($sessionTypes) ?? null);

            if ($lastType !== null) {
                $nextCursor = GoogleCursor::create(
                    $lastPageToken,
                    $lastType,
                    $since,
                    $until,
                )->toPageCursor();
            }
        }

        return new ResultPage(
            measurements: $allMeasurements,
            sessions: $allSessions,
            nextCursor: $nextCursor,
        );
    }

    public function renewAccess(string $userId): RenewalResult
    {
        try {
            $tokens = $this->oauthFlow->refreshToken($userId);

            $expiresAt = null;
            if (isset($tokens['expires_in'])) {
                $expiresAt = CarbonImmutable::now()->addSeconds($tokens['expires_in']);
            }

            return RenewalResult::renewed($expiresAt);
        } catch (HealthServiceFailure $e) {
            // invalid_grant from the token endpoint means the grant can no
            // longer be renewed (user revoked, testing expiry, project
            // deleted). Return a declined RenewalResult — the runner then
            // routes to AccessRevoked ⇒ needs_attention.
            if ($e->kind === FailureKind::AccessRevoked) {
                return RenewalResult::declined();
            }

            // CredentialsRejected or ServiceUnavailable during refresh —
            // also decline; retrying the same token won't help.
            if ($e->kind === FailureKind::CredentialsRejected ||
                $e->kind === FailureKind::ServiceUnavailable) {
                return RenewalResult::declined();
            }

            // For any other failure kind, re-throw — it is a programming error.
            throw $e;
        }
    }

    public function disconnect(string $userId): DisconnectResult
    {
        $accessToken = $this->oauthFlow->getAccessToken($userId);

        try {
            $this->oauthFlow->revoke($accessToken);
        } catch (\Throwable $e) {
            // Best-effort revocation — log but don't fail on error
            Log::warning(
                "Google token revocation failed for user {$userId}: {$e->getMessage()}"
            );
        }

        return new DisconnectResult(
            externalService: self::NAME,
            disconnected: true,
        );
    }

    public function completeConnection(
        string $userId,
        string $code,
        string $redirectUri,
    ): AuthorizationGrant {
        $tokens = $this->oauthFlow->exchangeCode($code, $redirectUri);

        // Parse granted scope strings back to bundle names.
        // Google returns the exact scope strings that were granted.
        $grantedBundleNames = [];
        foreach ($tokens['scopes'] as $scopeString) {
            $bundle = ScopeBundle::fromScopeString($scopeString);
            if ($bundle !== null) {
                $grantedBundleNames[] = $bundle->value;
            }
        }

        $expiresAt = null;
        if (isset($tokens['expires_in'])) {
            $expiresAt = CarbonImmutable::now()->addSeconds($tokens['expires_in']);
        }

        return new AuthorizationGrant(
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'],
            expiresAt: $expiresAt,
            scopes: implode(',', $grantedBundleNames),
        );
    }

    /**
     * @return list<MeasurementType|SessionType>
     */
    private function typeList(): array
    {
        return [
            MeasurementType::Steps,
            MeasurementType::HeartRate,
            MeasurementType::CaloriesBurned,
            MeasurementType::Weight,
            SessionType::Workout,
            SessionType::Sleep,
        ];
    }
}
