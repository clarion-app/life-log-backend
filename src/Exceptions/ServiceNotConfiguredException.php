<?php

namespace ClarionApp\LifeLogBackend\Exceptions;

use RuntimeException;

/**
 * Thrown when a service has no live credential configured.
 *
 * This is the exception ServiceCredentialProvider::require() raises when the
 * credential is absent (or soft-deleted). Distinct from UnknownServiceException
 * which is about registration, not configuration.
 */
class ServiceNotConfiguredException extends RuntimeException
{
    public function __construct(string $externalService)
    {
        parent::__construct(
            sprintf(
                'Service "%s" has no live credential configured. '
                . 'Configure credentials before connecting accounts.',
                $externalService,
            ),
        );
        $this->externalService = $externalService;
    }

    public readonly string $externalService;
}
