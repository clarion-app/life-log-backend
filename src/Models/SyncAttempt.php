<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncAttempt extends Model
{
    protected $table = 'life_log_sync_attempts';

    protected $fillable = [
        'connected_account_id',
        'user_id',
        'external_service',
        'trigger',
        'outcome',
        'range_since',
        'range_until',
        'pages_fetched',
        'measurements_written',
        'sessions_written',
        'failure_kind',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'range_since' => 'datetime',
        'range_until' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The connected account this attempt belongs to.
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id', 'id');
    }
}
