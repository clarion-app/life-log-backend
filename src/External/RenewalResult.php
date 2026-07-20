<?php

namespace ClarionApp\LifeLogBackend\External;

use Carbon\CarbonImmutable;

/**
 * What behavior 3 hands back after attempting to renew access.
 *
 * $renewed false is an ordinary outcome, not an error: a service may decline to
 * renew because the user revoked access, in which case the caller stops
 * retrying and prompts them instead of looping on a renewal that can never
 * succeed.
 *
 * $expiresAt is nullable because some services renew without saying for how
 * long. A caller must treat the absence as "unknown" and re-renew reactively on
 * AccessExpired rather than inventing a lifetime.
 */
final readonly class RenewalResult
{
    public function __construct(
        public bool $renewed,
        public ?CarbonImmutable $expiresAt = null,
    ) {
    }

    public static function renewed(?CarbonImmutable $expiresAt = null): self
    {
        return new self(true, $expiresAt);
    }

    public static function declined(): self
    {
        return new self(false, null);
    }
}
