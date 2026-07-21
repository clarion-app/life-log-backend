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

---

# External Health Services

Every wearable, scale, or health platform reaches this package through one
interface. Everything a service is peculiar about — field names, payload
nesting, source units, error shapes, how it pages — is consumed inside its own
implementation, so adding the fourth service costs the same as adding the
second.

## The Vocabulary

Services never name a type of their own. They translate into a fixed vocabulary
whose backed values are permanent storage names: once released, a case value is
never renamed and never reused for a different meaning. Growth is additive only.

### Measurement types (`Vocabulary\MeasurementType`)

| Type | Canonical unit | Rollup aggregation |
|---|---|---|
| `steps` | `count` | cumulative |
| `heart_rate` | `bpm` | point in time |
| `weight` | `kg` | point in time |
| `calories_burned` | `kcal` | cumulative |
| `distance` | `m` | cumulative |
| `active_minutes` | `s` | cumulative |

### Session types (`Vocabulary\SessionType`)

A session covers a stretch of time rather than an instant, so it is not a
measurement. Each type declares the summary values it may carry and the
canonical unit of each. A session may omit any declared key; it may never carry
an undeclared one.

| Type | Summary values |
|---|---|
| `sleep` | `duration` (s), `asleep_duration` (s) |
| `workout` | `duration` (s), `distance` (m), `energy` (kcal) |

### Unit conversion

`Support\UnitConverter::toCanonical()` scales a source value into its type's
canonical unit using exact integer rationals evaluated in bcmath — never float
literals. A float factor can round differently across platforms, and because raw
dedup upserts on `(external_service, external_id)`, a re-import would overwrite
a stored value with a drifted one while the row count stayed correct.

Supported conversions: `lb→kg`, `mi→m`, `km→m`, `min→s`, `h→s`, `kJ→kcal`.
Anything else raises `UnconvertibleUnitException`, which the service catches to
skip and record that one item.

`life-log:sync-vocabulary` projects the table above into
`life_log_measurement_type_classifications`, which the hourly rollup already
reads. Run it after any deployment that adds a vocabulary case.

## The Four Behaviors

`Contracts\ExternalHealthService` requires exactly four:

1. **`beginConnection(string $userId): ConnectionResult`** — start connecting an
   account. Stores nothing.
2. **`fetch(string $userId, CarbonImmutable $since, CarbonImmutable $until, ?PageCursor $cursor = null): ResultPage`**
   — one page of a time range, already translated into vocabulary form.
3. **`renewAccess(string $userId): RenewalResult`** — renew expired access. A
   declined renewal is an ordinary result, not an exception.
4. **`disconnect(string $userId): DisconnectResult`** — succeeds even for an
   already-disconnected account.

Plus `name()` (the stable registry name) and `supportedTypes()`, which returns
vocabulary enum instances so an off-vocabulary type is unrepresentable. Partial
coverage is normal — a scale supplies weight and nothing else — and a caller
asking for a range implying an unsupported type gets what the service does have,
not a failure.

Every return type is a final readonly value object, so no service payload is
reachable from anything the interface hands back.

## The Six Failure Kinds

Every error path raises `HealthServiceFailure` carrying one `Contracts\FailureKind`
case. Vendor status codes and error strings are mapped inside the service and
never reach the caller. The set is closed and split by downstream response
rather than severity, so callers `match()` on it exhaustively.

| Kind | What the caller should do |
|---|---|
| `access_expired` | Renew, then retry |
| `access_revoked` | Do not renew; prompt the user to reconnect |
| `credentials_rejected` | Halt and alert an administrator |
| `rate_limited` | Back off, honoring `retryAfterSeconds` when present |
| `service_unavailable` | Retry later with backoff |
| `invalid_request` | A bug — surface it; do not retry unchanged |

Unmappable types and unconvertible units are **not** failures. They are skipped,
passed to `Services\UnmappedTypeRecorder` (which never throws), and the rest of
the page still returns. Timestamps that are missing or outside the plausible
window — before 2000-01-01Z or more than 24 hours ahead — are rejected by
`Support\RecordedAtValidator` with `ImplausibleTimestampException` and handled
the same way.

## The Paging Contract

`fetch()` returns a `ResultPage`; `nextCursor()` is null **only at true
exhaustion**. Emptiness and exhaustion are independent signals, so all four
combinations are legal:

| Page | Cursor | Meaning |
|---|---|---|
| results | cursor | ordinary mid-range page |
| results | `null` | final page, with data on it |
| empty | cursor | a sparse span — keep going |
| empty | `null` | the range held nothing; not a failure |

A caller that stopped on the first empty page would silently truncate a backfill
at the first stretch the user did not wear their device.

A `PageCursor` is an opaque string whose contents belong to the issuing service,
but it must survive `PageCursor::fromString($cursor->toString())` — a backfill
spanning years gets interrupted and resumes from whatever was persisted to a
database column. `PageCursor::fromArray()` therefore rejects anything that is
not a JSON-serializable scalar. A cursor the service no longer honors raises
`invalid_request`; it never silently restarts the range, which would re-deliver
everything already written.

## Registering a Service

A service package registers itself from its own provider's `boot()` and edits no
file in this package:

```php
public function boot(): void
{
    $this->app->make(HealthServiceRegistry::class)
        ->register('acme-band', fn () => new AcmeBandService(/* ... */));
}
```

Registration takes a factory, not an instance, so `boot()` stays cheap — no HTTP
client is constructed and no credential is read for a service this request will
never touch. `resolve()` memoizes, because a service carries in-flight state
such as the generation a cursor was issued under.

A name already in use throws `DuplicateServiceRegistrationException`; an unknown
name throws `UnknownServiceException`. A factory whose product reports a
different `name()` than it was registered under is rejected, since the registry
name is what gets written into every row and dedup resolves those rows back
through it.

Test obligation is one method: extend `HealthServiceConformanceTestCase` and
return the service from `service()`. The inherited suite checks all four
behaviors, the paging contract including cursor round-trip and exactly-once
delivery, that every failure is one of the six kinds, and that every emitted
measurement and session is vocabulary-conformant in canonical units.

## Session Storage

Sessions follow the same two-tier shape as measurements:

- **`life_log_raw_health_sessions`** — ingestion table, not chain-replicated,
  deduplicated on `(external_service, external_id)`, written by
  `Services\RawSessionWriter`.
- **`life_log_health_sessions`** — permanent, chain-replicated history, written
  by `Services\SessionPromoter`. `source` is assigned from the raw row's
  `external_service`, never defaulted to `manual`.

Promotion scans for `promoted_at IS NULL` and writes through model saves rather
than `upsert()`, which would bypass the events the chain bridge listens for.
Only promoted raw sessions are ever pruned.

## Service Commands

| Command | Schedule | Purpose |
|---|---|---|
| `life-log:sync-vocabulary` | on demand, after deploy | Project the vocabulary's aggregation rules into the classification table |
| `life-log:promote-sessions` | hourly, `withoutOverlapping()` | Promote raw sessions into permanent, replicated history |
| `life-log:prune-raw-sessions` | daily, `withoutOverlapping()` | Delete promoted raw sessions past the retention window |

```env
# Raw session retention in days (null = keep forever)
LIFE_LOG_RAW_SESSION_RETENTION_DAYS=90
```

`life-log:sync-vocabulary` is deliberately unscheduled: the vocabulary only
changes when code does, so it belongs to the deployment step, not the clock.
Running it repeatedly is a true no-op — a row that already agrees is left
untouched, timestamps included.

---

# Account Connections

Every service credential and every user's connection to an external service
is managed entirely through the API — `/service-credentials` and
`/connected-accounts`. Nothing is configured from a file, and nothing
requires a restart.

## Two things to know before you rely on this in production

**`APP_KEY` rotation is unrecoverable for this data.** `ServiceCredential.client_secret`
and `AccountAuthorization.access_token`/`refresh_token` use Laravel's `encrypted`
cast, which is keyed off `APP_KEY`. Rotating `APP_KEY` renders every stored
secret and token unreadable — there is no re-encryption path in this feature.
Recovery is manual: re-enter each service's credentials via `PUT
/service-credentials/{service}`, and have each user reconnect their account.
Plan `APP_KEY` rotation accordingly.

**Replicated credential secrets are plaintext on the chain, permanently.**
`ServiceCredential` is bridged like every other model in this package, and the
bridge publishes `toArray()` — the *decrypted* secret — so that a credential
configured on one node is immediately usable on every other (SC-002a). The
`encrypted` cast protects the local database; it does not, and cannot, protect
the replicated copy, because each node has its own `APP_KEY` and none of them
could decrypt a secret encrypted under a different one. The chain is
append-only, so rotating a secret does not retract the previous value — every
value a credential ever held remains in stream history permanently. This is
only safe on a network of nodes the operator actually trusts; it is not a
confidentiality boundary against an untrusted peer.

Everything else about a credential's exposure is a solved problem: it never
appears in an API response, error, or log (`CredentialSecretExposureTest`,
`LogRedactionTest`). This is the one place the answer is "the operator's
network is the trust boundary," not "the code stops it."

---

# Google Health API — Version Pin

The Google Health API client pins a specific API version in
`src/Google/Api/ApiVersion.php`. Every URI the client constructs carries this
pin, and `ApiVersionPinTest` asserts that the pinned string appears in the
captured request URI — so a pin change is a deliberate edit with a failing
test attached.

**Monitoring duty**: Watch the [Google Health API release notes](https://developers.google.com/healthcare-api/releases)
for version announcements. When the API version changes:

1. Update the `VERSION` constant in `src/Google/Api/ApiVersion.php`.
2. Re-capture test fixtures under `tests/Support/Fixtures/google/` — each
   fixture is stamped with the API version and capture date it was produced
   against, and a pin bump invalidates them.
3. Run the test suite. `ApiVersionPinTest` will fail until the new version
   appears in the client's constructed URIs.

This is an operational instruction, not an automated process. A self-hosted
node has nothing to poll release notes with — it is a manual check before
deploying after a known Google API update.
