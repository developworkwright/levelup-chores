<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one reward a kid is saving toward, pinned to the top of their Loot Shop.
 * Nulled rather than cascaded when the item goes away — a parent retiring a
 * reward must not delete the kid, and an empty goal card is a fine resting state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->foreignId('saving_for_store_item_id')
                ->nullable()
                ->after('bonus_tickets')
                ->constrained('store_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saving_for_store_item_id');
        });
    }
};
