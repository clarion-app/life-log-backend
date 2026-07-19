<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_raw_measurements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id');
            $table->string('external_service', 64);
            $table->string('external_id', 191);
            $table->string('type', 64);
            $table->decimal('value', 16, 4);
            $table->string('unit', 32)->default('');
            $table->timestamp('recorded_at');
            $table->timestamp('bucket_hour');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // FR-004 dedup; required for upsert() to update rather than duplicate
            $table->unique(['external_service', 'external_id']);
            // Rollup's grouped read and FR-006 time-range access pattern
            $table->index(['user_id', 'bucket_hour']);
            // Retention prune scan
            $table->index('bucket_hour');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_raw_measurements');
    }
};
