<?php

namespace ClarionApp\LifeLogBackend\External;

/**
 * What behavior 4 hands back after disconnecting an account.
 *
 * The two flags are separate because they answer different questions. Local
 * disconnection succeeding while the remote was unreachable is the common case
 * for an already-revoked account, and it is a success — a caller must not retry
 * forever waiting for a remote acknowledgement that will never come. But the
 * distinction still matters: a still-live remote grant that we failed to revoke
 * is worth surfacing to an operator, whereas an unreachable one is not.
 *
 * Disconnecting an already-disconnected account succeeds; the operation is
 * idempotent by design.
 */
final readonly class DisconnectResult
{
    public function __construct(
        public bool $disconnected,
        public bool $remoteReachable,
    ) {
    }

    /** Disconnected locally and confirmed with the service. */
    public static function confirmed(): self
    {
        return new self(true, true);
    }

    /** Disconnected locally; the service could not be reached to confirm. */
    public static function localOnly(): self
    {
        return new self(true, false);
    }
}
