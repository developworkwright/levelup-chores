<?php

namespace App\Services;

use App\Enums\BossSkin;
use App\Enums\BossStage;
use App\Enums\ChoreCadence;
use App\Enums\MonsterHitKind;
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
 * The arena: the family goal the house is fighting, drawn as a monster.
 *
 * One stands at a time. Three stood here once, one per tier, and the kids chose
 * which of them each finished chore hit — a good idea that cost a tap on every
 * single claim and a decision nobody wanted to make twice a day. What is left
 * is what the tap was ever for: something the family is working toward, and a
 * bar that moves when the work gets done.
 *
 * A monster's health is summed from the hits landed on it and nothing else, so
 * this service owns both — writing a hit is the only way damage happens, and
 * reading the sum is the only way health is known.
 *
 * What it deliberately does *not* do is decide what a weak point is worth. That
 * is ChoreService's, at the moment a claim turns work into damage; this service
 * exposes the primitives it orchestrates ({@see self::land()}, {@see
 * self::settle()}) and keeps every rule about what a monster *is* in one place.
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
     * the family's too — a kid who had a bad week still watched it go down.
     */
    public const TICKETS_FOR_EVERYONE = 1;

    /** On top, for whoever landed the last blow. */
    public const TICKETS_FOR_FINISHER = 2;

    /**
     * On top, for whoever put the most damage in.
     *
     * Separate from the finisher's, because the kid who did the work over three
     * weeks and the kid who happened to tap last are rarely the same person and
     * only one of them is luck.
     */
    public const TICKETS_FOR_TOP_DAMAGE = 2;

    /** The longest a kid's name for a monster may be. */
    public const NICKNAME_LIMIT = 24;

    /**
     * The monster this household is fighting, or null while the arena is empty.
     *
     * Empty from the moment one falls until a parent names what the next one
     * pays out, which is the one decision the app can't make on their behalf.
     */
    public function current(Household $household): ?Monster
    {
        // `weakChore` eagerly, because every card that draws this names it.
        return Monster::with('weakChore')
            ->withSum('hits', 'damage')
            ->where('household_id', $household->id)
            ->live()
            ->latest('id')
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
     * @return array{monster: Monster, skin: BossSkin, stage: BossStage,
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
            'skin' => $monster->skin,
            'stage' => $stage,
            'name' => $monster->displayName(),
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
     * Stands the next monster up, which is a parent naming what beating it buys
     * and how much work that is worth.
     *
     * Refuses to double up: the one standing has to be beaten before the next
     * arrives, or the kids would be splitting their work across two bars again.
     */
    public function spawn(
        Household $household,
        string $rewardName,
        int $maxHealth,
        ?int $rewardCostCents = null,
        ?BossSkin $skin = null,
    ): Monster {
        if ($this->current($household) !== null) {
            throw new \RuntimeException('A monster is already standing.');
        }

        $battle = (int) Monster::where('household_id', $household->id)->max('battle');

        return Monster::create([
            'household_id' => $household->id,
            'battle' => $battle + 1,
            'skin' => $skin ?? $this->nextSkin($household),
            'reward_name' => $rewardName,
            'reward_cost_cents' => $rewardCostCents,
            'max_health' => max(1, $maxHealth),
            'started_at' => now(),
        ]);
    }

    /**
     * A face this family didn't just beat.
     *
     * Continues the rotation from the last monster they met, so beating one
     * introduces somebody new rather than the same face with the bar refilled.
     */
    public function nextSkin(Household $household): BossSkin
    {
        $latest = Monster::where('household_id', $household->id)
            ->latest('id')
            ->first();

        return $latest?->skin->next() ?? BossSkin::default();
    }

    /**
     * Draws this week's weak chore if the monster hasn't got one yet, and
     * returns the monster.
     *
     * Lazy, in the way the daily quest and the mystery chore are lazy: the
     * first person to look on a new week is the one who rolls it, so there is
     * no scheduled job to keep alive and no household that quietly stops
     * rotating because a cron died.
     */
    public function rotateWeakness(Household $household): ?Monster
    {
        $monster = $this->current($household);

        if ($monster === null) {
            return null;
        }

        $weekStart = HouseholdClock::for($household)->today()->startOfWeek();

        // Compared as dates rather than instants. A household keeps its own
        // timezone, so the stamp comes back out of a date column at UTC
        // midnight while the week starts at the family's — five hours apart,
        // which reads as "last week" on every single page load and re-rolls the
        // weak point continuously.
        $isStale = $monster->weak_rotated_on === null
            || $monster->weak_rotated_on->toDateString() < $weekStart->toDateString();

        if (! $isStale) {
            return $monster;
        }

        $pool = $this->weakChorePool($household);

        $monster->forceFill([
            'weak_chore_id' => $pool->isEmpty() ? null : $pool->random()->id,
            // Stamped even when the draw came up empty, so a household with no
            // eligible chores isn't re-rolled on every page load.
            'weak_rotated_on' => $weekStart,
        ])->save();

        // Loaded against the old id, so left alone it would name last week's
        // chore on every card that reads it.
        $monster->unsetRelation('weakChore');

        return $monster;
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

        $monster->unsetRelation('weakChore');
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
     * Lands damage on a monster and returns how much of it actually stuck.
     *
     * Capped at what the monster has left, so a killing blow's excess simply
     * stops there. That excess used to roll onto the tier above, which is the
     * one thing genuinely lost by having a single bar — and the alternative,
     * banking damage against a monster nobody has named yet, would be a fourth
     * currency to explain.
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
     * The chore's full payout lands on the monster standing, doubled if the kid
     * hit its weak point. Nothing standing means there is nowhere to put it —
     * the work still earned its points, which were always the kid's own and
     * never the monster's to give.
     */
    public function strike(Household $household, ChoreCompletion $completion): void
    {
        $monster = $this->current($household);

        if ($monster === null) {
            return;
        }

        $damage = $completion->points_awarded
            * ($completion->struck_weak_point ? self::WEAK_MULTIPLIER : 1);

        if ($this->land($monster, $damage, $completion->profile, $completion) > 0) {
            $this->settle($monster, $completion->profile);
        }
    }

    /** The monster standing, if nobody has named it yet. */
    public function nameable(Household $household): ?Monster
    {
        $monster = $this->current($household);

        return $monster?->nickname === null ? $monster : null;
    }

    /**
     * A kid naming the monster. Returns the name that stuck.
     *
     * First come, first served: a monster already carrying a name keeps it
     * until the day it goes down, so the perk is worth using the moment a new
     * one turns up rather than sitting in a pocket. Nothing is validated about
     * *taste* here — a parent can clear a name from the Monster Deck, which is
     * the right place for that judgement.
     *
     * @throws \RuntimeException when there is nothing to name or the name is unusable
     */
    public function nameMonster(Household $household, string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        if ($name === '') {
            throw new \RuntimeException('Give it a name first.');
        }

        $monster = $this->nameable($household);

        if ($monster === null) {
            throw new \RuntimeException('There is nothing standing to name.');
        }

        $name = mb_substr($name, 0, self::NICKNAME_LIMIT);

        $monster->forceFill(['nickname' => $name])->save();

        return $name;
    }

    /** A parent taking a name back off. The monster returns to its own. */
    public function clearNickname(Monster $monster): void
    {
        $monster->forceFill(['nickname' => null])->save();
    }

    /**
     * A parent moving the monster's health by hand.
     *
     * Unattributed by design: it is the tool for damage that never came from a
     * chore — a job rolled back by the daily reset, a monster seeded mid-battle
     * — and crediting it to a kid would put points on the leaderboard that
     * nobody earned.
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
     * monster up — asking the arena who died would name the wrong one entirely.
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
                "{$monster->displayName()} defeated",
                $monster,
            );

            $queue = $kid->pending_monster_kills ?? [];

            $queue[] = [
                'name' => $monster->displayName(),
                'skin' => $monster->skin->value,
                'reward' => $monster->reward_name,
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
     * Remembers where this kid left the fight, so the next visit only replays
     * what has happened since.
     *
     * Called by the arena as it renders, *after* the replay has been built —
     * the same compute-then-mark order the shell uses for badges and levels, and
     * what makes a replay play exactly once.
     *
     * Only the monster standing is kept. A beaten one's marker is dropped
     * rather than carried forever: its last blows arrive as a kill card instead,
     * which is a better telling of the same news, and the map stays one key wide
     * no matter how many fights a family gets through.
     */
    public function markSeen(Household $household, Profile $profile): void
    {
        $monster = $this->current($household);

        $seen = $monster === null
            ? []
            : [(string) $monster->id => min($monster->damage(), (int) $monster->max_health)];

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
     * hit and then a parent's adjustment — has to read the second against the
     * first, and the alternative is keeping a copy of a sum in step with a
     * table by hand, which is the exact thing this design set out not to do.
     * The next read goes back to the database and is simply right.
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
