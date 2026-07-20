<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\FakeSpanService;
use Tests\Support\FakeStepService;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
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
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * FR-013 — nothing of a service's own shape survives past its translate step.
 *
 * The boundary holds structurally rather than by convention: every object that
 * crosses it is a declared final readonly class whose properties are typed, so
 * there is physically nowhere to stash a vendor payload. A single `mixed`
 * property or an `array $raw` passthrough would reopen the boundary, and the
 * leak would only become visible much later, when a second service arrived and
 * a downstream consumer turned out to have been reading the first one's field
 * names all along.
 */
class NormalizationBoundaryTest extends TestCase
{
    private const USER = 'ffffffff-0000-4000-8000-00000000abcd';

    /** Every type permitted to cross the service boundary. */
    private const BOUNDARY_CLASSES = [
        TranslatedMeasurement::class,
        TranslatedSession::class,
        ResultPage::class,
        PageCursor::class,
        ConnectionResult::class,
        RenewalResult::class,
        DisconnectResult::class,
    ];

    /** Names that would signal an unstructured passthrough. */
    private const FORBIDDEN_MEMBERS = [
        'raw', 'rawpayload', 'payload', 'body', 'response', 'original',
        'attributes', 'extra', 'meta', 'metadata', 'vendor', 'source_data',
        '__get', '__call', '__callstatic',
    ];

    /** @test  D4 — each boundary type is final and readonly, so nothing can be bolted on later */
    public function everyBoundaryClassIsFinalAndReadonly(): void
    {
        foreach (self::BOUNDARY_CLASSES as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue($reflection->isFinal(), "{$class} must be final.");
            $this->assertTrue(
                $reflection->isReadOnly(),
                "{$class} must be readonly — a mutable boundary object can be repopulated with "
                . 'service-specific data after construction, past every invariant its constructor enforces.',
            );
        }
    }

    /** @test  no property is mixed, and none is named like a payload dumping ground */
    public function noBoundaryPropertyCanHoldAnUnstructuredPayload(): void
    {
        foreach (self::BOUNDARY_CLASSES as $class) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                $name = $property->getName();

                $this->assertNotContains(
                    strtolower($name),
                    self::FORBIDDEN_MEMBERS,
                    "{$class}::\${$name} names a passthrough. A service's own shape must be consumed "
                    . 'inside its translate step and never reachable from a returned object.',
                );

                $type = $property->getType();
                $this->assertNotNull($type, "{$class}::\${$name} must be typed.");
                $this->assertNotSame(
                    'mixed',
                    (string) $type,
                    "{$class}::\${$name} is mixed — that is a hole big enough for any vendor object.",
                );
            }
        }
    }

    /** @test  and no accessor hands one back either */
    public function noBoundaryMethodReturnsMixedOrNamesAPassthrough(): void
    {
        foreach (self::BOUNDARY_CLASSES as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();

                if ($name === '__construct') {
                    continue;
                }

                $this->assertNotContains(strtolower($name), self::FORBIDDEN_MEMBERS, "{$class}::{$name}()");

                $returnType = $method->getReturnType();
                $this->assertNotNull($returnType, "{$class}::{$name}() must declare a return type.");
                $this->assertNotSame('mixed', (string) $returnType, "{$class}::{$name}() returns mixed.");
            }
        }
    }

    /** @test  a measurement carries only structured, canonical fields */
    public function theOnlyArrayOnAMeasurementIsNoneAtAll(): void
    {
        $arrayProperties = [];

        foreach ((new ReflectionClass(TranslatedMeasurement::class))->getProperties() as $property) {
            if ((string) $property->getType() === 'array') {
                $arrayProperties[] = $property->getName();
            }
        }

        $this->assertSame([], $arrayProperties, 'A measurement has no array-shaped field to smuggle a payload in.');
    }

    /** @test  a session's one array is its declared summary values, keys and all */
    public function aSessionsOnlyArrayIsItsDeclaredSummaryValues(): void
    {
        $arrayProperties = [];

        foreach ((new ReflectionClass(TranslatedSession::class))->getProperties() as $property) {
            if ((string) $property->getType() === 'array') {
                $arrayProperties[] = $property->getName();
            }
        }

        $this->assertSame(['summaryValues'], $arrayProperties);

        // And its contents are constrained by the vocabulary, not by the service.
        $this->expectException(\InvalidArgumentException::class);
        new TranslatedSession(
            userId: self::USER,
            type: SessionType::Sleep,
            startedAt: CarbonImmutable::parse('2026-01-01T22:00:00Z'),
            endedAt: CarbonImmutable::parse('2026-01-02T06:00:00Z'),
            summaryValues: ['vendor_sleep_score' => '88.0000'],
            externalId: 'sleep-1',
            externalService: 'fake-span',
        );
    }

    /** @test  the interface returns only boundary types — never an array of service data */
    public function theInterfaceReturnsOnlyBoundaryTypes(): void
    {
        $expected = [
            'name' => 'string',
            'supportedTypes' => 'array',
            'beginConnection' => ConnectionResult::class,
            'fetch' => ResultPage::class,
            'renewAccess' => RenewalResult::class,
            'disconnect' => DisconnectResult::class,
        ];

        $reflection = new ReflectionClass(ExternalHealthService::class);
        $actual = [];

        foreach ($reflection->getMethods() as $method) {
            $type = $method->getReturnType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type, "{$method->getName()}() must be typed.");
            $actual[$method->getName()] = $type->getName();
        }

        $this->assertSame($expected, $actual);
    }

    /** @test  at runtime, every emitted object is one of the declared classes */
    public function everyObjectCrossingTheBoundaryIsADeclaredValueObject(): void
    {
        foreach ($this->services() as $service) {
            $page = $service->fetch(
                self::USER,
                CarbonImmutable::parse('2026-01-01T00:00:00Z'),
                CarbonImmutable::parse('2026-01-02T00:00:00Z'),
            );

            $this->assertInstanceOf(ResultPage::class, $page);

            foreach ($page->measurements() as $measurement) {
                $this->assertInstanceOf(TranslatedMeasurement::class, $measurement);
            }

            foreach ($page->sessions() as $session) {
                $this->assertInstanceOf(TranslatedSession::class, $session);
            }

            $this->assertInstanceOf(ConnectionResult::class, $service->beginConnection(self::USER));
            $this->assertInstanceOf(RenewalResult::class, $service->renewAccess(self::USER));
            $this->assertInstanceOf(DisconnectResult::class, $service->disconnect(self::USER));
        }
    }

    /** @test  the type is an enum instance, so an off-vocabulary name is unrepresentable */
    public function everyMeasurementCarriesAnEnumTypeAndItsCanonicalUnit(): void
    {
        $seen = 0;

        foreach ($this->services() as $service) {
            $cursor = null;

            do {
                $page = $service->fetch(
                    self::USER,
                    CarbonImmutable::parse('2026-01-01T00:00:00Z'),
                    CarbonImmutable::parse('2026-01-02T00:00:00Z'),
                    $cursor,
                );

                foreach ($page->measurements() as $measurement) {
                    $this->assertInstanceOf(
                        MeasurementType::class,
                        $measurement->type,
                        'A string type would let a service name something outside the vocabulary.',
                    );
                    $this->assertSame($measurement->type->canonicalUnit(), $measurement->unit);
                    $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $measurement->value);
                    $seen++;
                }

                foreach ($page->sessions() as $session) {
                    $this->assertInstanceOf(SessionType::class, $session->type);

                    foreach (array_keys($session->summaryValues) as $key) {
                        $this->assertArrayHasKey($key, $session->type->summaryValues());
                    }

                    $seen++;
                }

                $cursor = $page->nextCursor();
            } while ($cursor !== null);
        }

        $this->assertGreaterThan(0, $seen);
    }

    /** @test  supportedTypes() is enum instances, not names */
    public function supportedTypesAreEnumInstances(): void
    {
        foreach ($this->services() as $service) {
            $types = $service->supportedTypes();

            $this->assertNotEmpty($types);

            foreach ($types as $type) {
                $this->assertTrue(
                    $type instanceof MeasurementType || $type instanceof SessionType,
                    'A string here would let a service advertise a type the vocabulary has never heard of.',
                );
            }
        }
    }

    /** @return list<ExternalHealthService> */
    private function services(): array
    {
        return [new FakeStepService(), new FakeSpanService()];
    }
}
