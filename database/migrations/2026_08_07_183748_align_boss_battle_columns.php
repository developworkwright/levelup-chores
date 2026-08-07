<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs a database that ran the boss battle migrations while they still
 * identified a battle by its start timestamp.
 *
 * Battle identity moved from `boss_started_at` to a counter — two battles can
 * begin in the same second, and a clock reading that can tie is not an
 * identifier. The original migrations were updated in place to match, which is
 * fine for a database that had not run them yet and useless for one that had:
 * the migration was already recorded, so the new columns were never created.
 *
 * Every change is guarded, so this is a no-op on a database built from the
 * corrected migrations and a repair on one built from the earlier version.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('households', 'boss_battle')) {
            Schema::table('households', function (Blueprint $table) {
                $table->unsignedInteger('boss_battle')->default(1)->after('boss_started_at');
            });
        }

        if (! Schema::hasColumn('profiles', 'boss_battle_seen')) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->unsignedInteger('boss_battle_seen')->nullable()->after('boss_damage_seen');
            });
        }

        // The timestamp it replaces. Only ever held "which battle has this kid
        // watched", so dropping it costs one replay at worst.
        if (Schema::hasColumn('profiles', 'boss_battle_seen_at')) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->dropColumn('boss_battle_seen_at');
            });
        }

        if (! Schema::hasColumn('boss_defeats', 'battle')) {
            Schema::table('boss_defeats', function (Blueprint $table) {
                $table->unsignedInteger('battle')->default(1)->after('boss_name');
                $table->unique(['household_id', 'battle']);
            });
        }
    }

    public function down(): void
    {
        // Deliberately not reversible. Its whole purpose is to bring a schema
        // back in line with migrations that already claim to have run, so
        // undoing it would recreate the mismatch it exists to fix.
    }
};
