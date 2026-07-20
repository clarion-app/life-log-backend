<?php

namespace ClarionApp\LifeLogBackend\Vocabulary;

/**
 * The fixed vocabulary of span-based session types.
 *
 * A session covers a stretch of time rather than an instant, so it is not a
 * measurement: it has a start, an end, and zero or more summary values. The
 * backed value is the permanent storage name written into
 * life_log_raw_health_sessions.session_type and
 * life_log_health_sessions.session_type, under the same stability guarantee as
 * MeasurementType.
 */
enum SessionType: string
{
    case Sleep   = 'sleep';
    case Workout = 'workout';

    /**
     * The summary values this session type carries, keyed by name, valued by
     * the canonical unit each is stored in.
     *
     * A session may omit any of these. It may never carry a key absent here —
     * an undeclared key is a value of unknown meaning in permanent history.
     *
     * @return array<string, string>
     */
    public function summaryValues(): array
    {
        return match ($this) {
            self::Sleep   => ['duration' => 's', 'asleep_duration' => 's'],
            self::Workout => ['duration' => 's', 'distance' => 'm', 'energy' => 'kcal'],
        };
    }

    /**
     * The same declaration as value objects, for callers that want to pass a
     * key and its unit around together.
     *
     * @return list<SummaryValue>
     */
    public function summaryValueObjects(): array
    {
        $out = [];

        foreach ($this->summaryValues() as $key => $unit) {
            $out[] = new SummaryValue($key, $unit);
        }

        return $out;
    }
}
