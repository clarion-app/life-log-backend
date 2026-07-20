<?php

namespace ClarionApp\LifeLogBackend\Vocabulary;

/**
 * A session summary key paired with the canonical unit its value is stored in.
 *
 * Sessions carry their summary values as a key/value map, so the unit lives
 * with the declaration rather than beside each stored number. Like the
 * vocabulary names themselves, a released key and its unit are permanent.
 */
final readonly class SummaryValue
{
    public function __construct(
        public string $key,
        public string $unit,
    ) {
    }
}
