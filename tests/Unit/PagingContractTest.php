<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\FakeSpanService;
use Tests\Support\FakeStepService;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\Contracts\FailureKind;
use ClarionApp\LifeLogBackend\Exceptions\HealthServiceFailure;
use ClarionApp\LifeLogBackend\External\PageCursor;
use ClarionApp\LifeLogBackend\External\ResultPage;
use Carbon\CarbonImmutable;

/**
 * The paging contract, driven through two services that page by completely
 * different internal mechanisms.
 *
 * The load-bearing rule is that exhaustion and emptiness are independent. A
 * caller that stops on an empty page silently truncates a backfill the moment
 * the user's data has a gap in it, and the truncation looks like "the user has
 * no older data" rather than like a bug.
 */
class PagingContractTest extends TestCase
{
    private const USER = 'ffffffff-0000-4000-8000-00000000abcd';

    private function since(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-01T00:00:00Z');
    }

    private function until(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-02T00:00:00Z');
    }

    /**
     * Walk a service to exhaustion, returning every page it produced.
     *
     * @return list<ResultPage>
     */
    private function drain(ExternalHealthService $service, int $guard = 200): array
    {
        $pages = [];
        $cursor = null;

        do {
            $page = $service->fetch(self::USER, $this->since(), $this->until(), $cursor);
            $pages[] = $page;
            $cursor = $page->nextCursor();

            $this->assertLessThan($guard, count($pages), 'Paging did not terminate.');
        } while ($cursor !== null);

        return $pages;
    }

    /** @return list<string> */
    private function identities(ResultPage $page): array
    {
        $ids = [];

        foreach ($page->measurements() as $m) {
            $ids[] = 'm:' . $m->externalService . ':' . $m->externalId;
        }

        foreach ($page->sessions() as $s) {
            $ids[] = 's:' . $s->externalService . ':' . $s->externalId;
        }

        return $ids;
    }

    /** @test  combination 1 — an ordinary mid-range page: results and a cursor */
    public function aMidRangePageCarriesResultsAndACursor(): void
    {
        $service = new FakeSpanService(measurementCount: 6, sessionCount: 2, pageSize: 3);

        $page = $service->fetch(self::USER, $this->since(), $this->until());

        $this->assertNotEmpty($this->identities($page));
        $this->assertNotNull($page->nextCursor());
    }

    /** @test  combination 2 — the final page still carries data */
    public function theFinalPageCanCarryResultsAndNoCursor(): void
    {
        $service = new FakeSpanService(measurementCount: 3, sessionCount: 0, pageSize: 3);

        $page = $service->fetch(self::USER, $this->since(), $this->until());

        $this->assertCount(3, $page->measurements());
        $this->assertNull($page->nextCursor());
    }

    /** @test  combination 3 — the one that matters: empty, but keep going */
    public function anEmptyPageWithACursorMeansKeepGoing(): void
    {
        // Page 1 is a sparse span: the user simply wore nothing that stretch.
        $service = new FakeSpanService(
            measurementCount: 6,
            sessionCount: 0,
            pageSize: 3,
            sparsePages: [1],
        );

        $pages = $this->drain($service);

        $this->assertCount(3, $pages);
        $this->assertSame([], $this->identities($pages[1]), 'The sparse page should carry nothing.');
        $this->assertNotNull(
            $pages[1]->nextCursor(),
            'An empty page is not exhaustion. Stopping here would truncate the backfill at the '
            . 'first gap in the user\'s data and look like an absence of history.',
        );

        // And the data after the gap is genuinely delivered.
        $this->assertCount(3, $pages[2]->measurements());
    }

    /** @test  combination 4 — an empty range is exhaustion, not a failure */
    public function anEmptyRangeExhaustsWithoutFailing(): void
    {
        $service = new FakeSpanService(measurementCount: 0, sessionCount: 0, pageSize: 3);

        $page = $service->fetch(self::USER, $this->since(), $this->until());

        $this->assertSame([], $this->identities($page));
        $this->assertNull($page->nextCursor());
    }

    /** @test  exhaustion is the null cursor, never the empty result set */
    public function exhaustionIsSignalledByTheCursorAndNotByEmptiness(): void
    {
        $service = new FakeSpanService(
            measurementCount: 6,
            sessionCount: 0,
            pageSize: 3,
            sparsePages: [1],
        );

        $pages = $this->drain($service);

        $empties = array_filter($pages, fn (ResultPage $p) => $this->identities($p) === []);
        $this->assertCount(1, $empties, 'Exactly one page in this fixture is empty.');

        // That empty page is not the last one.
        $this->assertNotSame($pages[count($pages) - 1], array_values($empties)[0]);
        $this->assertNull($pages[count($pages) - 1]->nextCursor());
    }

    /** @test  FR-012 — a cursor survives being written down and read back */
    public function aCursorRoundTripsThroughItsStringForm(): void
    {
        foreach ([new FakeStepService(pageSize: 2), new FakeSpanService(pageSize: 3)] as $service) {
            $first = $service->fetch(self::USER, $this->since(), $this->until());
            $cursor = $first->nextCursor();
            $this->assertNotNull($cursor);

            $direct = $service->fetch(self::USER, $this->since(), $this->until(), $cursor);
            $revived = $service->fetch(
                self::USER,
                $this->since(),
                $this->until(),
                PageCursor::fromString($cursor->toString()),
            );

            $this->assertSame(
                $this->identities($direct),
                $this->identities($revived),
                'A cursor stored and re-supplied must resume at the identical position — Phase 1.3 '
                . 'backfills span years and have to survive process death.',
            );
            $this->assertSame(
                $direct->nextCursor()?->toString(),
                $revived->nextCursor()?->toString(),
            );
        }
    }

    /** @test  a cursor's contents are storable scalars, not a live object graph */
    public function aCursorHoldsOnlyJsonSerializableScalars(): void
    {
        $cursor = PageCursor::fromArray(['offset' => 500, 'generation' => 1, 'token' => 'abc', 'done' => false]);

        $this->assertSame(
            ['offset' => 500, 'generation' => 1, 'token' => 'abc', 'done' => false],
            PageCursor::fromString($cursor->toString())->toArray(),
        );

        $this->expectException(\InvalidArgumentException::class);
        PageCursor::fromArray(['resume' => fn () => null]);
    }

    /** @test  FR-011 — every result in the range, exactly once */
    public function drivingToExhaustionDeliversEveryIdentityExactlyOnce(): void
    {
        $service = new FakeSpanService(
            measurementCount: 17,
            sessionCount: 4,
            pageSize: 5,
            sparsePages: [2],
        );

        $delivered = [];

        foreach ($this->drain($service) as $page) {
            foreach ($this->identities($page) as $id) {
                $delivered[] = $id;
            }
        }

        $this->assertCount(21, $delivered);
        $this->assertSame(
            $delivered,
            array_values(array_unique($delivered)),
            'A duplicate here means a cursor re-delivered a page — permanent history would gain '
            . 'a second copy of a reading.',
        );
    }

    /** @test  the same holds for a service that pages by integer offset */
    public function offsetPagingAlsoDeliversEveryIdentityExactlyOnce(): void
    {
        $service = new FakeStepService(pageSize: 2);

        $delivered = [];

        foreach ($this->drain($service) as $page) {
            foreach ($this->identities($page) as $id) {
                $delivered[] = $id;
            }
        }

        $this->assertNotEmpty($delivered);
        $this->assertSame($delivered, array_values(array_unique($delivered)));
    }

    /** @test  an unhonored cursor is a failure, never a silent restart */
    public function anUnhonoredCursorRaisesInvalidRequest(): void
    {
        foreach ([new FakeStepService(pageSize: 2), new FakeSpanService(pageSize: 3)] as $service) {
            $cursor = $service->fetch(self::USER, $this->since(), $this->until())->nextCursor();
            $this->assertNotNull($cursor);

            $service->invalidateCursors();

            try {
                $service->fetch(self::USER, $this->since(), $this->until(), $cursor);
                $this->fail('A stale cursor must not be accepted.');
            } catch (HealthServiceFailure $e) {
                $this->assertSame(
                    FailureKind::InvalidRequest,
                    $e->kind,
                    'Silently restarting the range would duplicate everything already delivered and '
                    . 'hide the resume bug behind a merely slow backfill.',
                );
            }
        }
    }

    /** @test  a backwards range is a caller bug, not a user with no data */
    public function aBackwardsRangeRaisesInvalidRequest(): void
    {
        foreach ([new FakeStepService(), new FakeSpanService()] as $service) {
            try {
                $service->fetch(self::USER, $this->until(), $this->since());
                $this->fail('until < since must not be answered with an empty page.');
            } catch (HealthServiceFailure $e) {
                $this->assertSame(FailureKind::InvalidRequest, $e->kind);
            }
        }
    }

    /**
     * @test  SC-005 — 10,000 results at page size 500, delivered exactly once,
     *        with peak memory bounded by roughly one page
     *
     * The memory half is the point. Exactly-once delivery can be satisfied by a
     * service that materializes the entire range and hands out slices of it,
     * and that implementation is fine at 10,000 results and fatal at a
     * multi-year backfill. Bounding peak memory is what distinguishes real
     * paging from an array being sliced.
     *
     * Two things make the assertion non-vacuous. The fake generates raw items
     * lazily, one at a time, so an eager fake could not pass. And nothing here
     * retains a page: identities are checked against the expected sequence as
     * they arrive rather than collected, because a 10,000-entry list of strings
     * would dominate the very measurement being taken.
     */
    public function aTenThousandResultRangeDeliversExactlyOnceInBoundedMemory(): void
    {
        $pageSize = 500;
        $total = 10000;

        // Calibrate against one page of this service rather than a magic number,
        // so the bound tracks the real cost of a TranslatedMeasurement.
        $calibration = new FakeSpanService(measurementCount: $total, sessionCount: 0, pageSize: $pageSize);
        memory_reset_peak_usage();
        $baseline = memory_get_usage();
        $onePage = $calibration->fetch(self::USER, $this->since(), $this->until());
        $this->assertCount($pageSize, $onePage->measurements());
        $pageCost = memory_get_peak_usage() - $baseline;
        unset($calibration, $onePage);

        $this->assertGreaterThan(0, $pageCost, 'A page has to cost something, or the bound means nothing.');

        $service = new FakeSpanService(measurementCount: $total, sessionCount: 0, pageSize: $pageSize);

        memory_reset_peak_usage();
        $before = memory_get_usage();

        $delivered = 0;
        $outOfOrder = null;
        $cursor = null;

        do {
            $page = $service->fetch(self::USER, $this->since(), $this->until(), $cursor);

            foreach ($page->measurements() as $measurement) {
                // Ids are deterministic and ordered, so position alone proves no
                // result was repeated, skipped, or reordered — without holding
                // ten thousand of them to compare at the end.
                if ($outOfOrder === null && $measurement->externalId !== "span-m-{$delivered}") {
                    $outOfOrder = "position {$delivered} delivered '{$measurement->externalId}'";
                }

                $delivered++;
            }

            $cursor = $page->nextCursor();
            unset($page);
        } while ($cursor !== null);

        $peakGrowth = memory_get_peak_usage() - $before;

        $this->assertNull($outOfOrder, "Paging re-delivered or skipped a result: {$outOfOrder}");
        $this->assertSame($total, $delivered, 'Every result in the range, exactly once.');

        // One page, with headroom for the loop's own bookkeeping. Retaining even
        // a fraction of the range would blow well past this.
        $this->assertLessThan(
            $pageCost * 3,
            $peakGrowth,
            sprintf(
                'Peak memory grew by %d bytes draining %d results at page size %d, against a one-page '
                . 'cost of %d bytes. Something is retaining pages, which fails at backfill scale rather '
                . 'than here.',
                $peakGrowth,
                $total,
                $pageSize,
                $pageCost,
            ),
        );
    }

    /** @test  a null cursor starts the range from the beginning */
    public function aNullCursorStartsTheRange(): void
    {
        $service = new FakeSpanService(measurementCount: 6, sessionCount: 0, pageSize: 3);

        $withNull = $service->fetch(self::USER, $this->since(), $this->until(), null);
        $withDefault = $service->fetch(self::USER, $this->since(), $this->until());

        $this->assertSame($this->identities($withDefault), $this->identities($withNull));
    }
}
