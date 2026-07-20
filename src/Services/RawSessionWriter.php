<?php

namespace ClarionApp\LifeLogBackend\Services;

use ClarionApp\LifeLogBackend\External\TranslatedSession;
use ClarionApp\LifeLogBackend\Models\RawHealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Writes translated sessions into the raw staging tier, deduplicating on
 * (external_service, external_id) so a revised session replaces its earlier
 * form instead of accumulating alongside it.
 */
class RawSessionWriter
{
    /**
     * Sessions rejected by the most recent write(), each with the reason.
     *
     * @var list<array{external_id: string, external_service: string, reason: string}>
     */
    protected array $skipped = [];

    /**
     * @param  list<TranslatedSession|array<string, mixed>>  $sessions
     * @return int  the number of rows written or updated
     */
    public function write(array $sessions): int
    {
        $this->skipped = [];

        if (empty($sessions)) {
            return 0;
        }

        $rows = [];

        foreach ($sessions as $session) {
            $row = $session instanceof TranslatedSession
                ? $session->toRawSessionRow()
                : $session;

            $reason = $this->rejectionReason($row);

            if ($reason !== null) {
                $this->recordSkip($row, $reason);
                continue;
            }

            $rows[] = [
                'user_id' => $row['user_id'],
                'external_service' => $row['external_service'],
                'external_id' => $row['external_id'],
                'session_type' => $row['session_type'],
                'started_at' => CarbonImmutable::parse($row['started_at'])->utc(),
                'ended_at' => CarbonImmutable::parse($row['ended_at'])->utc(),
                'summary_values' => is_string($row['summary_values'] ?? null)
                    ? $row['summary_values']
                    : json_encode($row['summary_values'] ?? [], JSON_THROW_ON_ERROR),
                // Every write leaves the row pending promotion. On an update this
                // is the whole point: upsert() bypasses model events, so unless
                // promoted_at is named here explicitly it keeps its old non-null
                // value and the revision is never promoted.
                'promoted_at' => null,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        RawHealthSession::upsert($rows, ['external_service', 'external_id'], [
            'user_id', 'session_type', 'started_at', 'ended_at',
            'summary_values', 'promoted_at', 'updated_at',
        ]);

        return count($rows);
    }

    /**
     * Sessions rejected by the most recent write().
     *
     * @return list<array{external_id: string, external_service: string, reason: string}>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * Why this row cannot be stored, or null if it can.
     *
     * An unusable span is dropped rather than repaired. Inventing an end time
     * would put a fabricated duration into permanent history, where nothing
     * downstream could tell it apart from a measured one.
     */
    protected function rejectionReason(array $row): ?string
    {
        if (($row['ended_at'] ?? null) === null) {
            return 'Session has no end time; an end is never guessed.';
        }

        if (($row['started_at'] ?? null) === null) {
            return 'Session has no start time.';
        }

        $startedAt = CarbonImmutable::parse($row['started_at']);
        $endedAt = CarbonImmutable::parse($row['ended_at']);

        if (!$endedAt->greaterThan($startedAt)) {
            return sprintf(
                'Session ends at or before it starts (%s → %s).',
                $startedAt->toIso8601String(),
                $endedAt->toIso8601String(),
            );
        }

        return null;
    }

    protected function recordSkip(array $row, string $reason): void
    {
        $entry = [
            'external_id' => (string) ($row['external_id'] ?? ''),
            'external_service' => (string) ($row['external_service'] ?? ''),
            'reason' => $reason,
        ];

        $this->skipped[] = $entry;

        Log::warning('Skipped raw health session: ' . $reason, $entry);
    }
}
