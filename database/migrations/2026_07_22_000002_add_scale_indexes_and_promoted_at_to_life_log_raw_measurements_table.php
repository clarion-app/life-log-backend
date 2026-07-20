<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online DDL on MariaDB 10.6+ — not run by the agent (constitution §V).
 *
 * Adds the scale index for per-type range queries, drops the bucket_hour
 * single-column index (redundant with the composite), and adds promoted_at
 * for retention-prune eligibility on raw measurements.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('life_log_raw_measurements', function (Blueprint $table) {
            // Per-type range query pattern for backfill and scale reads
            $table->index(['user_id', 'type', 'recorded_at']);
            // Retention prune: only promoted raw measurements are eligible
            $table->timestamp('promoted_at')->nullable();
            $table->index('promoted_at');

            // Drop the single-column bucket_hour index — the composite
            // (user_id, bucket_hour) already covers the rollup read pattern,
            // and the new (user_id, type, recorded_at) covers backfill scans.
            $table->dropIndex('life_log_raw_measurements_bucket_hour_index');
        });
    }

    public function down()
    {
        Schema::table('life_log_raw_measurements', function (Blueprint $table) {
            $table->dropIndex('life_log_raw_measurements_user_id_type_recorded_at_index');
            $table->dropColumn('promoted_at');
            $table->dropIndex('life_log_raw_measurements_promoted_at_index');
            $table->index('bucket_hour');
        });
    }
};
