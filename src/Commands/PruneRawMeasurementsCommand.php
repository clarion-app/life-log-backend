<?php

namespace ClarionApp\LifeLogBackend\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\CarbonInterface;

class PruneRawMeasurementsCommand extends Command
{
    protected $signature = 'life-log:prune-raw-measurements';
    protected $description = 'Delete raw measurements older than the retention window';

    /**
     * Chunk size for bounded deletion.
     */
    protected int $chunkSize = 500;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $retentionDays = config('life-log.raw_retention_days');

        // null disables pruning entirely
        if ($retentionDays === null) {
            $this->info('Retention pruning is disabled (raw_retention_days is null).');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($retentionDays);
        $this->info("Pruning raw measurements older than {$cutoff->format('Y-m-d H:i:s')} ({$retentionDays} days)...");

        $totalDeleted = 0;

        // Delete in bounded chunks — exclude buckets that have an outstanding queue entry
        // (pending or deferred). This ensures the rollup can still process them.
        // Raw measurements are non-bridged, so deletion does not publish chain events.
        do {
            $deleted = DB::table('life_log_raw_measurements')
                ->where('bucket_hour', '<', $cutoff)
                ->whereDoesntHave('rollupQueue', function ($query) {
                    // Subquery: exclude rows where a queue entry exists for this bucket identity
                    // We use a raw EXISTS subquery for performance
                })
                // Use a subquery approach: delete rows where no matching queue row exists
                ->take($this->chunkSize);

            // Build the actual delete with EXISTS check
            $deletedCount = DB::table('life_log_raw_measurements as rm')
                ->where('rm.bucket_hour', '<', $cutoff)
                ->whereNotExists(function ($query) use ($cutoff) {
                    $query->select(DB::raw(1))
                        ->from('life_log_measurement_rollup_queue as q')
                        ->whereRaw('q.user_id = rm.user_id')
                        ->whereRaw('q.external_service = rm.external_service')
                        ->whereRaw('q.type = rm.type')
                        ->whereRaw('q.unit = rm.unit')
                        ->whereRaw('q.bucket_hour = rm.bucket_hour');
                })
                ->limit($this->chunkSize)
                ->delete();

            $totalDeleted += $deletedCount;

            if ($deletedCount > 0) {
                $this->info("Deleted {$deletedCount} raw measurements (total: {$totalDeleted})...");
            }
        } while ($deletedCount >= $this->chunkSize);

        $this->info("Pruning complete. Total deleted: {$totalDeleted}");

        Log::info("Raw measurement pruning complete: {$totalDeleted} rows deleted (cutoff: {$cutoff->format('Y-m-d H:i:s')})");

        return self::SUCCESS;
    }
}
