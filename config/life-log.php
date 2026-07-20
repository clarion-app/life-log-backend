<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Raw Measurement Retention
    |--------------------------------------------------------------------------
    |
    | The number of days to retain raw measurements before they are eligible
    | for cleanup. Defaults to null (keep forever).
    |
    | WARNING: A 90-day default silently deletes a five-year backfill ninety
    | days after it lands. Set LIFE_LOG_RAW_RETENTION_DAYS via env var only
    | if you explicitly want pruning.
    |
    */
    'raw_retention_days' => env('LIFE_LOG_RAW_RETENTION_DAYS', null),

    /*
    |--------------------------------------------------------------------------
    | Raw Session Retention
    |--------------------------------------------------------------------------
    |
    | The number of days to retain raw health sessions before they are eligible
    | for cleanup. Defaults to null (keep forever).
    | Only sessions that have already been promoted are ever pruned.
    |
    | WARNING: A 90-day default silently deletes a five-year backfill ninety
    | days after it lands. Set LIFE_LOG_RAW_SESSION_RETENTION_DAYS via env var
    | only if you explicitly want pruning.
    |
    */
    'raw_session_retention_days' => env('LIFE_LOG_RAW_SESSION_RETENTION_DAYS', null),

    /*
    |--------------------------------------------------------------------------
    | Rollup Batch Size
    |--------------------------------------------------------------------------
    |
    | The number of raw measurements to process in a single rollup batch.
    | Larger batches are more efficient but consume more memory.
    |
    */
    'rollup_batch_size' => (int) env('LIFE_LOG_ROLLUP_BATCH_SIZE', 500),

    /*
    |--------------------------------------------------------------------------
    | Maximum Measurement Value
    |--------------------------------------------------------------------------
    |
    | The maximum allowed value for a measurement. This should match the
    | capacity of the decimal(16,4) column used for the value field.
    |
    */
    'value_max' => env('LIFE_LOG_VALUE_MAX', '999999999999.9999'),

    /*
    |--------------------------------------------------------------------------
    | Account Sync: Overlap Hours
    |--------------------------------------------------------------------------
    |
    | Number of hours to look back beyond the last known sync point on each
    | run, to catch late-arriving or corrected readings from the provider.
    |
    */
    'sync_overlap_hours' => (int) env('LIFE_LOG_SYNC_OVERLAP_HOURS', 72),

    /*
    |--------------------------------------------------------------------------
    | Account Sync: Backoff Minutes
    |--------------------------------------------------------------------------
    |
    | Array of minute values forming the backoff ladder after consecutive
    | failures. The policy indexes into this array by the failure count
    | (pre-increment), clamping to the last element for any higher count.
    |
    */
    'sync_backoff_minutes' => [
        60, 240, 240, 240,
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Sync: Max Consecutive Failures
    |--------------------------------------------------------------------------
    |
    | Number of consecutive counted failures before the account is flagged
    | as needs_attention and removed from the automatic sweep.
    |
    */
    'sync_max_consecutive_failures' => (int) env('LIFE_LOG_SYNC_MAX_CONSECUTIVE_FAILURES', 5),

    /*
    |--------------------------------------------------------------------------
    | Account Sync: Max Pages Per Run
    |--------------------------------------------------------------------------
    |
    | Upper bound on fetch pages per sync run. Hitting this cap returns a
    | partial outcome, keeps the cursor, and leaves the checkpoint unchanged.
    |
    */
    'sync_max_pages_per_run' => (int) env('LIFE_LOG_SYNC_MAX_PAGES_PER_RUN', 200),

    /*
    |--------------------------------------------------------------------------
    | Account Sync: Jitter Seconds
    |--------------------------------------------------------------------------
    |
    | Maximum jitter (in seconds) applied to job dispatch delays from the
    | sweep command. Actual delay is deterministic: crc32(account_id) mod
    | this value, so the same account always gets the same delay.
    |
    */
    'sync_jitter_seconds' => (int) env('LIFE_LOG_SYNC_JITTER_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Account Backfill: Jitter Seconds
    |--------------------------------------------------------------------------
    |
    | Maximum jitter (in seconds) applied to backfill job dispatch delays
    | from the sweep command. Separate from sync jitter so the two cadences
    | don't thunder together.
    |
    */
    'backfill_jitter_seconds' => (int) env('LIFE_LOG_BACKFILL_JITTER_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Account Sync: Lock Seconds
    |--------------------------------------------------------------------------
    |
    | Duration of the distributed lock per account. Must exceed the job
    | timeout (SyncConnectedAccountJob::TIMEOUT = 600s). The lock is
    | non-blocking: if held, the run returns skipped immediately.
    |
    */
    'sync_lock_seconds' => (int) env('LIFE_LOG_SYNC_LOCK_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Account Sync: Attempt Retention Days
    |--------------------------------------------------------------------------
    |
    | Number of days to retain sync attempt records before they are eligible
    | for pruning. Set to null to disable automatic retention.
    |
    */
    'sync_attempt_retention_days' => env('LIFE_LOG_SYNC_ATTEMPT_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Token Refresh: Lock Seconds
    |--------------------------------------------------------------------------
    |
    | Duration of the per-account token refresh lock. Short and blocking,
    | unlike the sync lock: a refresh is one HTTP call, and the caller that
    | loses the race needs the token the winner is about to produce.
    |
    */
    'token_refresh_lock_seconds' => (int) env('LIFE_LOG_TOKEN_REFRESH_LOCK_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Token Refresh: Wait Seconds
    |--------------------------------------------------------------------------
    |
    | Maximum time a caller blocks waiting for another in-flight refresh of
    | the same account to finish before giving up.
    |
    */
    'token_refresh_wait_seconds' => (int) env('LIFE_LOG_TOKEN_REFRESH_WAIT_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Request Budget
    |--------------------------------------------------------------------------
    |
    | Two-lane budget manager that guards against quota exhaustion.
    |
    | - per_minute: maximum requests per calendar minute (any lane)
    | - per_day: maximum requests per calendar day (any lane)
    | - incremental_reserve: bytes reserved in the daily lane for incremental
    |   syncs; backfill cannot consume this reserve
    | - max_requests_per_backfill_run: hard cap on fetch calls per backfill
    |   run to prevent runaway consumption
    |
    | Cache keys use the configured Laravel cache store:
    |   life-log:budget:{service}:min:{YmdHi}  (TTL: 120s)
    |   life-log:budget:{service}:day:{Ymd}    (TTL: 48h)
    |
    | WARNING: The file cache driver does not support atomic increment.
    |          Budget enforcement requires a cache driver that does
    |          (database, redis, memcached). File cache will silently
    |          skip enforcement.
    |
    */
    'budget' => [
        'per_minute' => (int) env('LIFE_LOG_BUDGET_PER_MINUTE', 100000),
        'per_day' => (int) env('LIFE_LOG_BUDGET_PER_DAY', 80000000),
        'incremental_reserve' => (int) env('LIFE_LOG_BUDGET_INCREMENTAL_RESERVE', 5000),
        'max_requests_per_backfill_run' => (int) env('LIFE_LOG_BUDGET_MAX_REQUESTS_PER_BACKFILL_RUN', 500),
    ],

];
