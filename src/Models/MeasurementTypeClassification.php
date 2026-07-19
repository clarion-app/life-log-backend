<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;

class MeasurementTypeClassification extends Model
{
    protected $table = 'life_log_measurement_type_classifications';

    protected $fillable = [
        'type', 'aggregation',
    ];

    const AGGREGATION_CUMULATIVE = 'cumulative';
    const AGGREGATION_POINT_IN_TIME = 'point_in_time';
}
