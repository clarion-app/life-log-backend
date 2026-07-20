<?php

namespace ClarionApp\LifeLogBackend\Controllers;

use ClarionApp\LifeLogBackend\Connection\AccountDisconnector;
use ClarionApp\LifeLogBackend\Connection\ConnectionAttemptFactory;
use ClarionApp\LifeLogBackend\Connection\ConnectionAttemptVerifier;
use ClarionApp\LifeLogBackend\Connection\ConnectionCompleter;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Exceptions\ServiceNotConfiguredException;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Jobs\SyncConnectedAccountJob;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Sync\SyncLock;
use ClarionApp\LifeLogBackend\Sync\SyncTrigger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectedAccountController extends Controller
{
    public function __construct(
        private SyncLock $syncLock,
        private ConnectionAttemptFactory $attemptFactory,
        private ConnectionAttemptVerifier $attemptVerifier,
        private ConnectionCompleter $connectionCompleter,
        private ServiceCredentialProvider $credentialProvider,
        private HealthServiceRegistry $serviceRegistry,
        private AccountDisconnector $disconnector,
    ) {
    }

    /**
     * Resolve a connection the authenticated user owns, or null.
     *
     * The ownership predicate lives in the query, not in a check after the
     * fetch: another user's row is never loaded into memory, so no later
     * edit to a caller can accidentally act on it.
     */
    private function ownedAccount(string $id): ?ConnectedAccount
    {
        return ConnectedAccount::query()
            ->where('user_id', auth()->id())
            ->where('id', $id)
            ->first();
    }

    /**
     * The single refusal used for both "not yours" and "does not exist".
     *
     * Byte-identical in either case: a distinct response would confirm that
     * an id names a real connection belonging to someone else.
     */
    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found'], 404);
    }

    /**
     * List the authenticated user's connections.
     *
     * Each entry says what the service is, whether it is working, and when
     * it last succeeded — enough on its own to tell the user what needs
     * their attention. A connection that has never synced successfully
     * reports null, never a fabricated or zero timestamp.
     *
     * GET /connected-accounts
     *
     * 200 { "connections": [ … ] }
     */
    public function index(): JsonResponse
    {
        $accounts = ConnectedAccount::query()
            ->where('user_id', auth()->id())
            ->with('syncState')
            ->orderBy('connected_at')
            ->get();

        $connections = $accounts->map(function (ConnectedAccount $account): array {
            $state = $account->syncState;

            return [
                'id' => $account->id,
                'external_service' => $account->external_service,
                'status' => $account->sync_state === 'needs_attention'
                    ? 'needs_attention'
                    : 'healthy',
                'last_successful_sync_at' => $state?->last_success_at,
                'connected_at' => $account->connected_at,
                'needs_attention_reason' => $account->needsAttentionReason(),
            ];
        })->all();

        return response()->json(['connections' => $connections]);
    }

    /**
     * Trigger an on-demand sync for a connected account.
     *
     * Returns 202 with {"status": "queued"} on success,
     * or 202 with {"status": "already_running"} if a sync is in progress.
     * A connection that needs attention is refused with 409 and the reason
     * rather than started: the run would fail for a cause the user has to
     * resolve first, and a queued-then-failed attempt tells them less than
     * the reason does.
     * A connection the caller does not own returns 404.
     */
    public function sync(string $id): JsonResponse
    {
        $account = $this->ownedAccount($id);

        if (! $account) {
            return $this->notFound();
        }

        if ($account->sync_state === 'needs_attention') {
            return response()->json([
                'error' => 'needs_attention',
                'reason' => $account->needsAttentionReason(),
            ], 409);
        }

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
     * A connection the caller does not own returns 404.
     */
    public function show(string $id): JsonResponse
    {
        $account = $this->ownedAccount($id);

        if (! $account) {
            return $this->notFound();
        }

        $state = $account->syncState;

        return response()->json([
            'sync_state' => $account->sync_state,
            'synced_through_at' => $state?->synced_through_at,
            'consecutive_failures' => $state?->consecutive_failures ?? 0,
            'last_failure_kind' => $state?->last_failure_kind,
        ]);
    }

    /**
     * Begin a connection flow.
     *
     * Creates a ConnectionAttempt and returns the authorization URL.
     * The plaintext state appears only inside the URL, never as a
     * separate response field.
     *
     * POST /connected-accounts
     * Body: { "external_service": "acme-band" }
     *
     * 201 { "authorization_url": "...", "expires_at": "..." }
     * 422 { "error": "service_unavailable" } — no credential configured
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'external_service' => 'required|string',
        ]);

        $externalService = $request->input('external_service');
        $userId = auth()->id();

        // Check that the service is registered
        if (! $this->serviceRegistry->has($externalService)) {
            return response()->json([
                'error' => 'service_unavailable',
                'message' => 'The service is not available on this instance.',
            ], 422);
        }

        // Check that the service has a credential configured
        if (! $this->credentialProvider->isConfigured($externalService)) {
            return response()->json([
                'error' => 'service_unavailable',
                'message' => 'The service is not available on this instance.',
            ], 422);
        }

        // Get the credential for the redirect URI
        $credential = $this->credentialProvider->require($externalService);

        // Resolve the service and begin the connection
        $service = $this->serviceRegistry->resolve($externalService);
        $connectionResult = $service->beginConnection($userId);

        // Create the attempt (stores only the state hash)
        $attempt = $this->attemptFactory->create(
            userId: $userId,
            externalService: $externalService,
            state: $connectionResult->state,
            redirectUri: $credential->redirect_uri,
        );

        return response()->json([
            'authorization_url' => $connectionResult->authorizationUrl,
            'expires_at' => $attempt->expires_at,
        ], 201);
    }

    /**
     * Complete a connection flow (callback).
     *
     * Verifies the callback against the attempt, exchanges the code,
     * upserts the ConnectedAccount, replaces the AccountAuthorization,
     * and resets sync health — all in one transaction.
     *
     * POST /connected-accounts/callback
     * Body: { "external_service": "...", "state": "...", "code": "...", "redirect_uri": "..." }
     *
     * 201 { "id": "...", "external_service": "...", "status": "healthy", "reconnected": false }
     * 422 { "error": "connection_not_completed" } — any failure
     *
     * All seven failure cases return byte-identical 422 responses.
     */
    public function callback(Request $request): JsonResponse
    {
        $request->validate([
            'external_service' => 'required|string',
            'state'            => 'required|string',
            'code'             => 'required|string',
            'redirect_uri'     => 'required|string',
        ]);

        $externalService = $request->input('external_service');
        $state = $request->input('state');
        $code = $request->input('code');
        $redirectUri = $request->input('redirect_uri');
        $userId = auth()->id();

        // Uniform failure response
        $uniformFailure = function (): JsonResponse {
            return response()->json(['error' => 'connection_not_completed'], 422);
        };

        // Check that the service is registered
        if (! $this->serviceRegistry->has($externalService)) {
            return $uniformFailure();
        }

        // Verify the attempt (all six checks + atomic claim)
        $attempt = $this->attemptVerifier->verify(
            userId: $userId,
            externalService: $externalService,
            state: $state,
            redirectUri: $redirectUri,
        );

        if (! $attempt) {
            return $uniformFailure();
        }

        // Resolve the service
        $service = $this->serviceRegistry->resolve($externalService);

        // Exchange the code and complete the connection
        try {
            $result = $this->connectionCompleter->complete(
                userId: $userId,
                externalService: $externalService,
                code: $code,
                redirectUri: $redirectUri,
                service: $service,
            );
        } catch (HealthServiceFailure $e) {
            // Provider denied the code — still consume the attempt (FR-015)
            $this->attemptVerifier->consumeWithoutComplete($attempt);
            return $uniformFailure();
        }

        $account = $result['account'];

        return response()->json([
            'id' => $account->id,
            'external_service' => $account->external_service,
            'status' => 'healthy',
            'reconnected' => $result['reconnected'],
        ], 201);
    }

    /**
     * Disconnect a connected account.
     *
     * Asks the provider to revoke access on a best-effort basis, then
     * unconditionally tears down what was stored locally — the
     * authorization, the sync bookkeeping, and the connection itself
     * (FR-021, FR-022). Health data already ingested is never touched
     * (FR-023). A connection the caller does not own returns 404.
     *
     * DELETE /connected-accounts/{id}
     *
     * 200 { "disconnected": true, "revocation_confirmed": false }
     */
    public function destroy(string $id): JsonResponse
    {
        $account = $this->ownedAccount($id);

        if (! $account) {
            return $this->notFound();
        }

        $service = $this->serviceRegistry->has($account->external_service)
            ? $this->serviceRegistry->resolve($account->external_service)
            : null;

        $result = $this->disconnector->disconnect($account, $service);

        return response()->json([
            'disconnected' => $result->disconnected,
            'revocation_confirmed' => $result->remoteReachable,
        ], 200);
    }
}
