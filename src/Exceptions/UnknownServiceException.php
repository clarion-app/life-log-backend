<?php

namespace ClarionApp\LifeLogBackend\Exceptions;

use RuntimeException;

/**
 * Nothing is registered under the requested service name.
 *
 * Usually this is not a misspelling but a provider that never booted — the
 * package supplying the service is missing from the deployment, or registers
 * later than the code asking for it. The message therefore lists what *is*
 * registered, because "acme-band is unknown, and so is everything else" and
 * "acme-band is unknown, but four other services are here" have completely
 * different causes.
 */
class UnknownServiceException extends RuntimeException
{
    /**
     * @param  list<string>  $knownNames
     */
    public function __construct(
        public readonly string $name,
        public readonly array $knownNames = [],
    ) {
        $known = $knownNames === []
            ? 'No health services are registered at all, which usually means no service package is '
                . 'installed or none has booted yet.'
            : 'Registered: ' . implode(', ', $knownNames) . '.';

        parent::__construct("No health service is registered as '{$name}'. {$known}");
    }
}
