<?php

namespace ClarionApp\LifeLogBackend\Credentials;

/**
 * The outcome of verifying a service credential against the provider.
 */
enum VerificationOutcome: string
{
    /** Provider accepted the credential. */
    case Accepted = 'accepted';

    /** Provider actively refused the credential. */
    case Rejected = 'rejected';

    /** Could not reach a conclusion (service unavailable, rate limited, etc.). */
    case Indeterminate = 'indeterminate';
}
