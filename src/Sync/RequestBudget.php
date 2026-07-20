<?php

namespace ClarionApp\LifeLogBackend\Sync;

use Illuminate\Support\Facades\Cache;

/**
 * Instance-wide request budget with two lanes: incremental and backfill.
 *
 * The budget prevents the application from exhausting the provider's rate limits
 * by tracking request counts per service across two time windows: per-minute
 * and per-day. Both counters are checked on every reservation, and either can
 * deny.
 *
 * Two lanes share a common pool but the incremental lane has a protected
 * reserve that backfill cannot consume (SC-012). This ensures that ongoing
 * syncs keep working even when a backfill run is consuming budget aggressively.
 *
 * No locking, no sleeping, no retrying — a denial is a yield. The caller
 * decides when to retry.
 *
 * Cache keys:
 *   life-log:budget:{service}:min:{YmdHi} — per-minute counter, TTL 120s
 *   life-log:budget:{service}:day:{Ymd}   — per-day counter, TTL 48h
 *
 * @warning The `file` cache store undercounts under concurrency: two processes
 * reading the same file see the same count and both increment. This means the
 * instance can overspend, never starve. For production instances with multiple
 * workers, use `redis` or `database` cache store.
 */
class RequestBudget
{
    /**
     * Reserve requests for an incremental sync.
     *
     * Incremental syncs have priority: they can consume up to the full per_minute
     * ceiling (minus what's already used), including the reserve that backfill
     * cannot touch.
     *
     * @return bool true if the reservation was granted
     */
    public function reserveIncremental(string $service, int $n = 1): bool
    {
        return $this->reserve($service, $n, false);
    }

    /**
     * Reserve requests for a backfill run.
     *
     * Backfill can consume budget down to the incremental reserve, but not below
     * it. This protects incremental syncs from being starved by aggressive
     * backfill operations.
     *
     * @return bool true if the reservation was granted
     */
    public function reserveBackfill(string $service, int $n = 1): bool
    {
        return $this->reserve($service, $n, true);
    }

    /**
     * Remaining per-minute budget for a service.
     */
    public function remaining(string $service): int
    {
        $perMinute = (int) config('life-log.budget.per_minute', 100000);
        $used = $this->minuteCount($service);

        return max(0, $perMinute - $used);
    }

    /**
     * Check and increment both counters.
     *
     * @param  bool  $isBackfill  true if this is a backfill reservation
     * @return bool true if the reservation was granted
     */
    private function reserve(string $service, int $n, bool $isBackfill): bool
    {
        $perMinute = (int) config('life-log.budget.per_minute', 100000);
        $perDay = (int) config('life-log.budget.per_day', 80000000);
        $incrementalReserve = (int) config('life-log.budget.incremental_reserve', 5000);
        $maxBackfillPerRun = (int) config('life-log.budget.max_requests_per_backfill_run', 500);

        // Check per-minute ceiling
        $minuteUsed = $this->minuteCount($service);
        if ($minuteUsed + $n > $perMinute) {
            return false;
        }

        // Check per-day ceiling
        $dayUsed = $this->dayCount($service);
        if ($dayUsed + $n > $perDay) {
            return false;
        }

        // For backfill: check incremental reserve protection
        if ($isBackfill) {
            // Backfill can consume down to (per_minute - incremental_reserve)
            $backfillLimit = $perMinute - $incrementalReserve;
            if ($minuteUsed + $n > $backfillLimit) {
                return false;
            }

            // Check per-run limit for backfill
            $runKey = "life-log:budget:{$service}:run:" . spl_object_id($this);
            $runUsed = (int) Cache::get($runKey, 0);
            if ($runUsed + $n > $maxBackfillPerRun) {
                return false;
            }
        }

        // Atomically increment both counters
        $minuteOk = $this->incrementMinute($service, $n);
        if (!$minuteOk) {
            return false;
        }

        $dayOk = $this->incrementDay($service, $n);
        if (!$dayOk) {
            // Roll back minute increment (best effort)
            $this->rollBackMinute($service, $n);
            return false;
        }

        // For backfill, track per-run count
        if ($isBackfill) {
            $runKey = "life-log:budget:{$service}:run:" . spl_object_id($this);
            Cache::increment($runKey, $n);
        }

        return true;
    }

    private function minuteCount(string $service): int
    {
        $key = "life-log:budget:{$service}:min:" . now()->format('YmdHi');
        return (int) Cache::get($key, 0);
    }

    private function dayCount(string $service): int
    {
        $key = "life-log:budget:{$service}:day:" . now()->format('Ymd');
        return (int) Cache::get($key, 0);
    }

    private function incrementMinute(string $service, int $n): bool
    {
        $key = "life-log:budget:{$service}:min:" . now()->format('YmdHi');

        // Use Cache::add for atomic initialization, then increment
        $current = (int) Cache::get($key, 0);
        $perMinute = (int) config('life-log.budget.per_minute', 100000);

        if ($current + $n > $perMinute) {
            return false;
        }

        Cache::increment($key, $n);

        // Set TTL if this is a new key (120 seconds)
        if ($current === 0) {
            Cache::add($key, $n, now()->addSeconds(120));
        }

        return true;
    }

    private function incrementDay(string $service, int $n): bool
    {
        $key = "life-log:budget:{$service}:day:" . now()->format('Ymd');

        $current = (int) Cache::get($key, 0);
        $perDay = (int) config('life-log.budget.per_day', 80000000);

        if ($current + $n > $perDay) {
            return false;
        }

        Cache::increment($key, $n);

        // Set TTL if this is a new key (48 hours)
        if ($current === 0) {
            Cache::add($key, $n, now()->addHours(48));
        }

        return true;
    }

    private function rollBackMinute(string $service, int $n): void
    {
        $key = "life-log:budget:{$service}:min:" . now()->format('YmdHi');
        Cache::decrement($key, $n);
    }
}
