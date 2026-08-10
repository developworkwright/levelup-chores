<?php

namespace App\Services;

use App\Enums\BossSkin;
use App\Enums\BossStage;
use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Enums\MonsterHitKind;
use App\Enums\MonsterTier;
use App\Enums\ProfileRole;
use App\Enums\TicketKind;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Monster;
use App\Models\MonsterHit;
use App\Models\Profile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * The arena: three family goals standing at once, each drawn as a monster.
 *
 * Where the old single boss was art painted over `households.goal_now`, these
 * are the goals themselves. A monster's health is summed from the hits landed
 * on it and nothing else, so this service owns both — writing a hit is the only
 * way damage happens, and reading the sum is the only way health is known.
 *
 * What it deliberately does *not* do is decide which monster a chore hits, or
 * what a weak point is worth. That is ChoreService's, at the moment an approval
 * turns work into damage; this service exposes the primitives it orchestrates
 * ({@see self::land()}, {@see self::settle()}) and keeps every rule about what a
 * monster *is* in one place.
 */
class MonsterService
{
    /** How many recent blows an arena feed shows. */
    public const FEED_LIMIT = 8;

    /**
     * What a chore is worth against the monster that flinches at it.
     *
     * Double, because the point of a weak point is to be worth crossing the
     * arena for — a 20% nudge would be a number on a card that nobody changes
     * their plan over.
     */
    public const WEAK_MULTIPLIER = 2;

    /** How many unwatched kills a returning kid is made to sit through. */
    public const KILL_QUEUE_LIMIT = 3;

    /**
     * Tickets every kid in the household gets when a monster falls, whatever
     * they put in.
     *
     * The reward the monster was guarding is the family's, so the tickets are
     * too — a kid who was at a friend's house all week still lives here, and a
     * kill that pays only the people who swung turns a family win into a
     * scoreboard.
     */
    public const TICKETS_FOR_EVERYONE = 1;

    /** On top, for whoever landed the killing blow. */
    public const TICKETS_FOR_FINISHER = 2;

    /**
     * On top, for whoever did the most damage to it.
     *
     * Separate from the finisher's, and stacking with it, because they reward
     * different things: the last hit is luck of the timing, and the biggest
     * share is a fortnight of work. One kid doing both takes all five.
     */
    public const TICKETS_FOR_TOP_DAMAGE = 2;

    /**
     * The monsters currently standing, keyed by tier.
     *
     * Between zero and three of them: a tier is empty from the moment its
     * monster falls until a parent names what the next one pays out, which is
     * the one decision the app can't make on their behalf.
     *
     * @return Collection<int, Monster>
     */
    public function live(Household $household): Collection
    {
        // `weakChore` eagerly, because every card names it. Left lazy it is one
        // query per monster — invisible with a single boss, and three times the
        // cost the moment the arena draws three of them.
        return Monster::with('weakChore')
            ->withSum('hits', 'damage')
            ->where('household_id', $household->id)
            ->live()
            ->orderBy('tier')
            ->get()
            ->keyBy(fn (Monster $monster) => $monster->tier->value);
    }

    public function at(Household $household, MonsterTier $tier): ?Monster
    {
        return Monster::with('weakChore')
            ->withSum('hits', 'damage')
            ->where('household_id', $household->id)
            ->where('tier', $tier->value)
            ->live()
            ->first();
    }

    /**
     * Everything a card needs to draw itself.
     *
     * `damage` is the full swing of what has landed while `health` is floored
     * at zero: the last chore before a kill routinely pays more than the
     * monster has left, and showing a 40-point chore land for 12 reads as the
     * app shortchanging the kid who finished it.
     *
     * @return array{monster: Monster, tier: MonsterTier, skin: BossSkin, stage: BossStage,
     *               name: string, tagline: string, reward: string, health: int, maxHealth: int,
     *               healthPercent: int, damage: int, damagePercent: int, taunt: string,
     *               defeated: bool, weakChore: ?string}
     */
    public function stateFor(Monster $monster): array
    {
        $maxHealth = max(1, (int) $monster->max_health);
        $damage = min($monster->damage(), $maxHealth);
        $healthPercent = $this->healthPercent($damage, $maxHealth);

        // A beaten monster reads as beaten whatever its numbers say afterwards
        // — a parent correcting the bar must not walk back a kill the kids have
        // already celebrated.
        $stage = $monster->isDefeated()
            ? BossStage::Defeated
            : BossStage::fromHealth($healthPercent);

        return [
            'monster' => $monster,
            'tier' => $monster->tier,
            'skin' => $monster->skin,
            'stage' => $stage,
            'name' => $monster->skin->label(),
            'tagline' => $monster->skin->tagline(),
            'reward' => $monster->reward_name,
            'health' => max(0, $maxHealth - $damage),
            'maxHealth' => $maxHealth,
            'healthPercent' => $healthPercent,
            'damage' => $damage,
            'damagePercent' => 100 - $healthPercent,
            'taunt' => $stage->taunt(),
            'defeated' => $monster->isDefeated(),
            'weakChore' => $monster->weakChore?->name,
        ];
    }

    /**
     * Health left as a percentage, from damage dealt.
     *
     * A monster on its last few points must not round up to a bar that looks
     * untouched, and one that is genuinely dead must read as a clean zero.
     */
    public function healthPercent(int $damage, int $maxHealth): int
    {
        if ($maxHealth <= 0) {
            return 0;
        }

        $health = max(0, $maxHealth - min($damage, $maxHealth));
        $percent = (int) round($health / $maxHealth * 100);

        return $health > 0 ? max(1, $percent) : 0;
    }

    /**
     * Stands a new monster up at a tier, which is a parent naming what beating
     * it buys and how much work that is worth.
     *
     * Refuses to double up: a tier already occupied has to be beaten before the
     * next one arrives, or the kids would be aiming at two level 1s.
     */
    public function spawn(
        Household $household,
        MonsterTier $tier,
        string $rewardName,
        int $maxHealth,
        ?int $rewardCostCents = null,
        ?BossSkin $skin = null,
    ): Monster {
        if ($this->at($household, $tier) !== null) {
            throw new \RuntimeException("Tier {$tier->value} already has a monster standing.");
        }

        $battle = (int) Monster::where('household_id', $household->id)
            ->where('tier', $tier->value)
            ->max('battle');

        return Monster::create([
            'household_id' => $household->id,
            'tier' => $tier,
            'battle' => $battle + 1,
            'skin' => $skin ?? $this->nextSkin($household),
            'reward_name' => $rewardName,
            'reward_cost_cents' => $rewardCostCents,
            'max_health' => max(1, $maxHealth),
            'started_at' => now(),
        ]);
    }

    /**
     * A face nobody in this arena is already wearing.
     *
     * Continues the rotation from the last monster this family met, so beating
     * one introduces somebody new rather than the same face with the bar
     * refilled — then walks on past any skin currently standing at another
     * tier, because three identical monsters would make the choice between them
     * meaningless.
     */
    public function nextSkin(Household $household): BossSkin
    {
        $standing = $this->live($household)
            ->map(fn (Monster $monster) => $monster->skin)
            ->all();

        $latest = Monster::where('household_id', $household->id)
            ->latest('id')
            ->first();

        $skin = $latest?->skin->next() ?? BossSkin::default();

        // Bounded by the catalogue: with at most three standing there is always
        // a free face, and this can't outrun the rotation looking for it.
        foreach (BossSkin::cases() as $ignored) {
            if (! in_array($skin, $standing, true)) {
                return $skin;
            }

            $skin = $skin->next();
        }

        return $skin;
    }

    /**
     * The tier a chore hits when nobody said which.
     *
     * The highest one standing: before the picker exists this is the tier the
     * single family goal used to be, and after it exists this is what a
     * completion from an older client still does something sensible with.
     * Null when the arena is empty.
     */
    public function defaultTier(Household $household): ?MonsterTier
    {
        return $this->live($household)
            ->sortByDesc(fn (Monster $monster) => $monster->tier->value)
            ->first()?->tier;
    }

    /**
     * Draws this week's weak chore for any monster that hasn't got one yet,
     * and returns the arena.
     *
     * Lazy, in the way the daily quest and the mystery chore are lazy: the
     * first person to look on a new week is the one who rolls it, so there is
     * no scheduled job to keep alive and no household that quietly stops
     * rotating because a cron died.
     *
     * The three draws are distinct. One chore that is everybody's weak point
     * would collapse the choice back into "do that job", which is the opposite
     * of what the weak points are for.
     *
     * @return Collection<int, Monster>
     */
    public function rotateWeaknesses(Household $household): Collection
    {
        $live = $this->live($household);
        $weekStart = HouseholdClock::for($household)->today()->startOfWeek();

        // Weak points already spoken for this week — including ones a parent
        // picked by hand, which are as binding on the draw as a drawn one.
        $taken = $live
            ->filter(fn (Monster $monster) => $monster->weak_chore_id !== null)
            ->map(fn (Monster $monster) => $monster->weak_chore_id)
            ->all();

        foreach ($live as $monster) {
            // Compared as dates rather than instants. A household keeps its own
            // timezone, so the stamp comes back out of a date column at UTC
            // midnight while the week starts at the family's — five hours
            // apart, which reads as "last week" on every single page load and
            // re-rolls the weak points continuously.
            $isStale = $monster->weak_rotated_on === null
                || $monster->weak_rotated_on->toDateString() < $weekStart->toDateString();

            if (! $isStale) {
                continue;
            }

            $chore = $this->drawWeakChore($household, $taken);

            $monster->forceFill([
                'weak_chore_id' => $chore?->id,
                // Stamped even when the draw came up empty, so a household with
                // no eligible chores isn't re-rolled on every page load.
                'weak_rotated_on' => $weekStart,
            ])->save();

            if ($chore !== null) {
                $taken[] = $chore->id;
            }
        }

        return $live;
    }

    /**
     * A parent replacing this week's weak chore, because the draw picked
     * something unreasonable for the week they can see coming.
     *
     * Stamps the week as settled, which is what stops the lazy draw from
     * overwriting the choice on the very next page load — but does not lock it:
     * next week's rotation replaces a hand-picked weak point exactly as it
     * would a drawn one. The override is for the week in front of them, not
     * forever, which is all a quick fix should cost.
     */
    public function setWeakness(Monster $monster, ?Chore $chore): void
    {
        if ($chore !== null && $chore->household_id !== $monster->household_id) {
            throw new \RuntimeException('That chore belongs to another household.');
        }

        $monster->forceFill([
            'weak_chore_id' => $chore?->id,
            'weak_rotated_on' => HouseholdClock::for($monster->household)->today()->startOfWeek(),
        ])->save();
    }

    /**
     * The chores a weak point can be drawn from: on the board now, and open to
     * any age.
     *
     * Age-open for the same reason the mystery chore is — a weak point the
     * youngest kid is not allowed to do is a bonus with their name crossed off
     * it. Unlimited-cadence chores are excluded too: doubling something
     * repeatable without limit is a tap-farm, not a puzzle.
     *
     * @return Collection<int, Chore>
     */
    public function weakChorePool(Household $household): Collection
    {
        return $household->chores
            ->filter(fn (Chore $chore) => $chore->min_age === null)
            ->reject(fn (Chore $chore) => $chore->cadence === ChoreCadence::Unlimited)
            ->reject(fn (Chore $chore) => $chore->isUsedUp())
            ->values();
    }

    /** Whether this chore is what the monster flinches at. */
    public function isWeakPoint(Monster $monster, Chore $chore): bool
    {
        return $monster->weak_chore_id !== null && $monster->weak_chore_id === $chore->id;
    }

    /**
     * @param  array<int, int>  $taken
     */
    private function drawWeakChore(Household $household, array $taken): ?Chore
    {
        $pool = $this->weakChorePool($household)
            ->reject(fn (Chore $chore) => in_array($chore->id, $taken, true));

        return $pool->isEmpty() ? null : $pool->random();
    }

    /**
     * Lands damage on a monster and returns how much of it actually stuck.
     *
     * Capped at what the monster has left, so the caller can take the
     * difference and spill it onto the tier above — a killing blow's excess
     * rolling upward is why one chore can produce two hits.
     *
     * A monster already on the shelf absorbs nothing: aiming at it is a
     * correction waiting to happen, not damage.
     */
    public function land(
        Monster $monster,
        int $damage,
        ?Profile $profile = null,
        ?ChoreCompletion $completion = null,
        MonsterHitKind $kind = MonsterHitKind::Hit,
    ): int {
        if ($monster->isDefeated() || $damage <= 0) {
            return 0;
        }

        $applied = min($damage, $monster->healthLeft());

        if ($applied <= 0) {
            return 0;
        }

        $this->record($monster, $applied, $profile, $completion, $kind);

        return $applied;
    }

    /**
     * Turns an approved chore into damage on the arena.
     *
     * The chore's full payout lands on the monster the kid aimed at, doubled if
     * they hit its weak point, and whatever that monster hasn't the health left
     * to absorb rolls up to the next tier — where it can kill again, and spill
     * again. One chore finishing off two monsters in a breath is the best thing
     * this feature does, and it falls out of the loop rather than being
     * special-cased.
     *
     * Aimed at an empty tier — beaten this afternoon, not yet replaced — the hit
     * simply starts higher up rather than being thrown away. Only a family with
     * nothing standing at all loses it, and there is nowhere honest to put it in
     * that case: banking damage against a monster nobody has named yet would be
     * a fourth currency to explain.
     *
     * The doubling survives the climb. It was earned against the weak point the
     * kid was shown when they chose, and quietly halving the part that spills
     * would make the best hit in the game read like a bug.
     *
     * @param  ?MonsterTier  $from  overrides the tier stored on the completion,
     *                              which is how a parent re-aims a mis-tap
     */
    public function strike(Household $household, ChoreCompletion $completion, ?MonsterTier $from = null): void
    {
        $damage = $completion->points_awarded
            * ($completion->struck_weak_point ? self::WEAK_MULTIPLIER : 1);

        $tier = $from ?? $completion->target_tier ?? $this->defaultTier($household);
        $kind = MonsterHitKind::Hit;

        while ($tier !== null && $damage > 0) {
            $monster = $this->at($household, $tier);

            if ($monster !== null) {
                $applied = $this->land($monster, $damage, $completion->profile, $completion, $kind);

                if ($applied > 0) {
                    $damage -= $applied;
                    $this->settle($monster, $completion->profile);

                    // Only what actually rolls off a monster is a spill. A hit
                    // that skipped an empty tier on the way up was never
                    // anything but the kid's own blow, landing where it could.
                    $kind = MonsterHitKind::Spill;
                }
            }

            $tier = $tier->above();
        }
    }

    /**
     * A parent correcting a mis-tap: the same chore, landing on the monster the
     * kid meant.
     *
     * The old hits are deleted and re-thrown rather than a compensating pair of
     * adjustments being written, so the kid keeps the credit on the right
     * monster and the feed reads as though the tap had been right all along.
     *
     * Refused once anything it touched has fallen. A beaten monster has already
     * had its celebration and its reward promised out loud, and quietly pulling
     * the blow that killed it back out is worse than the mis-tap ever was.
     *
     * The weak-point doubling is carried over untouched. It was earned against
     * what the kid was shown when they chose; re-aiming moves the damage, not
     * its size.
     */
    public function reaim(ChoreCompletion $completion, MonsterTier $tier): bool
    {
        $household = $completion->profile?->household;

        if ($household === null || $completion->status !== CompletionStatus::Approved) {
            return false;
        }

        $landed = MonsterHit::with('monster')
            ->where('chore_completion_id', $completion->id)
            ->get();

        if ($landed->contains(fn (MonsterHit $hit) => $hit->monster?->isDefeated() ?? true)) {
            return false;
        }

        if ($this->at($household, $tier) === null) {
            return false;
        }

        MonsterHit::whereIn('id', $landed->pluck('id'))->delete();

        // Kept in step with where the damage actually went, so the completion
        // is still an honest record of what this chore did.
        $completion->forceFill(['target_tier' => $tier])->save();

        $this->strike($household, $completion, $tier);

        return true;
    }

    /**
     * The blows landed across the whole arena, newest first — the feed a parent
     * re-aims from.
     *
     * @return Collection<int, MonsterHit>
     */
    public function recentHits(Household $household, int $limit = 20): Collection
    {
        return MonsterHit::with(['profile', 'monster', 'completion.chore'])
            ->where('household_id', $household->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The chores a parent may put this monster's weak point on: the draw's own
     * pool, minus anything another monster is already flinching at.
     *
     * @return Collection<int, Chore>
     */
    public function weakChoreOptions(Household $household, Monster $monster): Collection
    {
        $taken = $this->live($household)
            ->reject(fn (Monster $other) => $other->is($monster))
            ->map(fn (Monster $other) => $other->weak_chore_id)
            ->filter()
            ->all();

        return $this->weakChorePool($household)
            ->reject(fn (Chore $chore) => in_array($chore->id, $taken, true))
            ->values();
    }

    /**
     * A parent moving a monster's health by hand.
     *
     * Uncapped downward and unattributed by design: it is the tool for damage
     * that never came from a chore — a job rolled back by the daily reset, a
     * tier seeded mid-battle — and crediting it to a kid would put points on
     * the leaderboard that nobody earned. A mis-tapped chore is not this; that
     * gets re-aimed, so the kid keeps the credit on the monster they meant.
     */
    public function adjust(Monster $monster, int $delta): ?MonsterHit
    {
        if ($delta === 0) {
            return null;
        }

        // Never past full health or below untouched: the bar has nowhere to put
        // the difference, so it would silently disagree with the number typed.
        $bounded = max(
            -$monster->damage(),
            min($delta, $monster->max_health - $monster->damage()),
        );

        if ($bounded === 0) {
            return null;
        }

        return $this->record($monster, $bounded, null, null, MonsterHitKind::Adjust);
    }

    /**
     * Banks the kill if the monster has run out of health, and says whether it
     * did. Safe to call after every hit — a monster still standing, or one
     * already on the shelf, is a no-op.
     *
     * The leaderboard is frozen here rather than left to be re-summed later.
     * The hits behind a beaten monster stay in the table, but a kid can be
     * renamed or deleted years before anyone browses the trophy shelf, and a
     * kill should still remember who won it.
     */
    public function settle(Monster $monster, ?Profile $finisher = null): bool
    {
        if ($monster->isDefeated() || $monster->healthLeft() > 0) {
            return false;
        }

        // Read before the row is marked beaten: contributionsFor() switches to
        // the frozen snapshot the moment `defeated_at` is set, and the snapshot
        // is what this is about to write.
        $contributions = $this->contributionsFor($monster);

        $monster->forceFill([
            'defeated_at' => now(),
            'finisher_profile_id' => $finisher?->id,
            // Without the model. A snapshot is a row of json that has to still
            // make sense in five years, and a serialised Profile in it would be
            // a copy of a kid's whole record — PIN hash included — frozen into
            // the trophy shelf.
            'contributions' => $contributions
                ->map(fn (array $row) => Arr::except($row, 'profile'))
                ->all(),
        ])->save();

        $this->payOutKill($monster, $finisher, $contributions);

        return true;
    }

    /**
     * The tickets a monster's death is worth, per kid.
     *
     * Three separate things, and they stack: everyone in the house gets one
     * because the reward is the family's; whoever landed the last blow gets two
     * more; and whoever put the most damage in gets two more again. A kid who
     * did both walks away with five.
     *
     * A tie for most damage pays both of them. `isLeader` is already shared on
     * a tie — the crown on the board works that way — and splitting a whole
     * ticket is not a thing that can be done.
     *
     * @param  Collection<int, array{profile_id: ?int, points: int, isLeader: bool}>  $contributions
     * @return array{everyone: int, finisher: int, topDamage: int, total: int}
     */
    private function ticketsFor(Profile $kid, ?Profile $finisher, Collection $contributions): array
    {
        $row = $contributions->firstWhere('profile_id', $kid->id);

        $finisherBonus = $finisher?->id === $kid->id ? self::TICKETS_FOR_FINISHER : 0;

        // Guarded on having actually landed something: with nothing but a
        // parent's hand adjustment on the bar, nobody did the most damage.
        $topBonus = ($row['isLeader'] ?? false) && ($row['points'] ?? 0) > 0
            ? self::TICKETS_FOR_TOP_DAMAGE
            : 0;

        return [
            'everyone' => self::TICKETS_FOR_EVERYONE,
            'finisher' => $finisherBonus,
            'topDamage' => $topBonus,
            'total' => self::TICKETS_FOR_EVERYONE + $finisherBonus + $topBonus,
        ];
    }

    /**
     * Pays the kill out and puts it on every kid's card queue.
     *
     * One pass over the household so the tickets and the card that announces
     * them can't disagree — a card reading "+5 tickets" beside a balance that
     * moved by one is worse than no card at all.
     *
     * Queued rather than dispatched, because a monster falls on a parent's
     * approvals screen — which is not a screen any kid is looking at. It waits
     * on their profile until they next open the app, and being a column rather
     * than the session means signing out cannot lose it.
     *
     * Everything the card will say is stamped in here rather than looked up
     * later: the monster's name, its artwork, and what beating it bought. A kid
     * can be days late to this, by which time a parent has stood the next
     * monster up at that tier — asking the arena who died would name the wrong
     * one entirely.
     *
     * The finisher is stored as the word each kid should read — "You" on the
     * profile that landed the blow, their name on everyone else's. Cheaper and
     * more honest than storing an id and having the view work out whether it is
     * looking at itself: two kids can share a name, ids can be deleted, and
     * neither problem exists if the row already says what to print.
     *
     * @param  Collection<int, array<string, mixed>>  $contributions
     */
    private function payOutKill(Monster $monster, ?Profile $finisher, Collection $contributions): void
    {
        $kids = Profile::where('household_id', $monster->household_id)
            ->where('role', ProfileRole::Kid)
            ->get();

        $tickets = app(TicketService::class);

        foreach ($kids as $kid) {
            $earned = $this->ticketsFor($kid, $finisher, $contributions);

            $tickets->record(
                $kid,
                TicketKind::BossDefeat,
                $earned['total'],
                "{$monster->skin->label()} defeated",
                $monster,
            );

            $queue = $kid->pending_monster_kills ?? [];

            $queue[] = [
                'name' => $monster->skin->label(),
                'skin' => $monster->skin->value,
                'reward' => $monster->reward_name,
                'tier' => $monster->tier->label(),
                'finisher' => match (true) {
                    $finisher === null => null,
                    $finisher->id === $kid->id => 'You',
                    default => $finisher->name,
                },
                'tickets' => $earned['total'],
                'finisherBonus' => $earned['finisher'] > 0,
                'topDamageBonus' => $earned['topDamage'] > 0,
            ];

            // A kid back from a fortnight away gets the last few kills, not a
            // queue of set pieces to sit through before they can use the app.
            //
            // `refresh()` because record() incremented the balance in the
            // database — writing a stale instance back would undo it.
            $kid->refresh()->forceFill([
                'pending_monster_kills' => array_slice($queue, -self::KILL_QUEUE_LIMIT),
            ])->save();
        }
    }

    /**
     * Every stage between what this kid last watched on this monster and where
     * it stands now, oldest first — always at least one entry, the current
     * state.
     *
     * The point is that the fight belongs to everyone. Chores are approved all
     * day by a parent looking at a screen no kid is on, so a kid opening the app
     * after school would otherwise find the monsters simply *already* half dead:
     * all of the damage, none of the moment. Handing the page the stages they
     * missed lets it play them back before settling on the truth.
     *
     * A monster this kid has never looked at replays nothing — {@see
     * self::markSeen()} seeds the marker silently.
     *
     * @return array<int, array{stage: BossStage, damage: int, health: int,
     *                          damagePercent: int, healthPercent: int, landed: int,
     *                          label: string, taunt: string}>
     */
    public function replayFor(Monster $monster, Profile $profile): array
    {
        $maxHealth = max(1, (int) $monster->max_health);
        $damage = min($monster->damage(), $maxHealth);
        $current = BossStage::fromHealth($this->healthPercent($damage, $maxHealth));

        $step = fn (BossStage $stage, int $to, int $from) => [
            'stage' => $stage,
            'damage' => $to,
            'health' => max(0, $maxHealth - $to),
            'damagePercent' => 100 - $this->healthPercent($to, $maxHealth),
            'healthPercent' => $this->healthPercent($to, $maxHealth),
            'landed' => max(0, $to - $from),
            'label' => $stage->label(),
            'taunt' => $stage->taunt(),
        ];

        $now = $step($current, $damage, $damage);
        $seen = ($profile->monsters_seen ?? [])[(string) $monster->id] ?? null;

        if ($seen === null || $damage <= $seen) {
            return [$now];
        }

        $stages = BossStage::cases();
        $from = BossStage::fromHealth($this->healthPercent($seen, $maxHealth));
        $fromIndex = (int) array_search($from, $stages, true);
        $toIndex = (int) array_search($current, $stages, true);

        $steps = [$step($from, $seen, $seen)];
        $previous = $seen;

        for ($i = $fromIndex + 1; $i <= $toIndex; $i++) {
            // Every stage but the last is entered at its own boundary, so the
            // bar visibly stops at each one instead of sliding past it.
            $to = $i === $toIndex
                ? $damage
                : (int) ceil($maxHealth * $stages[$i]->entryDamagePercent() / 100);

            $steps[] = $step($stages[$i], $to, $previous);
            $previous = $to;
        }

        // Damage that didn't cross a boundary still deserves the bar moving —
        // the kid missed the hits either way.
        if ($fromIndex === $toIndex) {
            $steps[] = $step($current, $damage, $seen);
        }

        return $steps;
    }

    /**
     * Remembers where this kid left each fight, so the next visit only replays
     * what has happened since.
     *
     * Called by the arena as it renders, *after* the replays have been built —
     * the same compute-then-mark order the shell uses for badges and levels, and
     * what makes a replay play exactly once.
     *
     * Only the monsters standing are kept. A beaten one's marker is dropped
     * rather than carried forever: its last blows arrive as a kill card instead,
     * which is a better telling of the same news, and the map stays three keys
     * wide no matter how many fights a family gets through.
     */
    public function markSeen(Household $household, Profile $profile): void
    {
        $seen = $this->live($household)
            ->mapWithKeys(fn (Monster $monster) => [
                (string) $monster->id => min($monster->damage(), (int) $monster->max_health),
            ])
            ->all();

        // The arena renders on every round trip and most of them have nothing
        // new to record.
        if (($profile->monsters_seen ?? []) === $seen) {
            return;
        }

        $profile->forceFill(['monsters_seen' => $seen])->save();
    }

    /**
     * Who put what into this monster, biggest first.
     *
     * Summed live from the hits while it stands, and read from the snapshot
     * once it falls. Kids who haven't landed anything yet stay on the board at
     * zero — the point of showing this is that it's a race.
     *
     * Shares are of what the kids have landed between them rather than of the
     * monster's health, so a parent's hand adjustment can't hand everyone a
     * smaller slice than they earned.
     *
     * `profile` is the model where it can still be found, so one component can
     * draw both a live board and a shelved one. Null on a snapshot naming a kid
     * who has since been deleted — the name is stored beside it precisely so
     * that case still reads as a person rather than a gap.
     *
     * @return Collection<int, array{profile: ?Profile, profile_id: ?int, name: string,
     *                               points: int, percent: int, isLeader: bool}>
     */
    public function contributionsFor(Monster $monster): Collection
    {
        if ($monster->isDefeated() && $monster->contributions !== null) {
            $kids = Profile::whereIn('id', Arr::pluck($monster->contributions, 'profile_id'))
                ->get()
                ->keyBy('id');

            return collect($monster->contributions)
                ->map(fn (array $row) => [...$row, 'profile' => $kids->get($row['profile_id'] ?? 0)]);
        }

        $landed = MonsterHit::query()
            ->earned()
            ->where('monster_id', $monster->id)
            ->groupBy('profile_id')
            ->selectRaw('profile_id, SUM(damage) as points')
            ->pluck('points', 'profile_id');

        $kids = Profile::where('household_id', $monster->household_id)
            ->where('role', ProfileRole::Kid)
            ->orderBy('name')
            ->get();

        $total = (int) $landed->sum();
        $best = (int) $landed->max();

        return $kids
            ->map(function (Profile $kid) use ($landed, $total, $best) {
                $points = max(0, (int) ($landed[$kid->id] ?? 0));

                return [
                    'profile' => $kid,
                    'profile_id' => $kid->id,
                    'name' => $kid->name,
                    'points' => $points,
                    'percent' => $total > 0 ? (int) round($points / $total * 100) : 0,
                    // Ties share the crown rather than letting the sort order
                    // pick a winner out of two identical numbers.
                    'isLeader' => $best > 0 && $points === $best,
                ];
            })
            ->sortByDesc('points')
            ->values();
    }

    /**
     * The blows landed on this monster, newest first.
     *
     * @return Collection<int, MonsterHit>
     */
    public function hits(Monster $monster, int $limit = self::FEED_LIMIT): Collection
    {
        return MonsterHit::with('profile')
            ->where('monster_id', $monster->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The trophy shelf: everything this family has put down, newest first.
     *
     * @return Collection<int, Monster>
     */
    public function shelf(Household $household, int $limit = 12): Collection
    {
        return Monster::with('finisher')
            ->where('household_id', $household->id)
            ->beaten()
            ->orderByDesc('defeated_at')
            // One chore can finish two monsters in the same breath, and those
            // stamps only carry to the second — so the tie is broken by which
            // fell second rather than by whatever order the rows come back in.
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Writes the hit, then drops any health this monster was carrying from the
     * query that loaded it.
     *
     * Dropped rather than patched. A caller landing two blows in a row — a
     * kill, then its spill onto the tier above — has to read the second
     * against the first, and the alternative is keeping a copy of a sum in step
     * with a table by hand, which is the exact thing this design set out not to
     * do. The next read goes back to the database and is simply right.
     */
    private function record(
        Monster $monster,
        int $damage,
        ?Profile $profile,
        ?ChoreCompletion $completion,
        MonsterHitKind $kind,
    ): MonsterHit {
        $hit = MonsterHit::create([
            'household_id' => $monster->household_id,
            'monster_id' => $monster->id,
            'chore_completion_id' => $completion?->id,
            'profile_id' => $profile?->id,
            'damage' => $damage,
            'kind' => $kind,
        ]);

        $monster->offsetUnset('hits_sum_damage');

        return $hit;
    }
}
