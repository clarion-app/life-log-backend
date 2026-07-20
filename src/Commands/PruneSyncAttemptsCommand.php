<?php

namespace ClarionApp\LifeLogBackend\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneSyncAttemptsCommand extends Command
{
    protected $signature = 'life-log:prune-sync-attempts';
    protected $description = 'Delete sync attempt rows older than the retention window';

    /**
     * Chunk size for bounded deletion.
     */
    protected int $chunkSize = 500;

    public function handle(): int
    {
        $retentionDays = config('life-log.sync_attempt_retention_days');

        // null disables pruning entirely
        if ($retentionDays === null) {
            $this->info('Sync attempt pruning is disabled (sync_attempt_retention_days is null).');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($retentionDays);
        $this->info("Pruning sync attempts older than {$cutoff->format('Y-m-d H:i:s')} ({$retentionDays} days)...");

        $totalDeleted = 0;

        // Delete in bounded chunks — simple age-based retention.
        // Sync attempts are non-bridged, so deletion does not publish chain events.
        do {
            $deletedCount = DB::table('life_log_sync_attempts')
                ->where('started_at', '<', $cutoff)
                ->limit($this->chunkSize)
                ->delete();

            $totalDeleted += $deletedCount;

            if ($deletedCount > 0) {
                $this->info("Deleted {$deletedCount} sync attempts (total: {$totalDeleted})...");
            }
        } while ($deletedCount >= $this->chunkSize);

        $this->info("Pruning complete. Total deleted: {$totalDeleted}");

        Log::info("Sync attempt pruning complete: {$totalDeleted} rows deleted (cutoff: {$cutoff->format('Y-m-d H:i:s')})");

        return self::SUCCESS;
    }
}
