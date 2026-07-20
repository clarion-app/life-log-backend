<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use ClarionApp\LifeLogBackend\Models\HealthSession;
use ClarionApp\LifeLogBackend\Models\RawHealthSession;
use ClarionApp\LifeLogBackend\Services\RawSessionWriter;
use ClarionApp\LifeLogBackend\Services\SessionPromoter;
use ClarionApp\LifeLogBackend\External\TranslatedSession;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionPromotionTest extends TestCase
{
    protected RawSessionWriter $writer;
    protected SessionPromoter $promoter;
    protected string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new RawSessionWriter();
        $this->promoter = new SessionPromoter();
        $this->userId = (string) Str::uuid();
    }

    protected function sleep(
        string $externalId,
        string $startedAt,
        string $endedAt,
        array $summaryValues = ['duration' => '27000.0000'],
        string $service = 'fake-span',
    ): TranslatedSession {
        return TranslatedSession::make(
            userId: $this->userId,
            type: SessionType::Sleep,
            startedAt: CarbonImmutable::parse($startedAt),
            endedAt: CarbonImmutable::parse($endedAt),
            summaryValues: $summaryValues,
            externalId: $externalId,
            externalService: $service,
        );
    }

    /** One raw session promotes to exactly one bridged record. */
    public function testOneBridgedRecordPerRawSession(): void
    {
        $this->writer->write([
            $this->sleep('sleep-1', '2026-07-01T23:10:00Z', '2026-07-02T06:40:00Z'),
            $this->sleep('sleep-2', '2026-07-02T23:00:00Z', '2026-07-03T07:00:00Z'),
        ]);

        $result = $this->promoter->run();

        $this->assertSame(2, $result['promoted']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(2, HealthSession::count());
        $this->assertSame(0, RawHealthSession::whereNull('promoted_at')->count());
    }

    /**
     * A session crossing midnight stays one row and is never bucketed
     * or split at the day boundary.
     */
    public function testMidnightCrossingSessionStaysOneRow(): void
    {
        $this->writer->write([
            $this->sleep('sleep-midnight', '2026-07-01T23:10:00Z', '2026-07-02T06:40:00Z'),
        ]);

        $this->promoter->run();

        $this->assertSame(1, HealthSession::count());

        $session = HealthSession::first();
        $this->assertSame(
            '2026-07-01 23:10:00',
            $session->started_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-02 06:40:00',
            $session->ended_at->utc()->format('Y-m-d H:i:s'),
        );

        // A session is not a measurement — there is no hour bucket anywhere on it.
        $this->assertNotContains('bucket_hour', array_keys($session->getAttributes()));
    }

    /** A revised session updates the existing permanent record rather than adding one. */
    public function testRevisedSessionUpdatesInPlace(): void
    {
        $this->writer->write([
            $this->sleep('sleep-1', '2026-07-01T23:10:00Z', '2026-07-02T06:40:00Z', ['duration' => '27000.0000']),
        ]);
        $this->promoter->run();

        $original = HealthSession::first();

        // The service revises the same session: it actually ended later.
        $this->writer->write([
            $this->sleep('sleep-1', '2026-07-01T23:10:00Z', '2026-07-02T07:10:00Z', ['duration' => '28800.0000']),
        ]);
        $result = $this->promoter->run();

        $this->assertSame(1, $result['promoted']);
        $this->assertSame(1, HealthSession::count());

        $revised = HealthSession::first();
        $this->assertSame($original->id, $revised->id);
        $this->assertSame(
            '2026-07-02 07:10:00',
            $revised->ended_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(['duration' => '28800.0000'], $revised->summary_values);
    }

    /** An end at or before the start is skipped with a reason, never stored. */
    public function testBackwardsSpanIsSkippedWithRecordedReason(): void
    {
        $written = $this->writer->write([
            [
                'user_id' => $this->userId,
                'external_service' => 'fake-span',
                'external_id' => 'backwards',
                'session_type' => SessionType::Sleep->value,
                'started_at' => CarbonImmutable::parse('2026-07-02T06:40:00Z'),
                'ended_at' => CarbonImmutable::parse('2026-07-01T23:10:00Z'),
                'summary_values' => json_encode(['duration' => '27000.0000']),
            ],
            [
                'user_id' => $this->userId,
                'external_service' => 'fake-span',
                'external_id' => 'zero-length',
                'session_type' => SessionType::Sleep->value,
                'started_at' => CarbonImmutable::parse('2026-07-01T23:10:00Z'),
                'ended_at' => CarbonImmutable::parse('2026-07-01T23:10:00Z'),
                'summary_values' => json_encode(['duration' => '0.0000']),
            ],
        ]);

        $this->assertSame(0, $written);
        $this->assertSame(0, RawHealthSession::count());

        $skipped = $this->writer->skipped();
        $this->assertCount(2, $skipped);
        $this->assertSame(['backwards', 'zero-length'], array_column($skipped, 'external_id'));

        foreach ($skipped as $entry) {
            $this->assertNotSame('', $entry['reason']);
        }
    }

    /** A missing end is skipped, never stored with a guessed end. */
    public function testNullEndIsSkippedAndNeverGuessed(): void
    {
        $written = $this->writer->write([
            [
                'user_id' => $this->userId,
                'external_service' => 'fake-span',
                'external_id' => 'open-ended',
                'session_type' => SessionType::Sleep->value,
                'started_at' => CarbonImmutable::parse('2026-07-01T23:10:00Z'),
                'ended_at' => null,
                'summary_values' => json_encode([]),
            ],
        ]);

        $this->assertSame(0, $written);
        $this->assertSame(0, RawHealthSession::count());

        $skipped = $this->writer->skipped();
        $this->assertCount(1, $skipped);
        $this->assertSame('open-ended', $skipped[0]['external_id']);
        $this->assertStringContainsString('end', strtolower($skipped[0]['reason']));
    }

    /**
     * The promoted source is the originating service, never the
     * 'manual' default that would mislabel an import as hand-entered.
     */
    public function testPromotedSourceIsTheExternalService(): void
    {
        $this->writer->write([
            $this->sleep('sleep-1', '2026-07-01T23:10:00Z', '2026-07-02T06:40:00Z', service: 'fake-span'),
            $this->sleep('sleep-2', '2026-07-02T22:00:00Z', '2026-07-03T06:00:00Z', service: 'fake-step'),
        ]);

        $this->promoter->run();

        $this->assertSame('fake-span', HealthSession::where('external_id', 'sleep-1')->value('source'));
        $this->assertSame('fake-step', HealthSession::where('external_id', 'sleep-2')->value('source'));
        $this->assertSame(0, HealthSession::where('source', 'manual')->count());
    }

    /** Promotion carries the whole vocabulary payload across. */
    public function testPromotedRecordCarriesTypeUserAndSummaryValues(): void
    {
        $this->writer->write([
            $this->sleep('sleep-1', '2026-07-01T23:10:00Z', '2026-07-02T06:40:00Z', ['duration' => '27000.0000']),
        ]);

        $this->promoter->run();

        $session = HealthSession::first();
        $this->assertSame($this->userId, $session->user_id);
        $this->assertSame(SessionType::Sleep->value, $session->session_type);
        $this->assertSame('fake-span', $session->external_service);
        $this->assertSame('sleep-1', $session->external_id);
        $this->assertSame(['duration' => '27000.0000'], $session->summary_values);
        $this->assertTrue(Str::isUuid($session->id));
    }

    /** A second run with nothing newly unpromoted promotes nothing. */
    public function testSecondRunPromotesNothing(): void
    {
        $this->writer->write([
            $this->sleep('sleep-1', '2026-07-01T23:10:00Z', '2026-07-02T06:40:00Z'),
        ]);

        $this->assertSame(1, $this->promoter->run()['promoted']);
        $this->assertSame(0, $this->promoter->run()['promoted']);
        $this->assertSame(1, HealthSession::count());
    }
}
