<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Google\Mapping\DataTypeMap;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;

/**
 * Every Google data type maps to a vocabulary type; unmapped types are skipped.
 */
class DataTypeMapTest extends TestCase
{
    /** @test T047 — stepCount maps to Steps */
    public function stepCountMapsToSteps(): void
    {
        $resolved = DataTypeMap::resolve('stepCount');
        $this->assertSame(MeasurementType::Steps, $resolved);
    }

    /** @test T047 — heartRateBpm maps to HeartRate */
    public function heartRateBpmMapsToHeartRate(): void
    {
        $resolved = DataTypeMap::resolve('heartRateBpm');
        $this->assertSame(MeasurementType::HeartRate, $resolved);
    }

    /** @test T047 — caloriesBurned maps to CaloriesBurned */
    public function caloriesBurnedMapsToCaloriesBurned(): void
    {
        $resolved = DataTypeMap::resolve('caloriesBurned');
        $this->assertSame(MeasurementType::CaloriesBurned, $resolved);
    }

    /** @test T047 — activeMinutes maps to ActiveMinutes */
    public function activeMinutesMapsToActiveMinutes(): void
    {
        $resolved = DataTypeMap::resolve('activeMinutes');
        $this->assertSame(MeasurementType::ActiveMinutes, $resolved);
    }

    /** @test T047 — distance maps to Distance */
    public function distanceMapsToDistance(): void
    {
        $resolved = DataTypeMap::resolve('distance');
        $this->assertSame(MeasurementType::Distance, $resolved);
    }

    /** @test T047 — weight maps to Weight */
    public function weightMapsToWeight(): void
    {
        $resolved = DataTypeMap::resolve('weight');
        $this->assertSame(MeasurementType::Weight, $resolved);
    }

    /** @test T047 — sleepSession maps to Sleep */
    public function sleepSessionMapsToSleep(): void
    {
        $resolved = DataTypeMap::resolve('sleepSession');
        $this->assertSame(SessionType::Sleep, $resolved);
    }

    /** @test T047 — exerciseSession maps to Workout */
    public function exerciseSessionMapsToWorkout(): void
    {
        $resolved = DataTypeMap::resolve('exerciseSession');
        $this->assertSame(SessionType::Workout, $resolved);
    }

    /** @test T047 — unmapped type returns null */
    public function unmappedTypeReturnsNull(): void
    {
        $resolved = DataTypeMap::resolve('unknownType');
        $this->assertNull($resolved);
    }

    /** @test T047 — shortCode returns expected abbreviation for each type */
    public function shortCodesAreCorrect(): void
    {
        $this->assertSame('steps', DataTypeMap::shortCode('stepCount'));
        $this->assertSame('hr', DataTypeMap::shortCode('heartRateBpm'));
        $this->assertSame('cal', DataTypeMap::shortCode('caloriesBurned'));
        $this->assertSame('actmin', DataTypeMap::shortCode('activeMinutes'));
        $this->assertSame('dist', DataTypeMap::shortCode('distance'));
        $this->assertSame('wt', DataTypeMap::shortCode('weight'));
        $this->assertSame('sleep', DataTypeMap::shortCode('sleepSession'));
        $this->assertSame('workout', DataTypeMap::shortCode('exerciseSession'));
    }

    /** @test T047 — allGoogleTypes returns all eight mapped types */
    public function allGoogleTypesReturnsEightTypes(): void
    {
        $types = DataTypeMap::allGoogleTypes();
        $this->assertCount(8, $types);
        $this->assertContains('stepCount', $types);
        $this->assertContains('heartRateBpm', $types);
        $this->assertContains('caloriesBurned', $types);
        $this->assertContains('activeMinutes', $types);
        $this->assertContains('distance', $types);
        $this->assertContains('weight', $types);
        $this->assertContains('sleepSession', $types);
        $this->assertContains('exerciseSession', $types);
    }

    /** @test T047 — has() checks membership */
    public function hasChecksMembership(): void
    {
        $this->assertTrue(DataTypeMap::has('stepCount'));
        $this->assertTrue(DataTypeMap::has('heartRateBpm'));
        $this->assertTrue(DataTypeMap::has('sleepSession'));
        $this->assertFalse(DataTypeMap::has('unknownType'));
    }
}
