<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\RawHealthSession;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FR-024b: Shipped retention defaults are null.
 *
 * A 90-day default silently deletes a five-year backfill ninety days after
 * it lands. The defaults are null so raw data is kept forever unless an
 * operator explicitly sets a retention window via env var.
 */
class RetentionDefaultTest extends TestCase
{
    protected string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (string) Str::uuid();
    }

    /**
     * @test The shipped default for raw_retention_days is null.
     *
     * This asserts the config file ships with null, not 90.
     */
    public function testRawRetentionDaysDefaultIsNull(): void
    {
        $default = config('life-log.raw_retention_days');
        $this->assertNull(
            $default,
            'raw_retention_days must default to null. '
            . 'A 90-day default silently deletes a five-year backfill ninety days after it lands.',
        );
    }

    /**
     * @test The shipped default for raw_session_retention_days is null.
     */
    public function testRawSessionRetentionDaysDefaultIsNull(): void
    {
        $default = config('life-log.raw_session_retention_days');
        $this->assertNull(
            $default,
            'raw_session_retention_days must default to null.',
        );
    }

    /**
     * @test life-log:prune-raw-measurements no-ops when retention is null.
     *
     * The command exits successfully without deleting anything.
     */
    public function testPruneRawMeasurementsNoOpsWhenRetentionIsNull(): void
    {
        Config::set('life-log.raw_retention_days', null);

        $now = CarbonImmutable::now();
        RawMeasurement::create([
            'user_id' => $this->userId,
            'external_service' => 'fitbit',
            'external_id' => 'ancient-steps',
            'type' => 'steps',
            'value' => 100.0,
            'unit' => 'count',
            'recorded_at' => $now->subDays(400),
            'bucket_hour' => $now->subDays(400),
        ]);

        $this->artisan('life-log:prune-raw-measurements')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        // Row is still there
        $this->assertSame(1, RawMeasurement::count());
    }

    /**
     * @test life-log:prune-raw-sessions no-ops when retention is null.
     */
    public function testPruneRawSessionsNoOpsWhenRetentionIsNull(): void
    {
        Config::set('life-log.raw_session_retention_days', null);

        $now = CarbonImmutable::now();
        RawHealthSession::create([
            'user_id' => $this->userId,
            'external_service' => 'fitbit',
            'external_id' => 'ancient-sleep',
            'session_type' => 'sleep',
            'started_at' => $now->subDays(400),
            'ended_at' => $now->subDays(399),
            'summary_values' => ['duration' => '28800.0000'],
            'promoted_at' => $now->subDays(399),
        ]);

        $this->artisan('life-log:prune-raw-sessions')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        // Row is still there
        $this->assertSame(1, RawHealthSession::count());
    }

    /**
     * @test Setting retention via env var still works.
     *
     * The command and schedule entry stay — an operator who wants pruning
     * sets the env var.
     */
    public function testSettingRetentionViaEnvVarStillWorks(): void
    {
        Config::set('life-log.raw_retention_days', 30);

        $now = CarbonImmutable::now();
        RawMeasurement::create([
            'user_id' => $this->userId,
            'external_service' => 'fitbit',
            'external_id' => 'old-steps',
            'type' => 'steps',
            'value' => 100.0,
            'unit' => 'count',
            'recorded_at' => $now->subDays(60),
            'bucket_hour' => $now->subDays(60),
        ]);

        $this->artisan('life-log:prune-raw-measurements')->assertExitCode(0);

        // Row is deleted (past 30-day retention)
        $this->assertSame(0, RawMeasurement::count());
    }
}
