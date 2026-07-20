<?php

namespace ClarionApp\LifeLogBackend\Exceptions;

use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use RuntimeException;

/**
 * A service reported a unit that cannot be converted to the target type's
 * canonical unit, or reported no unit at all.
 *
 * This is a runtime data condition, not a programming error: the caller skips
 * the item, records it as unmapped, and continues with the rest of the page.
 * Guessing a unit would put a value of unknown meaning into permanent,
 * replicated history.
 */
class UnconvertibleUnitException extends RuntimeException
{
    public function __construct(
        public readonly string $sourceUnit,
        public readonly MeasurementType $type,
    ) {
        $shown = $sourceUnit === '' ? '(none)' : $sourceUnit;

        parent::__construct(
            "Cannot convert unit '{$shown}' to '{$type->canonicalUnit()}' for measurement type '{$type->value}'."
        );
    }
}
