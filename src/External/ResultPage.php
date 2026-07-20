<?php

namespace ClarionApp\LifeLogBackend\External;

/**
 * One page of a fetch: what was found, and where to resume.
 *
 * Emptiness and exhaustion are independent, which is the whole reason the cursor
 * is a separate signal rather than an inference from the result count. All four
 * combinations are legal:
 *
 *   results + cursor   an ordinary mid-range page
 *   results + null     the final page, with data on it
 *   empty   + cursor   a sparse span — keep going
 *   empty   + null     the range held nothing; not a failure
 *
 * A caller that stopped on the empty page would silently truncate a backfill at
 * the first stretch the user did not wear their device, and the truncation would
 * be indistinguishable from the user simply having no older history.
 */
final readonly class ResultPage
{
    /**
     * @param  list<TranslatedMeasurement>  $measurements
     * @param  list<TranslatedSession>  $sessions
     */
    public function __construct(
        private array $measurements = [],
        private array $sessions = [],
        private ?PageCursor $nextCursor = null,
    ) {
    }

    /** @return list<TranslatedMeasurement> */
    public function measurements(): array
    {
        return $this->measurements;
    }

    /** @return list<TranslatedSession> */
    public function sessions(): array
    {
        return $this->sessions;
    }

    /** null means the range is exhausted — NOT that this page happened to be empty. */
    public function nextCursor(): ?PageCursor
    {
        return $this->nextCursor;
    }

    /** True when this page carried neither measurements nor sessions. */
    public function isEmpty(): bool
    {
        return $this->measurements === [] && $this->sessions === [];
    }
}
