<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Google\Api\ApiVersion;
use ClarionApp\LifeLogBackend\Google\Oauth\ScopeBundle;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;

/**
 * Exactly three scope bundles; each declares the types it covers;
 * the union is the six supported types.
 */
class ScopeBundleTest extends TestCase
{
    /** @test T036 — exactly three cases exist */
    public function exactlyThreeCasesExist(): void
    {
        $this->assertCount(3, ScopeBundle::cases());
    }

    /** @test T036 — all() returns all three bundles */
    public function allReturnsThreeBundles(): void
    {
        $all = ScopeBundle::all();
        $this->assertCount(3, $all);
        $this->assertSame(ScopeBundle::ActivityAndFitness, $all[0]);
        $this->assertSame(ScopeBundle::HealthMetrics, $all[1]);
        $this->assertSame(ScopeBundle::Sleep, $all[2]);
    }

    /** @test T036 — each case has a scope string under the BASE_URL */
    public function eachCaseHasScopeString(): void
    {
        foreach (ScopeBundle::cases() as $case) {
            $scope = $case->scopeString();
            $this->assertStringContainsString(ApiVersion::BASE_URL, $scope);
            $this->assertStringContainsString('readonly', $scope);
        }
    }

    /** @test T036 — ActivityAndFitness covers steps, heart rate, calories burned AND workouts */
    public function activityAndFitnessCoversCorrectTypes(): void
    {
        $covers = ScopeBundle::ActivityAndFitness->covers();
        $this->assertContains(MeasurementType::Steps, $covers);
        $this->assertContains(MeasurementType::HeartRate, $covers);
        $this->assertContains(MeasurementType::CaloriesBurned, $covers);
        $this->assertContains(SessionType::Workout, $covers);
        $this->assertCount(4, $covers);
    }

    /** @test T036 — HealthMetrics covers weight */
    public function healthMetricsCoversWeight(): void
    {
        $covers = ScopeBundle::HealthMetrics->covers();
        $this->assertContains(MeasurementType::Weight, $covers);
        $this->assertCount(1, $covers);
    }

    /** @test T036 — Sleep covers sleep sessions */
    public function sleepCoversSleep(): void
    {
        $covers = ScopeBundle::Sleep->covers();
        $this->assertContains(SessionType::Sleep, $covers);
        $this->assertCount(1, $covers);
    }

    /** @test T036 — the union of all bundles covers exactly the six supported types */
    public function unionOfBundlesCoversSixTypes(): void
    {
        $allTypes = [];
        foreach (ScopeBundle::cases() as $case) {
            foreach ($case->covers() as $type) {
                $allTypes[] = $type;
            }
        }

        $this->assertCount(6, $allTypes);
        $this->assertContains(MeasurementType::Steps, $allTypes);
        $this->assertContains(MeasurementType::HeartRate, $allTypes);
        $this->assertContains(MeasurementType::CaloriesBurned, $allTypes);
        $this->assertContains(MeasurementType::Weight, $allTypes);
        $this->assertContains(SessionType::Workout, $allTypes);
        $this->assertContains(SessionType::Sleep, $allTypes);
    }

    /** @test T036 — fromScopeString parses a scope string back to the bundle */
    public function fromScopeStringParsesCorrectly(): void
    {
        foreach (ScopeBundle::cases() as $case) {
            $parsed = ScopeBundle::fromScopeString($case->scopeString());
            $this->assertSame($case, $parsed);
        }
    }

    /** @test T036 — fromScopeString returns null for unknown scope */
    public function fromScopeStringReturnsNullForUnknown(): void
    {
        $this->assertNull(ScopeBundle::fromScopeString('https://unknown.scope'));
    }

    /** @test T036 — typesFor flat-maps granted bundles to types */
    public function typesForFlatMapsGrantedBundles(): void
    {
        $supported = [
            MeasurementType::Steps,
            MeasurementType::HeartRate,
            MeasurementType::CaloriesBurned,
            MeasurementType::Weight,
            SessionType::Workout,
            SessionType::Sleep,
        ];

        $types = ScopeBundle::typesFor(
            [ScopeBundle::ActivityAndFitness, ScopeBundle::HealthMetrics],
            $supported,
        );

        $this->assertCount(5, $types);
        $this->assertContains(MeasurementType::Steps, $types);
        $this->assertContains(MeasurementType::HeartRate, $types);
        $this->assertContains(MeasurementType::CaloriesBurned, $types);
        $this->assertContains(MeasurementType::Weight, $types);
        $this->assertNotContains(SessionType::Sleep, $types);
    }

    /** @test T036 — typesFor accepts string bundle names */
    public function typesForAcceptsStringBundleNames(): void
    {
        $supported = [
            MeasurementType::Steps,
            SessionType::Sleep,
        ];

        $types = ScopeBundle::typesFor(
            ['activity_and_fitness', 'sleep'],
            $supported,
        );

        $this->assertCount(2, $types);
        $this->assertContains(MeasurementType::Steps, $types);
        $this->assertContains(SessionType::Sleep, $types);
    }
}
