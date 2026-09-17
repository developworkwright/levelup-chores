<?php

use App\Enums\CosmeticSlot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pets: an eighth locker slot, and the one thing in it that moves about.
 *
 * A pet is a twelve-pose sprite sheet a grown-up uploads — idle, blink, crouch,
 * jump, walk, happy, held, landed, play, toss, sleep and its toy. It runs around
 * the kid's own pages, can be petted and picked up, and now and then a
 * sibling's pet comes to visit. One is out at a time; a kid can own any number.
 *
 * `cosmetics.effect` arrives with them and is not only theirs: it is a treatment
 * laid *over* uploaded art — the colours crawling, or the streak's fire burning
 * behind it — which is what makes a twenty-ticket pet worth twenty without
 * needing a second drawing. See App\Enums\CosmeticEffect.
 *
 * The worn column is guarded, because the migration that created the other
 * seven builds its columns by walking CosmeticSlot::cases() — so on a database
 * built from scratch today it has already made this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->string('effect', 16)->nullable()->after('motion');
        });

        if (! Schema::hasColumn('profiles', CosmeticSlot::Pet->wornColumn())) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->unsignedBigInteger(CosmeticSlot::Pet->wornColumn())->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->dropColumn('effect');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(CosmeticSlot::Pet->wornColumn());
        });
    }
};
