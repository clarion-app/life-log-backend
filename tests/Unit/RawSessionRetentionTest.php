<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use ClarionApp\LifeLogBackend\Models\RawHealthSession;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Raw sessions are a staging area, pruned on a retention window, but an
 * unpromoted session is never deleted — losing it would lose the only copy.
 */
class RawSessionRetentionTest extends TestCase
{
    protected string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (string) Str::uuid();
    }

    protected function seedSession(string $externalId, CarbonImmutable $endedAt, ?CarbonImmutable $promotedAt): void
    {
        RawHealthSession::create([
            'user_id' => $this->userId,
            'external_service' => 'fake-span',
            'external_id' => $externalId,
            'session_type' => 'sleep',
            'started_at' => $endedAt->subHours(8),
            'ended_at' => $endedAt,
            'summary_values' => ['duration' => '28800.0000'],
            'promoted_at' => $promotedAt,
        ]);
    }

    public function testPromotedSessionsPastRetentionAreDeleted(): void
    {
        Config::set('life-log.raw_session_retention_days', 30);

        $now = CarbonImmutable::now();
        $this->seedSession('old', $now->subDays(60), $now->subDays(60));
        $this->seedSession('recent', $now->subDays(1), $now->subDays(1));

        $this->artisan('life-log:prune-raw-sessions')->assertExitCode(0);

        $this->assertSame(1, RawHealthSession::count());
        $this->assertSame('recent', RawHealthSession::first()->external_id);
    }

    public function testUnpromotedSessionIsNeverDeletedRegardlessOfAge(): void
    {
        Config::set('life-log.raw_session_retention_days', 30);

        $now = CarbonImmutable::now();
        $this->seedSession('ancient-unpromoted', $now->subDays(400), null);
        $this->seedSession('ancient-promoted', $now->subDays(400), $now->subDays(400));

        $this->artisan('life-log:prune-raw-sessions')->assertExitCode(0);

        $this->assertSame(1, RawHealthSession::count());
        $this->assertSame('ancient-unpromoted', RawHealthSession::first()->external_id);
    }

    public function testNullRetentionDisablesPruningEntirely(): void
    {
        Config::set('life-log.raw_session_retention_days', null);

        $now = CarbonImmutable::now();
        $this->seedSession('ancient', $now->subDays(4000), $now->subDays(4000));

        $this->artisan('life-log:prune-raw-sessions')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        $this->assertSame(1, RawHealthSession::count());
    }

    /** Deletion is chunked, so a large backlog never loads whole into memory. */
    public function testDeletionIsBoundedInChunks(): void
    {
        Config::set('life-log.raw_session_retention_days', 30);

        $now = CarbonImmutable::now();
        $rows = [];

        for ($i = 0; $i < 60; $i++) {
            $endedAt = $now->subDays(100)->addMinutes($i);
            $rows[] = [
                'user_id' => $this->userId,
                'external_service' => 'fake-span',
                'external_id' => "bulk-{$i}",
                'session_type' => 'sleep',
                'started_at' => $endedAt->subHours(8),
                'ended_at' => $endedAt,
                'summary_values' => json_encode(['duration' => '28800.0000']),
                'promoted_at' => $now->subDays(100),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        RawHealthSession::insert($rows);
        $this->assertSame(60, RawHealthSession::count());

        // A chunk size well below the backlog forces the loop to iterate.
        $command = new \ClarionApp\LifeLogBackend\Commands\PruneRawSessionsCommand();
        $command->setChunkSize(10);
        $command->setLaravel($this->app);

        $exitCode = $command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\BufferedOutput(),
        );

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, RawHealthSession::count());
    }
}
