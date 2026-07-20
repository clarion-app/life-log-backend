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
use Tests\Support\RecordingMultiChain;
use Tests\TestCase;

/**
 * Promotion publishes to the chain only when the permanent
 * record actually changed. Re-promoting identical data must be silent.
 */
class SessionBridgeWriteMinimizationTest extends TestCase
{
    protected RecordingMultiChain $spy;
    protected RawSessionWriter $writer;
    protected SessionPromoter $promoter;
    protected string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = $this->enableRecordingBridge();
        $this->writer = new RawSessionWriter();
        $this->promoter = new SessionPromoter();
        $this->userId = (string) Str::uuid();
    }

    protected function sleep(string $externalId, string $endedAt, array $summaryValues): TranslatedSession
    {
        return TranslatedSession::make(
            userId: $this->userId,
            type: SessionType::Sleep,
            startedAt: CarbonImmutable::parse('2026-07-01T23:10:00Z'),
            endedAt: CarbonImmutable::parse($endedAt),
            summaryValues: $summaryValues,
            externalId: $externalId,
            externalService: 'fake-span',
        );
    }

    /** First promotion publishes once; re-promoting unchanged data publishes zero. */
    public function testFirstPromotionPublishesOnceAndUnchangedRePromotionPublishesZero(): void
    {
        $session = $this->sleep('sleep-1', '2026-07-02T06:40:00Z', ['duration' => '27000.0000']);

        $this->writer->write([$session]);
        $this->promoter->run();

        $this->assertCount(1, $this->spy->published);
        $this->assertSame('life_log_health_sessions', $this->spy->published[0]['stream']);

        // The service reports the exact same session again. The raw row is
        // rewritten and re-queued for promotion, but nothing downstream changed.
        $this->writer->write([$session]);
        $this->assertSame(1, RawHealthSession::whereNull('promoted_at')->count());

        $this->promoter->run();

        $this->assertCount(1, $this->spy->published, 'Unchanged re-promotion must not publish.');
        $this->assertSame(1, HealthSession::count());
    }

    /** updated_at is untouched when a re-promotion changes nothing. */
    public function testUnchangedRePromotionLeavesUpdatedAtUntouched(): void
    {
        $session = $this->sleep('sleep-1', '2026-07-02T06:40:00Z', ['duration' => '27000.0000']);

        $this->writer->write([$session]);
        $this->promoter->run();

        $before = HealthSession::first()->updated_at;

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        $this->writer->write([$session]);
        $this->promoter->run();
        CarbonImmutable::setTestNow();

        $this->assertEquals($before, HealthSession::first()->updated_at);
    }

    /** A genuine revision does publish — minimization must not suppress real change. */
    public function testGenuineRevisionPublishesAgain(): void
    {
        $this->writer->write([$this->sleep('sleep-1', '2026-07-02T06:40:00Z', ['duration' => '27000.0000'])]);
        $this->promoter->run();
        $this->assertCount(1, $this->spy->published);

        $this->writer->write([$this->sleep('sleep-1', '2026-07-02T07:10:00Z', ['duration' => '28800.0000'])]);
        $this->promoter->run();

        $this->assertCount(2, $this->spy->published);
        $this->assertSame(1, HealthSession::count());
    }
}
