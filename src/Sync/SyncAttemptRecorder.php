<?php

namespace ClarionApp\LifeLogBackend\Sync;

use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Models\SyncAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a sync run outcome to the sync_attempts table.
 *
 * Follows the UnmappedTypeRecorder discipline: the entire body is wrapped in
 * try/catch(\Throwable) that logs and returns. A storage layer failure must not
 * cost the caller their SyncResult or propagate an exception.
 *
 * Truncates error_message to a safe length and never stores a raw provider payload.
 */
class SyncAttemptRecorder
{
    /** Maximum length for error_message column (text type, but truncate for safety). */
    private const ERROR_MESSAGE_MAX = 1000;

    /**
     * Record a sync run outcome.
     *
     * Returns the SyncResult unchanged so the caller retains it regardless of
     * whether the write succeeded.
     *
     * @return SyncResult the input result, unchanged
     */
    public function record(
        string $connectedAccountId,
        SyncTrigger $trigger,
        SyncResult $result,
    ): SyncResult {
        try {
            $account = ConnectedAccount::find($connectedAccountId);

            if (! $account) {
                Log::warning(
                    "SyncAttemptRecorder: account '{$connectedAccountId}' not found, skipping record."
                );
                return $result;
            }

            SyncAttempt::create([
                'connected_account_id' => $connectedAccountId,
                'user_id' => $account->user_id,
                'external_service' => $account->external_service,
                'trigger' => $trigger->value,
                'outcome' => $result->outcome->value,
                'range_since' => $result->since,
                'range_until' => $result->until,
                'pages_fetched' => $result->pagesFetched,
                'measurements_written' => $result->measurementsWritten,
                'sessions_written' => $result->sessionsWritten,
                'failure_kind' => $result->failureKind?->value,
                'error_message' => $this->safeErrorMessage($result->errorMessage),
                'started_at' => $result->startedAt,
                'finished_at' => $result->finishedAt,
            ]);
        } catch (Throwable $e) {
            Log::error(
                sprintf(
                    'SyncAttemptRecorder: failed to record sync attempt for account [%s]: %s',
                    $connectedAccountId,
                    $e->getMessage(),
                )
            );
        }

        return $result;
    }

    /**
     * Sanitize and truncate the error message.
     *
     * Never stores a raw provider payload — only the sanitized message
     * from the HealthServiceFailure (which is service-authored prose for
     * a human reading a log).
     */
    private function safeErrorMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        // Strip any non-printable characters and truncate
        $cleaned = preg_replace('/[^\x20-\x7E\n\r\t]/', '', $message);

        if (strlen($cleaned) > self::ERROR_MESSAGE_MAX) {
            $cleaned = substr($cleaned, 0, self::ERROR_MESSAGE_MAX - 3) . '...';
        }

        return $cleaned;
    }
}
