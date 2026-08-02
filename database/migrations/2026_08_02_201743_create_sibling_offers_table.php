<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kid-to-kid deals for one-off favours ("play a game with me for 30 min — 100
 * points"). The favour is different every time, so it is free text rather than
 * anything catalogued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sibling_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('to_profile_id')->constrained('profiles')->cascadeOnDelete();
            // Which side of the deal the sender is on, so both "do this and
            // I'll pay you" and "pay me and I'll do this" fit one table.
            // Whether the row holds escrowed points follows from this — only
            // 'paying' debits at offer time — so there is no separate flag to
            // fall out of step with it.
            $table->string('kind');
            $table->string('description');
            $table->unsignedInteger('points');
            $table->string('status');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['to_profile_id', 'status']);
            $table->index(['from_profile_id', 'status']);
            // Drives the lazy expiry sweep, which runs per household.
            $table->index(['household_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sibling_offers');
    }
};
