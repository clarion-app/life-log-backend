<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

class HealthMetric extends Model
{
    use EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'life_log_health_metrics';

    protected $fillable = [
        'user_id', 'type', 'value', 'recorded_at', 'source', 'unit',
        'external_service', 'bucket_hour', 'metadata',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'recorded_at' => 'datetime',
        'bucket_hour' => 'datetime',
        'metadata' => 'array',
    ];
}
