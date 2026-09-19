<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The counter's top shelf: real sweets a grown-up hands over.
 *
 * `candies` is what a parent put on the counter — a name, a token price, how
 * many are in the cupboard and a colour for the drawn wrapper (there are no
 * photos yet). Taken off the counter rather than deleted, so an order still
 * names what it was for.
 *
 * `candy_orders` is the Loot Shop's redemption pattern in tokens: the tokens
 * leave the kid's balance on the tap, and the row waits in the parent's queue
 * until it is handed over or refunded. Name, price and colour are copied onto
 * the order so a parent editing the sweet later changes nothing a kid already
 * paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->string('name', 60);
            $table->unsignedSmallInteger('tokens');
            $table->unsignedSmallInteger('stock')->default(0);
            $table->unsignedSmallInteger('hue')->default(330);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('candy_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id')->index();
            $table->unsignedBigInteger('candy_id')->index();
            $table->string('name', 60);
            $table->unsignedSmallInteger('tokens');
            $table->unsignedSmallInteger('hue');
            $table->string('status', 16)->default('waiting');
            $table->unsignedBigInteger('decided_by_profile_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candy_orders');
        Schema::dropIfExists('candies');
    }
};
