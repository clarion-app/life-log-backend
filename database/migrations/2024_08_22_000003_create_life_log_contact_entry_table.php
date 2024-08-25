<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('life_log_contact_entry', function (Blueprint $table) {
            $table->uuid('contact_id');
            $table->uuid('entry_id');

            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->foreign('entry_id')->references('id')->on('entries')->onDelete('cascade');

            $table->primary(['contact_id', 'entry_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('life_log_contact_entry');
    }
};