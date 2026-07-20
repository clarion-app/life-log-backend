<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;

/**
 * Per-type backfill progress for a connected account.
 *
 * Tracks how far backfill has progressed for each measurement type, the cursor
 * position for resuming, and whether the backfill is complete for that type.
 *
 * Not bridged (constitution §III — sync bookkeeping is not user data).
 * Each connected account has at most one row per type, enforced by
 * unique(connected_account_id, type).
 */
class AccountBackfillState extends Model
{
    protected $table = 'life_log_account_backfill_states';

    protected $fillable = [
        'connected_account_id',
        'type',
        'backfilled_to',
        'cursor',
        'cursor_since',
        'cursor_until',
        'complete_at',
        'completeness_determined_at',
        'requests_used',
        'last_error_kind',
    ];

    protected $casts = [
        'backfilled_to' => 'datetime',
        'cursor_since' => 'datetime',
        'cursor_until' => 'datetime',
        'complete_at' => 'datetime',
        'completeness_determined_at' => 'datetime',
        'requests_used' => 'integer',
    ];

    /**
     * The connected account this backfill state belongs to.
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id', 'id');
    }

    /**
     * Whether backfill is complete for this type.
     *
     * Complete when the provider has returned null cursor (true exhaustion)
     * and we've confirmed there is no more data.
     */
    public function isComplete(): bool
    {
        return $this->complete_at !== null;
    }

    /**
     * Mark this backfill as complete.
     */
    public function markComplete(): void
    {
        if (!$this->isComplete()) {
            $this->complete_at = now();
            $this->completeness_determined_at = now();
            $this->save();
        }
    }

    /**
     * Update the cursor position after a fetch.
     *
     * @param  string|null  $cursor  The cursor string for the next page.
     * @param  string|null  $cursorSince  The since value this cursor was issued for.
     * @param  string|null  $cursorUntil  The until value this cursor was issued for.
     */
    public function updateCursor(?string $cursor, ?string $cursorSince = null, ?string $cursorUntil = null): void
    {
        $this->cursor = $cursor;
        $this->cursor_since = $cursorSince;
        $this->cursor_until = $cursorUntil;
        $this->save();
    }

    /**
     * Increment the requests used counter.
     */
    public function incrementRequestsUsed(int $n = 1): void
    {
        $this->requests_used = ($this->requests_used ?? 0) + $n;
        $this->save();
    }

    /**
     * Record the last error kind.
     */
    public function recordError(?string $errorKind): void
    {
        $this->last_error_kind = $errorKind;
        $this->save();
    }
}
