<?php

namespace ClarionApp\LifeLogBackend\Connection;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Google\Oauth\ScopeBundle;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;

/**
 * Resolves granted scopes from a ConnectedAccount's authorization row
 * into human-readable bundle names and vocabulary types.
 *
 * Reads the comma-separated scopes string, maps to ScopeBundle values,
 * expands to types via ScopeBundle::typesFor(), and computes the
 * missing set against the service's supported types.
 *
 * All degradation paths are non-throwing: null scopes, empty string,
 * unrecognised slugs, service absent from registry, missing
 * AccountAuthorization row all return empty arrays.
 */
final class GrantedScopeResolver
{
    public function __construct(
        private HealthServiceRegistry $registry,
    ) {
    }

    /**
     * Resolve the grant for a connected account.
     *
     * @return array{
     *     granted_scopes: list<string>,
     *     granted_types: list<string>,
     *     missing_types: list<string>
     * }
     */
    public function resolve(ConnectedAccount $account): array
    {
        // Service must be in the registry — if not, all empty
        if (! $this->registry->has($account->external_service)) {
            return [
                'granted_scopes' => [],
                'granted_types' => [],
                'missing_types' => [],
            ];
        }

        $service = $this->registry->resolve($account->external_service);
        $supportedTypes = $service->supportedTypes();
        $supportedTypeValues = $this->typeValues($supportedTypes);

        // Authorization may be absent (e.g., torn down but account not yet cleaned)
        // When absent, all supported types are missing.
        $authorization = $account->authorization;

        if (! $authorization) {
            return [
                'granted_scopes' => [],
                'granted_types' => [],
                'missing_types' => $supportedTypeValues,
            ];
        }

        // Parse scopes string → bundle slugs
        $scopesRaw = $authorization->scopes;

        if ($scopesRaw === null || trim($scopesRaw) === '') {
            return [
                'granted_scopes' => [],
                'granted_types' => [],
                'missing_types' => $supportedTypeValues,
            ];
        }

        $scopeParts = array_filter(array_map('trim', explode(',', $scopesRaw)));
        $grantedBundles = [];

        foreach ($scopeParts as $slug) {
            // Try to match as a ScopeBundle slug first
            try {
                $bundle = ScopeBundle::from($slug);
                $grantedBundles[] = $bundle;
            } catch (\ValueError) {
                // Unrecognised slug — drop silently
                continue;
            }
        }

        // Expand bundles to types
        $grantedTypeObjects = ScopeBundle::typesFor($grantedBundles, $supportedTypes);
        $grantedTypeValues = $this->typeValues($grantedTypeObjects);

        // Compute missing types
        $missingTypeValues = array_values(
            array_diff($supportedTypeValues, $grantedTypeValues)
        );

        return [
            'granted_scopes' => array_map(fn ($b) => $b->value, $grantedBundles),
            'granted_types' => $grantedTypeValues,
            'missing_types' => $missingTypeValues,
        ];
    }

    /**
     * Extract string values from an array of MeasurementType/SessionType enums.
     *
     * @param  array<\ClarionApp\LifeLogBackend\Vocabulary\MeasurementType|\ClarionApp\LifeLogBackend\Vocabulary\SessionType>  $types
     * @return list<string>
     */
    private function typeValues(array $types): array
    {
        return array_map(fn ($t) => $t->value, $types);
    }
}
