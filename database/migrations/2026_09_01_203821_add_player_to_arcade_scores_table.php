<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The arcade came in off the street, so the board can carry real names now.
 *
 * The original table was deliberately the thinnest in the schema — a rolled
 * codename, a number, a week, and nothing that said who — because the cabinet
 * sat on `/`, which is world-readable and world-writable. That constraint is
 * the only reason the codenames existed, and it is gone: the arcade lives
 * behind the PIN, so a row can name the person who played.
 *
 * Two columns rather than one. `profile_id` is who, and the board reads the
 * live profile name through it, so a kid renaming themselves renames their
 * scores — the same thing `Quote::attribution()` does. `household_id` is which
 * board, and it is the more important of the two: names on a shared board mean
 * one family could otherwise read another family's, which is exactly the leak
 * the codenames were there to prevent. Every query is scoped to it.
 *
 * Both nullable, because the public-era rows have neither and are not being
 * invented. They keep their codenames and stay readable; where a household can
 * be worked out without guessing — one household in the database, which is the
 * normal case — they are adopted so the all-time record is not reset by this
 * migration. Anything ambiguous is left alone rather than assigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arcade_scores', function (Blueprint $table) {
            $table->unsignedBigInteger('household_id')->nullable()->after('id')->index();
            $table->unsignedBigInteger('profile_id')->nullable()->after('household_id')->index();
        });

        // The weekly board is "this household, this week" now, so the index
        // that served the old global board is the wrong shape by one column.
        Schema::table('arcade_scores', function (Blueprint $table) {
            $table->index(['household_id', 'week', 'score']);
        });

        $households = DB::table('households')->pluck('id');

        if ($households->count() === 1) {
            DB::table('arcade_scores')->whereNull('household_id')->update([
                'household_id' => $households->first(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('arcade_scores', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'week', 'score']);
            $table->dropIndex(['household_id']);
            $table->dropIndex(['profile_id']);
            $table->dropColumn(['household_id', 'profile_id']);
        });
    }
};
