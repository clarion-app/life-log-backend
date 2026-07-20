<?php

namespace ClarionApp\LifeLogBackend\Support;

use InvalidArgumentException;

/**
 * Rounds a numeric string to the storage precision, half-up away from zero.
 *
 * Pure bcmath: the value goes in as a string and comes out as a string, and no
 * float is ever formed. decimal(16,4) is the storage precision in both
 * life_log_raw_measurements and life_log_health_metrics, and raw dedup upserts
 * on (external_service, external_id) — an upsert does not reject a re-import
 * whose value differs in the fourth decimal, it overwrites. So a rounding rule
 * that varies by platform or PHP version would drift stored values while the
 * row count stayed correct.
 *
 * Half-up away from zero is chosen over PHP's default half-to-even because it
 * is the rule a reader checking a converted value by hand will apply.
 */
final class Decimal4
{
    /** Storage precision. */
    public const SCALE = 4;

    /** Working precision for the intermediate add — well beyond SCALE. */
    private const WORKING_SCALE = 12;

    /** Half of the last retained digit: 0.00005 at SCALE 4. */
    private const HALF_ULP = '0.00005';

    /**
     * @param  string  $value  a numeric string, never a float
     * @return string 4dp string, e.g. "70.3070"
     */
    public static function round(string $value): string
    {
        $value = trim($value);

        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a numeric string: '{$value}'");
        }

        $value = ltrim($value, '+');

        // Add half of the last retained digit in the value's own direction, then
        // truncate — bcmath truncates toward zero, so this rounds away from it.
        $halfUlp = bccomp($value, '0', self::WORKING_SCALE) < 0
            ? '-' . self::HALF_ULP
            : self::HALF_ULP;

        $rounded = bcadd(bcadd($value, $halfUlp, self::WORKING_SCALE), '0', self::SCALE);

        // "-0.0000" is the same quantity as "0.0000" but a different string, and
        // stored values are compared as strings.
        if ($rounded === '-0.' . str_repeat('0', self::SCALE)) {
            $rounded = substr($rounded, 1);
        }

        return $rounded;
    }
}
