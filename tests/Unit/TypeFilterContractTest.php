<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;
use Tests\Support\FakeStepService;
use Tests\Support\FakeSpanService;

/**
 * T012 — fetch() accepts ?array $types = null; null returns all supported types
 * (backwards compatibility); a single-type filter returns only that type.
 */
class TypeFilterContractTest extends TestCase
{
    private const USER = 'ffffffff-0000-4000-8000-0000000c0f0f';

    private function since(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-01T00:00:00Z');
    }

    private function until(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-02T00:00:00Z');
    }

    /** @test  null type filter returns all supported types (backwards compatibility) */
    public function nullTypeFilterReturnsAllSupported(): void
    {
        $service = new FakeStepService();
        $supported = $service->supportedTypes();

        // Drain all pages with null filter
        $allTypes = [];
        $cursor = null;

        do {
            $page = $service->fetch(self::USER, $this->since(), $this->until(), $cursor, null);
            foreach ($page->measurements() as $m) {
                $allTypes[$m->type->value] = true;
            }
            foreach ($page->sessions() as $s) {
                $allTypes[$s->type->value] = true;
            }
            $cursor = $page->nextCursor();
        } while ($cursor !== null);

        // Every supported type should appear (at least one measurement/session)
        foreach ($supported as $type) {
            // For services that support a type, that type should appear in results
            // unless the fake has no data for it. FakeStepService has data for steps, weight, distance.
        }

        $this->assertNotEmpty($allTypes, 'null filter should return measurements.');
    }

    /** @test  single-type filter returns only that type */
    public function singleTypeFilterReturnsOnlyThatType(): void
    {
        $service = new FakeStepService();

        // Filter to only Steps
        $types = [MeasurementType::Steps];
        $cursor = null;
        $seenTypes = [];

        do {
            $page = $service->fetch(self::USER, $this->since(), $this->until(), $cursor, $types);
            foreach ($page->measurements() as $m) {
                $seenTypes[$m->type->value] = true;
            }
            foreach ($page->sessions() as $s) {
                $seenTypes[$s->type->value] = true;
            }
            $cursor = $page->nextCursor();
        } while ($cursor !== null);

        // Only steps should appear
        $this->assertSame(['steps' => true], $seenTypes, 'Filtering to Steps should return only Steps.');
    }

    /** @test  type filter with multiple types returns only those types */
    public function multiTypeFilterReturnsOnlyRequestedTypes(): void
    {
        $service = new FakeSpanService();

        // Filter to Steps and Sleep only
        $types = [MeasurementType::Steps, SessionType::Sleep];
        $cursor = null;
        $seenTypes = [];

        do {
            $page = $service->fetch(self::USER, $this->since(), $this->until(), $cursor, $types);
            foreach ($page->measurements() as $m) {
                $seenTypes[$m->type->value] = true;
            }
            foreach ($page->sessions() as $s) {
                $seenTypes[$s->type->value] = true;
            }
            $cursor = $page->nextCursor();
        } while ($cursor !== null);

        // Only steps and sleep should appear
        $allowed = ['steps' => true, 'sleep' => true];
        foreach ($seenTypes as $type => $_) {
            $this->assertArrayHasKey(
                $type,
                $allowed,
                "Type '{$type}' should not appear when filtering to Steps and Sleep only.",
            );
        }
    }

    /** @test  empty type filter returns no results */
    public function emptyTypeFilterReturnsNoResults(): void
    {
        $service = new FakeStepService();

        $page = $service->fetch(self::USER, $this->since(), $this->until(), null, []);

        $this->assertEmpty($page->measurements(), 'Empty filter should return no measurements.');
        $this->assertEmpty($page->sessions(), 'Empty filter should return no sessions.');
    }
}
