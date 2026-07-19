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

        // Seed data_stream_registries for HealthMetric so getModelStream() resolves
        DB::table('data_stream_registries')->insertOrIgnore([
            'class_name' => \ClarionApp\LifeLogBackend\Models\HealthMetric::class,
            'data_stream' => 'life_log_health_metrics',
        ]);

        return $spy;
    }
}
