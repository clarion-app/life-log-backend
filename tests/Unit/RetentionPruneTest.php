<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\MeasurementRollupQueue;
use ClarionApp\LifeLogBackend\Commands\PruneRawMeasurementsCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Carbon\CarbonImmutable;

class RetentionPruneTest extends TestCase
{
    /**
     * Helper: seed raw readings with specific bucket_hour values.
     */
    protected function seedRawReadings(array $readings): void
    {
        foreach ($readings as $r) {
            RawMeasurement::create([
                'user_id' => $r['user_id'],
                'external_service' => $r['external_service'],
                'external_id' => $r['external_id'] ?? ('auto-' . uniqid()),
                'type' => $r['type'],
                'value' => $r['value'],
                'unit' => $r['unit'] ?? '',
                'recorded_at' => $r['bucket_hour'],
                'bucket_hour' => $r['bucket_hour'],
            ]);
        }
    }

    /**
     * T070: Raw rows older than the retention window are deleted.
     */
    public function testOldRawRowsAreDeleted(): void
    {
        Config::set('life-log.raw_retention_days', 30);

        $userId = (string) \Illuminate\Support\Str::uuid();

        // Seed one old reading (60 days ago) and one recent reading (1 day ago)
        $oldHour = CarbonImmutable::now()->subDays(60);
        $recentHour = CarbonImmutable::now()->subDays(1);

        $this->seedRawReadings([
            ['user_id' => $userId, 'external_service' => 'fitbit', 'type' => 'steps', 'value' => 500, 'bucket_hour' => $oldHour],
            ['user_id' => $userId, 'external_service' => 'fitbit', 'type' => 'steps', 'value' => 1000, 'bucket_hour' => $recentHour],
        ]);

        $this->assertDatabaseCount('life_log_raw_measurements', 2);

        $this->artisan('life-log:prune-raw-measurements')->assertExitCode(0);

        $this->assertDatabaseCount('life_log_raw_measurements', 1);
        $this->assertDatabaseHas('life_log_raw_measurements', [
            'id' => RawMeasurement::where('bucket_hour', $recentHour)->value('id'),
        ]);
    }

    /**
     * T070: Rows inside the retention window survive pruning.
     */
    public function testRecentRawRowsSurvive(): void
    {
        Config::set('life-log.raw_retention_days', 30);

        $userId = (string) \Illuminate\Support\Str::uuid();

        // All readings within the last 30 days
        $this->seedRawReadings([
            ['user_id' => $userId, 'external_service' => 'fitbit', 'type' => 'steps', 'value' => 500, 'bucket_hour' => CarbonImmutable::now()->subDays(1)],
            ['user_id' => $userId, 'external_service' => 'fitbit', 'type' => 'steps', 'value' => 600, 'bucket_hour' => CarbonImmutable::now()->subDays(10)],
            ['user_id' => $userId, 'external_service' => 'fitbit', 'type' => 'steps', 'value' => 700, 'bucket_hour' => CarbonImmutable::now()->subDays(29)],
        ]);

        $this->assertDatabaseCount('life_log_raw_measurements', 3);

        $this->artisan('life-log:prune-raw-measurements')->assertExitCode(0);

        $this->assertDatabaseCount('life_log_raw_measurements', 3);
    }

    /**
     * T070: A row whose bucket still has a queue entry is never pruned.
     */
    public function testQueuedBucketsAreNeverPruned(): void
    {
        Config::set('life-log.raw_retention_days', 30);

        $userId = (string) \Illuminate\Support\Str::uuid();
        $oldHour = CarbonImmutable::now()->subDays(60);

        $this->seedRawReadings([
            ['user_id' => $userId, 'external_service' => 'fitbit', 'type' => 'steps', 'value' => 500, 'bucket_hour' => $oldHour],
        ]);

        // Mark the bucket as deferred (or pending) — should protect it
        MeasurementRollupQueue::updateOrCreate(
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'unit' => '',
                'bucket_hour' => $oldHour,
            ],
            ['deferred_reason' => 'unclassified_type'],
        );

        $this->assertDatabaseCount('life_log_raw_measurements', 1);

        $this->artisan('life-log:prune-raw-measurements')->assertExitCode(0);

        $this->assertDatabaseCount('life_log_raw_measurements', 1);
    }

    /**
     * T070: raw_retention_days = null disables pruning entirely.
     */
    public function testNullRetentionDisablesPruning(): void
    {
        Config::set('life-log.raw_retention_days', null);

        $userId = (string) \Illuminate\Support\Str::uuid();

        $this->seedRawReadings([
            ['user_id' => $userId, 'external_service' => 'fitbit', 'type' => 'steps', 'value' => 500, 'bucket_hour' => CarbonImmutable::now()->subDays(60)],
        ]);

        $this->assertDatabaseCount('life_log_raw_measurements', 1);

        $this->artisan('life-log:prune-raw-measurements')->assertExitCode(0);

        $this->assertDatabaseCount('life_log_raw_measurements', 1);
    }

    /**
     * T070: Pruning records zero publishes under enableRecordingBridge().
     */
    public function testPruningRecordsZeroPublishes(): void
    {
        $spy = $this->enableRecordingBridge();
        Config::set('life-log.raw_retention_days', 30);

        $userId = (string) \Illuminate\Support\Str::uuid();

        $this->seedRawReadings([
            ['user_id' => $userId, 'external_service' => 'fitbit', 'type' => 'steps', 'value' => 500, 'bucket_hour' => CarbonImmutable::now()->subDays(60)],
        ]);

        $this->artisan('life-log:prune-raw-measurements')->assertExitCode(0);

        // Raw measurements are non-bridged — prune should not publish anything
        $this->assertCount(0, $spy->published);
    }
}
