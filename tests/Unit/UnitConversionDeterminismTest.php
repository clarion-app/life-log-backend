<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Exceptions\UnconvertibleUnitException;
use ClarionApp\LifeLogBackend\Support\Decimal4;
use ClarionApp\LifeLogBackend\Support\UnitConverter;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;

class UnitConversionDeterminismTest extends TestCase
{
    protected UnitConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new UnitConverter();
    }

    /** @test  repeated conversion of the same input is byte-identical */
    public function conversionIsByteIdenticalAcrossRepeatedCalls(): void
    {
        $first = $this->converter->toCanonical('155.5', 'lb', MeasurementType::Weight);

        for ($i = 0; $i < 25; $i++) {
            $this->assertSame($first, $this->converter->toCanonical('155.5', 'lb', MeasurementType::Weight));
        }

        // A second instance must agree with the first — no per-instance state.
        $this->assertSame($first, (new UnitConverter())->toCanonical('155.5', 'lb', MeasurementType::Weight));
    }

    /** @test  all six registered factors */
    public function allSixRegisteredFactorsConvertExactly(): void
    {
        // lb -> kg : 45359237 / 100000000
        $this->assertSame('70.3068', $this->converter->toCanonical('155', 'lb', MeasurementType::Weight));
        // mi -> m : 1609344 / 1000
        $this->assertSame('1609.3440', $this->converter->toCanonical('1', 'mi', MeasurementType::Distance));
        // km -> m : 1000 / 1
        $this->assertSame('5000.0000', $this->converter->toCanonical('5', 'km', MeasurementType::Distance));
        // min -> s : 60 / 1
        $this->assertSame('1800.0000', $this->converter->toCanonical('30', 'min', MeasurementType::ActiveMinutes));
        // h -> s : 3600 / 1
        $this->assertSame('27000.0000', $this->converter->toCanonical('7.5', 'h', MeasurementType::ActiveMinutes));
        // kJ -> kcal : 1000 / 4184
        $this->assertSame('478.7285', $this->converter->toCanonical('2003', 'kJ', MeasurementType::CaloriesBurned));
    }

    /** @test  an already-canonical unit short-circuits but is still normalized to 4dp */
    public function identityConversionShortCircuitsToFourDecimals(): void
    {
        $this->assertSame('72.0000', $this->converter->toCanonical('72', 'bpm', MeasurementType::HeartRate));
        $this->assertSame('8421.0000', $this->converter->toCanonical('8421', 'count', MeasurementType::Steps));
        $this->assertSame('70.3068', $this->converter->toCanonical('70.30680', 'kg', MeasurementType::Weight));
    }

    /** @test  SC-004 — the same reading via two units agrees within retained precision */
    public function theSameReadingInTwoUnitsAgreesWithinRetainedPrecision(): void
    {
        $viaPounds = $this->converter->toCanonical('155', 'lb', MeasurementType::Weight);
        $viaKilos = $this->converter->toCanonical('70.3068', 'kg', MeasurementType::Weight);
        $this->assertSame($viaKilos, $viaPounds);

        $viaMiles = $this->converter->toCanonical('3.1069', 'mi', MeasurementType::Distance);
        $viaKm = $this->converter->toCanonical('5.0000', 'km', MeasurementType::Distance);
        $this->assertSame(0, bccomp($viaMiles, $viaKm, 0), "{$viaMiles} vs {$viaKm}");

        $viaHours = $this->converter->toCanonical('0.5', 'h', MeasurementType::ActiveMinutes);
        $viaMinutes = $this->converter->toCanonical('30', 'min', MeasurementType::ActiveMinutes);
        $this->assertSame($viaMinutes, $viaHours);
    }

    /** @test  converting out and back returns the original within retained precision */
    public function convertAndReconvertRoundTripsWithinRetainedPrecision(): void
    {
        $originalPounds = '155.0000';
        $kg = $this->converter->toCanonical($originalPounds, 'lb', MeasurementType::Weight);

        // Reverse by the same exact rational, rounded the same way.
        $backToPounds = Decimal4::round(bcdiv(bcmul($kg, '100000000', 12), '45359237', 12));

        $this->assertSame(0, bccomp($originalPounds, $backToPounds, 3), "{$originalPounds} vs {$backToPounds}");
    }

    /** @test  exact midpoints round half-up away from zero, never half-to-even */
    public function midpointsRoundHalfUpAwayFromZero(): void
    {
        // .00005 with an even preceding digit — half-to-even would give 70.3066.
        $this->assertSame('70.3067', Decimal4::round('70.30665'));
        $this->assertSame('-70.3067', Decimal4::round('-70.30665'));

        // .00005 with an odd preceding digit — both rules agree, kept as a guard.
        $this->assertSame('70.3068', Decimal4::round('70.30675'));
        $this->assertSame('-70.3068', Decimal4::round('-70.30675'));

        $this->assertSame('0.0001', Decimal4::round('0.00005'));
        $this->assertSame('-0.0001', Decimal4::round('-0.00005'));

        // Just below the midpoint stays down, in both directions.
        $this->assertSame('0.0000', Decimal4::round('0.00004999'));
        $this->assertSame('0.0000', Decimal4::round('-0.00004999'));
    }

    /** @test  no float is ever formed — very large values keep every digit */
    public function largeValuesKeepEveryDigit(): void
    {
        $this->assertSame('123456789012.3457', Decimal4::round('123456789012.34565'));
        $this->assertSame('999999999999.9999', Decimal4::round('999999999999.99994'));
        $this->assertSame('5000000.0000', $this->converter->toCanonical('5000', 'km', MeasurementType::Distance));
    }

    /** @test  integers and bare values normalize to exactly four decimals */
    public function outputAlwaysCarriesExactlyFourDecimals(): void
    {
        foreach (['0', '1', '-1', '42.5', '0.1'] as $value) {
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', Decimal4::round($value));
        }

        // Negative zero is normalized away — it would read as a distinct value.
        $this->assertSame('0.0000', Decimal4::round('-0.00001'));
    }

    /** @test  an unknown source unit throws rather than converting (FR-014) */
    public function unknownSourceUnitThrows(): void
    {
        $this->expectException(UnconvertibleUnitException::class);
        $this->converter->toCanonical('155', 'stone', MeasurementType::Weight);
    }

    /** @test  a unit convertible for another type is still unconvertible here */
    public function aUnitValidForAnotherTypeIsStillUnconvertible(): void
    {
        // 'min' converts to seconds, but never to metres.
        $this->expectException(UnconvertibleUnitException::class);
        $this->converter->toCanonical('30', 'min', MeasurementType::Distance);
    }

    /** @test  a missing unit throws rather than being guessed */
    public function missingSourceUnitThrows(): void
    {
        $this->expectException(UnconvertibleUnitException::class);
        $this->converter->toCanonical('155', '', MeasurementType::Weight);
    }

    /** @test  the exception names the service-side unit and the target type */
    public function exceptionCarriesTheUnitAndType(): void
    {
        try {
            $this->converter->toCanonical('155', 'stone', MeasurementType::Weight);
            $this->fail('Expected UnconvertibleUnitException');
        } catch (UnconvertibleUnitException $e) {
            $this->assertStringContainsString('stone', $e->getMessage());
            $this->assertStringContainsString('weight', $e->getMessage());
            $this->assertSame('stone', $e->sourceUnit);
            $this->assertSame(MeasurementType::Weight, $e->type);
        }
    }

    /** @test  a non-numeric value is a mapping bug, not a unit problem */
    public function nonNumericValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->converter->toCanonical('seventy', 'kg', MeasurementType::Weight);
    }
}
