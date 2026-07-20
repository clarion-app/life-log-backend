<?php

namespace ClarionApp\LifeLogBackend\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Prune connection attempts that are more than 24h past their expires_at.
 *
 * Runs hourly. The 24h grace period keeps recently-consumed rows around
 * long enough to be diagnosable. Deletes in bounded chunks.
 */
class PruneConnectionAttemptsCommand extends Command
{
    protected $signature = 'life-log:prune-connection-attempts';
    protected $description = 'Delete connection attempts more than 24h past their expiry';

    /**
     * Chunk size for bounded deletion.
     */
    protected int $chunkSize = 500;

    public function handle(): int
    {
        // Cutoff: expires_at more than 24h ago
        $cutoff = now()->subHours(24);
        $this->info("Pruning connection attempts expired before {$cutoff->format('Y-m-d H:i:s')}...");

        $totalDeleted = 0;

        // Delete in bounded chunks
        do {
            $deletedCount = DB::table('life_log_connection_attempts')
                ->where('expires_at', '<', $cutoff)
                ->limit($this->chunkSize)
                ->delete();

            $totalDeleted += $deletedCount;

            if ($deletedCount > 0) {
                $this->info("Deleted {$deletedCount} attempts (total: {$totalDeleted})...");
            }
        } while ($deletedCount >= $this->chunkSize);

        $this->info("Pruning complete. Total deleted: {$totalDeleted}");

        Log::info("Connection attempt pruning complete: {$totalDeleted} rows deleted (cutoff: {$cutoff->format('Y-m-d H:i:s')})");

        return self::SUCCESS;
    }
}
