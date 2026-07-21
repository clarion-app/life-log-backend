<?php

namespace ClarionApp\LifeLogBackend;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LifeLogBackend\Commands\BackfillAccountCommand;
use ClarionApp\LifeLogBackend\Commands\BackfillAccountsCommand;
use ClarionApp\LifeLogBackend\Commands\ProbeNotWornExclusionCommand;
use ClarionApp\LifeLogBackend\Commands\PruneConnectionAttemptsCommand;
use ClarionApp\LifeLogBackend\Commands\PruneRawMeasurementsCommand;
use ClarionApp\LifeLogBackend\Commands\PruneRawSessionsCommand;
use ClarionApp\LifeLogBackend\Commands\PruneSyncAttemptsCommand;
use ClarionApp\LifeLogBackend\Commands\PromoteMeasurementsCommand;
use ClarionApp\LifeLogBackend\Commands\PromoteSessionsCommand;
use ClarionApp\LifeLogBackend\Commands\RollupMeasurementsCommand;
use ClarionApp\LifeLogBackend\Commands\SyncAccountCommand;
use ClarionApp\LifeLogBackend\Commands\SyncAccountsCommand;
use ClarionApp\LifeLogBackend\Commands\SyncVocabularyClassificationsCommand;
use ClarionApp\LifeLogBackend\Connection\AccountDisconnector;
use ClarionApp\LifeLogBackend\Connection\ConnectionAttemptFactory;
use ClarionApp\LifeLogBackend\Connection\ConnectionAttemptVerifier;
use ClarionApp\LifeLogBackend\Connection\ConnectionCompleter;
use ClarionApp\LifeLogBackend\Connection\RedirectUriValidator;
use ClarionApp\LifeLogBackend\Credentials\CredentialVerifier;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Google\Oauth\GoogleOauthFlow;
use ClarionApp\LifeLogBackend\Jobs\BackfillConnectedAccountJob;
use ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob;
use GuzzleHttp\Client;
use ClarionApp\LifeLogBackend\Services\DirectMeasurementPromoter;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Services\RawSessionWriter;
use ClarionApp\LifeLogBackend\Services\SessionPromoter;
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use ClarionApp\LifeLogBackend\Sync\AccountSyncRunner;
use ClarionApp\LifeLogBackend\Sync\BackfillRunner;
use ClarionApp\LifeLogBackend\Sync\FailurePolicy;
use ClarionApp\LifeLogBackend\Sync\SyncAttemptRecorder;
use ClarionApp\LifeLogBackend\Sync\SyncLock;
use ClarionApp\LifeLogBackend\Sync\TokenRefreshCoordinator;
use Illuminate\Support\Facades\Schedule;

class LifeLogBackendServiceProvider extends ClarionPackageServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->singleton(RawMeasurementWriter::class);
        $this->app->singleton(HourlyMeasurementRollup::class);
        $this->app->singleton(DirectMeasurementPromoter::class);
        $this->app->singleton(UnmappedTypeRecorder::class);
        $this->app->singleton(RawSessionWriter::class);
        $this->app->singleton(SessionPromoter::class);

        // Account sync infrastructure
        //
        // FailurePolicy and AccountSyncRunner are bound scoped, not
        // singleton: both now read ServiceCredentialProvider (directly, or
        // through TokenRefreshCoordinator's persisted authorization), which
        // is itself scoped precisely so a queue worker observes a credential
        // rotation between jobs (see the note below). A singleton here would
        // capture one ServiceCredentialProvider instance — and its per-service
        // memoisation cache — for the life of the worker process, silently
        // reintroducing the staleness obligation 7 exists to prevent.
        $this->app->scoped(FailurePolicy::class);
        $this->app->singleton(SyncLock::class);
        $this->app->singleton(SyncAttemptRecorder::class);
        $this->app->scoped(TokenRefreshCoordinator::class);
        $this->app->scoped(AccountSyncRunner::class);
        $this->app->scoped(BackfillRunner::class);

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
        $this->app->singleton(AccountDisconnector::class);

        // Google Health integration
        // Binds the OAuth flow and registers 'google-health' through the registry.
        // Honours an optional ScriptedGoogleTransport container binding when present
        // (detected via the 'guzzle' singleton already being bound with a mock handler).
        $this->app->singleton(GoogleOauthFlow::class, function ($app) {
            $client = $app->bound(Client::class)
                ? $app->make(Client::class)
                : new Client();
            return new GoogleOauthFlow($app->make(ServiceCredentialProvider::class), $client);
        });
    }

    public function boot(): void
    {
        // Register 'google-health' through HealthServiceRegistry (not around it).
        // This keeps the out-of-package registration path (StubBandServiceProvider) live.
        $this->app->make(HealthServiceRegistry::class)->register(
            GoogleHealthService::NAME,
            fn () => new GoogleHealthService(
                $this->app->make(GoogleOauthFlow::class),
                // Honour an optional transport binding (the scripted seam under
                // test) here too — without it the fetch path builds its own
                // client and reaches the real network.
                $this->app->bound(Client::class) ? $this->app->make(Client::class) : null,
            ),
        );

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
                PromoteMeasurementsCommand::class,
                PruneRawMeasurementsCommand::class,
                SyncVocabularyClassificationsCommand::class,
                PromoteSessionsCommand::class,
                PruneRawSessionsCommand::class,
                SyncAccountsCommand::class,
                SyncAccountCommand::class,
                BackfillAccountsCommand::class,
                BackfillAccountCommand::class,
                PruneSyncAttemptsCommand::class,
                PruneConnectionAttemptsCommand::class,
                ProbeNotWornExclusionCommand::class,
            ]);

            // Schedule hourly rollup, daily pruning, and hourly sync sweep
            $this->app->booted(function () {
                Schedule::command('life-log:rollup')->hourly()->withoutOverlapping();
                Schedule::command('life-log:promote-measurements')->hourly()->withoutOverlapping();
                Schedule::command('life-log:prune-raw-measurements')->daily()->withoutOverlapping();
                Schedule::command('life-log:promote-sessions')->hourly()->withoutOverlapping();
                Schedule::command('life-log:prune-raw-sessions')->daily()->withoutOverlapping();
                Schedule::command('life-log:sync-accounts')->hourly()->withoutOverlapping();
                Schedule::command('life-log:backfill-accounts')->everyFifteenMinutes()->withoutOverlapping();
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