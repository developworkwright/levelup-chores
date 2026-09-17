<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each kid owns. Owned is forever and swapping is free — the ticket buys
 * the item, never the wearing of it — so this is written once, on purchase, and
 * never deleted.
 *
 * Free house items (cost 0) have no row: everybody owns those.
 *
 * `tickets_paid` is the price on the day, since a grown-up can reprice an item
 * afterwards. The unique index is the "can't buy it twice" rule in the schema,
 * so a double tap lands on a constraint rather than on a second charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owned_cosmetics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id')->index();
            $table->unsignedBigInteger('cosmetic_id')->index();
            $table->unsignedSmallInteger('tickets_paid');
            $table->timestamps();

            $table->unique(['profile_id', 'cosmetic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owned_cosmetics');
    }
};
