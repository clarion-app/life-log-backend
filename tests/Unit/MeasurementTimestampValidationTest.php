<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Exceptions\ImplausibleTimestampException;
use ClarionApp\LifeLogBackend\External\TranslatedMeasurement;
use ClarionApp\LifeLogBackend\Models\RawMeasurement;
use ClarionApp\LifeLogBackend\Models\UnmappedTypeRecord;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use ClarionApp\LifeLogBackend\Support\RecordedAtValidator;
use ClarionApp\LifeLogBackend\Support\UnitConverter;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;

/**
 * FR-032 — a measurement with no recorded time, or a time outside the plausible
 * range, is skipped with a recorded reason. It is never assigned a substitute
 * time, because any substitute buckets it into an hour it did not happen in.
 */
class MeasurementTimestampValidationTest extends TestCase
{
    private const USER = '00000000-0000-0000-0000-000000000001';

    protected RecordedAtValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-19 12:00:00');
        $this->validator = new RecordedAtValidator();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function aPlausibleTimeIsAcceptedAndNormalizedToUtc(): void
    {
        $at = $this->validator->validate('2026-07-19T09:30:00+02:00');

        $this->assertInstanceOf(CarbonImmutable::class, $at);
        $this->assertSame('UTC', $at->tzName);
        $this->assertSame('2026-07-19 07:30:00', $at->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function anAbsentTimeIsRejected(): void
    {
        foreach ([null, '', '   '] as $missing) {
            try {
                $this->validator->validate($missing);
                $this->fail('Expected ImplausibleTimestampException for ' . var_export($missing, true));
            } catch (ImplausibleTimestampException $e) {
                $this->assertSame(ImplausibleTimestampException::REASON_MISSING, $e->reason);
            }
        }
    }

    /** @test */
    public function anUnparseableTimeIsRejected(): void
    {
        $this->expectException(ImplausibleTimestampException::class);
        $this->validator->validate('not-a-timestamp');
    }

    /** @test */
    public function aTimeBeforeThePlausibleFloorIsRejected(): void
    {
        try {
            $this->validator->validate('1969-12-31T23:59:59Z');
            $this->fail('Expected ImplausibleTimestampException');
        } catch (ImplausibleTimestampException $e) {
            $this->assertSame(ImplausibleTimestampException::REASON_TOO_EARLY, $e->reason);
            $this->assertStringContainsString('1969', $e->getMessage());
        }
    }

    /** @test  the epoch-zero default a service emits for an unset field */
    public function epochZeroIsRejected(): void
    {
        $this->expectException(ImplausibleTimestampException::class);
        $this->validator->validate('1970-01-01T00:00:00Z');
    }

    /** @test */
    public function aTimeBeyondTheFutureToleranceIsRejected(): void
    {
        try {
            $this->validator->validate('2026-07-21 12:00:01');
            $this->fail('Expected ImplausibleTimestampException');
        } catch (ImplausibleTimestampException $e) {
            $this->assertSame(ImplausibleTimestampException::REASON_TOO_LATE, $e->reason);
        }
    }

    /** @test  modest clock skew is tolerated rather than discarded */
    public function modestClockSkewIntoTheFutureIsAccepted(): void
    {
        $at = $this->validator->validate('2026-07-19 18:00:00');
        $this->assertSame('2026-07-19 18:00:00', $at->format('Y-m-d H:i:s'));
    }

    /** @test  the boundaries themselves are inclusive */
    public function plausibleBoundsAreInclusive(): void
    {
        $this->assertSame(
            '2000-01-01 00:00:00',
            $this->validator->validate('2000-01-01T00:00:00Z')->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-20 12:00:00',
            $this->validator->validate('2026-07-20 12:00:00')->format('Y-m-d H:i:s'),
        );
    }

    /** @test  a value object cannot be built around an implausible time either */
    public function translatedMeasurementRejectsAnImplausibleTime(): void
    {
        $this->expectException(ImplausibleTimestampException::class);

        new TranslatedMeasurement(
            userId: self::USER,
            type: MeasurementType::HeartRate,
            value: '72.0000',
            unit: 'bpm',
            recordedAt: CarbonImmutable::parse('1970-01-01T00:00:00Z'),
            externalId: 'acme-1',
            externalService: 'acme-band',
        );
    }

    /**
     * @test FR-032 — the bad item is skipped with a recorded reason, the rest of
     * the page still returns, and nothing lands in a wrong hour bucket.
     */
    public function theBadItemIsSkippedAndTheRestOfThePageStillReturns(): void
    {
        $page = [
            ['type' => 'hr_bpm', 'value' => '70', 'unit' => 'bpm', 'at' => '2026-07-19T09:10:00Z', 'id' => 'a'],
            ['type' => 'hr_bpm', 'value' => '71', 'unit' => 'bpm', 'at' => null,                   'id' => 'b'],
            ['type' => 'hr_bpm', 'value' => '72', 'unit' => 'bpm', 'at' => '1970-01-01T00:00:00Z', 'id' => 'c'],
            ['type' => 'hr_bpm', 'value' => '73', 'unit' => 'bpm', 'at' => '2099-01-01T00:00:00Z', 'id' => 'd'],
            ['type' => 'hr_bpm', 'value' => '74', 'unit' => 'bpm', 'at' => '2026-07-19T09:50:00Z', 'id' => 'e'],
        ];

        $translated = $this->translate($page);

        $this->assertCount(2, $translated);
        $this->assertSame(['a', 'e'], array_map(fn ($m) => $m->externalId, $translated));

        // Each skip is recorded with its reason, never silently dropped.
        $this->assertSame(3, UnmappedTypeRecord::count());
        $reasons = UnmappedTypeRecord::pluck('service_type_name')->all();
        sort($reasons);
        $this->assertSame(
            ['hr_bpm:timestamp_missing', 'hr_bpm:timestamp_too_early', 'hr_bpm:timestamp_too_late'],
            $reasons,
        );
    }

    /** @test  FR-032 — a skipped item never reaches a bucket at all */
    public function aSkippedItemIsNeverBucketed(): void
    {
        $page = [
            ['type' => 'hr_bpm', 'value' => '70', 'unit' => 'bpm', 'at' => '2026-07-19T09:10:00Z', 'id' => 'a'],
            ['type' => 'hr_bpm', 'value' => '71', 'unit' => 'bpm', 'at' => null,                   'id' => 'b'],
        ];

        $writer = new RawMeasurementWriter();
        $writer->write(array_map(
            fn (TranslatedMeasurement $m) => $m->toRawMeasurementRow(),
            $this->translate($page),
        ));

        $this->assertSame(1, RawMeasurement::count());

        $row = RawMeasurement::first();
        $this->assertSame('a', $row->external_id);
        $this->assertSame('2026-07-19 09:00:00', CarbonImmutable::parse($row->bucket_hour)->format('Y-m-d H:i:s'));

        // Nothing was parked in the current hour, the epoch hour, or any other hour.
        $this->assertSame([], RawMeasurement::where('external_id', 'b')->get()->all());
        $this->assertSame(1, RawMeasurement::distinct()->count('bucket_hour'));
    }

    /**
     * A stand-in service's translation loop: validate the time, and on failure
     * record the reason and continue with the rest of the page.
     *
     * @param  list<array<string,mixed>>  $page
     * @return list<TranslatedMeasurement>
     */
    private function translate(array $page): array
    {
        $converter = new UnitConverter();
        $recorder = new UnmappedTypeRecorder();
        $out = [];

        foreach ($page as $item) {
            try {
                $recordedAt = $this->validator->validate($item['at']);
            } catch (ImplausibleTimestampException $e) {
                $recorder->record('acme-band', $item['type'] . ':' . $e->reason, $item['value'], $item['unit']);
                continue;
            }

            $out[] = new TranslatedMeasurement(
                userId: self::USER,
                type: MeasurementType::HeartRate,
                value: $converter->toCanonical($item['value'], $item['unit'], MeasurementType::HeartRate),
                unit: MeasurementType::HeartRate->canonicalUnit(),
                recordedAt: $recordedAt,
                externalId: $item['id'],
                externalService: 'acme-band',
            );
        }

        return $out;
    }
}
