<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;

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
     * The authorization row for this account (created at connection, replaced on reconnect).
     */
    public function authorization(): HasOne
    {
        return $this->hasOne(AccountAuthorization::class, 'connected_account_id', 'id');
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
            $state->needs_attention_reason = null;
            // synced_through_at preserved intentionally
            $state->save();
        }
    }

    /**
     * The reason this connection needs attention, or null if healthy.
     *
     * A management-side reason (credential_rotated, credential_removed, etc.)
     * outranks the last provider failure — "reconnect, the credential changed"
     * is more actionable than "credentials_rejected", which is only its symptom.
     */
    public function needsAttentionReason(): ?string
    {
        if ($this->sync_state !== 'needs_attention') {
            return null;
        }

        $state = $this->syncState;

        return $state?->needs_attention_reason
            ?? $state?->last_failure_kind
            ?? 'unknown';
    }

    /**
     * All backfill states for this account (one per type).
     */
    public function backfillStates(): HasMany
    {
        return $this->hasMany(AccountBackfillState::class, 'connected_account_id', 'id');
    }

    /**
     * The backfill state for a specific type, or null if not yet tracked.
     *
     * @param  MeasurementType|SessionType  $type
     */
    public function backfillState(MeasurementType|SessionType $type): ?AccountBackfillState
    {
        return $this->backfillStates()
            ->where('type', $type->value)
            ->first();
    }
}
