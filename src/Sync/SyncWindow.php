<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Carbon\CarbonImmutable;

/**
 * The time window for a single sync run.
 *
 * Derivation rules (from data-model.md):
 *  1. If a cursor exists (state->cursor is non-null): adopt cursor_since and
 *     cursor_until verbatim — the interrupted range must be retried as-is.
 *  2. If no synced_through_at (first sync): since = connected_at, until = now.
 *  3. Otherwise: since = max(synced_through_at - overlap_hours, connected_at),
 *     until = now.
 *
 * An empty window (until <= since) is a first-class state, not an error.
 */
final readonly class SyncWindow
{
    public function __construct(
        public CarbonImmutable $since,
        public CarbonImmutable $until,
    ) {
    }

    /**
     * Derive the window for the next sync run.
     */
    public static function for(
        ConnectedAccount $account,
        AccountSyncState $state,
        CarbonImmutable $now,
    ): self {
        // Normalize connected_at to CarbonImmutable (Eloquent may return Carbon)
        $connectedAt = $account->connected_at instanceof CarbonImmutable
            ? $account->connected_at
            : CarbonImmutable::createFromInterface($account->connected_at);

        // Rule 1: cursor exists — adopt stored window verbatim
        if ($state->cursor !== null) {
            $cursorSince = $state->cursor_since;
            if ($cursorSince !== null && !$cursorSince instanceof CarbonImmutable) {
                $cursorSince = CarbonImmutable::createFromInterface($cursorSince);
            }

            $cursorUntil = $state->cursor_until ?? $now;
            if ($cursorUntil !== null && !$cursorUntil instanceof CarbonImmutable) {
                $cursorUntil = CarbonImmutable::createFromInterface($cursorUntil);
            }

            return new self(
                $cursorSince ?? $connectedAt,
                $cursorUntil,
            );
        }

        // Rule 2: first sync (no synced_through_at)
        if ($state->synced_through_at === null) {
            return new self(
                $connectedAt,
                $now,
            );
        }

        // Rule 3: incremental sync with overlap
        $overlapHours = (int) config('life-log.sync_overlap_hours', 72);
        $syncedThroughAt = $state->synced_through_at instanceof CarbonImmutable
            ? $state->synced_through_at
            : CarbonImmutable::createFromInterface($state->synced_through_at);

        // If synced_through_at is in the future (everything through that point is done),
        // and now is before it, the window is effectively empty — nothing new to fetch.
        if ($now->lte($syncedThroughAt)) {
            return new self($syncedThroughAt, $syncedThroughAt); // Empty window
        }

        $since = $syncedThroughAt->copy()->subHours($overlapHours);

        // Clamp to connected_at — never look before the account existed
        if ($since->lessThan($connectedAt)) {
            $since = $connectedAt;
        }

        return new self($since, $now);
    }

    /**
     * True when the window is empty or backwards (until <= since).
     *
     * This is a first-class state, not an error — the runner returns
     * SyncOutcome::Skipped without calling the service.
     */
    public function isEmpty(): bool
    {
        return $this->until->lte($this->since);
    }
}
