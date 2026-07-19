<?php

namespace ClarionApp\LifeLogBackend;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Commands\RollupMeasurementsCommand;
use ClarionApp\LifeLogBackend\Commands\PruneRawMeasurementsCommand;
use Illuminate\Support\Facades\Schedule;

class LifeLogBackendServiceProvider extends ClarionPackageServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->singleton(RawMeasurementWriter::class);
        $this->app->singleton(HourlyMeasurementRollup::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../config/life-log.php', 'life-log');
        $this->publishes([
            __DIR__.'/../config/life-log.php' => 'config/life-log.php',
        ]);

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                RollupMeasurementsCommand::class,
                PruneRawMeasurementsCommand::class,
            ]);

            // Schedule hourly rollup and daily pruning without overlapping
            $this->app->booted(function () {
                Schedule::command('life-log:rollup')->hourly()->withoutOverlapping();
                Schedule::command('life-log:prune-raw-measurements')->daily()->withoutOverlapping();
            });
        }
    }
}