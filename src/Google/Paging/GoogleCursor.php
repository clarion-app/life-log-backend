<?php

namespace ClarionApp\LifeLogBackend\Google\Paging;

use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Wraps Google's pageToken via PageCursor::fromArray(), carrying the token
 * plus the segment's (type, since, until) — so a cursor replayed into a
 * different window or type set is detected and rejected as InvalidRequest.
 *
 * The pageToken itself is opaque to us — Google issues it and we echo it back.
 * What we control is the context: which type and window it was issued for.
 */
final readonly class GoogleCursor
{
    /**
     * @param  string|null  $pageToken  Google's opaque page token, or null to mean
     *         "the first page of $type" — the position a multi-type fetch resumes
     *         from once the preceding type has been exhausted. Distinct from a
     *         null PageCursor, which means "the first page of the whole request".
     * @param  MeasurementType|SessionType  $type  The data type this cursor was issued for
     * @param  CarbonImmutable  $since  Window start this cursor was issued for
     * @param  CarbonImmutable  $until  Window end this cursor was issued for
     */
    public function __construct(
        public ?string $pageToken,
        public MeasurementType|SessionType $type,
        public CarbonImmutable $since,
        public CarbonImmutable $until,
    ) {
        if ($this->pageToken === '') {
            throw new InvalidArgumentException(
                'GoogleCursor pageToken cannot be the empty string — use null for '
                . 'the first page of a type.'
            );
        }
    }

    /**
     * Build a PageCursor from this GoogleCursor.
     *
     * The resulting cursor carries (pageToken, type, since, until) as named
     * scalar parts, so it survives JSON serialization and round-trips cleanly.
     */
    public function toPageCursor(): PageCursor
    {
        return PageCursor::fromArray([
            'pageToken' => $this->pageToken,
            'type'      => $this->type->value,
            'since'     => $this->since->toISOString(),
            'until'     => $this->until->toISOString(),
        ]);
    }

    /**
     * Restore a GoogleCursor from a PageCursor that was built by toPageCursor().
     *
     * @throws InvalidArgumentException if the cursor parts don't match the expected shape
     */
    public static function fromPageCursor(PageCursor $cursor): self
    {
        $parts = $cursor->toArray();

        $pageToken = $parts['pageToken'] ?? null;
        $typeValue = $parts['type'] ?? null;
        $sinceIso  = $parts['since'] ?? null;
        $untilIso  = $parts['until'] ?? null;

        if ($pageToken !== null && (!is_string($pageToken) || $pageToken === '')) {
            throw new InvalidArgumentException('Cursor carries a malformed pageToken.');
        }

        if ($typeValue === null || !is_string($typeValue)) {
            throw new InvalidArgumentException('Cursor missing type.');
        }

        if ($sinceIso === null || !is_string($sinceIso)) {
            throw new InvalidArgumentException('Cursor missing since.');
        }

        if ($untilIso === null || !is_string($untilIso)) {
            throw new InvalidArgumentException('Cursor missing until.');
        }

        // Resolve the type value back to an enum instance.
        $type = self::resolveType($typeValue);

        return new self(
            pageToken: $pageToken,
            type:      $type,
            since:     CarbonImmutable::parse($sinceIso)->utc(),
            until:     CarbonImmutable::parse($untilIso)->utc(),
        );
    }

    /**
     * Check if this cursor was issued for the same type and window.
     *
     * Returns true if the cursor matches the given type and window exactly.
     * A mismatch means the cursor was replayed under different parameters.
     */
    public function matches(
        MeasurementType|SessionType $type,
        CarbonImmutable $since,
        CarbonImmutable $until,
    ): bool {
        return $this->type === $type
            && $this->since->eq($since)
            && $this->until->eq($until);
    }

    /**
     * Resolve a type value string back to a MeasurementType or SessionType.
     */
    private static function resolveType(string $typeValue): MeasurementType|SessionType
    {
        // Try MeasurementType first
        foreach (MeasurementType::cases() as $case) {
            if ($case->value === $typeValue) {
                return $case;
            }
        }

        // Then SessionType
        foreach (SessionType::cases() as $case) {
            if ($case->value === $typeValue) {
                return $case;
            }
        }

        throw new InvalidArgumentException("Unknown type value: '{$typeValue}'.");
    }
}
