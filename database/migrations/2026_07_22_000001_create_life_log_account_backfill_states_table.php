<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_account_backfill_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('connected_account_id');
            $table->string('type', 64);
            $table->timestamp('backfilled_to');
            $table->text('cursor')->nullable();
            $table->timestamp('cursor_since')->nullable();
            $table->timestamp('cursor_until')->nullable();
            $table->timestamp('complete_at')->nullable();
            $table->timestamp('completeness_determined_at')->nullable();
            $table->unsignedInteger('requests_used')->default(0);
            $table->string('last_error_kind', 32)->nullable();
            $table->timestamps();

            // One backfill state per connected account + measurement type.
            // Named explicitly: the generated name exceeds MySQL's 64-char limit.
            $table->unique(
                ['connected_account_id', 'type'],
                'life_log_backfill_states_account_type_unique'
            );
            // Sweep finds incomplete backfills
            $table->index('complete_at');

            $table->foreign('connected_account_id')
                ->references('id')
                ->on('life_log_connected_accounts')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_account_backfill_states');
    }
};
