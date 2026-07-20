<?php

namespace ClarionApp\LifeLogBackend\Credentials;

use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Exceptions\ServiceNotConfiguredException;

/**
 * Fetches live service credentials from the database.
 *
 * Per-request memoisation only (a protected $cache on a request-scoped
 * instance, not a singleton spanning jobs). A queue worker handling
 * successive jobs must observe a rotation between them.
 */
final class ServiceCredentialProvider
{
    /** @var array<string, ServiceCredential|null> per-request memoisation cache */
    protected array $cache = [];

    /**
     * Require a live credential for the given service.
     *
     * @throws ServiceNotConfiguredException when no live credential exists
     */
    public function require(string $externalService): ServiceCredential
    {
        $credential = $this->find($externalService);

        if ($credential === null) {
            throw new ServiceNotConfiguredException($externalService);
        }

        return $credential;
    }

    /**
     * Find a live credential, or return null if absent.
     */
    public function find(string $externalService): ?ServiceCredential
    {
        if (array_key_exists($externalService, $this->cache)) {
            return $this->cache[$externalService];
        }

        $credential = ServiceCredential::liveByService($externalService)->first();
        return $this->cache[$externalService] = $credential;
    }

    /**
     * True when a live credential exists — drives FR-011 / FR-018.
     */
    public function isConfigured(string $externalService): bool
    {
        return $this->find($externalService) !== null;
    }
}
