<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Events\ConnectedAccountStatusChanged;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Services\RawSessionWriter;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Runs a single account sync: acquires a lock, derives the window, pages
 * through the service, writes data transactionally, and finalizes the
 * checkpoint.
 *
 * run() never throws for an ordinary service failure — it returns a SyncResult
 * carrying a SyncOutcome. It may propagate a genuine programming error
 * (TypeError, container resolution failure), which the queue records as a
 * failed job.
 */
final class AccountSyncRunner
{
    private TokenRefreshCoordinator $tokenRefresh;

    private ServiceCredentialProvider $credentials;

    private WindowPlanner $planner;

    private RequestBudget $budget;

    public function __construct(
        private HealthServiceRegistry $registry,
        private RawMeasurementWriter $measurements,
        private RawSessionWriter $sessions,
        private FailurePolicy $policy,
        private SyncLock $locks,
        private SyncAttemptRecorder $recorder,
        private int $maxPages = 0,
        ?TokenRefreshCoordinator $tokenRefresh = null,
        ?ServiceCredentialProvider $credentials = null,
        ?WindowPlanner $planner = null,
        ?RequestBudget $budget = null,
    ) {
        if ($this->maxPages <= 0) {
            $this->maxPages = (int) config('life-log.sync_max_pages_per_run', 200);
        }

        // Optional constructor args (rather than required, promoted ones) so
        // every existing call site that predates token refresh and credential
        // rotation keeps working unchanged. Container resolution still wires
        // real instances in; only a bare `new AccountSyncRunner(...)` falls
        // back to resolving them here.
        $this->tokenRefresh = $tokenRefresh ?? app(TokenRefreshCoordinator::class);
        $this->credentials = $credentials ?? app(ServiceCredentialProvider::class);
        $this->planner = $planner ?? app(WindowPlanner::class);
        $this->budget = $budget ?? app(RequestBudget::class);
    }

    /**
     * Run a sync for the given account.
     *
     * @return SyncResult the outcome of this run
     */
    public function run(ConnectedAccount $account, SyncTrigger $trigger): SyncResult
    {
        $startedAt = CarbonImmutable::now();
        $result = null;

        try {
            // Non-blocking lock — if held, return skipped immediately
            $result = $this->locks->attempt($account->id, function () use ($account, $trigger, $startedAt) {
                return $this->execute($account, $trigger, $startedAt);
            });

            if ($result === null) {
                // Lock was held — return skipped
                $result = new SyncResult(
                    outcome: SyncOutcome::Skipped,
                    since: $startedAt,
                    until: $startedAt,
                    startedAt: $startedAt,
                    finishedAt: CarbonImmutable::now(),
                );
            }
        } finally {
            // Record the attempt (never throws) — called on every path
            if ($result !== null) {
                $this->recorder->record($account->id, $trigger, $result);
            }
        }

        return $result;
    }

    /**
     * Execute the sync (called inside the lock callback).
     *
     * Plans the window into per-type segments via WindowPlanner, iterates
     * segments, passes the type filter to fetch(), and takes reserveIncremental(1)
     * per fetch. The existing single-renewal retry sits inside the per-segment loop.
     */
    private function execute(
        ConnectedAccount $account,
        SyncTrigger $trigger,
        CarbonImmutable $startedAt,
    ): SyncResult {
        $now = CarbonImmutable::now();

        // Lazy-create the sync state row (firstOrCreate)
        $state = AccountSyncState::firstOrCreate(
            ['connected_account_id' => $account->id],
            [],
        );

        // Derive the window
        $window = SyncWindow::for($account, $state, $now);

        // Empty window → skipped (never mutates failure state)
        if ($window->isEmpty()) {
            return new SyncResult(
                outcome: SyncOutcome::Skipped,
                since: $window->since,
                until: $window->until,
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
            );
        }

        // Resolve the service from the registry
        $service = $this->registry->resolve($account->external_service);

        // Use supportedTypes from the service; fall back to ALL types for
        // pre-058 services that return an empty array (backwards compatible).
        $types = $service->supportedTypes();
        if (empty($types)) {
            $types = array_merge(
                MeasurementType::cases(),
                SessionType::cases(),
            );
        }

        // Plan the window into per-type segments
        $segments = $this->planner->plan(
            $window->since,
            $window->until,
            $service,
            $types,
        );

        // No segments to process
        if (empty($segments)) {
            return new SyncResult(
                outcome: SyncOutcome::Skipped,
                since: $window->since,
                until: $window->until,
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
            );
        }

        $pages = 0;
        $totalMeasurements = 0;
        $totalSessions = 0;

        try {
            // Iterate over segments
            foreach ($segments as $segment) {
                // Check page cap before starting a segment
                if ($pages >= $this->maxPages) {
                    return new SyncResult(
                        outcome: SyncOutcome::Partial,
                        since: $window->since,
                        until: $window->until,
                        pagesFetched: $pages,
                        measurementsWritten: $totalMeasurements,
                        sessionsWritten: $totalSessions,
                        startedAt: $startedAt,
                        finishedAt: CarbonImmutable::now(),
                    );
                }

                // Reserve budget for this fetch
                if (!$this->budget->reserveIncremental($account->external_service, 1)) {
                    // Budget denied — return partial (cursor kept, checkpoint held)
                    return new SyncResult(
                        outcome: SyncOutcome::Partial,
                        since: $window->since,
                        until: $window->until,
                        pagesFetched: $pages,
                        measurementsWritten: $totalMeasurements,
                        sessionsWritten: $totalSessions,
                        startedAt: $startedAt,
                        finishedAt: CarbonImmutable::now(),
                    );
                }

                // Page loop for this segment
                $cursor = $state->cursor !== null
                    ? \ClarionApp\LifeLogBackend\External\PageCursor::fromString($state->cursor)
                    : null;

                while (true) {
                    $page = null;
                    $renewalAttempted = false;

                    // Fetch with optional renewal retry for AccessExpired
                    while (true) {
                        try {
                            $page = $service->fetch(
                                $account->user_id,
                                $segment->since,
                                $segment->until,
                                $cursor,
                                [$segment->type],
                            );
                            break; // Success — exit retry loop
                        } catch (HealthServiceFailure $failure) {
                            // Check if this is AccessExpired and we haven't tried renewal yet
                            if ($failure->kind === FailureKind::AccessExpired && !$renewalAttempted) {
                                $renewalAttempted = true;
                                // Routed through the coordinator (not called directly) so a
                                // concurrent sync/user-triggered refresh for this account
                                // never race the same provider call (research §9).
                                $renewalResult = $this->tokenRefresh->renew($account, $service);

                                if ($renewalResult->renewed) {
                                    // Retry the same page immediately — nothing counted
                                    continue;
                                }

                                // Declined renewal — the grant can no longer be renewed.
                                // Routed through the same policy branch as a direct
                                // AccessRevoked failure so both paths agree on
                                // needs_attention_reason (FR-017).
                                $response = $this->policy->apply(
                                    $state,
                                    HealthServiceFailure::accessRevoked('Access renewal declined'),
                                    CarbonImmutable::now(),
                                );
                                $this->applyFailureResponse(
                                    $state,
                                    $account,
                                    $response,
                                    FailureKind::AccessRevoked,
                                    CarbonImmutable::now(),
                                );

                                return new SyncResult(
                                    outcome: SyncOutcome::Failure,
                                    since: $window->since,
                                    until: $window->until,
                                    pagesFetched: $pages,
                                    measurementsWritten: $totalMeasurements,
                                    sessionsWritten: $totalSessions,
                                    failureKind: FailureKind::AccessRevoked,
                                    errorMessage: 'Access renewal declined',
                                    startedAt: $startedAt,
                                    finishedAt: CarbonImmutable::now(),
                                );
                            }

                            // Not AccessExpired or renewal already attempted — propagate to outer handler
                            throw $failure;
                        }
                    }

                    // Transactional write: measurements + sessions + cursor save
                    DB::transaction(function () use ($page, $state, $segment, &$totalMeasurements, &$totalSessions) {
                        $measRows = array_map(fn ($m) => $m->toRawMeasurementRow(), $page->measurements());
                        $sessRows = array_map(fn ($s) => $s->toRawSessionRow(), $page->sessions());
                        $measCount = $this->measurements->write($measRows);
                        $sessCount = $this->sessions->write($sessRows);
                        $totalMeasurements += $measCount;
                        $totalSessions += $sessCount;

                        // Save cursor triple atomically with the page data
                        $nextCursor = $page->nextCursor();
                        $state->cursor = $nextCursor?->toString();

                        // When cursor is non-null, save the window it was issued against
                        if ($nextCursor !== null) {
                            $state->cursor_since = $segment->since;
                            $state->cursor_until = $segment->until;
                        } else {
                            // Exhaustion — clear cursor triple
                            $state->cursor_since = null;
                            $state->cursor_until = null;
                        }

                        $state->save();
                    });

                    $pages++;

                    // Exhaustion: nextCursor() === null — move to next segment
                    if ($page->nextCursor() === null) {
                        break;
                    }

                    // Page cap → partial (cursor is kept, checkpoint held)
                    if ($pages >= $this->maxPages) {
                        return new SyncResult(
                            outcome: SyncOutcome::Partial,
                            since: $window->since,
                            until: $window->until,
                            pagesFetched: $pages,
                            measurementsWritten: $totalMeasurements,
                            sessionsWritten: $totalSessions,
                            startedAt: $startedAt,
                            finishedAt: CarbonImmutable::now(),
                        );
                    }

                    // Update cursor for next iteration
                    $cursor = $state->cursor !== null
                        ? \ClarionApp\LifeLogBackend\External\PageCursor::fromString($state->cursor)
                        : null;
                }
            }

            // All segments processed — finalize
            $this->finalize($account, $state, $window, CarbonImmutable::now());

            return new SyncResult(
                outcome: SyncOutcome::Success,
                since: $window->since,
                until: $window->until,
                pagesFetched: $pages,
                measurementsWritten: $totalMeasurements,
                sessionsWritten: $totalSessions,
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
            );
        } catch (HealthServiceFailure $failure) {
            // Route to FailurePolicy
            $response = $this->policy->apply($state, $failure, CarbonImmutable::now());

            // Apply the failure response to the state
            $this->applyFailureResponse($state, $account, $response, $failure->kind, CarbonImmutable::now());

            return new SyncResult(
                outcome: SyncOutcome::Failure,
                since: $window->since,
                until: $window->until,
                pagesFetched: $pages,
                measurementsWritten: $totalMeasurements,
                sessionsWritten: $totalSessions,
                failureKind: $failure->kind,
                errorMessage: $failure->getMessage(),
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
            );
        }
    }

    /**
     * Finalize a successful sync run.
     *
     * Checkpoint = run-start until; cursor triple nulled; counter zeroed;
     * gate cleared; last_success_at stamped.
     */
    private function finalize(ConnectedAccount $account, AccountSyncState $state, SyncWindow $window, CarbonImmutable $now): void
    {
        $state->synced_through_at = $window->until;
        $state->cursor = null;
        $state->cursor_since = null;
        $state->cursor_until = null;
        $state->consecutive_failures = 0;
        $state->next_attempt_at = null;
        $state->last_success_at = $now;
        $state->save();

        $this->refreshCredentialVersion($account);

        Event::dispatch(new ConnectedAccountStatusChanged($account));
    }

    /**
     * Stamp the authorization's credential_version to current on a
     * successful sync (research §10) — silent, no user-visible event.
     */
    private function refreshCredentialVersion(ConnectedAccount $account): void
    {
        $credential = $this->credentials->find($account->external_service);

        if ($credential === null) {
            return;
        }

        $authorization = $account->authorization;

        if ($authorization !== null && $authorization->credential_version !== $credential->version) {
            $authorization->credential_version = $credential->version;
            $authorization->save();
        }
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

        // A renewal response carries no ladder rung — leave any existing gate
        // untouched rather than clearing it (failure-policy.md, AccessExpired row).
        if (! $response->attemptsRenewal) {
            $state->next_attempt_at = $response->nextAttemptAt;
        }

        $state->last_failure_at = $now;
        $state->last_failure_kind = $kind->value;
        // The management-side reason, distinct from last_failure_kind (data-model.md
        // §AccountSyncState). Null on every ordinary ladder branch, so the public
        // reason keeps falling back to last_failure_kind exactly as before.
        $state->needs_attention_reason = $response->needsAttentionReason?->value;
        $state->save();

        if ($response->flagsImmediately) {
            // Flag the account as needs_attention
            if ($account->sync_state !== 'needs_attention') {
                $account->sync_state = 'needs_attention';
                $account->save();

                // Fire the attention event
                Event::dispatch(new \ClarionApp\LifeLogBackend\Events\ConnectedAccountNeedsAttention($account));
            }

            Event::dispatch(new ConnectedAccountStatusChanged($account));
        }
    }
}
