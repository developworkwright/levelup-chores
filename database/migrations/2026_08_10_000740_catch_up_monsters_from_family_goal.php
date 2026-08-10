<?php

use App\Enums\BossSkin;
use App\Enums\MonsterHitKind;
use App\Enums\MonsterTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stands the current family goal up as a monster, for any household that hasn't
 * got one — the last thing to run before the arena goes on screen.
 *
 * The seed migration did this once already. It has to happen a second time
 * because of the gap between the two: a family whose boss was beaten and not
 * yet replaced had nothing to stand up that day, and started their next goal an
 * hour later. Everyone else has been earning against `goal_now` in the
 * meantime, which is still the number on their screen, so this is also what
 * carries that progress across rather than restarting the fight at zero on the
 * morning the arena appears.
 *
 * Levels 1 and 2 are deliberately left empty. What an ice cream outing is worth
 * is not a thing a migration can know, and a monster with a made-up reward on it
 * is worse than a slot that says "name one".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('households')->orderBy('id')->chunkById(100, function ($households) {
            foreach ($households as $household) {
                $this->standUp($household);
            }
        });
    }

    private function standUp(object $household): void
    {
        if ((int) $household->goal_target <= 0) {
            return;
        }

        $alreadyStanding = DB::table('monsters')
            ->where('household_id', $household->id)
            ->where('tier', MonsterTier::Three->value)
            ->whereNull('defeated_at')
            ->exists();

        if ($alreadyStanding) {
            return;
        }

        // Counted on from the tier's own history rather than from
        // `households.boss_battle`. The monsters table owns battle numbering
        // now, and the two have already drifted for any family that started a
        // goal after the first migration ran.
        $battle = (int) DB::table('monsters')
            ->where('household_id', $household->id)
            ->where('tier', MonsterTier::Three->value)
            ->max('battle');

        $monsterId = DB::table('monsters')->insertGetId([
            'household_id' => $household->id,
            'tier' => MonsterTier::Three->value,
            'battle' => $battle + 1,
            'skin' => $household->boss_key ?: BossSkin::default()->value,
            'reward_name' => $household->goal_name ?: 'Family goal',
            'reward_cost_cents' => null,
            'max_health' => $household->goal_target,
            'weak_chore_id' => null,
            'weak_rotated_on' => null,
            'started_at' => $household->boss_started_at ?? now(),
            'defeated_at' => null,
            'finisher_profile_id' => null,
            'contributions' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->carryProgressOver($household, $monsterId);
    }

    /**
     * Whatever the family had already earned against this goal, landed as hits
     * so the monster appears already wounded rather than untouched.
     *
     * Each kid's `goal_contribution` keeps their name on it; the difference
     * between that and `goal_now` becomes one unattributed adjustment, which is
     * honestly what it is.
     */
    private function carryProgressOver(object $household, int $monsterId): void
    {
        $now = now();

        $contributions = DB::table('profiles')
            ->where('household_id', $household->id)
            ->where('role', 'kid')
            ->where('goal_contribution', '>', 0)
            ->get(['id', 'goal_contribution']);

        $rows = $contributions->map(fn (object $kid) => [
            'household_id' => $household->id,
            'monster_id' => $monsterId,
            'chore_completion_id' => null,
            'profile_id' => $kid->id,
            'damage' => (int) $kid->goal_contribution,
            'kind' => MonsterHitKind::Hit->value,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        $remainder = (int) $household->goal_now - (int) $contributions->sum('goal_contribution');

        if ($remainder !== 0) {
            $rows[] = [
                'household_id' => $household->id,
                'monster_id' => $monsterId,
                'chore_completion_id' => null,
                'profile_id' => null,
                'damage' => $remainder,
                'kind' => MonsterHitKind::Adjust->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('monster_hits')->insert($rows);
        }
    }

    /**
     * Deliberately not reversible. The family goal columns it read are still
     * sitting there untouched, so nothing here is the only copy of anything —
     * and guessing which monsters a rollback ought to delete would risk taking
     * a real fight down with them.
     */
    public function down(): void {}
};
