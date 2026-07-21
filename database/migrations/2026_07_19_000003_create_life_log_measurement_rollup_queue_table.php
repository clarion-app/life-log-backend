<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_measurement_rollup_queue', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id');
            $table->string('external_service', 64);
            $table->string('type', 64);
            $table->string('unit', 32)->default('');
            $table->timestamp('bucket_hour');
            $table->string('deferred_reason', 32)->nullable();
            $table->timestamps();

            // Re-marking an already-pending bucket is an idempotent no-op upsert.
            // Named explicitly: the generated name exceeds MySQL's 64-char limit.
            $table->unique(
                ['user_id', 'external_service', 'type', 'unit', 'bucket_hour'],
                'life_log_rollup_queue_bucket_unique'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_measurement_rollup_queue');
    }
};
