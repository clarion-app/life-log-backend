<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use ClarionApp\LifeLogBackend\LifeLogBackendServiceProvider;
use Tests\Support\RecordingMultiChain;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LifeLogBackendServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Disable bridge by default — tests opt in via enableRecordingBridge()
        $app['config']->set('eloquent-multichain-bridge.disabled', true);

        // Configure auth for tests (session driver for actingAs() compatibility)
        $app['config']->set('auth.defaults.guard', 'api');
        $app['config']->set('auth.guards.api', [
            'driver'   => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model'  => \ClarionApp\Backend\Models\User::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        // Stub App\Http\Controllers\Controller
        if (!class_exists('App\Http\Controllers\Controller')) {
            eval('namespace App\Http\Controllers { class Controller { } }');
        }

        // Stub multichain service (no-op by default)
        $app->singleton('multichain', function () {
            $stub = new class {
                public function __call($method, $arguments) { return null; }
                public function publish($stream, $key, $value) { return 'stub-txid'; }
                public function liststreams($stream) { throw new \Exception('not found'); }
                public function create($type, $name, $private) { return null; }
                public function subscribe($stream) { return null; }
            };
            return $stub;
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        // users table
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // data_stream_registries (for bridge stream resolution)
        // DataStreamRegistry model uses EloquentMultiChainBridge which includes SoftDeletes
        if (!Schema::hasTable('data_stream_registries')) {
            Schema::create('data_stream_registries', function (Blueprint $table) {
                $table->id();
                $table->string('class_name')->unique();
                $table->string('data_stream');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // life_log_health_metrics (post-migration shape with provenance columns)
        if (!Schema::hasTable('life_log_health_metrics')) {
            Schema::create('life_log_health_metrics', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('type');
                $table->string('source', 64)->default('manual');
                $table->string('unit', 32)->nullable();
                $table->string('external_service', 64)->nullable();
                $table->timestamp('bucket_hour')->nullable();
                $table->json('metadata')->nullable();
                $table->decimal('value', 16, 4);
                $table->timestamp('recorded_at');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // life_log_raw_measurements
        if (!Schema::hasTable('life_log_raw_measurements')) {
            Schema::create('life_log_raw_measurements', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('user_id');
                $table->string('external_service', 64);
                $table->string('external_id', 191);
                $table->string('type', 64);
                $table->decimal('value', 16, 4);
                $table->string('unit', 32)->default('');
                $table->timestamp('recorded_at');
                $table->timestamp('bucket_hour');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['external_service', 'external_id']);
                $table->index(['user_id', 'bucket_hour']);
                $table->index('bucket_hour');
            });
        }

        // life_log_measurement_type_classifications
        if (!Schema::hasTable('life_log_measurement_type_classifications')) {
            Schema::create('life_log_measurement_type_classifications', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('type', 64)->unique();
                $table->string('aggregation', 16);
                $table->timestamps();
            });
        }

        // life_log_measurement_rollup_queue
        if (!Schema::hasTable('life_log_measurement_rollup_queue')) {
            Schema::create('life_log_measurement_rollup_queue', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('user_id');
                $table->string('external_service', 64);
                $table->string('type', 64);
                $table->string('unit', 32)->default('');
                $table->timestamp('bucket_hour');
                $table->string('deferred_reason', 32)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'external_service', 'type', 'unit', 'bucket_hour']);
            });
        }

        // life_log_raw_health_sessions
        if (!Schema::hasTable('life_log_raw_health_sessions')) {
            Schema::create('life_log_raw_health_sessions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('user_id');
                $table->string('external_service', 64);
                $table->string('external_id', 191);
                $table->string('session_type', 64);
                $table->timestamp('started_at');
                $table->timestamp('ended_at');
                $table->json('summary_values');
                $table->timestamp('promoted_at')->nullable();
                $table->timestamps();

                $table->unique(['external_service', 'external_id']);
                $table->index(['user_id', 'started_at']);
                $table->index('promoted_at');
            });
        }

        // life_log_health_sessions (bridged; source has no default by design)
        if (!Schema::hasTable('life_log_health_sessions')) {
            Schema::create('life_log_health_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('external_service', 64);
                $table->string('external_id', 191);
                $table->string('session_type', 64);
                $table->timestamp('started_at');
                $table->timestamp('ended_at');
                $table->json('summary_values');
                $table->string('source', 64);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['external_service', 'external_id']);
                $table->index(['user_id', 'started_at']);
            });
        }

        // life_log_unmapped_type_records
        if (!Schema::hasTable('life_log_unmapped_type_records')) {
            Schema::create('life_log_unmapped_type_records', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_service', 64);
                $table->string('service_type_name', 191);
                $table->string('sample_value', 64)->nullable();
                $table->string('sample_unit', 32)->nullable();
                $table->timestamp('first_seen_at');
                $table->timestamp('last_seen_at');
                $table->unsignedBigInteger('occurrence_count')->default(1);
                $table->timestamps();

                $table->unique(['external_service', 'service_type_name']);
            });
        }

        // life_log_connected_accounts
        if (!Schema::hasTable('life_log_connected_accounts')) {
            Schema::create('life_log_connected_accounts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('external_service', 64);
                $table->string('sync_state', 32)->default('normal');
                $table->timestamp('connected_at')->useCurrent();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['user_id', 'external_service']);
                $table->index('sync_state');
            });
        }

        // life_log_account_sync_states
        if (!Schema::hasTable('life_log_account_sync_states')) {
            Schema::create('life_log_account_sync_states', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('connected_account_id');
                $table->timestamp('synced_through_at')->nullable();
                $table->text('cursor')->nullable();
                $table->timestamp('cursor_since')->nullable();
                $table->timestamp('cursor_until')->nullable();
                $table->unsignedTinyInteger('consecutive_failures')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->string('last_failure_kind', 32)->nullable();
                $table->string('needs_attention_reason', 32)->nullable();
                $table->timestamps();

                $table->unique('connected_account_id');
                $table->index('next_attempt_at');
            });
        }

        // life_log_sync_attempts
        if (!Schema::hasTable('life_log_sync_attempts')) {
            Schema::create('life_log_sync_attempts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('connected_account_id');
                $table->uuid('user_id');
                $table->string('external_service', 64);
                $table->string('trigger', 16);
                $table->string('outcome', 16);
                $table->timestamp('range_since');
                $table->timestamp('range_until');
                $table->unsignedInteger('pages_fetched')->default(0);
                $table->unsignedInteger('measurements_written')->default(0);
                $table->unsignedInteger('sessions_written')->default(0);
                $table->string('failure_kind', 32)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['connected_account_id', 'started_at']);
                $table->index('started_at');
            });
        }

        // life_log_service_credentials
        if (!Schema::hasTable('life_log_service_credentials')) {
            Schema::create('life_log_service_credentials', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('external_service', 64);
                $table->string('client_id', 255);
                $table->text('client_secret');
                $table->string('redirect_uri', 512);
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('secret_updated_at')->useCurrent();
                $table->timestamp('last_verified_at')->nullable();
                $table->string('last_verification_outcome', 32)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['external_service', 'deleted_at']);
            });
        }

        // life_log_connection_attempts
        if (!Schema::hasTable('life_log_connection_attempts')) {
            Schema::create('life_log_connection_attempts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('external_service', 64);
                $table->char('state_hash', 64)->unique();
                $table->string('redirect_uri', 512);
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index('expires_at');
                $table->index(['user_id', 'external_service']);
            });
        }

        // life_log_account_authorizations
        if (!Schema::hasTable('life_log_account_authorizations')) {
            Schema::create('life_log_account_authorizations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('connected_account_id');
                $table->text('access_token');
                $table->text('refresh_token')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->text('scopes')->nullable();
                $table->unsignedInteger('credential_version');
                $table->timestamp('refreshed_at')->nullable();
                $table->timestamps();

                $table->foreign('connected_account_id')
                    ->references('id')
                    ->on('life_log_connected_accounts')
                    ->onDelete('cascade');
                $table->unique('connected_account_id');
            });
        }
    }

    /**
     * Project the measurement vocabulary into the classification table.
     *
     * Opt-in rather than automatic: tests that exercise the rollup against
     * deliberately unclassified or free-form types must be able to start from an
     * empty classification table.
     */
    public function syncVocabulary(): void
    {
        $this->artisan('life-log:sync-vocabulary');
    }

    /**
     * Enable the recording bridge spy.
     *
     * Binds RecordingMultiChain as the 'multichain' singleton,
     * sets eloquent-multichain-bridge.disabled = false,
     * and seeds the data_stream_registries row for HealthMetric.
     */
    public function enableRecordingBridge(): RecordingMultiChain
    {
        $spy = new RecordingMultiChain();
        $this->app->singleton('multichain', function () use ($spy) {
            return $spy;
        });
        config(['eloquent-multichain-bridge.disabled' => false]);

        // Seed data_stream_registries for the bridged models so getModelStream() resolves
        DB::table('data_stream_registries')->insertOrIgnore([
            [
                'class_name' => \ClarionApp\LifeLogBackend\Models\HealthMetric::class,
                'data_stream' => 'life_log_health_metrics',
            ],
            [
                'class_name' => \ClarionApp\LifeLogBackend\Models\HealthSession::class,
                'data_stream' => 'life_log_health_sessions',
            ],
        ]);

        return $spy;
    }
}
