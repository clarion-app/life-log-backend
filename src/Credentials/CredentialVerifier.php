<?php

namespace ClarionApp\LifeLogBackend\Credentials;

use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use Carbon\CarbonImmutable;

/**
 * Verifies a service credential against the provider.
 *
 * Maps the provider call to a VerificationOutcome and never forwards
 * a provider error body to the response (research §3).
 */
final class CredentialVerifier
{
    /**
     * Verify the stored credential by making a minimal provider call.
     *
     * The provider call is a fetch with an empty range — just enough to
     * prove the credential is accepted. The result is mapped to one of
     * three outcomes: accepted, rejected, or indeterminate.
     */
    public function verify(
        ExternalHealthService $service,
        ServiceCredential $credential,
    ): VerificationOutcome {
        try {
            // A minimal fetch call to verify credentials.
            // The range is arbitrary; we only care about the auth response.
            $since = CarbonImmutable::now()->subDay();
            $until = CarbonImmutable::now();
            $service->fetch('', $since, $until);

            // If we get here, the call succeeded — credentials are valid
            return VerificationOutcome::Accepted;
        } catch (HealthServiceFailure $e) {
            return $this->mapFailureKind($e->kind);
        } catch (\Throwable $e) {
            // Transport errors, unexpected exceptions → indeterminate
            return VerificationOutcome::Indeterminate;
        }
    }

    /**
     * Persist the verification outcome on the credential.
     */
    public function persistOutcome(ServiceCredential $credential, VerificationOutcome $outcome): void
    {
        $credential->last_verification_outcome = $outcome->value;
        $credential->last_verified_at = now();
        $credential->save();
    }

    /**
     * Map a FailureKind to a VerificationOutcome.
     *
     *  - CredentialsRejected → Rejected (the provider actively refused)
     *  - ServiceUnavailable, RateLimited, etc. → Indeterminate (can't conclude)
     */
    protected function mapFailureKind(FailureKind $kind): VerificationOutcome
    {
        return match ($kind) {
            FailureKind::CredentialsRejected => VerificationOutcome::Rejected,
            FailureKind::ServiceUnavailable,
            FailureKind::RateLimited,
            FailureKind::AccessExpired,
            FailureKind::AccessRevoked,
            FailureKind::InvalidRequest,
                => VerificationOutcome::Indeterminate,
        };
    }
}
