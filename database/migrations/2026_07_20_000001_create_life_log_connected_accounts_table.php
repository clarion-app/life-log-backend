<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_connected_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('external_service', 64);
            $table->string('sync_state', 32)->default('normal');
            $table->timestamp('connected_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            // One connected account per user + service
            $table->unique(['user_id', 'external_service']);
            // Sweep scans for due accounts by state
            $table->index('sync_state');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_connected_accounts');
    }
};
