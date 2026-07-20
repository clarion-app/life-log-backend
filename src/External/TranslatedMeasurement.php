<?php

namespace ClarionApp\LifeLogBackend\External;

use ClarionApp\LifeLogBackend\Support\Decimal4;
use ClarionApp\LifeLogBackend\Support\RecordedAtValidator;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One measurement, already translated out of a service's own shape into the
 * vocabulary.
 *
 * This is the only form a measurement crosses the service boundary in. Nothing
 * here holds an unstructured payload: the type is an enum instance rather than
 * a string, so an off-vocabulary type is unrepresentable, and the unit is
 * guaranteed to be the type's canonical unit.
 *
 * userId is carried on the object rather than passed alongside the batch
 * because RawMeasurementWriter::write() reads it per row — for the row itself,
 * for the dirty-bucket key, and for deriving an absent external_id. Carrying it
 * here means a batch spanning two users cannot be mis-attributed by a single
 * out-of-band parameter.
 */
final readonly class TranslatedMeasurement
{
    public function __construct(
        public string $userId,
        public MeasurementType $type,
        public string $value,
        public string $unit,
        public CarbonImmutable $recordedAt,
        public string $externalId,
        public string $externalService,
    ) {
        if ($this->userId === '') {
            throw new InvalidArgumentException('TranslatedMeasurement requires a user id.');
        }

        if ($this->externalId === '') {
            throw new InvalidArgumentException('TranslatedMeasurement requires an external id.');
        }

        if ($this->externalService === '') {
            throw new InvalidArgumentException('TranslatedMeasurement requires an external service name.');
        }

        // A mapping that emits a non-canonical unit is a programming error, not
        // a data condition — an unconvertible unit is caught before this point.
        if ($this->unit !== $type->canonicalUnit()) {
            throw new InvalidArgumentException(
                "Measurement type '{$type->value}' is stored in '{$type->canonicalUnit()}', got '{$this->unit}'."
            );
        }

        // 4dp string, matching the decimal(16,4) storage columns exactly. A
        // float here would defeat the whole point of the conversion path.
        if (!preg_match('/^-?\d+\.\d{4}$/', $this->value)) {
            throw new InvalidArgumentException(
                "Measurement value must be a 4dp decimal string, got '{$this->value}'."
            );
        }

        // Skip-and-record happens before construction; reaching here with an
        // implausible time would mean bucketing it into an hour it did not
        // happen in, so the object refuses to exist (FR-032).
        (new RecordedAtValidator())->assertPlausible($this->recordedAt);
    }

    /**
     * The row shape RawMeasurementWriter::write() already consumes, so storage
     * needs no change to accept translated imports.
     *
     * @return array<string, mixed>
     */
    public function toRawMeasurementRow(): array
    {
        return [
            'user_id' => $this->userId,
            'external_service' => $this->externalService,
            'external_id' => $this->externalId,
            'type' => $this->type->value,
            'value' => $this->value,
            'unit' => $this->unit,
            'recorded_at' => $this->recordedAt,
        ];
    }

    /**
     * Build from a converted value, normalizing the precision and taking the
     * unit from the type so a caller cannot disagree with it.
     */
    public static function make(
        string $userId,
        MeasurementType $type,
        string $value,
        CarbonImmutable $recordedAt,
        string $externalId,
        string $externalService,
    ): self {
        return new self(
            userId: $userId,
            type: $type,
            value: Decimal4::round($value),
            unit: $type->canonicalUnit(),
            recordedAt: $recordedAt,
            externalId: $externalId,
            externalService: $externalService,
        );
    }
}
