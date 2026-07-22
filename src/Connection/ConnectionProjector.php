<?php

namespace ClarionApp\LifeLogBackend\Connection;

use ClarionApp\LifeLogBackend\Models\ConnectedAccount;

/**
 * The single projection of a ConnectedAccount for the interface.
 *
 * `GET /connected-accounts`, `GET /connected-accounts/{id}`, and the
 * ConnectedAccountStatusChanged broadcast payload are byte-identical in
 * shape because they are all this method. A pushed connection cannot
 * disagree with a fetched one, and a field added here reaches every path
 * at once.
 *
 * The key set is an allow-list: no token, no secret, no credential_version.
 */
final class ConnectionProjector
{
    public function __construct(
        private GrantedScopeResolver $grantedScopeResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function project(ConnectedAccount $account): array
    {
        $state = $account->syncState;
        $grants = $this->grantedScopeResolver->resolve($account);

        return [
            'id' => $account->id,
            'external_service' => $account->external_service,
            'status' => $account->sync_state === 'needs_attention'
                ? 'needs_attention'
                : 'healthy',
            'last_successful_sync_at' => $state?->last_success_at,
            'connected_at' => $account->connected_at,
            'needs_attention_reason' => $account->needsAttentionReason(),
            'granted_scopes' => $grants['granted_scopes'],
            'granted_types' => $grants['granted_types'],
            'missing_types' => $grants['missing_types'],
        ];
    }
}
