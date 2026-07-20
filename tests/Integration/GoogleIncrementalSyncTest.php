<?php

namespace Tests\Integration;

use Tests\TestCase;
use Tests\Support\ScriptedGoogleTransport;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Google\Api\ApiVersion;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;

/**
 * US2 scenarios 1-6: incremental sync with paging, type filtering,
 * and cursor-based continuation.
 */
class GoogleIncrementalSyncTest extends TestCase
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

        // Store credential for google-health
        ServiceCredential::create([
            'id'             => (string) \Illuminate\Support\Str::uuid(),
            'external_service' => GoogleHealthService::NAME,
            'client_id'      => 'test-client-id',
            'client_secret'  => 'test-secret',
            'redirect_uri'   => 'https://example.com/callback',
            'version'        => 1,
        ]);

        // Setup transport
        $this->transport = ScriptedGoogleTransport::make();
        $this->transport->bind($this->app);
    }

    /** @test T054 — US2 scenario 1: single page of heart rate data */
    public function singlePageOfHeartRateData(): void
    {
        // Script a single page response with heart rate data
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [
                    [
                        'dataType'     => 'heartRateBpm',
                        'startedAtMs'  => 1753027200000,
                        'endedAtMs'    => 1753027260000,
                        'value'        => [['intVal' => 72]],
                        'unit'         => 'bpm',
                    ],
                    [
                        'dataType'     => 'heartRateBpm',
                        'startedAtMs'  => 1753027260000,
                        'endedAtMs'    => 1753027320000,
                        'value'        => [['intVal' => 75]],
                        'unit'         => 'bpm',
                    ],
                ],
            ]],
            'nextPageToken' => null,
        ]);

        // Create connected account and authorization
        $connectedAccount = ConnectedAccount::create([
            'user_id'            => $this->user->id,
            'external_service'   => GoogleHealthService::NAME,
            'external_user_id'   => 'test-user-id',
            'connected_at'       => CarbonImmutable::now(),
        ]);

        AccountAuthorization::create([
            'user_id'            => $this->user->id,
            'external_service'   => GoogleHealthService::NAME,
            'access_token'       => 'test-access-token',
            'refresh_token'      => 'test-refresh-token',
            'expires_at'         => CarbonImmutable::now()->addHour(),
            'credential_version' => 1,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-20 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-20 01:00:00', 'UTC');

        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [MeasurementType::HeartRate],
        );

        $this->assertCount(2, $page->measurements);
        $this->assertNull($page->nextCursor);
    }

    /** @test T054 — US2 scenario 2: multi-page paging */
    public function multiPagePaging(): void
    {
        // Script two pages of steps data
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [
                    [
                        'dataType'     => 'stepCount',
                        'startedAtMs'  => 1753027200000,
                        'endedAtMs'    => 1753027260000,
                        'value'        => [['intVal' => 100]],
                        'unit'         => 'steps',
                    ],
                ],
            ]],
            'nextPageToken' => 'page2token',
        ]);

        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [
                    [
                        'dataType'     => 'stepCount',
                        'startedAtMs'  => 1753027260000,
                        'endedAtMs'    => 1753027320000,
                        'value'        => [['intVal' => 150]],
                        'unit'         => 'steps',
                    ],
                ],
            ]],
            'nextPageToken' => null,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-20 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-20 01:00:00', 'UTC');

        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [MeasurementType::Steps],
        );

        $this->assertCount(2, $page->measurements);
        $this->assertNull($page->nextCursor);
        $this->assertSame(2, $this->transport->requestCount());
    }

    /** @test T054 — US2 scenario 3: type filter honours exact types */
    public function typeFilterHonoursExactTypes(): void
    {
        // Script responses for both heart rate and steps
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [
                    [
                        'dataType'     => 'heartRateBpm',
                        'startedAtMs'  => 1753027200000,
                        'endedAtMs'    => 1753027260000,
                        'value'        => [['intVal' => 72]],
                        'unit'         => 'bpm',
                    ],
                ],
            ]],
            'nextPageToken' => null,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-20 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-20 01:00:00', 'UTC');

        // Request only heart rate
        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [MeasurementType::HeartRate],
        );

        // Only heart rate measurements should be returned
        foreach ($page->measurements as $m) {
            $this->assertSame(MeasurementType::HeartRate, $m->type);
        }

        // Only one request was made (for heart rate)
        $this->assertSame(1, $this->transport->requestCount());
    }

    /** @test T054 — US2 scenario 4: empty page still returns valid page */
    public function emptyPageReturnsValidPage(): void
    {
        // Script an empty page response
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [],
            ]],
            'nextPageToken' => null,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-20 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-20 01:00:00', 'UTC');

        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [MeasurementType::HeartRate],
        );

        $this->assertCount(0, $page->measurements);
        $this->assertNull($page->nextCursor);
    }

    /** @test T054 — US2 scenario 5: cursor continuation */
    public function cursorContinuation(): void
    {
        // Script first page with continuation token
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [
                    [
                        'dataType'     => 'heartRateBpm',
                        'startedAtMs'  => 1753027200000,
                        'endedAtMs'    => 1753027260000,
                        'value'        => [['intVal' => 72]],
                        'unit'         => 'bpm',
                    ],
                ],
            ]],
            'nextPageToken' => 'continuationToken123',
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-20 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-20 01:00:00', 'UTC');

        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [MeasurementType::HeartRate],
        );

        $this->assertCount(1, $page->measurements);
        $this->assertNotNull($page->nextCursor);
    }

    /** @test T054 — US2 scenario 6: wide window rejected with InvalidRequest */
    public function wideWindowRejectedWithInvalidRequest(): void
    {
        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        // 100 days exceeds 14-day max for heart rate
        $since = CarbonImmutable::parse('2025-01-01 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-04-11 00:00:00', 'UTC');

        $this->expectException(\ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure::class);
        $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [MeasurementType::HeartRate],
        );
    }
}
