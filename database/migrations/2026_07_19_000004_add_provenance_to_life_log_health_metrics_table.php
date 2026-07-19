<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Step 1: Add all columns nullable — no default, no lock beyond the add
        Schema::table('life_log_health_metrics', function (Blueprint $table) {
            $table->string('source', 64)->nullable()->after('type');
            $table->string('unit', 32)->nullable()->after('source');
            $table->string('external_service', 64)->nullable()->after('unit');
            $table->timestamp('bucket_hour')->nullable()->after('external_service');
            $table->json('metadata')->nullable()->after('bucket_hour');
        });

        // Step 2: Backfill source = 'manual' via query builder (plan D6)
        // Fires no events, republishes nothing, leaves updated_at untouched
        DB::table('life_log_health_metrics')
            ->whereNull('source')
            ->update(['source' => 'manual']);

        // Step 3: Set source NOT NULL with default 'manual'
        Schema::table('life_log_health_metrics', function (Blueprint $table) {
            $table->string('source', 64)->default('manual')->change();
        });

        // T011: Partial unique index on (user_id, external_service, type, unit, bucket_hour)
        // where bucket_hour IS NOT NULL — enforces FR-007 at the database level.
        //
        // Portability note: partial indexes are native on SQLite and PostgreSQL
        // but NOT supported by MySQL. On MySQL, NULLs compare distinct in unique
        // indexes, so a full unique index is safe here (manual rows with NULL
        // bucket_hour stay unconstrained).
        //
        // We detect the driver and create the appropriate index.
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            // Partial index: only enforces uniqueness where bucket_hour IS NOT NULL
            DB::statement(
                "CREATE UNIQUE INDEX idx_health_metric_bucket_uniqueness ".
                "ON life_log_health_metrics (user_id, external_service, type, unit, bucket_hour) ".
                "WHERE bucket_hour IS NOT NULL"
            );
        } else {
            // MySQL: full unique index — NULLs are distinct, so manual rows are safe
            // This also serves as the last-resort guard against overlapping rollup runs
            // writing duplicate entries
            DB::statement(
                "CREATE UNIQUE INDEX idx_health_metric_bucket_uniqueness ".
                "ON life_log_health_metrics (user_id, external_service, type, unit, bucket_hour)"
            );
        }
    }

    public function down()
    {
        // Drop the partial/full unique index
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS idx_health_metric_bucket_uniqueness");
        } elseif ($driver === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS idx_health_metric_bucket_uniqueness");
        } else {
            DB::statement("ALTER TABLE life_log_health_metrics DROP INDEX idx_health_metric_bucket_uniqueness");
        }

        Schema::table('life_log_health_metrics', function (Blueprint $table) {
            $table->dropColumn(['source', 'unit', 'external_service', 'bucket_hour', 'metadata']);
        });
    }
};
