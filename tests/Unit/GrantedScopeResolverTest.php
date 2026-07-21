<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Connection\GrantedScopeResolver;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\Google\Oauth\ScopeBundle;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GrantedScopeResolver maps comma-separated scope strings to bundles,
 * then bundles to vocabulary types — and computes the missing set
 * (FR-017, FR-017a, FR-017b, FR-022).
 *
 * Covers the full FR-022 grant matrix: all three bundles, each pair,
 * each single, none; plus every degradation path.
 */
class GrantedScopeResolverTest extends TestCase
{
    private GrantedScopeResolver $resolver;
    private HealthServiceRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new HealthServiceRegistry();
        $this->resolver = new GrantedScopeResolver($this->registry);
    }

    // ------------------------------------------------------------------
    // Full FR-022 grant matrix — all combinations of three bundles
    // ------------------------------------------------------------------

    /** @test */
    public function allThreeBundles()
    {
        $account = $this->makeAccount('activity_and_fitness,health_metrics,sleep');
        $result = $this->resolver->resolve($account);

        $this->assertSame(
            ['activity_and_fitness', 'health_metrics', 'sleep'],
            $result['granted_scopes']
        );
        $this->assertSame(
            ['steps', 'heart_rate', 'calories_burned', 'workout', 'weight', 'sleep'],
            $result['granted_types']
        );
        $this->assertSame([], $result['missing_types']);
    }

    /** @test */
    public function activityAndFitnessPlusHealthMetrics()
    {
        $account = $this->makeAccount('activity_and_fitness,health_metrics');
        $result = $this->resolver->resolve($account);

        $this->assertSame(
            ['activity_and_fitness', 'health_metrics'],
            $result['granted_scopes']
        );
        $this->assertSame(
            ['steps', 'heart_rate', 'calories_burned', 'workout', 'weight'],
            $result['granted_types']
        );
        $this->assertSame(['sleep'], $result['missing_types']);
    }

    /** @test */
    public function activityAndFitnessPlusSleep()
    {
        $account = $this->makeAccount('activity_and_fitness,sleep');
        $result = $this->resolver->resolve($account);

        $this->assertSame(
            ['activity_and_fitness', 'sleep'],
            $result['granted_scopes']
        );
        $this->assertSame(
            ['steps', 'heart_rate', 'calories_burned', 'workout', 'sleep'],
            $result['granted_types']
        );
        $this->assertSame(['weight'], $result['missing_types']);
    }

    /** @test */
    public function healthMetricsPlusSleep()
    {
        $account = $this->makeAccount('health_metrics,sleep');
        $result = $this->resolver->resolve($account);

        $this->assertSame(
            ['health_metrics', 'sleep'],
            $result['granted_scopes']
        );
        $this->assertSame(['weight', 'sleep'], $result['granted_types']);
        $this->assertSame(
            ['steps', 'heart_rate', 'calories_burned', 'workout'],
            $result['missing_types']
        );
    }

    /** @test */
    public function activityAndFitnessOnly()
    {
        $account = $this->makeAccount('activity_and_fitness');
        $result = $this->resolver->resolve($account);

        $this->assertSame(['activity_and_fitness'], $result['granted_scopes']);
        $this->assertSame(
            ['steps', 'heart_rate', 'calories_burned', 'workout'],
            $result['granted_types']
        );
        $this->assertSame(['weight', 'sleep'], $result['missing_types']);
    }

    /** @test */
    public function healthMetricsOnly()
    {
        $account = $this->makeAccount('health_metrics');
        $result = $this->resolver->resolve($account);

        $this->assertSame(['health_metrics'], $result['granted_scopes']);
        $this->assertSame(['weight'], $result['granted_types']);
        $this->assertSame(
            ['steps', 'heart_rate', 'calories_burned', 'workout', 'sleep'],
            $result['missing_types']
        );
    }

    /** @test */
    public function sleepOnly()
    {
        $account = $this->makeAccount('sleep');
        $result = $this->resolver->resolve($account);

        $this->assertSame(['sleep'], $result['granted_scopes']);
        $this->assertSame(['sleep'], $result['granted_types']);
        $this->assertSame(
            ['steps', 'heart_rate', 'calories_burned', 'workout', 'weight'],
            $result['missing_types']
        );
    }

    /** @test */
    public function noScopes()
    {
        $account = $this->makeAccount('');
        $result = $this->resolver->resolve($account);

        $this->assertSame([], $result['granted_scopes']);
        $this->assertSame([], $result['granted_types']);
        $this->assertSame(
            ['steps', 'heart_rate', 'calories_burned', 'workout', 'weight', 'sleep'],
            $result['missing_types']
        );
    }

    // ------------------------------------------------------------------
    // Degradation paths
    // ------------------------------------------------------------------

    /** @test */
    public function nullScopes()
    {
        $account = $this->makeAccount(null);
        $result = $this->resolver->resolve($account);

        $this->assertSame([], $result['granted_scopes']);
        $this->assertSame([], $result['granted_types']);
        $this->assertSame(
            ['steps', 'heart_rate', 'calories_burned', 'workout', 'weight', 'sleep'],
            $result['missing_types']
        );
    }

    /** @test */
    public function emptyStringScopes()
    {
        $account = $this->makeAccount('');
        $result = $this->resolver->resolve($account);

        $this->assertSame([], $result['granted_scopes']);
        $this->assertSame([], $result['granted_types']);
    }

    /** @test */
    public function unrecognisedSlugDropped()
    {
        $account = $this->makeAccount('activity_and_fitness,unknown_scope,health_metrics');
        $result = $this->resolver->resolve($account);

        $this->assertSame(
            ['activity_and_fitness', 'health_metrics'],
            $result['granted_scopes']
        );
        // unknown_scope is silently dropped; the rest resolve normally
        $this->assertNotContains('unknown_scope', $result['granted_scopes']);
    }

    /** @test */
    public function serviceAbsentFromRegistry()
    {
        $account = ConnectedAccount::create([
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'external_service' => 'nonexistent-service',
            'sync_state' => 'normal',
            'connected_at' => now(),
        ]);

        $result = $this->resolver->resolve($account);

        $this->assertSame([], $result['granted_scopes']);
        $this->assertSame([], $result['granted_types']);
        $this->assertSame([], $result['missing_types']);
    }

    /** @test */
    public function noAccountAuthorizationRow()
    {
        $this->registry->register('fake-step', function () {
            return $this->stubService('fake-step', [MeasurementType::Steps]);
        });

        $account = ConnectedAccount::create([
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'external_service' => 'fake-step',
            'sync_state' => 'normal',
            'connected_at' => now(),
        ]);
        // No AccountAuthorization row created

        $result = $this->resolver->resolve($account);

        // No authorization → all supported types are missing
        $this->assertSame([], $result['granted_scopes']);
        $this->assertSame([], $result['granted_types']);
        $this->assertSame(['steps'], $result['missing_types']);
    }

    /** @test */
    public function doesNotThrowOnAnyDegradation()
    {
        // All degradation paths above should return gracefully, not throw
        $nullScopes = $this->makeAccount(null);
        $this->resolver->resolve($nullScopes);

        $emptyScopes = $this->makeAccount('');
        $this->resolver->resolve($emptyScopes);

        $badSlug = $this->makeAccount('totally_invalid');
        $this->resolver->resolve($badSlug);

        // No exception thrown — assertions above cover return values
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeAccount(?string $scopesString): ConnectedAccount
    {
        $servicePrefix = (string) Str::uuid();
        $serviceName = 'fake-' . $servicePrefix;

        $this->registry->register($serviceName, function () use ($serviceName) {
            return $this->stubService($serviceName, [
                MeasurementType::Steps,
                MeasurementType::HeartRate,
                MeasurementType::CaloriesBurned,
                SessionType::Workout,
                MeasurementType::Weight,
                SessionType::Sleep,
            ]);
        });

        $userId = (string) Str::uuid();

        $account = new ConnectedAccount();
        $account->id = (string) Str::uuid();
        $account->user_id = $userId;
        $account->external_service = $serviceName;
        $account->sync_state = 'normal';
        $account->connected_at = now();
        $account->save();

        $auth = new AccountAuthorization();
        $auth->connected_account_id = $account->id;
        $auth->access_token = 'test_token';
        $auth->refresh_token = 'test_refresh';
        $auth->scopes = $scopesString;
        $auth->credential_version = 1;
        $auth->save();

        return $account;
    }

    private function stubService(string $name, array $types): ExternalHealthService
    {
        return new class ($name, $types) implements ExternalHealthService {
            public function __construct(
                private string $name,
                private array $types,
            ) {}

            public function name(): string { return $this->name; }
            public function supportedTypes(): array { return $this->types; }
            public function maxWindow(MeasurementType|SessionType $type): ?\DateInterval { return null; }
            public function beginConnection(string $userId): \ClarionApp\LifeLogBackend\External\ConnectionResult { throw new \RuntimeException('not used'); }
            public function completeConnection(string $userId, string $code, string $redirectUri): \ClarionApp\LifeLogBackend\External\AuthorizationGrant { throw new \RuntimeException('not used'); }
            public function fetch(string $userId, CarbonImmutable $since, CarbonImmutable $until, ?PageCursor $cursor = null, ?array $types = null): \ClarionApp\LifeLogBackend\External\ResultPage { throw new \RuntimeException('not used'); }
            public function renewAccess(string $userId): \ClarionApp\LifeLogBackend\External\RenewalResult { throw new \RuntimeException('not used'); }
            public function disconnect(string $userId): \ClarionApp\LifeLogBackend\External\DisconnectResult { throw new \RuntimeException('not used'); }
        };
    }
}
