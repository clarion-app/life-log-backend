<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Exceptions\UnconvertibleUnitException;
use ClarionApp\LifeLogBackend\Models\UnmappedTypeRecord;
use ClarionApp\LifeLogBackend\Services\UnmappedTypeRecorder;
use ClarionApp\LifeLogBackend\Support\UnitConverter;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UnmappedTypeRecorderTest extends TestCase
{
    protected UnmappedTypeRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new UnmappedTypeRecorder();
    }

    /** @test  FR-015 — first sighting persists the full record */
    public function firstSightingInsertsTheRecord(): void
    {
        CarbonImmutable::setTestNow('2026-07-19 09:15:00');

        $this->recorder->record('acme-band', 'blood_glucose', '5.4', 'mmol/L');

        $row = UnmappedTypeRecord::first();
        $this->assertNotNull($row);
        $this->assertSame('acme-band', $row->external_service);
        $this->assertSame('blood_glucose', $row->service_type_name);
        $this->assertSame('5.4', $row->sample_value);
        $this->assertSame('mmol/L', $row->sample_unit);
        $this->assertSame(1, (int) $row->occurrence_count);
        $this->assertSame('2026-07-19 09:15:00', $row->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-19 09:15:00', $row->last_seen_at->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow();
    }

    /** @test  FR-015 — the first sighting is also logged */
    public function firstSightingIsLogged(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context = []) {
                return str_contains($message, 'acme-band')
                    && str_contains($message, 'blood_glucose');
            });

        $this->recorder->record('acme-band', 'blood_glucose', '5.4', 'mmol/L');
    }

    /** @test  a repeat sighting increments and touches last_seen_at only */
    public function repeatSightingIncrementsWithoutMovingFirstSeen(): void
    {
        CarbonImmutable::setTestNow('2026-07-19 09:15:00');
        $this->recorder->record('acme-band', 'blood_glucose', '5.4', 'mmol/L');

        CarbonImmutable::setTestNow('2026-07-20 11:45:00');
        $this->recorder->record('acme-band', 'blood_glucose', '6.1', 'mmol/L');
        $this->recorder->record('acme-band', 'blood_glucose', '6.2', 'mmol/L');

        $this->assertSame(1, UnmappedTypeRecord::count());

        $row = UnmappedTypeRecord::first();
        $this->assertSame(3, (int) $row->occurrence_count);
        $this->assertSame('2026-07-19 09:15:00', $row->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-20 11:45:00', $row->last_seen_at->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow();
    }

    /** @test  only the first sighting logs — the hot path stays quiet thereafter */
    public function repeatSightingDoesNotLogAgain(): void
    {
        Log::shouldReceive('info')->once();

        $this->recorder->record('acme-band', 'blood_glucose');
        $this->recorder->record('acme-band', 'blood_glucose');
        $this->recorder->record('acme-band', 'blood_glucose');
    }

    /** @test  the same type name from two services is two records */
    public function recordsAreScopedPerService(): void
    {
        $this->recorder->record('acme-band', 'blood_glucose');
        $this->recorder->record('other-band', 'blood_glucose');

        $this->assertSame(2, UnmappedTypeRecord::count());
    }

    /** @test  sample value and unit are optional */
    public function sampleValueAndUnitAreOptional(): void
    {
        $this->recorder->record('acme-band', 'mystery_type');

        $row = UnmappedTypeRecord::first();
        $this->assertNull($row->sample_value);
        $this->assertNull($row->sample_unit);
        $this->assertSame(1, (int) $row->occurrence_count);
    }

    /** @test  FR-018 — a broken recorder never fails the surrounding translation */
    public function recorderFailureNeverThrows(): void
    {
        Schema::drop('life_log_unmapped_type_records');

        $this->recorder->record('acme-band', 'blood_glucose', '5.4', 'mmol/L');

        $this->addToAssertionCount(1);
    }

    /**
     * @test FR-014/FR-018 — with storage broken, translation still skips the
     * unmappable item and returns everything it could map.
     */
    public function translationCompletesWhenTheRecorderCannotStore(): void
    {
        Schema::drop('life_log_unmapped_type_records');

        $page = [
            ['type' => 'bodyweight', 'value' => '155', 'unit' => 'lb'],
            ['type' => 'bodyweight', 'value' => '155', 'unit' => 'stone'],
            ['type' => 'hr_bpm', 'value' => '72', 'unit' => 'bpm'],
        ];

        $this->assertSame(['70.3068', '72.0000'], $this->translate($page));
    }

    /** @test  FR-014 — the rest of the page still returns when storage works */
    public function unmappableItemIsSkippedAndTheRestOfThePageReturns(): void
    {
        $page = [
            ['type' => 'bodyweight', 'value' => '155', 'unit' => 'lb'],
            ['type' => 'bodyweight', 'value' => '155', 'unit' => 'stone'],
            ['type' => 'hr_bpm', 'value' => '72', 'unit' => 'bpm'],
        ];

        $this->assertSame(['70.3068', '72.0000'], $this->translate($page));

        $row = UnmappedTypeRecord::first();
        $this->assertNotNull($row);
        $this->assertSame('bodyweight', $row->service_type_name);
        $this->assertSame('stone', $row->sample_unit);
    }

    /**
     * A stand-in service's translation loop, mirroring the documented pattern:
     * convert, and on an unconvertible unit record and continue.
     *
     * @param  list<array<string,string>>  $page
     * @return list<string>
     */
    private function translate(array $page): array
    {
        $map = [
            'bodyweight' => MeasurementType::Weight,
            'hr_bpm' => MeasurementType::HeartRate,
        ];

        $converter = new UnitConverter();
        $values = [];

        foreach ($page as $item) {
            try {
                $values[] = $converter->toCanonical($item['value'], $item['unit'], $map[$item['type']]);
            } catch (UnconvertibleUnitException) {
                $this->recorder->record('acme-band', $item['type'], $item['value'], $item['unit']);
                continue;
            }
        }

        return $values;
    }
}
