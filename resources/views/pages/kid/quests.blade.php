<?php

use App\Enums\ChoreCategory;
use App\Enums\CompletionStatus;
use App\Enums\PerkEffect;
use App\Enums\PriceBand;
use App\Enums\SleepBand;
use App\Enums\SleepCardType;
use App\Enums\SleepOutcome;
use App\Exceptions\BountyUnavailableException;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\BonusPerk;
use App\Models\Bounty;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Profile;
use App\Models\Spin;
use App\Services\BadgeService;
use App\Services\BonusShopService;
use App\Services\BountyService;
use App\Services\ChoreService;
use App\Services\GratitudeService;
use App\Services\HouseholdClock;
use App\Services\MonsterService;
use App\Services\PerkInventoryService;
use App\Services\SleepService;
use App\Services\SpinService;
use App\Services\StreakService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The board: today's target, the main quest, and everything else there is to do.
 *
 * It used to be the kid's whole day — the loot tray, the chests, the spin and
 * the boss all sat on it too, which made it a long page a kid had to already
 * know their way around. Those moved to Home, which is organised by *when*
 * rather than by what kind of thing something is. What's left here is the work:
 * the main quest (<x-quest-hero>, shared with Home so there is one
 * implementation of the chest rather than two), the bonus wheel, the side
 * quests, the gratitude quest, the bounty board and the mystery chore.
 *
 * The wheel is the one that came back. It went to Home with the rest and the
 * kids kept opening this page looking for it, which was them being right: the
 * wheel lands on a side quest and multiplies it, and every one of those rows
 * is on this page. Home keeps a one-line pointer at it.
 */
new class extends Component
{
    public Profile $profile;

    /**
     * Snapshotted at mount. Arriving with the quest already cleared
     * collapses the hero so the chore board isn't pushed down the page on
     * every tab switch — but clearing it *during* this visit keeps the full
     * card on screen, so the moment still gets its celebration.
     */
    public bool $questDoneOnArrival = false;

    /**
     * Where the wheel is pointing, in degrees. Restored at mount from a spin
     * already taken today so a kid coming back finds the wheel parked on what
     * they landed on rather than reset to the top.
     */
    public float $wheelDeg = 0;

    public bool $spinning = false;

    public bool $spinRevealed = false;

    /**
     * Why a tap on the boosted chore didn't take. Separate from boardMessage
     * because the two are read in different places — this one belongs beside
     * the Active Boost card, not under the board.
     */
    public ?string $boostMessage = null;

    public ?string $perkMessage = null;

    /**
     * Why a tap on the board didn't take. Cooldowns are household-wide, so
     * the board a kid is looking at can go stale between renders — without
     * this the claim just silently no-ops and reads as a broken button.
     */
    public ?string $boardMessage = null;

    /**
     * Why taking a job on the bounty board didn't work. Same reasoning as
     * boardMessage: a sibling can take the same job a second before you do, and
     * a button that silently does nothing explains none of it.
     */
    public ?string $bountyMessage = null;

    /**
     * Why a card couldn't be taken. Same reasoning as boardMessage — the hand
     * is dealt from chores the whole household shares, so a sibling can claim
     * one out from under a kid who is still deciding.
     */
    public ?string $questCardMessage = null;

    public string $search = '';

    /**
     * Board states a kid can't act on right now.
     *
     * 'pending' is deliberately absent — their own claim waiting on a parent
     * is progress, and the card is the only proof the tap landed.
     *
     * @var array<int, string>
     */
    private const UNAVAILABLE_STATES = ['done', 'expired'];

    /**
     * Transient, like the search beside it — a board with half of it taken
     * looks very different an hour later, so this defaults back to showing
     * everything rather than quietly hiding chores that have since reopened.
     */
    public bool $hideUnavailable = false;

    public function toggleUnavailable(): void
    {
        $this->hideUnavailable = ! $this->hideUnavailable;
    }

    /**
     * Which price band is showing, as a {@see PriceBand} value — null for all.
     *
     * A six-year-old asks a parent for "a $2 job" over and over, and until now
     * the board could not answer him: no ordering control at all, and the only
     * filter a typed search, which is unusable by exactly the kid who needs it
     * most. Transient like the search beside it, for the same reason — a board
     * half-taken looks very different an hour later.
     */
    public ?int $band = null;

    /**
     * The live chip: a {@see ChoreCategory} value, or one of the three special
     * chips ('done', 'muscle'). Null for all.
     */
    public ?string $category = null;

    /** Open by default — see the adder's note in the template. */
    public bool $adderOpen = true;

    /** What the adding-up card is aiming at, in points. Set in mount(). */
    public int $target = 0;

    /**
     * The two chores the adder is holding, as ids. Never more, never fewer —
     * two copies of one job is not a plan, and a third slot is a shopping list.
     *
     * Empty until the first render fills it, which is also the repair path for
     * a chore a sibling claims mid-build: see syncSlots().
     *
     * **Not `$slots`.** Livewire reserves that name — `SupportSlots` reads
     * `$component->slots` and calls `getName()` on whatever it finds, so a
     * public `$slots` of anything else fataly breaks every render of the page.
     *
     * @var array<int, int>
     */
    public array $adderSlots = [];

    /**
     * Which job left a slot, and what took its place.
     *
     * A property rather than a local in with(), because **Livewire renders
     * twice per round trip**: the first render repairs the slots and has the
     * news, the second finds them already repaired and would drop it on the
     * floor. Cleared in hydrate(), so it lives for exactly the one request
     * that made it.
     */
    public ?string $slotNotice = null;

    /** @see $slotNotice */
    public function hydrate(): void
    {
        $this->slotNotice = null;
    }

    /** Dollars. The stepper clamps here, one dollar per tap. */
    private const TARGET_MIN_DOLLARS = 1;

    private const TARGET_MAX_DOLLARS = 20;

    private const TARGET_DEFAULT_DOLLARS = 4;

    /** Tapping the live band clears it — the control is its own off switch. */
    public function pickBand(int $band): void
    {
        $this->band = $this->band === $band ? null : $band;
    }

    public function pickCategory(string $category): void
    {
        $this->category = $this->category === $category ? null : $category;
    }

    public function toggleAdder(): void
    {
        $this->adderOpen = ! $this->adderOpen;
    }

    /**
     * A dollar more or less, clamped both ends.
     *
     * Presets ($2 / $5 / $10) were built and rejected: a kid who wants $4 had
     * to work out which button got him nearest and then do the sum anyway,
     * which is the exact arithmetic this card exists to remove.
     */
    public function stepTarget(int $dollars): void
    {
        $rate = $this->pointsPerDollar();

        $this->target = min(
            self::TARGET_MAX_DOLLARS * $rate,
            max(self::TARGET_MIN_DOLLARS * $rate, $this->target + $dollars * $rate),
        );
    }

    /** Steps one slot along the pool, wrapping, skipping whatever the other holds. */
    public function stepSlot(int $slot, int $direction): void
    {
        $pool = $this->adderPool();
        $this->syncSlots($pool);

        if ($this->adderSlots === []) {
            return;
        }

        $ids = $pool->pluck('id')->all();
        $at = array_search($this->adderSlots[$slot], $ids, true);

        if ($at === false) {
            return;
        }

        $other = $this->adderSlots[1 - $slot];

        // Terminates because syncSlots() guarantees at least two chores in the
        // pool, so there is always somewhere else to land.
        do {
            $at = ($at + $direction + count($ids)) % count($ids);
        } while ($ids[$at] === $other);

        $this->adderSlots[$slot] = $ids[$at];
    }

    /**
     * The cheapest pair that clears the target.
     *
     * O(n²) over the board, which at ~20 chores is nothing. Leaves the slots
     * alone when nothing reaches — the shortfall line then says how far off it
     * is, which is more use than silently rearranging two jobs that still
     * don't add up.
     */
    public function pickTwo(): void
    {
        $pool = $this->adderPool();
        $this->syncSlots($pool);

        if ($this->adderSlots === []) {
            return;
        }

        $chores = $pool->values()->all();
        $best = null;

        for ($i = 0; $i < count($chores); $i++) {
            for ($j = $i + 1; $j < count($chores); $j++) {
                $total = $chores[$i]->points + $chores[$j]->points;

                if ($total < $this->target || ($best !== null && $total >= $best['total'])) {
                    continue;
                }

                $best = ['total' => $total, 'pair' => [$chores[$i]->id, $chores[$j]->id]];
            }
        }

        if ($best !== null) {
            $this->adderSlots = $best['pair'];
        }
    }

    private function pointsPerDollar(): int
    {
        return max(1, (int) $this->profile->household->points_per_dollar);
    }

    /**
     * The chores the adder can put in a slot: everything claimable right now,
     * cheapest first.
     *
     * Cheapest first rather than in board order, because the arrows are used to
     * walk a total up and down — urgency ordering would make the running total
     * jump about at random. Deliberately ignores the band and the chip: the
     * adder answers a question the filtered list has already failed to answer.
     */
    private function adderPool(): \Illuminate\Support\Collection
    {
        return app(ChoreService::class)->boardFor($this->profile)
            ->filter(fn (array $entry) => $entry['state'] === 'ready')
            ->map(fn (array $entry) => $entry['chore'])
            ->sortBy(fn (Chore $chore) => [$chore->points, $chore->id])
            ->values();
    }

    /**
     * Repairs the slots against the pool, and says so when it had to.
     *
     * Cooldowns are household-wide, so a sibling can claim a chore sitting in a
     * slot while the kid is still adding up. Leaving a dead job there would
     * quote them a total they can't earn, so the slot steps to the next
     * available chore — and gets a line saying which job went, because a card
     * that rearranges itself silently reads as a bug.
     *
     * Empties the slots entirely on a board with fewer than two claimable
     * chores, which is how the template knows to leave the adder out.
     *
     * @param  \Illuminate\Support\Collection<int, Chore>  $pool
     */
    private function syncSlots(\Illuminate\Support\Collection $pool): void
    {
        $ids = $pool->pluck('id')->all();

        if (count($ids) < 2) {
            $this->adderSlots = [];

            return;
        }

        $gone = [];
        $chosen = [];

        foreach ([0, 1] as $slot) {
            $id = $this->adderSlots[$slot] ?? null;

            if ($id !== null && ! in_array($id, $ids, true)) {
                $gone[] = $id;
                $id = null;
            }

            // A duplicate is the same problem as a missing one, and worth no
            // notice: nothing was taken, the card just has to hold two jobs.
            $chosen[$slot] = $id !== null && ! in_array($id, $chosen, true) ? $id : null;
        }

        foreach ([0, 1] as $slot) {
            $chosen[$slot] ??= collect($ids)->first(fn (int $id) => ! in_array($id, $chosen, true));
        }

        $this->adderSlots = $chosen;

        if ($gone === []) {
            return;
        }

        $names = $this->profile->household->chores
            ->whereIn('id', $gone)
            ->pluck('name')
            ->all();

        $this->slotNotice = count($names) === 1
            ? "{$names[0]} just went — swapped in another job."
            : 'Those two just went — swapped in another pair.';
    }

    /**
     * The three boxes of the gratitude quest. Deferred rather than live —
     * nothing on the page reacts to a half-typed answer, so there's no reason
     * to spend a round trip per keystroke.
     *
     * @var array<int, string>
     */
    public array $gratitude = ['', '', ''];

    public ?string $gratitudeMessage = null;

    public function clearSearch(): void
    {
        $this->search = '';
    }

    /**
     * Livewire re-renders after any action, so the refresh is the round trip
     * itself. Clearing the message matters though: the board is about to show
     * whoever took the chore, and leaving the older wording next to it just
     * says the same thing twice.
     */
    public function refreshBoard(): void
    {
        $this->boardMessage = null;
        $this->bountyMessage = null;
    }

    /**
     * Take a job off the bounty board without leaving the page.
     *
     * The card is a window onto Trades & Jobs, so it only ever fires the claim
     * — the rest of the lifecycle (report done, confirm, send back, cancel)
     * lives on the page built for it, which is what the header link is for.
     */
    public function takeJob(int $bountyId): void
    {
        $this->bountyMessage = null;

        $bounty = Bounty::where('household_id', $this->profile->household_id)->find($bountyId);

        if (! $bounty) {
            $this->bountyMessage = 'That job is no longer there.';

            return;
        }

        try {
            app(BountyService::class)->claim($bounty, $this->profile);
        } catch (BountyUnavailableException|InsufficientPointsException|InsufficientTicketsException $e) {
            // Losing the race is the ordinary outcome here, not an error page:
            // the row refreshes to its new state with the reason beside it.
            $this->bountyMessage = $e->getMessage();

            return;
        }

        // Paying for an offered job moves the balance in the header, so pull it
        // back before the response re-renders with a stale number on it.
        $this->profile->refresh();

        $this->dispatch(
            'celebrate',
            message: $bounty->kind->posterPays()
                ? "It's yours — go and do it!"
                : 'Hired! They will let you know when it is done.',
            motion: 'burst',
            origin: 'tap',
        );
    }

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isKid(), 403);

        $this->questDoneOnArrival = app(ChoreService::class)->isQuestDoneToday($this->profile);

        // $4 in this household's own money. The bands and the stepper are
        // declared in dollars and resolved against points_per_dollar, so a
        // household that rates a chore differently still gets a "$2–5" button
        // that means $2 to $5.
        $this->target = self::TARGET_DEFAULT_DOLLARS * $this->pointsPerDollar();

        $spins = app(SpinService::class);
        $spinToday = $spins->today($this->profile);

        if ($spinToday) {
            $chores = $this->wheelChores();
            $slice = 360 / max(1, $chores->count());
            $index = $chores->search(fn ($chore) => $chore->id === $spinToday->chore_id);

            $this->wheelDeg = $this->restingDeg((int) $index, $slice);
            $this->spinRevealed = true;
        }
    }

    /**
     * Opens the quest chest, putting three cards on the table.
     *
     * No celebration here: the chest no longer reveals anything, it deals. The
     * card that gets announced is the one they choose.
     */
    public function dealHand(): void
    {
        app(ChoreService::class)->dealQuestHand($this->profile);
    }

    /**
     * Takes one of today's cards. The other two have already burned client-side
     * by the time this runs — see <x-quest-cards>.
     */
    public function chooseQuest(int $choreId): void
    {
        $this->questCardMessage = null;

        $service = app(ChoreService::class);
        $quest = $service->chooseQuest($this->profile, $choreId);

        if (! $quest) {
            // Almost always a sibling getting there first. The cards re-render
            // with the claimant named on whichever one went, so the message
            // only has to explain why the tap bounced.
            $this->questCardMessage = "That one just went — pick another card.";

            return;
        }

        // The charm's hand-in roll is deliberately not part of this number:
        // it hasn't happened yet, and quoting a total that later grows is a
        // better surprise than one that appears to shrink.
        $bonus = $service->cardBonusesFor($this->profile)[$quest->chore_id] ?? 0;
        $points = $quest->chore->points * app(SpinService::class)->multiplierFor($this->profile, $quest->chore) + $bonus;

        // Dispatched from the server rather than from the card, so it can only
        // fire on a pick that actually landed.
        $this->dispatch(
            'celebrate',
            style: 'confetti',
            motion: 'burst',
            origin: 'tap',
            tier: 'big',
            hold: 2600,
            card: [
                'accent' => $bonus > 0 ? 'var(--fq-gold)' : 'var(--fq-lime)',
                'sub' => $bonus > 0 ? 'Bold Quest Taken' : "Today's Quest",
                'label' => $quest->chore->name,
                'note' => '+'.number_format($points).' PTS',
            ],
        );
    }

    /**
     * Kept for the paths that need a quest simply decided rather than chosen —
     * see ChoreService::revealQuest().
     */
    public function revealQuest(): void
    {
        app(ChoreService::class)->revealQuest($this->profile);
    }

    public function claimQuest(): void
    {
        $service = app(ChoreService::class);

        // The chest has to be opened before the quest can be claimed.
        if (! $service->isQuestRevealedToday($this->profile)) {
            return;
        }

        $quest = $service->questFor($this->profile);
        $wasDone = $service->isQuestDoneToday($this->profile);
        $boosted = app(SpinService::class)->multiplierFor($this->profile, $quest->chore) > 1;

        $service->claimQuest($this->profile);

        // Read after the claim, which is what settles it. This is the charm's
        // second chance and the reason it can't really be wasted — a hand that
        // looked ordinary can still pay here.
        $charmPayout = $service->charmPayoutFor($this->profile);

        // The streak (and any milestone bonus) now moves on a parent's
        // approval, so don't quote a day count here that hasn't been earned
        // yet — and when it is earned, the chest still does the reveal. A quest
        // that happens to be the mystery chore says nothing about it either,
        // for the same reason claimChore() doesn't.
        if (! $wasDone) {
            if ($boosted) {
                $this->dispatch('celebrate', message: 'Quest cleared! Bonus wheel treat earned.', treat: 'cookie', motion: 'burst', origin: 'tap');
            } else {
                $this->dispatch('celebrate', message: 'Quest cleared! Your streak grows once a parent approves.', motion: 'burst', origin: 'tap');
            }

            // Queued behind the clear rather than folded into it: it's a second
            // piece of news, and it is the whole reason the charm was bought.
            if ($charmPayout > 0) {
                $this->dispatch(
                    'celebrate',
                    message: 'The charm paid out — +'.number_format($charmPayout).' bonus points!',
                    style: 'star',
                    motion: 'burst',
                    origin: 'tap',
                );
            }
        }
    }

        /**
     * The gratitude quest. Both refusals are worth their own wording: one is
     * "you missed a box", the other is "you already did this today", and a
     * button that silently does nothing explains neither.
     */
    /**
     * Answer last night's own-bed card.
     *
     * Every outcome celebrates, including the two that don't light a star —
     * with hearts rather than coins, because a kid who came in at 3am and said
     * so honestly has done the thing this card is actually for.
     */
    public function answerSleep(string $outcome): void
    {
        $choice = SleepOutcome::tryFrom($outcome);

        if (! $choice) {
            return;
        }

        try {
            $result = app(SleepService::class)->record($this->profile, $choice);
        } catch (RuntimeException) {
            // Already answered, or switched off mid-visit. The card re-renders
            // showing what they said, which explains it better than a message.
            return;
        }

        $this->profile->refresh();

        $this->dispatch(
            'celebrate',
            // Finishing a picture is the bigger news and wins the headline; a
            // plain good night still says what it paid, because that is the
            // reward for pressing the button at all. A tapered-out household
            // pays nothing, and "+0 pts" would read as being shortchanged.
            message: match (true) {
                $result['constellation'] && $result['constellationPoints'] > 0 => $result['constellation']->label()
                    .' complete! +'.number_format($result['constellationPoints'] + $result['nightPoints']).' pts',
                (bool) $result['constellation'] => $result['constellation']->label().' complete!',
                // Every answer can pay now, so the headline follows the answer
                // rather than assuming a perfect night.
                $result['nightPoints'] > 0 => $choice->countsAsOwnBed()
                    ? 'A night in your own bed! +'.number_format($result['nightPoints']).' pts'
                    : $choice->response().' +'.number_format($result['nightPoints']).' pts',
                default => $choice->response(),
            },
            style: $result['constellation'] || $result['nightPoints'] > 0 ? 'money' : 'heart',
            motion: 'burst',
            origin: 'tap',
        );
    }

    /**
     * Answer last night's hours card.
     *
     * Takes minutes rather than a band, and the service works out which band
     * that is — a kid who edits the wire can change what they claim to have
     * slept, which is between them and their conscience, but they can't pick
     * the payout directly.
     */
    public function answerSleepHours(int $minutes): void
    {
        try {
            $result = app(SleepService::class)->recordHours($this->profile, $minutes);
        } catch (RuntimeException) {
            // Already answered, or switched off mid-visit. The card re-renders
            // showing what they said, which explains it better than a message.
            return;
        }

        $this->profile->refresh();

        $this->dispatch(
            'celebrate',
            // Hearts rather than coins on the nights that didn't pay: a kid who
            // slept badly and said so has still done the thing this card is
            // for, and "+0 pts" would read as being shortchanged for it.
            message: $result['nightPoints'] > 0
                ? $result['band']->label().' — '.SleepBand::say($result['minutes'])
                    .'! +'.number_format($result['nightPoints']).' pts'
                : $result['band']->response(),
            style: $result['nightPoints'] > 0 ? 'money' : 'heart',
            motion: 'burst',
            origin: 'tap',
        );
    }

    public function openSleepChest(): void
    {
        $opened = app(SleepService::class)->openChest($this->profile);

        if (! $opened) {
            return;
        }

        $this->profile->refresh();

        $this->dispatch(
            'celebrate',
            message: $opened['nights'].' nights in a row! +'.$opened['tickets'].' tickets',
            style: 'star',
            motion: 'burst',
            origin: 'tap',
        );
    }

    public function logGratitude(): void
    {
        $service = app(GratitudeService::class);

        if ($service->record($this->profile, $this->gratitude)) {
            $this->gratitude = ['', '', ''];
            $this->gratitudeMessage = null;

            // Hearts, not coins — this is the one quest that isn't about
            // earning anything, and the tickets are a thank-you rather than
            // the point of it.
            $this->dispatch(
                'celebrate',
                message: 'Gratitude logged! +'.GratitudeService::TICKETS.' tickets.',
                style: 'heart',
                motion: 'burst',
                origin: 'tap',
            );

            return;
        }

        $this->gratitudeMessage = $service->isAvailable($this->profile)
            ? 'Fill in all three before you hand it in.'
            : "Today's gratitude quest is already done — back tomorrow!";
    }
    /**
     * Every perk with a button on this page: the quest charm, the reroll and
     * the mystery hint on the board, and the wheel respin beside the spin.
     */
    public function usePerk(string $effect): void
    {
        $case = PerkEffect::tryFrom($effect);

        if (! $case) {
            return;
        }

        try {
            $outcome = app(PerkInventoryService::class)->use($this->profile, $case);
            $this->perkMessage = null;
        } catch (PerkUnavailableException $e) {
            $this->perkMessage = $e->getMessage();

            return;
        }

        if ($case === PerkEffect::WheelRespin) {
            // Put the wheel back where it started so the second spin happens in
            // place rather than on a wheel still parked on the first result.
            $this->spinRevealed = false;
            $this->spinning = false;
            $this->wheelDeg = 0;

            $this->dispatch('celebrate', message: 'Wheel reset — take another spin!', style: $case->celebrationStyle());

            return;
        }

        // Same styles as the Bonus Shop's own copy of this — a Streak Restore
        // used from the board and one used from the shop are the same moment
        // and must not celebrate differently.
        $this->dispatch(
            'celebrate',
            message: $outcome,
            style: $case->celebrationStyle(),
            motion: 'burst',
            origin: 'tap',
        );
    }

    /**
     * Buys a Quest Charm without leaving the page.
     *
     * The charm is the one perk whose whole value is spent in a window that
     * closes: it can only be cast on a chest that is still shut, and a kid who
     * has to go to the Bonus Shop to buy one comes back to a page they have
     * usually opened by then. Every other perk keeps until you need it, which
     * is why this shortcut exists here and not on all of them.
     */
    public function buyQuestCharm(): void
    {
        $this->buyPerk(PerkEffect::QuestCharm, 'cast it before you open the chest!');
    }

    /**
     * Sold from beside the wheel for the same reason the charm is sold from
     * the hero: the window to use one closes the moment the wheel goes, and a
     * kid who has to leave for the shop first comes back to a spent spin.
     */
    public function buyOpSpin(): void
    {
        $this->buyPerk(PerkEffect::OpSpin, 'charge the wheel before you spin!');
    }

    /**
     * Goes through BonusShopService like the shop does, so the ticket spend,
     * the refusals and the ledger entry are the same on both routes.
     */
    private function buyPerk(PerkEffect $effect, string $suffix): void
    {
        $perk = BonusPerk::where('household_id', $this->profile->household_id)
            ->enabled()
            ->where('effect', $effect)
            ->first();

        // A parent can switch a perk off from the console, in which case the
        // button isn't rendered and this is a stale tab.
        if (! $perk) {
            return;
        }

        try {
            app(BonusShopService::class)->purchase($this->profile, $perk);
            $this->perkMessage = null;
            $this->dispatch('celebrate', message: "{$perk->name} bought — {$suffix}", style: 'ticket', motion: 'burst', origin: 'tap');
        } catch (InsufficientTicketsException|PerkUnavailableException $e) {
            $this->perkMessage = $e->getMessage();
        }
    }

    public function spin(): void
    {
        if ($this->spinning || $this->spinRevealed || ! $this->profile->household->spin_enabled) {
            return;
        }

        $spins = app(SpinService::class);

        if ($spins->hasSpunToday($this->profile)) {
            $this->spinRevealed = true;

            return;
        }

        // The family can clear the board before a kid gets round to spinning.
        // SpinService throws on an empty pool, and nothing here catches it, so
        // the guard has to be in front of the call rather than around it.
        if ($this->wheelChores()->isEmpty()) {
            return;
        }

        $result = $spins->spin($this->profile);

        $chores = $this->wheelChores();
        $slice = 360 / max(1, $chores->count());
        $index = $chores->search(fn ($chore) => $chore->id === $result->chore_id);

        $target = $this->restingDeg((int) $index, $slice);

        while ($target <= $this->wheelDeg) {
            $target += 360;
        }

        $this->wheelDeg = $target + 360 * 6;
        $this->spinning = true;
    }

    public function finishSpin(): void
    {
        $this->spinning = false;
        $this->spinRevealed = true;

        $result = app(SpinService::class)->today($this->profile);

        if ($result) {
            $this->dispatch(
                'celebrate',
                message: "{$result->multiplier}x boost on {$result->chore->name}!",
                style: 'confetti',
                big: $result->multiplier >= 3,
            );
        }

        app(BadgeService::class)->evaluate($this->profile);
    }

    /**
     * The pointer sits at the top of the wheel. Segment 0 starts at local
     * angle 0° (3 o'clock, before any rotation) and runs clockwise — same
     * convention as the segment/label markup below — so resting here is
     * what actually brings a chore's slice under the pointer at the top.
     */
    private function restingDeg(int $index, float $slice): float
    {
        return -90 - ($index * $slice + $slice / 2);
    }

    /**
     * What the wheel can land on, or nothing at all.
     *
     * Guarded because the eligible pool is built by excluding today's quest
     * hand, so asking for it *deals* one — and a household with no chores at
     * all makes that throw. Every entry point to the wheel goes through here.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Chore>
     */
    private function wheelChores(): \Illuminate\Support\Collection
    {
        try {
            return app(SpinService::class)->eligibleChoresFor($this->profile);
        } catch (\RuntimeException) {
            return collect();
        }
    }

    /**
     * What the Active Boost card can offer for the chore the wheel landed on:
     * the claim itself, or the reason it isn't available.
     *
     * @return ?array{claimable: bool, label: string, note: ?string}
     */
    private function boostClaim(?Spin $boost): ?array
    {
        if (! $boost) {
            return null;
        }

        $service = app(ChoreService::class);
        $chore = $boost->chore;
        $quest = $service->questFor($this->profile);

        // The chest reveal is the whole ceremony of the main quest, so a boost
        // that landed on it gets pointed back up the page rather than quietly
        // claimed from under an unopened chest.
        if ($quest->chore_id === $chore->id) {
            return [
                'claimable' => false,
                'label' => 'This is your main quest',
                'note' => 'Open the chest at the top of this page to claim it.',
            ];
        }

        $state = $service->stateFor($this->profile, $chore);
        $claimant = $service->claimantFor($chore);

        return match (true) {
            $state === 'ready' => ['claimable' => true, 'label' => 'Mark it done', 'note' => null],
            $state === 'pending' => ['claimable' => false, 'label' => 'Waiting on a parent', 'note' => null],
            $state === 'expired' => ['claimable' => false, 'label' => "Time's up", 'note' => 'A parent is taking that one.'],
            $claimant && $claimant->profile_id !== $this->profile->id => [
                'claimable' => false,
                'label' => $claimant->profile->name.' got this one',
                'note' => null,
            ],
            default => ['claimable' => false, 'label' => 'Already done today', 'note' => null],
        };
    }

    /**
     * Claims the boosted chore from the wheel card rather than from its row on
     * the board below. Every guard the board applies is re-run here — a
     * disabled button in a browser is never the thing standing between a kid
     * and a double claim.
     */
    public function claimBoostedChore(): void
    {
        $this->boostMessage = null;

        $boost = app(SpinService::class)->today($this->profile);
        $claim = $this->boostClaim($boost);

        if (! $claim) {
            return;
        }

        if (! $claim['claimable']) {
            $this->boostMessage = $claim['note'] ?? $claim['label'].'.';

            return;
        }

        $chore = $boost->chore;

        if (! $chore->isAppropriateFor($this->profile)) {
            return;
        }

        // Silent about the mystery chore on purpose — the find is announced
        // once a parent approves the work, by the card the kid shell queues.
        $this->dispatch(
            'celebrate',
            message: "{$chore->name} claimed at {$boost->multiplier}x! Bonus wheel treat earned.",
            treat: 'cookie',
            motion: 'burst',
            origin: 'tap',
        );

        app(ChoreService::class)->claim($this->profile, $chore);
    }

    public function claimChore(int $choreId): void
    {
        $this->boardMessage = null;

        $this->completeChore($choreId);
    }

    /**
     * Everything that has to be true before a chore can be claimed, re-checked
     * server-side — never trust a disabled button in the browser.
     *
     */
    private function choreIsClaimable(int $choreId): bool
    {
        $chore = Chore::find($choreId);

        if (! $chore || $chore->household_id !== $this->profile->household_id) {
            return false;
        }

        $service = app(ChoreService::class);
        $quest = $service->questFor($this->profile);

        // stateFor() already accounts for the mystery chore's household-wide
        // (not per-kid) exclusivity, so no special-casing is needed here.
        if ($chore->id === $quest->chore_id || ! $chore->isAppropriateFor($this->profile)) {
            return false;
        }

        // The one rejection worth explaining: nothing about this kid changed,
        // someone else in the house just got there first. Polling shrinks the
        // window but can never close it, so the message has to exist.
        if ($service->stateFor($this->profile, $chore) !== 'ready') {
            $claimant = $service->claimantFor($chore);

            $this->boardMessage = match (true) {
                $claimant && $claimant->profile_id !== $this->profile->id => "{$claimant->profile->name} got to {$chore->name} first!",
                // Worth its own wording: nobody beat them to it, the clock did,
                // and "isn't available" would leave them refreshing for a chore
                // that isn't coming back until tomorrow.
                $service->isExpired($chore) => "Time's up on {$chore->name} — a parent is taking that one. Back tomorrow!",
                default => "{$chore->name} isn't available right now.",
            };

            return false;
        }

        return true;
    }

    private function completeChore(int $choreId): void
    {
        if (! $this->choreIsClaimable($choreId)) {
            return;
        }

        $chore = Chore::findOrFail($choreId);
        $boosted = app(SpinService::class)->multiplierFor($this->profile, $chore) > 1;

        // Nothing here says anything about the mystery chore, deliberately.
        // Announcing the find on the tap told a kid which chore carried the
        // bonus for the price of submitting it, so submitting everything on the
        // board was a way to be told the answer. It's announced when a parent
        // approves the work, by the card the kid shell queues.
        if ($boosted) {
            $this->dispatch('celebrate', message: "{$chore->name} claimed! Bonus wheel treat earned.", treat: 'cookie', motion: 'burst', origin: 'tap');
        } else {
            $this->dispatch('celebrate', message: "{$chore->name} claimed! Waiting on parent.", motion: 'burst', origin: 'tap');
        }

        app(ChoreService::class)->claim($this->profile, $chore);
    }

    /** The monster standing, as the strip and the watcher want it. */
    private function monsterState(): ?array
    {
        $arena = app(MonsterService::class);
        $monster = $arena->rotateWeakness($this->profile->household);

        return $monster ? $arena->stateFor($monster) : null;
    }

    public function with(): array
    {
        $service = app(ChoreService::class);
        $spin = app(SpinService::class);
        $inventory = app(PerkInventoryService::class);

        // A Livewire round trip doesn't pass back through the route middleware
        // that expires a lapsed streak, and this is the page a kid is most
        // likely to be sitting on when the household day rolls over.
        app(StreakService::class)->syncStreak($this->profile);

        $board = $service->boardFor($this->profile);

        // Hidden before the search rather than after, so the search's "2 / 5"
        // counter is measured against the board actually on screen.
        $isUnavailable = fn (array $entry) => in_array($entry['state'], self::UNAVAILABLE_STATES, true);
        $shown = $this->hideUnavailable ? $board->reject($isUnavailable) : $board;

        $quest = $service->questFor($this->profile);
        $questRevealed = $quest->revealed_at !== null;
        $questDone = $quest->completed_at !== null;

        // The hand, and what each card is worth. Built here rather than in the
        // component so the card can name a claimant the same way the board
        // does — this is the one page that already has that lookup to hand.
        $hand = $service->offeredChoresFor($this->profile);
        $cardBonuses = $service->cardBonusesFor($this->profile);

        $questCards = $hand->map(fn (Chore $chore) => [
            'chore' => $chore,
            'points' => $chore->points,
            'bonus' => $cardBonuses[$chore->id] ?? 0,
            'bold' => isset($cardBonuses[$chore->id]),
            'takenBy' => $service->claimantOtherThan($chore, $this->profile)?->profile,
            'expired' => $service->isExpired($chore),
        ]);

        // Claiming the quest sets completed_at immediately, but the points
        // don't land until a parent approves — so "done" and "waiting" are
        // different things and the CTA has to say which one it is. Scoped to
        // today's household day rather than to completed_at, because a sent-back
        // quest clears that stamp and the attempt still has to be findable —
        // that's what turns the CTA into "have another go" instead of a bare
        // "Mark it done" with no hint anything happened.
        $clock = HouseholdClock::for($this->profile->household);

        $questCompletion = ChoreCompletion::where('profile_id', $this->profile->id)
            ->where('chore_id', $quest->chore_id)
            ->where('submitted_at', '>=', $clock->startOf($clock->today()))
            ->latest('submitted_at')
            ->first();

        $questApproved = $questCompletion?->status === CompletionStatus::Approved;
        $questPending = $questCompletion?->status === CompletionStatus::Pending;
        $questSentBack = $questCompletion?->status === CompletionStatus::Rejected;

        $boost = $spin->today($this->profile);
        $wheelChores = $this->wheelChores();
        $questBoosted = $boost && $boost->chore_id === $quest->chore_id;

        $household = $this->profile->household;

        $mysteryChore = $service->mysteryChoreFor($household);

        // Whoever a parent has actually signed off, not whoever tapped first —
        // a pending claim used to name the chore here, which handed the answer
        // to anyone willing to submit the whole board.
        $mysteryToday = $service->mysteryTodayFor($household);
        $mysteryFinder = $mysteryToday?->foundBy;

        $gratitude = app(GratitudeService::class);

        $earnedToday = $service->pointsEarnedToday($this->profile);

        // --- The side-quest board: price bands, chips and the adding-up card.
        //
        // All of it filtered in PHP over the collection boardFor() already
        // returned, never re-queried — same reasoning as the search below it.
        $rate = $this->pointsPerDollar();
        $doneBefore = $service->choresDoneBefore($this->profile);

        // Resolved once per chore so the row's tags, the chips and the filter
        // can't disagree about what a job is.
        $flagged = $shown->map(fn (array $entry) => $entry + [
            'muscle' => $entry['chore']->isHeavy(),
            'doneBefore' => in_array($entry['chore']->id, $doneBefore, true),
            'category' => ChoreCategory::forChore($entry['chore']),
        ]);

        $band = $this->band === null ? null : PriceBand::tryFrom($this->band);

        $matchesChip = fn (array $entry): bool => match ($this->category) {
            null => true,
            'done' => $entry['doneBefore'],
            'muscle' => $entry['muscle'],
            default => $entry['category']->value === $this->category,
        };

        $filtered = $flagged
            ->filter(fn (array $entry) => $entry['chore']->matches($this->search))
            ->filter(fn (array $entry) => $band === null || $band->contains($entry['chore']->points, $rate))
            ->filter($matchesChip)
            ->values();

        // A chip that leads nowhere is worse than no chip, so every one of them
        // — the two special ones included — has to have something behind it.
        // Muscle is empty on every board until a parent flags a chore, since
        // effort is the one axis nothing guesses at. Outside used to sit here
        // as a third; it is a category now, so a chore is Outside *or* Garden
        // rather than both, and a parent decides which.
        $chips = collect([
            ['id' => 'done', 'label' => 'Done before', 'fa' => 'fa-solid fa-rotate-left', 'has' => fn (array $e) => $e['doneBefore']],
            ['id' => 'muscle', 'label' => 'Muscle', 'fa' => 'fa-solid fa-dumbbell', 'has' => fn (array $e) => $e['muscle']],
        ])
            ->filter(fn (array $chip) => $flagged->contains($chip['has']))
            ->concat(
                // Enum order, not board order, so the row doesn't reshuffle
                // itself as chores are claimed through the day.
                collect(ChoreCategory::cases())
                    ->filter(fn (ChoreCategory $case) => $flagged->contains(fn (array $e) => $e['category'] === $case))
                    ->map(fn (ChoreCategory $case) => [
                        'id' => $case->value,
                        'label' => $case->label(),
                        'fa' => $case->faClass(),
                    ]),
            )
            ->map(fn (array $chip) => [
                'id' => $chip['id'],
                'label' => $chip['label'],
                'fa' => $chip['fa'],
                'selected' => $this->category === $chip['id'],
            ])
            ->values();

        // Counted off the whole board rather than the filtered one: these are
        // what the buttons *offer* to show, so they have to survive being on.
        $bands = collect(PriceBand::cases())->map(fn (PriceBand $case) => [
            'band' => $case,
            'count' => $flagged->filter(fn (array $entry) => $case->contains($entry['chore']->points, $rate))->count(),
            'selected' => $this->band === $case->value,
        ]);

        $adderPool = $this->adderPool();
        $this->syncSlots($adderPool);
        $slotChores = collect($this->adderSlots)
            ->map(fn (int $id) => $adderPool->firstWhere('id', $id))
            ->filter()
            ->values();
        $slotTotal = (int) $slotChores->sum('points');

        return [
            'quest' => $quest,
            // A quest chore that expires is rerolled by questFor() rather than
            // left to dead-end the day, so this is only ever a live deadline.
            'questClosesAt' => $service->deadlineFor($quest->chore),
            'questRevealed' => $questRevealed,
            // The chest and the pick are separate stamps: the chest stays open
            // across a refresh while the kid is still deciding, which is what
            // stops the 2.6s rattle replaying every time they look at the page.
            'handDealt' => $quest->dealt_at !== null,
            'questCards' => $questCards,
            'questBoldBonus' => $cardBonuses[$quest->chore_id] ?? 0,
            // Null until the charm is cast, and its effect stays null until the
            // chest is opened — the two together are what the cards' charm
            // strip reads.
            'questCharm' => $quest->isCharmed() ? $quest->charm_effect : null,
            'questCharmed' => $quest->isCharmed(),
            'questCharmPayout' => $service->charmPayoutFor($this->profile),
            'questDone' => $questDone,
            'questApproved' => $questApproved,
            'questPending' => $questPending,
            'questSentBack' => $questSentBack,
            'boost' => $boost,
            'boostClaim' => $this->boostClaim($boost),
            'wheelChores' => $wheelChores,
            'wheelSlice' => 360 / max(1, $wheelChores->count()),
            'respin' => $inventory->holds($this->profile, PerkEffect::WheelRespin)
                ? [
                    'effect' => PerkEffect::WheelRespin,
                    'count' => $inventory->countOf($this->profile, PerkEffect::WheelRespin),
                    'blocked' => $inventory->blockedReason($this->profile, PerkEffect::WheelRespin),
                ]
                : null,
            // The charge already on the wheel, the charge in their pocket, and
            // the one they could buy — three states of the same control, and
            // only ever one of them is on screen.
            'wheelCharged' => $spin->isCharged($this->profile),
            'opSpin' => $inventory->holds($this->profile, PerkEffect::OpSpin)
                ? [
                    'effect' => PerkEffect::OpSpin,
                    'count' => $inventory->countOf($this->profile, PerkEffect::OpSpin),
                    'blocked' => $inventory->blockedReason($this->profile, PerkEffect::OpSpin),
                ]
                : null,
            'opSpinForSale' => $inventory->holds($this->profile, PerkEffect::OpSpin) || $spin->isCharged($this->profile)
                ? null
                : BonusPerk::where('household_id', $household->id)
                    ->enabled()
                    ->where('effect', PerkEffect::OpSpin)
                    ->first(),
            'questBoosted' => $questBoosted,
            // The bold card's bonus rides on top of any wheel multiplier
            // rather than being multiplied by it — see BOLD_CARD_BONUS_PERCENT.
            'questPoints' => $quest->chore->points * ($questBoosted ? $boost->multiplier : 1)
                + ($cardBonuses[$quest->chore_id] ?? 0)
                + $service->charmPayoutFor($this->profile),
            // Filtered in PHP rather than re-queried — the board is already
            // loaded, and Chore::matches() is the in-memory twin of the
            // scope the parent admin searches with.
            'board' => $filtered,
            // "18 open" when nothing is filtered, "6 of 18" when something is.
            // The first is a board to browse; the second is a board with a
            // question asked of it, and the denominator is what says so.
            'boardCount' => $this->band !== null || $this->category !== null || trim($this->search) !== ''
                ? $filtered->count().' of '.$flagged->count()
                : $flagged->count().' open',
            'bands' => $bands,
            'chips' => $chips,
            'pointsPerDollar' => $rate,
            // Two chores, or none at all — a board with fewer than two
            // claimable jobs has nothing to add up.
            'slotChores' => $slotChores,
            'slotTotal' => $slotTotal,
            'slotNotice' => $this->slotNotice,
            'targetMin' => self::TARGET_MIN_DOLLARS * $rate,
            // Counted off the whole board, never off $shown — it's the number
            // the toggle offers to bring back, so it has to survive being on.
            'unavailableCount' => $board->filter($isUnavailable)->count(),
            'mysteryChore' => $mysteryChore,
            'mysteryFinder' => $mysteryFinder,
            // Stamped on the card so "found" reads as a moment in the day
            // rather than as a state the page has always been in.
            'mysteryFoundAt' => $mysteryToday?->found_at,
            'mysteryHint' => $service->mysteryHintFor($this->profile),
            // Today's only. Everything older lives on the Journal tab — this
            // page is about the day in front of you.
            'gratitudeToday' => $gratitude->todayFor($this->profile),
            // Null unless both the household and this kid have it switched on,
            // which is what keeps the card off every other kid's page.
            'sleepCard' => app(SleepService::class)->cardFor($this->profile),
            // Contextual "use it here" buttons for the perks that act on this
            // page, so a kid doesn't have to go hunting in the shop.
            'heldPerks' => collect([PerkEffect::QuestReroll, PerkEffect::MysteryHint, PerkEffect::QuestCharm])
                ->filter(fn (PerkEffect $effect) => $inventory->holds($this->profile, $effect))
                ->mapWithKeys(fn (PerkEffect $effect) => [$effect->value => [
                    'effect' => $effect,
                    'count' => $inventory->countOf($this->profile, $effect),
                    'blocked' => $inventory->blockedReason($this->profile, $effect),
                ]]),
            // The catalogue row, only when they're holding none — that's the
            // whole condition for offering to sell one. Null when a parent has
            // switched the charm off, which takes the button with it.
            'charmForSale' => $inventory->holds($this->profile, PerkEffect::QuestCharm)
                ? null
                : BonusPerk::where('household_id', $household->id)
                    ->enabled()
                    ->where('effect', PerkEffect::QuestCharm)
                    ->first(),
            'household' => $household,
            // Only the watching monster in the background now — the boss card
            // itself moved to Home. Status only, no replay, nothing marked seen.
            'monsterState' => $this->monsterState(),
            // A window onto Trades & Jobs: only what this kid could take right
            // now, with the link carrying everything else.
            'bountyBoard' => app(BountyService::class)->boardFor($this->profile),
            'bountiesWaiting' => app(BountyService::class)->waitingOn($this->profile),
            // The plan made on the Goal Planner, reported back where the work
            // actually happens — a target you only see on the page you set it
            // on is a wish rather than something to play against.
            'dailyGoal' => $this->profile->daily_points_goal,
            'earnedToday' => $earnedToday,
            'dailyGoalPercent' => $this->profile->daily_points_goal > 0
                ? min(100, (int) round($earnedToday / $this->profile->daily_points_goal * 100))
                : 0,
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="quests" refresh-action="refreshBoard">
    {{-- The monster, watching the board being cleared. --}}
    @if ($monsterState)
        <x-monster-watcher :state="$monsterState" />
    @endif

    {{-- One column: what you owe today, the quest that gates everything, and
         only then the board itself and the things hanging off it. --}}
    <div class="flex flex-col gap-4">
        {{-- 1. Today's Target --}}
        <div class="flex flex-wrap items-center gap-4 rounded-[18px] border border-fq-line bg-fq-panel px-4 py-[13px]">
            <h3 class="font-baloo text-[17px] font-bold whitespace-nowrap">Today's Target</h3>

            @if ($dailyGoal)
                <div class="h-[12px] min-w-[180px] flex-1 overflow-hidden rounded-full bg-fq-track">
                    <div
                        class="h-full rounded-full transition-[width] duration-500"
                        style="width:{{ $dailyGoalPercent }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime))"
                    ></div>
                </div>

                <span class="font-mono-fq text-[11px] whitespace-nowrap {{ $earnedToday >= $dailyGoal ? 'text-fq-lime' : 'text-fq-text-4' }}">
                    {{ number_format($earnedToday) }} / {{ number_format($dailyGoal) }} PTS
                    @if ($earnedToday >= $dailyGoal)
                        · SMASHED
                    @else
                        · {{ number_format($dailyGoal - $earnedToday) }} TO GO
                    @endif
                </span>
            @else
                <p class="min-w-[180px] flex-1 text-[13px] text-fq-text-5">
                    Pick a points-a-day target and see when you'll get what you're saving for.
                </p>
                <a
                    href="{{ route('kid.goal') }}"
                    wire:navigate
                    class="rounded-[12px] border border-fq-line-3 bg-fq-sunk px-[14px] py-[9px] text-[13px] whitespace-nowrap text-fq-text-2-b transition hover:border-fq-lime hover:text-fq-text"
                >Make a plan</a>
            @endif

            @if ($mysteryChore)
                {{-- The entry point to the mystery card, which used to hang off
                     the loot tray. The tray went to Home with the chests; the
                     mystery chore did not, so its pointer moved up here where a
                     kid still passes it on the way into the board.

                     It scrolls rather than jumps: the card's position is fixed
                     (always under the side quests) and the point is to show a
                     kid where it lives, not to teleport them. --}}
                <button
                    type="button"
                    x-data
                    @click="
                        const card = document.getElementById('mystery-card');
                        if (card) window.scrollTo({ top: card.getBoundingClientRect().top + window.scrollY - 16, behavior: 'smooth' });
                    "
                    class="inline-flex cursor-pointer items-center gap-[7px] rounded-full border border-fq-badge-line px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.12em] whitespace-nowrap uppercase transition hover:brightness-125"
                    style="background: var(--fq-tab-active); color: var(--fq-magenta)"
                >
                    <span
                        class="h-[7px] w-[7px] shrink-0 rounded-full"
                        style="background: var(--fq-magenta){{ $mysteryFinder ? '' : '; animation: fq-pulse 1.8s ease-in-out infinite' }}"
                    ></span>
                    {{ $mysteryFinder ? 'Mystery chore found →' : 'Mystery chore live' }}
                </button>
            @endif
        </div>
        {{-- 2. Main quest chest. There is exactly one place on this page to
             tap it, and the card itself is shared with Home, which opens the
             same chest at the top of its own page — see <x-quest-hero>. --}}
        <x-quest-hero
            :profile="$profile"
            :household="$household"
            :quest="$quest"
            :quest-done-on-arrival="$questDoneOnArrival"
            :quest-revealed="$questRevealed"
            :hand-dealt="$handDealt"
            :quest-cards="$questCards"
            :quest-charm="$questCharm"
            :quest-charmed="$questCharmed"
            :quest-charm-payout="$questCharmPayout"
            :quest-bold-bonus="$questBoldBonus"
            :quest-points="$questPoints"
            :quest-closes-at="$questClosesAt"
            :quest-done="$questDone"
            :quest-approved="$questApproved"
            :quest-pending="$questPending"
            :quest-sent-back="$questSentBack"
            :quest-card-message="$questCardMessage"
            :boost="$boost"
            :quest-boosted="$questBoosted"
            :charm-for-sale="$charmForSale"
            :held-perks="$heldPerks"
        />
        @if ($perkMessage)
            <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">
                {{ $perkMessage }}
            </div>
        @endif

        {{-- The own-bed card, above gratitude because it asks about last night
             and the morning is when it makes sense to answer. Absent entirely
             unless a parent has switched it on for this kid.

             The Night Chest used to sit below it as a flat rectangle of its
             own, separated from the run that earns it. It is a rail inside the
             card now, drawn as the actual chest mark. --}}
        {{-- Which card depends on what this kid has graduated to. Two
             components rather than one that branches: they share the chest and
             the run, and nothing else — the own-bed one is mostly sky, and the
             hours one is mostly stepper. --}}
        @if ($sleepCard)
            @if ($sleepCard['type'] === SleepCardType::Hours)
                <x-sleep-hours-card :card="$sleepCard" />
            @else
                <x-sleep-card :card="$sleepCard" />
            @endif
        @endif

        {{-- 3. Gratitude quest. The one quest that isn't work — nothing for a
             parent to approve, so the tickets land on hand-in. --}}
        <div
            wire:key="gratitude-quest"
            class="rounded-[20px] border p-4"
            style="background: var(--fq-wash-cleared); border-color: color-mix(in srgb, var(--fq-magenta) 40%, transparent)"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-magenta)">Gratitude Quest</p>
                <span class="inline-flex items-center gap-2 whitespace-nowrap">
                    <span
                        class="rounded-full border border-fq-ticket-line px-[10px] py-1 font-mono-fq text-[10px] text-fq-lime"
                        style="background: var(--fq-ticket-bg)"
                    >+{{ \App\Services\GratitudeService::TICKETS }} TICKETS</span>
                    <span class="font-mono-fq text-[10px] text-fq-text-4 uppercase">{{ $gratitudeToday ? 'Done today' : 'Not done today' }}</span>
                </span>
            </div>

            <h2 class="mt-[6px] font-baloo text-xl font-bold">Today you were grateful for&hellip;</h2>

            @if ($gratitudeToday)
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($gratitudeToday->items as $index => $item)
                        <div class="min-w-[150px] flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[11px]">
                            <span class="font-baloo text-[13px] font-extrabold" style="color: var(--fq-magenta)">{{ $index + 1 }}</span>
                            <span class="ml-2 text-sm text-fq-text-2">{{ $item }}</span>
                        </div>
                    @endforeach
                </div>

                <p class="mt-3 text-[13px] text-fq-text-5">
                    A new one opens up tomorrow. Everything you've written is kept in your
                    <a href="{{ route('kid.journal') }}" wire:navigate class="font-semibold underline" style="color: var(--fq-magenta)">Journal</a>.
                </p>
            @else
                @php $slotHints = ['1 · something', '2 · someone', '3 · anything']; @endphp

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach (range(0, \App\Services\GratitudeService::ITEMS - 1) as $index)
                        <input
                            type="text"
                            wire:model="gratitude.{{ $index }}"
                            wire:keydown.enter="logGratitude"
                            maxlength="{{ \App\Services\GratitudeService::MAX_LENGTH }}"
                            placeholder="{{ $slotHints[$index] ?? 'Something good…' }}"
                            aria-label="Grateful for, number {{ $index + 1 }}"
                            class="min-w-[150px] flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[11px] text-sm outline-none focus:border-fq-magenta"
                        >
                    @endforeach
                </div>

                <button
                    type="button"
                    wire:click="logGratitude"
                    wire:loading.attr="disabled"
                    wire:target="logGratitude"
                    class="mt-3 rounded-[14px] px-5 py-[11px] font-baloo text-[15px] font-bold transition hover:brightness-110 disabled:opacity-60"
                    style="background: var(--fq-magenta); color: var(--fq-ink)"
                >Hand it in</button>
            @endif

            @if ($gratitudeMessage)
                <p class="mt-3 text-[13px]" style="color: var(--fq-gold)">{{ $gratitudeMessage }}</p>
            @endif
        </div>

        {{-- 4. Bonus Wheel. It was a section on Home, and the kids kept coming
             here to look for it — which is the right instinct. The wheel lands
             on one of the side quests below and doubles or triples it, so the
             board it boosts is the page it belongs on. Home keeps a one-line
             pointer at it and nothing else.

             `id` so that pointer can land on it rather than at the top of a
             long page. --}}
        <div id="bonus-wheel" class="flex scroll-mt-4 flex-col gap-[13px]">
            <div class="flex items-baseline justify-between gap-[10px]">
                <h3 class="font-baloo text-[22px] font-extrabold">Bonus Wheel</h3>
                <span
                    class="font-mono-fq text-[10px] tracking-[0.14em] whitespace-nowrap uppercase"
                    style="color: {{ $spinRevealed ? 'var(--fq-lime)' : 'var(--fq-magenta)' }}"
                >{{ $spinRevealed ? '✓ Spun today' : 'One spin waiting' }}</span>
            </div>

            <div
                x-data
                x-init="$watch('$wire.spinning', (value) => { if (value) setTimeout(() => $wire.finishSpin(), 6100) })"
                class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] items-stretch gap-4"
            >
                <div class="flex flex-col items-center justify-center gap-[18px] rounded-[24px] border border-fq-line bg-fq-panel p-[22px] text-center">
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-magenta uppercase">One Spin Per Day</p>

                    <div class="relative h-[290px] w-[290px]">
                        <div
                            class="absolute top-0 left-1/2 z-[3] -translate-x-1/2 drop-shadow-[0_3px_6px_#000]"
                            style="width:0;height:0;border-left:12px solid transparent;border-right:12px solid transparent;border-top:20px solid var(--fq-gold)"
                        ></div>

                        {{-- Shadow lives on this static wrapper — box-shadow rotates along with
                             its element, so it has to stay off the div that spins. --}}
                        <div class="absolute inset-0 rounded-full" style="box-shadow:0 0 0 3px var(--fq-wheel-ring), var(--fq-shadow-wheel);">
                            <div
                                class="absolute inset-0 rounded-full"
                                style="
                                    background: var(--fq-wheel-rim);
                                    transform: rotate({{ $wheelDeg }}deg);
                                    transition: transform 6s cubic-bezier(.11,.85,.1,1);
                                "
                            >
                                {{-- Colorful cookie face — a wedge per chore, cycling through the
                                     app's accent palette, muted under a dark cookie-toned overlay so
                                     it still reads as chocolatey rather than a plain rainbow. --}}
                                @php
                                    $wheelPalette = ['var(--fq-lime)', 'var(--fq-cyan)', 'var(--fq-gold)', 'var(--fq-magenta)', 'var(--fq-coral)', 'var(--fq-violet)', 'var(--fq-sky)'];
                                    $wheelStops = $wheelChores->values()->map(
                                        fn ($wc, $i) => $wheelPalette[$i % count($wheelPalette)] . ' ' . ($i * $wheelSlice) . 'deg ' . (($i + 1) * $wheelSlice) . 'deg'
                                    )->implode(', ');
                                @endphp
                                <div class="absolute inset-[13px] rounded-full" style="background: conic-gradient(from 90deg, {{ $wheelStops }})"></div>
                                <div class="absolute inset-[13px] rounded-full" style="background: radial-gradient(circle at 38% 32%, rgba(40,25,12,.45), rgba(20,12,6,.72) 75%)"></div>

                                {{-- Segment dividers — one per chore boundary, radiating from the
                                     center plate out to the rim, so it reads as an actual wheel
                                     of distinct outcomes instead of plain decoration. --}}
                                @for ($i = 0; $i < $wheelChores->count(); $i++)
                                    @php $boundaryDeg = $i * $wheelSlice; @endphp
                                    <div
                                        class="absolute"
                                        style="
                                            left:145px; top:145px; width:2px; height:132px;
                                            background: color-mix(in srgb, var(--fq-wheel-label) 40%, transparent);
                                            transform-origin: top center;
                                            transform: translateX(-1px) rotate({{ $boundaryDeg - 90 }}deg);
                                        "
                                    ></div>
                                @endfor

                                {{-- Chore name per segment, running from the center plate out to
                                     the rim along its slice — reads outward, tilt-your-head style,
                                     which fits far more of each name than a horizontal label would.

                                     The name and nothing else. The points were tried here and
                                     taken back out: a slice is 98px of 9px type, so a number on
                                     the end is bought with the name's last few characters — and
                                     the payout is stated in full the moment the wheel stops,
                                     which is the only point at which one of these numbers is the
                                     one that matters. --}}
                                @foreach ($wheelChores as $i => $wheelChore)
                                    @php $midDeg = $i * $wheelSlice + $wheelSlice / 2; @endphp
                                    <div
                                        class="absolute overflow-hidden text-ellipsis whitespace-nowrap font-mono-fq font-semibold"
                                        style="
                                            left:145px; top:145px; margin-top:-6px; width:98px; height:12px;
                                            transform-origin: left center;
                                            transform: rotate({{ $midDeg }}deg) translateX(30px);
                                            font-size:9px; letter-spacing:.01em; color: var(--fq-wheel-label);
                                            text-shadow: 0 1px 1px rgba(0,0,0,.5);
                                        "
                                    >{{ $wheelChore->name }}</div>
                                @endforeach

                                {{-- Small decorative hub — just the wheel's center pin, not a
                                     click target (the real spin action is the button below). --}}
                                <div
                                    class="absolute top-1/2 left-1/2 z-[2] rounded-full"
                                    style="width:22px; height:22px; transform: translate(-50%, -50%); background: var(--fq-wheel-hub); border: 2px solid var(--fq-wheel-hub-line); box-shadow: inset 0 1px 3px rgba(0,0,0,.5)"
                                ></div>
                            </div>
                        </div>
                    </div>

                    @php
                        // The landed panel and the Active Boost card are the same
                        // result stated twice, so they're tinted from one place.
                        $boostIsBig = $boost && $boost->multiplier >= 3;
                        $boostTint = $boostIsBig
                            ? 'background: color-mix(in srgb, var(--fq-gold) 20%, transparent); border-color: color-mix(in srgb, var(--fq-gold) 55%, transparent)'
                            : 'background: color-mix(in srgb, var(--fq-magenta) 20%, transparent); border-color: color-mix(in srgb, var(--fq-magenta) 50%, transparent)';
                        $boostColor = $boostIsBig ? 'var(--fq-gold)' : 'var(--fq-magenta)';
                    @endphp

                    @if ($spinRevealed && $boost && ! $spinning)
                        <div
                            wire:key="landed-{{ $boost->id }}"
                            class="w-full max-w-[300px] rounded-[16px] border px-4 py-3 text-center"
                            style="animation: fq-pop .3s ease both; {{ $boostTint }}"
                        >
                            <p class="font-mono-fq text-[10px] tracking-[0.2em] uppercase" style="color: {{ $boostColor }}">
                                You landed on
                                {{-- Said on the result, not just on the button that
                                     bought it: the ticket is spent by now, and this
                                     is the only place the kid gets to see it work. --}}
                                @if ($boost->was_op)
                                    <span style="color: var(--fq-gold)">&middot; &#9889; OP</span>
                                @endif
                            </p>
                            <p class="mt-1 font-baloo text-lg font-extrabold">{{ $boost->chore->name }} &mdash; {{ $boost->multiplier }}x</p>
                            {{-- The multiplier stated as the number it actually pays.
                                 "3x" is arithmetic homework; the total is the thing
                                 worth getting off the sofa for. --}}
                            <p class="mt-1 font-mono-fq text-[11px] text-fq-text-3">
                                {{ number_format($boost->chore->points) }}
                                <span class="text-fq-text-5">&rarr;</span>
                                <span class="font-semibold" style="color: {{ $boostColor }}">{{ number_format($boost->chore->points * $boost->multiplier) }} PTS</span>
                            </p>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-4">
                    {{-- The spin lives here rather than under the wheel: on a phone the
                         wheel fills the screen, and the button a kid came for shouldn't
                         be the thing they have to scroll past it to find. --}}
                    <div class="flex flex-col gap-3 rounded-[24px] border border-fq-line bg-fq-panel p-5">
                        @if ($spinning)
                            <button type="button" disabled class="w-full cursor-default rounded-[18px] bg-fq-line-2 py-4 font-baloo text-[19px] font-extrabold text-fq-text-3">
                                Spinning&hellip;
                            </button>
                        @elseif ($spinRevealed)
                            <button type="button" disabled class="w-full cursor-default rounded-[18px] bg-fq-line-2 py-4 font-baloo text-[19px] font-extrabold text-fq-text-3">
                                Used today &mdash; back tomorrow
                            </button>
                        @else
                            {{-- A charged wheel wears the gold rather than the
                                 magenta: the button is the last thing a kid looks
                                 at before spending the charge, so it is where the
                                 charge has to be visible. --}}
                            <button
                                type="button"
                                wire:click="spin"
                                class="w-full rounded-[18px] py-4 font-baloo text-[19px] font-extrabold text-fq-bg transition hover:brightness-110"
                                style="background:{{ $wheelCharged ? 'var(--fq-gold)' : 'var(--fq-magenta)' }}; box-shadow: var(--fq-shadow-glow-lg) {{ $wheelCharged ? 'var(--fq-gold)' : 'var(--fq-magenta)' }}"
                            >{{ $wheelCharged ? '⚡ OP SPIN' : 'SPIN' }}</button>
                        @endif

                        <p class="text-[13px] text-fq-text-4">
                            @if ($spinRevealed)
                                One spin a day. Your boost is locked in below.
                            @elseif ($wheelCharged)
                                Charged! This spin can land 4x, and 3x is far more likely — plus a sweet treat when you finish it.
                            @else
                                Land on a chore, get 2x or 3x its points — plus a sweet treat when you finish it. Do it today.
                            @endif
                        </p>

                        {{-- The charge, in whichever of its three states applies:
                             already on the wheel, in the pocket, or for sale. Only
                             ever one of them, and never once the wheel has gone —
                             a charge bought after the spin would sit unseen until
                             tomorrow. --}}
                        @unless ($spinRevealed || $spinning)
                            @if ($wheelCharged)
                                <div
                                    class="flex items-center gap-2 rounded-[12px] border px-[14px] py-[10px] text-xs font-semibold"
                                    style="border-color: color-mix(in srgb, var(--fq-gold) 55%, transparent); background: color-mix(in srgb, var(--fq-gold) 16%, transparent); color: var(--fq-gold)"
                                >
                                    <span class="font-baloo text-sm">⚡</span>
                                    <span>Wheel charged &mdash; 4x is in play</span>
                                </div>
                            @elseif ($opSpin)
                                <div class="flex flex-col items-start gap-1">
                                    <x-perk-button :entry="$opSpin" />
                                    @if ($opSpin['blocked'])
                                        <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $opSpin['blocked'] }}</span>
                                    @endif
                                </div>
                            @elseif ($opSpinForSale)
                                @php $canAffordOp = $profile->bonus_tickets >= $opSpinForSale->cost; @endphp

                                <button
                                    type="button"
                                    wire:click="buyOpSpin"
                                    @disabled(! $canAffordOp)
                                    title="{{ $opSpinForSale->description }}"
                                    class="inline-flex h-[42px] items-center gap-2 self-start rounded-[12px] border px-[14px] text-xs font-semibold whitespace-nowrap transition hover:brightness-125 disabled:opacity-40"
                                    style="border-color: var(--fq-steel-edge); color: var(--fq-steel-text); background: var(--fq-steel-panel)"
                                >
                                    <span class="font-baloo text-sm">{{ $opSpinForSale->glyph }}</span>
                                    <span>Buy an {{ $opSpinForSale->name }}</span>
                                    <span class="font-mono-fq text-[10px]" style="color: {{ $canAffordOp ? 'var(--fq-lime)' : 'var(--fq-text-5)' }}">
                                        {{ $opSpinForSale->cost }}&#127903;
                                    </span>
                                </button>
                            @endif
                        @endunless

                        @if ($respin)
                            {{-- The charge is spent by the spin, not by the result,
                                 so a respin cannot hand it back — and the kid has
                                 no way of knowing that from a button that just says
                                 "respin". Asked once, and only on a spin the ticket
                                 actually paid for. --}}
                            @php $opAtRisk = $boost && $boost->was_op && ! $respin['blocked']; @endphp

                            <div class="flex flex-col items-start gap-1" x-data="{ asking: false }">
                                @if ($opAtRisk)
                                    <div x-show="! asking">
                                        <button
                                            type="button"
                                            x-on:click="asking = true"
                                            class="inline-flex h-[42px] items-center gap-2 rounded-[12px] border px-[14px] text-xs font-semibold whitespace-nowrap transition hover:brightness-125"
                                            style="border-color: var(--fq-steel-edge); color: var(--fq-steel-text); background: var(--fq-steel-panel)"
                                        >
                                            <span class="font-baloo text-sm">↻</span>
                                            <span>Use Wheel Respin</span>
                                            @if ($respin['count'] > 1)
                                                <span class="font-mono-fq text-[10px]">×{{ $respin['count'] }}</span>
                                            @endif
                                        </button>
                                    </div>

                                    <div x-show="asking" x-cloak class="flex w-full flex-col gap-[9px]">
                                        <p class="text-[13px] text-fq-notice-text">
                                            You spent an <strong style="color: var(--fq-gold)">⚡ OP charge</strong> on this spin.
                                            Respin and it's gone &mdash; the next one is an ordinary 2x or 3x.
                                        </p>

                                        <div class="flex gap-2">
                                            <button
                                                type="button"
                                                wire:click="usePerk('{{ $respin['effect']->value }}')"
                                                x-on:click="asking = false"
                                                class="flex-1 rounded-[14px] py-[11px] font-baloo text-[15px] font-extrabold transition hover:brightness-110"
                                                style="background: var(--fq-fill-gold-soft); color: var(--fq-ink)"
                                            >Respin anyway</button>

                                            <button
                                                type="button"
                                                x-on:click="asking = false"
                                                class="shrink-0 rounded-[14px] border bg-fq-sunk px-[16px] py-[11px] font-baloo text-[15px] font-extrabold text-fq-text-2-b transition hover:brightness-125"
                                                style="border-color: var(--fq-line-3)"
                                            >Keep my {{ $boost->multiplier }}x</button>
                                        </div>
                                    </div>
                                @else
                                    <x-perk-button :entry="$respin" />
                                @endif

                                @if ($respin['blocked'])
                                    <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $respin['blocked'] }}</span>
                                @endif
                            </div>
                        @endif

                        @if ($perkMessage)
                            <p class="text-[13px] text-fq-text-4">{{ $perkMessage }}</p>
                        @endif
                    </div>

                    <div class="flex flex-1 flex-col rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                        <h3 class="font-baloo text-lg font-bold">Active Boost</h3>
                        @if ($spinRevealed && $boost)
                            <div class="mt-3 flex items-center justify-between gap-3 rounded-[16px] border p-[14px]" style="{{ $boostTint }}">
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold">{{ $boost->chore->name }}</span>
                                    <span class="font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-4 uppercase">
                                        {{ number_format($boost->chore->points) }} &rarr; {{ number_format($boost->chore->points * $boost->multiplier) }} pts
                                    </span>
                                </span>
                                <span class="font-baloo text-[22px] font-extrabold whitespace-nowrap" style="color: {{ $boostColor }}">{{ $boost->multiplier }}x</span>
                            </div>

                            {{-- The claim, right here. The boosted chore is the one a
                                 kid came to the wheel for, and making them go and find its
                                 row again on the board below is a scroll for no reason. --}}
                            @if ($boostClaim && $boostClaim['claimable'])
                                <button
                                    type="button"
                                    wire:click="claimBoostedChore"
                                    class="mt-3 w-full rounded-[14px] py-[11px] text-sm font-semibold text-fq-bg transition hover:brightness-110"
                                    style="background: var(--fq-lime)"
                                >{{ $boostClaim['label'] }}</button>
                            @elseif ($boostClaim)
                                <button
                                    type="button"
                                    disabled
                                    class="mt-3 w-full cursor-default rounded-[14px] bg-fq-panel-alt py-[11px] text-sm font-semibold text-fq-text-4"
                                >{{ $boostClaim['label'] }}</button>
                            @endif

                            @if ($boostClaim && $boostClaim['note'])
                                <p class="mt-2 text-[13px] text-fq-text-5">{{ $boostClaim['note'] }}</p>
                            @endif

                            @if ($boostMessage)
                                <p class="mt-2 text-[13px] font-semibold text-fq-gold">{{ $boostMessage }}</p>
                            @endif
                        @else
                            <p class="mt-3 text-[13px] text-fq-text-5">No boost yet today.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- 5. Side quests — the price bands, the chips and the adding-up card.

             A six-year-old asks for "a $2 job" over and over, and the board
             used to have no answer: no ordering control at all, one typed
             search that the kid who needs it most cannot use, and payouts
             written in points. Two questions came out of that, and they need
             two different controls — "what can I do for about $2?" is a band,
             and "I need exactly $4" is the card at the bottom, because a band
             cannot answer it without the kid doing sums on top of sums. --}}
        @php
            // Always two decimal places. He is learning money, and "$2" on one
            // row beside "$2.50" on the next is noise. The rate is the
            // household's own, the same one the shell's points tile uses.
            $money = fn (int $points) => '$'.number_format($points / $pointsPerDollar, 2);
            $bandDim = 'color-mix(in srgb, var(--fq-lime) 70%, var(--fq-text-4))';
            $selectedFill = 'linear-gradient(180deg, #2a2405, var(--fq-sunk))';
        @endphp

        {{-- Full width, deliberately — no max-width, whatever the handoff's
             430px says.

             That 430px is the *phone*. What the handoff's desktop note
             actually rules out is a second column — "a two-column grid of
             these rows reads as a table" — and one full-width column honours
             that. Capping it was tried at 430px and at 640px and both were
             worse in the same way: the board became a narrow strip sitting
             under the full-width Quest Chest and Gratitude cards, and 430px
             also clipped the chip row mid-word, which the handoff says must
             never happen. Matching the page it lives on beats matching a
             number drawn for a phone. **Don't re-cap this.**

             The wrapper stays for the gap: 13px between blocks here, not the
             page's 16px. --}}
        <div class="flex flex-col gap-[13px]">
            <div class="flex items-baseline justify-between gap-[10px]">
                <h3 class="font-baloo text-[22px] font-extrabold">Side Quests</h3>
                <span class="font-mono-fq text-[10px] tracking-[0.14em] whitespace-nowrap uppercase" style="color: var(--fq-lime)">
                    {{ $boardCount }}
                </span>
            </div>

            {{-- Price bands. Four constants over chores.points, declared in
                 dollars and resolved against the household's rate. The top one is
                 open-ended and usually empty — that's deliberate. It's where an
                 occasional big one-time job lands, and an empty band that
                 sometimes fills is a promise; hiding it costs the eldest kid the
                 one place he goes looking. --}}
            <div class="flex flex-col gap-2">
                <span class="font-mono-fq text-[9.5px] tracking-[0.2em] text-fq-text-4 uppercase">How much do you want?</span>
                <div class="flex gap-[6px]">
                    @foreach ($bands as $entry)
                        @php
                            $priceBand = $entry['band'];
                            $on = $entry['selected'];
                        @endphp
                        {{-- Selected loses a pixel of padding top and bottom,
                             absorbing the extra border so the row doesn't shift
                             under a thumb that just tapped it. --}}
                        <button
                            type="button"
                            wire:click="pickBand({{ $priceBand->value }})"
                            class="flex min-w-0 flex-1 flex-col items-center gap-1 rounded-[16px] px-[3px] {{ $on ? 'border-2 pt-[10px] pb-2' : 'border pt-[11px] pb-[9px]' }}"
                            style="{{ $on
                                ? 'border-color: var(--fq-lime); background: '.$selectedFill
                                : 'border-color: var(--fq-line-2); background: var(--fq-sunk)' }}"
                        >
                            <span
                                class="font-baloo text-[19px] leading-none font-extrabold whitespace-nowrap"
                                style="color: {{ $on ? 'var(--fq-lime)' : 'var(--fq-text)' }}"
                            >{{ $priceBand->label() }}</span>
                            <span
                                class="font-mono-fq text-[8px] tracking-[0.06em] whitespace-nowrap uppercase"
                                style="color: {{ $on ? $bandDim : 'var(--fq-text-4)' }}"
                            >{{ $priceBand->sub() }}</span>
                            <span
                                class="font-mono-fq text-[9px] whitespace-nowrap"
                                style="color: {{ $on ? $bandDim : 'var(--fq-text-4)' }}"
                            >{{ $entry['count'] }} jobs</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Category chips, single-select with the same tap-to-clear rule as
                 the bands — the control is its own off switch, so there's no
                 separate "All" chip to explain.

                 The row scrolls rather than clipping: a chip cut mid-word with no
                 way to reach it reads as broken rather than as a bleed.

                 Drawn at a 38px minimum with 7px/13px/7px/10px of padding,
                 built at 44px with more room on every side. The handoff sets
                 the floor and then says outright that if the chip lands under
                 44px the padding is what goes up and nothing else shrinks — it
                 landed at 38, a thumb target under the line, and read as a
                 squat lozenge beside the 42px search box below it. The extra
                 costs a chip or so of visible row on a narrow phone, which the
                 row already scrolls for. --}}
            @if ($chips->isNotEmpty())
                <div
                    class="flex gap-[6px] overflow-x-auto overflow-y-hidden pb-[2px]"
                    style="scrollbar-width: none; -webkit-overflow-scrolling: touch"
                >
                    @foreach ($chips as $chip)
                        <button
                            type="button"
                            wire:key="chip-{{ $chip['id'] }}"
                            wire:click="pickCategory('{{ $chip['id'] }}')"
                            class="flex min-h-[44px] flex-none items-center gap-[8px] rounded-full border py-[11px] pr-[18px] pl-[15px] whitespace-nowrap"
                            style="{{ $chip['selected']
                                ? 'border-color: var(--fq-lime); background: '.$selectedFill
                                : 'border-color: var(--fq-line); background: var(--fq-panel)' }}"
                        >
                            <x-chore-icon
                                :icon="$chip['fa']"
                                class="text-[12px]"
                                style="color: {{ $chip['selected'] ? 'var(--fq-lime)' : 'var(--fq-text-4)' }}"
                            />
                            <span
                                class="text-[12px] {{ $chip['selected'] ? 'font-semibold' : '' }}"
                                style="color: {{ $chip['selected'] ? 'var(--fq-lime)' : 'var(--fq-text-3)' }}"
                            >{{ $chip['label'] }}</span>
                            @if ($chip['selected'])
                                <span class="font-baloo text-[13px] font-extrabold" style="color: {{ $bandDim }}">&times;</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- The typed search and the hide toggle, kept from the shipped board
                 and moved down here: the bands and chips are what a kid reaches
                 for, and these are the fine print above the list they narrow. --}}
            <div class="flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Find a chore"
                    class="min-w-[160px] flex-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-[10px] text-sm outline-none focus:border-fq-cyan"
                >
                @if (trim($search) !== '')
                    <button
                        type="button"
                        wire:click="clearSearch"
                        class="rounded-[14px] border border-fq-line-3 bg-fq-sunk px-3 py-[10px] text-xs text-fq-text-3"
                    >Clear</button>
                @endif

                {{-- Only offered when there's actually something to hide. An
                     always-on switch that does nothing on a clear board is one
                     more control to wonder about. --}}
                @if ($unavailableCount > 0)
                    <button
                        type="button"
                        wire:click="toggleUnavailable"
                        class="rounded-[14px] border px-3 py-[10px] text-xs whitespace-nowrap transition"
                        style="{{ $hideUnavailable
                            ? 'border-color: var(--fq-lime); color: var(--fq-lime); background: var(--fq-sunk)'
                            : 'border-color: var(--fq-line-3); color: var(--fq-text-3); background: var(--fq-sunk)' }}"
                    >
                        {{ $hideUnavailable
                            ? 'Show '.$unavailableCount.' more'
                            : "Hide {$unavailableCount} I can't do" }}
                    </button>
                @endif
            </div>

            {{-- The backstop for the seconds between polls. Polling narrows that
                 window but can't close it, so the tap still has to explain itself
                 rather than silently doing nothing. --}}
            @if ($boardMessage)
                <div
                    class="flex items-center gap-[10px] rounded-[16px] border px-[14px] py-3"
                    style="border-color: var(--fq-ticket-line); background: var(--fq-ticket-bg)"
                >
                    <span class="text-[15px] text-fq-lime">&#8635;</span>
                    <span class="flex-1 text-sm" style="color: var(--fq-notice-text)">{{ $boardMessage }}</span>
                    <button
                        type="button"
                        wire:click="refreshBoard"
                        class="rounded-[11px] border bg-fq-sunk px-[13px] py-[7px] text-xs font-semibold text-fq-lime transition hover:brightness-115"
                        style="border-color: var(--fq-ticket-line)"
                    >Refresh</button>
                </div>
            @endif

            {{-- The board list. One row per chore, and the row *is* the button —
                 refreshed when the kid comes back to the page, not on a timer. The
                 server scales to zero when idle, so a poll on a tablet left open
                 all afternoon would keep it awake and billing for nothing. This
                 fires one request at the only moment a stale board can actually
                 mislead someone: when they look at it. --}}
            <div
                x-data="{
                    last: 0,
                    refresh() {
                        if (document.visibilityState !== 'visible') return;

                        // Returning to a tab fires focus and visibilitychange
                        // together; one refresh covers both.
                        if (Date.now() - this.last < 2000) return;

                        this.last = Date.now();
                        $wire.$refresh();
                    },
                }"
                x-on:visibilitychange.window="refresh()"
                x-on:focus.window="refresh()"
                class="flex flex-col gap-2"
            >
                @foreach ($board as $entry)
                    @php
                        $chore = $entry['chore'];
                        $state = $entry['state'];
                        $takenBy = $entry['takenBy'];
                        $closesAt = $entry['closesAt'];
                        $boosted = $questBoosted === false && $boost && $boost->chore_id === $chore->id;
                        $payout = $chore->points * ($boosted ? $boost->multiplier : 1);
                        $boostColor = $boosted && $boost->multiplier >= 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)';
                        $dimmed = $takenBy || $state === 'expired';
                        // Cadence first, then only what a kid browses by. Built the
                        // same way the chips filter, off the flags resolved once in
                        // with(), so a row can't say "Muscle" under a chip that
                        // didn't show it. The category itself is not a tag — the
                        // face already says Kitchen, and repeating the chip you
                        // filtered by on every row it returned is noise.
                        $tags = [$chore->cadence->kidLabel()];
                        // Whatever a parent said, not only the hard ones. The
                        // Muscle *chip* collects Heavy alone, but a chore
                        // deliberately marked easy going is worth saying on the
                        // row — it is the answer to "is this a big one?", which
                        // is the question the effort control exists for.
                        if ($chore->effort) {
                            $tags[] = $chore->effort->kidLabel();
                        }
                        if ($entry['doneBefore']) {
                            $tags[] = 'Done before';
                        }
                        $status = match ($state) {
                            'pending' => 'Pending approval',
                            // "Back tomorrow" on a chore a sibling took reads as
                            // "you already did this" — the one wording that could
                            // send a kid off to redo it. Name them instead.
                            'done' => $takenBy
                                ? $takenBy->name.' got this one'
                                : match ($chore->cadence) {
                                    \App\Enums\ChoreCadence::Weekly => 'Back in 7 days',
                                    \App\Enums\ChoreCadence::Once => 'Gone for now',
                                    default => 'Back tomorrow',
                                },
                            // Not "Locked" and not "Back tomorrow" — the clock ran
                            // out and a parent has it now. Saying so is what makes
                            // the countdown mean anything next time.
                            'expired' => "Time's up",
                            default => null,
                        };
                        // The whole row claims, so the label the button used to
                        // carry lives in its tooltip and its accessible name — the
                        // one place it can say what a tap does without putting a
                        // second call to action on a 40px row.
                        $rowTitle = match (true) {
                            $state === 'ready' => 'Mark it done',
                            (bool) $takenBy => 'Taken by '.$takenBy->name,
                            $state === 'expired' => 'A parent took this one',
                            default => $status,
                        };
                    @endphp
                    <button
                        type="button"
                        wire:key="chore-{{ $chore->id }}"
                        title="{{ $chore->name }} &mdash; {{ $rowTitle }}"
                        @if ($state === 'ready')
                            wire:click="claimChore({{ $chore->id }})"
                        @else
                            disabled
                        @endif
                        class="flex items-center gap-[11px] rounded-[17px] px-[13px] py-[11px] text-left {{ $dimmed ? 'opacity-70' : '' }} {{ $chore->isOneTime() || $closesAt ? 'border-2' : 'border border-fq-line' }} {{ $state === 'ready' ? 'transition hover:brightness-115' : 'cursor-default' }}"
                        style="background: var(--fq-panel); {{ $state === 'pending' ? 'border-color: var(--fq-success-border)' : ($closesAt ? 'border-color: color-mix(in srgb, var(--fq-cyan) 55%, transparent)' : ($chore->isOneTime() ? 'border-color: color-mix(in srgb, var(--fq-gold) 55%, transparent); background: var(--fq-wash-gold)' : '')) }}"
                    >
                        {{-- The same face the chore wears everywhere else. A board
                             of fourteen identical text rows is unusable to a kid
                             who can't read them; a picture per row is the only
                             thing that makes it scannable. --}}
                        <span
                            class="grid h-10 w-10 flex-none place-items-center rounded-[12px] border"
                            style="border-color: var(--fq-line-2);
                                   background: var(--fq-sunk);
                                   color: {{ $dimmed ? 'var(--fq-text-5)' : 'var(--fq-text-3)' }}"
                        >
                            @if ($chore->icon)
                                <x-chore-icon :icon="$chore->icon" class="text-[18px]" />
                            @else
                                <span class="font-baloo text-[17px] font-extrabold">{{ mb_substr($chore->name, 0, 1) }}</span>
                            @endif
                        </span>

                        <div class="flex min-w-0 flex-1 flex-col gap-[2px]">
                            {{-- Flagged, not just sorted: a row sitting at the top
                                 of the list only reads as urgent if you can see why
                                 it's there. --}}
                            @if ($chore->isOneTime())
                                <span class="mb-[2px] inline-block self-start rounded-[8px] px-[8px] py-[2px] font-mono-fq text-[9px] tracking-[0.14em] uppercase" style="background: color-mix(in srgb, var(--fq-gold) 22%, transparent); color: var(--fq-gold)">
                                    &#9889; One-time
                                </span>
                            @endif
                            <span class="text-[14.5px] leading-[1.2] font-semibold {{ $dimmed ? 'line-through decoration-2' : '' }}">{{ $chore->name }}</span>
                            <span class="font-mono-fq text-[9px] tracking-[0.06em] text-fq-text-4 uppercase">
                                {{ implode(' · ', $tags) }}
                                @if ($boosted)
                                    · <span style="color: {{ $boostColor }}">{{ $boost->multiplier }}x wheel boost</span>
                                @endif
                            </span>
                            @if ($status)
                                <span
                                    class="font-mono-fq text-[9px] tracking-[0.06em] uppercase"
                                    style="color: {{ $state === 'expired' ? 'var(--fq-danger)' : ($state === 'pending' ? 'var(--fq-lime)' : 'var(--fq-gold)') }}"
                                >{{ $status }}</span>
                            @endif
                            @if ($closesAt)
                                {{-- The race, spelled out. It has to be read on the
                                     row, because the whole point is deciding to go
                                     and do it right now. --}}
                                <x-chore-countdown wire:key="closes-{{ $chore->id }}" :closes-at="$closesAt" class="mt-[3px] self-start" />
                            @endif
                        </div>

                        {{-- Money big, points small. He thinks in dollars; the
                             points are what the rest of the app is counted in, so
                             both have to be on the row. Gold means a bonus and
                             nothing else — the bands and chips use gold for
                             *selection*, so a wheel-boosted payout keeps its own
                             colour and no filter ever paints a row. --}}
                        <div class="flex flex-none flex-col items-end">
                            <span
                                class="font-baloo text-[19px] leading-none font-extrabold whitespace-nowrap"
                                style="color: {{ $takenBy ? 'var(--fq-text-5)' : ($boosted ? $boostColor : 'var(--fq-lime)') }}"
                            >{{ $money($payout) }}</span>
                            <span class="font-mono-fq text-[8.5px] text-fq-text-4">{{ $payout }} PTS</span>
                        </div>

                        {{-- What the tap does, said out loud.

                             The whole row is the button, which is the design —
                             but a row that claims a chore and shows no sign of
                             it is a trap, and the tooltip this replaces is
                             worth nothing to a six-year-old on a tablet. A tick
                             in a ring is the one affordance that needs no
                             reading: it is how every list of things to do says
                             "tick this off".

                             Not a nested <button> — that is invalid inside one
                             and would swallow the tap. The row stays the
                             control; this is its face. The sr-only text is what
                             a screen reader announces in place of it. --}}
                        {{-- Always rendered, in all three states, so the money
                             above it lines up down the whole board rather than
                             sliding as rows are claimed. --}}
                        <span
                            class="grid h-[34px] w-[34px] flex-none place-items-center rounded-full border-2 text-[13px]"
                            style="border-color: {{ $state === 'ready'
                                ? 'color-mix(in srgb, var(--fq-lime) 45%, transparent)'
                                : 'var(--fq-line-2)' }};
                                   color: {{ $state === 'ready' ? 'var(--fq-lime)' : 'var(--fq-text-5)' }}"
                        >
                            @if ($state === 'ready')
                                <i class="fa-solid fa-check" aria-hidden="true"></i>
                                <span class="sr-only">Mark it done</span>
                            @elseif ($state === 'pending')
                                {{-- Ticked, and waiting on a parent. Their own
                                     tap is the one thing on a dimmed row worth
                                     still showing. --}}
                                <i class="fa-solid fa-check" aria-hidden="true"></i>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>

            @if ($board->isEmpty())
                {{-- One panel, three headlines. Hiding everything leaves a blank
                     column that reads as a bug, so whichever control emptied the
                     board has to say so — and then point at the adder below, which
                     is the one thing on the page that can still answer. --}}
                <div class="flex flex-col items-center gap-[9px] rounded-[18px] border border-dashed p-[20px] px-4 text-center" style="border-color: var(--fq-line-2); background: var(--fq-panel)">
                    <span class="text-sm leading-[1.4]" style="color: var(--fq-text-2)">
                        @if (trim($search) !== '')
                            Nothing matches "{{ $search }}".
                        @elseif ($hideUnavailable)
                            Everything else is taken or closed for today.
                        @else
                            Nothing on the board matches that right now.
                        @endif
                    </span>
                    <span class="max-w-[280px] text-[13px] leading-[1.45] text-fq-text-4 text-pretty">
                        {{ $band !== null
                            ? 'Try a different amount, or add two smaller jobs together below.'
                            : 'Try another kind, or add two jobs together below.' }}
                    </span>
                </div>
            @endif

            {{-- 4b. The adding-up card.

                 "I need exactly $4." A band can't answer that — he'd be doing sums
                 on top of sums — so this does the arithmetic for him. It sits
                 *below* the list on purpose: it answers a question the list has
                 already failed to answer, so it reads as the thing you reach for
                 last. Above the board, a kid had to step over a calculator to get
                 to the jobs. --}}
            @if ($slotChores->count() === 2)
                @php
                    $target = $target;
                    $short = $target - $slotTotal;
                @endphp
                <div wire:key="quest-adder" class="overflow-hidden rounded-[22px] border" style="border-color: var(--fq-line-3); background: linear-gradient(160deg, #1d0b2f, var(--fq-panel))">
                    @unless ($adderOpen)
                        <button
                            type="button"
                            wire:click="toggleAdder"
                            class="flex w-full items-center gap-[11px] px-[15px] py-[14px] text-left"
                        >
                            <span class="grid h-9 w-9 flex-none place-items-center rounded-[11px] font-baloo text-[17px] font-extrabold" style="background: var(--fq-sunk); color: var(--fq-lime)">+</span>
                            <span class="min-w-0 flex-1 text-[13.5px] leading-[1.35]" style="color: var(--fq-text-2)">
                                Want an exact amount? <span style="color: var(--fq-magenta)">Add two jobs up &rarr;</span>
                            </span>
                        </button>
                    @else
                        <div class="flex flex-col gap-[13px] p-[15px]">
                            <div class="flex items-center justify-between gap-[10px]">
                                <span class="font-mono-fq text-[9.5px] tracking-[0.2em] text-fq-text-4 uppercase">I want to make</span>
                                <button
                                    type="button"
                                    wire:click="toggleAdder"
                                    class="min-h-[32px] flex-none rounded-[10px] border px-[11px] py-[6px] font-mono-fq text-[9.5px] tracking-[0.1em] text-fq-text-4 uppercase"
                                    style="border-color: var(--fq-line-2); background: var(--fq-sunk)"
                                >Hide</button>
                            </div>

                            {{-- One dollar per tap, clamped both ends. At the floor
                                 the minus greys *in place* rather than
                                 disappearing, so the control never reflows under
                                 his thumb mid-tap. --}}
                            <div class="flex items-stretch gap-[9px]">
                                @if ($target > $targetMin)
                                    <button
                                        type="button"
                                        wire:click="stepTarget(-1)"
                                        title="A dollar less"
                                        class="grid w-[60px] flex-none place-items-center rounded-[17px] border font-baloo text-[30px] leading-none font-extrabold"
                                        style="border-color: var(--fq-line-3); background: var(--fq-sunk); color: var(--fq-magenta)"
                                    >&minus;</button>
                                @else
                                    <span
                                        aria-hidden="true"
                                        class="grid w-[60px] flex-none place-items-center rounded-[17px] border font-baloo text-[30px] leading-none font-extrabold"
                                        style="border-color: #241539; background: #0c0716; color: var(--fq-line-2)"
                                    >&minus;</span>
                                @endif

                                <div
                                    class="flex min-w-0 flex-1 flex-col items-center justify-center gap-[1px] rounded-[17px] border-2 px-1 pt-[11px] pb-[9px]"
                                    style="border-color: var(--fq-lime); background: {{ $selectedFill }}"
                                >
                                    <span class="font-baloo text-[38px] leading-none font-extrabold" style="color: var(--fq-lime)">${{ (int) round($target / $pointsPerDollar) }}</span>
                                    <span class="font-mono-fq text-[8.5px] tracking-[0.14em] uppercase" style="color: {{ $bandDim }}">{{ $money($target) }}</span>
                                </div>

                                <button
                                    type="button"
                                    wire:click="stepTarget(1)"
                                    title="A dollar more"
                                    class="grid w-[60px] flex-none place-items-center rounded-[17px] border font-baloo text-[30px] leading-none font-extrabold"
                                    style="border-color: var(--fq-line-3); background: var(--fq-sunk); color: var(--fq-magenta)"
                                >+</button>
                            </div>

                            {{-- Two slots, never more and never fewer. Stepping
                                 skips whatever the other slot holds — two copies of
                                 one job is not a plan, it's a bug the kid has to
                                 work out for himself. --}}
                            <div class="flex flex-col gap-2">
                                @foreach ($slotChores as $slot => $slotChore)
                                    <div wire:key="slot-{{ $slot }}" class="flex items-center gap-2 rounded-[18px] border border-fq-line p-[9px]" style="background: var(--fq-panel)">
                                        <button
                                            type="button"
                                            wire:click="stepSlot({{ $slot }}, -1)"
                                            title="Something else"
                                            class="grid h-[52px] w-[38px] flex-none place-items-center rounded-[13px] border font-baloo text-[20px] font-extrabold"
                                            style="border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-magenta)"
                                        >&lsaquo;</button>

                                        <div class="flex min-w-0 flex-1 items-center gap-[10px]">
                                            {{-- Brighter than the board list: this
                                                 is the active thing on screen. --}}
                                            <span
                                                class="grid h-10 w-10 flex-none place-items-center rounded-[12px] border"
                                                style="border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-cyan)"
                                            >
                                                @if ($slotChore->icon)
                                                    <x-chore-icon :icon="$slotChore->icon" class="text-[18px]" />
                                                @else
                                                    <span class="font-baloo text-[17px] font-extrabold">{{ mb_substr($slotChore->name, 0, 1) }}</span>
                                                @endif
                                            </span>
                                            <div class="flex min-w-0 flex-1 flex-col gap-[1px]">
                                                <span class="truncate text-[13.5px] leading-[1.2] font-semibold">{{ $slotChore->name }}</span>
                                                <span class="font-baloo text-[17px] leading-[1.1] font-extrabold" style="color: var(--fq-lime)">{{ $money($slotChore->points) }}</span>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            wire:click="stepSlot({{ $slot }}, 1)"
                                            title="Something else"
                                            class="grid h-[52px] w-[38px] flex-none place-items-center rounded-[13px] border font-baloo text-[20px] font-extrabold"
                                            style="border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-magenta)"
                                        >&rsaquo;</button>
                                    </div>
                                @endforeach
                            </div>

                            @if ($slotNotice)
                                {{-- A slot that rearranges itself silently reads as
                                     a bug. Cooldowns are household-wide, so this is
                                     a sibling claiming a job mid-sum. --}}
                                <p class="font-mono-fq text-[9.5px] tracking-[0.06em] uppercase" style="color: var(--fq-gold)">{{ $slotNotice }}</p>
                            @endif

                            <div class="flex flex-col gap-[10px]">
                                <div class="flex items-end justify-between gap-[10px]">
                                    <div class="flex flex-col gap-[2px]">
                                        <span class="font-mono-fq text-[9px] tracking-[0.2em] text-fq-text-4 uppercase">Both together</span>
                                        <span class="font-baloo text-[34px] leading-none font-extrabold" style="color: var(--fq-lime)">{{ $money($slotTotal) }}</span>
                                    </div>
                                    <span class="flex-none font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-4 uppercase">Want {{ $money($target) }}</span>
                                </div>

                                <div class="h-[10px] overflow-hidden rounded-full" style="background: var(--fq-sunk)">
                                    <div
                                        class="h-full rounded-full"
                                        style="width: {{ min(100, (int) round($slotTotal / max(1, $target) * 100)) }}%; background: linear-gradient(90deg, var(--fq-magenta), var(--fq-lime))"
                                    ></div>
                                </div>

                                {{-- Three branches, not two. "Pick two for me"
                                     lands dead on target for nearly every amount
                                     the stepper can reach, so without the exact
                                     branch the normal success message would read
                                     "and $0.00 spare" — arithmetically true and
                                     meaningless to the kid it's written for. --}}
                                @if ($slotTotal === $target)
                                    <span class="text-[13.5px] leading-[1.4]" style="color: var(--fq-lime)">
                                        That's exactly <span class="font-bold">{{ $money($target) }}</span> &mdash; perfect.
                                    </span>
                                @elseif ($slotTotal > $target)
                                    <span class="text-[13.5px] leading-[1.4]" style="color: var(--fq-lime)">
                                        That's enough &mdash; and <span class="font-bold">{{ $money($slotTotal - $target) }}</span> spare.
                                    </span>
                                @else
                                    <span class="text-[13.5px] leading-[1.4]" style="color: var(--fq-text-3)">
                                        Still <span class="font-bold" style="color: var(--fq-coral)">{{ $money($short) }}</span> to go &mdash; try the arrows.
                                    </span>
                                @endif

                                <button
                                    type="button"
                                    wire:click="pickTwo"
                                    class="rounded-[14px] py-[13px] font-baloo text-[15px] font-extrabold transition hover:brightness-110"
                                    style="background: var(--fq-lime); color: var(--fq-bg)"
                                >Pick two for me</button>
                            </div>
                        </div>
                    @endunless
                </div>
            @endif
        </div>{{-- /Side Quests --}}

        {{-- 6. Bounty board — a window onto Trades & Jobs showing only what
             this kid could take right now. --}}
        <div wire:key="bounty-board" class="rounded-[20px] border border-fq-line bg-fq-panel p-[18px]">
            <div class="flex flex-wrap items-center justify-between gap-[10px]">
                <h3 class="font-baloo text-lg font-bold">Bounty Board</h3>

                <span class="inline-flex flex-wrap items-center gap-[10px] whitespace-nowrap">
                    @if ($bountiesWaiting > 0)
                        <span
                            class="rounded-full border px-[10px] py-1 font-mono-fq text-[10px] uppercase"
                            style="border-color: color-mix(in srgb, var(--fq-coral) 50%, transparent); background: var(--fq-line); color: var(--fq-coral)"
                        >
                            <span class="sm:hidden">{{ $bountiesWaiting }} waiting</span>
                            <span class="hidden sm:inline">{{ $bountiesWaiting }} waiting on you</span>
                        </span>
                    @endif

                    <a
                        href="{{ route('kid.trades') }}"
                        wire:navigate
                        class="font-mono-fq text-[10px] tracking-[0.12em] uppercase transition hover:text-fq-text"
                        style="color: var(--fq-magenta)"
                    >All Trades &amp; Jobs &rarr;</a>
                </span>
            </div>

            <p class="mt-1 text-sm text-fq-text-2">
                Jobs the others put up. Take one for extra points, or hire someone to do yours.
            </p>

            @if ($bountyMessage)
                <div
                    class="mt-3 rounded-[14px] border px-[14px] py-3 text-sm"
                    style="border-color: var(--fq-ticket-line); background: var(--fq-ticket-bg); color: var(--fq-notice-text)"
                >{{ $bountyMessage }}</div>
            @endif

            <div class="mt-[14px] flex flex-col gap-[10px]">
                @forelse ($bountyBoard as $job)
                    {{-- On an offered job the taker is the one paying, so the
                         button has to know whether they can afford it. On a
                         wanted one the poster's side was held at post. --}}
                    @php $shortfall = $job->kind->posterPays() ? 0 : $job->shortfallFor($profile); @endphp

                    <div wire:key="bounty-{{ $job->id }}" class="rounded-[16px] border border-fq-line bg-fq-sunk px-4 py-[14px]">
                        <div class="flex flex-wrap items-center gap-[9px]">
                            <span class="h-[10px] w-[10px] shrink-0 rounded-full" style="background: {{ $job->poster->color->cssVar() }}"></span>
                            <span class="font-baloo text-[16px] font-bold">{{ $job->poster->name }}</span>
                            <span class="rounded-full border border-fq-line-2 px-[10px] py-1 font-mono-fq text-[9.5px] tracking-[0.1em] whitespace-nowrap text-fq-text-4 uppercase">
                                {{ $job->kind->headline() }}
                            </span>
                            @if ($job->isTargeted())
                                {{-- Only ever rendered to the kid it is aimed
                                     at, so "just for you" is the whole point. --}}
                                <span class="rounded-full px-[10px] py-1 font-mono-fq text-[9.5px] tracking-[0.1em] whitespace-nowrap uppercase" style="background: var(--fq-tab-active); color: var(--fq-magenta)">
                                    Just for you
                                </span>
                            @endif
                            <span class="ml-auto font-mono-fq text-[10px] whitespace-nowrap text-fq-text-5">
                                {{ $job->expires_at->diffForHumans(['parts' => 1, 'syntax' => Carbon\Carbon::DIFF_ABSOLUTE]) }} left
                            </span>
                        </div>

                        <p class="mt-2 text-[15px] leading-[1.35]">{{ $job->description }}</p>

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <div class="rounded-[14px] border border-fq-line bg-fq-panel px-[14px] py-2">
                                <p class="font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-5 uppercase">
                                    {{ $job->kind->posterPays() ? 'You get' : 'You pay' }}
                                </p>
                                <p class="mt-[2px] font-baloo text-[20px] leading-none font-extrabold" style="color: {{ $job->reward_asset->cssVar() }}">
                                    {{ $job->rewardText() }}
                                </p>
                            </div>

                            <div class="ml-auto">
                                @if ($shortfall > 0)
                                    <button type="button" disabled class="cursor-default rounded-[13px] bg-fq-panel-alt px-5 py-[11px] text-[13.5px] font-bold text-fq-text-4">
                                        Need {{ $job->reward_asset->format($shortfall) }}
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="takeJob({{ $job->id }})"
                                        class="rounded-[13px] px-5 py-[11px] text-[13.5px] font-bold whitespace-nowrap text-fq-bg transition hover:brightness-110"
                                        style="background: var(--fq-lime)"
                                    >{{ $job->kind->takeLabel() }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="rounded-[16px] border border-dashed border-fq-line-4 p-5 text-center text-sm text-fq-text-4">
                        Nothing up for grabs. Post a job and see who takes it.
                    </p>
                @endforelse
            </div>
        </div>

        {{-- 7. Mystery chore. Always in this slot, live or found, so the pill
             on Today's Target always scrolls to the same place. --}}
        @if ($mysteryChore)
            <div
                id="mystery-card"
                wire:key="mystery-status"
                class="rounded-[20px] border p-[18px]"
                style="background: var(--fq-wash-violet); border-color: var(--fq-badge-line)"
            >
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-magenta)">Mystery Chore</p>
                    <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4 uppercase">
                        @if ($mysteryFinder && $mysteryFoundAt)
                            Found · {{ $mysteryFoundAt->copy()->setTimezone($household->timezone)->format('g:i A') }}
                        @elseif ($mysteryFinder)
                            Found
                        @else
                            Live · Unclaimed
                        @endif
                    </span>
                </div>

                @if ($mysteryFinder === null)
                    <h2 class="mt-2 font-baloo text-xl font-bold">One of today's chores is worth a bonus</h2>
                    <p class="mt-1 max-w-[420px] text-sm text-fq-text-2">
                        Nobody knows which one until a parent approves it. First to get it signed off
                        earns +{{ number_format(\App\Services\ChoreService::MYSTERY_BONUS_POINTS) }} pts.
                    </p>

                    @if ($mysteryHint)
                        <div class="mt-3 rounded-[14px] border px-4 py-3" style="border-color: var(--fq-badge-line); background: var(--fq-sunk)">
                            <p class="font-mono-fq text-[10px] tracking-[0.2em] uppercase" style="color: var(--fq-magenta)">Your Hint</p>
                            <p class="mt-1 text-sm text-fq-text-2">{{ $mysteryHint }}</p>
                        </div>
                    @elseif (isset($heldPerks['mystery_hint']))
                        <div class="mt-[14px] flex flex-col items-start gap-1">
                            <x-perk-button :entry="$heldPerks['mystery_hint']" />
                            @if ($heldPerks['mystery_hint']['blocked'])
                                <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $heldPerks['mystery_hint']['blocked'] }}</span>
                            @endif
                        </div>
                    @endif
                @else
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <div
                            class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[10px] font-baloo text-[15px] font-extrabold text-fq-bg sm:h-[34px] sm:w-[34px] sm:rounded-[11px]"
                            style="background: {{ $mysteryFinder->color->cssVar() }}"
                        >{{ mb_substr($mysteryFinder->name, 0, 1) }}</div>

                        <div class="min-w-[220px] flex-1">
                            {{-- Named for everyone once it's found: the secret
                                 is spent, and knowing which chore it was is half
                                 the fun of losing. Naming it also matters to the
                                 winner, who may have several claims in and no
                                 way to tell which one carried the bonus. --}}
                            <h2 class="font-baloo text-[17px] leading-[1.15] font-bold sm:text-xl">
                                {{ $mysteryFinder->id === $profile->id ? 'You' : $mysteryFinder->name }} found it — {{ $mysteryChore->name }}
                            </h2>
                            <p class="mt-[3px] text-[13px] text-fq-text-2 sm:text-sm">
                                {{ $mysteryFinder->id === $profile->id ? 'You' : 'They' }} banked a
                                +{{ number_format(\App\Services\ChoreService::MYSTERY_BONUS_POINTS) }} pt bonus.
                                A new one hides tomorrow.
                            </p>
                        </div>

                        <span class="font-baloo text-[17px] font-extrabold whitespace-nowrap sm:text-xl" style="color: var(--fq-magenta)">
                            +{{ number_format(\App\Services\ChoreService::MYSTERY_BONUS_POINTS) }}
                        </span>
                    </div>
                @endif
            </div>
        @endif

    </div>
</x-kid.shell>
