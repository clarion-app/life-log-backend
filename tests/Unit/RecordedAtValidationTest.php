<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Support\RecordedAtValidator;
use ClarionApp\LifeLogBackend\Exceptions\ImplausibleTimestampException;
use Carbon\CarbonImmutable;

/**
 * Implausible timestamps are skipped.
 * Measurements must have recorded_at between 2000-01-01 and now+FUTURE_TOLERANCE.
 */
class RecordedAtValidationTest extends TestCase
{
    /** @test T053 — plausible timestamp passes */
    public function plausibleTimestampPasses(): void
    {
        $validator = new RecordedAtValidator();
        $timestamp = CarbonImmutable::parse('2025-07-20 10:00:00', 'UTC');

        // Should not throw
        $validator->assertPlausible($timestamp);
        $this->assertTrue(true);
    }

    /** @test T053 — timestamp before 2000-01-01 fails */
    public function timestampBeforeTwoThousandFails(): void
    {
        $validator = new RecordedAtValidator();
        $timestamp = CarbonImmutable::parse('1999-12-31 23:59:59', 'UTC');

        $this->expectException(ImplausibleTimestampException::class);
        $validator->assertPlausible($timestamp);
    }

    /** @test T053 — timestamp too far in the future fails */
    public function timestampTooFarInFutureFails(): void
    {
        $validator = new RecordedAtValidator();
        $timestamp = CarbonImmutable::parse('2031-01-01 00:00:00', 'UTC');

        $this->expectException(ImplausibleTimestampException::class);
        $validator->assertPlausible($timestamp);
    }

    /** @test T053 — timestamp at exactly 2000-01-01 passes */
    public function timestampAtTwoThousandPasses(): void
    {
        $validator = new RecordedAtValidator();
        $timestamp = CarbonImmutable::parse('2000-01-01 00:00:00', 'UTC');

        // Should not throw
        $validator->assertPlausible($timestamp);
        $this->assertTrue(true);
    }

    /** @test T053 — far future timestamp fails */
    public function farFutureTimestampFails(): void
    {
        $validator = new RecordedAtValidator();
        $timestamp = CarbonImmutable::parse('2100-01-01 00:00:00', 'UTC');

        $this->expectException(ImplausibleTimestampException::class);
        $validator->assertPlausible($timestamp);
    }

    /** @test T053 — epoch timestamp (1970) fails */
    public function epochTimestampFails(): void
    {
        $validator = new RecordedAtValidator();
        $timestamp = CarbonImmutable::parse('1970-01-01 00:00:00', 'UTC');

        $this->expectException(ImplausibleTimestampException::class);
        $validator->assertPlausible($timestamp);
    }
}
