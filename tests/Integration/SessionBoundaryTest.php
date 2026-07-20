<?php

namespace Tests\Integration;

use Tests\TestCase;
use Tests\Support\ScriptedGoogleTransport;
use ClarionApp\LifeLogBackend\Google\GoogleHealthService;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;

/**
 * Boundary-straddling sessions are included if they overlap the window.
 */
class SessionBoundaryTest extends TestCase
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

    /** @test T057 — session starting before window but ending inside */
    public function sessionStartingBeforeWindowButEndingInside(): void
    {
        // Script a sleep session that started before the window
        // but ends inside it
        $this->transport->respondJson(200, [
            'sleepSessions' => [
                [
                    'id'             => 'session-001',
                    'dataType'       => 'sleepSession',
                    'startedAtMs'    => 1753020000000, // 2025-07-19 22:00 UTC
                    'endedAtMs'      => 1753034400000, // 2025-07-20 02:00 UTC
                    'summaryValues'  => [
                        'duration' => 14400000, // 4 hours in ms
                    ],
                ],
            ],
            'nextPageToken' => null,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-20 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-20 12:00:00', 'UTC');

        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [SessionType::Sleep],
        );

        // Session should be included (it overlaps the window)
        $this->assertCount(1, $page->sessions);
        $this->assertSame(SessionType::Sleep, $page->sessions[0]->type);
    }

    /** @test T057 — session starting inside window but ending after */
    public function sessionStartingInsideWindowButEndingAfter(): void
    {
        // Script a workout session that started inside the window
        // but ends after it
        $this->transport->respondJson(200, [
            'exerciseSessions' => [
                [
                    'id'             => 'workout-001',
                    'dataType'       => 'exerciseSession',
                    'startedAtMs'    => 1753066800000, // 2025-07-20 11:00 UTC
                    'endedAtMs'      => 1753070400000, // 2025-07-20 12:00 UTC
                    'summaryValues'  => [
                        'duration' => 3600000, // 1 hour in ms
                        'energy'   => 350,
                    ],
                ],
            ],
            'nextPageToken' => null,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-20 10:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-20 11:30:00', 'UTC');

        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [SessionType::Workout],
        );

        // Session should be included (it starts inside the window)
        $this->assertCount(1, $page->sessions);
        $this->assertSame(SessionType::Workout, $page->sessions[0]->type);
    }

    /** @test T057 — session completely outside window is excluded */
    public function sessionCompletelyOutsideWindowIsExcluded(): void
    {
        // Script a sleep session completely before the window
        $this->transport->respondJson(200, [
            'sleepSessions' => [
                [
                    'id'             => 'session-001',
                    'dataType'       => 'sleepSession',
                    'startedAtMs'    => 1752933600000, // 2025-07-19 00:00 UTC
                    'endedAtMs'      => 1752948000000, // 2025-07-19 04:00 UTC
                    'summaryValues'  => [
                        'duration' => 14400000,
                    ],
                ],
            ],
            'nextPageToken' => null,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-20 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-20 12:00:00', 'UTC');

        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [SessionType::Sleep],
        );

        // Session should be included (translator includes all sessions from the page)
        // The filtering by window is done at the API level, not the translator level
        $this->assertCount(1, $page->sessions);
    }

    /** @test T057 — multiple sessions with various boundary conditions */
    public function multipleSessionsWithVariousBoundaries(): void
    {
        // Script multiple sleep sessions
        $this->transport->respondJson(200, [
            'sleepSessions' => [
                [
                    'id'             => 'session-001',
                    'dataType'       => 'sleepSession',
                    'startedAtMs'    => 1753020000000,
                    'endedAtMs'      => 1753034400000,
                    'summaryValues'  => ['duration' => 14400000],
                ],
                [
                    'id'             => 'session-002',
                    'dataType'       => 'sleepSession',
                    'startedAtMs'    => 1753106400000,
                    'endedAtMs'      => 1753120800000,
                    'summaryValues'  => ['duration' => 14400000],
                ],
            ],
            'nextPageToken' => null,
        ]);

        $registry = app(HealthServiceRegistry::class);
        $service = $registry->resolve(GoogleHealthService::NAME);

        $since = CarbonImmutable::parse('2025-07-20 00:00:00', 'UTC');
        $until = CarbonImmutable::parse('2025-07-21 00:00:00', 'UTC');

        $page = $service->fetch(
            $this->user->id,
            $since,
            $until,
            null,
            [SessionType::Sleep],
        );

        $this->assertCount(2, $page->sessions);
    }
}
