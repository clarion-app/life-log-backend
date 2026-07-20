<?php

namespace ClarionApp\LifeLogBackend\Connection;

use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;

/**
 * Verifies a callback against a ConnectionAttempt with all six security checks.
 *
 * Performs an atomic single-use claim so two concurrent callbacks with the same
 * state yield exactly one winner. Returns a uniform failure for any rejection
 * so the response body does not distinguish "no such attempt" from "expired"
 * from "wrong user".
 */
final class ConnectionAttemptVerifier
{
    public function __construct(
        private RedirectUriValidator $redirectUriValidator,
    ) {
    }

    /**
     * Verify and atomically claim a connection attempt.
     *
     * @param  string  $userId         The authenticated user's ID
     * @param  string  $externalService The service name from the route
     * @param  string  $state          The plaintext state from the callback
     * @param  string  $redirectUri    The redirect URI from the callback
     *
     * @return ConnectionAttempt|null  The claimed attempt, or null if verification failed
     */
    public function verify(
        string $userId,
        string $externalService,
        string $state,
        string $redirectUri,
    ): ?ConnectionAttempt {
        $stateHash = hash('sha256', $state);

        // Find the matching attempt
        $attempt = ConnectionAttempt::query()
            ->where('state_hash', $stateHash)
            ->where('external_service', $externalService)
            ->first();

        if (! $attempt) {
            return null;
        }

        // Check 2: Not expired
        if ($attempt->expires_at->isPast()) {
            return null;
        }

        // Check 4: Same user who started the attempt
        if ($attempt->user_id !== $userId) {
            return null;
        }

        // Check 5: Redirect URI matches the registered one exactly, compared
        // after canonicalisation (research §7) — an equivalent-but-differently-
        // cased scheme or host is the same address and must not be refused,
        // while a prefix near-miss like https://good.example.com.evil.test/
        // still is.
        if (! $this->redirectUriValidator->matchesExact($redirectUri, $attempt->redirect_uri)) {
            return null;
        }

        // Check 3: Atomic single-use claim — UPDATE WHERE consumed_at IS NULL
        $claimed = ConnectionAttempt::query()
            ->where('id', $attempt->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($claimed !== 1) {
            return null;
        }

        $attempt->refresh();
        return $attempt;
    }

    /**
     * Consume an attempt without completing (e.g., on provider denial).
     *
     * This is called when the attempt passes verification but the provider
     * refuses the code. The attempt is still consumed so it cannot be replayed.
     */
    public function consumeWithoutComplete(ConnectionAttempt $attempt): void
    {
        ConnectionAttempt::query()
            ->where('id', $attempt->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
