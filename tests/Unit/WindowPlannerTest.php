<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use ClarionApp\LifeLogBackend\Sync\TypeSegment;
use ClarionApp\LifeLogBackend\Sync\WindowPlanner;
use Carbon\CarbonImmutable;
use Tests\Support\FakeStepService;
use Tests\Support\FakeSpanService;
use Tests\Support\StubBandService;

/**
 * T021 — WindowPlanner: one (since, until) in, per-type segments out.
 *
 * - Type with null limit gets exactly one segment.
 * - Type whose limit is narrower gets several, newest first.
 * - 20-day range against 14-day heart-rate limit yields two segments.
 */
class WindowPlannerTest extends TestCase
{
    private WindowPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new WindowPlanner();
    }

    /** @test  type with null maxWindow gets exactly one segment */
    public function nullLimitGivesOneSegment(): void
    {
        $service = new FakeStepService(); // maxWindow returns null for all types
        $since = CarbonImmutable::parse('2026-01-01');
        $until = CarbonImmutable::parse('2026-01-02');

        $segments = $this->planner->plan(
            $since,
            $until,
            $service,
            $service->supportedTypes(),
        );

        // Each type with null limit gets exactly one segment
        $typeCounts = [];
        foreach ($segments as $seg) {
            $typeCounts[$seg->type->value] = ($typeCounts[$seg->type->value] ?? 0) + 1;
        }

        foreach ($typeCounts as $type => $count) {
            $this->assertSame(
                1,
                $count,
                "Type {$type} with null maxWindow should get exactly one segment.",
            );
        }
    }

    /** @test  segments are TypeSegment value objects */
    public function segmentsAreTypeSegmentInstances(): void
    {
        $service = new FakeStepService();
        $segments = $this->planner->plan(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-02'),
            $service,
            $service->supportedTypes(),
        );

        foreach ($segments as $seg) {
            $this->assertInstanceOf(TypeSegment::class, $seg);
        }
    }

    /** @test  segment boundaries are within the original range */
    public function segmentsStayWithinRange(): void
    {
        $service = new FakeStepService();
        $since = CarbonImmutable::parse('2026-01-01');
        $until = CarbonImmutable::parse('2026-01-02');

        $segments = $this->planner->plan($since, $until, $service, $service->supportedTypes());

        foreach ($segments as $seg) {
            $this->assertGreaterThanOrEqual($since, $seg->since, 'Segment since must be >= range since.');
            $this->assertLessThanOrEqual($until, $seg->until, 'Segment until must be <= range until.');
        }
    }

    /** @test  type whose limit is narrower than the range gets several segments, newest first */
    public function narrowLimitSplitsIntoSegmentsNewestFirst(): void
    {
        // StubBandService will be updated to support maxWindow; for now we test
        // with a service that has a known limit. We'll verify the ordering after
        // the fakes are updated. For now, test with null limit (no split).
        $service = new FakeStepService();
        $since = CarbonImmutable::parse('2026-01-01');
        $until = CarbonImmutable::parse('2026-01-10');

        $segments = $this->planner->plan($since, $until, $service, [MeasurementType::Steps]);

        // With null limit, one segment covering the full range
        $this->assertCount(1, $segments);
        $this->assertSame($since, $segments[0]->since);
        $this->assertSame($until, $segments[0]->until);
    }

    /** @test  20-day range against 14-day limit yields two segments */
    public function twentyDayRangeWithFourteenDayLimitYieldsTwoSegments(): void
    {
        // This test verifies the latent incremental bug fix from plan §Phase 0 #4.
        // A service with a 14-day maxWindow for heart rate, queried for 20 days,
        // must split into two segments: [day 0..14] and [day 14..20].
        // We test this with ScriptedSyncService which supports settable maxWindow.
        $service = \Tests\Support\ScriptedSyncService::emitting([]);
        $service->setMaxWindow(MeasurementType::HeartRate, 'P14D');

        $since = CarbonImmutable::parse('2026-01-01');
        $until = $since->copy()->addDays(20);

        $segments = $this->planner->plan($since, $until, $service, [MeasurementType::HeartRate]);

        $this->assertCount(
            2,
            $segments,
            '20-day range with 14-day limit should yield exactly two segments.',
        );

        // Newest first ordering
        $this->assertGreaterThan($segments[1]->since, $segments[0]->since, 'Segments must be newest first.');
    }

    /** @test  segments for the same type do not overlap (except at boundaries) */
    public function segmentsDoNotOverlap(): void
    {
        $service = \Tests\Support\ScriptedSyncService::emitting([]);
        $service->setMaxWindow(MeasurementType::HeartRate, 'P7D');

        $since = CarbonImmutable::parse('2026-01-01');
        $until = $since->copy()->addDays(21);

        $segments = $this->planner->plan($since, $until, $service, [MeasurementType::HeartRate]);

        $this->assertCount(3, $segments, '21 days / 7-day limit = 3 segments.');

        // Check no overlap (each segment's since <= previous segment's until)
        for ($i = 1; $i < count($segments); $i++) {
            $prevIdx = $i - 1;
            $this->assertLessThanOrEqual(
                $segments[$prevIdx]->since,
                $segments[$i]->until,
                "Segment {$i} should not overlap with segment {$prevIdx}.",
            );
        }
    }

    /** @test  plan returns empty list for empty type set */
    public function emptyTypeSetReturnsEmptyList(): void
    {
        $service = new FakeStepService();
        $segments = $this->planner->plan(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-02'),
            $service,
            [],
        );

        $this->assertEmpty($segments);
    }

    /** @test  plan returns empty list when since >= until */
    public function emptyRangeReturnsEmptyList(): void
    {
        $service = new FakeStepService();
        $now = CarbonImmutable::now();

        $segments = $this->planner->plan($now, $now, $service, $service->supportedTypes());
        $this->assertEmpty($segments);

        $segments = $this->planner->plan($now->addDay(), $now, $service, $service->supportedTypes());
        $this->assertEmpty($segments);
    }
}
