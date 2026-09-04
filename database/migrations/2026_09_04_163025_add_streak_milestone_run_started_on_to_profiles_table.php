<?php

use App\Services\StreakService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which run the milestone high-water mark was paid to.
 *
 * `streak_milestone_paid_through` shipped as a *lifetime* mark, and that made
 * every chest a once-ever prize by accident. A kid who opened a 7-day chest,
 * lost the run and built it back to seven got nothing at day 3, day 5 or day 7
 * the second time round — and because the track draws `reached` straight off
 * the streak number, all three chests rendered as already opened. No chest, no
 * ledger line, and a screen insisting they had collected it.
 *
 * The mark itself is still needed: it is what stops a kid lapsing a streak and
 * buying a repair to collect every milestone a second time. So it is scoped to
 * a run rather than dropped, and the run's *start date* is what identifies it.
 * A repair splices the missed night back in, which leaves the restored run
 * starting on the same date it always did — so it keeps its mark, and the
 * exploit stays closed. A genuinely new run starts later, and gets a clean one.
 *
 * **The backfill only touches dead runs.** A profile on `streak = 0` has no run
 * to re-collect anything for, so clearing its mark is safe and is what lets the
 * next run pay from day 1. A profile mid-run is ambiguous — the mark might well
 * have been earned in the run it is standing in — so the column is left null and
 * {@see StreakService::refreshStreak()} adopts the current run
 * rather than resetting it. Inventing a second payout is the worse of the two
 * mistakes to make with money.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so a throw between the column and the backfill can't leave a
        // database holding the column with nothing recorded to roll back.
        if (! Schema::hasColumn('profiles', 'streak_milestone_run_started_on')) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->date('streak_milestone_run_started_on')->nullable()->after('streak_milestone_paid_through');
            });
        }

        DB::table('profiles')->where('streak', 0)->update([
            'streak_milestone_paid_through' => 0,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('profiles', 'streak_milestone_run_started_on')) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->dropColumn('streak_milestone_run_started_on');
            });
        }
    }
};
