<?php

namespace ClarionApp\LifeLogBackend\Sync;

/**
 * Per-type outcome within a backfill run.
 *
 * `completed` and `windowExhausted` answer different questions and must not be
 * collapsed into one. `completed` means this type has no history left at all —
 * it is never queried again. `windowExhausted` means only that the one window
 * this run took was walked to its end, which is what an ordinary healthy run
 * does every time. Treating an exhausted window as an incomplete type is what
 * made every successful backfill report itself as Partial.
 */
final readonly class BackfillTypeOutcome
{
    public function __construct(
        public string $type,
        public bool $skipped = false,
        public bool $completed = false,
        public int $pagesFetched = 0,
        public int $measurementsWritten = 0,
        public int $sessionsWritten = 0,
        public bool $windowExhausted = false,
    ) {
    }
}
