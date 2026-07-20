<?php

namespace ClarionApp\LifeLogBackend;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Services\RawSessionWriter;
use ClarionApp\LifeLogBackend\Services\SessionPromoter;
use ClarionApp\LifeLogBackend\Commands\RollupMeasurementsCommand;
use ClarionApp\LifeLogBackend\Commands\PruneRawMeasurementsCommand;
use ClarionApp\LifeLogBackend\Commands\PromoteSessionsCommand;
use ClarionApp\LifeLogBackend\Commands\PruneRawSessionsCommand;
use ClarionApp\LifeLogBackend\Commands\SyncVocabularyClassificationsCommand;
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use Illuminate\Support\Facades\Schedule;

class LifeLogBackendServiceProvider extends ClarionPackageServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->singleton(RawMeasurementWriter::class);
        $this->app->singleton(HourlyMeasurementRollup::class);
        $this->app->singleton(UnmappedTypeRecorder::class);
        $this->app->singleton(RawSessionWriter::class);
        $this->app->singleton(SessionPromoter::class);

        // Single registration point for external health services. Implementations
        // register themselves against this instance from their own providers, so
        // adding a service touches no file in this package.
        $this->app->singleton(HealthServiceRegistry::class);
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
                SyncVocabularyClassificationsCommand::class,
                PromoteSessionsCommand::class,
                PruneRawSessionsCommand::class,
            ]);

            // Schedule hourly rollup and daily pruning without overlapping
            $this->app->booted(function () {
                Schedule::command('life-log:rollup')->hourly()->withoutOverlapping();
                Schedule::command('life-log:prune-raw-measurements')->daily()->withoutOverlapping();
                Schedule::command('life-log:promote-sessions')->hourly()->withoutOverlapping();
                Schedule::command('life-log:prune-raw-sessions')->daily()->withoutOverlapping();
            });
        }
    }
}