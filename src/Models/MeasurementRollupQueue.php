<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;

class MeasurementRollupQueue extends Model
{
    protected $table = 'life_log_measurement_rollup_queue';

    protected $fillable = [
        'user_id', 'external_service', 'type', 'unit', 'bucket_hour', 'deferred_reason',
    ];

    protected $casts = [
        'bucket_hour' => 'datetime',
    ];
}
