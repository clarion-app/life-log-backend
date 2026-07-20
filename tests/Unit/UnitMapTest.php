<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Google\Mapping\UnitMap;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;

/**
 * UnitMap declares canonical units and allowed sources for each Google data type.
 * The actual conversion is delegated to UnitConverter.
 */
class UnitMapTest extends TestCase
{
    /** @test T048 — stepCount canonical unit is count */
    public function stepCountCanonicalUnitIsCount(): void
    {
        $this->assertSame('count', UnitMap::canonicalUnit('stepCount'));
    }

    /** @test T048 — heartRateBpm canonical unit is bpm */
    public function heartRateBpmCanonicalUnitIsBpm(): void
    {
        $this->assertSame('bpm', UnitMap::canonicalUnit('heartRateBpm'));
    }

    /** @test T048 — weight canonical unit is kg */
    public function weightCanonicalUnitIsKg(): void
    {
        $this->assertSame('kg', UnitMap::canonicalUnit('weight'));
    }

    /** @test T048 — caloriesBurned canonical unit is kcal */
    public function caloriesBurnedCanonicalUnitIsKcal(): void
    {
        $this->assertSame('kcal', UnitMap::canonicalUnit('caloriesBurned'));
    }

    /** @test T048 — distance canonical unit is m */
    public function distanceCanonicalUnitIsM(): void
    {
        $this->assertSame('m', UnitMap::canonicalUnit('distance'));
    }

    /** @test T048 — activeMinutes canonical unit is s */
    public function activeMinutesCanonicalUnitIsS(): void
    {
        $this->assertSame('s', UnitMap::canonicalUnit('activeMinutes'));
    }

    /** @test T048 — unmapped type returns null */
    public function unmappedTypeReturnsNull(): void
    {
        $this->assertNull(UnitMap::canonicalUnit('unknownType'));
    }

    /** @test T048 — allowedSources lists valid source units for weight */
    public function allowedSourcesListValidUnitsForWeight(): void
    {
        $allowed = UnitMap::allowedSources('weight');
        $this->assertContains('kg', $allowed);
        $this->assertContains('g', $allowed);
        $this->assertContains('lb', $allowed);
    }

    /** @test T048 — allowedSources lists valid source units for distance */
    public function allowedSourcesListValidUnitsForDistance(): void
    {
        $allowed = UnitMap::allowedSources('distance');
        $this->assertContains('m', $allowed);
        $this->assertContains('km', $allowed);
        $this->assertContains('mi', $allowed);
    }

    /** @test T048 — isAllowedSource checks membership */
    public function isAllowedSourceChecksMembership(): void
    {
        $this->assertTrue(UnitMap::isAllowedSource('weight', 'kg'));
        $this->assertTrue(UnitMap::isAllowedSource('weight', 'lb'));
        $this->assertFalse(UnitMap::isAllowedSource('weight', 'oz'));
    }

    /** @test T048 — measurementType() maps google type back to MeasurementType */
    public function measurementTypeMapsGoogleTypeBack(): void
    {
        $this->assertSame(MeasurementType::Steps, UnitMap::measurementType('stepCount'));
        $this->assertSame(MeasurementType::HeartRate, UnitMap::measurementType('heartRateBpm'));
        $this->assertSame(MeasurementType::Weight, UnitMap::measurementType('weight'));
        $this->assertSame(MeasurementType::CaloriesBurned, UnitMap::measurementType('caloriesBurned'));
        $this->assertSame(MeasurementType::Distance, UnitMap::measurementType('distance'));
        $this->assertSame(MeasurementType::ActiveMinutes, UnitMap::measurementType('activeMinutes'));
    }

    /** @test T048 — measurementType returns null for unmapped type */
    public function measurementTypeReturnsNullForUnmapped(): void
    {
        $this->assertNull(UnitMap::measurementType('unknownType'));
    }
}
