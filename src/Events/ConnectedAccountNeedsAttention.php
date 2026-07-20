<?php

namespace ClarionApp\LifeLogBackend\Events;

use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a connected account hits the consecutive failure threshold
 * and is flagged needs_attention (FR-012).
 */
class ConnectedAccountNeedsAttention
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
    ) {
    }
}
