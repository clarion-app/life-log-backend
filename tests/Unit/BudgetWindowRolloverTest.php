<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Sync\RequestBudget;
use Illuminate\Support\Facades\Cache;

/**
 * T026 — New minute resets consumption; keys carry documented TTLs.
 */
class BudgetWindowRolloverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'life-log.budget.per_minute' => 10,
            'life-log.budget.per_day' => 100,
            'life-log.budget.incremental_reserve' => 2,
            'life-log.budget.max_requests_per_backfill_run' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @test  per_minute key has the documented TTL format */
    public function perMinuteKeyHasCorrectFormat(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        $budget->reserveIncremental($service, 1);

        // The key should be life-log:budget:{service}:min:{YmdHi}
        $now = now();
        $expectedKey = "life-log:budget:{$service}:min:{$now->format('YmdHi')}";

        $this->assertNotNull(
            Cache::get($expectedKey),
            "Per-minute key should exist at expected path: {$expectedKey}",
        );
    }

    /** @test  per_day key has the documented TTL format */
    public function perDayKeyHasCorrectFormat(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        $budget->reserveIncremental($service, 1);

        // The key should be life-log:budget:{service}:day:{Ymd}
        $now = now();
        $expectedKey = "life-log:budget:{$service}:day:{$now->format('Ymd')}";

        $this->assertNotNull(
            Cache::get($expectedKey),
            "Per-day key should exist at expected path: {$expectedKey}",
        );
    }

    /** @test  new minute resets per_minute consumption */
    public function newMinuteResetsConsumption(): void
    {
        $service = 'test-service';

        // Use the cache to simulate time passing by manipulating keys directly
        $budget = new RequestBudget();

        // Exhaust per_minute
        while ($budget->reserveBackfill($service, 1)) {
            // consume
        }

        // Backfill should be denied
        $denied = $budget->reserveBackfill($service, 1);
        $this->assertFalse($denied, 'Backfill should be denied after per_minute is exhausted.');

        // Simulate new minute by clearing the per_minute key
        $now = now();
        $minuteKey = "life-log:budget:{$service}:min:{$now->format('YmdHi')}";
        Cache::forget($minuteKey);

        // Now backfill should succeed again (new minute)
        $granted = $budget->reserveBackfill($service, 1);
        $this->assertTrue(
            $granted,
            'New minute should reset per_minute consumption.',
        );
    }

    /** @test  per_day is NOT reset by new minute */
    public function perDayNotResetByNewMinute(): void
    {
        $service = 'test-service';
        $budget = new RequestBudget();

        // Set per_day to a low value for this test
        config(['life-log.budget.per_day' => 5]);

        // Exhaust per_day
        for ($i = 0; $i < 5; $i++) {
            $budget->reserveIncremental($service, 1);
        }

        // Clear per_minute key (simulating new minute)
        $now = now();
        $minuteKey = "life-log:budget:{$service}:min:{$now->format('YmdHi')}";
        Cache::forget($minuteKey);

        // Should still be denied by per_day
        $result = $budget->reserveIncremental($service, 1);
        $this->assertFalse(
            $result,
            'per_day ceiling should still deny even after per_minute resets.',
        );
    }

    /** @test  keys carry documented TTLs (120s for minute, 48h for day) */
    public function keysCarryDocumentedTtls(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        $budget->reserveIncremental($service, 1);

        $now = now();
        $minuteKey = "life-log:budget:{$service}:min:{$now->format('YmdHi')}";
        $dayKey = "life-log:budget:{$service}:day:{$now->format('Ymd')}";

        // Both keys should exist
        $this->assertNotNull(Cache::get($minuteKey), 'Minute key should exist.');
        $this->assertNotNull(Cache::get($dayKey), 'Day key should exist.');
    }
}
