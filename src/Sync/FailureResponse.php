<?php

namespace ClarionApp\LifeLogBackend\Sync;

use Carbon\CarbonImmutable;

/**
 * The policy's verdict on a HealthServiceFailure.
 *
 * Final readonly value object — the runner reads the flags and acts, nothing
 * mutates this after construction.
 */
final readonly class FailureResponse
{
    public function __construct(
        public bool $countsAsFailure,     // increments consecutive_failures
        public bool $flagsImmediately,    // → needs_attention, ladder skipped
        public bool $clearsCursor,        // discard cursor + stored window
        public bool $attemptsRenewal,     // call renewAccess() once, in-run
        public ?CarbonImmutable $nextAttemptAt,
    ) {
    }
}
