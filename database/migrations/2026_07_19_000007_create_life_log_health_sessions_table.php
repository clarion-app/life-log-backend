<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_health_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('external_service', 64);
            $table->string('external_id', 191);
            $table->string('session_type', 64);
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->json('summary_values');
            // Provenance. Deliberately no default: every row here originates from an
            // external service, so a 'manual' default would mislabel imported sessions
            // as hand-entered. The promoter assigns this from the raw row's
            // external_service.
            $table->string('source', 64);
            $table->timestamps();
            $table->softDeletes();

            // One permanent record per external session
            $table->unique(['external_service', 'external_id']);
            $table->index(['user_id', 'started_at']);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_health_sessions');
    }
};
