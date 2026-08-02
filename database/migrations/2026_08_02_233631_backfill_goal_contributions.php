<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Households that were already part-way through a family goal when
 * `goal_contribution` was added have a filled progress bar and an MVP board of
 * zeroes under it — which reads as broken rather than as "not measured yet".
 *
 * The points that fed those goals were never attributed to anyone, so there is
 * nothing to recover exactly. This apportions the progress already banked by
 * each kid's share of approved chore points, which is the only evidence of who
 * did the work that exists. It is an estimate, made once, and every point after
 * this migration is credited for real by ChoreService::approve().
 *
 * Shares are floored and the rounding remainder handed to the biggest earner,
 * so the contributions add up to exactly `goal_now` and the board can't total
 * more than the bar it sits under.
 */
return new class extends Migration
{
    public function up(): void
    {
        $households = DB::table('households')->where('goal_now', '>', 0)->get();

        foreach ($households as $household) {
            $earned = DB::table('chore_completions')
                ->join('profiles', 'profiles.id', '=', 'chore_completions.profile_id')
                ->where('profiles.household_id', $household->id)
                ->where('profiles.role', 'kid')
                ->where('chore_completions.status', 'approved')
                ->groupBy('chore_completions.profile_id')
                ->orderByDesc('points')
                ->get([
                    'chore_completions.profile_id',
                    DB::raw('SUM(chore_completions.points_awarded) as points'),
                ]);

            $total = (int) $earned->sum('points');

            // No approved chores at all means the progress came from a parent
            // typing it in. Splitting that between kids would invent a race
            // nobody ran, so it stays unattributed.
            if ($total < 1) {
                continue;
            }

            $goalNow = (int) $household->goal_now;
            $assigned = 0;

            foreach ($earned as $row) {
                $share = (int) floor($goalNow * $row->points / $total);
                $assigned += $share;

                DB::table('profiles')
                    ->where('id', $row->profile_id)
                    ->update(['goal_contribution' => $share]);
            }

            // Ordered by earnings, so the remainder lands on the top contributor.
            DB::table('profiles')
                ->where('id', $earned->first()->profile_id)
                ->increment('goal_contribution', $goalNow - $assigned);
        }
    }

    /**
     * Nothing to reverse: the figures written here were derived, not moved, so
     * there is no prior state to restore. Dropping the column undoes it.
     */
    public function down(): void {}
};
