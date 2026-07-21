<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\Backend\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Source filtering and opt-in pagination on the measurement list.
 *
 * 058 backfills years of Direct-mode readings into life_log_health_metrics, so
 * an unbounded index is a ceiling the interface would inherit. Pagination is
 * opt-in rather than default because ManualEntryBackwardCompatibilityTest is
 * frozen against the bare-array shape: an old client must keep receiving
 * exactly what it received before, and a client that has not asked to be
 * paginated must never be silently truncated into believing it has the lot.
 */
class HealthMetricIndexFilteringTest extends TestCase
{
    protected string $userId = '00000000-0000-0000-0000-000000000001';
    protected string $otherUserId = '00000000-0000-0000-0000-000000000002';

    private function url(array $params = []): string
    {
        $base = '/api/clarion-app/life-log/health-metric';

        return $params === [] ? $base : $base . '?' . http_build_query($params);
    }

    private function seedMetric(
        string $type,
        float $value,
        ?string $source = 'manual',
        ?string $recordedAt = '2026-07-19 09:00:00',
        ?string $userId = null,
    ): HealthMetric {
        return HealthMetric::create([
            'user_id'     => $userId ?? $this->userId,
            'type'        => $type,
            'value'       => $value,
            'recorded_at' => CarbonImmutable::parse($recordedAt),
            'source'      => $source,
        ]);
    }

    private function actingAsUser(?string $id = null): static
    {
        $id ??= $this->userId;

        $user = User::find($id) ?? User::forceCreate([
            'id'       => $id,
            'name'     => 'Test User',
            'email'    => $id . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($user, 'api');

        return $this;
    }

    // ------------------------------------------------------- frozen contract

    /** @test with no parameters the response is still a bare array of every row */
    public function withoutParametersTheResponseIsABareArray(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 80.0);
        $this->seedMetric('steps', 5000.0, 'google-health');

        $response = $this->getJson($this->url());

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $this->assertIsList($response->json(), 'the unparameterised index must stay a bare list');
    }

    /** @test an unparameterised index is never silently truncated */
    public function unparameterisedIndexReturnsEveryRow(): void
    {
        $this->actingAsUser();
        for ($i = 0; $i < 150; $i++) {
            $this->seedMetric('steps', $i, 'google-health', '2026-07-19 09:00:00');
        }

        $response = $this->getJson($this->url());

        $response->assertJsonCount(150);
    }

    // ------------------------------------------------------- source filtering

    /** @test source filters the list to one origin */
    public function sourceFiltersToOneOrigin(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 80.0, 'manual');
        $this->seedMetric('steps', 5000.0, 'google-health');
        $this->seedMetric('steps', 6000.0, 'google-health');

        $response = $this->getJson($this->url(['source' => 'google-health']));

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        foreach ($response->json() as $row) {
            $this->assertSame('google-health', $row['source']);
        }
    }

    /** @test source=manual selects manual entries only */
    public function sourceManualSelectsManualEntriesOnly(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 80.0, 'manual');
        $this->seedMetric('steps', 5000.0, 'google-health');

        $response = $this->getJson($this->url(['source' => 'manual']));

        $response->assertJsonCount(1);
        $this->assertSame('manual', $response->json()[0]['source']);
    }

    /**
     * @test source=manual also claims a row whose provenance is blank
     *
     * Reachable through the public API: store() falls back to 'manual' only for
     * a *missing* source, so a client posting an empty string stores one.
     */
    public function sourceManualClaimsRowsWithNoRecordedSource(): void
    {
        $this->actingAsUser();
        $orphan = $this->seedMetric('weight', 79.0, 'manual');
        DB::table('life_log_health_metrics')->where('id', $orphan->id)->update(['source' => '']);
        $this->seedMetric('steps', 5000.0, 'google-health');

        $response = $this->getJson($this->url(['source' => 'manual']));

        $response->assertJsonCount(1);
        $this->assertSame($orphan->id, $response->json()[0]['id']);
    }

    /** @test a filter matching nothing returns an empty list, not an error */
    public function aFilterMatchingNothingReturnsAnEmptyList(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 80.0, 'manual');

        $response = $this->getJson($this->url(['source' => 'fitbit']));

        $response->assertStatus(200);
        $response->assertJsonCount(0);
    }

    /** @test the filter never reaches another user's rows */
    public function theFilterNeverReachesAnotherUsersRows(): void
    {
        $this->actingAsUser();
        $this->seedMetric('steps', 5000.0, 'google-health');
        $this->seedMetric('steps', 9999.0, 'google-health', '2026-07-19 09:00:00', $this->otherUserId);

        $response = $this->getJson($this->url(['source' => 'google-health']));

        $response->assertJsonCount(1);
        $this->assertSame('5000.0000', $response->json()[0]['value']);
    }

    // ----------------------------------------------------- opt-in pagination

    /** @test page turns the response into an envelope */
    public function pageTurnsTheResponseIntoAnEnvelope(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 80.0);

        $response = $this->getJson($this->url(['page' => 1]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'available_sources'],
        ]);
        $this->assertIsList($response->json('data'));
    }

    /** @test per_page alone is enough to opt in */
    public function perPageAloneOptsIn(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 80.0);

        $response = $this->getJson($this->url(['per_page' => 10]));

        $response->assertJsonStructure(['data', 'meta']);
        $this->assertSame(10, $response->json('meta.per_page'));
    }

    /** @test pages are bounded and report the true total */
    public function pagesAreBoundedAndReportTheTrueTotal(): void
    {
        $this->actingAsUser();
        for ($i = 0; $i < 25; $i++) {
            $this->seedMetric('steps', $i, 'google-health');
        }

        $response = $this->getJson($this->url(['per_page' => 10, 'page' => 2]));

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(25, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
        $this->assertSame(2, $response->json('meta.current_page'));
    }

    /** @test per_page is clamped so one request cannot ask for the whole table */
    public function perPageIsClamped(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 80.0);

        $this->assertSame(500, $this->getJson($this->url(['per_page' => 100000]))->json('meta.per_page'));
        $this->assertSame(1, $this->getJson($this->url(['per_page' => 0]))->json('meta.per_page'));
        $this->assertSame(1, $this->getJson($this->url(['per_page' => -5]))->json('meta.per_page'));
    }

    /** @test paging is stable — no row is skipped or repeated across pages */
    public function pagingIsStableAcrossPages(): void
    {
        $this->actingAsUser();
        // Identical timestamps: without a tiebreaker the sort is not total and
        // rows drift between pages.
        for ($i = 0; $i < 30; $i++) {
            $this->seedMetric('steps', $i, 'google-health', '2026-07-19 09:00:00');
        }

        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            foreach ($this->getJson($this->url(['per_page' => 10, 'page' => $page]))->json('data') as $row) {
                $seen[] = $row['id'];
            }
        }

        $this->assertCount(30, $seen);
        $this->assertCount(30, array_unique($seen), 'a row appeared on more than one page');
    }

    /** @test the newest reading comes first */
    public function theNewestReadingComesFirst(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 1.0, 'manual', '2026-07-01 09:00:00');
        $newest = $this->seedMetric('weight', 2.0, 'manual', '2026-07-20 09:00:00');
        $this->seedMetric('weight', 3.0, 'manual', '2026-07-10 09:00:00');

        $response = $this->getJson($this->url(['page' => 1]));

        $this->assertSame($newest->id, $response->json('data.0.id'));
    }

    /** @test filter and pagination compose */
    public function filterAndPaginationCompose(): void
    {
        $this->actingAsUser();
        for ($i = 0; $i < 12; $i++) {
            $this->seedMetric('steps', $i, 'google-health');
        }
        $this->seedMetric('weight', 80.0, 'manual');

        $response = $this->getJson($this->url(['source' => 'google-health', 'per_page' => 5, 'page' => 1]));

        $this->assertCount(5, $response->json('data'));
        $this->assertSame(12, $response->json('meta.total'), 'total must count the filtered set');
    }

    // ------------------------------------------------- available_sources meta

    /** @test available_sources reports what is actually in this user's data */
    public function availableSourcesReportsWhatIsActuallyPresent(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 80.0, 'manual');
        $this->seedMetric('steps', 5000.0, 'google-health');
        $this->seedMetric('steps', 6000.0, 'google-health');

        $sources = $this->getJson($this->url(['page' => 1]))->json('meta.available_sources');

        $this->assertSame(['google-health', 'manual'], $sources);
    }

    /** @test available_sources is unaffected by the active filter */
    public function availableSourcesIsUnaffectedByTheActiveFilter(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 80.0, 'manual');
        $this->seedMetric('steps', 5000.0, 'google-health');

        $response = $this->getJson($this->url(['source' => 'manual', 'page' => 1]));

        $this->assertSame(
            ['google-health', 'manual'],
            $response->json('meta.available_sources'),
            'the option list must not collapse to whatever is currently selected'
        );
    }

    /** @test a blank source is reported as manual, never as a blank option */
    public function aBlankSourceIsReportedAsManual(): void
    {
        $this->actingAsUser();
        $orphan = $this->seedMetric('weight', 79.0, 'manual');
        DB::table('life_log_health_metrics')->where('id', $orphan->id)->update(['source' => '']);

        $sources = $this->getJson($this->url(['page' => 1]))->json('meta.available_sources');

        $this->assertSame(['manual'], $sources);
    }

    /** @test available_sources never names another user's sources */
    public function availableSourcesNeverNamesAnotherUsersSources(): void
    {
        $this->actingAsUser();
        $this->seedMetric('weight', 80.0, 'manual');
        $this->seedMetric('steps', 9999.0, 'fitbit', '2026-07-19 09:00:00', $this->otherUserId);

        $sources = $this->getJson($this->url(['page' => 1]))->json('meta.available_sources');

        $this->assertSame(['manual'], $sources);
    }

    /**
     * @test an empty available_sources is how the interface tells "you have no
     *       readings" apart from "this filter matched nothing" (FR-035)
     */
    public function emptyAvailableSourcesDistinguishesNoDataFromNoMatches(): void
    {
        $this->actingAsUser();

        $noData = $this->getJson($this->url(['page' => 1]));
        $this->assertSame(0, $noData->json('meta.total'));
        $this->assertSame([], $noData->json('meta.available_sources'));

        $this->seedMetric('weight', 80.0, 'manual');

        $noMatches = $this->getJson($this->url(['source' => 'fitbit', 'page' => 1]));
        $this->assertSame(0, $noMatches->json('meta.total'));
        $this->assertSame(['manual'], $noMatches->json('meta.available_sources'));
    }
}
