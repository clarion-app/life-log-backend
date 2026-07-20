<?php

namespace Tests\Support;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\AuthorizationGrant;
use ClarionApp\LifeLogBackend\External\ConnectionResult;
use ClarionApp\LifeLogBackend\External\DisconnectResult;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\RenewalResult;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\External\TranslatedMeasurement;
use ClarionApp\LifeLogBackend\External\TranslatedSession;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;

/**
 * The suite every external health service inherits.
 *
 * A new service's whole test obligation is to extend this class and say which
 * service it is. That is the claim this file has to earn: if adding a service
 * meant also writing its own version of these checks, the contract would not be
 * doing the work — the second service and the fourth would cost the same only
 * because someone re-derived the same assertions each time.
 *
 * **Zero service-specific branching** (FR-030, SC-002). No `instanceof` against
 * a concrete service, no switch on `name()`, no "except for the one that has no
 * sessions". Every check below is written against the interface and the
 * vocabulary alone. That constraint is what makes a pass here meaningful: an
 * accommodation added for one service would silently stop testing the others.
 *
 * It has one consequence worth stating. The suite can only reach behavior the
 * interface exposes, so it cannot force a service to produce each of the six
 * failure kinds — only the two the contract makes reachable from a legal call
 * (a backwards range and a stale cursor). Proving all six are *producible* is
 * per-service work, and lives in the service's own tests.
 */
abstract class HealthServiceConformanceTestCase extends TestCase
{
    private const USER = 'ffffffff-0000-4000-8000-0000000c0f0f';

    /**
     * The service under test. The only thing a subclass supplies.
     *
     * Called fresh per test, so state one test leaves behind — an exhausted
     * cursor generation, an armed failure — cannot reach the next.
     */
    abstract protected function service(): ExternalHealthService;

    protected function since(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-01T00:00:00Z');
    }

    protected function until(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-02T00:00:00Z');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Walk a service to exhaustion.
     *
     * @return list<ResultPage>
     */
    private function drain(ExternalHealthService $service, int $guard = 500): array
    {
        $pages = [];
        $cursor = null;

        do {
            $page = $service->fetch(self::USER, $this->since(), $this->until(), $cursor);
            $pages[] = $page;
            $cursor = $page->nextCursor();

            $this->assertLessThan(
                $guard,
                count($pages),
                'Paging did not terminate: nextCursor() never became null.',
            );
        } while ($cursor !== null);

        return $pages;
    }

    /**
     * The external identity of everything on a page, in delivery order.
     *
     * @return list<string>
     */
    private function identities(ResultPage $page): array
    {
        $ids = [];

        foreach ($page->measurements() as $measurement) {
            $ids[] = 'm:' . $measurement->externalService . ':' . $measurement->externalId;
        }

        foreach ($page->sessions() as $session) {
            $ids[] = 's:' . $session->externalService . ':' . $session->externalId;
        }

        return $ids;
    }

    /**
     * Everything a full drain delivered.
     *
     * @return array{measurements: list<TranslatedMeasurement>, sessions: list<TranslatedSession>}
     */
    private function drainAll(ExternalHealthService $service): array
    {
        $measurements = [];
        $sessions = [];

        foreach ($this->drain($service) as $page) {
            foreach ($page->measurements() as $measurement) {
                $measurements[] = $measurement;
            }

            foreach ($page->sessions() as $session) {
                $sessions[] = $session;
            }
        }

        return ['measurements' => $measurements, 'sessions' => $sessions];
    }

    // ------------------------------------------------------------- behavior 0

    /** @test  the registry name is non-empty and does not change between calls */
    public function itReportsAStableName(): void
    {
        $service = $this->service();

        $this->assertNotSame('', $service->name());
        $this->assertSame($service->name(), $service->name());
    }

    /**
     * @test  FR-009 — supported types are vocabulary enum instances, never strings
     *
     * The enum return type already makes an off-vocabulary name unrepresentable;
     * what is checked here is that the list is usable — non-empty and free of
     * duplicates, so a caller can treat it as a set.
     */
    public function itDeclaresSupportedTypesFromTheVocabulary(): void
    {
        $types = $this->service()->supportedTypes();

        $this->assertNotSame([], $types, 'A service that supports nothing has no reason to exist.');

        $seen = [];

        foreach ($types as $type) {
            $this->assertTrue(
                $type instanceof MeasurementType || $type instanceof SessionType,
                'supportedTypes() may only contain vocabulary enum instances.',
            );

            $key = $type::class . ':' . $type->value;
            $this->assertNotContains($key, $seen, "Duplicate supported type '{$type->value}'.");
            $seen[] = $key;
        }
    }

    // ------------------------------------------------------------- behavior 1

    /** @test  behavior 1 — begin connecting an account */
    public function itBeginsAConnection(): void
    {
        $service = $this->service();
        $result = $service->beginConnection(self::USER);

        $this->assertInstanceOf(ConnectionResult::class, $result);
        $this->assertSame(
            $service->name(),
            $result->externalService,
            'A connection result must name the service that produced it, under its registry name.',
        );
        $this->assertNotSame('', $result->authorizationUrl);
        $this->assertNotSame('', $result->state);
    }

    /** @test  the state value ties one callback to one request, so it cannot be fixed */
    public function itIssuesADistinctStateValuePerUser(): void
    {
        $service = $this->service();

        $this->assertNotSame(
            $service->beginConnection(self::USER)->state,
            $service->beginConnection('11111111-0000-4000-8000-000000000001')->state,
        );
    }

    // ------------------------------------------------------------- behavior 3

    /** @test  behavior 3 — a declined renewal is a result, not an exception */
    public function itReturnsARenewalResult(): void
    {
        $result = $this->service()->renewAccess(self::USER);

        $this->assertInstanceOf(RenewalResult::class, $result);

        // An unknown expiry is legal; a claimed renewal with an expiry already in
        // the past is not, because a caller would renew in a loop forever.
        if ($result->renewed && $result->expiresAt !== null) {
            $this->assertTrue($result->expiresAt->greaterThan(CarbonImmutable::now()));
        }
    }

    // ------------------------------------------------------------- behavior 4

    /** @test  behavior 4 — disconnecting succeeds, and succeeds again */
    public function itDisconnectsIdempotently(): void
    {
        $service = $this->service();

        $first = $service->disconnect(self::USER);
        $second = $service->disconnect(self::USER);

        $this->assertInstanceOf(DisconnectResult::class, $first);
        $this->assertTrue($first->disconnected);
        $this->assertTrue(
            $second->disconnected,
            'Disconnecting an already-disconnected account must succeed — a caller retrying after a '
            . 'partial failure would otherwise never converge.',
        );
    }

    // ------------------------------------------------------------- behavior 5

    /** @test  behavior 5 — completeConnection returns an AuthorizationGrant */
    public function itCompletesAConnection(): void
    {
        $service = $this->service();
        $grant = $service->completeConnection(self::USER, 'test-code', 'https://example.com/callback');

        $this->assertInstanceOf(AuthorizationGrant::class, $grant);
        $this->assertNotSame('', $grant->accessToken);
        $this->assertNotSame('', $grant->refreshToken);
        $this->assertTrue(
            $grant->expiresAt->greaterThan(CarbonImmutable::now()),
            'An expired grant on return means the token exchange succeeded but is already useless.',
        );
    }

    /**
     * @test  completeConnection never leaks the code or the secret in an error message
     *
     * A failure here means the token exchange rejected the code or the credential.
     * The message is safe for logging, so it must not contain the code, the client
     * secret, or any provider response body.
     */
    public function itDoesNotLeakCredentialsInCompleteConnectionErrors(): void
    {
        $service = $this->service();
        $secret = 'sk_test_4eC39HqLyjWDarjtT1zdp7dc';

        // The service may or may not have a way to fail completeConnection.
        // If it does, the message must not leak sensitive values.
        try {
            $service->completeConnection(self::USER, 'bogus-code', 'https://example.com/callback');
        } catch (HealthServiceFailure $e) {
            $message = $e->getMessage();

            $this->assertStringNotContainsString('bogus-code', $message);
            $this->assertStringNotContainsString($secret, $message);
            $this->assertContains(
                $e->kind,
                [FailureKind::CredentialsRejected, FailureKind::InvalidRequest],
                'completeConnection failures should be CredentialsRejected or InvalidRequest.',
            );
        }
    }

    // ------------------------------------------- behavior 2: the paging contract

    /** @test  FR-011 — driving the range to exhaustion delivers each identity exactly once */
    public function itDeliversEveryResultExactlyOnce(): void
    {
        $delivered = [];

        foreach ($this->drain($this->service()) as $page) {
            foreach ($this->identities($page) as $id) {
                $delivered[] = $id;
            }
        }

        $this->assertSame(
            $delivered,
            array_values(array_unique($delivered)),
            'A repeated identity means a cursor re-delivered a page. Permanent history would gain a '
            . 'second copy of a reading that already happened once.',
        );
    }

    /** @test  FR-012 — a cursor survives being written down and read back */
    public function itResumesFromACursorThatWasStoredAndRestored(): void
    {
        $service = $this->service();

        $first = $service->fetch(self::USER, $this->since(), $this->until());
        $cursor = $first->nextCursor();

        if ($cursor === null) {
            // A single-page range has no resume point to test. Everything else
            // about paging is still covered; assert the shape and move on.
            $this->assertInstanceOf(ResultPage::class, $first);

            return;
        }

        $direct = $service->fetch(self::USER, $this->since(), $this->until(), $cursor);
        $restored = $service->fetch(
            self::USER,
            $this->since(),
            $this->until(),
            PageCursor::fromString($cursor->toString()),
        );

        $this->assertSame(
            $this->identities($direct),
            $this->identities($restored),
            'A cursor persisted to a column and re-supplied days later must resume at the identical '
            . 'position — a backfill spanning years has to survive process death.',
        );
        $this->assertSame($direct->nextCursor()?->toString(), $restored->nextCursor()?->toString());
    }

    /** @test  a null cursor means "start the range", and is the documented default */
    public function itTreatsANullCursorAsTheStartOfTheRange(): void
    {
        $service = $this->service();

        $explicit = $service->fetch(self::USER, $this->since(), $this->until(), null);
        $default = $service->fetch(self::USER, $this->since(), $this->until());

        $this->assertSame($this->identities($default), $this->identities($explicit));
    }

    /**
     * @test  exhaustion is the null cursor, and it is actually reached
     *
     * A service whose cursor never goes null pages forever; one that goes null
     * early truncates the range. Both look like "the user has less history than
     * they do", which is why termination is asserted rather than assumed.
     *
     * That emptiness and exhaustion are *independent* — an empty page mid-range
     * meaning "keep going" — needs a range with a deliberate gap in it, which is
     * fixture-shaped and therefore lives in PagingContractTest. The suite cannot
     * ask an arbitrary service for a sparse page without knowing how to build
     * one, and knowing that would be service-specific branching.
     */
    public function itSignalsExhaustionWithANullCursor(): void
    {
        $pages = $this->drain($this->service());

        $this->assertNull(
            $pages[count($pages) - 1]->nextCursor(),
            'The drain stopped, so the last page must be the one that reported exhaustion.',
        );

        // Re-fetching the exhausted range from the start is legal and must stay
        // terminating: a backfill re-run must not become an infinite one.
        $this->assertNull($this->drain($this->service())[count($pages) - 1]->nextCursor());
    }

    /** @test  a backwards range is a caller bug, surfaced rather than answered with nothing */
    public function itRejectsABackwardsRange(): void
    {
        try {
            $this->service()->fetch(self::USER, $this->until(), $this->since());
            $this->fail('until < since must raise, not return an empty page.');
        } catch (HealthServiceFailure $e) {
            $this->assertSame(FailureKind::InvalidRequest, $e->kind);
        }
    }

    // --------------------------------------------------- FR-016: failure kinds

    /**
     * @test  FR-016 — every failure is one of the six kinds
     *
     * Reachable through the interface alone: a backwards range is the one error
     * the contract guarantees any service can be driven into without knowing
     * anything about it. What is asserted is the closure of the set — the raised
     * type is HealthServiceFailure and its kind is a member, so no vendor
     * exception and no seventh kind escaped the implementation.
     */
    public function itFailsOnlyWithKindsFromTheClosedSet(): void
    {
        $failures = [];

        try {
            $this->service()->fetch(self::USER, $this->until(), $this->since());
        } catch (HealthServiceFailure $e) {
            $failures[] = $e;
        }

        $this->assertNotSame([], $failures, 'No failure was reachable to inspect.');

        foreach ($failures as $failure) {
            $this->assertContains($failure->kind, FailureKind::cases());

            // Only rate limiting carries a wait hint. A number attached to any
            // other kind would be a caller's cue to sleep before a retry that
            // cannot succeed.
            if ($failure->kind !== FailureKind::RateLimited) {
                $this->assertNull($failure->retryAfterSeconds);
            }

            $this->assertNotSame('', $failure->getMessage());
        }
    }

    // ----------------------------------------------- FR-013: vocabulary output

    /**
     * @test  every emitted measurement is vocabulary-conformant, in canonical units
     *
     * This is the property the whole contract exists for. Whatever the service
     * called this reading, whatever unit it arrived in, however deeply it was
     * nested — by the time it crosses the boundary it is a vocabulary type in
     * that type's one canonical unit, at the precision the storage columns hold.
     */
    public function itEmitsOnlyVocabularyConformantMeasurements(): void
    {
        $service = $this->service();
        $supported = $service->supportedTypes();

        foreach ($this->drainAll($service)['measurements'] as $measurement) {
            $type = $measurement->type;

            $this->assertInstanceOf(MeasurementType::class, $type);
            $this->assertContains(
                $type,
                $supported,
                "Emitted measurement type '{$type->value}' is not among the types this service declares.",
            );
            $this->assertSame(
                $type->canonicalUnit(),
                $measurement->unit,
                "Type '{$type->value}' is stored in '{$type->canonicalUnit()}'; a value in any other unit "
                . 'is a different number wearing the same name.',
            );
            $this->assertMatchesRegularExpression(
                '/^-?\d+\.\d{4}$/',
                $measurement->value,
                'Values cross the boundary as 4dp decimal strings, matching decimal(16,4) storage exactly. '
                . 'A float here would reintroduce the drift the conversion path exists to avoid.',
            );
            $this->assertSame(
                $service->name(),
                $measurement->externalService,
                'A measurement must be attributed to the service under its registry name, or dedup on '
                . '(external_service, external_id) matches the wrong rows.',
            );
            $this->assertNotSame('', $measurement->externalId);
            $this->assertInstanceOf(CarbonImmutable::class, $measurement->recordedAt);
        }

        // A service with nothing to emit in this range still has to have been
        // asked, and the drain above is what asks.
        $this->assertNotSame('', $service->name());
    }

    /** @test  the same for sessions: declared summary keys only, in declared units */
    public function itEmitsOnlyVocabularyConformantSessions(): void
    {
        $service = $this->service();
        $supported = $service->supportedTypes();

        foreach ($this->drainAll($service)['sessions'] as $session) {
            $type = $session->type;

            $this->assertInstanceOf(SessionType::class, $type);
            $this->assertContains($type, $supported);
            $this->assertSame($service->name(), $session->externalService);
            $this->assertNotSame('', $session->externalId);

            $this->assertTrue(
                $session->endedAt->greaterThan($session->startedAt),
                'A session that ends at or before it starts makes every duration derived from it wrong.',
            );

            $declared = $type->summaryValues();

            foreach ($session->summaryValues as $key => $value) {
                // Omitting a declared value is fine — a service may not measure
                // it. Carrying an undeclared one is not: it is a number of
                // unknown meaning and unknown unit in permanent history.
                $this->assertArrayHasKey(
                    $key,
                    $declared,
                    "Session type '{$type->value}' declares no summary value '{$key}'.",
                );
                $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $value);
            }

            $this->assertSame(
                array_intersect_key($declared, $session->summaryValues),
                $session->summaryUnits(),
                'Summary units come from the vocabulary, not from the service.',
            );
        }

        $this->assertNotSame('', $service->name());
    }

    /**
     * @test  FR-013 — nothing raw is reachable from what crosses the boundary
     *
     * Checked structurally rather than by inspecting payloads: every returned
     * object is a final readonly value object whose properties are scalars,
     * enums, dates, or arrays of those. There is nowhere for a vendor envelope
     * to hide, which is why the boundary holds without asking implementers to
     * be careful.
     */
    public function itReturnsOnlyValueObjectsWithNoRawPayloadReachable(): void
    {
        $service = $this->service();
        $drained = $this->drainAll($service);
        $returned = [
            $service->beginConnection(self::USER),
            $service->completeConnection(self::USER, 'test-code', 'https://example.com/callback'),
            $service->renewAccess(self::USER),
            $service->disconnect(self::USER),
            ...$drained['measurements'],
            ...$drained['sessions'],
        ];

        foreach ($returned as $object) {
            $reflection = new \ReflectionClass($object);

            $this->assertTrue($reflection->isFinal(), $reflection->getName() . ' must be final.');
            $this->assertTrue($reflection->isReadOnly(), $reflection->getName() . ' must be readonly.');

            foreach ($reflection->getProperties() as $property) {
                $value = $property->getValue($object);

                $this->assertTrue(
                    $this->isTransportable($value),
                    $reflection->getName() . '::$' . $property->getName() . ' holds a '
                    . get_debug_type($value) . ', which can reach back into service-specific shape.',
                );
            }
        }
    }

    /** Scalars, enums, dates, and arrays of those — nothing that can carry a payload. */
    private function isTransportable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if ($value instanceof \UnitEnum || $value instanceof \DateTimeInterface) {
            return true;
        }

        if ($value instanceof PageCursor || $value instanceof CarbonImmutable) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->isTransportable($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    // --------------------------------------------------- T014: contract conformance additions

    /**
     * @test  every supported type has a declared max window (may be null = no limit)
     *
     * The contract requires maxWindow() to be callable for every type the
     * service claims. A null return means "no known limit"; a DateInterval
     * means "split any wider range before calling."
     */
    public function maxWindowDeclared(): void
    {
        $service = $this->service();

        foreach ($service->supportedTypes() as $type) {
            $maxWindow = $service->maxWindow($type);

            $this->assertTrue(
                $maxWindow === null || $maxWindow instanceof \DateInterval,
                "maxWindow({$type->value}) must return ?DateInterval, got " . get_debug_type($maxWindow),
            );
        }
    }

    /**
     * @test  null type filter returns all supported types
     *
     * When types is omitted or null, the service returns everything it has.
     * This is the default behavior and must not filter.
     */
    public function nullTypeFilterReturnsAll(): void
    {
        $service = $this->service();
        $page = $service->fetch(self::USER, $this->since(), $this->until());

        // Collect all types present
        $typesFound = [];

        foreach ($page->measurements() as $m) {
            $typesFound[$m->type->value] = true;
        }

        foreach ($page->sessions() as $s) {
            $typesFound[$s->type->value] = true;
        }

        // Re-fetch with explicit null — must return the same types
        $pageNull = $service->fetch(self::USER, $this->since(), $this->until(), null, null);
        $typesNull = [];

        foreach ($pageNull->measurements() as $m) {
            $typesNull[$m->type->value] = true;
        }

        foreach ($pageNull->sessions() as $s) {
            $typesNull[$s->type->value] = true;
        }

        $this->assertSame(
            array_keys($typesFound),
            array_keys($typesNull),
            'Null type filter must return the same types as no filter.',
        );
    }

    /**
     * @test  type filter restricts output to requested types only
     *
     * When types is provided, the service returns only items matching those types.
     * This is obligation 9 from the contract.
     */
    public function typeFilterRestricts(): void
    {
        $service = $this->service();
        $supported = $service->supportedTypes();

        if (count($supported) < 2) {
            // Need at least two types to test filtering
            $this->markTestSkipped('Service supports fewer than 2 types; filtering cannot be meaningfully tested.');
        }

        $filterType = $supported[0];
        $page = $service->fetch(
            self::USER,
            $this->since(),
            $this->until(),
            null,
            [$filterType],
        );

        foreach ($page->measurements() as $m) {
            $this->assertSame(
                $filterType,
                $m->type,
                "Measurement type {$m->type->value} is not in the filter set.",
            );
        }

        foreach ($page->sessions() as $s) {
            $this->assertSame(
                $filterType,
                $s->type,
                "Session type {$s->type->value} is not in the filter set.",
            );
        }
    }

    /**
     * @test  empty type filter returns nothing
     *
     * An empty array for types is legal and must return an empty page.
     */
    public function emptyTypeFilterReturnsNothing(): void
    {
        $service = $this->service();
        $page = $service->fetch(
            self::USER,
            $this->since(),
            $this->until(),
            null,
            [],
        );

        $this->assertEmpty($page->measurements());
        $this->assertEmpty($page->sessions());
    }
}
