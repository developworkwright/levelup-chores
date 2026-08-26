<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per hit of the Lucky Block: what was paid, what came out, and
 * whether a grown-up has handed it over yet.
 *
 * Deliberately not a `redemptions` row. A redemption is a points transaction
 * with a refund path — reject one and the points go back — and a Lucky Block
 * hit has neither: it cost tickets, the draw has already happened, and there
 * is nothing to give back that wouldn't also mean un-drawing the prize. Making
 * `store_item_id` nullable to squeeze this in would have put a null check on
 * every line of the redemption flow to save a table.
 *
 * The name and icon are snapshotted because the pool is editable: a parent
 * renaming or deleting a prize must not rewrite what a kid was told they won.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lucky_hits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id')->index();
            // Kept for the "leave the pool until redeemed" rule, which needs to
            // know which prize is still out. Nullable so deleting a prize
            // doesn't take the record of winning it with it.
            $table->unsignedBigInteger('lucky_prize_id')->nullable()->index();
            $table->string('prize_name');
            $table->string('prize_icon', 64)->nullable();
            $table->unsignedInteger('tickets_spent');
            $table->timestamp('won_at');
            // Null until a parent ticks it off. Pending is the absence of this
            // rather than a status column: there are only two states and no
            // third one is coming.
            $table->timestamp('fulfilled_at')->nullable();
            $table->unsignedBigInteger('fulfilled_by_profile_id')->nullable();
            $table->timestamps();

            // The approvals queue's only query.
            $table->index(['household_id', 'fulfilled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lucky_hits');
    }
};
