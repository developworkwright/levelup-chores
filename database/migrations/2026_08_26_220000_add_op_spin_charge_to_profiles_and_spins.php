<?php

use App\Enums\PerkEffect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The OP Spin: a ticket charges the wheel before it is spun, and the charge
 * buys better odds rather than a better result.
 *
 * Two columns, because the charge and the spin it paid for are different
 * facts with different lifetimes:
 *
 * - **`profiles.op_spin_armed_at`** is the charge waiting to be spent. It sits
 *   on the profile rather than on a spin row because there is no spin row yet
 *   when it is bought — that is the whole point of arming.
 * - **`spins.was_op`** is what the spin was rolled under, kept after the
 *   charge is gone. The wheel needs it to warn that a respin trades an OP
 *   result for an ordinary one, and no other column can still answer that
 *   question once `op_spin_armed_at` has been cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->timestamp('op_spin_armed_at')->nullable()->after('bonus_tickets');
        });

        Schema::table('spins', function (Blueprint $table) {
            $table->boolean('was_op')->default(false)->after('multiplier');
        });

        // PerkEffect::defaults() seeds new households; existing ones only get
        // the row from here, and BonusPerkCatalogTest fails if any household
        // is left without one.
        $defaults = PerkEffect::OpSpin->defaults();

        $rows = DB::table('households')->pluck('id')->map(fn (int $id) => [
            'household_id' => $id,
            'effect' => PerkEffect::OpSpin->value,
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
     * Held charges and chest rewards become Wheel Respins rather than being
     * deleted: `owned_perks.effect` and `daily_chests.reward_effect` both cast
     * to PerkEffect, and a value the enum no longer has throws the moment
     * anything counts them. A kid who spent a ticket keeps something usable.
     */
    public function down(): void
    {
        DB::table('owned_perks')->where('effect', PerkEffect::OpSpin->value)->update(['effect' => PerkEffect::WheelRespin->value]);
        DB::table('daily_chests')->where('reward_effect', PerkEffect::OpSpin->value)->update(['reward_effect' => PerkEffect::WheelRespin->value]);
        DB::table('bonus_perks')->where('effect', PerkEffect::OpSpin->value)->delete();

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('op_spin_armed_at');
        });

        Schema::table('spins', function (Blueprint $table) {
            $table->dropColumn('was_op');
        });
    }
};
