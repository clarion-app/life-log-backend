<?php

namespace Tests\Integration;

use ClarionApp\Backend\ClarionBackendServiceProvider;
use ClarionApp\LifeLogBackend\Connection\ConnectionCompleter;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Events\ConnectedAccountStatusChanged;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\LifeLogBackendServiceProvider;
use ClarionApp\LifeLogBackend\Sync\AccountSyncRunner;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Integration tests for ConnectedAccountStatusChanged broadcasts.
 *
 * Verifies that Event::fake() captures the event at each of the four
 * dispatch sites: finalize, failure-policy transition, connection complete,
 * and credential destroy.
 */
class StatusChangeBroadcastTest extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
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

        $app['config']->set('broadcasting.default', 'pusher');
        $app['config']->set('broadcasting.connections.pusher', [
            'driver'  => 'pusher',
            'key'     => 'test-key',
            'secret'  => 'test-secret',
            'app_id'  => '999999',
            'options' => ['cluster' => 'mt1', 'useTLS' => true],
        ]);

        $app['config']->set('auth.defaults.guard', 'api');
        $app['config']->set('auth.guards.api', [
            'driver'   => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model'  => \ClarionApp\Backend\Models\User::class,
        ]);

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

        if (!Schema::hasTable('life_log_connected_accounts')) {
            Schema::create('life_log_connected_accounts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('external_service');
                $table->string('sync_state')->default('normal');
                $table->timestamp('connected_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('life_log_account_sync_states')) {
            Schema::create('life_log_account_sync_states', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('connected_account_id');
                $table->timestamp('synced_through_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->string('last_failure_kind')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->string('type_gate')->nullable();
                $table->text('cursor')->nullable();
                $table->timestamp('cursor_since')->nullable();
                $table->timestamp('cursor_until')->nullable();
                $table->timestamp('next_attempt_at')->nullable();
                $table->string('needs_attention_reason')->nullable();
                $table->timestamps();

                $table->unique('connected_account_id');
                $table->foreign('connected_account_id')
                    ->references('id')
                    ->on('life_log_connected_accounts')
                    ->onDelete('cascade');
            });
        }

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

        if (!Schema::hasTable('life_log_service_credentials')) {
            Schema::create('life_log_service_credentials', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('external_service');
                $table->text('client_id');
                $table->text('client_secret');
                $table->text('redirect_uri');
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('secret_updated_at')->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->string('last_verification_outcome')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique('external_service');
            });
        }

        if (!Schema::hasTable('life_log_connection_attempts')) {
            Schema::create('life_log_connection_attempts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('external_service');
                $table->string('state_hash');
                $table->text('redirect_uri');
                $table->timestamp('expires_at');
                $table->boolean('consumed')->default(false);
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    /**
     * Teardown: clean up the registry after each test.
     */
    protected function tearDown(): void
    {
        $registry = app(HealthServiceRegistry::class);

        foreach ($registry->names() as $name) {
            if (str_starts_with($name, 'fake-')) {
                $registry->forget($name);
            }
        }

        parent::tearDown();
    }

    // ========================================================================
    // AccountSyncRunner finalize dispatches event
    // ========================================================================

    public function testFinalizeDispatchesStatusChangedEvent(): void
    {
        Event::fake();

        $this->registerFakeService();
        $user = $this->makeUser();
        $account = $this->makeAccount($user->id, $this->service);

        Event::assertNotDispatched(ConnectedAccountStatusChanged::class);

        // Run the sync runner - it will dispatch the event on finalize
        $runner = app(AccountSyncRunner::class);
        $runner->run($account, SyncTrigger::Scheduled);

        Event::assertDispatched(ConnectedAccountStatusChanged::class, function ($event) use ($account) {
            return $event->account()->id === $account->id;
        });
    }

    // ========================================================================
    // ConnectionCompleter dispatches event on connect
    // ========================================================================

    public function testConnectionCompleterDispatchesEventOnConnect(): void
    {
        Event::fake();

        $this->registerFakeService();
        $this->registerFakeCredential();
        $user = $this->makeUser();

        Event::assertNotDispatched(ConnectedAccountStatusChanged::class);

        $completer = app(ConnectionCompleter::class);
        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve($this->service);

        $result = $completer->complete(
            $user->id,
            $this->service,
            'test-code',
            "https://example.com/callback/{$this->service}",
            $service,
        );

        Event::assertDispatched(ConnectedAccountStatusChanged::class, function ($event) use ($result) {
            return $event->account()->id === $result['account']->id;
        });
    }

    /**
     * ConnectionCompleter dispatches event on reconnect.
     */
    public function testConnectionCompleterDispatchesEventOnReconnect(): void
    {
        $this->registerFakeService();
        $this->registerFakeCredential();
        $user = $this->makeUser();
        $account = $this->makeAccount($user->id, $this->service);

        // Re-fake events (Event::fake() resets listeners)
        Event::fake();

        $completer = app(ConnectionCompleter::class);
        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve($this->service);

        $result = $completer->complete(
            $user->id,
            $this->service,
            'test-code',
            "https://example.com/callback/{$this->service}",
            $service,
        );

        Event::assertDispatched(ConnectedAccountStatusChanged::class, function ($event) use ($result) {
            return $event->account()->id === $result['account']->id;
        });

        $this->assertTrue($result['reconnected']);
    }

    // ========================================================================
    // ServiceCredentialController destroy dispatches per-account events
    // ========================================================================

    public function testCredentialDestroyDispatchesPerAccountEvents(): void
    {
        $this->registerFakeService();
        $this->registerFakeCredential();
        $user1 = $this->makeUser();
        $user2 = $this->makeUser2();
        $account1 = $this->makeAccount($user1->id, $this->service);
        $account2 = $this->makeAccount($user2->id, $this->service);

        Event::fake();

        // Reset guard to session — ClarionBackendServiceProvider::boot() forces passport
        config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);
        Broadcast::routes();

        // Trigger the destroy endpoint as user1
        $response = $this->actingAs($user1)
            ->deleteJson("/api/clarion-app/life-log/service-credentials/{$this->service}");

        $response->assertOk();

        // Two events should be dispatched (one per account)
        Event::assertDispatched(ConnectedAccountStatusChanged::class, 2);

        Event::assertDispatched(ConnectedAccountStatusChanged::class, function ($event) use ($account1) {
            return $event->account()->id === $account1->id;
        });

        Event::assertDispatched(ConnectedAccountStatusChanged::class, function ($event) use ($account2) {
            return $event->account()->id === $account2->id;
        });
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private string $service;

    private function registerFakeService(): void
    {
        $this->service = 'fake-' . Str::uuid();
        $registry = app(HealthServiceRegistry::class);
        $serviceName = $this->service;
        $registry->register($this->service, function () use ($serviceName) {
            return new class ($serviceName) implements ExternalHealthService {
                public function __construct(private string $name) {}
                public function name(): string { return $this->name; }
                public function supportedTypes(): array { return []; }
                public function maxWindow(\ClarionApp\LifeLogBackend\Vocabulary\MeasurementType|\ClarionApp\LifeLogBackend\Vocabulary\SessionType $type): ?\DateInterval { return null; }
                public function beginConnection(string $userId): \ClarionApp\LifeLogBackend\External\ConnectionResult {
                    return new \ClarionApp\LifeLogBackend\External\ConnectionResult(
                        externalService: $this->name,
                        authorizationUrl: 'https://example.com/auth',
                        state: 'test-state',
                    );
                }
                public function completeConnection(string $userId, string $code, string $redirectUri): \ClarionApp\LifeLogBackend\External\AuthorizationGrant {
                    return new \ClarionApp\LifeLogBackend\External\AuthorizationGrant(
                        accessToken: 'test-access-token',
                        refreshToken: 'test-refresh-token',
                        expiresAt: null,
                        scopes: 'activity_and_fitness,health_metrics,sleep',
                    );
                }
                public function fetch(string $userId, \Carbon\CarbonImmutable $since, \Carbon\CarbonImmutable $until, ?\ClarionApp\LifeLogBackend\External\PageCursor $cursor = null, ?array $types = null): \ClarionApp\LifeLogBackend\External\ResultPage {
                    return new \ClarionApp\LifeLogBackend\External\ResultPage([], [], null);
                }
                public function renewAccess(string $userId): \ClarionApp\LifeLogBackend\External\RenewalResult {
                    return \ClarionApp\LifeLogBackend\External\RenewalResult::renewed();
                }
                public function disconnect(string $userId): \ClarionApp\LifeLogBackend\External\DisconnectResult {
                    return \ClarionApp\LifeLogBackend\External\DisconnectResult::confirmed();
                }
            };
        });
    }

    private function registerFakeCredential(): void
    {
        $credential = new ServiceCredential();
        $credential->id = (string) Str::uuid();
        $credential->external_service = $this->service;
        $credential->client_id = 'test-client-id';
        $credential->client_secret = 'test-client-secret';
        $credential->redirect_uri = "https://example.com/callback/{$this->service}";
        $credential->save();
    }

    private function makeUser(): \ClarionApp\Backend\Models\User
    {
        $user = new \ClarionApp\Backend\Models\User();
        $user->id = (string) Str::uuid();
        $user->name = 'Test User';
        $user->email = $user->id . '@example.com';
        $user->password = 'hashed';
        $user->save();
        return $user;
    }

    private function makeUser2(): \ClarionApp\Backend\Models\User
    {
        $user = new \ClarionApp\Backend\Models\User();
        $user->id = (string) Str::uuid();
        $user->name = 'Test User 2';
        $user->email = $user->id . '@example.com';
        $user->password = 'hashed';
        $user->save();
        return $user;
    }

    private function makeAccount(string $userId, string $service): ConnectedAccount
    {
        $account = new ConnectedAccount();
        $account->id = (string) Str::uuid();
        $account->user_id = $userId;
        $account->external_service = $service;
        $account->sync_state = 'normal';
        $account->connected_at = now();
        $account->save();

        $auth = new AccountAuthorization();
        $auth->id = (string) Str::uuid();
        $auth->connected_account_id = $account->id;
        $auth->access_token = 'test_token';
        $auth->refresh_token = 'test_refresh';
        $auth->scopes = 'activity_and_fitness,health_metrics,sleep';
        $auth->credential_version = 1;
        $auth->save();

        return $account;
    }
}
