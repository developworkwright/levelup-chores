<?php

use App\Enums\PerkEffect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Name a Monster perk: somewhere to put the name, and its catalogue row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monsters', function (Blueprint $table) {
            // What a kid called it, shown instead of the skin's own name. Per
            // monster rather than per skin: the skin catalogue is shared by
            // every household, and one family's "Barry" must not turn up in
            // somebody else's arena.
            $table->string('nickname', 24)->nullable()->after('skin');
        });

        $defaults = PerkEffect::NameMonster->defaults();

        $rows = DB::table('households')->pluck('id')->map(fn (int $id) => [
            'household_id' => $id,
            'effect' => PerkEffect::NameMonster->value,
            'name' => $defaults['name'],
            'description' => $defaults['description'],
            'cost' => $defaults['cost'],
            'glyph' => $defaults['glyph'],
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('bonus_perks')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::table('monsters', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });

        DB::table('bonus_perks')->where('effect', PerkEffect::NameMonster->value)->delete();
    }
};
