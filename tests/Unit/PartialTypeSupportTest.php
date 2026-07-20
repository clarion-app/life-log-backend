<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\FakeSpanService;
use Tests\Support\FakeStepService;
use ClarionApp\LifeLogBackend\Contracts\ExternalHealthService;
use ClarionApp\LifeLogBackend\External\ResultPage;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use ClarionApp\LifeLogBackend\Vocabulary\Vocabulary;
use Carbon\CarbonImmutable;

/**
 * FR-033 — partial coverage is the normal case, not an error.
 *
 * No real service supplies the whole vocabulary: a scale reports weight and
 * nothing else, a watch reports steps but never sleep. If asking for a range
 * that implies an unsupported type failed the whole request, a caller would have
 * to know each service's coverage before calling it, which is exactly the
 * service-specific knowledge this contract exists to remove. The service
 * answers with what it has.
 */
class PartialTypeSupportTest extends TestCase
{
    private const USER = 'ffffffff-0000-4000-8000-00000000abcd';

    /** @test  neither fake covers the whole vocabulary — that is the point */
    public function eachServiceCoversOnlyPartOfTheVocabulary(): void
    {
        $whole = count(Vocabulary::measurementTypes()) + count(Vocabulary::sessionTypes());

        foreach ($this->services() as $service) {
            $this->assertLessThan(
                $whole,
                count($service->supportedTypes()),
                'A fake covering everything would never exercise the partial-support path.',
            );
        }
    }

    /** @test  the two fakes deliberately disagree about what they cover */
    public function theTwoServicesSupportDifferentTypeSets(): void
    {
        $step = $this->supportedValues(new FakeStepService());
        $span = $this->supportedValues(new FakeSpanService());

        $this->assertNotSame($step, $span);
        $this->assertNotEmpty(array_diff($step, $span));
        $this->assertNotEmpty(array_diff($span, $step));
    }

    /** @test  US2 scenario 9 — an unsupported type in range does not fail the request */
    public function aRangeImplyingAnUnsupportedTypeStillReturnsWhatIsSupported(): void
    {
        foreach ($this->services() as $service) {
            $supported = $this->supportedValues($service);

            // Heart rate is in the vocabulary and neither fake supplies it. The
            // range below covers it in principle; the fetch must succeed anyway.
            $this->assertNotContains(MeasurementType::HeartRate->value, $supported);

            $page = $this->fetch($service);

            $this->assertInstanceOf(ResultPage::class, $page);
            $this->assertNotEmpty(
                array_merge($page->measurements(), $page->sessions()),
                'The service must still hand back the types it does supply.',
            );
        }
    }

    /** @test  and nothing outside the advertised set is ever emitted */
    public function nothingOutsideTheAdvertisedSetIsEverEmitted(): void
    {
        foreach ($this->services() as $service) {
            $supported = $this->supportedValues($service);
            $cursor = null;

            do {
                $page = $this->fetch($service, $cursor);

                foreach ($page->measurements() as $measurement) {
                    $this->assertContains(
                        $measurement->type->value,
                        $supported,
                        "{$service->name()} emitted '{$measurement->type->value}' without advertising it.",
                    );
                }

                foreach ($page->sessions() as $session) {
                    $this->assertContains($session->type->value, $supported);
                }

                $cursor = $page->nextCursor();
            } while ($cursor !== null);
        }
    }

    /** @test  a measurements-only service is legal — no sessions is not a failure */
    public function aMeasurementsOnlyServiceReturnsNoSessionsWithoutFailing(): void
    {
        $service = new FakeStepService();

        $this->assertSame(
            [],
            array_filter($service->supportedTypes(), fn ($t) => $t instanceof SessionType),
        );

        $page = $this->fetch($service);

        $this->assertSame([], $page->sessions());
        $this->assertNotEmpty($page->measurements());
    }

    /** @test  a service with nothing at all to report answers with an empty page */
    public function aServiceWithNothingToReportReturnsAnEmptyPage(): void
    {
        $page = $this->fetch(new FakeStepService(rows: []));

        $this->assertSame([], $page->measurements());
        $this->assertSame([], $page->sessions());
        $this->assertNull($page->nextCursor());
    }

    private function fetch(ExternalHealthService $service, $cursor = null): ResultPage
    {
        return $service->fetch(
            self::USER,
            CarbonImmutable::parse('2026-01-01T00:00:00Z'),
            CarbonImmutable::parse('2026-01-02T00:00:00Z'),
            $cursor,
        );
    }

    /** @return list<string> */
    private function supportedValues(ExternalHealthService $service): array
    {
        return array_map(fn ($t) => $t->value, $service->supportedTypes());
    }

    /** @return list<ExternalHealthService> */
    private function services(): array
    {
        return [new FakeStepService(), new FakeSpanService()];
    }
}
