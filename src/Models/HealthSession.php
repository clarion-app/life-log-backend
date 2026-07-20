<?php

namespace ClarionApp\LifeLogBackend\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Permanent session history: one row per external session, replicated to the
 * chain.
 *
 * A session is never hour-bucketed. One night's sleep crossing midnight is one
 * row spanning both days, because splitting it would destroy the only fact the
 * record carries — how long it actually ran.
 *
 * `source` names the originating service and has no database default: every row
 * here comes from an import, so defaulting it to 'manual' would misattribute
 * every one of them.
 */
class HealthSession extends Model
{
    use EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'life_log_health_sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'external_service', 'external_id', 'session_type',
        'started_at', 'ended_at', 'summary_values', 'source',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'summary_values' => 'array',
    ];

    public function scopeForUserBetween(Builder $query, $userId, $from, $to): Builder
    {
        return $query->where('user_id', $userId)
            ->whereBetween('started_at', [$from, $to])
            ->orderBy('started_at');
    }
}
