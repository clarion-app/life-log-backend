<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen value from decimal(8,2) to decimal(16,4) (FR-016).
     *
     * Kept in its own migration so a failed widening can be diagnosed and
     * retried without re-running the additive step. Widening-only, so no
     * stored value can change (SC-008). Laravel 11 alters columns natively.
     */
    public function up()
    {
        Schema::table('life_log_health_metrics', function (Blueprint $table) {
            $table->decimal('value', 16, 4)->change();
        });
    }

    public function down()
    {
        Schema::table('life_log_health_metrics', function (Blueprint $table) {
            $table->decimal('value', 8, 2)->change();
        });
    }
};
