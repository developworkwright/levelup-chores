<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The things a kid has starred.
 *
 * Half of "favorites". The other half needs no table at all: what they have
 * actually *bought* is already in `redemptions`, and counting it is a better
 * signal than anything they'd tell you — a star says what they are dreaming
 * about, a repeat purchase says what really moves them. Both pin to the top of
 * the shop; only one of them is worth storing.
 *
 * Per kid rather than per household. Two siblings wanting different things is
 * the normal case, and a shared list would let one of them curate the other's
 * shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loot_favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id')->index();
            $table->unsignedBigInteger('store_item_id')->index();
            $table->timestamp('created_at')->nullable();

            // A second tap unstars rather than adding a duplicate, and the
            // index is what makes that safe against a double tap racing itself.
            $table->unique(['profile_id', 'store_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loot_favorites');
    }
};
