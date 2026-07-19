# clarion-app/life-log-backend

Laravel package for storing and aggregating wearable/life-log measurements.

## Two-Tier Storage Model

### Raw Store (`life_log_raw_measurements`)
- High-frequency ingestion table for wearable device readings
- **Not replicated** via chain
- Deduplicated on `(external_service, external_id)` unique constraint
- Retained for a configurable window (default 90 days), then pruned daily

### Permanent History (`life_log_health_metrics`)
- Hourly-aggregated summaries, bridged to chain via `EloquentMultiChainBridge`
- One entry per `(user_id, external_service, type, unit, bucket_hour)`
- Supports cumulative types (SUM) and point-in-time types (AVG in PHP)
- Soft-deletable, chain-replicated

## Rollup Schedule

The `life-log:rollup` command runs **hourly** via Laravel Scheduler with `withoutOverlapping()`:

```bash
php artisan life-log:rollup
```

Process:
1. Takes a bounded batch of queue rows (`life_log_measurement_rollup_queue`) with `lockForUpdate()`
2. Fetches aggregated values via a single grouped query (SUM/COUNT per bucket)
3. Looks up aggregation type (`cumulative` or `point_in_time`) in `life_log_measurement_type_classifications`
4. Writes `HealthMetric` entries via `firstOrNew()` + `save()` (preserves events for replication)
5. Deletes processed queue rows

### Deferred Reasons

Queue rows may accumulate with `deferred_reason` set:

| Reason | Meaning | Resolution |
|---|---|---|
| `unclassified_type` | Measurement type has no entry in `life_log_measurement_type_classifications` | Add the type to the classification table |
| `overflow` | Computed aggregate exceeds `decimal(16,4)` capacity | Investigate data source; usually indicates a sensor error |

To re-process deferred rows after fixing the classification, add the type then run:
```bash
php artisan life-log:rollup
```

## Retention Config

```env
# Raw measurement retention in days (null = keep forever)
LIFE_LOG_RAW_RETENTION_DAYS=90

# Rollup batch size
LIFE_LOG_ROLLUP_BATCH_SIZE=500

# Maximum measurement value (matches decimal(16,4) capacity)
LIFE_LOG_VALUE_MAX=999999999999.9999
```

The `life-log:prune-raw-measurements` command runs **daily** and deletes raw rows:
- Older than `raw_retention_days`
- That have no outstanding queue entry (pending or deferred)
- In bounded chunks (500 rows per DELETE)

```bash
php artisan life-log:prune-raw-measurements
```

## Provenance

Every `HealthMetric` entry reports a `source`:
- `manual` — user-entered via API (default)
- External service name (e.g., `fitbit`, `garmin`) — rollup-generated

Manual entries may carry an optional `unit` field. Rollup entries always carry the unit from the raw readings.
