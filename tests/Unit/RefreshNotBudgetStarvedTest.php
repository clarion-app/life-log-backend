<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Sync\RequestBudget;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * T106: Refresh succeeds with backfill lane exhausted.
 *
 * Token refresh uses the incremental lane of RequestBudget, so it is
 * never starved by an exhausted backfill lane.
 */
class RefreshNotBudgetStarvedTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Configure tight budget limits for testing.
        config()->set('life-log.budget.per_minute', 10);
        config()->set('life-log.budget.per_day', 100);
        config()->set('life-log.budget.incremental_reserve', 3);
        config()->set('life-log.budget.max_requests_per_backfill_run', 10);

        Cache::flush();
    }

    /**
     * The backfill lane can be exhausted without affecting the incremental lane.
     * Backfill hits the incremental_reserve ceiling, but incremental can still
     * consume the full per_minute budget.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function incrementalLaneIndependentOfBackfill(): void
    {
        $budget = new RequestBudget();
        $service = 'test-budget-service';

        // Backfill can consume down to (per_minute - incremental_reserve) = 7.
        $backfill1 = $budget->reserveBackfill($service);
        $backfill2 = $budget->reserveBackfill($service);
        $backfill3 = $budget->reserveBackfill($service);
        $backfill4 = $budget->reserveBackfill($service);
        $backfill5 = $budget->reserveBackfill($service);
        $backfill6 = $budget->reserveBackfill($service);
        $backfill7 = $budget->reserveBackfill($service);

        $this->assertTrue($backfill1);
        $this->assertTrue($backfill2);
        $this->assertTrue($backfill3);
        $this->assertTrue($backfill4);
        $this->assertTrue($backfill5);
        $this->assertTrue($backfill6);
        $this->assertTrue($backfill7);

        // Backfill is now exhausted (hits incremental_reserve at 7).
        $backfill8 = $budget->reserveBackfill($service);
        $this->assertFalse($backfill8);

        // Incremental lane is still available (can consume up to per_minute = 10).
        $incremental1 = $budget->reserveIncremental($service);
        $this->assertTrue($incremental1);
    }

    /**
     * Token refresh can proceed even when backfill is fully exhausted.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function refreshAllowedWhenBackfillExhausted(): void
    {
        $budget = new RequestBudget();
        $service = 'test-refresh-service';

        // Exhaust backfill lane (hits incremental_reserve at 7).
        for ($i = 0; $i < 7; $i++) {
            $budget->reserveBackfill($service);
        }
        $backfillDenied = $budget->reserveBackfill($service);
        $this->assertFalse($backfillDenied);

        // Refresh reserves from the incremental lane — it should succeed.
        $refresh = $budget->reserveIncremental($service);
        $this->assertTrue($refresh);
    }

    /**
     * Both lanes share the per-minute and per-day limits, but backfill has
     * the incremental_reserve protection.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function bothLanesSharePerMinuteLimit(): void
    {
        $budget = new RequestBudget();
        $service = 'test-shared-service';

        // Use 7 backfill requests (hits incremental_reserve).
        for ($i = 0; $i < 7; $i++) {
            $budget->reserveBackfill($service);
        }
        $backfillDenied = $budget->reserveBackfill($service);
        $this->assertFalse($backfillDenied);

        // Incremental can still use remaining per-minute budget.
        // 10 total per-minute - 7 backfill = 3 remaining.
        $inc1 = $budget->reserveIncremental($service);
        $inc2 = $budget->reserveIncremental($service);
        $inc3 = $budget->reserveIncremental($service);
        $this->assertTrue($inc1);
        $this->assertTrue($inc2);
        $this->assertTrue($inc3);

        // Now per-minute limit is exhausted.
        $inc4 = $budget->reserveIncremental($service);
        $this->assertFalse($inc4);
    }

    /**
     * The remaining() method shows available budget for a service.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function remainingShowsAvailableBudget(): void
    {
        $budget = new RequestBudget();
        $service = 'test-remaining-service';

        // Initially, full per_minute budget is available.
        $this->assertEquals(10, $budget->remaining($service));

        // After reserving some, remaining decreases.
        $budget->reserveIncremental($service);
        $budget->reserveIncremental($service);
        $this->assertEquals(8, $budget->remaining($service));
    }
}
