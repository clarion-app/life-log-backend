<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('life_log_account_authorizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('connected_account_id');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->unsignedInteger('credential_version');
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->foreign('connected_account_id')
                ->references('id')
                ->on('life_log_connected_accounts')
                ->onDelete('cascade');
            $table->unique('connected_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('life_log_account_authorizations');
    }
};
