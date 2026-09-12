<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The household day this kid was last told they had powered up.
 *
 * Powering up was built as a *state* — the strip on Home says whether today's
 * extras are on — and a state is something you have to go and look at. The
 * first person to try it did a chore and did not find out until they logged out
 * and back in, which is the whole reward missing its moment.
 *
 * So it becomes an event as well, announced by the shell's quiet-rewards run
 * like a badge or a level. This column is what stops it announcing twice: one
 * card on the day's first chore, and nothing on the four after it.
 *
 * A date rather than a timestamp, because the question is "was this today" and
 * the household day is a date — see HouseholdClock.
 *
 * Null is the right default and does **not** need backfilling here, unlike the
 * seen-at markers it sits beside. Those swallow their first announcement when
 * null because null means "everything before now is unread"; this one means "we
 * have never told them today", and telling a kid who is already powered up that
 * they are powered up is correct rather than a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->date('powered_up_on')->nullable()->after('streak');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('powered_up_on');
        });
    }
};
