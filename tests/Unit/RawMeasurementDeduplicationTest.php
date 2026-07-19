<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use Carbon\CarbonImmutable;

class RawMeasurementDeduplicationTest extends TestCase
{
    protected RawMeasurementWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = app(RawMeasurementWriter::class);
    }

    /** @test FR-004/FR-015 scenario 2.1: same service+identity re-record updates in place */
    public function sameServiceIdentityReRecordUpdatesInPlace(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 'step-001',
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:30:00 UTC'),
            ],
        ];

        $this->writer->write($readings);
        $this->assertEquals(1, RawMeasurement::count());

        // Re-record same identity with different value
        $readings[0]['value'] = 200.0;
        $this->writer->write($readings);

        $this->assertEquals(1, RawMeasurement::count(), 'Count should not increase on re-record');
        $this->assertEquals('200.0000', RawMeasurement::first()->value);
    }

    /** @test scenario 2.2: re-record with changed value/unit/recorded_at reflects newest values */
    public function reRecordWithChangedValuesReflectsNewestValues(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 'hr-001',
                'type' => 'heart_rate',
                'value' => 72.0,
                'unit' => 'bpm',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:30:00 UTC'),
            ],
        ];

        $this->writer->write($readings);

        // Re-record with changed value, unit, and recorded_at (crossing hour boundary)
        $readings[0]['value'] = 80.0;
        $readings[0]['unit'] = 'beats_per_min';
        $readings[0]['recorded_at'] = CarbonImmutable::parse('2026-07-19 10:15:00 UTC');
        $this->writer->write($readings);

        $record = RawMeasurement::first();
        $this->assertEquals('80.0000', $record->value);
        $this->assertEquals('beats_per_min', $record->unit);
        $this->assertEquals('2026-07-19 10:00:00', $record->bucket_hour->format('Y-m-d H:i:s'));
    }

    /** @test scenario 2.3: identity 12345 from two different services stores two rows */
    public function sameIdentityFromDifferentServicesStoresTwoRows(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => '12345',
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:30:00 UTC'),
            ],
            [
                'user_id' => $userId,
                'external_service' => 'garmin',
                'external_id' => '12345',
                'type' => 'steps',
                'value' => 110.0,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:35:00 UTC'),
            ],
        ];

        $this->writer->write($readings);

        $this->assertEquals(2, RawMeasurement::count());
    }

    /** @test scenario 2.4: mixed new-and-seen batch inserts and updates in one operation */
    public function mixedNewAndSeenBatchInsertsAndUpdates(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        // First batch
        $this->writer->write([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 'existing-001',
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:30:00 UTC'),
            ],
        ]);

        // Mixed batch: one existing, one new
        $this->writer->write([
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 'existing-001',
                'type' => 'steps',
                'value' => 150.0,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:30:00 UTC'),
            ],
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'external_id' => 'new-001',
                'type' => 'steps',
                'value' => 200.0,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 10:30:00 UTC'),
            ],
        ]);

        $this->assertEquals(2, RawMeasurement::count());
        $existing = RawMeasurement::where('external_id', 'existing-001')->first();
        $this->assertEquals('150.0000', $existing->value);
        $new = RawMeasurement::where('external_id', 'new-001')->first();
        $this->assertEquals('200.0000', $new->value);
    }

    /** @test SC-011 scenario 2.5: batch with no external_id recorded twice leaves count unchanged */
    public function batchWithNoExternalIdRecordedTwiceLeavesCountUnchanged(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';

        $readings = [
            [
                'user_id' => $userId,
                'external_service' => 'fitbit',
                'type' => 'steps',
                'value' => 100.0,
                'unit' => 'steps',
                'recorded_at' => CarbonImmutable::parse('2026-07-19 09:30:00 UTC'),
            ],
        ];

        $this->writer->write($readings);
        $this->assertEquals(1, RawMeasurement::count());

        // Re-record same reading (no external_id — should derive same identity)
        $this->writer->write($readings);

        $this->assertEquals(1, RawMeasurement::count(), 'Derived identity should dedup');
    }

    /** @test T033: deriveExternalId produces same output for same inputs */
    public function deriveExternalIdProducesSameOutputForSameInputs(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';
        $service = 'fitbit';
        $type = 'steps';
        $recordedAt = CarbonImmutable::parse('2026-07-19 09:30:00 UTC');

        $id1 = $this->writer->deriveExternalId($userId, $service, $type, $recordedAt);
        $id2 = $this->writer->deriveExternalId($userId, $service, $type, $recordedAt);

        $this->assertEquals($id1, $id2);
        $this->assertStringStartsWith('derived:', $id1);
    }

    /** @test T033: deriveExternalId produces different output for different inputs */
    public function deriveExternalIdDivergesForDifferentInputs(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';
        $recordedAt = CarbonImmutable::parse('2026-07-19 09:30:00 UTC');

        $id1 = $this->writer->deriveExternalId($userId, 'fitbit', 'steps', $recordedAt);
        $id2 = $this->writer->deriveExternalId($userId, 'garmin', 'steps', $recordedAt);
        $id3 = $this->writer->deriveExternalId($userId, 'fitbit', 'heart_rate', $recordedAt);

        $this->assertNotEquals($id1, $id2, 'Different service should produce different id');
        $this->assertNotEquals($id1, $id3, 'Different type should produce different id');
    }

    /** @test T033: derived prefix can never collide with provider-supplied id */
    public function derivedPrefixNeverCollidesWithProviderSuppliedId(): void
    {
        $userId = '00000000-0000-0000-0000-000000000001';
        $recordedAt = CarbonImmutable::parse('2026-07-19 09:30:00 UTC');

        $derivedId = $this->writer->deriveExternalId($userId, 'fitbit', 'steps', $recordedAt);

        $this->assertStringStartsWith('derived:', $derivedId);
        $this->assertMatchesRegularExpression('/^derived:[a-f0-9]{64}$/', $derivedId);
    }
}
