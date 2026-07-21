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
     * Fetch exactly one page.
     *
     * One call issues one provider request. That is not an implementation
     * detail: the engine reserves one unit of budget per fetch() and commits
     * the returned cursor before asking for the next page, so a service that
     * drained a window internally would spend N units against a reservation of
     * one and would lose every page of progress to an interruption.
     *
     * With more than one type requested, the types are walked in order and the
     * cursor names the type it left off in.
     *
     * @param  list<MeasurementType|SessionType>|null  $types  null means every supported type.
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
        if ($until->lt($since)) {
            throw HealthServiceFailure::invalidRequest(
                'The requested range ends before it starts.'
            );
        }

        // Obligation 9: honour $types exactly — no extra types returned.
        $types = $types ?? $this->supported;
        $types = array_values(array_filter(
            $types,
            fn ($type) => in_array($type, $this->supported, true),
        ));

        if ($types === []) {
            return new ResultPage([], [], null);
        }

        // Obligation 8: a range wider than the declared limit is a caller bug.
        // Rejecting it is the point — truncating would silently drop the part
        // of the window the engine has already recorded as covered.
        foreach ($types as $type) {
            $limit = $this->maxWindow($type);

            if ($limit !== null && $since->add($limit)->lt($until)) {
                throw HealthServiceFailure::invalidRequest(
                    'The requested range is wider than this type\'s maximum query window.'
                );
            }
        }

        // Resolve where in the type list this call resumes.
        $index = 0;
        $pageToken = null;

        if ($cursor !== null) {
            $googleCursor = GoogleCursor::fromPageCursor($cursor);
            $index = $this->indexOfType($types, $googleCursor->type);

            // Obligation 9: paging is per (range, type-set). A cursor issued
            // against a different window, or for a type this call did not ask
            // for, is rejected rather than reinterpreted.
            if ($index === null || !$googleCursor->matches($googleCursor->type, $since, $until)) {
                throw HealthServiceFailure::invalidRequest(
                    'This cursor was issued for a different range or type set.'
                );
            }

            $pageToken = $googleCursor->pageToken;
        }

        $client = new GoogleHealthClient(
            $this->httpClient ?? new Client(),
            $this->oauthFlow->getAccessToken($userId),
            AggregateSource::fromConfig(),
        );

        $type = $types[$index];
        $measurements = [];
        $sessions = [];
        $nextPageToken = null;

        try {
            if ($type instanceof MeasurementType) {
                $result = $client->fetchMeasurements($userId, $type, $since, $until, $pageToken);
                $measurements = (new MeasurementTranslator())->translate(
                    $result['dataPoints'],
                    $userId,
                    $this->unmappedRecorder,
                );
            } else {
                $result = $client->fetchSessions($userId, $type, $since, $until, $pageToken);
                $sessions = (new SessionTranslator())->translate(
                    $result['sessions'],
                    $userId,
                    $this->unmappedRecorder,
                );
            }

            $nextPageToken = $result['nextPageToken'];
        } catch (ScopeInsufficientException) {
            // This type is not covered by the current grant. Skip it — a
            // narrowed grant is not a failure, and the remaining types still
            // have to be walked, so the cursor advances past this one.
            $nextPageToken = null;
        }

        return new ResultPage(
            measurements: $measurements,
            sessions: $sessions,
            nextCursor: $this->nextCursor($types, $index, $type, $nextPageToken, $since, $until),
        );
    }

    /**
     * Where the next page resumes: the same type when it has more pages, the
     * next requested type when it does not, and null once the last type is
     * exhausted — which is the only condition that ends the range.
     *
     * @param  list<MeasurementType|SessionType>  $types
     */
    private function nextCursor(
        array $types,
        int $index,
        MeasurementType|SessionType $type,
        ?string $nextPageToken,
        CarbonImmutable $since,
        CarbonImmutable $until,
    ): ?PageCursor {
        if ($nextPageToken !== null) {
            return (new GoogleCursor($nextPageToken, $type, $since, $until))->toPageCursor();
        }

        if (!isset($types[$index + 1])) {
            return null;
        }

        return (new GoogleCursor(null, $types[$index + 1], $since, $until))->toPageCursor();
    }

    /**
     * Position of a type within the requested set, or null when absent.
     *
     * @param  list<MeasurementType|SessionType>  $types
     */
    private function indexOfType(array $types, MeasurementType|SessionType $type): ?int
    {
        foreach ($types as $index => $candidate) {
            if ($candidate === $type) {
                return $index;
            }
        }

        return null;
    }

    public function renewAccess(string $userId): RenewalResult
    {
        try {
            $tokens = $this->oauthFlow->refreshToken(
                $this->oauthFlow->getRefreshToken($userId),
            );

            $expiresAt = null;
            if (isset($tokens['expires_in'])) {
                $expiresAt = CarbonImmutable::now()->addSeconds($tokens['expires_in']);
            }

            // RenewalResult carries no token, so the renewal is only durable
            // if it is stored here — otherwise the next fetch presents the
            // expired token again.
            $this->oauthFlow->storeAccessToken($userId, $tokens['access_token'], $expiresAt);

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
