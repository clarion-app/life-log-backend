<?php

namespace ClarionApp\LifeLogBackend\External;

use InvalidArgumentException;

/**
 * What behavior 1 hands back: where to send the user to authorize, and the
 * one-time value that ties their return trip to this request.
 *
 * Nothing is persisted here — storing the connection is Phase 1.4's job. This
 * object exists so the caller never has to know whether the service speaks
 * OAuth, an API key exchange, or something else.
 */
final readonly class ConnectionResult
{
    public function __construct(
        public string $externalService,
        public string $authorizationUrl,
        public string $state,
    ) {
        if ($this->externalService === '') {
            throw new InvalidArgumentException('A connection result must name the service it came from.');
        }

        if ($this->authorizationUrl === '') {
            throw new InvalidArgumentException('A connection result must carry an authorization URL.');
        }

        // The state ties the callback to the request that started it. Without it
        // a callback cannot be distinguished from one forged elsewhere.
        if ($this->state === '') {
            throw new InvalidArgumentException('A connection result must carry a state value.');
        }
    }
}
