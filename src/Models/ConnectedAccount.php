<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

class ConnectedAccount extends Model
{
    use EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'life_log_connected_accounts';

    protected $fillable = [
        'user_id',
        'external_service',
        'sync_state',
        'connected_at',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
    ];

    /**
     * The sync state row for this account (lazy-created on first sync).
     */
    public function syncState(): HasOne
    {
        return $this->hasOne(AccountSyncState::class, 'connected_account_id', 'id');
    }

    /**
     * Reset sync health for a reconnect or manual reset.
     *
     * Sets sync_state to normal, zeroes the counter, clears gate + cursor triple,
     * and preserves synced_through_at (FR-014) — a user reconnecting after an
     * outage gets a run covering those days, not their entire history.
     */
    public function resetSyncHealth(): void
    {
        $this->sync_state = 'normal';
        $this->save();

        if ($this->syncState) {
            $state = $this->syncState;
            $state->consecutive_failures = 0;
            $state->next_attempt_at = null;
            $state->cursor = null;
            $state->cursor_since = null;
            $state->cursor_until = null;
            // synced_through_at preserved intentionally
            $state->save();
        }
    }
}
