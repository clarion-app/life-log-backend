<?php

namespace ClarionApp\LifeLogBackend\Connection;

use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Credentials\ServiceCredentialProvider;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Completes a connection: exchanges the code, upserts the account,
 * replaces the authorization, and resets sync health — all in one transaction.
 */
final class ConnectionCompleter
{
    public function __construct(
        private ServiceCredentialProvider $credentialProvider,
    ) {
    }

    /**
     * Complete a connection within a database transaction.
     *
     * @return array{account: ConnectedAccount, reconnected: bool}
     *
     * @throws HealthServiceFailure if the provider refuses the code
     */
    public function complete(
        string $userId,
        string $externalService,
        string $code,
        string $redirectUri,
        ExternalHealthService $service,
    ): array {
        return DB::transaction(function () use ($userId, $externalService, $code, $redirectUri, $service) {
            // Exchange the code for an authorization grant
            $grant = $service->completeConnection($userId, $code, $redirectUri);

            // Get the current credential (for version stamping)
            $credential = $this->credentialProvider->require($externalService);

            // Upsert the ConnectedAccount. withTrashed() matters here: a
            // service the user previously disconnected leaves a soft-deleted
            // row behind (it is bridged — a tombstone, not a hard delete),
            // and the (user_id, external_service) unique index does not
            // exempt soft-deleted rows. Reconnecting the same service after
            // a disconnect must restore that row rather than insert a
            // second one, or the insert fails the unique constraint.
            $account = ConnectedAccount::withTrashed()
                ->where('user_id', $userId)
                ->where('external_service', $externalService)
                ->first();

            $reconnected = $account !== null;

            if ($account) {
                if ($account->trashed()) {
                    $account->restore();
                }

                // Update existing account
                $account->sync_state = 'normal';
                $account->save();

                // Replace the authorization wholesale
                AccountAuthorization::query()
                    ->where('connected_account_id', $account->id)
                    ->delete();

                // Reset sync health
                $account->resetSyncHealth();
            } else {
                // Create new account
                $account = ConnectedAccount::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $userId,
                    'external_service' => $externalService,
                    'sync_state' => 'normal',
                    'connected_at' => now(),
                ]);
            }

            // Create the new authorization
            AccountAuthorization::create([
                'id' => (string) Str::uuid(),
                'connected_account_id' => $account->id,
                'access_token' => $grant->accessToken,
                'refresh_token' => $grant->refreshToken,
                'expires_at' => $grant->expiresAt?->toDateTimeString(),
                'scopes' => $grant->scopes,
                'credential_version' => $credential->version,
            ]);

            return [
                'account' => $account->fresh(),
                'reconnected' => $reconnected,
            ];
        });
    }
}
