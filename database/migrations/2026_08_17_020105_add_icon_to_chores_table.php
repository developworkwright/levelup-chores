<?php

use App\Enums\ChoreIcon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The face a chore card wears, for kids who can't read the name yet.
 *
 * A **key** ('dishes'), never a character. The drawing can then be redrawn, or
 * the whole set restyled, without a data migration — see {@see ChoreIcon}.
 *
 * Existing chores are backfilled from their names using the same keyword pass
 * that runs on save, so a board built before this shipped arrives with faces
 * already on most of it. Chores whose names match nothing are left null and
 * fall back to the typographic face; guessing at those would put a picture on
 * a card that a pre-reader would then choose *by* that picture.
 *
 * The keyword list is read from the enum rather than copied here, which is the
 * opposite of this project's usual migration rule. It is deliberate: this
 * backfill is a convenience, not a record of what happened. If the words later
 * change, a re-run landing on today's answers rather than August's costs
 * nothing — where a *payout* migration doing the same would rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->string('icon', 32)->nullable()->after('hint');
        });

        foreach (DB::table('chores')->select('id', 'name')->get() as $chore) {
            $icon = ChoreIcon::forName($chore->name);

            if ($icon) {
                DB::table('chores')->where('id', $chore->id)->update(['icon' => $icon->value]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
