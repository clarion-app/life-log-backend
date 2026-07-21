<?php

namespace ClarionApp\LifeLogBackend\Events;

use ClarionApp\LifeLogBackend\Connection\GrantedScopeResolver;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast event fired when a connected account's status changes.
 *
 * Implements ShouldBroadcastNow (not ShouldBroadcast) because this event
 * is dispatched from inside a queued sync job. The payload is a full
 * snapshot identical to the index entry, so a duplicate delivery is harmless.
 *
 * FR-021: The event carries the same fields as GET /connected-accounts entries.
 * FR-021a: Broadcast on private-User.{id} for the owning user only.
 * SC-004: No secrets in the broadcast payload.
 */
class ConnectedAccountStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private ConnectedAccount $account,
        private ?GrantedScopeResolver $grantedScopeResolver = null,
    ) {
    }

    public function account(): ConnectedAccount
    {
        return $this->account;
    }

    /**
     * The channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('User.' . $this->account->user_id),
        ];
    }

    /**
     * The data that should be delivered to the frontend.
     *
     * This is a full snapshot (key-for-key identical to an index entry),
     * not a delta. A duplicate delivery is harmless — the frontend
     * replaces the cached entry in place.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $resolver = $this->grantedScopeResolver
            ?? app(GrantedScopeResolver::class);

        return $this->project($this->account, $resolver);
    }

    /**
     * Shared projection — identical to ConnectedAccountController::project().
     *
     * @param  GrantedScopeResolver  $resolver
     * @return array<string, mixed>
     */
    private function project(ConnectedAccount $account, GrantedScopeResolver $resolver): array
    {
        $state = $account->syncState;
        $grants = $resolver->resolve($account);

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
