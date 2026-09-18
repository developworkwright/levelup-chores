<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pets grow up: baby, young, adult — see App\Enums\PetStage.
 *
 * The art is one full twelve-pose sheet per stage, because a puppy is not a
 * small dog. `art_path` stays the adult, which is what every pet already
 * uploaded is; the two younger sheets are optional, and a stage without its own
 * falls back to the next one up, drawn smaller.
 *
 * Growth lives on the kid's `owned_cosmetics` row rather than on the pet or the
 * profile, so it belongs to that kid's copy of that pet: putting a different pet
 * out and coming back later finds this one exactly as big as it was left, and a
 * traded pet — the row moves — arrives as grown as it left. A pet always has a
 * row, because the console never lets one be free.
 *
 * Pets already owned start at nothing, which makes them babies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->string('baby_art_path')->nullable()->after('art_path');
            $table->string('young_art_path')->nullable()->after('baby_art_path');
        });

        Schema::table('owned_cosmetics', function (Blueprint $table) {
            $table->unsignedInteger('growth')->default(0)->after('tickets_paid');
        });
    }

    public function down(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->dropColumn(['baby_art_path', 'young_art_path']);
        });

        Schema::table('owned_cosmetics', function (Blueprint $table) {
            $table->dropColumn('growth');
        });
    }
};
