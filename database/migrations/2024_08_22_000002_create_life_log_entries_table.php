<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('title');
            $table->text('content');
            $table->timestamp('entry_date');
            $table->uuid('location_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('life_log_locations')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_entries');
    }
};