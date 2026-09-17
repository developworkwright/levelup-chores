<?php

use App\Enums\CosmeticSlot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each kid is wearing: one column per slot, holding a cosmetics id.
 *
 * Seven columns rather than a pivot, because every surface that draws a kid —
 * the login door, the header, the feed, the boards — reads the whole set at
 * once, and seven columns come back with the profile they already loaded.
 *
 * Null means the house default: no frame and the letter tile for the first two,
 * and the app as it already looks for the rest. No index — nothing is ever
 * looked up by what somebody is wearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            foreach (CosmeticSlot::cases() as $slot) {
                $table->unsignedBigInteger($slot->wornColumn())->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(array_map(fn (CosmeticSlot $slot) => $slot->wornColumn(), CosmeticSlot::cases()));
        });
    }
};
