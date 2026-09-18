<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An egg is one particular pet now.
 *
 * Every egg-only pet is its own egg in the shop, in its own colour, and the
 * kid picks one without knowing what is inside. `cosmetic_id` is the pet in
 * this egg, fixed when it is bought. Unique, because each egg — and the pet in
 * it — exists once in the house: the first kid to buy it has it, and it is
 * gone from the shop for everybody. The unique index is that rule in the
 * schema, so two siblings tapping the same egg in the same second cannot both
 * end up with it.
 *
 * Nullable only for any egg bought before this, which hatches as it always
 * did — into whatever egg pet is left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_eggs', function (Blueprint $table) {
            $table->unsignedBigInteger('cosmetic_id')->nullable()->unique()->after('profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('pet_eggs', function (Blueprint $table) {
            $table->dropUnique(['cosmetic_id']);
            $table->dropColumn('cosmetic_id');
        });
    }
};
