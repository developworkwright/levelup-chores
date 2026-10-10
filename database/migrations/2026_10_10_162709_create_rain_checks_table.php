<?php

use App\Enums\PerkEffect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Rain Check: a kid who lands a boost on something they can't do right
 * now — pulling weeds at 9pm — saves it for tomorrow instead of losing it.
 *
 * Its own table rather than a column on `spins`, because the boost has to
 * outlive the spin: a kid can bank a boost and then respin for another one
 * today, and a respin deletes the spin row.
 *
 * One per kid per day (`for_date`), enforced by the unique index. Banking a
 * second boost the same evening replaces the first rather than adding to it.
 * `spin_id` is the spin it was saved from, which is how today's wheel knows
 * its own boost has been put away; the spin can be gone by tomorrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rain_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('spin_id')->index();
            $table->unsignedBigInteger('chore_id');
            $table->unsignedTinyInteger('multiplier');
            $table->date('for_date');
            $table->timestamps();

            $table->unique(['profile_id', 'for_date']);
        });

        // PerkEffect::defaults() seeds new households; existing ones only get
        // the row from here, and BonusPerkCatalogTest fails if any household
        // is left without one.
        $defaults = PerkEffect::RainCheck->defaults();

        $rows = DB::table('households')->pluck('id')->map(fn (int $id) => [
            'household_id' => $id,
            'effect' => PerkEffect::RainCheck->value,
            ...$defaults,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('bonus_perks')->insert($rows);
        }
    }

    /**
     * Held rain checks become Wheel Respins rather than being deleted — the
     * same reason the OP Spin's own migration gives: the effect columns cast
     * to PerkEffect and throw on a value the enum no longer has.
     */
    public function down(): void
    {
        DB::table('owned_perks')->where('effect', PerkEffect::RainCheck->value)->update(['effect' => PerkEffect::WheelRespin->value]);
        DB::table('daily_chests')->where('reward_effect', PerkEffect::RainCheck->value)->update(['reward_effect' => PerkEffect::WheelRespin->value]);
        DB::table('bonus_perks')->where('effect', PerkEffect::RainCheck->value)->delete();

        Schema::dropIfExists('rain_checks');
    }
};
