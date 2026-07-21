<?php

namespace Tests\Integration;

use ClarionApp\Backend\ClarionBackendServiceProvider;
use ClarionApp\LifeLogBackend\LifeLogBackendServiceProvider;
use Illuminate\Support\Facades\Broadcast;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * T007: FR-021a — UUID-safe private-channel authorization.
 *
 * User A's authenticated POST /broadcasting/auth for private-User.{B->id} must be
 * refused (403), and for private-User.{A->id} must succeed (200).
 *
 * Four harness requirements (all were discovered during the analysis spike):
 * 1. Fixed ids that both cast to 0 via (int) — so the vulnerable code collides.
 * 2. ClarionBackendServiceProvider before LifeLogBackendServiceProvider — Routes.php lives there.
 * 3. Reset the guard to session driver inside the test body — boot() forces passport.
 * 4. Use pusher broadcaster — NullBroadcaster/LogBroadcaster auth() are no-ops.
 */
class UserChannelAuthorizationTest extends BaseTestCase
{
    private const USER_A_ID = 'a1111111-1111-4111-8111-111111111111';
    private const USER_B_ID = 'f2222222-2222-4222-8222-222222222222';

    protected function getPackageProviders($app): array
    {
        // Requirement 2: ClarionBackendServiceProvider loads Routes.php (Broadcast::channel)
        return [
            ClarionBackendServiceProvider::class,
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

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // Requirement 4: Use pusher broadcaster so auth() actually calls the predicate
        $app['config']->set('broadcasting.default', 'pusher');
        $app['config']->set('broadcasting.connections.pusher', [
            'driver'  => 'pusher',
            'key'     => 'test-pushers-key',
            'secret'  => 'test-pushers-secret',
            'app_id'  => '999999',
            'options' => ['cluster' => 'mt1', 'useTLS' => true],
        ]);

        // Auth defaults — session driver for actingAs() compatibility
        $app['config']->set('auth.defaults.guard', 'api');
        $app['config']->set('auth.guards.api', [
            'driver'   => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model'  => \ClarionApp\Backend\Models\User::class,
        ]);

        // Disable bridge
        $app['config']->set('eloquent-multichain-bridge.disabled', true);
    }

    protected function defineEnvironment($app): void
    {
        if (!class_exists('App\Http\Controllers\Controller')) {
            eval('namespace App\Http\Controllers { class Controller { } }');
        }

        $app->singleton('multichain', function () {
            return new class {
                public function __call($method, $arguments) { return null; }
                public function publish($stream, $key, $value) { return 'stub-txid'; }
                public function liststreams($stream) { throw new \Exception('not found'); }
                public function create($type, $name, $private) { return null; }
                public function subscribe($stream) { return null; }
            };
        });
    }

    protected function defineDatabaseMigrations(): void
    {
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

        if (!Schema::hasTable('data_stream_registries')) {
            Schema::create('data_stream_registries', function (Blueprint $table) {
                $table->id();
                $table->string('class_name')->unique();
                $table->string('data_stream');
                $table->timestamps();
                $table->softDeletes();
            });
        }

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
                $table->timestamp('promoted_at')->nullable();
                $table->timestamps();

                $table->unique(['external_service', 'external_id']);
                $table->index(['user_id', 'bucket_hour']);
            });
        }

        if (!Schema::hasTable('life_log_entries')) {
            Schema::create('life_log_entries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('title');
                $table->text('content');
                $table->timestamp('entry_date');
                $table->uuid('location_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('life_log_locations')) {
            Schema::create('life_log_locations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->decimal('latitude', 10, 8);
                $table->decimal('longitude', 11, 8);
                $table->text('description')->nullable();
                $table->timestamp('visited_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('service_credentials')) {
            Schema::create('service_credentials', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('external_service');
                $table->string('client_id');
                $table->text('client_secret');
                $table->string('redirect_uri');
                $table->integer('version')->default(1);
                $table->timestamp('secret_updated_at')->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->string('last_verification_outcome')->nullable();
                $table->timestamps();

                $table->unique('external_service');
            });
        }

        if (!Schema::hasTable('connected_accounts')) {
            Schema::create('connected_accounts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('external_service');
                $table->string('sync_state')->default('normal');
                $table->timestamps();

                $table->unique(['user_id', 'external_service']);
                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('account_authorizations')) {
            Schema::create('account_authorizations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('connected_account_id');
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->text('scopes')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index('connected_account_id');
            });
        }

        if (!Schema::hasTable('account_sync_states')) {
            Schema::create('account_sync_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('connected_account_id');
                $table->timestamp('synced_through_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->string('last_failure_kind')->nullable();
                $table->integer('consecutive_failures')->default(0);
                $table->timestamps();

                $table->index('connected_account_id');
            });
        }

        if (!Schema::hasTable('connection_attempts')) {
            Schema::create('connection_attempts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('external_service');
                $table->string('state_token');
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->index('user_id');
            });
        }

        // Seed two users with fixed UUIDs that both (int)-cast to 0
        // Requirement 1: These specific ids collide under (int) casting
        DB::table('users')->insert([
            [
                'id'              => self::USER_A_ID,
                'name'            => 'User A',
                'email'           => 'user-a@example.com',
                'email_verified_at' => now(),
                'password'        => 'hashed',
                'remember_token'  => null,
                'created_at'      => now(),
                'updated_at'      => now(),
                'deleted_at'      => null,
            ],
            [
                'id'              => self::USER_B_ID,
                'name'            => 'User B',
                'email'           => 'user-b@example.com',
                'email_verified_at' => now(),
                'password'        => 'hashed',
                'remember_token'  => null,
                'created_at'      => now(),
                'updated_at'      => now(),
                'deleted_at'      => null,
            ],
        ]);
    }

    /**
     * Verify that the two fixed UUIDs both cast to 0 via (int).
     * This is the precondition that makes the vulnerability exploitable.
     */
    public function testFixedUuidsCollisionUnderIntCast(): void
    {
        $this->assertSame(0, (int) self::USER_A_ID,
            'USER_A_ID must (int)-cast to 0 for the vulnerability to apply');
        $this->assertSame(0, (int) self::USER_B_ID,
            'USER_B_ID must (int)-cast to 0 for the vulnerability to apply');
        $this->assertSame((int) self::USER_A_ID, (int) self::USER_B_ID,
            'Both ids must collide under (int) cast');
    }

    /**
     * T007a: User A should NOT be authorized to subscribe to User B's private channel.
     *
     * On the vulnerable code (before T008), this assertion FAILS because:
     * (int) 'a1111111-1111-4111-8111-111111111111' === (int) 'f2222222-2222-4222-8222-222222222222'
     * Both cast to 0, so the predicate returns true and the channel is authorized (200).
     * The test must fail by returning 200 with a Pusher auth signature — that is the exploit.
     */
    public function testUserAIsRefusedUserBChannel(): void
    {
        // Requirement 3: Reset guard to session — ClarionBackendServiceProvider::boot()
        // forces passport which is not available under testbench
        config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

        // Requirement 4: Broadcast::routes() registers /broadcasting/auth
        Broadcast::routes();

        $userA = \ClarionApp\Backend\Models\User::find(self::USER_A_ID);

        $response = $this->actingAs($userA)->post('/broadcasting/auth', [
            'channel_name' => "private-User." . self::USER_B_ID,
            'server_data'  => '{"channel":"private-User.' . self::USER_B_ID . '","user_id":"' . self::USER_A_ID . '"}',
            'socket_id'    => '1234.5678',
        ]);

        $response->assertStatus(403);
    }

    /**
     * T007b: User A should be authorized to subscribe to their own private channel.
     */
    public function testUserAIsAuthorizedOwnChannel(): void
    {
        config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);
        Broadcast::routes();

        $userA = \ClarionApp\Backend\Models\User::find(self::USER_A_ID);

        $response = $this->actingAs($userA)->post('/broadcasting/auth', [
            'channel_name' => "private-User." . self::USER_A_ID,
            'server_data'  => '{"channel":"private-User.' . self::USER_A_ID . '","user_id":"' . self::USER_A_ID . '"}',
            'socket_id'    => '1234.5678',
        ]);

        $response->assertStatus(200);
    }
}
