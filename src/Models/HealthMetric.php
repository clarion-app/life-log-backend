<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

class HealthMetric extends Model
{
    use EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'life_log_health_metrics';
}
