<?php

namespace ClarionApp\LifeLogBackend\Controllers;

use ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncLock;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ConnectedAccountController extends Controller
{
    public function __construct(
        private SyncLock $syncLock,
    ) {
    }

    /**
     * Trigger an on-demand sync for a connected account.
     *
     * Returns 202 with {"status": "queued"} on success,
     * or 202 with {"status": "already_running"} if a sync is in progress.
     */
    public function sync(string $id): JsonResponse
    {
        // Check lock status BEFORE dispatching to return already_running
        $isRunning = $this->syncLock->attempt($id, function () {
            return false; // Lock acquired — no run in progress
        });

        if ($isRunning === null) {
            // Lock was held — a run is already in progress
            return response()->json(['status' => 'already_running'], 202);
        }

        // Dispatch with no jitter delay (on-demand)
        SyncConnectedAccountJob::dispatch($id, SyncTrigger::OnDemand);

        return response()->json(['status' => 'queued'], 202);
    }

    /**
     * Show sync health for a connected account.
     *
     * Returns sync_state, synced_through_at, consecutive_failures,
     * and last_failure_kind. Tolerates a missing state row (never-synced).
     */
    public function show(string $id): JsonResponse
    {
        $account = ConnectedAccount::find($id);

        if (! $account) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $state = $account->syncState;

        return response()->json([
            'sync_state' => $account->sync_state,
            'synced_through_at' => $state?->synced_through_at,
            'consecutive_failures' => $state?->consecutive_failures ?? 0,
            'last_failure_kind' => $state?->last_failure_kind,
        ]);
    }
}
