<?php

namespace Tests\Integration;

use ClarionApp\Backend\ClarionBackendServiceProvider;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Google\Oauth\ScopeBundle;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use ClarionApp\LifeLogBackend\LifeLogBackendServiceProvider;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Support\Str;

/**
 * T044: FR-022 grant matrix on connected-accounts endpoints.
 *
 * GET /connected-accounts and GET /connected-accounts/{id} both carry
 * granted_scopes, granted_types, missing_types for the whole matrix.
 * The existing six fields are unchanged. A connection with null scopes
 * returns granted_scopes: [] with all supported types in missing_types.
 * Ownership scoping and the byte-identical 404 are unaffected.
 */
class ConnectedAccountGrantsTest extends BaseTestCase
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

        // life_log_connected_accounts
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

        // life_log_account_sync_states
        if (!Schema::hasTable('life_log_account_sync_states')) {
            Schema::create('life_log_account_sync_states', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('connected_account_id');
                $table->timestamp('synced_through_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->string('last_failure_kind')->nullable();
                $table->string('type_gate')->nullable();
                $table->text('cursor')->nullable();
                $table->timestamps();

                $table->unique('connected_account_id');
                $table->foreign('connected_account_id')
                    ->references('id')
                    ->on('life_log_connected_accounts')
                    ->onDelete('cascade');
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

        // life_log_service_credentials
        if (!Schema::hasTable('life_log_service_credentials')) {
            Schema::create('life_log_service_credentials', function (Blueprint $table) {
                $table->id();
                $table->string('service');
                $table->text('client_id');
                $table->text('client_secret');
                $table->text('redirect_uri');
                $table->timestamps();

                $table->unique('service');
            });
        }

        // life_log_connection_attempts
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

    private function registerFakeService(string $name, array $types): void
    {
        $registry = app(HealthServiceRegistry::class);
        $registry->register($name, function () use ($name, $types) {
            return new class ($name, $types) implements ExternalHealthService {
                public function __construct(
                    private string $name,
                    private array $types,
                ) {}

                public function name(): string { return $this->name; }
                public function supportedTypes(): array { return $this->types; }
                public function maxWindow(\ClarionApp\LifeLogBackend\Vocabulary\MeasurementType|\ClarionApp\LifeLogBackend\Vocabulary\SessionType $type): ?\DateInterval { return null; }
                public function beginConnection(string $userId): \ClarionApp\LifeLogBackend\External\ConnectionResult {
                    throw new \RuntimeException('not implemented');
                }
                public function completeConnection(string $userId, string $code, string $redirectUri): \ClarionApp\LifeLogBackend\External\AuthorizationGrant {
                    throw new \RuntimeException('not implemented');
                }
                public function fetch(string $userId, \Carbon\CarbonImmutable $since, \Carbon\CarbonImmutable $until, ?\ClarionApp\LifeLogBackend\External\PageCursor $cursor = null, ?array $types = null): \ClarionApp\LifeLogBackend\External\ResultPage {
                    throw new \RuntimeException('not implemented');
                }
                public function renewAccess(string $userId): \ClarionApp\LifeLogBackend\External\RenewalResult {
                    throw new \RuntimeException('not implemented');
                }
                public function disconnect(string $userId): \ClarionApp\LifeLogBackend\External\DisconnectResult {
                    throw new \RuntimeException('not implemented');
                }
            };
        });
    }

    private function makeUser(string $id): \ClarionApp\Backend\Models\User
    {
        $user = new \ClarionApp\Backend\Models\User();
        $user->id = $id;
        $user->name = 'Test User';
        $user->email = $id . '@example.com';
        $user->password = 'hashed';
        $user->save();
        return $user;
    }

    private function makeAccount(string $userId, string $service, ?string $scopes): ConnectedAccount
    {
        $account = new ConnectedAccount();
        $account->id = (string) Str::uuid();
        $account->user_id = $userId;
        $account->external_service = $service;
        $account->sync_state = 'normal';
        $account->connected_at = now();
        $account->save();

        if ($scopes !== null) {
            $auth = new AccountAuthorization();
            $auth->connected_account_id = $account->id;
            $auth->access_token = 'test_token';
            $auth->refresh_token = 'test_refresh';
            $auth->scopes = $scopes;
            $auth->credential_version = 1;
            $auth->save();
        }

        return $account;
    }

    private function indexAs(string $userId): array
    {
        // Reset guard to session — ClarionBackendServiceProvider::boot() forces passport
        config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);
        Broadcast::routes();

        $user = \ClarionApp\Backend\Models\User::find($userId);
        $guard = app('auth')->guard('api');
        $guard->setUser($user);

        $response = $this->json('GET', '/api/clarion-app/life-log/connected-accounts');
        return (array) json_decode($response->getContent(), true);
    }

    private function showAs(string $userId, string $accountId): array
    {
        // Reset guard to session — ClarionBackendServiceProvider::boot() forces passport
        config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);
        Broadcast::routes();

        $user = \ClarionApp\Backend\Models\User::find($userId);
        $guard = app('auth')->guard('api');
        $guard->setUser($user);

        $response = $this->json('GET', "/api/clarion-app/life-log/connected-accounts/{$accountId}");
        return (array) json_decode($response->getContent(), true);
    }

    /**
     * All three bundles grant all types — nothing missing.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function allThreeBundlesGrantAllTypes(): void
    {
        $this->registerFakeService('fake-google', [
            MeasurementType::Steps, MeasurementType::HeartRate,
            MeasurementType::CaloriesBurned, SessionType::Workout,
            MeasurementType::Weight, SessionType::Sleep,
        ]);

        $userId = (string) Str::uuid();
        $this->makeUser($userId);

        $account = $this->makeAccount(
            $userId,
            'fake-google',
            'activity_and_fitness,health_metrics,sleep'
        );

        $body = $this->indexAs($userId);
        $conn = $body['connections'][0];

        $this->assertSame(['activity_and_fitness', 'health_metrics', 'sleep'], $conn['granted_scopes']);
        $this->assertSame([
            'steps', 'heart_rate', 'calories_burned', 'workout', 'weight', 'sleep',
        ], $conn['granted_types']);
        $this->assertSame([], $conn['missing_types']);

        // Show endpoint matches
        $showBody = $this->showAs($userId, $account->id);
        $this->assertSame($conn['granted_scopes'], $showBody['granted_scopes']);
        $this->assertSame($conn['granted_types'], $showBody['granted_types']);
        $this->assertSame($conn['missing_types'], $showBody['missing_types']);
    }

    /**
     * Activity and fitness only — weight and sleep missing.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function activityAndFitnessOnly(): void
    {
        $this->registerFakeService('fake-google', [
            MeasurementType::Steps, MeasurementType::HeartRate,
            MeasurementType::CaloriesBurned, SessionType::Workout,
            MeasurementType::Weight, SessionType::Sleep,
        ]);

        $userId = (string) Str::uuid();
        $this->makeUser($userId);

        $this->makeAccount($userId, 'fake-google', 'activity_and_fitness');

        $body = $this->indexAs($userId);
        $conn = $body['connections'][0];

        $this->assertSame(['activity_and_fitness'], $conn['granted_scopes']);
        $this->assertSame(
            ['steps', 'heart_rate', 'calories_burned', 'workout'],
            $conn['granted_types']
        );
        $this->assertSame(['weight', 'sleep'], $conn['missing_types']);
    }

    /**
     * Null scopes — empty granted, all types missing.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function nullScopesReturnsEmptyGranted(): void
    {
        $this->registerFakeService('fake-google', [
            MeasurementType::Steps, MeasurementType::HeartRate,
            MeasurementType::CaloriesBurned, SessionType::Workout,
            MeasurementType::Weight, SessionType::Sleep,
        ]);

        $userId = (string) Str::uuid();
        $this->makeUser($userId);

        $this->makeAccount($userId, 'fake-google', null);

        $body = $this->indexAs($userId);
        $conn = $body['connections'][0];

        $this->assertSame([], $conn['granted_scopes']);
        $this->assertSame([], $conn['granted_types']);
        $this->assertSame([
            'steps', 'heart_rate', 'calories_burned', 'workout', 'weight', 'sleep',
        ], $conn['missing_types']);
    }

    /**
     * Existing six fields are unchanged.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function existingFieldsUnchanged(): void
    {
        $this->registerFakeService('fake-google', [
            MeasurementType::Steps,
        ]);

        $userId = (string) Str::uuid();
        $this->makeUser($userId);

        $this->makeAccount($userId, 'fake-google', 'activity_and_fitness');

        $body = $this->indexAs($userId);
        $conn = $body['connections'][0];

        $this->assertArrayHasKey('id', $conn);
        $this->assertArrayHasKey('external_service', $conn);
        $this->assertArrayHasKey('status', $conn);
        $this->assertArrayHasKey('last_successful_sync_at', $conn);
        $this->assertArrayHasKey('connected_at', $conn);
        $this->assertArrayHasKey('needs_attention_reason', $conn);
    }

    /**
     * Ownership scoping — user A sees only their connections.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function ownershipScoping(): void
    {
        $this->registerFakeService('fake-google', [
            MeasurementType::Steps,
        ]);

        $userA = (string) Str::uuid();
        $userB = (string) Str::uuid();
        $this->makeUser($userA);
        $this->makeUser($userB);

        $this->makeAccount($userA, 'fake-google', 'activity_and_fitness');
        $accountB = $this->makeAccount($userB, 'fake-google', 'activity_and_fitness');

        $body = $this->indexAs($userA);
        $this->assertCount(1, $body['connections']);
        $this->assertNotSame($accountB->id, $body['connections'][0]['id']);

        // Show returns 404 for another user's account
        $showBody = $this->showAs($userA, $accountB->id);
        $this->assertSame('not_found', $showBody['error']);
    }

    /**
     * Byte-identical 404 for non-existent account.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function notFoundIs404(): void
    {
        $userId = (string) Str::uuid();
        $this->makeUser($userId);

        // Reset guard to session
        config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);
        Broadcast::routes();

        $user = \ClarionApp\Backend\Models\User::find($userId);
        $guard = app('auth')->guard('api');
        $guard->setUser($user);

        $response = $this->json('GET', '/api/clarion-app/life-log/connected-accounts/' . (string) Str::uuid());
        $response->assertStatus(404);
        $body = (array) json_decode($response->getContent(), true);
        $this->assertSame('not_found', $body['error']);
    }
}
