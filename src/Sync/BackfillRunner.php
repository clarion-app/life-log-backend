<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Models\AccountBackfillState;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Services\RawSessionWriter;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Runs a single account backfill: acquires the shared SyncLock, derives
 * per-type backfill windows, pages backwards through the service, writes
 * data transactionally, and advances the boundary.
 *
 * Shares SyncLock with AccountSyncRunner — one lock per account.
 * Failures delegate to 056's FailurePolicy against the same AccountSyncState.
 * Boundary advances only on exhaustion (FR-015c).
 *
 * run() never throws for an ordinary service failure — it returns a BackfillResult
 * carrying a SyncOutcome. It may propagate a genuine programming error
 * (TypeError, container resolution failure), which the queue records as a
 * failed job.
 */
final class BackfillRunner
{
    private WindowPlanner $planner;

    private RequestBudget $budget;

    private int $maxRequestsPerRun;

    private int $maxPages;

    public function __construct(
        private HealthServiceRegistry $registry,
        private RawMeasurementWriter $measurements,
        private RawSessionWriter $sessions,
        private FailurePolicy $policy,
        private SyncLock $locks,
        private SyncAttemptRecorder $recorder,
        private TokenRefreshCoordinator $tokenRefresh,
        ?RequestBudget $budget = null,
        ?WindowPlanner $planner = null,
        int $maxRequestsPerRun = 0,
        int $maxPages = 0,
    ) {
        $this->budget = $budget ?? app(RequestBudget::class);
        $this->planner = $planner ?? app(WindowPlanner::class);
        $this->maxRequestsPerRun = $maxRequestsPerRun;
        $this->maxPages = $maxPages;

        if ($this->maxRequestsPerRun <= 0) {
            $this->maxRequestsPerRun = (int) config('life-log.budget.max_requests_per_backfill_run', 50);
        }

        if ($this->maxPages <= 0) {
            $this->maxPages = (int) config('life-log.sync_max_pages_per_run', 200);
        }
    }

    /**
     * Run a backfill for the given account.
     *
     * @param  MeasurementType|SessionType|null  $type  Optional single type to backfill (null for all granted incomplete types).
     */
    public function run(
        ConnectedAccount $account,
        MeasurementType|SessionType|null $type = null,
    ): BackfillResult {
        $startedAt = CarbonImmutable::now();
        $result = null;

        try {
            // Non-blocking lock — if held, return skipped immediately
            $result = $this->locks->attempt($account->id, function () use ($account, $startedAt, $type) {
                return $this->execute($account, $startedAt, $type);
            });

            if ($result === null) {
                // Lock was held — return skipped
                $result = new BackfillResult(
                    outcome: SyncOutcome::Skipped,
                    startedAt: $startedAt,
                    finishedAt: CarbonImmutable::now(),
                );
            }
        } finally {
            // Record the attempt (never throws) — called on every path
            if ($result !== null) {
                $this->recorder->record(
                    $account->id,
                    SyncTrigger::Backfill,
                    new SyncResult(
                        outcome: $result->outcome,
                        since: $startedAt,
                        until: $result->finishedAt,
                        pagesFetched: $result->pagesFetched,
                        startedAt: $startedAt,
                        finishedAt: $result->finishedAt,
                    ),
                );
            }
        }

        return $result;
    }

    /**
     * Execute the backfill (called inside the lock callback).
     */
    private function execute(
        ConnectedAccount $account,
        CarbonImmutable $startedAt,
        MeasurementType|SessionType|null $singleType,
    ): BackfillResult {
        $now = CarbonImmutable::now();

        // Lazy-create the sync state row (firstOrCreate)
        $syncState = AccountSyncState::firstOrCreate(
            ['connected_account_id' => $account->id],
            [],
        );

        // Resolve the service from the registry
        $service = $this->registry->resolve($account->external_service);

        // Determine which types to backfill
        $typesToBackfill = $this->resolveTypesToBackfill($account, $service, $singleType);

        if (empty($typesToBackfill)) {
            return new BackfillResult(
                outcome: SyncOutcome::Success,
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
            );
        }

        $totalPages = 0;
        $totalRequests = 0;
        $typeOutcomes = [];

        try {
            // Process each type
            foreach ($typesToBackfill as $typeInstance) {
                // Check per-run request cap
                if ($totalRequests >= $this->maxRequestsPerRun) {
                    break; // Yield the account lock — budget still has room but this run is done
                }

                $typeOutcome = $this->backfillType(
                    $account,
                    $syncState,
                    $service,
                    $typeInstance,
                    $now,
                    $startedAt,
                    $totalPages,
                    $totalRequests,
                );

                $typeOutcomes[$typeInstance->value] = $typeOutcome;
                $totalPages += $typeOutcome->pagesFetched;
            }

            // Determine overall outcome
            $outcome = $this->determineOutcome($typeOutcomes, $totalRequests);

            return new BackfillResult(
                outcome: $outcome,
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
                pagesFetched: $totalPages,
                requestsUsed: $totalRequests,
                typeOutcomes: $typeOutcomes,
            );
        } catch (HealthServiceFailure $failure) {
            // Route to FailurePolicy (uses the same AccountSyncState)
            $response = $this->policy->apply($syncState, $failure, CarbonImmutable::now());
            $this->applyFailureResponse($syncState, $account, $response, $failure->kind, CarbonImmutable::now());

            return new BackfillResult(
                outcome: SyncOutcome::Failure,
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
                pagesFetched: $totalPages,
                requestsUsed: $totalRequests,
                typeOutcomes: $typeOutcomes,
            );
        }
    }

    /**
     * Resolve which types to backfill.
     *
     * @return list<MeasurementType|SessionType>
     */
    private function resolveTypesToBackfill(
        ConnectedAccount $account,
        ExternalHealthService $service,
        MeasurementType|SessionType|null $singleType,
    ): array {
        if ($singleType !== null) {
            return [$singleType];
        }

        // Get all granted types
        $grantedTypes = $service->supportedTypes();
        if (empty($grantedTypes)) {
            $grantedTypes = array_merge(
                MeasurementType::cases(),
                SessionType::cases(),
            );
        }

        // Filter to incomplete types only
        $incompleteTypes = [];
        foreach ($grantedTypes as $type) {
            $backfillState = AccountBackfillState::where([
                'connected_account_id' => $account->id,
                'type' => $type->value,
            ])->first();

            if ($backfillState === null || !$backfillState->isComplete()) {
                $incompleteTypes[] = $type;
            }
        }

        return $incompleteTypes;
    }

    /**
     * Backfill a single type.
     *
     * @return BackfillTypeOutcome
     */
    private function backfillType(
        ConnectedAccount $account,
        AccountSyncState $syncState,
        ExternalHealthService $service,
        MeasurementType|SessionType $type,
        CarbonImmutable $now,
        CarbonImmutable $startedAt,
        int &$totalPages,
        int &$totalRequests,
    ): BackfillTypeOutcome {
        // Get or create backfill state for this type
        $backfillState = AccountBackfillState::firstOrCreate(
            [
                'connected_account_id' => $account->id,
                'type' => $type->value,
            ],
            [
                'backfilled_to' => $now,
            ],
        );

        // Skip if already complete
        if ($backfillState->isComplete()) {
            return new BackfillTypeOutcome(
                type: $type->value,
                skipped: true,
            );
        }

        // Derive the backfill window
        $window = BackfillWindow::next($backfillState, $service);
        if ($window === null) {
            return new BackfillTypeOutcome(
                type: $type->value,
                skipped: true,
            );
        }

        $pagesFetched = 0;
        $measurementsWritten = 0;
        $sessionsWritten = 0;
        $cursor = null;

        // Page loop
        while (true) {
            // Check per-run request cap
            if ($totalRequests >= $this->maxRequestsPerRun) {
                break;
            }

            // Check page cap
            if ($totalPages >= $this->maxPages) {
                break;
            }

            // Reserve budget for backfill
            if (!$this->budget->reserveBackfill($account->external_service, 1)) {
                // Budget denied — yield (no failure recorded)
                break;
            }

            $totalRequests++;

            // Fetch with optional renewal retry for AccessExpired
            $page = null;
            $renewalAttempted = false;

            while (true) {
                try {
                    $page = $service->fetch(
                        $account->user_id,
                        $window->since,
                        $window->until,
                        $cursor,
                        [$type],
                    );
                    break; // Success — exit retry loop
                } catch (HealthServiceFailure $failure) {
                    if ($failure->kind === FailureKind::AccessExpired && !$renewalAttempted) {
                        $renewalAttempted = true;
                        $renewalResult = $this->tokenRefresh->renew($account, $service);

                        if ($renewalResult->renewed) {
                            continue; // Retry the same page
                        }

                        // Declined renewal — treat as AccessRevoked
                        throw HealthServiceFailure::accessRevoked('Access renewal declined');
                    }

                    throw $failure; // Propagate to outer handler
                }
            }

            // Transactional write: measurements + sessions + cursor save
            DB::transaction(function () use ($page, $backfillState, $type, &$measurementsWritten, &$sessionsWritten) {
                $measRows = array_map(fn ($m) => $m->toRawMeasurementRow(), $page->measurements());
                $sessRows = array_map(fn ($s) => $s->toRawSessionRow(), $page->sessions());
                $measCount = $this->measurements->write($measRows);
                $sessCount = $this->sessions->write($sessRows);
                $measurementsWritten += $measCount;
                $sessionsWritten += $sessCount;

                // Save cursor atomically with the page data
                $nextCursor = $page->nextCursor();
                $backfillState->cursor = $nextCursor?->toString();
                $backfillState->save();
            });

            $pagesFetched++;

            // Exhaustion: nextCursor() === null — boundary advances only on exhaustion (FR-015c)
            if ($page->nextCursor() === null) {
                // Check if this was an empty window — mark complete
                if ($pagesFetched === 1 && empty($page->measurements()) && empty($page->sessions())) {
                    $backfillState->complete_at = CarbonImmutable::now();
                    $backfillState->completeness_determined_at = CarbonImmutable::now();
                }

                // Advance boundary to since (the oldest point we've now covered)
                $backfillState->backfilled_to = $window->since;
                $backfillState->cursor = null;
                $backfillState->save();
                break;
            }

            // Update cursor for next iteration
            $cursor = $page->nextCursor();
        }

        return new BackfillTypeOutcome(
            type: $type->value,
            skipped: false,
            completed: $backfillState->isComplete(),
            pagesFetched: $pagesFetched,
            measurementsWritten: $measurementsWritten,
            sessionsWritten: $sessionsWritten,
        );
    }

    /**
     * Determine the overall outcome from per-type outcomes.
     */
    private function determineOutcome(
        array $typeOutcomes,
        int $totalRequests,
    ): SyncOutcome {
        if (empty($typeOutcomes)) {
            return SyncOutcome::Success;
        }

        // Check if we hit the per-run cap
        if ($totalRequests >= $this->maxRequestsPerRun) {
            return SyncOutcome::Partial;
        }

        // Check if any type was processed (not just skipped)
        $processed = array_filter($typeOutcomes, fn ($o) => !$o->skipped);
        if (empty($processed)) {
            return SyncOutcome::Success;
        }

        // Any processed type that is not completed → Partial
        // This covers budget denial (pagesFetched=0, completed=false) and partial pages
        foreach ($processed as $outcome) {
            if (!$outcome->completed) {
                return SyncOutcome::Partial;
            }
        }

        return SyncOutcome::Success;
    }

    /**
     * Apply the FailurePolicy response to the state and account.
     */
    private function applyFailureResponse(
        AccountSyncState $state,
        ConnectedAccount $account,
        FailureResponse $response,
        FailureKind $kind,
        CarbonImmutable $now,
    ): void {
        if ($response->clearsCursor) {
            $state->cursor = null;
            $state->cursor_since = null;
            $state->cursor_until = null;
        }

        if ($response->countsAsFailure) {
            $state->consecutive_failures = ($state->consecutive_failures ?? 0) + 1;
        }

        if (!$response->attemptsRenewal) {
            $state->next_attempt_at = $response->nextAttemptAt;
        }

        $state->last_failure_at = $now;
        $state->last_failure_kind = $kind->value;
        $state->needs_attention_reason = $response->needsAttentionReason?->value;
        $state->save();

        if ($response->flagsImmediately) {
            if ($account->sync_state !== 'needs_attention') {
                $account->sync_state = 'needs_attention';
                $account->save();

                \Illuminate\Support\Facades\Event::dispatch(
                    new \ClarionApp\LifeLogBackend\Events\ConnectedAccountNeedsAttention($account)
                );
            }
        }
    }
}
