<?php

namespace ClarionApp\LifeLogBackend\Commands;

use Illuminate\Console\Command;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;

class RollupMeasurementsCommand extends Command
{
    protected $signature = 'life-log:rollup';
    protected $description = 'Roll up raw measurements into hourly HealthMetric summaries';

    public function handle(HourlyMeasurementRollup $rollup): int
    {
        $this->info('Starting measurement rollup...');

        $result = $rollup->run();

        $this->info(sprintf(
            'Rollup complete: %d buckets written, %d deferred',
            $result['written'],
            $result['deferred'],
        ));

        return self::SUCCESS;
    }
}
