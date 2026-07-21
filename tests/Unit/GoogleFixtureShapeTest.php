<?php

namespace Tests\Unit;

use ClarionApp\LifeLogBackend\Google\Api\ApiVersion;
use ClarionApp\LifeLogBackend\Google\Mapping\MeasurementTranslator;
use ClarionApp\LifeLogBackend\Google\Mapping\SessionTranslator;
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Tests\TestCase;

/**
 * The captured-shape fixtures are only worth keeping if something reads them.
 *
 * contracts/google-api.md rests two guarantees on this directory: that every
 * fixture is stamped with the API version it was captured against, and that a
 * pin bump leaving stale fixtures becomes visible. Neither holds while the
 * files sit unopened — a fixture whose shape has drifted away from what the
 * translators parse is indistinguishable from one that never worked, and the
 * stamp is a comment nothing checks.
 */
class GoogleFixtureShapeTest extends TestCase
{
    private const DIR = __DIR__ . '/../Support/Fixtures/google';

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $path = self::DIR . '/' . $name;
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function points(array $body): array
    {
        return $body['dataset'][0]['point'] ?? [];
    }

    /** @test  every fixture carries the pinned version and a capture date */
    public function everyFixtureIsStampedWithThePinnedVersion(): void
    {
        $files = glob(self::DIR . '/*.json');
        $this->assertNotEmpty($files, 'No fixtures found — the stamping rule has nothing to protect.');

        foreach ($files as $path) {
            $body = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $name = basename($path);

            $this->assertSame(
                ApiVersion::VERSION,
                $body['_api_version'] ?? null,
                "{$name} was captured against a different API version than the one pinned. "
                . 'A pin bump requires re-capturing the fixtures it invalidates.',
            );
            $this->assertNotEmpty($body['_capture_date'] ?? null, "{$name} has no capture date.");
        }
    }

    /** @test  the heart-rate page translates into vocabulary measurements */
    public function heartRateFixtureTranslates(): void
    {
        $body = $this->fixture('read-measurements-heart-rate-first-page.json');

        $measurements = (new MeasurementTranslator())->translate(
            $this->points($body),
            'fixture-user',
            app(UnmappedTypeRecorder::class),
        );

        $this->assertCount(2, $measurements);
        $this->assertSame(MeasurementType::HeartRate, $measurements[0]->type);
        $this->assertSame('bpm', $measurements[0]->unit);
        $this->assertSame('72.5000', $measurements[0]->value);

        // The external id is a pure function of (type, instant) — this is what
        // makes a corrected reading replace the row it corrects.
        $this->assertSame('hr:1753017600', $measurements[0]->externalId);
        $this->assertNotNull($body['nextPageToken']);
    }

    /** @test  the steps page translates, including the provider's unit spelling */
    public function stepsFixtureTranslates(): void
    {
        $body = $this->fixture('read-measurements-steps-first-page.json');

        $measurements = (new MeasurementTranslator())->translate(
            $this->points($body),
            'fixture-user',
            app(UnmappedTypeRecorder::class),
        );

        $this->assertCount(2, $measurements);
        $this->assertSame(MeasurementType::Steps, $measurements[0]->type);
        $this->assertSame('count', $measurements[0]->unit);
        $this->assertSame('120.0000', $measurements[0]->value);
    }

    /** @test  an empty page still carries a continuation token — a sparse span, not the end */
    public function emptyPageFixtureIsASparseSpan(): void
    {
        $body = $this->fixture('read-measurements-empty-page.json');

        $measurements = (new MeasurementTranslator())->translate(
            $this->points($body),
            'fixture-user',
            app(UnmappedTypeRecorder::class),
        );

        $this->assertSame([], $measurements);
        $this->assertNotNull(
            $body['nextPageToken'],
            'An empty page with no token would end the range; the fixture exists to model the other case.',
        );
    }

    /** @test  the reconciled series yields one reading per instant (FR-009) */
    public function reconciledFixtureYieldsOneReadingPerInstant(): void
    {
        $body = $this->fixture('read-measurements-reconciled-dedup.json');

        $measurements = (new MeasurementTranslator())->translate(
            $this->points($body),
            'fixture-user',
            app(UnmappedTypeRecorder::class),
        );

        $ids = array_map(fn ($m) => $m->externalId, $measurements);

        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    /** @test  session fixtures translate and keep the provider-supplied id */
    public function sessionFixturesTranslate(): void
    {
        $recorder = app(UnmappedTypeRecorder::class);
        $translator = new SessionTranslator();

        $sleep = $translator->translate(
            $this->fixture('read-sessions-sleep-single.json')['sleepSessions'],
            'fixture-user',
            $recorder,
        );

        $this->assertCount(1, $sleep);
        $this->assertSame(SessionType::Sleep, $sleep[0]->type);
        $this->assertSame('sleep-1753003200', $sleep[0]->externalId);

        $workout = $translator->translate(
            $this->fixture('read-sessions-workout-single.json')['exerciseSessions'],
            'fixture-user',
            $recorder,
        );

        $this->assertCount(1, $workout);
        $this->assertSame(SessionType::Workout, $workout[0]->type);
        $this->assertSame('workout-1753041600', $workout[0]->externalId);
    }
}
