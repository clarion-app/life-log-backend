<?php

namespace Tests\Support;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Exceptions\ImplausibleTimestampException;
use ClarionApp\LifeLogBackend\Exceptions\UnconvertibleUnitException;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\External\TranslatedMeasurement;
use ClarionApp\LifeLogBackend\External\TranslatedSession;
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use ClarionApp\LifeLogBackend\Support\RecordedAtValidator;
use ClarionApp\LifeLogBackend\Support\UnitConverter;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;
use Generator;

/**
 * The second stand-in service, built to disagree with FakeStepService on every
 * dimension the contract is supposed to absorb.
 *
 * Nested envelope instead of flat rows, metric units with durations in minutes,
 * opaque continuation tokens instead of integer offsets, sessions as well as
 * measurements, dotted namespaced type names, vendor error codes instead of HTTP
 * statuses. Two services this dissimilar passing the same conformance suite
 * unmodified is the evidence the contract actually absorbs service peculiarity.
 *
 * Pages are generated **lazily**, one raw item at a time. A fake that built its
 * whole dataset in the constructor would hold every result in memory regardless
 * of paging, and the scale test's memory bound would pass while proving nothing.
 */
final class FakeSpanService implements ExternalHealthService
{
    public const NAME = 'fake-span';

    private int $generation = 1;

    private ?string $forcedErrorCode = null;

    private ?int $forcedRetryAfter = null;

    private UnitConverter $converter;

    private RecordedAtValidator $clock;

    /**
     * @param  list<int>  $sparsePages  page indexes that carry no items but are not the end —
     *                                  a stretch the user simply wore nothing
     */
    public function __construct(
        private int $measurementCount = 6,
        private int $sessionCount = 2,
        private int $pageSize = 3,
        private array $sparsePages = [],
        private ?UnmappedTypeRecorder $recorder = null,
    ) {
        $this->converter = new UnitConverter();
        $this->clock = new RecordedAtValidator();
    }

    public function name(): string
    {
        return self::NAME;
    }

    /** Overlaps FakeStepService on steps and weight; diverges everywhere else. */
    public function supportedTypes(): array
    {
        return [
            MeasurementType::Steps,
            MeasurementType::Weight,
            MeasurementType::ActiveMinutes,
            SessionType::Sleep,
            SessionType::Workout,
        ];
    }

    public function beginConnection(string $userId): ConnectionResult
    {
        $this->failIfForced();

        return new ConnectionResult(
            externalService: self::NAME,
            authorizationUrl: 'https://span.example/connect?app=life-log',
            state: 'span-' . substr(sha1($userId . self::NAME), 0, 16),
        );
    }

    public function fetch(
        string $userId,
        CarbonImmutable $since,
        CarbonImmutable $until,
        ?PageCursor $cursor = null,
    ): ResultPage {
        $this->failIfForced();

        if ($until->lessThan($since)) {
            throw HealthServiceFailure::invalidRequest(
                'the requested window ends before it begins'
            );
        }

        $page = $this->pageIndexFrom($cursor);
        $total = $this->measurementCount + $this->sessionCount;

        // A sparse page consumes no items; the data page index therefore skips
        // over every sparse page that came before this one.
        $dataPage = $page - count(array_filter($this->sparsePages, fn (int $s): bool => $s < $page));
        $isSparse = in_array($page, $this->sparsePages, true);

        $start = $dataPage * $this->pageSize;
        $count = $isSparse ? 0 : max(0, min($this->pageSize, $total - $start));
        $consumed = $isSparse ? min($start, $total) : min($start + $this->pageSize, $total);

        // The envelope this service actually speaks: items nested two levels down,
        // alongside metadata a caller must never see. The items are a generator,
        // so only one raw item exists at a time.
        $envelope = [
            'meta' => ['schema' => 'span.v2', 'partial' => false],
            'data' => [
                'items' => $this->rawItems($start, $count, $since),
                'next_token' => null,
            ],
        ];

        $measurements = [];
        $sessions = [];

        foreach ($envelope['data']['items'] as $item) {
            $translated = $this->translate($userId, $item);

            if ($translated instanceof TranslatedMeasurement) {
                $measurements[] = $translated;
            } elseif ($translated instanceof TranslatedSession) {
                $sessions[] = $translated;
            }
        }

        $more = $consumed < $total || in_array($page + 1, $this->sparsePages, true);

        return new ResultPage($measurements, $sessions, $more ? $this->tokenFor($page + 1) : null);
    }

    public function renewAccess(string $userId): RenewalResult
    {
        $this->failIfForced();

        // This service renews without saying for how long — a real variation the
        // caller has to tolerate rather than invent a lifetime for.
        return RenewalResult::renewed();
    }

    public function disconnect(string $userId): DisconnectResult
    {
        $this->failIfForced();

        // The remote could not be reached to confirm, which is still a success:
        // local access is gone and retrying forever would help nobody.
        return DisconnectResult::localOnly();
    }

    public function invalidateCursors(): void
    {
        $this->generation++;
    }

    /** Arm the next behavior call to fail, in this service's own error language. */
    public function failWith(string $vendorErrorCode, ?int $retryAfterSeconds = null): void
    {
        $this->forcedErrorCode = $vendorErrorCode;
        $this->forcedRetryAfter = $retryAfterSeconds;
    }

    /**
     * The vendor code is mapped to a kind here and then discarded. A caller has
     * no way to learn that this service says 'TOKEN_EXPIRED' where the other one
     * says 401.
     */
    private function failIfForced(): void
    {
        if ($this->forcedErrorCode === null) {
            return;
        }

        $code = $this->forcedErrorCode;
        $retryAfter = $this->forcedRetryAfter;

        $this->forcedErrorCode = null;
        $this->forcedRetryAfter = null;

        throw match ($code) {
            'TOKEN_EXPIRED' => HealthServiceFailure::accessExpired('span: authorization has aged out'),
            'GRANT_REVOKED' => HealthServiceFailure::accessRevoked('span: the member withdrew consent'),
            'BAD_CLIENT'    => HealthServiceFailure::credentialsRejected('span: client assertion refused'),
            'THROTTLED'     => HealthServiceFailure::rateLimited($retryAfter, 'span: quota exceeded'),
            'MALFORMED'     => HealthServiceFailure::invalidRequest('span: query was not understood'),
            default         => HealthServiceFailure::serviceUnavailable("span: {$code}"),
        };
    }

    private function tokenFor(int $page): PageCursor
    {
        // Opaque to the caller by construction — a continuation token, not a
        // position it could compute for itself.
        return PageCursor::fromString(
            base64_encode(json_encode(['p' => $page, 'g' => $this->generation], JSON_THROW_ON_ERROR))
        );
    }

    private function pageIndexFrom(?PageCursor $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        $decoded = json_decode((string) base64_decode($cursor->toString(), true), true);

        if (!is_array($decoded) || ($decoded['g'] ?? null) !== $this->generation) {
            throw HealthServiceFailure::invalidRequest('span: continuation token is stale');
        }

        return (int) ($decoded['p'] ?? 0);
    }

    /**
     * One raw item at a time, built on demand.
     *
     * Nothing here materializes the full range: peak memory is one raw item plus
     * the translated page, whether the range holds ten results or ten thousand.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function rawItems(int $start, int $count, CarbonImmutable $since): Generator
    {
        for ($i = $start; $i < $start + $count; $i++) {
            yield $i < $this->measurementCount
                ? $this->rawMeasurement($i, $since)
                : $this->rawSession($i - $this->measurementCount, $since);
        }
    }

    /** @return array<string, mixed> */
    private function rawMeasurement(int $i, CarbonImmutable $since): array
    {
        // Nested one level deeper than the step service's flat rows, with dotted
        // namespaced names and metric units.
        [$name, $value, $unit] = match ($i % 3) {
            0 => ['activity.steps',  (string) (100 + $i), 'count'],
            1 => ['mass',            '70.' . str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT), 'kg'],
            default => ['activity.active', (string) (10 + ($i % 30)), 'min'],
        };

        return [
            'kind' => 'sample',
            'identity' => ['external_id' => "span-m-{$i}"],
            'observation' => ['name' => $name, 'quantity' => ['value' => $value, 'unit' => $unit]],
            'occurred' => ['at' => $since->addSeconds($i)->toIso8601String()],
        ];
    }

    /** @return array<string, mixed> */
    private function rawSession(int $j, CarbonImmutable $since): array
    {
        $startedAt = $since->addHours($j);

        // Durations in minutes — the caller never learns that, because they are
        // converted to the vocabulary's seconds before crossing the boundary.
        return $j % 2 === 0
            ? [
                'kind' => 'span',
                'identity' => ['external_id' => "span-s-{$j}"],
                'observation' => ['name' => 'span.sleep'],
                'window' => ['from' => $startedAt->toIso8601String(), 'to' => $startedAt->addHours(7)->toIso8601String()],
                'totals' => [
                    ['name' => 'duration', 'value' => '420', 'unit' => 'min'],
                    ['name' => 'asleep_duration', 'value' => '385', 'unit' => 'min'],
                ],
            ]
            : [
                'kind' => 'span',
                'identity' => ['external_id' => "span-s-{$j}"],
                'observation' => ['name' => 'span.workout'],
                'window' => ['from' => $startedAt->toIso8601String(), 'to' => $startedAt->addMinutes(45)->toIso8601String()],
                'totals' => [
                    ['name' => 'duration', 'value' => '45', 'unit' => 'min'],
                    ['name' => 'distance', 'value' => '8200', 'unit' => 'm'],
                    ['name' => 'energy', 'value' => '480', 'unit' => 'kcal'],
                ],
            ];
    }

    private function translate(string $userId, array $item): TranslatedMeasurement|TranslatedSession|null
    {
        return $item['kind'] === 'span'
            ? $this->translateSession($userId, $item)
            : $this->translateMeasurement($userId, $item);
    }

    private function translateMeasurement(string $userId, array $item): ?TranslatedMeasurement
    {
        $name = $item['observation']['name'];

        $type = match ($name) {
            'activity.steps'  => MeasurementType::Steps,
            'mass'            => MeasurementType::Weight,
            'activity.active' => MeasurementType::ActiveMinutes,
            default           => null,
        };

        $quantity = $item['observation']['quantity'];

        if ($type === null) {
            $this->recorder()->record(self::NAME, $name, $quantity['value'], $quantity['unit']);

            return null;
        }

        try {
            $value = $this->converter->toCanonical($quantity['value'], $quantity['unit'], $type);
            $recordedAt = $this->clock->validate($item['occurred']['at']);
        } catch (UnconvertibleUnitException|ImplausibleTimestampException) {
            $this->recorder()->record(self::NAME, $name, $quantity['value'], $quantity['unit']);

            return null;
        }

        return new TranslatedMeasurement(
            userId: $userId,
            type: $type,
            value: $value,
            unit: $type->canonicalUnit(),
            recordedAt: $recordedAt,
            externalId: $item['identity']['external_id'],
            externalService: self::NAME,
        );
    }

    private function translateSession(string $userId, array $item): ?TranslatedSession
    {
        $name = $item['observation']['name'];

        $type = match ($name) {
            'span.sleep'   => SessionType::Sleep,
            'span.workout' => SessionType::Workout,
            default        => null,
        };

        if ($type === null) {
            $this->recorder()->record(self::NAME, $name);

            return null;
        }

        $declared = $type->summaryValues();
        $summary = [];

        foreach ($item['totals'] as $total) {
            if (!array_key_exists($total['name'], $declared)) {
                $this->recorder()->record(self::NAME, "{$name}.{$total['name']}", $total['value'], $total['unit']);

                continue;
            }

            // The converter is keyed by measurement type, so each summary value is
            // converted through the type whose canonical unit the vocabulary
            // declares for that key. Same rational factors, no float anywhere.
            $through = match ($declared[$total['name']]) {
                's'    => MeasurementType::ActiveMinutes,
                'm'    => MeasurementType::Distance,
                'kcal' => MeasurementType::CaloriesBurned,
                default => null,
            };

            if ($through === null) {
                $this->recorder()->record(self::NAME, "{$name}.{$total['name']}", $total['value'], $total['unit']);

                continue;
            }

            try {
                $summary[$total['name']] = $this->converter->toCanonical($total['value'], $total['unit'], $through);
            } catch (UnconvertibleUnitException) {
                $this->recorder()->record(self::NAME, "{$name}.{$total['name']}", $total['value'], $total['unit']);
            }
        }

        try {
            $startedAt = $this->clock->validate($item['window']['from']);
            $endedAt = $this->clock->validate($item['window']['to']);
        } catch (ImplausibleTimestampException) {
            $this->recorder()->record(self::NAME, $name);

            return null;
        }

        return new TranslatedSession(
            userId: $userId,
            type: $type,
            startedAt: $startedAt,
            endedAt: $endedAt,
            summaryValues: $summary,
            externalId: $item['identity']['external_id'],
            externalService: self::NAME,
        );
    }

    private function recorder(): UnmappedTypeRecorder
    {
        return $this->recorder ??= new UnmappedTypeRecorder();
    }
}
