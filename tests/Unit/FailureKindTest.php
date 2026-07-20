<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ReflectionClass;
use UnhandledMatchError;

/**
 * Pins the failure vocabulary closed.
 *
 * The set being closed is what lets every caller match() exhaustively: a kind
 * outside the six would silently fall through a caller's handling and turn a
 * recoverable condition into a lost sync. Adding a case must therefore be a
 * deliberate, test-breaking act rather than a quiet extension, and the only way
 * to build a failure must be a named constructor so no arbitrary kind can be
 * assembled at a call site.
 */
class FailureKindTest extends TestCase
{
    /** Frozen as released — the closed set of FR-016. */
    private const FROZEN_KINDS = [
        'access_expired',
        'access_revoked',
        'credentials_rejected',
        'rate_limited',
        'service_unavailable',
        'invalid_request',
    ];

    /** @test */
    public function thereAreExactlySixKinds(): void
    {
        $this->assertCount(
            6,
            FailureKind::cases(),
            'The failure set is closed. Adding or removing a kind changes how every caller must '
            . 'handle failures downstream, so it has to break this test first.',
        );

        $this->assertSame(
            self::FROZEN_KINDS,
            array_map(fn (FailureKind $k) => $k->value, FailureKind::cases()),
        );
    }

    /** @test  named constructors are the only reachable construction path */
    public function theConstructorIsPrivate(): void
    {
        $constructor = (new ReflectionClass(HealthServiceFailure::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue(
            $constructor->isPrivate(),
            'A public constructor would let a call site assemble any kind it liked, including one '
            . 'the downstream match() was never written for.',
        );
    }

    /** @test */
    public function eachNamedConstructorCarriesItsOwnKind(): void
    {
        $this->assertSame(FailureKind::AccessExpired, HealthServiceFailure::accessExpired()->kind);
        $this->assertSame(FailureKind::AccessRevoked, HealthServiceFailure::accessRevoked()->kind);
        $this->assertSame(FailureKind::CredentialsRejected, HealthServiceFailure::credentialsRejected()->kind);
        $this->assertSame(FailureKind::RateLimited, HealthServiceFailure::rateLimited()->kind);
        $this->assertSame(FailureKind::ServiceUnavailable, HealthServiceFailure::serviceUnavailable()->kind);
        $this->assertSame(FailureKind::InvalidRequest, HealthServiceFailure::invalidRequest()->kind);
    }

    /** @test  every kind is reachable, so none of the six is decorative */
    public function everyKindHasANamedConstructor(): void
    {
        $produced = [
            HealthServiceFailure::accessExpired()->kind,
            HealthServiceFailure::accessRevoked()->kind,
            HealthServiceFailure::credentialsRejected()->kind,
            HealthServiceFailure::rateLimited()->kind,
            HealthServiceFailure::serviceUnavailable()->kind,
            HealthServiceFailure::invalidRequest()->kind,
        ];

        $this->assertSame(FailureKind::cases(), $produced);
    }

    /** @test  FR-017 — the wait hint belongs to rate limiting and nowhere else */
    public function onlyRateLimitedCarriesARetryHint(): void
    {
        $this->assertSame(90, HealthServiceFailure::rateLimited(90)->retryAfterSeconds);
        $this->assertNull(HealthServiceFailure::rateLimited()->retryAfterSeconds);

        $this->assertNull(HealthServiceFailure::accessExpired()->retryAfterSeconds);
        $this->assertNull(HealthServiceFailure::accessRevoked()->retryAfterSeconds);
        $this->assertNull(HealthServiceFailure::credentialsRejected()->retryAfterSeconds);
        $this->assertNull(HealthServiceFailure::serviceUnavailable()->retryAfterSeconds);
        $this->assertNull(HealthServiceFailure::invalidRequest()->retryAfterSeconds);
    }

    /** @test  the message is service-authored prose; nothing downstream parses it */
    public function theMessageIsCarriedThrough(): void
    {
        $failure = HealthServiceFailure::serviceUnavailable('upstream returned a maintenance page');

        $this->assertStringContainsString('maintenance page', $failure->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $failure);
    }

    /** @test  there is nowhere to put a status code or vendor error object */
    public function theFailureHasNoPropertyThatCouldLeakAVendorShape(): void
    {
        $properties = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new ReflectionClass(HealthServiceFailure::class))->getProperties(),
        );

        // Only the two declared properties, plus whatever RuntimeException itself
        // owns. Anything else would be a place for a service's own error shape to
        // survive past its translate step.
        $declared = array_values(array_diff($properties, ['message', 'code', 'file', 'line', 'trace', 'previous', 'string']));
        sort($declared);

        $this->assertSame(['kind', 'retryAfterSeconds'], $declared);
    }

    /** @test  an unhandled kind must explode, not fall through */
    public function anIncompleteMatchRaisesUnhandledMatchError(): void
    {
        $incomplete = static fn (FailureKind $kind): string => match ($kind) {
            FailureKind::AccessExpired       => 'renew',
            FailureKind::AccessRevoked       => 'reconnect',
            FailureKind::CredentialsRejected => 'alert',
            FailureKind::RateLimited         => 'back off',
            FailureKind::ServiceUnavailable  => 'retry later',
            // InvalidRequest deliberately omitted
        };

        $this->assertSame('renew', $incomplete(FailureKind::AccessExpired));

        $this->expectException(UnhandledMatchError::class);
        $incomplete(FailureKind::InvalidRequest);
    }
}
