<?php

namespace ClarionApp\LifeLogBackend\Connection;

use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use Illuminate\Support\Str;

/**
 * Creates a new ConnectionAttempt bound to a user, service, and redirect URI.
 *
 * Stores only the SHA-256 hash of the state (never plaintext). Rejects a state
 * under 32 bytes of entropy. Sets expires_at to one hour from now.
 */
final class ConnectionAttemptFactory
{
    /**
     * Create a new connection attempt.
     *
     * @throws \InvalidArgumentException if $state has less than 32 bytes of entropy
     */
    public function create(
        string $userId,
        string $externalService,
        string $state,
        string $redirectUri,
    ): ConnectionAttempt {
        // Reject weak state — less than 32 bytes of entropy
        if (strlen($state) < 32) {
            throw new \InvalidArgumentException(
                'The OAuth state must be at least 32 bytes of entropy. '
                . 'A weak state is susceptible to brute-force forgery.',
            );
        }

        return ConnectionAttempt::create([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'external_service' => $externalService,
            'state_hash' => hash('sha256', $state),
            'redirect_uri' => $redirectUri,
            'expires_at' => now()->addHour(),
        ]);
    }
}
