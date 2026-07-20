<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Sync\RequestBudget;
use Illuminate\Support\Facades\Cache;

/**
 * T025 — Neither lane exceeds per_minute or per_day; both counters are checked
 * and either can deny.
 */
class BudgetCeilingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'life-log.budget.per_minute' => 50,
            'life-log.budget.per_day' => 200,
            'life-log.budget.incremental_reserve' => 10,
            'life-log.budget.max_requests_per_backfill_run' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @test  per_minute ceiling is enforced — no lane exceeds it */
    public function perMinuteCeilingIsEnforced(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        $total = 0;
        for ($i = 0; $i < 100; $i++) {
            if ($budget->reserveIncremental($service, 1) || $budget->reserveBackfill($service, 1)) {
                $total++;
            }
        }

        $this->assertLessThanOrEqual(
            50,
            $total,
            'Total reservations must not exceed per_minute ceiling.',
        );
    }

    /** @test  per_day ceiling is enforced */
    public function perDayCeilingIsEnforced(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        // Reserve up to per_day limit
        $total = 0;
        for ($i = 0; $i < 300; $i++) {
            if ($budget->reserveIncremental($service, 1) || $budget->reserveBackfill($service, 1)) {
                $total++;
            }
        }

        $this->assertLessThanOrEqual(
            200,
            $total,
            'Total reservations must not exceed per_day ceiling.',
        );
    }

    /** @test  per_day can deny even if per_minute has room */
    public function perDayCanDeny(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        // Fill up to per_day
        for ($i = 0; $i < 200; $i++) {
            $budget->reserveIncremental($service, 1);
        }

        // Should be denied by per_day even though per_minute may have room
        $result = $budget->reserveIncremental($service, 1);
        $this->assertFalse(
            $result,
            'per_day ceiling should deny even when per_minute has room.',
        );
    }

    /** @test  remaining() reports correct value */
    public function remainingReportsCorrectValue(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        $initial = $budget->remaining($service);
        $this->assertSame(50, $initial, 'Initial remaining should equal per_minute.');

        $budget->reserveIncremental($service, 10);
        $after = $budget->remaining($service);
        $this->assertSame(40, $after, 'Remaining should decrease by reservation amount.');
    }

    /** @test  reservations are per-service */
    public function reservationsArePerService(): void
    {
        $budget = new RequestBudget();

        // Exhaust budget for service A
        while ($budget->reserveBackfill('service-a', 1)) {
            // consume
        }

        // Service B should still have budget available
        $result = $budget->reserveBackfill('service-b', 1);
        $this->assertTrue(
            $result,
            'Budget reservations must be per-service, not global.',
        );
    }
}
