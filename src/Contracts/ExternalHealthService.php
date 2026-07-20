<?php

namespace ClarionApp\LifeLogBackend\Contracts;

use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;

/**
 * The four behaviors every external health service supports (FR-008).
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
}
