<?php

namespace ClarionApp\LifeLogBackend\Controllers;

use ClarionApp\LifeLogBackend\Connection\RedirectUriValidator;
use ClarionApp\LifeLogBackend\Credentials\CredentialVerifier;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Credentials\VerificationOutcome;
use ClarionApp\LifeLogBackend\External\HealthServiceRegistry;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\AccountSyncState;
use ClarionApp\LifeLogBackend\Sync\NeedsAttentionReason;
use ClarionApp\LifeLogBackend\Exceptions\ServiceNotConfiguredException;
use ClarionApp\LifeLogBackend\Exceptions\UnknownServiceException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceCredentialController extends Controller
{
    public function __construct(
        protected HealthServiceRegistry $registry,
        protected ServiceCredentialProvider $credentialProvider,
        protected RedirectUriValidator $redirectUriValidator,
        protected CredentialVerifier $verifier,
    ) {
    }

    /**
     * GET /service-credentials — list all registered services and their config state.
     */
    public function index(): JsonResponse
    {
        $services = [];

        foreach ($this->registry->names() as $name) {
            $credential = $this->credentialProvider->find($name);

            if ($credential) {
                $public = $credential->toPublicArray();
                $public['configured'] = true;
                $services[] = $public;
            } else {
                $services[] = [
                    'external_service' => $name,
                    'configured' => false,
                ];
            }
        }

        return response()->json(['services' => $services]);
    }

    /**
     * POST /service-credentials — create a new service credential.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'external_service' => ['required', 'string', 'max:64'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string'],
            'redirect_uri' => ['required', 'string', 'max:512'],
        ], $this->sanitizedValidationMessages());

        $externalService = $validated['external_service'];

        // Check that the service is registered
        if (! $this->registry->has($externalService)) {
            return response()->json(['error' => 'unknown_service'], 422);
        }

        // Check that there isn't already a live credential (DB check, not cached)
        if (ServiceCredential::liveByService($externalService)->exists()) {
            return response()->json(['error' => 'already_configured'], 409);
        }

        // Validate redirect_uri
        if (! $this->redirectUriValidator->isValid($validated['redirect_uri'])) {
            return response()->json([
                'error' => 'validation_error',
                'messages' => ['redirect_uri' => ['The redirect URI is invalid.']],
            ], 422);
        }

        $canonicalUri = $this->redirectUriValidator->canonicalise($validated['redirect_uri']);

        $credential = ServiceCredential::create([
            'external_service' => $externalService,
            'client_id' => $validated['client_id'],
            'client_secret' => $validated['client_secret'],
            'redirect_uri' => $canonicalUri,
            'secret_updated_at' => now(),
        ]);

        return response()->json($credential->toPublicArray(), 201);
    }

    /**
     * PUT /service-credentials/{service} — update an existing credential.
     */
    public function update(Request $request, string $service): JsonResponse
    {
        $credential = ServiceCredential::liveByService($service)->first();

        if (! $credential) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $validated = $request->validate([
            'client_id' => ['sometimes', 'string', 'max:255'],
            'client_secret' => ['sometimes', 'string', 'min:1'],
            'redirect_uri' => ['sometimes', 'string', 'max:512'],
        ], $this->sanitizedValidationMessages());

        // Update client_id if provided
        if (array_key_exists('client_id', $validated)) {
            $credential->client_id = $validated['client_id'];
        }

        // Update redirect_uri if provided
        if (array_key_exists('redirect_uri', $validated)) {
            if (! $this->redirectUriValidator->isValid($validated['redirect_uri'])) {
                return response()->json([
                    'error' => 'validation_error',
                    'messages' => ['redirect_uri' => ['The redirect URI is invalid.']],
                ], 422);
            }
            $credential->redirect_uri = $this->redirectUriValidator->canonicalise(
                $validated['redirect_uri'],
            );
        }

        // Update client_secret if provided (present and non-empty)
        if (array_key_exists('client_secret', $validated) && $validated['client_secret'] !== '') {
            $credential->client_secret = $validated['client_secret'];
            $credential->version = ($credential->version ?? 1) + 1;
            $credential->secret_updated_at = now();
        }

        $credential->save();

        return response()->json($credential->toPublicArray());
    }

    /**
     * POST /service-credentials/{service}/verify — verify credentials against the provider.
     */
    public function verify(string $service): JsonResponse
    {
        $credential = ServiceCredential::liveByService($service)->first();

        if (! $credential) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $externalService = $this->registry->resolve($service);
        $outcome = $this->verifier->verify($externalService, $credential);
        $this->verifier->persistOutcome($credential, $outcome);

        return response()->json([
            'outcome' => $outcome->value,
            'verified_at' => $credential->last_verified_at?->toISOString(),
        ]);
    }

    /**
     * DELETE /service-credentials/{service} — remove a credential (soft delete).
     *
     * Existing connections are not deleted; each is marked needs_attention
     * with needs_attention_reason = credential_removed.
     */
    public function destroy(string $service): JsonResponse
    {
        $credential = ServiceCredential::liveByService($service)->first();

        if (! $credential) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // Soft-delete the credential
        $credential->delete();

        // Mark all existing connections as needs_attention
        $markedCount = 0;
        $accounts = ConnectedAccount::where('external_service', $service)
            ->whereNull('deleted_at')
            ->get();

        foreach ($accounts as $account) {
            if ($account->sync_state !== 'needs_attention') {
                $account->sync_state = 'needs_attention';
                $account->save();
            }

            // Update or create sync state with needs_attention_reason
            $syncState = $account->syncState;
            if ($syncState) {
                $syncState->needs_attention_reason = NeedsAttentionReason::CredentialRemoved->value;
                $syncState->save();
            } else {
                AccountSyncState::create([
                    'connected_account_id' => $account->id,
                    'needs_attention_reason' => NeedsAttentionReason::CredentialRemoved->value,
                ]);
            }

            $markedCount++;
        }

        return response()->json([
            'removed' => true,
            'connections_marked_needing_attention' => $markedCount,
        ]);
    }

    /**
     * Return validation messages that never contain user input.
     *
     * This prevents the secret from being echoed in validation error messages.
     */
    protected function sanitizedValidationMessages(): array
    {
        return [];
    }
}
