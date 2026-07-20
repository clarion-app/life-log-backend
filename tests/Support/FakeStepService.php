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
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use ClarionApp\LifeLogBackend\Support\RecordedAtValidator;
use ClarionApp\LifeLogBackend\Support\UnitConverter;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;

/**
 * A stand-in service built to be as unlike FakeSpanService as possible.
 *
 * Its peculiarities — flat scalar rows, imperial units, integer-offset paging,
 * measurements only, its own type names, HTTP-status-shaped errors — all live
 * inside this class. Nothing about them is visible to a caller, which is the
 * property the contract exists to guarantee. If a test elsewhere ever has to
 * know which of the two fakes it is talking to, the boundary has leaked.
 */
final class FakeStepService implements ExternalHealthService
{
    public const NAME = 'fake-step';

    /** Bumped by invalidateCursors(); a cursor from an older generation is refused. */
    private int $generation = 1;

    /** The next call to any behavior fails with this HTTP status, then clears. */
    private ?int $forcedStatus = null;

    private ?int $forcedRetryAfter = null;

    /** @var list<array<string, string>> flat scalar rows, exactly as this service would emit them */
    private array $rows;

    private UnitConverter $converter;

    private RecordedAtValidator $clock;

    /**
     * @param  list<array<string, string>>|null  $rows
     */
    public function __construct(
        private int $pageSize = 3,
        ?array $rows = null,
        private ?UnmappedTypeRecorder $recorder = null,
    ) {
        $this->rows = $rows ?? self::defaultRows();
        $this->converter = new UnitConverter();
        $this->clock = new RecordedAtValidator();
    }

    /**
     * Flat, scalar-only payload rows — no nesting anywhere, imperial units, and
     * this service's own names for things.
     *
     * The last two rows are deliberately unusable: one type the vocabulary has
     * no equivalent for, one unit that cannot be converted. Both must be skipped
     * and recorded while the rest of the page still comes back (FR-014).
     *
     * @return list<array<string, string>>
     */
    public static function defaultRows(): array
    {
        return [
            ['id' => 'step-1', 'type' => 'step_count',    'value' => '1200',  'unit' => 'count', 'recorded_at' => '2026-01-01T08:00:00Z'],
            ['id' => 'step-2', 'type' => 'step_count',    'value' => '3400',  'unit' => 'count', 'recorded_at' => '2026-01-01T09:00:00Z'],
            ['id' => 'step-3', 'type' => 'bodyweight',    'value' => '181.5', 'unit' => 'lb',    'recorded_at' => '2026-01-01T07:30:00Z'],
            ['id' => 'step-4', 'type' => 'walk_distance', 'value' => '2.5',   'unit' => 'mi',    'recorded_at' => '2026-01-01T10:00:00Z'],
            ['id' => 'step-5', 'type' => 'blood_glucose', 'value' => '96',    'unit' => 'mg/dL', 'recorded_at' => '2026-01-01T11:00:00Z'],
            ['id' => 'step-6', 'type' => 'bodyweight',    'value' => '13.1',  'unit' => 'stone', 'recorded_at' => '2026-01-01T12:00:00Z'],
        ];
    }

    public function name(): string
    {
        return self::NAME;
    }

    /** A watch and a scale: no sessions, no heart rate. Partial coverage is normal. */
    public function supportedTypes(): array
    {
        return [
            MeasurementType::Steps,
            MeasurementType::Weight,
            MeasurementType::Distance,
        ];
    }

    public function beginConnection(string $userId): ConnectionResult
    {
        $this->failIfForced();

        return new ConnectionResult(
            externalService: self::NAME,
            authorizationUrl: 'https://step.example/oauth/authorize?client_id=life-log',
            state: 'state-' . substr(md5($userId . self::NAME), 0, 16),
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
                'until precedes since; the caller\'s date arithmetic is wrong.'
            );
        }

        $offset = $this->offsetFrom($cursor);
        $inRange = $this->rowsInRange($since, $until);

        $measurements = [];

        foreach (array_slice($inRange, $offset, $this->pageSize) as $row) {
            $translated = $this->translate($userId, $row);

            if ($translated !== null) {
                $measurements[] = $translated;
            }
        }

        $consumed = min($offset + $this->pageSize, count($inRange));
        $next = $consumed < count($inRange)
            ? PageCursor::fromArray(['offset' => $consumed, 'generation' => $this->generation])
            : null;

        return new ResultPage($measurements, [], $next);
    }

    public function renewAccess(string $userId): RenewalResult
    {
        $this->failIfForced();

        return RenewalResult::renewed(CarbonImmutable::now()->addHours(8));
    }

    public function disconnect(string $userId): DisconnectResult
    {
        $this->failIfForced();

        // Idempotent: disconnecting an account that was never connected succeeds.
        return DisconnectResult::confirmed();
    }

    /** Every cursor issued so far stops being honored. */
    public function invalidateCursors(): void
    {
        $this->generation++;
    }

    /** Arm the next behavior call to fail, in this service's own error language. */
    public function failWith(int $httpStatus, ?int $retryAfterSeconds = null): void
    {
        $this->forcedStatus = $httpStatus;
        $this->forcedRetryAfter = $retryAfterSeconds;
    }

    /**
     * The HTTP status never escapes this method — it is mapped to a kind here and
     * the number is gone. There is nowhere on HealthServiceFailure to put it even
     * if this service wanted to.
     */
    private function failIfForced(): void
    {
        if ($this->forcedStatus === null) {
            return;
        }

        $status = $this->forcedStatus;
        $retryAfter = $this->forcedRetryAfter;

        $this->forcedStatus = null;
        $this->forcedRetryAfter = null;

        throw match (true) {
            $status === 400 => HealthServiceFailure::invalidRequest('step service rejected the request'),
            $status === 401 => HealthServiceFailure::accessExpired('step service token has lapsed'),
            $status === 403 => HealthServiceFailure::accessRevoked('step service grant was withdrawn'),
            $status === 407 => HealthServiceFailure::credentialsRejected('step service rejected our client'),
            $status === 429 => HealthServiceFailure::rateLimited($retryAfter, 'step service is throttling'),
            $status >= 500  => HealthServiceFailure::serviceUnavailable("step service returned {$status}"),
            default         => HealthServiceFailure::serviceUnavailable("step service returned {$status}"),
        };
    }

    private function offsetFrom(?PageCursor $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        $parts = $cursor->toArray();

        if (($parts['generation'] ?? null) !== $this->generation) {
            // Never silently restart: re-delivering the whole range would write a
            // second copy of everything already stored and look merely slow.
            throw HealthServiceFailure::invalidRequest('this cursor is no longer honored');
        }

        return (int) ($parts['offset'] ?? 0);
    }

    /** @return list<array<string, string>> */
    private function rowsInRange(CarbonImmutable $since, CarbonImmutable $until): array
    {
        return array_values(array_filter($this->rows, function (array $row) use ($since, $until): bool {
            $at = CarbonImmutable::parse($row['recorded_at']);

            return !$at->lessThan($since) && !$at->greaterThan($until);
        }));
    }

    /**
     * Skip and record, never guess (FR-014). An unmappable type or an
     * unconvertible unit costs one row, not the page.
     */
    private function translate(string $userId, array $row): ?TranslatedMeasurement
    {
        $type = match ($row['type']) {
            'step_count'    => MeasurementType::Steps,
            'bodyweight'    => MeasurementType::Weight,
            'walk_distance' => MeasurementType::Distance,
            default         => null,
        };

        if ($type === null) {
            $this->recorder()->record(self::NAME, $row['type'], $row['value'], $row['unit']);

            return null;
        }

        try {
            $value = $this->converter->toCanonical($row['value'], $row['unit'], $type);
            $recordedAt = $this->clock->validate($row['recorded_at']);
        } catch (UnconvertibleUnitException|ImplausibleTimestampException) {
            $this->recorder()->record(self::NAME, $row['type'], $row['value'], $row['unit']);

            return null;
        }

        return new TranslatedMeasurement(
            userId: $userId,
            type: $type,
            value: $value,
            unit: $type->canonicalUnit(),
            recordedAt: $recordedAt,
            externalId: $row['id'],
            externalService: self::NAME,
        );
    }

    private function recorder(): UnmappedTypeRecorder
    {
        return $this->recorder ??= new UnmappedTypeRecorder();
    }
}
