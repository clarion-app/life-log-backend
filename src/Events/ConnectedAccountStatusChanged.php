<?php

namespace ClarionApp\LifeLogBackend\Events;

use ClarionApp\LifeLogBackend\Connection\ConnectionProjector;
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
        private ?ConnectionProjector $projector = null,
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
        // The same ConnectionProjector the controller uses — one projection,
        // so a pushed connection cannot disagree with a fetched one.
        $projector = $this->projector ?? app(ConnectionProjector::class);

        return $projector->project($this->account);
    }
}
