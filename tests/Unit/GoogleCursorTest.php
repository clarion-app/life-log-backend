<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Google\Paging\GoogleCursor;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * GoogleCursor round-trip and replay detection.
 */
class GoogleCursorTest extends TestCase
{
    /** @test T049 — create and extract pageToken */
    public function createAndExtractPageToken(): void
    {
        $cursor = new GoogleCursor(
            'abc123token',
            MeasurementType::HeartRate,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-15'),
        );

        $this->assertSame('abc123token', $cursor->pageToken);
    }

    /** @test T049 — toPageCursor and fromPageCursor round-trip */
    public function roundTripThroughPageCursor(): void
    {
        $since = CarbonImmutable::parse('2026-02-01')->utc();
        $until = CarbonImmutable::parse('2026-05-01')->utc();

        $original = new GoogleCursor(
            'xyz789token',
            MeasurementType::Steps,
            $since,
            $until,
        );

        $pageCursor = $original->toPageCursor();
        $restored = GoogleCursor::fromPageCursor($pageCursor);

        $this->assertSame('xyz789token', $restored->pageToken);
        $this->assertSame(MeasurementType::Steps, $restored->type);
        $this->assertEquals($since, $restored->since);
        $this->assertEquals($until, $restored->until);
    }

    /** @test T049 — matches() returns true for same type and window */
    public function matchesReturnsTrueForSameTypeAndWindow(): void
    {
        $since = CarbonImmutable::parse('2026-01-01');
        $until = CarbonImmutable::parse('2026-01-15');

        $cursor = new GoogleCursor(
            'token123',
            MeasurementType::HeartRate,
            $since,
            $until,
        );

        $this->assertTrue($cursor->matches(MeasurementType::HeartRate, $since, $until));
    }

    /** @test T049 — matches() returns false for different type */
    public function matchesReturnsFalseForDifferentType(): void
    {
        $since = CarbonImmutable::parse('2026-01-01');
        $until = CarbonImmutable::parse('2026-01-15');

        $cursor = new GoogleCursor(
            'token123',
            MeasurementType::HeartRate,
            $since,
            $until,
        );

        $this->assertFalse($cursor->matches(MeasurementType::Steps, $since, $until));
    }

    /** @test T049 — matches() returns false for different window */
    public function matchesReturnsFalseForDifferentWindow(): void
    {
        $cursor = new GoogleCursor(
            'token123',
            MeasurementType::HeartRate,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-01-15'),
        );

        $this->assertFalse(
            $cursor->matches(
                MeasurementType::HeartRate,
                CarbonImmutable::parse('2026-02-01'),
                CarbonImmutable::parse('2026-02-15'),
            )
        );
    }

    /** @test T049 — fromPageCursor throws InvalidArgumentException for non-Google cursor */
    public function fromPageCursorThrowsForNonGoogleCursor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $pageCursor = PageCursor::fromArray([
            'source' => 'other-service',
            'token'  => 'some-token',
        ]);

        GoogleCursor::fromPageCursor($pageCursor);
    }

    /** @test T049 — replay detection: matches() catches exact same request */
    public function replayDetectionCatchesSameRequest(): void
    {
        $since = CarbonImmutable::parse('2026-03-01 00:00:00');
        $until = CarbonImmutable::parse('2026-03-15 00:00:00');

        $cursor = new GoogleCursor(
            'pageTokenFromPreviousCall',
            MeasurementType::CaloriesBurned,
            $since,
            $until,
        );

        // Same type, same window — this is a replay
        $this->assertTrue($cursor->matches(MeasurementType::CaloriesBurned, $since, $until));
    }
}
