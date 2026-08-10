<?php

use App\Enums\BossSkin;
use App\Enums\MonsterHitKind;
use App\Enums\MonsterTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves each household's single family goal onto the new arena as its tier 3
 * monster, and rebuilds the trophy shelf from `boss_defeats`.
 *
 * Tier 3 because the goal that exists today is the long game — a saved-up
 * family reward measured in thousands of points. Tiers 1 and 2 start empty and
 * are spawned by a parent naming what they pay out, which is the only person
 * who can say what an ice cream outing is worth.
 *
 * Nothing is dropped here. `goal_name`, `goal_target` and `goal_now` stay
 * exactly where they are and keep being written by the code that reads them, so
 * a database that has run this migration behaves precisely as it did before —
 * the new tables simply sit alongside, unread, until the arena is wired up.
 * They come out in a later migration once nothing reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('households')->orderBy('id')->chunkById(100, function ($households) {
            foreach ($households as $household) {
                $this->shelveDefeats($household);
                $this->standUpCurrentGoal($household);
            }
        });
    }

    /**
     * Every monster this family has already put down, as tier 3 rows.
     *
     * Each gets one `Adjust` hit for its full health so the bar reads finished.
     * Its leaderboard rides along in the `contributions` snapshot it was already
     * carrying rather than being rebuilt from ledger archaeology — that snapshot
     * is the record, and the live numbers behind it were zeroed the day the next
     * goal started.
     */
    private function shelveDefeats(object $household): void
    {
        $defeats = DB::table('boss_defeats')
            ->where('household_id', $household->id)
            ->orderBy('battle')
            ->get();

        foreach ($defeats as $defeat) {
            if ($this->alreadyStanding($household, $defeat->battle)) {
                continue;
            }

            $monsterId = DB::table('monsters')->insertGetId([
                'household_id' => $household->id,
                'tier' => MonsterTier::Three->value,
                'battle' => $defeat->battle,
                'skin' => $defeat->boss_key,
                'reward_name' => $defeat->goal_name ?: 'Family goal',
                'reward_cost_cents' => null,
                'max_health' => $defeat->health,
                'weak_chore_id' => null,
                'weak_rotated_on' => null,
                'started_at' => $defeat->started_at ?? $defeat->defeated_at,
                'defeated_at' => $defeat->defeated_at,
                'finisher_profile_id' => $defeat->finisher_profile_id,
                'contributions' => $defeat->contributions,
                'created_at' => $defeat->created_at,
                'updated_at' => $defeat->updated_at,
            ]);

            DB::table('monster_hits')->insert([
                'household_id' => $household->id,
                'monster_id' => $monsterId,
                'chore_completion_id' => null,
                'profile_id' => null,
                'damage' => $defeat->health,
                'kind' => MonsterHitKind::Adjust->value,
                'created_at' => $defeat->defeated_at,
                'updated_at' => $defeat->defeated_at,
            ]);
        }
    }

    /**
     * The goal currently being fought over, as the live tier 3 monster.
     *
     * Skipped when the family has no target set — a household with nothing to
     * fight over has no monster, the same rule the arena has always followed.
     *
     * Also skipped when the current battle is already on the shelf: a beaten
     * boss stays standing, KO'd, until a parent starts the next goal, so that
     * household's `boss_battle` already belongs to a defeat row and standing a
     * second monster up on it would collide with it.
     */
    private function standUpCurrentGoal(object $household): void
    {
        $battle = (int) ($household->boss_battle ?: 1);

        if ((int) $household->goal_target <= 0) {
            return;
        }

        if ($this->alreadyStanding($household, $battle)) {
            return;
        }

        $monsterId = DB::table('monsters')->insertGetId([
            'household_id' => $household->id,
            'tier' => MonsterTier::Three->value,
            'battle' => $battle,
            'skin' => $household->boss_key ?: BossSkin::default()->value,
            'reward_name' => $household->goal_name ?: 'Family goal',
            'reward_cost_cents' => null,
            'max_health' => $household->goal_target,
            'weak_chore_id' => null,
            'weak_rotated_on' => null,
            'started_at' => $household->boss_started_at ?? $household->created_at ?? now(),
            'defeated_at' => null,
            'finisher_profile_id' => null,
            'contributions' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedDamage($household, $monsterId);
    }

    /**
     * Whether tier 3 already holds this battle.
     *
     * Every insert here is guarded by it, which makes the whole migration safe
     * to run a second time. That matters more than it looks: a family whose
     * boss was beaten but not yet replaced gets nothing stood up, and then
     * starts the next goal an hour later — so the arena has to be able to come
     * back for whatever it left behind, without duplicating the shelf.
     *
     * It also covers the ordinary collision: a beaten boss stays standing until
     * a parent starts the next goal, so that household's current battle number
     * already belongs to a defeat row.
     */
    private function alreadyStanding(object $household, int $battle): bool
    {
        return DB::table('monsters')
            ->where('household_id', $household->id)
            ->where('tier', MonsterTier::Three->value)
            ->where('battle', $battle)
            ->exists();
    }

    /**
     * Rebuilds `goal_now` as hits, so the migrated monster's health and its
     * leaderboard come out of the same table everything else will.
     *
     * Each kid's `goal_contribution` becomes a hit in their name — that column
     * is already the authoritative per-kid split, and re-deriving it from the
     * ledger would only find a different answer. Whatever `goal_now` holds
     * beyond the sum of those (a parent's hand nudge, a chore rolled back by
     * the daily reset) becomes one unattributed `Adjust`, which is what it
     * honestly is.
     */
    private function seedDamage(object $household, int $monsterId): void
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
     * The household goal columns were never touched, so the arena's tables are
     * the only thing this created and emptying them puts the database back.
     */
    public function down(): void
    {
        DB::table('monster_hits')->delete();
        DB::table('monsters')->delete();
    }
};
