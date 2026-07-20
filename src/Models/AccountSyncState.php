<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountSyncState extends Model
{
    protected $table = 'life_log_account_sync_states';

    protected $fillable = [
        'connected_account_id',
        'synced_through_at',
        'cursor',
        'cursor_since',
        'cursor_until',
        'consecutive_failures',
        'next_attempt_at',
        'last_success_at',
        'last_failure_at',
        'last_failure_kind',
    ];

    protected $casts = [
        'synced_through_at' => 'datetime',
        'cursor_since' => 'datetime',
        'cursor_until' => 'datetime',
        'next_attempt_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The connected account this state belongs to.
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id', 'id');
    }
}
