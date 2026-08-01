<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perks become things a kid owns rather than things that fire on purchase.
 * Buying a wheel respin now puts one in their pocket to spend when it suits
 * them — which is also what lets a chest hand one out directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owned_perks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            // The effect itself, not a bonus_perks id: a parent disabling or
            // repricing a perk must not invalidate one already owned.
            $table->string('effect');
            $table->string('source');
            $table->timestamp('acquired_at');
            $table->timestamp('consumed_at')->nullable();

            $table->index(['profile_id', 'consumed_at']);
        });

        Schema::create('daily_chests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->date('chest_date');
            $table->string('reward_kind');
            $table->unsignedInteger('reward_amount')->default(0);
            // Only set when reward_kind is 'perk'.
            $table->string('reward_effect')->nullable();
            $table->boolean('quest_was_done')->default(false);
            $table->timestamp('created_at');

            // One chest per kid per household-day.
            $table->unique(['profile_id', 'chest_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_chests');
        Schema::dropIfExists('owned_perks');
    }
};
