<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use ClarionApp\LifeLogBackend\Models\RawHealthSession;
use ClarionApp\LifeLogBackend\Services\RawSessionWriter;
use ClarionApp\LifeLogBackend\Services\SessionPromoter;
use ClarionApp\LifeLogBackend\External\TranslatedSession;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (external_service, external_id) identifies a session, so re-importing
 * one updates it in place.
 */
class RawSessionDeduplicationTest extends TestCase
{
    protected RawSessionWriter $writer;
    protected string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new RawSessionWriter();
        $this->userId = (string) Str::uuid();
    }

    protected function sleep(
        string $externalId,
        string $endedAt = '2026-07-02T06:40:00Z',
        array $summaryValues = ['duration' => '27000.0000'],
        string $service = 'fake-span',
    ): TranslatedSession {
        return TranslatedSession::make(
            userId: $this->userId,
            type: SessionType::Sleep,
            startedAt: CarbonImmutable::parse('2026-07-01T23:10:00Z'),
            endedAt: CarbonImmutable::parse($endedAt),
            summaryValues: $summaryValues,
            externalId: $externalId,
            externalService: $service,
        );
    }

    public function testReImportUpdatesInPlaceRatherThanDuplicating(): void
    {
        $this->writer->write([$this->sleep('sleep-1')]);
        $this->writer->write([
            $this->sleep('sleep-1', '2026-07-02T07:10:00Z', ['duration' => '28800.0000']),
        ]);

        $this->assertSame(1, RawHealthSession::count());

        $row = RawHealthSession::first();
        $this->assertSame('2026-07-02 07:10:00', $row->ended_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(['duration' => '28800.0000'], $row->summary_values);
    }

    /** The same external_id under a different service is a different session. */
    public function testSameExternalIdUnderDifferentServiceIsDistinct(): void
    {
        $this->writer->write([
            $this->sleep('shared-id', service: 'fake-span'),
            $this->sleep('shared-id', service: 'fake-step'),
        ]);

        $this->assertSame(2, RawHealthSession::count());
    }

    /**
     * The trap this test exists for: upsert() bypasses model events, so
     * promoted_at only resets if it is named explicitly in the update columns.
     * Omit it and a revised session stays flagged as promoted forever, and the
     * revision never reaches permanent history.
     */
    public function testReImportResetsPromotedAtToNull(): void
    {
        $this->writer->write([$this->sleep('sleep-1')]);

        RawHealthSession::query()->update(['promoted_at' => CarbonImmutable::now()]);
        $this->assertNotNull(RawHealthSession::first()->promoted_at);

        $this->writer->write([
            $this->sleep('sleep-1', '2026-07-02T07:10:00Z', ['duration' => '28800.0000']),
        ]);

        $this->assertNull(
            RawHealthSession::first()->promoted_at,
            'A re-imported session must be re-examined by promotion.',
        );
    }

    /** The reset is what makes a revision actually reach permanent history. */
    public function testRevisionAfterPromotionIsPromotedAgain(): void
    {
        $promoter = new SessionPromoter();

        $this->writer->write([$this->sleep('sleep-1')]);
        $promoter->run();

        $this->writer->write([
            $this->sleep('sleep-1', '2026-07-02T07:10:00Z', ['duration' => '28800.0000']),
        ]);
        $result = $promoter->run();

        $this->assertSame(1, $result['promoted']);
        $this->assertSame(
            '2026-07-02 07:10:00',
            \ClarionApp\LifeLogBackend\Models\HealthSession::first()->ended_at->utc()->format('Y-m-d H:i:s'),
        );
    }
}
