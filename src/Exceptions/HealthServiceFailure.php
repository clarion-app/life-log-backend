<?php

namespace ClarionApp\LifeLogBackend\Exceptions;

use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use RuntimeException;

/**
 * The single failure type every external health service raises.
 *
 * There is deliberately no property capable of holding a status code, a response
 * body, or a vendor error object. The normalization boundary holds because there
 * is nowhere to put service-specific data — not because a convention asks
 * implementers not to. A service maps its own error shape to a kind inside its
 * translate step and the shape stops there.
 *
 * The constructor is private so the six named constructors are the only
 * reachable construction path; a kind outside the closed set cannot be
 * assembled at a call site.
 *
 * The message is service-authored prose for a human reading a log. Nothing
 * downstream parses it — behavior is driven entirely by $kind.
 */
final class HealthServiceFailure extends RuntimeException
{
    private function __construct(
        public readonly FailureKind $kind,
        public readonly ?int $retryAfterSeconds,
        string $message,
    ) {
        parent::__construct($message === '' ? $kind->value : $message);
    }

    /** Token lapsed — the caller may renew and retry. */
    public static function accessExpired(string $message = ''): self
    {
        return new self(FailureKind::AccessExpired, null, $message);
    }

    /** The user withdrew access — renewal cannot help; they must reconnect. */
    public static function accessRevoked(string $message = ''): self
    {
        return new self(FailureKind::AccessRevoked, null, $message);
    }

    /** Our application credentials were rejected — an operator has to act. */
    public static function credentialsRejected(string $message = ''): self
    {
        return new self(FailureKind::CredentialsRejected, null, $message);
    }

    /**
     * Rate limited (FR-017).
     *
     * The wait hint is optional because plenty of services rate-limit without
     * telling you for how long; absent it the caller falls back to its own
     * backoff rather than guessing a number and hammering the service.
     */
    public static function rateLimited(?int $retryAfterSeconds = null, string $message = ''): self
    {
        return new self(FailureKind::RateLimited, $retryAfterSeconds, $message);
    }

    /** The service is unreachable or erroring — retry later. */
    public static function serviceUnavailable(string $message = ''): self
    {
        return new self(FailureKind::ServiceUnavailable, null, $message);
    }

    /** A malformed request: a backwards range, an unhonored cursor, a bug on our side. */
    public static function invalidRequest(string $message = ''): self
    {
        return new self(FailureKind::InvalidRequest, null, $message);
    }
}
