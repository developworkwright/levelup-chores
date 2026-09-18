<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surprise eggs: bought with tickets in the Locker, cracked one approved chore
 * at a time, and hatched into a pet that was never in the shop — one from the
 * household's egg-only pool (cosmetics with stock 'egg').
 *
 * One row per egg a kid buys. While `hatched_at` is null the egg is out on the
 * kid's pages in place of their pet; a kid has at most one of those at a time.
 * `revealed_at` is when the kid first saw it hatch, so the hatching plays once.
 *
 * `hatched_cosmetic_id` is the pet it became. The egg's price is kept here,
 * the way owned_cosmetics keeps what a pet cost, since the price can change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_eggs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id')->index();
            $table->unsignedSmallInteger('tickets_paid');
            $table->unsignedTinyInteger('cracks')->default(0);
            $table->unsignedBigInteger('hatched_cosmetic_id')->nullable()->index();
            $table->timestamp('hatched_at')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_eggs');
    }
};
