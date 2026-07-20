<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\LifeLogBackend\Models\MeasurementTypeClassification;
use ClarionApp\LifeLogBackend\Services\DirectMeasurementPromoter;
use ClarionApp\LifeLogBackend\Services\HourlyMeasurementRollup;
use ClarionApp\LifeLogBackend\Services\RawMeasurementWriter;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;

/**
 * Manual entry is untouched by the provider contract.
 *
 * The vocabulary constrains the import path only. Nothing about recording a
 * measurement by hand changed: the same routes, the same request fields, the
 * same response shapes, and no validation of `type` against the vocabulary.
 *
 * A manual reading and an imported reading of the same type are one series in
 * one table, told apart only by the pre-existing `source` column.
 */
class ManualEntryUnaffectedTest extends TestCase
{
    protected string $userId = '00000000-0000-0000-0000-000000000001';

    /** The exact response keys manual entry returned before this feature. */
    protected array $expectedKeys = [
        'id', 'user_id', 'type', 'value', 'recorded_at', 'source',
        'created_at', 'updated_at',
    ];

    // ---------------------------------------------------------------- CRUD

    /** @test create returns 201 and the pre-feature response shape */
    public function createReturnsPreFeatureShape(): void
    {
        $response = $this->actingAsUser()->postJson($this->url(), [
            'type' => 'blood_pressure',
            'value' => 118.0,
            'recorded_at' => '2026-07-19 09:00:00',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure($this->expectedKeys);
        $this->assertNoVocabularyFieldsLeakedInto($response->json());
        $this->assertSame('manual', $response->json('source'));
    }

    /** @test index lists only the acting user's metrics, unchanged shape */
    public function indexListsOnlyOwnMetricsWithUnchangedShape(): void
    {
        $this->actingAsUser();
        $this->seedManual('blood_pressure', 118.0);
        $this->seedManual('cholesterol', 190.0);

        // A metric belonging to somebody else must stay invisible.
        HealthMetric::create([
            'user_id' => '00000000-0000-0000-0000-0000000000ff',
            'type' => 'blood_pressure',
            'value' => 200.0,
            'recorded_at' => CarbonImmutable::parse('2026-07-19 09:00:00'),
        ]);

        $response = $this->getJson($this->url());

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonStructure(['*' => $this->expectedKeys]);
    }

    /** @test show returns the metric unchanged */
    public function showReturnsMetricUnchanged(): void
    {
        $this->actingAsUser();
        $metric = $this->seedManual('blood_pressure', 118.0);

        $response = $this->getJson($this->url($metric->id));

        $response->assertStatus(200);
        $response->assertJsonStructure($this->expectedKeys);
        $response->assertJsonFragment(['type' => 'blood_pressure', 'value' => '118.0000']);
    }

    /** @test update rewrites the metric and returns 200 */
    public function updateRewritesMetric(): void
    {
        $this->actingAsUser();
        $metric = $this->seedManual('blood_pressure', 118.0);

        $response = $this->putJson($this->url($metric->id), [
            'type' => 'blood_pressure',
            'value' => 126.0,
            'recorded_at' => '2026-07-19 10:00:00',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure($this->expectedKeys);
        $this->assertSame('126.0000', HealthMetric::find($metric->id)->value);
    }

    /** @test destroy returns 204 and removes the metric */
    public function destroyRemovesMetric(): void
    {
        $this->actingAsUser();
        $metric = $this->seedManual('blood_pressure', 118.0);

        $this->deleteJson($this->url($metric->id))->assertStatus(204);

        $this->assertSame(0, HealthMetric::withoutTrashed()->count());
    }

    // -------------------------------------------------- no vocabulary gate

    /**
     * @test free-form type names are accepted, including names the vocabulary
     *       has never heard of and names that collide with it
     */
    public function freeFormTypeNamesAreAcceptedWithoutVocabularyValidation(): void
    {
        $this->actingAsUser();

        $types = [
            'blood_pressure',            // never in the vocabulary
            'mood',                      // never in the vocabulary
            'peak flow (morning)',       // spaces and punctuation
            'STEPS',                     // differs from a vocabulary name only by case
            MeasurementType::Steps->value, // exactly a vocabulary name
        ];

        foreach ($types as $type) {
            $response = $this->postJson($this->url(), [
                'type' => $type,
                'value' => 42.0,
                'recorded_at' => '2026-07-19 09:00:00',
            ]);

            $response->assertStatus(201);
            $this->assertSame($type, $response->json('type'), "type '{$type}' was rewritten");
        }

        $this->assertSame(count($types), HealthMetric::count());
    }

    /** @test update accepts a free-form type just as create does */
    public function updateAcceptsFreeFormType(): void
    {
        $this->actingAsUser();
        $metric = $this->seedManual(MeasurementType::Weight->value, 70.0);

        $response = $this->putJson($this->url($metric->id), [
            'type' => 'not_in_any_vocabulary',
            'value' => 70.0,
            'recorded_at' => '2026-07-19 10:00:00',
        ]);

        $response->assertStatus(200);
        $this->assertSame('not_in_any_vocabulary', HealthMetric::find($metric->id)->type);
    }

    /**
     * @test a manual entry under a vocabulary name is stored verbatim — no
     *       canonical unit is imposed and no import-side columns are filled
     */
    public function manualEntryUnderVocabularyNameIsNotNormalized(): void
    {
        $this->actingAsUser();

        // Weight's canonical unit is kg; a manual pound reading must survive as-is.
        $response = $this->postJson($this->url(), [
            'type' => MeasurementType::Weight->value,
            'value' => 154.0,
            'recorded_at' => '2026-07-19 09:00:00',
            'unit' => 'lb',
        ]);

        $response->assertStatus(201);

        $metric = HealthMetric::first();
        $this->assertSame('154.0000', $metric->value, 'manual value was unit-converted');
        $this->assertSame('lb', $metric->unit);
        $this->assertNull($metric->external_service);
        $this->assertNull($metric->bucket_hour);
    }

    /** @test the vocabulary sync leaves manual free-form types unclassified */
    public function vocabularySyncDoesNotClassifyManualTypes(): void
    {
        $this->artisan('life-log:sync-vocabulary');

        $this->assertNull(
            MeasurementTypeClassification::where('type', 'blood_pressure')->first(),
            'a manual free-form type gained a classification row'
        );
    }

    // ------------------------------------------------- one shared series

    /**
     * @test a manual weight and an imported weight are the same series in the
     *       same table, distinguished only by the existing `source` column
     */
    public function manualAndImportedWeightShareOneSeries(): void
    {
        $this->actingAsUser();

        $type = MeasurementType::Weight->value;

        // Manual side: recorded by hand through the unchanged controller.
        $this->postJson($this->url(), [
            'type' => $type,
            'value' => 70.0,
            'recorded_at' => '2026-07-19 09:15:00',
        ])->assertStatus(201);

        // Import side: the real raw-write + rollup path.
        $this->artisan('life-log:sync-vocabulary');

        (new RawMeasurementWriter())->write([[
            'user_id' => $this->userId,
            'external_service' => 'fake-band',
            'external_id' => 'band-weight-1',
            'type' => $type,
            'value' => '71.5000',
            'unit' => MeasurementType::Weight->canonicalUnit(),
            'recorded_at' => '2026-07-19T11:15:00Z',
        ]]);

        (new HourlyMeasurementRollup())->run();
        (new DirectMeasurementPromoter())->run();

        // One series: both rows answer the same query on the same type name.
        $series = HealthMetric::where('user_id', $this->userId)
            ->where('type', $type)
            ->orderBy('recorded_at')
            ->get();

        $this->assertCount(2, $series, 'the two origins are not in one series');

        // Not namespaced apart — no prefixed or suffixed variant exists.
        $this->assertSame(
            [$type],
            HealthMetric::where('user_id', $this->userId)->pluck('type')->unique()->values()->all(),
            'the imported reading was written under a namespaced type name'
        );

        // `source` is the only thing telling them apart.
        $this->assertSame(['manual', 'fake-band'], $series->pluck('source')->all());
        $this->assertSame(['70.0000', '71.5000'], $series->pluck('value')->all());
    }

    /**
     * @test importing under a vocabulary name never touches rows recorded
     *       by hand under that same name
     */
    public function importDoesNotDisturbManualRowsOfTheSameType(): void
    {
        $this->actingAsUser();

        $type = MeasurementType::Steps->value;

        $this->postJson($this->url(), [
            'type' => $type,
            'value' => 1000.0,
            'recorded_at' => '2026-07-19 09:15:00',
        ])->assertStatus(201);

        $manual = HealthMetric::first();
        $before = $manual->only(['value', 'source', 'recorded_at', 'updated_at']);

        $this->artisan('life-log:sync-vocabulary');

        // Import lands in the same clock hour as the manual entry.
        (new RawMeasurementWriter())->write([[
            'user_id' => $this->userId,
            'external_service' => 'fake-band',
            'external_id' => 'band-steps-1',
            'type' => $type,
            'value' => '2500.0000',
            'unit' => MeasurementType::Steps->canonicalUnit(),
            'recorded_at' => '2026-07-19T09:45:00Z',
        ]]);

        (new HourlyMeasurementRollup())->run();

        $this->assertEquals($before, $manual->fresh()->only(array_keys($before)));
        $this->assertSame(2, HealthMetric::count(), 'the import overwrote the manual row');
    }

    // ------------------------------------------------------------ helpers

    protected function url(?string $id = null): string
    {
        $base = '/api/clarion-app/life-log/health-metric';

        return $id === null ? $base : $base . '/' . $id;
    }

    protected function seedManual(string $type, float $value): HealthMetric
    {
        return HealthMetric::create([
            'user_id' => $this->userId,
            'type' => $type,
            'value' => $value,
            'recorded_at' => CarbonImmutable::parse('2026-07-19 09:00:00'),
            'source' => 'manual',
        ]);
    }

    /**
     * The import path added columns to the table; a manual response must not
     * start advertising them as required fields of the old contract.
     */
    protected function assertNoVocabularyFieldsLeakedInto(array $payload): void
    {
        foreach (['external_service', 'bucket_hour'] as $importColumn) {
            if (array_key_exists($importColumn, $payload)) {
                $this->assertNull(
                    $payload[$importColumn],
                    "manual entry response populated the import column '{$importColumn}'"
                );
            }
        }
    }

    protected function actingAsUser(): static
    {
        $user = User::find($this->userId) ?? User::forceCreate([
            'id' => $this->userId,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($user, 'api');

        return $this;
    }
}
