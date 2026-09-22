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
use App\Services\HouseholdClock;
use App\Services\KnackService;
use App\Services\MonsterService;
use App\Services\PerkInventoryService;
use App\Services\SleepService;
use App\Services\SpinService;
use App\Services\StreakService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The board: today's target and everything there is to do.
 *
 * It used to be the kid's whole day — the loot tray, the chests, the spin and
 * the boss all sat on it too, which made it a long page a kid had to already
 * know their way around. Those moved to Home, which is organised by *when*
 * rather than by what kind of thing something is. What's left here is the work:
 * the bonus wheel, the board, the bounty board and the mystery chore. The
 * gratitude quest went to Home too, as a row of "Your day".
 *
 * The main quest used to sit at the top of it, and is gone. It was a second
 * board on top of this one: a chest, a hand of three cards, and — while the kid
 * decided — up to five chores cut out of the list below, so the page that shows
 * a kid what there is to do was hiding some of it. What the quest paid for is
 * still paid, by the board itself. The Quest Charm is what survived it, and it
 * now lands here: five random rows, half again each, for this kid only.
 *
 * The wheel is the one that came back. It went to Home with the rest and the
 * kids kept opening this page looking for it, which was them being right: the
 * wheel lands on a chore and multiplies it, and every one of those rows is on
 * this page. Home keeps a one-line pointer at it.
 */
new class extends Component
{
    public Profile $profile;

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
     * Which chore has its "are you sure?" sheet open, or null.
     *
     * The whole row is the claim button, which reads to a kid as "tap this to
     * find out more" — and a tap that instead submitted the job for approval
     * cost them a chore they hadn't done. So the tap now opens the sheet, and
     * the sheet is *both* halves of the fix: it answers the question they were
     * actually asking (what does this pay, how often, is it a big one) and it
     * puts the claim behind a second, labelled press.
     *
     * One id rather than a per-row Alpine flag, deliberately. The board morphs
     * under Livewire as chores are claimed and filters change, and a client-side
     * open/closed map keyed by row is exactly the thing that survives a morph
     * pointing at the wrong chore.
     */
    public ?int $confirmingChoreId = null;

    /**
     * Why taking a job on the bounty board didn't work. Same reasoning as
     * boardMessage: a sibling can take the same job a second before you do, and
     * a button that silently does nothing explains none of it.
     */
    public ?string $bountyMessage = null;

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

    /** Tapping the live band clears it — the control is its own off switch. */
    public function pickBand(int $band): void
    {
        $this->band = $this->band === $band ? null : $band;
    }

    public function pickCategory(string $category): void
    {
        $this->category = $this->category === $category ? null : $category;
    }

    /**
     * The household's own exchange rate. The bands are declared in dollars and
     * resolved against this, so a household that rates a chore differently
     * still gets a "$2–5" button that means $2 to $5.
     */
    private function pointsPerDollar(): int
    {
        return max(1, (int) $this->profile->household->points_per_dollar);
    }

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

        // The board is memoised for the life of the request, and this button
        // exists for the kid who thinks a sibling has just taken something.
        // Handing them the answer this request already worked out would make
        // the one control built to defeat staleness the thing that caches it.
        app(ChoreService::class)->forgetBoards();
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

        // A pet with Night Owl saves the run right here — said in the same
        // breath, so Home need not say it again.
        $owl = app(KnackService::class)->nightOwl($this->profile, announced: true);

        $this->profile->refresh();

        $this->dispatch(
            'celebrate',
            // Finishing a picture is the bigger news and wins the headline; a
            // plain good night still says what it paid, because that is the
            // reward for pressing the button at all. A tapered-out household
            // pays nothing, and "+0 pts" would read as being shortchanged.
            message: ($owl ? "🐾 {$owl} saved your run! " : '').match (true) {
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
     * Answer last night's hours card: when they fell asleep, and when they
     * woke, as minutes since noon the evening before.
     *
     * Takes times rather than a band or a length, and the service works out
     * both from them — a kid who edits the wire can change what they claim to
     * have slept, which is between them and their conscience, but they can't
     * pick the payout directly.
     */
    public function answerSleepHours(int $asleep, int $awake): void
    {
        try {
            $result = app(SleepService::class)->recordHours($this->profile, $asleep, $awake);
        } catch (RuntimeException) {
            // Already answered, or switched off mid-visit. The card re-renders
            // showing what they said, which explains it better than a message.
            return;
        }

        $owl = app(KnackService::class)->nightOwl($this->profile, announced: true);

        $this->profile->refresh();

        // Hours enough in the wrong ones is the one answer that reads as a bug
        // unless the toast says why, and the card repeats it underneath.
        $said = SleepBand::say($result['minutes'])
            .($result['missedCoreHours'] ? ', but not across 12 to 6' : '');

        $this->dispatch(
            'celebrate',
            // Hearts rather than coins on the nights that didn't pay: a kid who
            // slept badly and said so has still done the thing this card is
            // for, and "+0 pts" would read as being shortchanged for it.
            message: ($owl ? "🐾 {$owl} saved your run! " : '').match (true) {
                $result['nightPoints'] > 0 => $result['band']->label().' — '.$said
                    .'! +'.number_format($result['nightPoints']).' pts',
                // A long sleep at the wrong end of the clock. Saying "a rough
                // one" to a kid who slept ten hours would just sound broken.
                $result['missedCoreHours'] => $said.'. Nothing lost — tonight is a new go.',
                default => $result['band']->response(),
            },
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

    /**
     * Every perk with a button on this page: the quest charm and the mystery
     * hint on the board, and the wheel respin beside the spin.
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
     * Buys one of this page's bonus items without leaving the page.
     *
     * Sold from here because here is where they are spent: a charm lands on
     * this board for the rest of today, and the window to charge a spin closes
     * the moment the wheel goes. A kid sent to the Bonus Shop first comes back
     * to a board they have left or a spin they have spent.
     *
     * The match is the allow-list as well as the wording — an effect this page
     * has no button for is a stale tab or a poke at the wire, and is ignored
     * rather than sold.
     */
    public function buyBonusItem(string $effect): void
    {
        $case = PerkEffect::tryFrom($effect);

        $suffix = $case === null ? null : match ($case) {
            PerkEffect::QuestCharm => 'cast it over the board!',
            PerkEffect::OpSpin => 'charge the wheel before you spin!',
            PerkEffect::WheelRespin => 'send the wheel round again!',
            PerkEffect::MysteryHint => 'read your clue!',
            default => null,
        };

        if ($suffix === null) {
            return;
        }

        $this->buyPerk($case, $suffix);
    }

    /**
     * One bonus item beside the thing it acts on: how many are held, whether
     * one can be spent right now, and what the next one costs.
     *
     * The price rides along whether or not they are holding any. A control
     * that only offers to sell when the pocket is empty is one a kid can never
     * stock up from, and one that hides the price once they own one takes the
     * answer away at the moment they are deciding whether to spend it.
     *
     * `$perks` is the household's enabled catalogue, keyed by effect, so four
     * of these cost one query between them rather than one each.
     *
     * @param  \Illuminate\Support\Collection<string, BonusPerk>  $perks
     * @return array{effect: PerkEffect, count: int, blocked: ?string,
     *               perk: ?BonusPerk, shortfall: int}
     */
    private function bonusItem(PerkEffect $effect, Collection $perks, PerkInventoryService $inventory): array
    {
        $count = $inventory->countOf($this->profile, $effect);
        $perk = $perks->get($effect->value);

        return [
            'effect' => $effect,
            'count' => $count,
            // Only asked when there is something to block: blockedReason() is
            // about spending one, and a kid holding none is not being refused.
            'blocked' => $count > 0 ? $inventory->blockedReason($this->profile, $effect) : null,
            'perk' => $perk,
            'shortfall' => $perk ? max(0, $perk->cost - (int) $this->profile->bonus_tickets) : 0,
        ];
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

    /**
     * The pet's Fetch: today's 2x boost rolled again, same chore. Offered by
     * the pet beside the wheel — see x-knack-offer and KnackService::fetch().
     */
    public function useFetch(): void
    {
        $multiplier = app(KnackService::class)->fetch($this->profile);

        if ($multiplier === null) {
            $this->perkMessage = 'Nothing to fetch right now.';

            return;
        }

        $this->dispatch(
            'celebrate',
            message: $multiplier > 2 ? "Fetched it — {$multiplier}x now!" : 'Fetched it… still 2x. Worth a try!',
            style: 'star',
            big: $multiplier > 2,
        );
    }

    /**
     * The pet's Sniffer: the mystery chore narrowed down to five, or three,
     * and every other card on the board marked. See KnackService::sniff().
     */
    public function useSniffer(): void
    {
        $maybe = app(KnackService::class)->sniff($this->profile);

        if ($maybe === null) {
            $this->perkMessage = 'Nothing to sniff out right now.';

            return;
        }

        $this->dispatch('celebrate', message: 'Sniffed it out — it\'s one of these '.count($maybe).'!', style: 'star');
    }

    /**
     * The pet's Paw Nudge: the boost moves one chore over on the wheel, and
     * the wheel turns the one click to show it. A grown pet goes the way the
     * kid picked; a young one picks for itself. See KnackService::nudge().
     */
    public function useNudge(?string $direction = null): void
    {
        $result = app(KnackService::class)->nudge($this->profile, $direction);

        if ($result === null) {
            $this->perkMessage = 'Nothing to nudge right now.';

            return;
        }

        $this->turnWheelToBoost();
        $this->dispatch('celebrate', message: 'Nudged — it\'s '.$result['chore']->name.' now!', style: 'star');
    }

    /**
     * The pet's Sure Paw: the boost goes on the chore the kid pointed at, and
     * the wheel turns to show it. A grown pet will take any chore on the
     * wheel; a young one offers three of its own choosing. See
     * KnackService::surePaw().
     */
    public function usePaw(int $choreId): void
    {
        $chore = app(KnackService::class)->surePaw($this->profile, $choreId);

        if ($chore === null) {
            $this->perkMessage = 'Nothing to point at right now.';

            return;
        }

        $this->turnWheelToBoost();
        $this->dispatch('celebrate', message: 'Boost on '.$chore->name.'!', style: 'star');
    }

    /** A young pet's nudge, put back where the wheel first landed. */
    public function putNudgeBack(): void
    {
        if (app(KnackService::class)->unnudge($this->profile)) {
            $this->turnWheelToBoost();
        }
    }

    /**
     * The pet's Second Look: today's spin cleared and the wheel back to the
     * top, the same as the respin perk leaves it, ready for another spin.
     */
    public function useSecondLook(): void
    {
        if (! app(KnackService::class)->secondLook($this->profile)) {
            $this->perkMessage = 'Nothing to look at again right now.';

            return;
        }

        $this->spinRevealed = false;
        $this->spinning = false;
        $this->wheelDeg = 0;

        $this->dispatch('celebrate', message: 'Second look — take another spin!', style: 'star');
    }

    /** The pet's Good Luck Charm, cast over the board. See KnackService::charm(). */
    public function useCharm(): void
    {
        $charmed = app(KnackService::class)->charm($this->profile);

        if ($charmed === null || $charmed->isEmpty()) {
            $this->perkMessage = 'Nothing left on the board to charm.';

            return;
        }

        $this->dispatch(
            'celebrate',
            message: $charmed->count() === 1 ? '1 chore just went charmed — find it!' : $charmed->count().' chores just went charmed — find them!',
            style: 'star',
        );
    }

    /**
     * Turns the wheel to wherever today's boost now is, the short way round,
     * so a nudge is one click of the wheel rather than another six turns.
     */
    private function turnWheelToBoost(): void
    {
        $spin = app(SpinService::class)->today($this->profile);
        $chores = $this->wheelChores();
        $index = $spin ? $chores->search(fn ($chore) => $chore->id === $spin->chore_id) : false;

        if ($index === false) {
            return;
        }

        $target = $this->restingDeg((int) $index, 360 / max(1, $chores->count()));

        while ($target < $this->wheelDeg - 180) {
            $target += 360;
        }

        while ($target > $this->wheelDeg + 180) {
            $target -= 360;
        }

        $this->wheelDeg = $target;
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

    /**
     * Puts the wheel's snapshot back in step with the database.
     *
     * `$spinRevealed` is set in mount() and never moved again on its own, and
     * today's spin can disappear out from under it: a parent resets the wheel
     * from the console, or the kid spends a Wheel Respin in another tab. The
     * page went on showing "Used today — back tomorrow" over a result that no
     * longer existed — and since the SPIN button is rendered in the other
     * branch, there was nothing left to tap to find out otherwise. Navigating
     * away and back fixed it, which is exactly the kind of "it's broken until
     * you reload" a kid will not work out on their own.
     *
     * Skipped mid-animation: the row is written before the wheel finishes
     * turning, so reading it there would jump the page to the result six
     * seconds early. `$spinning` is what the markup reads first anyway.
     *
     * A wheel parked on a result that has been cleared goes back to zero, the
     * same as the respin perk leaves it — the next spin should start from the
     * top rather than crawl on from wherever the last one stopped.
     */
    private function reconcileWheel(bool $spunToday): void
    {
        if ($this->spinning) {
            return;
        }

        if ($this->spinRevealed && ! $spunToday) {
            $this->wheelDeg = 0;
        }

        $this->spinRevealed = $spunToday;
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
     * Guarded because a household whose board is empty makes the spin throw.
     * Every entry point to the wheel goes through here.
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

    /**
     * What a tap on a board row now does: opens the sheet rather than claiming.
     *
     * Runs the full claimability check first, so a tap on a row a sibling took
     * a second ago gets the explanation it always got instead of a sheet for a
     * chore that can no longer be taken — choreIsClaimable() has already
     * written boardMessage by the time this returns false.
     */
    public function askChore(int $choreId): void
    {
        $this->boardMessage = null;
        $this->confirmingChoreId = null;

        if (! $this->choreIsClaimable($choreId)) {
            return;
        }

        $this->confirmingChoreId = $choreId;
    }

    public function cancelChore(): void
    {
        $this->confirmingChoreId = null;
    }

    /**
     * The confirm. Still the method the board's claim path has always gone
     * through, and still re-checks everything server-side — the sheet can sit
     * open for minutes, which is plenty of time for a sibling to take the job.
     */
    public function claimChore(int $choreId): void
    {
        $this->boardMessage = null;
        $this->confirmingChoreId = null;

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

        // stateFor() already accounts for the mystery chore's household-wide
        // (not per-kid) exclusivity, so no special-casing is needed here.
        if (! $chore->isAppropriateFor($this->profile)) {
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
        $monsters = app(MonsterService::class);
        $monster = $monsters->rotateWeakness($this->profile->household);

        return $monster ? $monsters->stateFor($monster) : null;
    }

    public function with(): array
    {
        $service = app(ChoreService::class);
        $spin = app(SpinService::class);
        $inventory = app(PerkInventoryService::class);

        // The household's live catalogue, keyed by effect: every bonus item on
        // this page reads its name, price and glyph from here, and a parent
        // switching one off is what takes its buy button away.
        $perks = BonusPerk::where('household_id', $this->profile->household_id)
            ->enabled()
            ->get()
            ->keyBy(fn (BonusPerk $perk) => $perk->effect->value);

        // A Livewire round trip doesn't pass back through the route middleware
        // that expires a lapsed streak, and this is the page a kid is most
        // likely to be sitting on when the household day rolls over.
        app(StreakService::class)->syncStreak($this->profile);

        $board = $service->boardFor($this->profile);

        // Hidden before the search rather than after, so the search's "2 / 5"
        // counter is measured against the board actually on screen.
        $isUnavailable = fn (array $entry) => in_array($entry['state'], self::UNAVAILABLE_STATES, true);
        $shown = $this->hideUnavailable ? $board->reject($isUnavailable) : $board;

        $boost = $spin->today($this->profile);

        // Before wheelChores(), which forces in whatever today's spin landed
        // on: with the spin gone there is nothing to force, and the wheel this
        // render draws is the one the next spin will actually turn.
        $this->reconcileWheel($boost !== null);

        $wheelChores = $this->wheelChores();

        $household = $this->profile->household;

        $mysteryChore = $service->mysteryChoreFor($household);

        // Whoever a parent has actually signed off, not whoever tapped first —
        // a pending claim used to name the chore here, which handed the answer
        // to anyone willing to submit the whole board.
        $mysteryToday = $service->mysteryTodayFor($household);
        $mysteryFinder = $mysteryToday?->foundBy;

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

        // The pet's knack, where this page has one to offer: Fetch beside the
        // wheel, Sniffer over the board — and a sniff's marks for the day.
        $knacks = app(KnackService::class);
        $knack = $knacks->stateFor($this->profile);

        return [
            'knack' => $knack,
            'fetchOffer' => $knack && ! $this->spinning && $this->spinRevealed && $knacks->fetchable($this->profile) !== null,
            'nudgeTargets' => $knack && ! $this->spinning && $this->spinRevealed ? $knacks->nudgeTargets($this->profile) : null,
            // Sure Paw: the chores it will put the boost on, the kid's pick.
            'pawTargets' => $knack && ! $this->spinning && $this->spinRevealed ? $knacks->pawTargets($this->profile) : null,
            // A young pet's nudge, to keep or put back.
            'nudged' => $knack && ! $this->spinning ? $knacks->nudgedToday($this->profile) : null,
            'secondLookOffer' => $knack && ! $this->spinning && $this->spinRevealed && $knacks->secondLookable($this->profile),
            // Lucky Tail waiting to charge the spin, and whether it charged this one.
            'luckyTail' => $knack && ! $this->spinRevealed && ! $this->spinning ? $knacks->luckyTailReady($this->profile) : null,
            'luckyTailBoost' => $boost !== null && $knacks->luckyTailOn($boost),
            'charmOffer' => $knack && $knacks->charmable($this->profile),
            'sniffOffer' => $knack && $knacks->sniffable($this->profile),
            'sniffMaybe' => $knacks->sniffedToday($this->profile),
            'boost' => $boost,
            'boostClaim' => $this->boostClaim($boost),
            'wheelChores' => $wheelChores,
            'wheelSlice' => 360 / max(1, $wheelChores->count()),
            // The three bonus items this page acts on, each the same control:
            // how many are held, a button to spend one, and the price of the
            // next. See bonusItem().
            'respinItem' => $this->bonusItem(PerkEffect::WheelRespin, $perks, $inventory),
            'opSpinItem' => $this->bonusItem(PerkEffect::OpSpin, $perks, $inventory),
            // Whether the charge is already on the wheel, which is the one
            // state where neither half of that control has anything to offer.
            'wheelCharged' => $spin->isCharged($this->profile),
            // What a charm pays, for the strip above the board to quote. A
            // constant rather than a sum: the bonus is a percentage of whatever
            // row it lands on, so there is no single number until it lands.
            'charmPercent' => ChoreService::CHARM_BONUS_PERCENT,
            'charmChores' => ChoreService::CHARM_CHORES,
            // How many rows are lit right now — the honest read of "did my
            // ticket do anything", counted off the board the kid can see rather
            // than off the charm rows, so a chore a sibling took since stops
            // being counted.
            'charmedCount' => $flagged->filter(fn (array $entry) => $entry['charmed'])->count(),
            // Filtered in PHP rather than re-queried — the board is already
            // loaded, and Chore::matches() is the in-memory twin of the
            // scope the parent admin searches with.
            'board' => $filtered,
            // Resolved off $flagged, not $filtered: a chip or a band changing
            // under an open sheet must not blank it, and a 'ready' chore is
            // never one hideUnavailable rejects. Re-checked for 'ready' every
            // render, so a sheet whose chore a sibling just took closes itself
            // rather than offering a button that can only fail.
            'confirming' => $this->confirmingChoreId === null ? null : $flagged->first(
                fn (array $entry) => $entry['chore']->id === $this->confirmingChoreId && $entry['state'] === 'ready'
            ),
            // "18 open" when nothing is filtered, "6 of 18" when something is.
            // The first is a board to browse; the second is a board with a
            // question asked of it, and the denominator is what says so.
            'boardCount' => $this->band !== null || $this->category !== null || trim($this->search) !== ''
                ? $filtered->count().' of '.$flagged->count()
                : $flagged->count().' open',
            'bands' => $bands,
            'chips' => $chips,
            'pointsPerDollar' => $rate,
            // Counted off the whole board, never off $shown — it's the number
            // the toggle offers to bring back, so it has to survive being on.
            'unavailableCount' => $board->filter($isUnavailable)->count(),
            'mysteryChore' => $mysteryChore,
            'mysteryFinder' => $mysteryFinder,
            // Stamped on the card so "found" reads as a moment in the day
            // rather than as a state the page has always been in.
            'mysteryFoundAt' => $mysteryToday?->found_at,
            'mysteryHint' => $service->mysteryHintFor($this->profile),
            // Null unless both the household and this kid have it switched on,
            // which is what keeps the card off every other kid's page.
            'sleepCard' => app(SleepService::class)->cardFor($this->profile),
            // The board's own two bonus items, same control as the wheel's.
            'charmItem' => $this->bonusItem(PerkEffect::QuestCharm, $perks, $inventory),
            'hintItem' => $this->bonusItem(PerkEffect::MysteryHint, $perks, $inventory),
            'household' => $household,
            // The boss card and the monster watching from behind the board. It
            // came back from Home: every hit on it is a chore off this board.
            // Status only, no replay, nothing marked seen — see <x-monster-mini>
            // for why the catch-up belongs to Household.
            'monsterState' => $this->monsterState(),
            // How much damage is still in the post, for the boss caption.
            'pendingCount' => ChoreCompletion::where('profile_id', $this->profile->id)
                ->where('status', CompletionStatus::Pending)
                ->count(),
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

    {{-- One column: what you owe today, then the board itself and the things
         hanging off it. --}}
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

        {{-- The boss fight, straight under the target: the monster is the reason
             clearing the board is worth anything, so a kid meets it on the way
             down to the chores. --}}
        @if ($monsterState)
            <div wire:key="family-boss">
                <x-monster-mini :state="$monsterState" :pending="$pendingCount" />
            </div>
        @endif

        @if ($perkMessage)
            <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">
                {{ $perkMessage }}
            </div>
        @endif

        {{-- The own-bed card, near the top because it asks about last night
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
                                {{-- Charged by the pet rather than a ticket. --}}
                                @if ($luckyTailBoost)
                                    <span class="text-fq-green" data-lucky-tail-spin>&middot; 🐾 Lucky Tail</span>
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

                    {{-- A 2x, and a pet with Fetch: it offers another go at the
                         boost, same chore. See KnackService::fetchable(). --}}
                    @if ($fetchOffer)
                        <x-knack-offer
                            wire:key="knack-fetch-{{ $boost->id }}"
                            :knack="App\Enums\PetKnack::Fetch"
                            :pet="$knack['pet']->name"
                            offer="can fetch you another go at that boost."
                            question="Send {{ $knack['pet']->name }} to fetch a better boost? Same chore — it might come back 3x!"
                            yes="Fetch!"
                            action="useFetch"
                            :left="$knack['left']"
                            :uses="$knack['uses']"
                            :act="[['sniff', 0.6], ['jump', 0.4], ['happy', 1.2]]"
                            class="max-w-[300px]"
                        />
                    @endif

                    {{-- Paw Nudge: the boost one chore over. A grown pet asks
                         which way; a young one goes whichever way it likes. --}}
                    @if ($nudgeTargets)
                        @php
                            $grownNudge = $knack['stage'] === App\Enums\PetStage::Adult;
                            $nudgeChoices = $grownNudge
                                ? array_values(array_filter([
                                    $nudgeTargets['left'] ? ['◀ '.$nudgeTargets['left']->name, 'left'] : null,
                                    $nudgeTargets['right'] ? [$nudgeTargets['right']->name.' ▶', 'right'] : null,
                                ]))
                                : null;
                        @endphp
                        <x-knack-offer
                            wire:key="knack-nudge-{{ $boost->id }}"
                            :knack="App\Enums\PetKnack::PawNudge"
                            :pet="$knack['pet']->name"
                            offer="can bat the wheel one chore over — same boost."
                            :question="$grownNudge ? 'Which way should '.$knack['pet']->name.' bat the wheel?' : 'Let '.$knack['pet']->name.' bat the wheel? It picks which way!'"
                            yes="Bat it!"
                            action="useNudge"
                            :choices="$nudgeChoices"
                            :left="$knack['left']"
                            :uses="$knack['uses']"
                            :act="[['crouch', 0.3], ['swipe', 0.7], ['happy', 1]]"
                            class="max-w-[300px]"
                        />
                    @endif

                    {{-- Sure Paw: the boost put where the kid points. A grown
                         pet takes any chore on the wheel; a young one offers
                         three it sniffed out. --}}
                    @if ($pawTargets && $pawTargets->isNotEmpty())
                        <x-knack-offer
                            wire:key="knack-paw-{{ $boost->id }}"
                            :knack="App\Enums\PetKnack::SurePaw"
                            :pet="$knack['pet']->name"
                            offer="can put the boost on the chore you pick — same boost."
                            :question="'Which chore should '.$knack['pet']->name.' put the boost on?'"
                            yes="Point at it!"
                            action="usePaw"
                            :choices="$pawTargets->map(fn ($chore) => [$chore->name, $chore->id])->all()"
                            :left="$knack['left']"
                            :uses="$knack['uses']"
                            :act="[['crouch', 0.3], ['swipe', 0.7], ['happy', 1]]"
                            class="max-w-[300px]"
                        />
                    @endif

                    {{-- A young pet's nudge, just done: keep it, or put it back. --}}
                    @if ($nudged && ! ($nudged['putBack'] ?? false) && $boost && $boost->chore_id === $nudged['to'] && $knack['stage'] !== App\Enums\PetStage::Adult && ($boostClaim['claimable'] ?? false))
                        @php $original = $wheelChores->firstWhere('id', $nudged['from']); @endphp
                        <div class="flex w-full max-w-[300px] flex-wrap items-center gap-[8px] rounded-[12px] border border-fq-green px-[12px] py-[8px] text-left text-[12.5px]" data-nudged>
                            <span class="min-w-[140px] flex-1"><i class="fa-solid fa-paw mr-[4px] text-fq-green"></i>{{ $knack['pet']->name }} batted it to <strong>{{ $boost->chore->name }}</strong>!</span>
                            @if ($original)
                                <button type="button" wire:click="putNudgeBack" class="rounded-[9px] border border-fq-line-3 px-[10px] py-[5px] font-mono-fq text-[9px] tracking-[0.08em] text-fq-text-3 uppercase">Put it back</button>
                            @endif
                        </div>
                    @endif

                    {{-- Second Look: another spin, chore and boost. --}}
                    @if ($secondLookOffer)
                        <x-knack-offer
                            wire:key="knack-second-look-{{ $boost->id }}"
                            :knack="App\Enums\PetKnack::SecondLook"
                            :pet="$knack['pet']->name"
                            offer="can spin the wheel again for you."
                            question="Let {{ $knack['pet']->name }} give the wheel a second spin? You'll land somewhere new."
                            yes="Spin again!"
                            action="useSecondLook"
                            :left="$knack['left']"
                            :uses="$knack['uses']"
                            :act="[['jump', 0.4], ['swipe', 0.6], ['happy', 1]]"
                            class="max-w-[300px]"
                        />
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
                        {{-- Lucky Tail, waiting to charge this spin by itself. --}}
                        @if ($luckyTail && ! $wheelCharged)
                            <div
                                class="flex items-center gap-2 rounded-[12px] border px-[14px] py-[10px] text-xs font-semibold text-fq-green"
                                style="border-color: color-mix(in srgb, var(--fq-green) 55%, transparent); background: color-mix(in srgb, var(--fq-green) 12%, transparent)"
                                data-lucky-tail-ready
                            >
                                <i class="fa-solid fa-paw"></i>
                                <span>{{ $knack['pet']->name }}'s Lucky Tail is charging this spin &mdash; {{ $luckyTail === App\Enums\PetStage::Adult ? '4x is in play' : 'a better shot at 3x' }}</span>
                            </div>
                        @endif

                        @unless ($spinRevealed || $spinning)
                            @if ($wheelCharged)
                                <div
                                    class="flex items-center gap-2 rounded-[12px] border px-[14px] py-[10px] text-xs font-semibold"
                                    style="border-color: color-mix(in srgb, var(--fq-gold) 55%, transparent); background: color-mix(in srgb, var(--fq-gold) 16%, transparent); color: var(--fq-gold)"
                                >
                                    <span class="font-baloo text-sm">⚡</span>
                                    <span>Wheel charged &mdash; 4x is in play</span>
                                </div>
                            @else
                                {{-- Held, for sale, or both — the same control the
                                     board's charm uses, and here for the same
                                     reason: the window to charge a spin closes the
                                     moment the wheel goes, so the price belongs
                                     beside the button, not a tab away. Gone once
                                     the wheel has gone, since a charge bought after
                                     the spin sits unseen until tomorrow. --}}
                                <x-perk-offer :entry="$opSpinItem" notch="var(--fq-panel)">
                                    4x in play, and 3x far more likely
                                </x-perk-offer>
                            @endif
                        @endunless

                        {{-- Offered once the wheel has gone even when they are
                             holding none: a result they want changed is the only
                             moment a respin means anything, and that is exactly
                             when being sent to the shop is most annoying. --}}
                        @if ($respinItem['count'] > 0 || $spinRevealed)
                            {{-- The charge is spent by the spin, not by the result,
                                 so a respin cannot hand it back — and the kid has
                                 no way of knowing that from a button that just says
                                 "respin". Asked once, and only on a spin the ticket
                                 actually paid for. --}}
                            @php
                                $opAtRisk = $respinItem['count'] > 0
                                    && $boost
                                    && $boost->was_op
                                    && ! $respinItem['blocked'];
                            @endphp

                            @if ($opAtRisk)
                                <div class="flex flex-col items-start gap-1" x-data="{ asking: false }">
                                    <div x-show="! asking">
                                        <button
                                            type="button"
                                            x-on:click="asking = true"
                                            class="inline-flex h-[42px] items-center gap-2 rounded-[12px] border px-[14px] text-xs font-semibold whitespace-nowrap transition hover:brightness-125"
                                            style="border-color: var(--fq-steel-edge); color: var(--fq-steel-text); background: var(--fq-steel-panel)"
                                        >
                                            <span class="font-baloo text-sm">↻</span>
                                            <span>Use Wheel Respin</span>
                                            @if ($respinItem['count'] > 1)
                                                <span class="font-mono-fq text-[10px]">×{{ $respinItem['count'] }}</span>
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
                                                wire:click="usePerk('{{ $respinItem['effect']->value }}')"
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

                                    @if ($respinItem['blocked'])
                                        <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $respinItem['blocked'] }}</span>
                                    @endif
                                </div>
                            @else
                                <x-perk-offer :entry="$respinItem" notch="var(--fq-panel)">
                                    A fresh chore and a fresh multiplier
                                </x-perk-offer>
                            @endif
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

            {{-- The charm, in whichever of its three states applies: rows
                 already lit, one in the pocket, or one for sale. The same
                 three-state control the wheel's OP charge uses beside the SPIN
                 button, and here for the same reason — this is the thing it
                 acts on, so this is where it is worth a ticket.

                 It sits above the bands rather than beside the board's title,
                 because a charm changes what every row below is worth: it is a
                 control over the list, like the bands and the chips, not a
                 badge on it. --}}
            {{-- Stacked, not wrapped: the stub is full-width, so the charmed
                 mark sits in its own row above it rather than trying to share
                 one. --}}
            <div class="flex flex-col items-stretch gap-2">
                @if ($charmedCount > 0)
                    <div
                        class="flex items-center gap-2 rounded-[12px] border px-[14px] py-[10px] text-xs font-semibold"
                        style="border-color: color-mix(in srgb, var(--fq-violet) 55%, transparent); background: color-mix(in srgb, var(--fq-violet) 16%, transparent); color: var(--fq-violet)"
                    >
                        <span class="font-baloo text-sm">&#10023;</span>
                        <span>
                            {{ $charmedCount }} {{ Str::plural('chore', $charmedCount) }} charmed &mdash;
                            +{{ $charmPercent }}% each, today only
                        </span>
                    </div>
                @endif

                {{-- Offered alongside the mark, not instead of it: a second
                     charm widens the spread, and a kid holding one after
                     casting one should be able to spend it. The price stays up
                     whether or not they are holding any — a control that only
                     sells to an empty pocket is one nobody can stock up from.
                     --}}
                <x-perk-offer :entry="$charmItem">
                    {{ $charmChores }} random chores, +{{ $charmPercent }}% each, today only
                </x-perk-offer>
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

            {{-- A pet with Sniffer and a mystery chore still out there: it offers
                 to narrow it down. See KnackService::sniffable(). --}}
            @if ($sniffOffer)
                <x-knack-offer
                    wire:key="knack-sniffer"
                    :knack="App\Enums\PetKnack::Sniffer"
                    :pet="$knack['pet']->name"
                    offer="can sniff out which chores might be the Mystery Chore."
                    question="Let {{ $knack['pet']->name }} sniff the board? It'll narrow the Mystery Chore down to {{ App\Services\KnackService::sniffKeeps($knack['stage']) }}."
                    yes="Sniff it out"
                    action="useSniffer"
                    :left="$knack['left']"
                    :uses="$knack['uses']"
                    :act="[['sniff', 1.4], ['sniff', 0.8], ['happy', 1]]"
                />
            @endif

            {{-- Good Luck Charm: a whole Quest Charm grown, one chore young. --}}
            @if ($charmOffer)
                <x-knack-offer
                    wire:key="knack-charm"
                    :knack="App\Enums\PetKnack::GoodLuckCharm"
                    :pet="$knack['pet']->name"
                    :offer="$knack['stage'] === App\Enums\PetStage::Adult ? 'can charm '.App\Services\ChoreService::CHARM_CHORES.' chores to pay half again today.' : 'can charm a chore to pay half again today.'"
                    question="Let {{ $knack['pet']->name }} cast its Good Luck Charm over your board?"
                    yes="Charm it!"
                    action="useCharm"
                    :left="$knack['left']"
                    :uses="$knack['uses']"
                    :act="[['sit', 0.8], ['jump', 0.4], ['happy', 1]]"
                />
            @endif

            {{-- After a sniff: which chores are still in the running, and a note
                 that the rest are paw-printed. Only for the kid who sniffed. --}}
            @if ($sniffMaybe)
                <p class="flex items-center gap-[8px] rounded-[12px] border border-fq-green px-[12px] py-[8px] text-[12.5px] text-fq-text-2" style="background: color-mix(in srgb, var(--fq-green) 8%, transparent)" data-sniffed="{{ count($sniffMaybe) }}">
                    <i class="fa-solid fa-paw text-fq-green"></i>
                    <span>{{ $knack['pet']->name ?? 'Your pet' }} sniffed the board — the Mystery Chore is one of the <strong class="text-fq-green">{{ count($sniffMaybe) }} marked "maybe!"</strong> Paw prints mean "not this one".</span>
                </p>
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
                        $helpWanted = $entry['helpWanted'];
                        $charmed = $entry['charmed'];
                        $boosted = $boost && $boost->chore_id === $chore->id;
                        // Read off the board rather than recomputed: the charm
                        // rides on top of the multiplier rather than inside it,
                        // exactly as ChoreService::claim() pays it, and this row
                        // has to quote the number that will actually land.
                        $charmBonus = $entry['charmBonus'];
                        $payout = $chore->points * ($boosted ? $boost->multiplier : 1) + $charmBonus;
                        $boostColor = $boosted && $boost->multiplier >= 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)';
                        $dimmed = $takenBy || $state === 'expired';
                        // After a sniff, every card is marked: a "maybe!" on the
                        // few still in the running, a paw print on everything
                        // else — including chores that could never have been
                        // it, so no card is left looking undecided.
                        $sniffMark = $sniffMaybe === null ? null : (in_array($chore->id, $sniffMaybe, true) ? 'maybe' : 'paw');
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
                            // Not "Mark it done" any more: the tap opens the
                            // sheet, and a row promising to submit the job on a
                            // single press is the thing being fixed. It still
                            // has to say that marking it done is what lies
                            // through there, or the tap looks like it does
                            // nothing worth making.
                            $state === 'ready' => 'See it and mark it done',
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
                            wire:click="askChore({{ $chore->id }})"
                        @else
                            disabled
                        @endif
                        @if ($sniffMark) data-sniff="{{ $sniffMark }}" @endif
                        class="relative flex items-center gap-[11px] rounded-[17px] px-[13px] py-[11px] text-left {{ $dimmed || $sniffMark === 'paw' ? 'opacity-70' : '' }} {{ $helpWanted || $chore->isOneTime() || $closesAt ? 'border-2' : 'border border-fq-line' }} {{ $state === 'ready' ? 'transition hover:brightness-115' : 'cursor-default' }}"
                        {{-- Their own pending claim outranks everything: it is
                             feedback on a tap they just made. Below that the
                             order matches the board's own sort — a job a parent
                             asked for, then a one-time chore, then a clock. --}}
                        style="background: var(--fq-panel); {{ $state === 'pending' ? 'border-color: var(--fq-success-border)' : ($helpWanted ? 'border-color: color-mix(in srgb, var(--fq-coral) 65%, transparent); background: var(--fq-wash-coral)' : ($closesAt ? 'border-color: color-mix(in srgb, var(--fq-cyan) 55%, transparent)' : ($chore->isOneTime() ? 'border-color: color-mix(in srgb, var(--fq-gold) 55%, transparent); background: var(--fq-wash-gold)' : ''))) }}"
                    >
                        {{-- The sniff's mark, stamped in a ripple down the board. --}}
                        @if ($sniffMark === 'maybe')
                            <span class="pointer-events-none absolute -top-[7px] right-[12px] z-[1] rounded-full border border-fq-green px-[7px] py-[1px] font-mono-fq text-[8.5px] tracking-[0.1em] text-fq-green uppercase" style="background: var(--fq-bg); box-shadow: 0 0 10px color-mix(in srgb, var(--fq-green) 60%, transparent); animation: fq-pop .3s ease both {{ $loop->index * 0.08 }}s">🐾 maybe!</span>
                        @elseif ($sniffMark === 'paw')
                            <span class="pointer-events-none absolute top-[3px] left-[40px] z-[1] -rotate-12 text-[17px] text-fq-text-3 opacity-80" style="animation: fq-pop .3s ease both {{ $loop->index * 0.08 }}s" title="Not the Mystery Chore"><i class="fa-solid fa-paw"></i></span>
                        @endif

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
                            {{-- Above the one-time flag, because it is the
                                 stronger claim on their attention and it is the
                                 reason the row is sitting up here.

                                 The ticket comes off the badge once the row is
                                 no longer claimable. Cooldowns are household
                                 wide, so a sibling taking it means the ticket is
                                 genuinely gone — leaving the number on a struck
                                 through row would be advertising a prize that
                                 isn't there. The ask itself still shows, since
                                 it was still made. --}}
                            @if ($helpWanted)
                                <span class="mb-[2px] inline-flex items-center gap-[5px] self-start rounded-[8px] px-[8px] py-[2px] font-mono-fq text-[9px] tracking-[0.14em] uppercase" style="background: color-mix(in srgb, var(--fq-coral) 22%, transparent); color: var(--fq-coral)">
                                    <i class="fa-solid fa-hand" aria-hidden="true"></i>
                                    Help wanted
                                    @if ($state === 'ready')
                                        · +{{ ChoreService::HELP_WANTED_TICKETS }} {{ Str::plural('ticket', ChoreService::HELP_WANTED_TICKETS) }}
                                    @endif
                                </span>
                            @endif
                            @if ($chore->isOneTime())
                                <span class="mb-[2px] inline-block self-start rounded-[8px] px-[8px] py-[2px] font-mono-fq text-[9px] tracking-[0.14em] uppercase" style="background: color-mix(in srgb, var(--fq-gold) 22%, transparent); color: var(--fq-gold)">
                                    &#9889; One-time
                                </span>
                            @endif
                            {{-- The charm mark. Below the other two because it
                                 is the only one that is about this kid rather
                                 than about the job: a flagged or one-time chore
                                 is urgent for everybody, and a charmed one is
                                 simply worth more to whoever paid the ticket.
                                 It stays on a row a sibling has taken — unlike
                                 the Help Wanted ticket, nothing about it was
                                 lost by losing the race, and the mark is how a
                                 kid finds out where their five landed. --}}
                            @if ($charmed)
                                <span class="mb-[2px] inline-block self-start rounded-[8px] px-[8px] py-[2px] font-mono-fq text-[9px] tracking-[0.14em] uppercase" style="background: color-mix(in srgb, var(--fq-violet) 24%, transparent); color: var(--fq-violet)">
                                    &#10023; Charmed · +{{ $charmPercent }}%
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
                                style="color: {{ $takenBy
                                    ? 'var(--fq-text-5)'
                                    : ($boosted ? $boostColor : ($charmed ? 'var(--fq-violet)' : 'var(--fq-lime)')) }}"
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
                                <span class="sr-only">See it and mark it done</span>
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

            {{-- The "are you sure?" sheet.

                 Kids kept tapping rows expecting to be told more about a chore
                 and submitting it for approval instead. So this is deliberately
                 information first and a question second: everything the row
                 could not fit, then the one press that actually claims. Reading
                 it costs nothing, which is what makes the curious tap safe
                 again.

                 Never the chore's `hint` — that is the Mystery Chore's clue and
                 the Bonus Shop sells it. Showing it here would give it away to
                 anyone who opened enough sheets. --}}
            @if ($confirming)
                @php
                    $askChore = $confirming['chore'];
                    $askBoosted = $boost && $boost->chore_id === $askChore->id;
                    $askCharmed = $confirming['charmed'];
                    $askCharmBonus = $confirming['charmBonus'];
                    $askPayout = $askChore->points * ($askBoosted ? $boost->multiplier : 1) + $askCharmBonus;
                    $askTags = [$askChore->cadence->kidLabel()];

                    if ($askChore->effort) {
                        $askTags[] = $askChore->effort->kidLabel();
                    }

                    if ($confirming['doneBefore']) {
                        $askTags[] = 'Done before';
                    }
                @endphp
                <div
                    x-data
                    x-on:keydown.escape.window="$wire.cancelChore()"
                    class="fixed inset-0 z-[60] flex items-end justify-center px-3 pb-3 sm:items-center sm:pb-0"
                >
                    {{-- The backdrop is its own element and its own button, so
                         a tap outside backs out. A sheet a six-year-old cannot
                         dismiss is a worse trap than the one it replaced. --}}
                    <button
                        type="button"
                        wire:click="cancelChore"
                        aria-label="Close"
                        class="absolute inset-0 cursor-default"
                        style="background: rgba(6, 3, 14, 0.74)"
                    ></button>

                    <div
                        class="relative w-full max-w-[420px] rounded-[22px] border p-[18px]"
                        style="animation: fq-pop .22s ease both;
                               background: var(--fq-panel);
                               border-color: var(--fq-line-2);
                               box-shadow: 0 26px 60px -20px #000"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="grid h-12 w-12 flex-none place-items-center rounded-[14px] border"
                                style="border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-3)"
                            >
                                @if ($askChore->icon)
                                    <x-chore-icon :icon="$askChore->icon" class="text-[22px]" />
                                @else
                                    <span class="font-baloo text-[20px] font-extrabold">{{ mb_substr($askChore->name, 0, 1) }}</span>
                                @endif
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="font-baloo text-[19px] leading-[1.15] font-extrabold">{{ $askChore->name }}</p>
                                <p class="mt-[2px] font-mono-fq text-[9px] tracking-[0.06em] text-fq-text-4 uppercase">
                                    {{ implode(' · ', $askTags) }}
                                </p>
                            </div>

                            {{-- The same money-big, points-small treatment as
                                 the row it came from, so the number a kid
                                 tapped on is recognisably the number here. --}}
                            <div class="flex flex-none flex-col items-end">
                                <span
                                    class="font-baloo text-[24px] leading-none font-extrabold whitespace-nowrap"
                                    style="color: {{ $askBoosted ? 'var(--fq-magenta)' : ($askCharmed ? 'var(--fq-violet)' : 'var(--fq-lime)') }}"
                                >{{ $money($askPayout) }}</span>
                                <span class="font-mono-fq text-[8.5px] text-fq-text-4">{{ $askPayout }} PTS</span>
                            </div>
                        </div>

                        @if ($askBoosted || $askCharmed || $confirming['helpWanted'] || $confirming['closesAt'])
                            <div class="mt-3 flex flex-wrap items-center gap-[6px]">
                                @if ($askCharmed)
                                    {{-- Says what the extra on the number above
                                         actually is. Without it the sheet
                                         quotes a payout that matches neither
                                         the chore's own points nor anything
                                         else in the app. --}}
                                    <span class="inline-block rounded-[8px] px-[8px] py-[3px] font-mono-fq text-[9px] tracking-[0.14em] uppercase" style="background: color-mix(in srgb, var(--fq-violet) 24%, transparent); color: var(--fq-violet)">
                                        &#10023; Charmed · +{{ number_format($askCharmBonus) }} pts
                                    </span>
                                @endif
                                @if ($confirming['helpWanted'])
                                    <span class="inline-flex items-center gap-[5px] rounded-[8px] px-[8px] py-[3px] font-mono-fq text-[9px] tracking-[0.14em] uppercase" style="background: color-mix(in srgb, var(--fq-coral) 22%, transparent); color: var(--fq-coral)">
                                        <i class="fa-solid fa-hand" aria-hidden="true"></i>
                                        Help wanted · +{{ ChoreService::HELP_WANTED_TICKETS }} {{ Str::plural('ticket', ChoreService::HELP_WANTED_TICKETS) }}
                                    </span>
                                @endif
                                @if ($askBoosted)
                                    <span class="inline-block rounded-[8px] px-[8px] py-[3px] font-mono-fq text-[9px] tracking-[0.14em] uppercase" style="background: color-mix(in srgb, var(--fq-magenta) 22%, transparent); color: var(--fq-magenta)">
                                        {{ $boost->multiplier }}x wheel boost
                                    </span>
                                @endif
                                @if ($confirming['closesAt'])
                                    <x-chore-countdown wire:key="ask-closes-{{ $askChore->id }}" :closes-at="$confirming['closesAt']" />
                                @endif
                            </div>
                        @endif

                        {{-- The question, and the only sentence on the card that
                             really matters: a claim is a statement about work
                             that already happened, and a parent is about to
                             check whether it did. --}}
                        <p class="mt-4 text-[15px] leading-[1.35] font-semibold">Have you finished this one?</p>
                        <p class="mt-[3px] text-[12.5px] leading-[1.35] text-fq-text-4">
                            Only say yes if the job is actually done &mdash; a parent has to check it before the points land.
                        </p>

                        <div class="mt-4 flex gap-2">
                            {{-- "Not yet" rather than "Cancel": backing out of a
                                 chore you have not done is a perfectly good
                                 answer, and it is the one most of these taps
                                 want. It is listed first for the same reason. --}}
                            <button
                                type="button"
                                wire:click="cancelChore"
                                class="flex-1 rounded-[14px] border py-[11px] text-[14px] font-semibold"
                                style="border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-3)"
                            >Not yet</button>
                            <button
                                type="button"
                                wire:click="claimChore({{ $askChore->id }})"
                                class="flex-1 rounded-[14px] border py-[11px] text-[14px] font-extrabold"
                                style="border-color: var(--fq-lime); background: var(--fq-fill-gold); color: var(--fq-ink)"
                            >Yes, it&rsquo;s done</button>
                        </div>
                    </div>
                </div>
            @endif

            @if ($board->isEmpty())
                {{-- One panel, three headlines. Hiding everything leaves a blank
                     column that reads as a bug, so whichever control emptied the
                     board has to say so, and say which control to loosen. --}}
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
                        {{ $band !== null ? 'Try a different amount.' : 'Try another kind.' }}
                    </span>
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
                    @else
                        {{-- The hint is the board's other bonus item, so it gets
                             the board's other control: held count, a button to
                             spend one, and what the next costs. Rendered only
                             while the mystery is unfound and unhinted — the two
                             branches above are the states where there is nothing
                             left to buy. --}}
                        <div class="mt-[14px]">
                            <x-perk-offer :entry="$hintItem">
                                A clue to which chore it is, yours alone
                            </x-perk-offer>
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
