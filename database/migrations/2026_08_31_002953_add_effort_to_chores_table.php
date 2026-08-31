<?php

use App\Enums\ChoreIcon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much work a chore actually is — the board's Muscle chip.
 *
 * The one axis of the side-quest filters that can't be derived from what is
 * already stored. Price comes off `points`, "done before" off
 * `chore_completions`, and the kind of job (and whether it's outdoors) off
 * `icon` — but scrubbing a bathroom is hard work behind an indoor icon, and
 * weed whacking is the case that proves it.
 *
 * **Deliberately not backfilled.** Every other column added to this table with
 * a keyword pass ({@see ChoreIcon}) was guessing at a *picture*, where being
 * wrong costs a kid a moment's confusion. Being wrong here sends a six-year-old
 * at a job he can't finish, so null — "nobody has said" — is the honest
 * starting value for every existing chore, and a parent fills it in from the
 * Chores admin one chore at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->string('effort', 16)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('effort');
        });
    }
};
