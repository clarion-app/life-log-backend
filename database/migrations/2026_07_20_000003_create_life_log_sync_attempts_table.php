<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_sync_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('connected_account_id');
            $table->uuid('user_id');
            $table->string('external_service', 64);
            $table->string('trigger', 16);
            $table->string('outcome', 16);
            $table->timestamp('range_since');
            $table->timestamp('range_until');
            $table->unsignedInteger('pages_fetched')->default(0);
            $table->unsignedInteger('measurements_written')->default(0);
            $table->unsignedInteger('sessions_written')->default(0);
            $table->string('failure_kind', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Look up recent attempts for an account
            $table->index(['connected_account_id', 'started_at']);
            // Global timeline / retention prune scan
            $table->index('started_at');

            $table->foreign('connected_account_id')
                ->references('id')->on('life_log_connected_accounts')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_sync_attempts');
    }
};
