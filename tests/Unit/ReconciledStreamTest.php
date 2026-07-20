<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\External\TranslatedMeasurement;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;

/**
 * Reconciled series: per-device duplicates → one reading.
 * The upsert on (external_service, external_id) ensures idempotence.
 */
class ReconciledStreamTest extends TestCase
{
    /** @test T052 — duplicate external ID results in one measurement (upsert) */
    public function duplicateExternalIdResultsInOneMeasurement(): void
    {
        $userId = (string) \Illuminate\Support\Str::uuid();
        $user = \ClarionApp\Backend\Models\User::create([
            'id'     => $userId,
            'name'   => 'Test User',
            'email'  => 'test@example.com',
            'password' => 'hashed',
        ]);

        $externalId = 'hr:1753027200';
        $recordedAt = CarbonImmutable::parse('2025-07-20 10:00:00', 'UTC');

        // Insert first measurement
        $raw1 = \ClarionApp\LifeLogBackend\Models\RawMeasurement::create([
            'user_id'          => $userId,
            'external_service' => 'google-health',
            'external_id'      => $externalId,
            'type'             => 'HeartRate',
            'value'            => 72.0,
            'unit'             => 'bpm',
            'recorded_at'      => $recordedAt,
            'bucket_hour'      => $recordedAt->startOfHour(),
        ]);

        // Attempt to insert duplicate (should fail due to unique constraint)
        try {
            \ClarionApp\LifeLogBackend\Models\RawMeasurement::create([
                'user_id'          => $userId,
                'external_service' => 'google-health',
                'external_id'      => $externalId,
                'type'             => 'HeartRate',
                'value'            => 75.0,
                'unit'             => 'bpm',
                'recorded_at'      => $recordedAt,
                'bucket_hour'      => $recordedAt->startOfHour(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Expected — unique constraint violation
        }

        // Only one measurement should exist
        $count = \ClarionApp\LifeLogBackend\Models\RawMeasurement::where(
            'external_service', 'google-health'
        )->where('external_id', $externalId)->count();

        $this->assertSame(1, $count);
    }

    /** @test T052 — corrections replace in place (upsert semantics) */
    public function correctionsReplaceInPlace(): void
    {
        $userId = (string) \Illuminate\Support\Str::uuid();
        $user = \ClarionApp\Backend\Models\User::create([
            'id'     => $userId,
            'name'   => 'Test User',
            'email'  => 'test@example.com',
            'password' => 'hashed',
        ]);

        $externalId = 'wt:1753027200';
        $recordedAt = CarbonImmutable::parse('2025-07-20 10:00:00', 'UTC');

        // Insert initial measurement
        \ClarionApp\LifeLogBackend\Models\RawMeasurement::create([
            'user_id'          => $userId,
            'external_service' => 'google-health',
            'external_id'      => $externalId,
            'type'             => 'Weight',
            'value'            => 70.0,
            'unit'             => 'kg',
            'recorded_at'      => $recordedAt,
            'bucket_hour'      => $recordedAt->startOfHour(),
        ]);

        // Upsert with corrected value
        \ClarionApp\LifeLogBackend\Models\RawMeasurement::updateOrCreate(
            [
                'external_service' => 'google-health',
                'external_id'      => $externalId,
            ],
            [
                'user_id'          => $userId,
                'type'             => 'Weight',
                'value'            => 70.5,
                'unit'             => 'kg',
                'recorded_at'      => $recordedAt,
                'bucket_hour'      => $recordedAt->startOfHour(),
            ]
        );

        // Value should be updated
        $measurement = \ClarionApp\LifeLogBackend\Models\RawMeasurement::where(
            'external_service', 'google-health'
        )->where('external_id', $externalId)->first();

        $this->assertSame('70.5000', (string) $measurement->value);
    }

    /** @test T052 — different external IDs are distinct measurements */
    public function differentExternalIdsAreDistinct(): void
    {
        $userId = (string) \Illuminate\Support\Str::uuid();
        $user = \ClarionApp\Backend\Models\User::create([
            'id'     => $userId,
            'name'   => 'Test User',
            'email'  => 'test@example.com',
            'password' => 'hashed',
        ]);

        $recordedAt = CarbonImmutable::parse('2025-07-20 10:00:00', 'UTC');

        // Insert two measurements with different external IDs
        \ClarionApp\LifeLogBackend\Models\RawMeasurement::create([
            'user_id'          => $userId,
            'external_service' => 'google-health',
            'external_id'      => 'hr:1753027200',
            'type'             => 'HeartRate',
            'value'            => 72.0,
            'unit'             => 'bpm',
            'recorded_at'      => $recordedAt,
            'bucket_hour'      => $recordedAt->startOfHour(),
        ]);

        \ClarionApp\LifeLogBackend\Models\RawMeasurement::create([
            'user_id'          => $userId,
            'external_service' => 'google-health',
            'external_id'      => 'hr:1753030800',
            'type'             => 'HeartRate',
            'value'            => 75.0,
            'unit'             => 'bpm',
            'recorded_at'      => $recordedAt->addHour(),
            'bucket_hour'      => $recordedAt->addHour()->startOfHour(),
        ]);

        // Two measurements should exist
        $count = \ClarionApp\LifeLogBackend\Models\RawMeasurement::where(
            'external_service', 'google-health'
        )->where('user_id', $userId)->count();

        $this->assertSame(2, $count);
    }
}
