<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_raw_health_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id');
            $table->string('external_service', 64);
            $table->string('external_id', 191);
            $table->string('session_type', 64);
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->json('summary_values');
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();

            // Dedup key; required for upsert() to update a revised session in place
            $table->unique(['external_service', 'external_id']);
            // Time-range retrieval
            $table->index(['user_id', 'started_at']);
            // Promotion scan (promoted_at IS NULL) and prune guard (promoted_at IS NOT NULL)
            $table->index('promoted_at');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_raw_health_sessions');
    }
};
