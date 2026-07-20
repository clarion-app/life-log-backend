<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_account_sync_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('connected_account_id');
            $table->timestamp('synced_through_at')->nullable();
            $table->text('cursor')->nullable();
            $table->timestamp('cursor_since')->nullable();
            $table->timestamp('cursor_until')->nullable();
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_kind', 32)->nullable();
            $table->timestamps();

            // Exactly one state row per connected account (lazy creation)
            $table->unique('connected_account_id');
            // Sweep's due-account query
            $table->index('next_attempt_at');

            $table->foreign('connected_account_id')
                ->references('id')->on('life_log_connected_accounts')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_account_sync_states');
    }
};
