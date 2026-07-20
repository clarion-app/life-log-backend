<?php

namespace ClarionApp\LifeLogBackend\Exceptions;

use RuntimeException;

/**
 * A service reported a measurement with no recorded time, or a time outside the
 * plausible range.
 *
 * Like an unconvertible unit, this is a runtime data condition: the caller
 * skips the item, records the reason, and continues with the rest of the page.
 * Substituting a default — the epoch, or "now" — would bucket the reading into
 * an hour it did not happen in, which is worse than not storing it at all.
 */
class ImplausibleTimestampException extends RuntimeException
{
    public const REASON_MISSING = 'timestamp_missing';
    public const REASON_UNPARSEABLE = 'timestamp_unparseable';
    public const REASON_TOO_EARLY = 'timestamp_too_early';
    public const REASON_TOO_LATE = 'timestamp_too_late';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self(self::REASON_MISSING, 'Measurement has no recorded time; skipped rather than bucketed.');
    }

    public static function unparseable(string $raw): self
    {
        return new self(
            self::REASON_UNPARSEABLE,
            "Measurement time '{$raw}' could not be parsed; skipped rather than bucketed.",
        );
    }

    public static function tooEarly(string $shown, string $floor): self
    {
        return new self(
            self::REASON_TOO_EARLY,
            "Measurement time '{$shown}' is before the plausible floor of '{$floor}'; skipped rather than bucketed.",
        );
    }

    public static function tooLate(string $shown, string $ceiling): self
    {
        return new self(
            self::REASON_TOO_LATE,
            "Measurement time '{$shown}' is beyond the plausible ceiling of '{$ceiling}'; skipped rather than bucketed.",
        );
    }
}
