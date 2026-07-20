<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('life_log_service_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_service', 64);
            $table->string('client_id', 255);
            $table->text('client_secret');
            $table->string('redirect_uri', 512);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('secret_updated_at')->useCurrent();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_verification_outcome', 32)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['external_service', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('life_log_service_credentials');
    }
};
