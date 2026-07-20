<?php

namespace ClarionApp\LifeLogBackend;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LifeLogBackend\Commands\PruneConnectionAttemptsCommand;
use ClarionApp\LifeLogBackend\Commands\PruneRawMeasurementsCommand;
use ClarionApp\LifeLogBackend\Commands\PruneRawSessionsCommand;
use ClarionApp\LifeLogBackend\Commands\PruneSyncAttemptsCommand;
use ClarionApp\LifeLogBackend\Commands\PromoteSessionsCommand;
use ClarionApp\LifeLogBackend\Commands\RollupMeasurementsCommand;
use ClarionApp\LifeLogBackend\Commands\SyncAccountCommand;
use ClarionApp\LifeLogBackend\Commands\SyncAccountsCommand;
use ClarionApp\LifeLogBackend\Commands\SyncVocabularyClassificationsCommand;
use ClarionApp\LifeLogBackend\Connection\ConnectionAttemptFactory;
use ClarionApp\LifeLogBackend\Connection\ConnectionAttemptVerifier;
use ClarionApp\LifeLogBackend\Connection\ConnectionCompleter;
use ClarionApp\LifeLogBackend\Connection\RedirectUriValidator;
use ClarionApp\LifeLogBackend\Credentials\CredentialVerifier;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Services\RawSessionWriter;
use ClarionApp\LifeLogBackend\Services\SessionPromoter;
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use ClarionApp\LifeLogBackend\Sync\AccountSyncRunner;
use ClarionApp\LifeLogBackend\Sync\FailurePolicy;
use ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder;
use ClarionApp\LifeLogBackend\Sync\SyncLock;
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

        // Account sync infrastructure
        $this->app->singleton(FailurePolicy::class);
        $this->app->singleton(SyncLock::class);
        $this->app->singleton(SyncAttemptRecorder::class);
        $this->app->singleton(AccountSyncRunner::class);

        // Single registration point for external health services. Implementations
        // register themselves against this instance from their own providers, so
        // adding a service touches no file in this package.
        $this->app->singleton(HealthServiceRegistry::class);

        // Per-request memoisation: ServiceCredentialProvider caches lookups for
        // the current request/job, so queue workers see rotated credentials between
        // jobs. A singleton would leak the cache across requests in web context,
        // and a fresh instance per injection would defeat memoisation entirely.
        $this->app->scoped(ServiceCredentialProvider::class);

        // Redirect URI validation and credential verification
        $this->app->singleton(RedirectUriValidator::class);
        $this->app->singleton(CredentialVerifier::class);

        // Connection flow infrastructure
        $this->app->singleton(ConnectionAttemptFactory::class);
        $this->app->singleton(ConnectionAttemptVerifier::class);
        $this->app->singleton(ConnectionCompleter::class);
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
                SyncAccountsCommand::class,
                SyncAccountCommand::class,
                PruneSyncAttemptsCommand::class,
                PruneConnectionAttemptsCommand::class,
            ]);

            // Schedule hourly rollup, daily pruning, and hourly sync sweep
            $this->app->booted(function () {
                Schedule::command('life-log:rollup')->hourly()->withoutOverlapping();
                Schedule::command('life-log:prune-raw-measurements')->daily()->withoutOverlapping();
                Schedule::command('life-log:promote-sessions')->hourly()->withoutOverlapping();
                Schedule::command('life-log:prune-raw-sessions')->daily()->withoutOverlapping();
                Schedule::command('life-log:sync-accounts')->hourly()->withoutOverlapping();
                Schedule::command('life-log:prune-sync-attempts')->daily()->withoutOverlapping();
                Schedule::command('life-log:prune-connection-attempts')->hourly()->withoutOverlapping();
            });
        }

        // Assert at boot that sync_lock_seconds exceeds the job timeout.
        // A slow run's lock expires beneath it and a second run starts against
        // the same cursor, which violates the checkpoint invariant.
        $lockSeconds = (int) config('life-log.sync_lock_seconds', 900);
        if ($lockSeconds <= SyncConnectedAccountJob::TIMEOUT) {
            throw new \RuntimeException(
                sprintf(
                    'life-log.sync_lock_seconds (%d) must exceed SyncConnectedAccountJob::TIMEOUT (%d). '
                    . 'A slow run would lose its lock before it finishes.',
                    $lockSeconds,
                    SyncConnectedAccountJob::TIMEOUT,
                )
            );
        }
    }
}