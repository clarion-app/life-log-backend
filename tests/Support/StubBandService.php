<?php

namespace Tests\Support;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\External\TranslatedMeasurement;
use ClarionApp\LifeLogBackend\Support\UnitConverter;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;

/**
 * The smallest thing that is still a health service: one supported type, one
 * page, no paging state.
 *
 * Its purpose is to be added from outside. A chest strap that reports heart
 * rate and nothing else is a realistic third service, and the interesting fact
 * about it is how little it needs to know — it names itself, declares one type,
 * and answers the four behaviors.
 */
final class StubBandService implements ExternalHealthService
{
    public const NAME = StubBandServiceProvider::NAME;

    private UnitConverter $converter;

    public function __construct()
    {
        $this->converter = new UnitConverter();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supportedTypes(): array
    {
        return [MeasurementType::HeartRate];
    }

    public function beginConnection(string $userId): ConnectionResult
    {
        $state = 'band-' . bin2hex(random_bytes(32));
        return new ConnectionResult(
            externalService: self::NAME,
            authorizationUrl: 'https://band.example/authorize?state=' . urlencode($state),
            state: $state,
        );
    }

    public function fetch(
        string $userId,
        CarbonImmutable $since,
        CarbonImmutable $until,
        ?PageCursor $cursor = null,
    ): ResultPage {
        if ($until->lessThan($since)) {
            throw HealthServiceFailure::invalidRequest('band: the window ends before it begins');
        }

        if ($cursor !== null) {
            // This service is always exhausted after one page, so any cursor it
            // is handed came from somewhere else or from an older release.
            throw HealthServiceFailure::invalidRequest('band: no cursor was ever issued');
        }

        $measurements = [];

        foreach ([['band-1', '62'], ['band-2', '71']] as [$id, $bpm]) {
            $measurements[] = new TranslatedMeasurement(
                userId: $userId,
                type: MeasurementType::HeartRate,
                value: $this->converter->toCanonical($bpm, 'bpm', MeasurementType::HeartRate),
                unit: MeasurementType::HeartRate->canonicalUnit(),
                recordedAt: $since->addMinutes(count($measurements) * 5),
                externalId: $id,
                externalService: self::NAME,
            );
        }

        return new ResultPage($measurements, [], null);
    }

    public function renewAccess(string $userId): RenewalResult
    {
        return RenewalResult::renewed(CarbonImmutable::now()->addDay());
    }

    public function disconnect(string $userId): DisconnectResult
    {
        return DisconnectResult::confirmed();
    }

    public function completeConnection(
        string $userId,
        string $code,
        string $redirectUri,
    ): AuthorizationGrant {
        return new AuthorizationGrant(
            accessToken: 'band-access-' . $userId,
            refreshToken: 'band-refresh-' . $userId,
            expiresAt: CarbonImmutable::now()->addHours(2),
            scopes: 'heart_rate',
            externalAccountId: 'band-acc-' . $userId,
        );
    }
}
