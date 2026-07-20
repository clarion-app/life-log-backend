<?php

namespace ClarionApp\LifeLogBackend\Commands;

use ClarionApp\LifeLogBackend\Services\DirectMeasurementPromoter;
use Illuminate\Console\Command;

class PromoteMeasurementsCommand extends Command
{
    protected $signature = 'life-log:promote-measurements';
    protected $description = 'Promote Direct-mode raw measurements into permanent, replicated HealthMetric records';

    public function handle(DirectMeasurementPromoter $promoter): int
    {
        $this->info('Starting direct measurement promotion...');

        $result = $promoter->run();

        $this->info(sprintf(
            'Promotion complete: %d measurements promoted, %d skipped',
            $result['promoted'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
