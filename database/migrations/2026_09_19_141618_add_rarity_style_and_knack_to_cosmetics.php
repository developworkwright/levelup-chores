<?php

use App\Enums\CosmeticSlot;
use App\Enums\PetStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A pet's rarity, its style and — Rare and up — its knack. See
 * App\Enums\PetRarity, PetStyle and PetKnack.
 *
 * Null on everything that isn't a pet. Every pet already in a house starts as
 * a Common with a style spread across the four by id, so none is left without
 * one; a grown-up can change either from the console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->string('pet_rarity', 16)->nullable()->after('pet_rig');
            $table->string('pet_style', 16)->nullable()->after('pet_rarity');
            $table->string('pet_knack', 32)->nullable()->after('pet_style');
        });

        DB::table('cosmetics')
            ->where('slot', CosmeticSlot::Pet->value)
            ->orderBy('id')
            ->get(['id'])
            ->each(fn (object $pet) => DB::table('cosmetics')->where('id', $pet->id)->update([
                'pet_rarity' => 'common',
                'pet_style' => PetStyle::startingFor($pet->id)->value,
            ]));
    }

    public function down(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->dropColumn(['pet_rarity', 'pet_style', 'pet_knack']);
        });
    }
};
