<?php

namespace App\Services;

use App\Enums\BossSkin;
use App\Enums\BossStage;
use App\Enums\LedgerKind;
use App\Models\BossDefeat;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use Illuminate\Support\Collection;

/**
 * The family goal, drawn as a monster.
 *
 * Every number here belongs to the goal: `goal_target` is the boss's health and
 * `goal_now` is the damage the family has done to it. Nothing in this service
 * moves points, mints anything, or writes to `goal_now` — it reads. That is the
 * whole design: a boss that paid out on its own would be a second family goal
 * to keep in step with the first, and the two would drift.
 *
 * ChoreService owns the moment a goal is crossed and calls `recordDefeat()`
 * there; a parent starting the next goal calls `startNewBattle()`.
 */
class BossService
{
    /** How many recent blows the arena feed shows. */
    public const FEED_LIMIT = 8;

    /**
     * Which monster is standing, assigning one if this household has never had
     * a battle. Lazy, in the same way the daily quest and the mystery chore
     * assign themselves — a household created before this feature (or by a
     * factory that knows nothing about it) gets a boss the first time anyone
     * looks, rather than a null the views have to keep guarding.
     *
     * `boss_battle` is seeded here rather than left to the column default,
     * which is the whole reason this writes three fields instead of two. A
     * model that has just been inserted does not carry defaults the database
     * filled in, so an unrefreshed household reads the counter as null — and a
     * battle number that reads 0 on one call and 1 on the next is two battles
     * as far as every guard here is concerned. Seeding it makes the value true
     * in memory and in the row at the same moment.
     */
    public function skinFor(Household $household): BossSkin
    {
        if ($household->boss_key === null
            || $household->boss_started_at === null
            || $household->boss_battle === null) {
            $household->boss_key ??= BossSkin::default();
            $household->boss_started_at ??= now();
            $household->boss_battle ??= 1;
            $household->save();
        }

        return $household->boss_key;
    }

    /**
     * Everything the arena needs to draw itself, or null when there is no goal
     * to fight over — a household with no target set has no monster, and a
     * health bar over zero is a divide by zero waiting to happen.
     *
     * `damage` is the full swing of the blow, while `health` is floored at
     * zero: the last chore before a defeat routinely pays more than the boss
     * has left, and showing a 40-point chore land for 12 reads as the app
     * shortchanging the kid who finished it.
     *
     * @return array{skin: BossSkin, stage: BossStage, name: string, tagline: string,
     *               health: int, maxHealth: int, healthPercent: int, damage: int,
     *               damagePercent: int, taunt: string, defeated: bool}|null
     */
    public function stateFor(Household $household): ?array
    {
        if ($household->goal_target <= 0) {
            return null;
        }

        $skin = $this->skinFor($household);

        $maxHealth = (int) $household->goal_target;
        $damage = min((int) $household->goal_now, $maxHealth);
        $health = max(0, $maxHealth - $damage);
        $healthPercent = $this->healthPercent($damage, $maxHealth);
        $stage = BossStage::fromHealth($healthPercent);

        return [
            'skin' => $skin,
            'stage' => $stage,
            'name' => $skin->label(),
            'tagline' => $skin->tagline(),
            'health' => $health,
            'maxHealth' => $maxHealth,
            'healthPercent' => $healthPercent,
            'damage' => $damage,
            'damagePercent' => 100 - $healthPercent,
            'taunt' => $stage->taunt(),
            'defeated' => $stage->isDefeated(),
        ];
    }

    /**
     * Health left as a percentage, from damage dealt.
     *
     * Shared by the live state and the replay so the two can't disagree about
     * which stage a given number of points puts the boss in. A boss on its last
     * few points must not round up to a bar that looks untouched, and one that
     * is genuinely dead must read as a clean zero.
     */
    private function healthPercent(int $damage, int $maxHealth): int
    {
        if ($maxHealth <= 0) {
            return 0;
        }

        $health = max(0, $maxHealth - min($damage, $maxHealth));
        $percent = (int) round($health / $maxHealth * 100);

        return $health > 0 ? max(1, $percent) : 0;
    }

    /**
     * Every stage between what this kid last watched and where the boss stands
     * now, oldest first — always at least one entry, the current state.
     *
     * The point is that the fight belongs to everyone. Chores are approved all
     * day by a parent looking at a screen no kid is on, so a kid opening the app
     * after school would otherwise find the monster simply *already* half dead:
     * all of the damage, none of the moment. Handing the page the stages it
     * missed lets it play them back before settling on the truth.
     *
     * A kid who has never looked replays nothing — {@see self::markSeen()} seeds
     * their marker silently, the same way the shell seeds `badges_seen_at`, so
     * nobody is met with a five-stage recap of a boss they have been watching
     * all week.
     *
     * @return array<int, array{stage: BossStage, damage: int, health: int,
     *                          damagePercent: int, healthPercent: int, landed: int,
     *                          label: string, taunt: string}>
     */
    public function replayFor(Household $household, Profile $profile): array
    {
        $state = $this->stateFor($household);

        if ($state === null) {
            return [];
        }

        $maxHealth = $state['maxHealth'];
        $step = fn (BossStage $stage, int $damage, int $from) => [
            'stage' => $stage,
            'damage' => $damage,
            'health' => max(0, $maxHealth - $damage),
            'damagePercent' => 100 - $this->healthPercent($damage, $maxHealth),
            'healthPercent' => $this->healthPercent($damage, $maxHealth),
            'landed' => max(0, $damage - $from),
            'label' => $stage->label(),
            'taunt' => $stage->taunt(),
        ];

        $current = $step($state['stage'], $state['damage'], $state['damage']);

        // Same battle or not: a new monster starts everyone at nothing seen, so
        // its first blows are watched rather than skipped.
        $sameBattle = $profile->boss_battle_seen === (int) $household->boss_battle;
        $seenDamage = $sameBattle ? (int) $profile->boss_damage_seen : 0;

        if ($profile->boss_battle_seen === null || $state['damage'] <= $seenDamage) {
            return [$current];
        }

        $stages = BossStage::cases();
        $from = BossStage::fromHealth($this->healthPercent($seenDamage, $maxHealth));
        $fromIndex = (int) array_search($from, $stages, true);
        $toIndex = (int) array_search($state['stage'], $stages, true);

        $steps = [$step($from, $seenDamage, $seenDamage)];
        $previous = $seenDamage;

        for ($i = $fromIndex + 1; $i <= $toIndex; $i++) {
            // Every stage but the last is entered at its own boundary, so the
            // bar visibly stops at each one instead of sliding past it.
            $damage = $i === $toIndex
                ? $state['damage']
                : (int) ceil($maxHealth * $stages[$i]->entryDamagePercent() / 100);

            $steps[] = $step($stages[$i], $damage, $previous);
            $previous = $damage;
        }

        // Damage that didn't cross a boundary still deserves the bar moving —
        // the kid missed the hits either way.
        if ($fromIndex === $toIndex) {
            $steps[] = $step($state['stage'], $state['damage'], $seenDamage);
        }

        return $steps;
    }

    /**
     * Remember where this kid left the fight, so the next visit only replays
     * what happened since.
     *
     * Called by the arena as it renders, *after* the replay has been built —
     * the same compute-then-mark order the kid shell uses for badges and
     * levels, and what makes a replay play exactly once.
     */
    public function markSeen(Household $household, Profile $profile): void
    {
        $battle = (int) $household->boss_battle;
        $damage = min((int) $household->goal_now, (int) $household->goal_target);

        // The arena renders on every round trip and most of them have nothing
        // new to record.
        if ($profile->boss_damage_seen === $damage && $profile->boss_battle_seen === $battle) {
            return;
        }

        $profile->forceFill([
            'boss_damage_seen' => $damage,
            'boss_battle_seen' => $battle,
        ])->save();
    }

    /**
     * The blows landed on the boss currently standing, newest first.
     *
     * Read straight off the ledger rather than stored: an approved chore
     * already writes an `Earn` entry naming the kid, the chore and the points,
     * which is exactly a hit. `boss_started_at` is what keeps the previous
     * monster's fight out of this one's feed.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function hits(Household $household, int $limit = self::FEED_LIMIT): Collection
    {
        $since = $household->boss_started_at ?? $household->created_at;

        return LedgerEntry::with('profile')
            ->where('household_id', $household->id)
            ->where('kind', LedgerKind::Earn)
            ->where('created_at', '>=', $since)
            ->whereNotNull('profile_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Bank the kill. Returns null if this battle has already been recorded.
     *
     * The guard is keyed on the battle counter, which is what identifies a
     * battle — a defeat already carrying the current number means this one is
     * on the shelf. ChoreService only calls this on the crossing itself, so
     * this is belt-and-braces, backed by a unique index in case two approvals
     * ever race for the last few points.
     *
     * Deliberately does *not* rotate the skin. The defeated monster stays on
     * screen, KO'd, until a parent starts the next goal — swapping in a fresh
     * one against a bar still reading 100% damage would render the new boss as
     * already dead, and would rob the kids of the moment they just earned.
     */
    public function recordDefeat(Household $household, ?Profile $finisher = null): ?BossDefeat
    {
        $skin = $this->skinFor($household);
        $battle = (int) $household->boss_battle;

        $alreadyRecorded = BossDefeat::where('household_id', $household->id)
            ->where('battle', $battle)
            ->exists();

        if ($alreadyRecorded) {
            return null;
        }

        return BossDefeat::create([
            'household_id' => $household->id,
            'boss_key' => $skin,
            'boss_name' => $skin->label(),
            'battle' => $battle,
            'health' => (int) $household->goal_target,
            'goal_name' => $household->goal_name,
            'started_at' => $household->boss_started_at,
            'defeated_at' => now(),
            'finisher_profile_id' => $finisher?->id,
            'contributions' => $this->contributionSnapshot($household),
        ]);
    }

    /**
     * Rotate to the next monster and start its clock. Called when a parent
     * resets the family goal, which is the one moment a new battle begins.
     */
    public function startNewBattle(Household $household): void
    {
        $household->boss_key = $this->skinFor($household)->next();
        $household->boss_started_at = now();
        $household->boss_battle = (int) $household->boss_battle + 1;
        $household->save();
    }

    /** A parent picking a monster by hand. Restarts the clock with it. */
    public function setSkin(Household $household, BossSkin $skin): void
    {
        $household->boss_key = $skin;
        $household->boss_started_at ??= now();
        $household->save();
    }

    /**
     * @return Collection<int, BossDefeat>
     */
    public function defeats(Household $household, int $limit = 12): Collection
    {
        return BossDefeat::with('finisher')
            ->where('household_id', $household->id)
            ->orderByDesc('defeated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Who did what, frozen at the moment of the kill.
     *
     * A snapshot rather than a relation, because starting the next goal zeroes
     * every kid's `goal_contribution` — by the time anyone browses the trophy
     * shelf the live numbers are gone.
     *
     * ChoreService is resolved through the container rather than injected:
     * ChoreService depends on this service, and constructor-injecting it back
     * would close the loop. Same reason SpinService reaches for it this way.
     *
     * @return array<int, array{profile_id: int, name: string, points: int, percent: int}>
     */
    private function contributionSnapshot(Household $household): array
    {
        return app(ChoreService::class)
            ->goalContributors($household)
            ->map(fn (array $row) => [
                'profile_id' => $row['profile']->id,
                'name' => $row['profile']->name,
                'points' => $row['points'],
                'percent' => $row['percent'],
            ])
            ->values()
            ->all();
    }
}
