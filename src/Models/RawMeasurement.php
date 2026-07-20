<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class RawMeasurement extends Model
{
    // Non-bridged raw store — no EloquentMultiChainBridge, no SoftDeletes
    // Constitution §III: high-frequency exclusion from chain replication

    protected $table = 'life_log_raw_measurements';

    protected $fillable = [
        'user_id', 'external_service', 'external_id', 'type', 'value',
        'unit', 'recorded_at', 'bucket_hour', 'metadata',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'recorded_at' => 'datetime',
        'bucket_hour' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        // T062: deleted event re-marks the affected bucket dirty
        static::deleted(function ($measurement) {
            \ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue::updateOrCreate(
                [
                    'user_id' => $measurement->user_id,
                    'external_service' => $measurement->external_service,
                    'type' => $measurement->type,
                    'unit' => $measurement->unit,
                    'bucket_hour' => $measurement->bucket_hour,
                ],
                [],
            );
        });
    }

    /**
     * The promotion scan: one indexed predicate, no join, no timestamp
     * comparison. A measurement is pending exactly when it has never been
     * promoted or has been rewritten since.
     */
    public function scopePendingPromotion(Builder $query): Builder
    {
        return $query->whereNull('promoted_at');
    }

    /**
     * FR-006 time-range retrieval scope.
     * Rides the (user_id, bucket_hour) index.
     */
    public function scopeForUserBetween(Builder $query, $userId, $from, $to): Builder
    {
        return $query->where('user_id', $userId)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at');
    }
}
