<?php

namespace Tests\Integration;

use Tests\TestCase;
use Tests\Support\ScriptedGoogleTransport;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;

/**
 * Per-type window sizing: heart rate ≤14 days, steps ≤90 days.
 */
class PerTypeWindowTest extends TestCase
{
    protected $user;
    protected $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \ClarionApp\Backend\Models\User::create([
            'id'     => (string) \Illuminate\Support\Str::uuid(),
            'name'   => 'Test User',
            'email'  => 'test@example.com',
            'password' => 'hashed',
        ]);

        $this->actingAs($this->user);

        ServiceCredential::create([
            'id'             => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => GoogleHealthService::NAME,
            'client_id'      => 'test-client-id',
            'client_secret'  => 'test-secret',
            'redirect_uri'   => 'https://example.com/callback',
            'version'        => 1,
        ]);

        $this->transport = ScriptedGoogleTransport::make();
        $this->transport->bind($this->app);
    }

    /** @test T055 — heart rate max window is 14 days */
    public function heartRateMaxWindowIsFourteenDays(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $maxWindow = $service->maxWindow(MeasurementType::HeartRate);
        $this->assertNotNull($maxWindow);
        $this->assertSame(14, $maxWindow->d);
    }

    /** @test T055 — steps max window is 90 days */
    public function stepsMaxWindowIsNinetyDays(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $maxWindow = $service->maxWindow(MeasurementType::Steps);
        $this->assertNotNull($maxWindow);
        $this->assertSame(90, $maxWindow->d);
    }

    /** @test T055 — weight max window is 90 days */
    public function weightMaxWindowIsNinetyDays(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $maxWindow = $service->maxWindow(MeasurementType::Weight);
        $this->assertNotNull($maxWindow);
        $this->assertSame(90, $maxWindow->d);
    }

    /** @test T055 — sleep session max window is 90 days */
    public function sleepSessionMaxWindowIsNinetyDays(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $maxWindow = $service->maxWindow(SessionType::Sleep);
        $this->assertNotNull($maxWindow);
        $this->assertSame(90, $maxWindow->d);
    }

    /** @test T055 — workout session max window is 90 days */
    public function workoutSessionMaxWindowIsNinetyDays(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $maxWindow = $service->maxWindow(SessionType::Workout);
        $this->assertNotNull($maxWindow);
        $this->assertSame(90, $maxWindow->d);
    }

    /** @test T055 — heart rate window of exactly 14 days passes */
    public function heartRateWindowOfExactlyFourteenDaysPasses(): void
    {
        // Script a response
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [],
            ]],
            'nextPageToken' => null,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-01 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-15 00:00:00', 'UTC'); // 14 days

        // Should not throw
        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [MeasurementType::HeartRate],
        );

        $this->assertCount(0, $page->measurements);
    }

    /** @test T055 — heart rate window of 15 days fails */
    public function heartRateWindowOfFifteenDaysFails(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-01 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-16 00:00:00', 'UTC'); // 15 days

        $this->expectException(\ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure::class);
        $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [MeasurementType::HeartRate],
        );
    }

    /** @test T055 — steps window of exactly 90 days passes */
    public function stepsWindowOfExactlyNinetyDaysPasses(): void
    {
        // Script a response
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [],
            ]],
            'nextPageToken' => null,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-04-01 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-06-30 00:00:00', 'UTC'); // ~90 days

        // Should not throw
        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [MeasurementType::Steps],
        );

        $this->assertCount(0, $page->measurements);
    }
}
