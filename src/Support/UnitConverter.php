<?php

namespace ClarionApp\LifeLogBackend\Support;

use ClarionApp\LifeLogBackend\Exceptions\UnconvertibleUnitException;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use InvalidArgumentException;

/**
 * Converts a service-reported value into its measurement type's canonical unit.
 *
 * Factors are exact integer rationals evaluated in bcmath, never decimal
 * approximations. A float factor such as 0.45359237 can round differently
 * across platforms or PHP versions, so re-importing an identical reading would
 * write a value differing in the fourth decimal; the dedup upsert on
 * (external_service, external_id) does not reject that, it overwrites, drifting
 * the stored value while the row count stays correct. Integer rationals make
 * the conversion bit-identical everywhere, forever.
 */
final class UnitConverter
{
    /** Working precision for the rational evaluation, before rounding to 4dp. */
    private const WORKING_SCALE = 12;

    /**
     * source unit => target unit => [numerator, denominator]
     *
     * @var array<string, array<string, array{string, string}>>
     */
    private const FACTORS = [
        'lb'  => ['kg'   => ['45359237', '100000000']],
        'mi'  => ['m'    => ['1609344', '1000']],
        'km'  => ['m'    => ['1000', '1']],
        'min' => ['s'    => ['60', '1']],
        'h'   => ['s'    => ['3600', '1']],
        'kJ'  => ['kcal' => ['1000', '4184']],
    ];

    /**
     * @param  string  $value  a numeric string — never a float
     * @return string 4dp string in $type->canonicalUnit(), e.g. "70.3070"
     *
     * @throws UnconvertibleUnitException the caller skips the item and records it (FR-014)
     * @throws InvalidArgumentException   the value is not numeric — a mapping bug, not a data condition
     */
    public function toCanonical(string $value, string $sourceUnit, MeasurementType $type): string
    {
        $canonicalUnit = $type->canonicalUnit();
        $sourceUnit = trim($sourceUnit);

        // Already canonical — nothing to scale, just normalize the precision.
        if ($sourceUnit === $canonicalUnit) {
            return Decimal4::round($value);
        }

        $factor = self::FACTORS[$sourceUnit][$canonicalUnit] ?? null;

        if ($factor === null) {
            throw new UnconvertibleUnitException($sourceUnit, $type);
        }

        [$numerator, $denominator] = $factor;

        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', trim($value))) {
            throw new InvalidArgumentException("Not a numeric string: '{$value}'");
        }

        $scaled = bcdiv(
            bcmul(trim($value), $numerator, self::WORKING_SCALE),
            $denominator,
            self::WORKING_SCALE,
        );

        return Decimal4::round($scaled);
    }
}
