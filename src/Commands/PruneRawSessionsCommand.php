<?php

namespace ClarionApp\LifeLogBackend\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneRawSessionsCommand extends Command
{
    protected $signature = 'life-log:prune-raw-sessions';
    protected $description = 'Delete promoted raw health sessions older than the retention window';

    /**
     * Chunk size for bounded deletion.
     */
    protected int $chunkSize = 500;

    public function setChunkSize(int $chunkSize): void
    {
        $this->chunkSize = $chunkSize;
    }

    public function handle(): int
    {
        $retentionDays = config('life-log.raw_session_retention_days');

        // null disables pruning entirely
        if ($retentionDays === null) {
            $this->info('Retention pruning is disabled (raw_session_retention_days is null).');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($retentionDays);
        $this->info("Pruning raw health sessions ended before {$cutoff->format('Y-m-d H:i:s')} ({$retentionDays} days)...");

        $totalDeleted = 0;

        // Only promoted rows are eligible. An unpromoted raw session is the only
        // copy of that session anywhere, so age alone never justifies deleting it.
        // Raw sessions are non-bridged, so deletion publishes no chain events.
        do {
            $deletedCount = DB::table('life_log_raw_health_sessions')
                ->where('ended_at', '<', $cutoff)
                ->whereNotNull('promoted_at')
                ->limit($this->chunkSize)
                ->delete();

            $totalDeleted += $deletedCount;

            if ($deletedCount > 0) {
                $this->info("Deleted {$deletedCount} raw health sessions (total: {$totalDeleted})...");
            }
        } while ($deletedCount >= $this->chunkSize);

        $this->info("Pruning complete. Total deleted: {$totalDeleted}");

        Log::info("Raw health session pruning complete: {$totalDeleted} rows deleted (cutoff: {$cutoff->format('Y-m-d H:i:s')})");

        return self::SUCCESS;
    }
}
