<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The staging tier for span-based sessions.
 *
 * Non-bridged and not soft-deleted, for the same reason as RawMeasurement:
 * this tier is churn — rewritten whenever a service revises a session, and
 * pruned once promoted. Only the permanent tier is replicated.
 */
class RawHealthSession extends Model
{
    protected $table = 'life_log_raw_health_sessions';

    protected $fillable = [
        'user_id', 'external_service', 'external_id', 'session_type',
        'started_at', 'ended_at', 'summary_values', 'promoted_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'promoted_at' => 'datetime',
        'summary_values' => 'array',
    ];

    /**
     * The promotion scan: one indexed predicate, no join, no timestamp
     * comparison. A session is pending exactly when it has never been promoted
     * or has been rewritten since.
     */
    public function scopePendingPromotion(Builder $query): Builder
    {
        return $query->whereNull('promoted_at');
    }

    /**
     * Time-range retrieval, riding the (user_id, started_at) index.
     */
    public function scopeForUserBetween(Builder $query, $userId, $from, $to): Builder
    {
        return $query->where('user_id', $userId)
            ->whereBetween('started_at', [$from, $to])
            ->orderBy('started_at');
    }
}
