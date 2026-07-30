<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "This Week" calendar feature was dropped from scope — it needs a real
 * Google Calendar integration to be worth having, which is out of scope for
 * this project. This table has no other consumer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('calendar_events');
    }

    public function down(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamp('starts_at');
            $table->string('location')->nullable();
            $table->string('color_tag');
            $table->timestamps();
        });
    }
};
