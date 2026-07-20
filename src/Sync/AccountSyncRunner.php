<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Services\RawSessionWriter;
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
    public function __construct(
        private HealthServiceRegistry $registry,
        private RawMeasurementWriter $measurements,
        private RawSessionWriter $sessions,
        private FailurePolicy $policy,
        private SyncLock $locks,
        private SyncAttemptRecorder $recorder,
        private int $maxPages = 0,
    ) {
        if ($this->maxPages <= 0) {
            $this->maxPages = (int) config('life-log.sync_max_pages_per_run', 200);
        }
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

        $pages = 0;
        $totalMeasurements = 0;
        $totalSessions = 0;
        $cursor = $state->cursor;

        try {
            // Page loop
            while (true) {
                $page = null;
                $renewalAttempted = false;

                // Fetch with optional renewal retry for AccessExpired
                while (true) {
                    try {
                        $page = $service->fetch(
                            $account->user_id,
                            $window->since,
                            $window->until,
                            $cursor !== null ? \ClarionApp\LifeLogBackend\External\PageCursor::fromString($cursor) : null,
                        );
                        break; // Success — exit retry loop
                    } catch (HealthServiceFailure $failure) {
                        // Check if this is AccessExpired and we haven't tried renewal yet
                        if ($failure->kind === \ClarionApp\LifeLogBackend\Contracts\FailureKind::AccessExpired && !$renewalAttempted) {
                            $renewalAttempted = true;
                            $renewalResult = $service->renewAccess($account->user_id);

                            if ($renewalResult->success) {
                                // Retry the same page immediately — nothing counted
                                continue;
                            }

                            // Declined renewal — treat as AccessRevoked (flag now, no ladder)
                            $this->applyAccessRevoked($state, $account, CarbonImmutable::now());

                            return new SyncResult(
                                outcome: SyncOutcome::Failure,
                                since: $window->since,
                                until: $window->until,
                                pagesFetched: $pages,
                                measurementsWritten: $totalMeasurements,
                                sessionsWritten: $totalSessions,
                                failureKind: \ClarionApp\LifeLogBackend\Contracts\FailureKind::AccessRevoked,
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
                DB::transaction(function () use ($page, $state, $window, &$totalMeasurements, &$totalSessions) {
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
                        $state->cursor_since = $window->since;
                        $state->cursor_until = $window->until;
                    } else {
                        // Exhaustion — clear cursor triple
                        $state->cursor_since = null;
                        $state->cursor_until = null;
                    }

                    $state->save();
                });

                $pages++;

                // Exhaustion: nextCursor() === null
                if ($page->nextCursor() === null) {
                    // Finalize
                    $this->finalize($state, $window, CarbonImmutable::now());

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
                $cursor = $state->cursor;
            }
        } catch (HealthServiceFailure $failure) {
            // Route to FailurePolicy
            $response = $this->policy->apply($state, $failure, CarbonImmutable::now());

            // Apply the failure response to the state
            $this->applyFailureResponse($state, $account, $response, CarbonImmutable::now());

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
    private function finalize(AccountSyncState $state, SyncWindow $window, CarbonImmutable $now): void
    {
        $state->synced_through_at = $window->until;
        $state->cursor = null;
        $state->cursor_since = null;
        $state->cursor_until = null;
        $state->consecutive_failures = 0;
        $state->next_attempt_at = null;
        $state->last_success_at = $now;
        $state->save();
    }

    /**
     * Apply AccessRevoked handling (used by declined renewal path).
     */
    private function applyAccessRevoked(
        AccountSyncState $state,
        ConnectedAccount $account,
        CarbonImmutable $now,
    ): void {
        // Clear cursor triple
        $state->cursor = null;
        $state->cursor_since = null;
        $state->cursor_until = null;
        $state->save();

        // Flag the account
        if ($account->sync_state !== 'needs_attention') {
            $account->sync_state = 'needs_attention';
            $account->save();

            Event::dispatch(new \ClarionApp\LifeLogBackend\Events\ConnectedAccountNeedsAttention($account));
        }
    }

    /**
     * Apply the FailurePolicy response to the state and account.
     */
    private function applyFailureResponse(
        AccountSyncState $state,
        ConnectedAccount $account,
        FailureResponse $response,
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

        $state->next_attempt_at = $response->nextAttemptAt;
        $state->last_failure_at = $now;
        $state->save();

        if ($response->flagsImmediately) {
            // Flag the account as needs_attention
            if ($account->sync_state !== 'needs_attention') {
                $account->sync_state = 'needs_attention';
                $account->save();

                // Fire the attention event
                Event::dispatch(new \ClarionApp\LifeLogBackend\Events\ConnectedAccountNeedsAttention($account));
            }
        }
    }
}
