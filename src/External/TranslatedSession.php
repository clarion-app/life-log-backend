<?php

namespace ClarionApp\LifeLogBackend\External;

use ClarionApp\LifeLogBackend\Support\Decimal4;
use ClarionApp\LifeLogBackend\Support\RecordedAtValidator;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One span-based session — a night's sleep, a workout — already translated out
 * of a service's own shape into the vocabulary.
 *
 * A session is not a measurement: it covers a stretch of time rather than an
 * instant, so it is never hour-bucketed and a session crossing midnight stays
 * one record (FR-024).
 *
 * userId is carried here for the same reason as on TranslatedMeasurement, plus a
 * harder one: life_log_raw_health_sessions.user_id is a non-nullable foreign
 * key, so a session with no owner cannot be stored at all.
 */
final readonly class TranslatedSession
{
    /**
     * @param  array<string, string>  $summaryValues  keys declared by $type, values 4dp strings in the declared unit
     */
    public function __construct(
        public string $userId,
        public SessionType $type,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $endedAt,
        public array $summaryValues,
        public string $externalId,
        public string $externalService,
    ) {
        if ($this->userId === '') {
            throw new InvalidArgumentException('TranslatedSession requires a user id.');
        }

        if ($this->externalId === '') {
            throw new InvalidArgumentException('TranslatedSession requires an external id.');
        }

        if ($this->externalService === '') {
            throw new InvalidArgumentException('TranslatedSession requires an external service name.');
        }

        // A zero-length or backwards session is not a span. Storing one would put
        // a sleep that ended before it began into permanent history, where every
        // duration computed from it afterwards is wrong.
        if (!$this->endedAt->greaterThan($this->startedAt)) {
            throw new InvalidArgumentException(
                "Session '{$this->externalId}' ends at or before it starts "
                . "({$this->startedAt->toIso8601String()} → {$this->endedAt->toIso8601String()})."
            );
        }

        $declared = $type->summaryValues();

        foreach ($this->summaryValues as $key => $value) {
            // A subset is legal — a service may not measure everything. An
            // undeclared key is not: it would be a number of unknown meaning and
            // unknown unit sitting in replicated history forever.
            if (!array_key_exists($key, $declared)) {
                throw new InvalidArgumentException(
                    "Session type '{$type->value}' declares no summary value '{$key}'. Declared: "
                    . implode(', ', array_keys($declared)) . '.'
                );
            }

            if (!is_string($value) || !preg_match('/^-?\d+\.\d{4}$/', $value)) {
                throw new InvalidArgumentException(
                    "Summary value '{$key}' must be a 4dp decimal string in '{$declared[$key]}', got "
                    . var_export($value, true) . '.'
                );
            }
        }

        $validator = new RecordedAtValidator();
        $validator->assertPlausible($this->startedAt);
        $validator->assertPlausible($this->endedAt);
    }

    /**
     * The unit each present summary value is expressed in, taken from the
     * vocabulary rather than from the service.
     *
     * @return array<string, string>
     */
    public function summaryUnits(): array
    {
        return array_intersect_key($this->type->summaryValues(), $this->summaryValues);
    }

    /**
     * The row shape the raw session store consumes.
     *
     * @return array<string, mixed>
     */
    public function toRawSessionRow(): array
    {
        return [
            'user_id' => $this->userId,
            'external_service' => $this->externalService,
            'external_id' => $this->externalId,
            'session_type' => $this->type->value,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'summary_values' => json_encode($this->summaryValues, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Build from raw numeric strings, normalizing each summary value's precision
     * so a service cannot decide its own.
     *
     * @param  array<string, string>  $summaryValues
     */
    public static function make(
        string $userId,
        SessionType $type,
        CarbonImmutable $startedAt,
        CarbonImmutable $endedAt,
        array $summaryValues,
        string $externalId,
        string $externalService,
    ): self {
        $rounded = [];

        foreach ($summaryValues as $key => $value) {
            $rounded[$key] = is_string($value) ? Decimal4::round($value) : $value;
        }

        return new self(
            userId: $userId,
            type: $type,
            startedAt: $startedAt,
            endedAt: $endedAt,
            summaryValues: $rounded,
            externalId: $externalId,
            externalService: $externalService,
        );
    }
}
