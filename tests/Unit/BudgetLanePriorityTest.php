<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Sync\RequestBudget;
use Illuminate\Support\Facades\Cache;

/**
 * T024 — Budget saturated by reserveBackfill() until denies ⇒ reserveIncremental() still grants.
 *
 * This is SC-012: the incremental lane must remain available even when the
 * backfill lane is saturated. The budget has two lanes sharing a common pool,
 * but the incremental lane has a reserve that backfill cannot consume.
 */
class BudgetLanePriorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Configure budget for testing
        config([
            'life-log.budget.per_minute' => 100,
            'life-log.budget.per_day' => 1000,
            'life-log.budget.incremental_reserve' => 20,
            'life-log.budget.max_requests_per_backfill_run' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @test  backfill can consume budget down to the incremental reserve */
    public function backfillConsumesDownToReserve(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        // Backfill should be able to reserve up to (per_minute - incremental_reserve)
        $backfillCount = 0;
        for ($i = 0; $i < 100; $i++) {
            if ($budget->reserveBackfill($service, 1)) {
                $backfillCount++;
            }
        }

        // Backfill consumed per_minute - incremental_reserve = 80
        $this->assertSame(80, $backfillCount, 'Backfill should consume down to the incremental reserve.');
    }

    /** @test  incremental still grants after backfill saturates its lane */
    public function incrementalGrantsAfterBackfillSaturated(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        // Saturate backfill lane
        while ($budget->reserveBackfill($service, 1)) {
            // keep reserving until denied
        }

        // Incremental should still grant (up to its reserve)
        $incrementalGranted = $budget->reserveIncremental($service, 1);
        $this->assertTrue(
            $incrementalGranted,
            'Incremental lane should still grant after backfill is saturated.',
        );
    }

    /** @test  incremental reserve is protected from backfill */
    public function incrementalReserveIsProtected(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        // Try to exhaust everything with backfill
        $backfillCount = 0;
        while ($budget->reserveBackfill($service, 1)) {
            $backfillCount++;
        }

        // Incremental should still have its reserve available
        $incrementalCount = 0;
        for ($i = 0; $i < 30; $i++) {
            if ($budget->reserveIncremental($service, 1)) {
                $incrementalCount++;
            }
        }

        $this->assertSame(
            20,
            $incrementalCount,
            'Incremental lane should have exactly its reserve available after backfill saturation.',
        );
    }

    /** @test  both lanes together cannot exceed per_minute */
    public function bothLanesCannotExceedPerMinute(): void
    {
        $budget = new RequestBudget();
        $service = 'test-service';

        $total = 0;

        // Exhaust backfill
        while ($budget->reserveBackfill($service, 1)) {
            $total++;
        }

        // Exhaust incremental
        while ($budget->reserveIncremental($service, 1)) {
            $total++;
        }

        $this->assertLessThanOrEqual(
            100,
            $total,
            'Combined lanes cannot exceed per_minute ceiling.',
        );
    }
}
