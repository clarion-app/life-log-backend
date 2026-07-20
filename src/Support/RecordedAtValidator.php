<?php

namespace ClarionApp\LifeLogBackend\Support;

use ClarionApp\LifeLogBackend\Exceptions\ImplausibleTimestampException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Validates a service-reported measurement time before anything buckets it.
 *
 * A missing or nonsensical time has no safe default. Falling back to the epoch
 * parks the reading in January 1970; falling back to "now" parks it in the
 * import hour. Both produce a stored reading that claims to have happened at a
 * time it did not, and the hourly rollup then aggregates it into the wrong
 * bucket permanently. Skipping and recording the reason is the only option that
 * does not corrupt history.
 */
final class RecordedAtValidator
{
    /**
     * Earliest time any wearable reading could plausibly carry. Also catches the
     * epoch-zero value services emit for an unset field.
     */
    public const PLAUSIBLE_FLOOR = '2000-01-01T00:00:00Z';

    /**
     * How far ahead of now a reading may claim to be. Devices and services do
     * drift, so modest skew is tolerated rather than discarded; a year is not
     * skew, it is a broken field.
     */
    public const FUTURE_TOLERANCE_SECONDS = 86400;

    /**
     * @param  mixed  $raw  whatever the service supplied for the time
     * @return CarbonImmutable normalized to UTC
     *
     * @throws ImplausibleTimestampException the caller skips the item and records the reason (FR-032)
     */
    public function validate(mixed $raw): CarbonImmutable
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            throw ImplausibleTimestampException::missing();
        }

        try {
            $at = CarbonImmutable::parse($raw)->utc();
        } catch (Throwable) {
            throw ImplausibleTimestampException::unparseable(is_scalar($raw) ? (string) $raw : gettype($raw));
        }

        return $this->assertPlausible($at);
    }

    /**
     * The range check on an already-parsed time.
     *
     * @throws ImplausibleTimestampException
     */
    public function assertPlausible(CarbonImmutable $at): CarbonImmutable
    {
        $at = $at->utc();
        $floor = CarbonImmutable::parse(self::PLAUSIBLE_FLOOR)->utc();
        $ceiling = CarbonImmutable::now()->utc()->addSeconds(self::FUTURE_TOLERANCE_SECONDS);

        if ($at->lessThan($floor)) {
            throw ImplausibleTimestampException::tooEarly(
                $at->toIso8601String(),
                $floor->toIso8601String(),
            );
        }

        if ($at->greaterThan($ceiling)) {
            throw ImplausibleTimestampException::tooLate(
                $at->toIso8601String(),
                $ceiling->toIso8601String(),
            );
        }

        return $at;
    }
}
