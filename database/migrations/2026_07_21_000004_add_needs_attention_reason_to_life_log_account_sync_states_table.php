<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('life_log_account_sync_states', function (Blueprint $table) {
            $table->string('needs_attention_reason', 32)->nullable()->after('last_failure_kind');
        });
    }

    public function down(): void
    {
        Schema::table('life_log_account_sync_states', function (Blueprint $table) {
            $table->dropColumn('needs_attention_reason');
        });
    }
};
