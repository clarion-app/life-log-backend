<?php

namespace ClarionApp\LifeLogBackend\External;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What behavior 5 (completeConnection) hands back after exchanging a consent
 * code for a usable authorization.
 *
 * Nothing here is a provider payload — obligation 1 (translate before
 * returning) applies unchanged.
 */
final readonly class AuthorizationGrant
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $scopes = null,
        public ?string $externalAccountId = null,
    ) {
        if ($this->accessToken === '') {
            throw new InvalidArgumentException(
                'An authorization grant must carry an access token — a grant without one '
                . 'cannot authorize the fetch it exists to enable.'
            );
        }
    }
}
