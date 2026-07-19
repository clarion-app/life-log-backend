<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\LifeLogBackend\Support\MeasurementBucket;
use Carbon\CarbonImmutable;

class MeasurementBucketTest extends TestCase
{
    /** @test */
    public function buckets_09_59_59_to_09_00(): void
    {
        $recordedAt = CarbonImmutable::parse('2026-07-19 09:59:59.999');
        $bucket = MeasurementBucket::for($recordedAt);

        $this->assertEquals('2026-07-19 09:00:00', $bucket->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $bucket->timezone->getName());
    }

    /** @test */
    public function buckets_10_00_00_to_10_00(): void
    {
        $recordedAt = CarbonImmutable::parse('2026-07-19 10:00:00.000');
        $bucket = MeasurementBucket::for($recordedAt);

        $this->assertEquals('2026-07-19 10:00:00', $bucket->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $bucket->timezone->getName());
    }

    /** @test */
    public function normalizes_offset_input_to_utc_bucket(): void
    {
        // 15:00:00 +05:00 = 10:00:00 UTC → bucket 10:00
        $recordedAt = CarbonImmutable::parse('2026-07-19 15:00:00+05:00');
        $bucket = MeasurementBucket::for($recordedAt);

        $this->assertEquals('2026-07-19 10:00:00', $bucket->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $bucket->timezone->getName());
    }

    /** @test */
    public function normalize_returns_utc_recorded_at(): void
    {
        $recordedAt = CarbonImmutable::parse('2026-07-19 15:30:00+05:00');
        $normalized = MeasurementBucket::normalize($recordedAt);

        $this->assertEquals('2026-07-19 10:30:00', $normalized->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $normalized->timezone->getName());
    }

    /** @test */
    public function already_utc_input_is_unchanged_by_normalize(): void
    {
        $recordedAt = CarbonImmutable::parse('2026-07-19 10:30:00 UTC');
        $normalized = MeasurementBucket::normalize($recordedAt);

        $this->assertEquals('2026-07-19 10:30:00', $normalized->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $normalized->timezone->getName());
    }
}
