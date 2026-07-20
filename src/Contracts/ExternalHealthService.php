<?php

namespace ClarionApp\LifeLogBackend\Contracts;

use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;

/**
 * The five behaviors every external health service supports (FR-008).
 *
 * Everything a service is peculiar about — field names, payload nesting, source
 * units, error shapes, how it pages — is consumed inside the implementation.
 * Every return type here is a final readonly value object, so no service payload
 * is reachable from anything this interface hands back (FR-013). That is what
 * makes adding the fourth service the same amount of work as the second.
 *
 * Obligations on implementers:
 *
 *  1. Translate before returning. Nothing raw is reachable from a returned object.
 *  2. Convert with UnitConverter and a declared rational factor — never a float literal.
 *  3. Skip and record, never guess. An unmappable type or unconvertible unit goes
 *     to UnmappedTypeRecorder and is omitted; the rest of the page still returns.
 *  4. Fail with a kind. Every error path raises HealthServiceFailure carrying one
 *     of the six FailureKind cases; vendor error shapes are mapped internally.
 *  5. Honor the paging contract. nextCursor() is null only at true exhaustion, and
 *     a cursor must survive fromString(toString()).
 *  6. Never name a type outside the vocabulary — enforced by the enum return type
 *     of supportedTypes().
 *  7. Never hold a credential across calls. Fetch the current credential from
 *     ServiceCredentialProvider inside each method that needs one. HealthServiceRegistry
 *     memoises instances, so a credential read in a constructor survives every
 *     rotation for the life of the worker process — the service keeps presenting a
 *     secret the operator has already replaced, and the failure appears only after a
 *     rotation, only in a long-lived worker, and never in a single-request test.
 *  8. Do not silently split a range wider than maxWindow(). If the caller requests
 *     a window wider than the declared maximum, reject with FailureKind::InvalidRequest.
 *     The caller (WindowPlanner) is responsible for splitting.
 *  9. Return only the types requested in the $types filter. A null filter means
 *     "all supported types" (backwards compatible); a non-null filter restricts
 *     results to the listed types only.
 */
interface ExternalHealthService
{
    /** Stable registry name. Must match the name used at registration. */
    public function name(): string;

    /**
     * The vocabulary types this service can supply (FR-009).
     *
     * Partial coverage is normal: a scale supplies weight and nothing else. A
     * caller asking for a range that implies an unsupported type gets what the
     * service does have, not a failure (FR-033).
     *
     * Enum instances rather than strings, so an off-vocabulary type is
     * unrepresentable.
     *
     * @return list<MeasurementType|SessionType>
     */
    public function supportedTypes(): array;

    /**
     * Maximum window the provider will serve in a single request, per type.
     *
     * Returns null if the provider has no known limit (the caller may request
     * any range). Returns a DateInterval if the provider caps the window — the
     * caller MUST NOT request a range wider than this; it must split instead.
     *
     * A service that returns null for a type it supports signals "no limit
     * known" — the caller still checks for provider-side errors and retries
     * with a narrower range if the request fails.
     *
     * @param  MeasurementType|SessionType  $type
     */
    public function maxWindow(MeasurementType|SessionType $type): ?\DateInterval;

    /**
     * Behavior 1 — begin connecting an account.
     *
     * Stores nothing; persistence of the resulting connection belongs to a later
     * phase.
     */
    public function beginConnection(string $userId): ConnectionResult;

    /**
     * Behavior 2 — fetch one page of a time range, already in vocabulary form.
     *
     * A null cursor starts the range; a non-null cursor resumes from that
     * position. The returned page's nextCursor() is null only at true
     * exhaustion — an empty page with a cursor means a sparse span, keep going.
     *
     * The $types filter restricts results to the listed types. A null filter
     * returns all supported types (backwards compatible with pre-058 callers).
     *
     * @param  list<MeasurementType|SessionType>|null  $types  Types to filter by; null for all.
     *
     * @throws HealthServiceFailure InvalidRequest when $until < $since, or when the
     *                              cursor is no longer honored. Never silently
     *                              restarts the range: that would re-deliver
     *                              everything already written.
     */
    public function fetch(
        string $userId,
        CarbonImmutable $since,
        CarbonImmutable $until,
        ?PageCursor $cursor = null,
        ?array $types = null,
    ): ResultPage;

    /**
     * Behavior 3 — renew expired access.
     *
     * A declined renewal is an ordinary result, not an exception; the caller
     * stops retrying and prompts the user to reconnect.
     */
    public function renewAccess(string $userId): RenewalResult;

    /** Behavior 4 — disconnect. Succeeds for an already-disconnected account. */
    public function disconnect(string $userId): DisconnectResult;

    /**
     * Behavior 5 — exchange an approved consent for a usable authorization.
     *
     * Called once, after the package has verified the callback: the state matched a
     * live single-use attempt, the attempt belongs to the signed-in user, and the
     * redirect address matches the registered one. An implementation MUST NOT
     * re-derive any of that; it receives a callback the package has already
     * established is genuine.
     *
     * $redirectUri is passed because most providers require the token exchange to
     * repeat the value used at authorization. It is the registered value, never a
     * value taken from the request.
     *
     * @throws HealthServiceFailure CredentialsRejected when the provider refuses the
     *         code or the credential; InvalidRequest when the code is malformed or
     *         already redeemed. The exception message MUST NOT contain the code, the
     *         client secret, or any provider response body.
     */
    public function completeConnection(
        string $userId,
        string $code,
        string $redirectUri,
    ): AuthorizationGrant;
}
