<?php

namespace ClarionApp\LifeLogBackend\Services;

use ClarionApp\LifeLogBackend\Models\HealthSession;
use ClarionApp\LifeLogBackend\Models\RawHealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Moves raw sessions into permanent, chain-replicated history — one permanent
 * record per external session, forever.
 *
 * Deliberately unlike the measurement rollup: there is no hour bucketing and no
 * aggregation at any step. A session already is the unit of meaning, so
 * promotion is a one-for-one copy.
 */
class SessionPromoter
{
    protected int $chunkSize;

    public function __construct(?int $chunkSize = null)
    {
        $this->chunkSize = $chunkSize ?? (int) config('life-log.rollup_batch_size', 500);
    }

    /**
     * Promote every raw session awaiting promotion.
     *
     * @return array{promoted: int, skipped: int}
     */
    public function run(): array
    {
        $promoted = 0;
        $skipped = 0;

        do {
            $batch = RawHealthSession::pendingPromotion()
                ->orderBy('id')
                ->limit($this->chunkSize)
                ->get();

            foreach ($batch as $raw) {
                if ($this->promote($raw)) {
                    $promoted++;
                } else {
                    $skipped++;
                }
            }
        } while ($batch->count() === $this->chunkSize && $batch->isNotEmpty());

        return ['promoted' => $promoted, 'skipped' => $skipped];
    }

    /**
     * Promote one raw session, returning whether it reached permanent history.
     */
    protected function promote(RawHealthSession $raw): bool
    {
        if ($raw->ended_at === null || !$raw->ended_at->greaterThan($raw->started_at)) {
            Log::warning('Skipped promoting a health session with an unusable span.', [
                'external_service' => $raw->external_service,
                'external_id' => $raw->external_id,
            ]);

            // Marked promoted so the scan does not revisit it every run. A later
            // corrected import resets promoted_at and gets another chance.
            $this->markPromoted($raw);

            return false;
        }

        $session = HealthSession::firstOrNew([
            'external_service' => $raw->external_service,
            'external_id' => $raw->external_id,
        ]);

        $session->user_id = $raw->user_id;
        $session->session_type = $raw->session_type;
        $session->started_at = $raw->started_at;
        $session->ended_at = $raw->ended_at;
        $session->summary_values = $raw->summary_values;
        // Provenance is the originating service, never 'manual'.
        $session->source = $raw->external_service;

        // An unchanged record is not dirty, so save() issues no UPDATE, so the
        // bridge publishes nothing and updated_at stays put.
        $session->save();

        $this->markPromoted($raw);

        return true;
    }

    /**
     * Stamp promoted_at without disturbing updated_at — the raw row's content
     * did not change, only its promotion state.
     */
    protected function markPromoted(RawHealthSession $raw): void
    {
        RawHealthSession::withoutTimestamps(function () use ($raw) {
            $raw->promoted_at = CarbonImmutable::now();
            $raw->save();
        });
    }
}
