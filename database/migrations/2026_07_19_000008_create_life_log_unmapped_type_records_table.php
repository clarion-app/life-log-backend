<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // No user scoping: this records that *a service* emits a type life-log
        // cannot map, which is a property of the service, not of a user.
        Schema::create('life_log_unmapped_type_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('external_service', 64);
            $table->string('service_type_name', 191);
            $table->string('sample_value', 64)->nullable();
            $table->string('sample_unit', 32)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedBigInteger('occurrence_count')->default(1);
            $table->timestamps();

            // Upsert key: one row per (service, type name).
            // Named explicitly: the generated name exceeds MySQL's 64-char limit.
            $table->unique(
                ['external_service', 'service_type_name'],
                'life_log_unmapped_types_service_name_unique'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_unmapped_type_records');
    }
};
