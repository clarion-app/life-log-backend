<?php

namespace ClarionApp\LifeLogBackend\Contracts;

/**
 * The closed set of ways any external health service can fail (FR-016).
 *
 * Closed, because callers match() on it exhaustively: a seventh kind appearing
 * at runtime would fall through handling that was written before it existed,
 * and PHP raising UnhandledMatchError is the correct, loud outcome. Adding a
 * case is therefore a deliberate act that breaks FailureKindTest first.
 *
 * The split is by downstream response, not by severity. A bare
 * temporary/permanent distinction cannot express that AccessExpired should
 * trigger a renewal and a retry while AccessRevoked must not — one is
 * recoverable without the user, the other needs them to reconnect. Collapsing
 * them would either spam renewal attempts that can never succeed or prompt a
 * user who never needed to do anything.
 */
enum FailureKind: string
{
    /** Access token lapsed — renew, then retry. */
    case AccessExpired = 'access_expired';

    /** The user revoked access — do not renew; prompt them to reconnect. */
    case AccessRevoked = 'access_revoked';

    /** Our own credentials were rejected — halt and alert an administrator. */
    case CredentialsRejected = 'credentials_rejected';

    /** Too many requests — back off, honoring the wait hint when present. */
    case RateLimited = 'rate_limited';

    /** The service is down — retry later with backoff. */
    case ServiceUnavailable = 'service_unavailable';

    /** We asked for something incoherent — a bug; surface it, do not retry unchanged. */
    case InvalidRequest = 'invalid_request';
}
