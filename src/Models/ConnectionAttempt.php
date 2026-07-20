<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A connection a user started but has not completed.
 *
 * Single-use, one hour. Not bridged (ephemeral by definition).
 */
class ConnectionAttempt extends Model
{
    protected $table = 'life_log_connection_attempts';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'external_service',
        'state_hash',
        'redirect_uri',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (! $model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Whether this attempt is still usable (not consumed, not expired).
     *
     * This is advisory only. Consumption is the atomic UPDATE in
     * ConnectionAttemptVerifier; this method exists for readable pre-checks
     * and must never be the sole gate.
     */
    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
