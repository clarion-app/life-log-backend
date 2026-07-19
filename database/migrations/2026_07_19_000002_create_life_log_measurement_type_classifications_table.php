<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_measurement_type_classifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type', 64)->unique();
            $table->string('aggregation', 16);
            $table->timestamps();
        });

        // Seed initial test classifications.
        // The canonical vocabulary arrives in Phase 1.2 (FR-013).
        DB::table('life_log_measurement_type_classifications')->insert([
            ['type' => 'steps', 'aggregation' => 'cumulative', 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'heart_rate', 'aggregation' => 'point_in_time', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('life_log_measurement_type_classifications');
    }
};
