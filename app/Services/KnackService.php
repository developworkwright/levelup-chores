<?php

namespace App\Services;

use App\Enums\CosmeticSlot;
use App\Enums\PetKnack;
use App\Enums\PetRarity;
use App\Enums\PetStage;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Chore;
use App\Models\Cosmetic;
use App\Models\LuckyHit;
use App\Models\PetKnackUse;
use App\Models\PetTreat;
use App\Models\Profile;
use App\Models\Spin;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What the pet a kid has out can do for them, and how much of it is left.
 *
 * Only the pet that is out, only a kid's, and only once it is past being a
 * baby — see PetKnack. A visiting sibling's pet brings its looks, never its
 * knack. Grown-ups' pets are for trying pets out and have no knacks at all.
 *
 * Uses are counted over a rolling window (PetKnack::allowance()), so nothing
 * resets on a schedule — there is no scheduler here — and a spent use simply
 * comes back that many days after it was spent. Counted per kid and knack,
 * not per pet, so swapping between two pets with the same knack doubles
 * nothing.
 *
 * **Power Treats** add to that, bought with tickets and fed to the pet: one
 * more use of a knack that is spent, banked until the week's own run out; or,
 * for an always-on knack, double strength for the rest of the day. No limit
 * on how many — each costs a ticket less than the Bonus Shop perk the knack
 * matches (treatPrice()), so having the pet is always the cheaper way.
 */
class KnackService
{
    public function __construct(
        private CosmeticService $cosmetics,
        private PetService $pets,
    ) {}

    /**
     * The knack of the pet this kid has out, and where it stands — or null
     * when they have no pet out, an egg in its place, or a Common.
     *
     * `left` counts banked Power Treats too (`treats` of them); `doubled` is
     * an always-on knack fed a treat today.
     *
     * @return array{pet: Cosmetic, knack: PetKnack, stage: PetStage, unlocked: bool, strength: ?string, description: string, automatic: bool, uses: ?int, left: ?int, treats: int, doubled: bool, days: ?int, backAt: ?CarbonInterface, choresToUnlock: ?int, choresToFull: ?int}|null
     */
    public function stateFor(Profile $kid): ?array
    {
        if (! $kid->isKid() || $this->pets->eggFor($kid) !== null) {
            return null;
        }

        $pet = $this->cosmetics->wornIn($kid, CosmeticSlot::Pet);
        $knack = $pet?->knack();

        if ($pet === null || $knack === null) {
            return null;
        }

        $stage = $this->pets->stageOf($kid, $pet);
        $unlocked = PetKnack::unlocked($stage);
        $allowance = $knack->allowance($stage);
        $growth = $this->pets->growthOf($kid, $pet);

        [$left, $backAt] = $allowance === null ? [null, null] : $this->charges($kid, $knack, $allowance);
        $treats = $this->bankedTreats($kid, $knack);

        return [
            'pet' => $pet,
            'knack' => $knack,
            'stage' => $stage,
            'unlocked' => $unlocked,
            'strength' => match (true) {
                ! $unlocked => null,
                $stage === PetStage::Adult => 'full',
                default => 'half',
            },
            'description' => $knack->describe($stage),
            'automatic' => $knack->automatic(),
            'uses' => $allowance['uses'] ?? null,
            'left' => $left === null ? null : $left + $treats,
            'treats' => $treats,
            'doubled' => $knack->alwaysOn() && $this->doubledToday($kid, $knack),
            'days' => $allowance['days'] ?? null,
            'backAt' => $backAt,
            'choresToUnlock' => $unlocked ? null : PetStage::Young->startsAt() - $growth,
            'choresToFull' => $stage === PetStage::Adult ? null : PetStage::Adult->startsAt() - $growth,
        ];
    }

    /**
     * Whether this kid's pet can do this knack right now: it is the knack of
     * the pet they have out, the pet is old enough, and a use is left. An
     * always-on knack is available whenever the pet has it.
     */
    public function available(Profile $kid, PetKnack $knack): bool
    {
        $state = $this->stateFor($kid);

        if ($state === null || $state['knack'] !== $knack || ! $state['unlocked']) {
            return false;
        }

        return $state['left'] === null || $state['left'] > 0;
    }

    /**
     * Spends one use of a knack, if one is there to spend.
     *
     * The count is taken again inside a lock on the kid's row, so two taps
     * in the same moment — or two tabs — cannot both spend the last use.
     *
     * @return bool whether a use was spent
     */
    public function use(Profile $kid, PetKnack $knack, ?array $payload = null): bool
    {
        return DB::transaction(function () use ($kid, $knack, $payload) {
            Profile::whereKey($kid->id)->lockForUpdate()->first();

            $state = $this->stateFor($kid);

            if ($state === null || $state['knack'] !== $knack || ! $state['unlocked'] || $state['left'] === null || $state['left'] < 1) {
                return false;
            }

            // The week's own first; a banked treat only once those are gone.
            $treat = $state['left'] > $state['treats'] ? null : PetTreat::where('profile_id', $kid->id)
                ->where('knack', $knack->value)
                ->whereNull('knack_use_id')
                ->oldest('id')
                ->first();

            $use = PetKnackUse::create([
                'household_id' => $kid->household_id,
                'profile_id' => $kid->id,
                'cosmetic_id' => $state['pet']->id,
                'knack' => $knack,
                'payload' => $payload,
                'used_at' => now(),
            ]);

            $treat?->update(['knack_use_id' => $use->id]);

            return true;
        });
    }

    /**
     * How old the kid's pet is, if it has this knack and is old enough to do
     * it — null otherwise. What the always-on knacks read to know how strong
     * to be.
     */
    public function strengthFor(Profile $kid, PetKnack $knack): ?PetStage
    {
        $state = $this->stateFor($kid);

        return $state !== null && $state['knack'] === $knack && $state['unlocked'] ? $state['stage'] : null;
    }

    /**
     * Big Pockets: how much more the arcade will pay this kid today — 5 for a
     * young pet, 10 for a grown one, nothing without the knack.
     */
    public function pocketsFor(Profile $kid): int
    {
        $pockets = match ($this->strengthFor($kid, PetKnack::BigPockets)) {
            PetStage::Adult => 10,
            PetStage::Young => 5,
            default => 0,
        };

        return $pockets * ($pockets > 0 && $this->doubledToday($kid, PetKnack::BigPockets) ? 2 : 1);
    }

    /**
     * Coin Sniffer: bonus tokens for a run that reached this many new rungs.
     * One a rung grown; young, every other one — the first, the third — so a
     * run that reached one new rung is never told the pet found nothing.
     */
    public function coinsSniffedFor(Profile $kid, int $newRungs): int
    {
        $coins = match ($this->strengthFor($kid, PetKnack::CoinSniffer)) {
            PetStage::Adult => $newRungs,
            PetStage::Young => (int) ceil($newRungs / 2),
            default => 0,
        };

        return $coins * ($coins > 0 && $this->doubledToday($kid, PetKnack::CoinSniffer) ? 2 : 1);
    }

    /**
     * Fetch, when there is something to fetch: today's spin, landed on a 2x,
     * on a chore the kid can still do. Null otherwise — including when the
     * pet has no fetch left.
     */
    public function fetchable(Profile $kid): ?Spin
    {
        if (! $this->available($kid, PetKnack::Fetch)) {
            return null;
        }

        $spin = app(SpinService::class)->today($kid);

        if ($spin === null || $spin->multiplier !== 2) {
            return null;
        }

        return app(ChoreService::class)->stateFor($kid, $spin->chore) === 'ready' ? $spin : null;
    }

    /**
     * The pet fetches the boost again: one use spent, the multiplier rolled
     * again on the same chore.
     *
     * @return int|null the new multiplier, or null when there was nothing to fetch
     */
    public function fetch(Profile $kid): ?int
    {
        $spin = $this->fetchable($kid);

        if ($spin === null || ! $this->use($kid, PetKnack::Fetch, ['spin_id' => $spin->id, 'from' => $spin->multiplier])) {
            return null;
        }

        return app(SpinService::class)->rerollBoost($spin);
    }

    /**
     * How many chores a sniff leaves in the running — the mystery chore and
     * the rest chosen at random — at this age.
     */
    public static function sniffKeeps(PetStage $stage): int
    {
        return $stage === PetStage::Adult ? 3 : 5;
    }

    /**
     * Whether the pet can sniff the board right now: it has a sniff left,
     * the mystery chore is still out there to find, nobody has claimed it,
     * the kid has not sniffed already today, and there are more chores that
     * could be it than a sniff would leave — otherwise there is nothing to
     * narrow, and no sniff is spent on nothing.
     */
    public function sniffable(Profile $kid): bool
    {
        if (! $this->available($kid, PetKnack::Sniffer) || $this->sniffedToday($kid) !== null) {
            return false;
        }

        $chores = app(ChoreService::class);
        $mystery = $chores->mysteryChoreFor($kid->household);

        if ($mystery === null || $chores->mysteryFinderFor($kid->household) !== null) {
            return false;
        }

        $candidates = $chores->mysteryCandidates($kid->household);

        return $candidates->contains('id', $mystery->id)
            && $candidates->count() > self::sniffKeeps($this->stateFor($kid)['stage']);
    }

    /**
     * The sniff: the mystery chore and enough others at random to make up
     * five (young) or three (grown), in no particular order, kept for the day.
     * Nothing about the list says which one it is — the others are drawn from
     * the same pool the draw itself uses, hinted or not.
     *
     * @return array<int, int>|null the chore ids still in the running
     */
    public function sniff(Profile $kid): ?array
    {
        if (! $this->sniffable($kid)) {
            return null;
        }

        $chores = app(ChoreService::class);
        $mystery = $chores->mysteryChoreFor($kid->household);
        $keeps = self::sniffKeeps($this->stateFor($kid)['stage']);

        $maybe = $chores->mysteryCandidates($kid->household)
            ->reject(fn (Chore $chore) => $chore->id === $mystery->id)
            ->shuffle()
            ->take($keeps - 1)
            ->push($mystery)
            ->pluck('id')
            ->shuffle()
            ->values()
            ->all();

        $today = HouseholdClock::for($kid->household)->today()->toDateString();

        return $this->use($kid, PetKnack::Sniffer, ['day' => $today, 'maybe' => $maybe]) ? $maybe : null;
    }

    /**
     * Today's sniff, for the board to mark: the chores still in the running.
     * Null when the kid has not sniffed today, and once the mystery chore is
     * found — there is nothing left to narrow down.
     *
     * @return array<int, int>|null
     */
    public function sniffedToday(Profile $kid): ?array
    {
        $today = HouseholdClock::for($kid->household)->today();

        $use = PetKnackUse::where('profile_id', $kid->id)
            ->where('knack', PetKnack::Sniffer->value)
            ->where('used_at', '>=', HouseholdClock::for($kid->household)->startOf($today))
            ->latest('used_at')
            ->first();

        if ($use === null || ($use->payload['day'] ?? null) !== $today->toDateString()) {
            return null;
        }

        if (app(ChoreService::class)->mysteryFinderFor($kid->household) !== null) {
            return null;
        }

        return array_map('intval', $use->payload['maybe'] ?? []);
    }

    /**
     * Uses left in the window, and when the next one comes back — null
     * while none has been spent.
     *
     * @param  array{uses: int, days: int}  $allowance
     * @return array{0: int, 1: ?CarbonInterface}
     */
    private function charges(Profile $kid, PetKnack $knack, array $allowance): array
    {
        $spent = PetKnackUse::where('profile_id', $kid->id)
            ->where('knack', $knack->value)
            ->where('used_at', '>', now()->subDays($allowance['days']))
            // A use a treat paid for was never one of the week's own.
            ->whereNotIn('id', PetTreat::where('profile_id', $kid->id)->whereNotNull('knack_use_id')->select('knack_use_id'))
            ->orderBy('used_at')
            ->pluck('used_at');

        // In the house's own time, so "back Thursday" is the kid's Thursday.
        $backAt = $spent->isEmpty() ? null : $spent->first()->copy()->addDays($allowance['days'])->setTimezone($kid->household->timezone);

        return [max(0, $allowance['uses'] - $spent->count()), $backAt];
    }

    /**
     * What a Power Treat for this knack costs, in tickets: a ticket under the
     * Bonus Shop perk it matches, at the house's own price for that perk, so
     * the pet is always the cheaper way — never under one ticket. The Lucky
     * Block stands in for Digger's perk. A knack with nothing to match pays
     * by its tier.
     */
    public function treatPrice(Profile $kid, PetKnack $knack): int
    {
        $perk = $knack->matchingPerk();

        $match = match (true) {
            $perk !== null => BonusPerk::where('household_id', $kid->household_id)->where('effect', $perk)->value('cost') ?? $perk->defaults()['cost'],
            $knack === PetKnack::Digger => LuckyBlockService::TICKET_COST,
            default => null,
        };

        if ($match !== null) {
            return max(1, (int) $match - 1);
        }

        return match ($knack->rarity()) {
            PetRarity::Legendary => 4,
            PetRarity::Epic => 3,
            default => 2,
        };
    }

    /**
     * Buys a Power Treat for the knack of the pet that is out, and feeds it.
     * Only a pet old enough to have learned its knack takes one — a baby has
     * nothing to power up yet.
     *
     * @throws InsufficientTicketsException
     * @throws PerkUnavailableException
     */
    public function buyTreat(Profile $kid): PetTreat
    {
        $state = $this->stateFor($kid);

        if ($state === null) {
            throw new PerkUnavailableException('Your pet needs a knack to power up.');
        }

        if (! $state['unlocked']) {
            throw new PerkUnavailableException($state['pet']->name.' is still learning its knack — a treat will help once it grows up a bit.');
        }

        $price = $this->treatPrice($kid, $state['knack']);

        return DB::transaction(function () use ($kid, $state, $price) {
            // Fresh, inside the transaction: the header can hold a stale copy.
            $kid->refresh();

            $treat = PetTreat::create([
                'household_id' => $kid->household_id,
                'profile_id' => $kid->id,
                'cosmetic_id' => $state['pet']->id,
                'knack' => $state['knack'],
                'tickets_paid' => $price,
                'day' => HouseholdClock::for($kid->household)->today()->toDateString(),
            ]);

            app(TicketService::class)->spend($kid, $price, 'Power Treat for '.$state['pet']->name.' ('.$state['knack']->label().')', $treat);

            return $treat;
        });
    }

    /** Treats waiting to pay for a use of this knack. */
    private function bankedTreats(Profile $kid, PetKnack $knack): int
    {
        if ($knack->alwaysOn()) {
            return 0;
        }

        return PetTreat::where('profile_id', $kid->id)->where('knack', $knack->value)->whereNull('knack_use_id')->count();
    }

    /** Whether an always-on knack was fed a treat today — double strength until the day turns. */
    public function doubledToday(Profile $kid, PetKnack $knack): bool
    {
        return PetTreat::where('profile_id', $kid->id)
            ->where('knack', $knack->value)
            ->whereDate('day', HouseholdClock::for($kid->household)->today()->toDateString())
            ->exists();
    }

    /* ------------------------------------------------------------------ *
     * The Epic knacks
     * ------------------------------------------------------------------ */

    /**
     * Today's spin, when a knack on the wheel can still act on it: it has
     * landed, and the chore it landed on is still there to do. A boost moved
     * or rolled again after the chore is claimed would be changing a job the
     * kid has already handed in.
     */
    private function openSpin(Profile $kid): ?Spin
    {
        $spin = app(SpinService::class)->today($kid);

        return $spin !== null && app(ChoreService::class)->stateFor($kid, $spin->chore) === 'ready' ? $spin : null;
    }

    /**
     * Paw Nudge: the chores either side of today's spin on the wheel, as the
     * wheel draws them — the next one that can still be done, each way. Null
     * where there is none, and nothing at all when there is no nudge to use.
     *
     * @return array{left: ?Chore, right: ?Chore}|null
     */
    public function nudgeTargets(Profile $kid): ?array
    {
        $spin = $this->available($kid, PetKnack::PawNudge) ? $this->openSpin($kid) : null;

        if ($spin === null || $this->nudgedToday($kid) !== null) {
            return null;
        }

        $wheel = app(SpinService::class)->eligibleChoresFor($kid)->values();
        $at = $wheel->search(fn (Chore $chore) => $chore->id === $spin->chore_id);

        if ($at === false || $wheel->count() < 2) {
            return null;
        }

        $chores = app(ChoreService::class);
        $step = function (int $direction) use ($wheel, $at, $chores, $kid): ?Chore {
            for ($i = 1; $i < $wheel->count(); $i++) {
                $chore = $wheel[($at + $direction * $i + $wheel->count() * $i) % $wheel->count()];

                if ($chore->id !== $wheel[$at]->id && $chores->stateFor($kid, $chore) === 'ready') {
                    return $chore;
                }
            }

            return null;
        };

        $targets = ['left' => $step(-1), 'right' => $step(1)];

        return $targets['left'] || $targets['right'] ? $targets : null;
    }

    /**
     * The pet bats the wheel one chore over, keeping the boost. A grown pet
     * goes the way the kid says; a young one goes whichever way it likes —
     * `$direction` is ignored for it — and the kid can put it back
     * (unnudge()), though the nudge is spent either way. One nudge a spin.
     *
     * @return array{chore: Chore, direction: string}|null
     */
    public function nudge(Profile $kid, ?string $direction = null): ?array
    {
        $targets = $this->nudgeTargets($kid);

        if ($targets === null) {
            return null;
        }

        $grown = $this->stateFor($kid)['stage'] === PetStage::Adult;
        $ways = array_keys(array_filter($targets));
        $way = $grown && in_array($direction, $ways, true) ? $direction : $ways[array_rand($ways)];
        $spin = $this->openSpin($kid);
        $from = $spin->chore_id;

        if (! $this->use($kid, PetKnack::PawNudge, ['day' => $spin->spin_date->toDateString(), 'spin_id' => $spin->id, 'from' => $from, 'to' => $targets[$way]->id, 'direction' => $way])) {
            return null;
        }

        app(SpinService::class)->moveBoostTo($spin, $targets[$way]);

        return ['chore' => $targets[$way], 'direction' => $way];
    }

    /**
     * Today's nudge, if there was one: the use's record of where the boost
     * came from and went.
     *
     * @return array{spin_id: int, from: int, to: int, direction: string, putBack?: bool}|null
     */
    public function nudgedToday(Profile $kid): ?array
    {
        $today = HouseholdClock::for($kid->household)->today()->toDateString();

        $use = PetKnackUse::where('profile_id', $kid->id)
            ->where('knack', PetKnack::PawNudge->value)
            ->latest('used_at')
            ->first();

        return $use !== null && ($use->payload['day'] ?? null) === $today ? $use->payload : null;
    }

    /**
     * A young pet's nudge, put back: the boost returns to the chore the wheel
     * first landed on. Still only while that spin's chore is there to do.
     */
    public function unnudge(Profile $kid): bool
    {
        $nudge = $this->nudgedToday($kid);
        $spin = $this->openSpin($kid);

        if ($nudge === null || ($nudge['putBack'] ?? false) || $spin === null || $spin->id !== $nudge['spin_id'] || $spin->chore_id !== $nudge['to']) {
            return false;
        }

        $from = Chore::find($nudge['from']);

        if ($from === null || app(ChoreService::class)->stateFor($kid, $from) !== 'ready') {
            return false;
        }

        app(SpinService::class)->moveBoostTo($spin, $from);

        PetKnackUse::where('profile_id', $kid->id)
            ->where('knack', PetKnack::PawNudge->value)
            ->latest('used_at')
            ->first()
            ?->update(['payload' => [...$nudge, 'putBack' => true]]);

        return true;
    }

    /** Second Look: whether the pet can spin the wheel again right now. */
    public function secondLookable(Profile $kid): bool
    {
        return $this->available($kid, PetKnack::SecondLook) && $this->openSpin($kid) !== null;
    }

    /**
     * The pet spins the wheel again: today's spin is cleared, chore and boost
     * both, and the kid takes another spin. An OP charge spent on the first
     * is not handed back — the same rule the respin perk keeps.
     */
    public function secondLook(Profile $kid): bool
    {
        if (! $this->secondLookable($kid) || ! $this->use($kid, PetKnack::SecondLook)) {
            return false;
        }

        return app(SpinService::class)->clearToday($kid) !== null;
    }

    /**
     * Lucky Tail, ready to charge the next spin: how old the pet is (grown
     * charges the full OP table, young a better shot at 3x), or null. The
     * spin spends the use itself — see SpinService::spin().
     */
    public function luckyTailReady(Profile $kid): ?PetStage
    {
        return $this->available($kid, PetKnack::LuckyTail) ? $this->stateFor($kid)['stage'] : null;
    }

    /** Whether this spin was charged by a pet's Lucky Tail. */
    public function luckyTailOn(Spin $spin): bool
    {
        return PetKnackUse::where('profile_id', $spin->profile_id)
            ->where('knack', PetKnack::LuckyTail->value)
            ->where('payload->spin_id', $spin->id)
            ->exists();
    }

    /** Good Luck Charm: whether there is anything on the board left to charm. */
    public function charmable(Profile $kid): bool
    {
        if (! $this->available($kid, PetKnack::GoodLuckCharm)) {
            return false;
        }

        $chores = app(ChoreService::class);
        $charmed = $chores->charmedChoreIdsFor($kid);

        return $chores->boardFor($kid)
            ->contains(fn (array $entry) => $entry['state'] === 'ready' && ! in_array($entry['chore']->id, $charmed, true));
    }

    /**
     * The pet casts its charm: a whole Quest Charm when grown, one chore when
     * young — each charmed chore pays half again today, exactly as the perk's.
     *
     * @return Collection<int, Chore>|null the chores it lit
     */
    public function charm(Profile $kid): ?Collection
    {
        if (! $this->charmable($kid)) {
            return null;
        }

        $grown = $this->stateFor($kid)['stage'] === PetStage::Adult;

        if (! $this->use($kid, PetKnack::GoodLuckCharm)) {
            return null;
        }

        return app(ChoreService::class)->charmBoard($kid, $grown ? ChoreService::CHARM_CHORES : 1);
    }

    /** Digger: whether the pet can dig the Lucky Block right now. */
    public function diggable(Profile $kid): bool
    {
        $block = app(LuckyBlockService::class);

        return $this->available($kid, PetKnack::Digger) && $block->drawableFor($kid)->isNotEmpty();
    }

    /**
     * The pet digs a free hit on the Lucky Block — the same pool, the same
     * reveal and the same grown-up queue as a bought one.
     */
    public function dig(Profile $kid): ?LuckyHit
    {
        if (! $this->diggable($kid) || ! $this->use($kid, PetKnack::Digger)) {
            return null;
        }

        return app(LuckyBlockService::class)->hit($kid, free: true);
    }

    /* ------------------------------------------------------------------ *
     * The Legendary knacks
     * ------------------------------------------------------------------ */

    /**
     * Guard Dog: a streak about to be lost over one missed day is saved, by
     * itself, the moment it is noticed — on the way in, before any page shows
     * a streak of nothing (see the SyncStreak middleware). A kid who missed a
     * day is not around to tap anything, and "you had it and forgot to use
     * it" is a bad thing to hand them. The same rescue the Streak Restore perk
     * buys; the kid hears about it on Home (takeRescues()).
     */
    public function guardStreak(Profile $kid): bool
    {
        $streaks = app(StreakService::class);

        if (! $kid->isKid() || $streaks->repairableStreakDate($kid) === null || ! $this->available($kid, PetKnack::GuardDog)) {
            return false;
        }

        try {
            return DB::transaction(function () use ($kid, $streaks) {
                $date = $streaks->repairableStreakDate($kid);

                if ($date === null || ! $this->use($kid, PetKnack::GuardDog, ['date' => $date->toDateString(), 'seen' => false])) {
                    return false;
                }

                // Spent and saved together, or neither.
                if ($streaks->repairStreak($kid) === null) {
                    throw new RuntimeException('Nothing to guard.');
                }

                return true;
            });
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Night Owl: a bedtime run broken by a night out of their own bed is saved
     * by itself — right after the answer, or on the way in. The same save the
     * Night Saver perk buys.
     *
     * @param  bool  $announced  whether the kid is being told right now, so Home need not say it again
     * @return string|null the pet's name, when it saved the run
     */
    public function nightOwl(Profile $kid, bool $announced = false): ?string
    {
        $sleep = app(SleepService::class);

        if (! $kid->isKid() || $sleep->saveReason($kid) !== null || ! $this->available($kid, PetKnack::NightOwl)) {
            return null;
        }

        $pet = $this->stateFor($kid)['pet'];

        try {
            return DB::transaction(function () use ($kid, $sleep, $announced, $pet) {
                if (! $this->use($kid, PetKnack::NightOwl, ['seen' => $announced])) {
                    return null;
                }

                if (! $sleep->saveNight($kid)) {
                    throw new RuntimeException('No night to save.');
                }

                return $pet->name;
            });
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Rescues the kid has not been told about yet — Guard Dog and Night Owl
     * go off while nobody is looking — as lines for Home, each told once.
     *
     * @return array<int, string>
     */
    public function takeRescues(Profile $kid): array
    {
        $uses = PetKnackUse::where('profile_id', $kid->id)
            ->whereIn('knack', [PetKnack::GuardDog->value, PetKnack::NightOwl->value])
            ->where('payload->seen', false)
            ->orderBy('used_at')
            ->get();

        $names = Cosmetic::whereIn('id', $uses->pluck('cosmetic_id'))->pluck('name', 'id');

        return $uses->map(function (PetKnackUse $use) use ($names) {
            $use->update(['payload' => [...$use->payload, 'seen' => true]]);
            $pet = $names[$use->cosmetic_id] ?? 'Your pet';

            return $use->knack === PetKnack::GuardDog
                ? "{$pet} guarded your streak — the day you missed still counts!"
                : "{$pet} saved your bedtime run — it's still going!";
        })->all();
    }

    /**
     * Sidekick: how much harder this kid's chores hit the monster, as a
     * percentage — 5 young, 10 grown, doubled on a Power Treat day.
     */
    public function sidekickPercentFor(Profile $kid): int
    {
        $percent = match ($this->strengthFor($kid, PetKnack::Sidekick)) {
            PetStage::Adult => 10,
            PetStage::Young => 5,
            default => 0,
        };

        return $percent * ($percent > 0 && $this->doubledToday($kid, PetKnack::Sidekick) ? 2 : 1);
    }
}
