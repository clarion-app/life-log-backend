<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('life_log_connection_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('external_service', 64);
            $table->char('state_hash', 64)->unique();
            $table->string('redirect_uri', 512);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('expires_at');
            $table->index(['user_id', 'external_service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('life_log_connection_attempts');
    }
};
