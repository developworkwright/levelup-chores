<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arcade tokens: what every game pays, and what the prize counter takes.
 *
 * `token_entries` is the source of truth and `profiles.arcade_tokens` a cache
 * kept in step with it, the same arrangement as bonus tickets. `day` is the
 * household day an entry landed on, because the daily cap is a question about
 * one household day and asking it of `created_at` would put a run at 1am on
 * the wrong one — see HouseholdClock.
 *
 * `pet_prizes` is what a kid has bought for their pet off the counter, one row
 * each, kept forever. Which one is out is on the profile (`pet_snack`,
 * `pet_toy`, `pet_bed`), because there is exactly one of each in use at a time
 * and a column says that without a "worn" flag that could be set twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id');
            $table->string('kind', 24);
            $table->integer('amount');
            $table->string('description');
            $table->string('game', 32)->nullable();
            $table->date('day');
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'day']);
            $table->index(['related_type', 'related_id']);
        });

        Schema::create('pet_prizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id');
            $table->string('slot', 12);
            $table->string('key', 32);
            $table->unsignedSmallInteger('tokens_paid');
            $table->timestamps();

            $table->unique(['profile_id', 'slot', 'key']);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedInteger('arcade_tokens')->default(0);
            $table->string('pet_snack', 32)->nullable();
            $table->string('pet_toy', 32)->nullable();
            $table->string('pet_bed', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['arcade_tokens', 'pet_snack', 'pet_toy', 'pet_bed']);
        });

        Schema::dropIfExists('pet_prizes');
        Schema::dropIfExists('token_entries');
    }
};
