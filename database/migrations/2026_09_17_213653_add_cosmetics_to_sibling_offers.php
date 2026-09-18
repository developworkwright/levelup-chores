<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A limited cosmetic as a side of a sibling trade.
 *
 * Every other thing a kid can put up is an amount of something, so the offer
 * carried two assets and two numbers. An item is not an amount — it is one
 * named row — so each side gains a nullable cosmetic id, used when its asset is
 * TradeAsset::Cosmetic and null otherwise.
 *
 * This is what makes a limited worth chasing. It was on sale for one week ever,
 * so once that week is gone the only way to get one is from the sibling who
 * bought it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sibling_offers', function (Blueprint $table) {
            $table->unsignedBigInteger('give_cosmetic_id')->nullable()->after('give_amount');
            $table->unsignedBigInteger('get_cosmetic_id')->nullable()->after('get_amount');

            // Both indexed: the one question asked on every compose is "is this
            // item already up in a live offer", and it is asked from either side.
            $table->index('give_cosmetic_id');
            $table->index('get_cosmetic_id');
        });
    }

    public function down(): void
    {
        Schema::table('sibling_offers', function (Blueprint $table) {
            $table->dropIndex(['give_cosmetic_id']);
            $table->dropIndex(['get_cosmetic_id']);
            $table->dropColumn(['give_cosmetic_id', 'get_cosmetic_id']);
        });
    }
};
