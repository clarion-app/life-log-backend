<?php

namespace Tests\Integration;

use Tests\TestCase;
use Tests\Support\ScriptedGoogleTransport;
use Tests\Support\ConnectsGoogleAccount;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;

/**
 * Empty page doesn't end the range — paging continues until nextPageToken is null.
 */
class SparsePagePagingTest extends TestCase
{
    use ConnectsGoogleAccount;

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

        $this->connectGoogleAccount($this->user->id);
    }

    /** @test T056 — empty page followed by data page */
    public function emptyPageFollowedByDataPage(): void
    {
        // First page is empty but has a continuation token
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [],
            ]],
            'nextPageToken' => 'page2token',
        ]);

        // Second page has data
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [
                    [
                        'dataType'     => 'stepCount',
                        'startedAtMs'  => 1753027200000,
                        'endedAtMs'    => 1753027260000,
                        'value'        => [['intVal' => 500]],
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

        // The empty page is a sparse span, not the end of the range: it comes
        // back with a cursor, and following that cursor reaches the data. A
        // caller that stopped at the empty page would truncate the window and
        // be unable to tell that from "no more history".
        $first = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [MeasurementType::Steps],
        );

        $this->assertCount(0, $first->measurements());
        $this->assertNotNull($first->nextCursor());

        $second = $service->fetch(
            $this->user->id,
            $since,
            $until,
            $first->nextCursor(),
            [MeasurementType::Steps],
        );

        $this->assertCount(1, $second->measurements());
        $this->assertNull($second->nextCursor());
        $this->assertSame(2, $this->transport->requestCount());
    }

    /**
     * Drive a range to exhaustion the way the engine does — one request per
     * fetch, resuming from the returned cursor.
     *
     * @return list<\ClarionApp\LifeLogBackend\External\TranslatedMeasurement>
     */
    private function drain(
        $service,
        CarbonImmutable $since,
        CarbonImmutable $until,
        MeasurementType $type,
    ): array {
        $measurements = [];
        $cursor = null;
        $guard = 0;

        do {
            $page = $service->fetch($this->user->id, $since, $until, $cursor, [$type]);
            $measurements = array_merge($measurements, $page->measurements());
            $cursor = $page->nextCursor();
        } while ($cursor !== null && ++$guard < 20);

        return $measurements;
    }

    /** @test T056 — multiple empty pages before data */
    public function multipleEmptyPagesBeforeData(): void
    {
        // Three empty pages
        $this->transport->respondJson(200, [
            'dataset' => [['point' => []]],
            'nextPageToken' => 'page2',
        ]);
        $this->transport->respondJson(200, [
            'dataset' => [['point' => []]],
            'nextPageToken' => 'page3',
        ]);
        $this->transport->respondJson(200, [
            'dataset' => [['point' => []]],
            'nextPageToken' => 'page4',
        ]);

        // Fourth page has data
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

        $measurements = $this->drain($service, $since, $until, MeasurementType::HeartRate);

        // Three consecutive gaps do not end the range.
        $this->assertCount(1, $measurements);
        $this->assertSame(4, $this->transport->requestCount());
    }

    /** @test T056 — empty page with null nextPageToken ends range */
    public function emptyPageWithNullTokenEndsRange(): void
    {
        // Empty page with no continuation
        $this->transport->respondJson(200, [
            'dataset' => [['point' => []]],
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

        $this->assertCount(0, $page->measurements());
        $this->assertNull($page->nextCursor());
        $this->assertSame(1, $this->transport->requestCount());
    }

    /** @test T056 — interleaved empty and data pages */
    public function interleavedEmptyAndDataPages(): void
    {
        // Page 1: data
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [
                    [
                        'dataType'     => 'caloriesBurned',
                        'startedAtMs'  => 1753027200000,
                        'endedAtMs'    => 1753027260000,
                        'value'        => [['intVal' => 100]],
                        'unit'         => 'kcal',
                    ],
                ],
            ]],
            'nextPageToken' => 'page2',
        ]);

        // Page 2: empty
        $this->transport->respondJson(200, [
            'dataset' => [['point' => []]],
            'nextPageToken' => 'page3',
        ]);

        // Page 3: data
        $this->transport->respondJson(200, [
            'dataset' => [[
                'point' => [
                    [
                        'dataType'     => 'caloriesBurned',
                        'startedAtMs'  => 1753027260000,
                        'endedAtMs'    => 1753027320000,
                        'value'        => [['intVal' => 150]],
                        'unit'         => 'kcal',
                    ],
                ],
            ]],
            'nextPageToken' => null,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-20 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-20 01:00:00', 'UTC');

        $measurements = $this->drain($service, $since, $until, MeasurementType::CaloriesBurned);

        $this->assertCount(2, $measurements);
        $this->assertSame(3, $this->transport->requestCount());
    }
}
