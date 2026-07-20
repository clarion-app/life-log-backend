<?php

namespace ClarionApp\LifeLogBackend\Sync;

/**
 * Why a connection needs attention, as distinct from how the provider last
 * failed.
 *
 * Distinct from FailureKind, which is 055's closed vocabulary for what a
 * provider did. These are management-side reasons — "reconnect, the credential
 * changed" is more actionable than "credentials_rejected", which is only its
 * symptom.
 */
enum NeedsAttentionReason: string
{
    /** CredentialsRejected arrived under a stale credential_version. */
    case CredentialRotated = 'credential_rotated';

    /** The service's credential was soft-deleted while connections existed. */
    case CredentialRemoved = 'credential_removed';

    /** The grant can no longer be renewed and retrying is futile. */
    case AuthorizationUnrenewable = 'authorization_unrenewable';

    /** The ordinary backoff ladder exhausted. */
    case SyncFailures = 'sync_failures';
}
