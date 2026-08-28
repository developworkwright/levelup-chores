<?php

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\PerkEffect;
use App\Enums\ProfileRole;
use App\Enums\SleepOutcome;
use App\Enums\TicketKind;
use App\Models\ChoreCompletion;
use App\Models\Monster;
use App\Models\OwnedPerk;
use App\Models\Profile;
use App\Services\BadgeService;
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\LedgerService;
use App\Services\MonsterService;
use App\Services\PerkInventoryService;
use App\Services\SleepService;
use App\Services\SpinService;
use App\Services\StreakService;
use App\Services\TicketService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    /** @var array<int, string> */
    public array $newPins = [];

    /** @var array<int, string> */
    public array $pinMessages = [];

    /** @var array<int, string> */
    public array $questMessages = [];

    public ?string $mysteryMessage = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);

        // Seeded once, so the Arena controls open showing what is actually set
        // rather than blank fields that would read as "not configured".
        $household = $this->profile->household;

        $this->eveningWatchHour = $household->evening_watch_hour;
        $this->weeklyChoreTarget = (string) ($household->weekly_chore_target ?? '');
        $this->weeklyPrize = (string) ($household->weekly_prize ?? '');
        $this->weeklyPrizeNote = (string) ($household->weekly_prize_note ?? '');
    }

    private function ownedKid(int $profileId): ?Profile
    {
        return Profile::where('household_id', $this->profile->household_id)
            ->where('role', ProfileRole::Kid)
            ->find($profileId);
    }

    public function adjustPoints(int $profileId, int $delta): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid) {
            return;
        }

        $sign = $delta > 0 ? '+' : '';
        app(LedgerService::class)->record(
            $this->profile->household,
            $kid,
            LedgerKind::Adjustment,
            $delta,
            "Parent adjustment: {$sign}{$delta} for {$kid->name}",
        );

        if ($delta > 0) {
            $this->dispatch('celebrate', message: "{$kid->name} got {$sign}{$delta} points!", motion: 'burst', origin: 'tap');
        }

        app(BadgeService::class)->evaluate($kid);
    }

    public function recordCashIn(int $profileId): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid) {
            return;
        }

        $amount = 5 * $this->profile->household->points_per_dollar;
        app(LedgerService::class)->record(
            $this->profile->household,
            $kid,
            LedgerKind::CashIn,
            $amount,
            "{$kid->name} turned in \$5 cash",
        );

        $this->dispatch('celebrate', message: "{$kid->name} turned in \$5 cash!", motion: 'burst', origin: 'tap');

        app(BadgeService::class)->evaluate($kid);
    }

    public function recordPayout(int $profileId): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid || $kid->points <= 0) {
            return;
        }

        $dollars = number_format($kid->points / $this->profile->household->points_per_dollar, 2);
        app(LedgerService::class)->record(
            $this->profile->household,
            $kid,
            LedgerKind::CashOut,
            -$kid->points,
            "Paid out \${$dollars} to {$kid->name}",
        );
    }

    public function adjustTickets(int $profileId, int $delta): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid) {
            return;
        }

        $sign = $delta > 0 ? '+' : '';
        app(TicketService::class)->record(
            $kid,
            TicketKind::Adjustment,
            $delta,
            "Parent adjustment: {$sign}{$delta} tickets",
        );
    }

    public function resetSpin(int $profileId): void
    {
        $kid = $this->ownedKid($profileId);

        if ($kid) {
            app(SpinService::class)->clearToday($kid);
        }
    }

    /**
     * The Arena's household settings.
     *
     * Seeded in mount() from the household so the controls open showing what
     * is actually set, and written back together by saveArenaSettings().
     */
    public ?int $eveningWatchHour = null;

    public string $weeklyChoreTarget = '';

    public string $weeklyPrize = '';

    public string $weeklyPrizeNote = '';

    public ?string $arenaMessage = null;

    /**
     * Writes all four at once, from an explicit button.
     *
     * Deliberately not save-on-blur, which is what this was first: four fields
     * that each wrote themselves the moment focus left them, with nothing on
     * screen to say anything had happened. A parent filling in a prize and a
     * rule tabs between them and has no way to tell the difference between
     * "saved" and "silently didn't" — so there is a button, and it says so.
     */
    public function saveArenaSettings(): void
    {
        // Bounded to the evening. A watch hour before the afternoon would have
        // the Arena calling a quest at risk over breakfast, which is the sort
        // of nagging this page's whole copy is written to avoid.
        $hour = max(15, min(23, (int) $this->eveningWatchHour));

        $digits = preg_replace('/\D/', '', $this->weeklyChoreTarget);
        $target = $digits === '' ? null : max(1, min(65535, (int) $digits));

        $prize = trim($this->weeklyPrize);
        $note = trim($this->weeklyPrizeNote);

        $this->profile->household->update([
            'evening_watch_hour' => $hour,
            'weekly_chore_target' => $target,
            // Empty clears rather than storing '', so the Arena's "is there a
            // prize" check stays a plain null test.
            'weekly_prize' => $prize === '' ? null : $prize,
            'weekly_prize_note' => $note === '' ? null : $note,
        ]);

        // Echoed back from what was actually written, so a value that got
        // clamped or stripped shows the parent the number they really have.
        $this->eveningWatchHour = $hour;
        $this->weeklyChoreTarget = (string) ($target ?? '');

        $this->arenaMessage = $target
            ? 'Saved.'
            : 'Saved — with no weekly target, the house bar stays off.';
    }

    /** The household switch. Off, every per-kid switch is moot. */
    public function toggleHouseholdSleepCard(): void
    {
        $household = $this->profile->household;
        $household->update(['sleep_card_enabled' => ! $household->sleep_card_enabled]);
    }

    /**
     * Taper what a constellation pays. Nothing already earned moves — see
     * SleepService::setConstellationPoints().
     */
    public function adjustConstellationPayout(int $delta): void
    {
        $sleep = app(SleepService::class);
        $household = $this->profile->household;

        $sleep->setConstellationPoints(
            $household,
            $sleep->constellationPoints($household) + $delta,
        );
    }

    /**
     * Taper what one kind of answer pays. Same reasoning as the constellation,
     * one dial per outcome.
     */
    public function adjustNightPayout(string $outcome, int $delta): void
    {
        $answer = SleepOutcome::tryFrom($outcome);

        if (! $answer) {
            return;
        }

        $sleep = app(SleepService::class);
        $household = $this->profile->household;

        $sleep->setPointsFor($household, $answer, $sleep->pointsFor($household, $answer) + $delta);
    }

    /**
     * Switch the own-bed card on or off for one kid.
     *
     * Per kid rather than by age: a parent knows who is working on this, and a
     * birthday is a poor proxy either way round.
     */
    public function toggleSleepCard(int $profileId): void
    {
        $kid = $this->ownedKid($profileId);

        if ($kid) {
            $kid->update(['sleep_card_enabled' => ! $kid->sleep_card_enabled]);
        }
    }

    /**
     * Nudge a kid's own-bed numbers. The card is answered by a small child and
     * sometimes the answer is wrong — but the paid marks never move down with
     * it, so correcting a number can't re-pay a constellation.
     */
    public function adjustSleep(int $profileId, int $nights = 0, int $run = 0): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid) {
            return;
        }

        app(SleepService::class)->adjust(
            $kid,
            nights: $nights !== 0 ? max(0, $kid->sleep_nights + $nights) : null,
            run: $run !== 0 ? max(0, $kid->sleep_run + $run) : null,
        );
    }

    public function rerollMystery(): void
    {
        $chore = app(ChoreService::class)->rerollMysteryChore($this->profile->household);

        $this->mysteryMessage = $chore
            ? "Swapped to \"{$chore->name}\"."
            : 'Nothing to swap to — someone has already found it, or there is no other eligible chore.';
    }

    /**
     * Same logic the kid's Quest Reroll perk uses, minus the ticket charge —
     * so a parent can veto a chore without it costing anyone anything.
     */
    public function rerollQuest(int $profileId): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid) {
            return;
        }

        $quest = app(ChoreService::class)->rerollQuest($kid);

        // Never names a chore any more: a reroll deals a fresh hand, and the
        // row's chore_id is a placeholder until the kid takes a card. Quoting
        // it would tell a parent their kid's quest is something nobody has
        // chosen — and something they may well not choose.
        $this->questMessages[$profileId] = $quest
            ? 'Dealt a new hand — '.count($quest->offeredChoreIds()).' fresh cards to choose from.'
            : 'Nothing to swap — the quest is already cleared, or there is no other eligible chore.';
    }

    /**
     * Hands a Quest Charm over for nothing, the way rerollQuest() hands over a
     * reroll — same perk the Bonus Shop sells, minus the tickets.
     *
     * Gives rather than casts. A charm only goes on a chest that is still shut,
     * so a parent tapping this in the afternoon would be spending the kid's one
     * gamble for them, on a hand the kid has already read. In the pocket it
     * keeps until they decide a morning is worth it.
     */
    public function giveQuestCharm(int $profileId): void
    {
        $kid = $this->ownedKid($profileId);

        if (! $kid) {
            return;
        }

        $perks = app(PerkInventoryService::class);
        $perks->grant($kid, PerkEffect::QuestCharm, OwnedPerk::SOURCE_GIFT);

        $held = $perks->countOf($kid, PerkEffect::QuestCharm);
        $charms = $held.' '.Str::plural('charm', $held);

        // Says which of the two things just happened, because they feel very
        // different to a parent: a charm handed over at breakfast is for today,
        // and one handed over after the chest is open is a present for a
        // morning that hasn't come yet.
        $this->questMessages[$profileId] = $perks->blockedReason($kid, PerkEffect::QuestCharm) === null
            ? "Quest Charm handed over — {$kid->name} is holding {$charms}, and today's chest can still take one."
            : "Quest Charm handed over — {$kid->name} is holding {$charms}. Today's chest can't take one any more, so it keeps for a future quest.";
    }

    public function changePin(int $profileId): void
    {
        $kid = $this->ownedKid($profileId) ?? ($profileId === $this->profile->id ? $this->profile : null);
        $pin = $this->newPins[$profileId] ?? '';

        if (! $kid || ! preg_match('/^\d{4}$/', $pin)) {
            $this->pinMessages[$profileId] = 'PIN must be exactly 4 digits.';

            return;
        }

        $kid->setPin($pin);
        $kid->resetPinAttempts();
        $kid->save();

        $this->newPins[$profileId] = '';
        $this->pinMessages[$profileId] = 'PIN updated.';
    }

    /**
     * What's assigned today, and how far the kid has gotten on it — lets a
     * parent see today's main quest for each kid without waiting for an
     * approval request to show up.
     *
     * Opening the chest and clearing the quest are two different moments:
     * `revealed_at` is the kid tapping the chest, `completed_at` is them
     * marking the chore done. Reading only the second one meant the chest read
     * as unopened right up until the quest was claimed — and a sent-back quest,
     * which clears `completed_at` on purpose, fell all the way back to
     * "not opened" as well.
     */
    private function questSummaryFor(Profile $kid): array
    {
        $chores = app(ChoreService::class);
        $quest = $chores->questFor($kid);

        // Before the pick there is no quest to name — `chore_id` holds a
        // placeholder card, and printing it under "Today's Quest" told a
        // parent their kid had been given a chore nobody has chosen. The hand
        // goes out instead, which is the honest answer to what is happening
        // and the thing a parent needs in order to judge whether to re-deal.
        // `completed_at` is checked alongside the pick, not folded into it: a
        // quest can be cleared without one — claimQuest() doesn't require a
        // card to have been taken — and reporting a finished quest as "choosing
        // a card" would hide work that is sitting waiting for approval.
        if (! $quest->isPicked() && $quest->completed_at === null) {
            return [
                'chore' => null,
                'hand' => $chores->offeredChoresFor($kid),
                'status' => $quest->dealt_at !== null ? 'choosing' : 'not_started',
                'canReroll' => true,
                'charmed' => $quest->isCharmed(),
                'charmsHeld' => app(PerkInventoryService::class)->countOf($kid, PerkEffect::QuestCharm),
            ];
        }

        // Scoped to today's household day: the same chore may well have been
        // done last week, and that attempt says nothing about today's quest.
        // Clocked off the parent's own household rather than the kid's, which
        // is the same household and one relation load per card cheaper.
        $clock = HouseholdClock::for($this->profile->household);

        $completion = ChoreCompletion::where('profile_id', $kid->id)
            ->where('chore_id', $quest->chore_id)
            ->where('submitted_at', '>=', $clock->startOf($clock->today()))
            ->latest('submitted_at')
            ->first();

        $status = match (true) {
            $completion !== null => $completion->status->value,
            $quest->completed_at !== null => 'pending',
            $quest->revealed_at !== null => 'opened',
            default => 'not_started',
        };

        return [
            'chore' => $quest->chore,
            'hand' => collect(),
            'status' => $status,
            // Mirrors what rerollQuest() will actually do, so the button isn't
            // dead on a quest that is still swappable — opened and sent-back
            // quests both still are.
            'canReroll' => $quest->completed_at === null,
            'charmed' => $quest->isCharmed(),
            'charmsHeld' => app(PerkInventoryService::class)->countOf($kid, PerkEffect::QuestCharm),
        ];
    }

    public function with(): array
    {
        $kids = Profile::where('household_id', $this->profile->household_id)
            ->where('role', ProfileRole::Kid)
            ->orderByDesc('age')
            ->get();

        $chores = app(ChoreService::class);

        // `profiles.streak` is a cache, and the SyncStreak middleware only
        // expires it for the kid who is signed in — so a run that died
        // overnight kept reading as live here until that kid next opened the
        // app, and the parent was looking at a different number from the one on
        // their kid's own header. O(1) per kid, which is what makes it fine on
        // a page that lists the household.
        $kids->each(fn (Profile $kid) => app(StreakService::class)->syncStreak($kid));
        $household = $this->profile->household;
        $mysteryChore = $chores->mysteryChoreFor($household);
        $mysteryClaimant = $mysteryChore ? $chores->claimantFor($mysteryChore) : null;

        return [
            'kids' => $kids,
            'questSummaries' => $kids->mapWithKeys(fn (Profile $kid) => [$kid->id => $this->questSummaryFor($kid)]),
            'spins' => $kids->mapWithKeys(function (Profile $kid) {
                $spin = app(SpinService::class)->today($kid);

                return [$kid->id => $spin?->loadMissing('chore')];
            }),
            // Read-only on purpose, unlike the wheel beside it. The wheel can be
            // reset because a spin only picks a chore to be worth more; a chest
            // has already paid tickets, points or a perk out, so "open it again"
            // would mean paying twice.
            'chests' => $kids->mapWithKeys(function (Profile $kid) {
                $chest = app(ChestService::class)->openedToday($kid);

                return [$kid->id => $chest ? [
                    'prize' => app(ChestService::class)->describe($chest),
                    'openedAt' => $chest->created_at,
                    // Whether it rolled on the boosted table — the honest answer
                    // to "why did they get a perk and mine got 50 points".
                    'questWasDone' => $chest->quest_was_done,
                ] : null];
            }),
            'mysteryChore' => $mysteryChore,
            // Who won it, settled by an approval.
            'mysteryFinder' => $chores->mysteryFinderFor($household),
            // Who's waiting on you for it — and only that. claimantFor() also
            // returns an *approved* claim inside the cooldown, so reading it
            // as "needs approval" tells you to go and sign off work you already
            // signed off. A chore approved without winning (anything decided
            // before the bonus moved to approval) has to read as its own state.
            'mysteryAwaiting' => $mysteryClaimant?->status === CompletionStatus::Pending
                ? $mysteryClaimant->profile
                : null,
            'mysteryClaimant' => $mysteryClaimant,
            'household' => $household,
            // A glance only — naming the reward, pricing it and setting health
            // all live on the Monster Deck, which is where the dollars-per-point
            // readout can sit beside them.
            'arenaState' => ($monster = app(MonsterService::class)->current($household))
                ? app(MonsterService::class)->stateFor($monster)
                : null,
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="kids">
    {{-- The family goal has a reward, a price and a health bar to set, so it has
         a page of its own. What is left here is the glance: how the fight
         stands, and the way through to change it. --}}
    <div class="mb-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h3 class="font-baloo text-lg font-bold">Family Goal</h3>
                <p class="mt-1 text-[13px] text-fq-text-3">
                    @if ($arenaState)
                        What the kids are fighting for right now.
                    @else
                        Nothing standing, so the kids' work has nothing to land on.
                    @endif
                </p>
            </div>

            <a
                href="{{ route('parent.monsters') }}"
                wire:navigate
                class="rounded-[12px] border border-fq-line-3 bg-fq-sunk px-4 py-2 text-xs text-fq-text-3 transition hover:border-fq-lime hover:text-fq-text"
            >Open the Monster Deck</a>
        </div>

        @if ($arenaState)
            <div class="mt-3">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="text-[13px] font-semibold">
                        {{ $arenaState['name'] }} &middot; {{ $arenaState['reward'] }}
                    </span>
                    <span class="font-mono-fq text-[10px] text-fq-text-4">
                        {{ number_format($arenaState['damage']) }} / {{ number_format($arenaState['maxHealth']) }} PTS
                        &middot; {{ $arenaState['damagePercent'] }}%
                    </span>
                </div>

                <div class="mt-[6px] h-[10px] overflow-hidden rounded-full border border-fq-line bg-fq-track">
                    <div
                        class="h-full rounded-full transition-[width] duration-500"
                        style="width:{{ $arenaState['damagePercent'] }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime), var(--fq-gold))"
                    ></div>
                </div>
            </div>
        @endif

    </div>

    <div
        class="mb-[14px] rounded-[22px] border p-[18px]"
        style="background: var(--fq-wash-violet); border-color: color-mix(in srgb, var(--fq-magenta) 50%, transparent)"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="font-mono-fq text-[10px] tracking-[0.22em] uppercase" style="color: var(--fq-magenta)">Today's Mystery Chore</p>
                <h3 class="mt-1 font-baloo text-lg font-bold">
                    {{ $mysteryChore?->name ?? 'Nothing eligible today' }}
                </h3>
            </div>

            <div class="flex items-center gap-2">
                @if ($mysteryChore)
                    <span
                        class="rounded-[10px] border px-3 py-2 font-mono-fq text-[11px] whitespace-nowrap"
                        style="border-color: var(--fq-line-2); color: {{ match (true) {
                            $mysteryFinder !== null => 'var(--fq-lime)',
                            $mysteryClaimant !== null && $mysteryAwaiting === null => 'var(--fq-text-4)',
                            default => 'var(--fq-gold)',
                        } }}"
                    >
                        @if ($mysteryFinder)
                            FOUND BY {{ strtoupper($mysteryFinder->name) }}
                        @elseif ($mysteryAwaiting)
                            {{-- Waiting on you: the bonus is yours to hand over
                                 or send back, and until you do nobody has won. --}}
                            {{ strtoupper($mysteryAwaiting->name) }} — NEEDS APPROVAL
                        @elseif ($mysteryClaimant)
                            {{-- Done and signed off, but no bonus recorded — the
                                 chore is on cooldown for the whole house, so
                                 today's bonus can't be won on it any more. Pick
                                 a different one to put the game back in play. --}}
                            NOBODY WON IT
                        @else
                            UP FOR GRABS
                        @endif
                    </span>
                @endif

                <button
                    type="button"
                    wire:click="rerollMystery"
                    @disabled($mysteryFinder !== null || $mysteryAwaiting !== null)
                    class="rounded-[10px] border bg-fq-sunk px-3 py-2 text-xs whitespace-nowrap text-fq-text-3 disabled:opacity-40"
                    style="border-color: var(--fq-line-3)"
                >Pick a different one</button>
            </div>
        </div>

        @if ($mysteryMessage)
            <p class="mt-2 text-xs text-fq-text-4">{{ $mysteryMessage }}</p>
        @endif

        <p class="mt-2 text-xs text-fq-text-5">
            Only visible here — on the kids' boards this looks like any other chore, worth
            +{{ \App\Services\ChoreService::MYSTERY_BONUS_POINTS }} to whoever you approve for it first.
        </p>
    </div>

    <div class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-[14px]">
        @foreach ($kids as $kid)
            @php
                $dollars = number_format($kid->points / $profile->household->points_per_dollar, 2);
                $quest = $questSummaries[$kid->id];
                $spin = $spins[$kid->id];
                $chest = $chests[$kid->id];
                $questLabels = [
                    'not_started' => ['label' => 'Chest not opened', 'color' => 'var(--fq-text-4)'],
                    // The chest is open and the cards are on the table. Its own
                    // state because "not opened" and "opened, not done" both
                    // claim the kid has a quest, and at this point they don't.
                    'choosing' => ['label' => 'Choosing a card', 'color' => 'var(--fq-violet)'],
                    'opened' => ['label' => 'Opened, not done', 'color' => 'var(--fq-cyan)'],
                    'pending' => ['label' => 'Waiting on you', 'color' => 'var(--fq-gold)'],
                    'approved' => ['label' => 'Cleared', 'color' => 'var(--fq-lime)'],
                    'rejected' => ['label' => 'Sent back', 'color' => 'var(--fq-danger)'],
                ][$quest['status']];
            @endphp
            <div wire:key="kid-{{ $kid->id }}" class="flex flex-col gap-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-[44px] w-[44px] items-center justify-center rounded-[14px] font-baloo text-lg font-extrabold text-fq-bg"
                        style="background:{{ $kid->color->cssVar() }}"
                    >{{ mb_substr($kid->name, 0, 1) }}</div>
                    <div>
                        <p class="font-baloo text-[19px] font-bold">{{ $kid->name }}</p>
                        <p class="font-mono-fq text-[10px] text-fq-text-4 uppercase">AGE {{ $kid->age }} · LVL {{ $kid->level() }} · {{ $kid->streak }}D STREAK</p>
                    </div>
                </div>

                <div class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[10px]">
                    <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Today's Quest</p>
                    <div class="mt-1 flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold">
                            {{-- Fully qualified: a Volt SFC template section
                                 can't resolve a bare class name. --}}
                            {{ $quest['chore']?->name ?? ($quest['hand']->count().' '.\Illuminate\Support\Str::plural('card', $quest['hand']->count()).' dealt') }}
                        </span>
                        <span class="font-mono-fq text-[10px] font-semibold whitespace-nowrap" style="color: {{ $questLabels['color'] }}">{{ $questLabels['label'] }}</span>
                    </div>

                    {{-- What is actually on the table. Shown rather than hidden:
                         a parent deciding whether to re-deal needs to see what
                         they would be taking away. --}}
                    @if ($quest['hand']->isNotEmpty())
                        <p class="mt-1 font-mono-fq text-[10px] leading-snug text-fq-text-4">{{ $quest['hand']->pluck('name')->join(' · ') }}</p>
                    @endif

                    @php
                        // What the parent needs before they hand another one
                        // over: whether today is already charmed, and how many
                        // are sitting unspent in the pocket.
                        $charmNotes = array_filter([
                            $quest['charmed'] ? 'Chest is charmed' : null,
                            $quest['charmsHeld'] > 0
                                ? $quest['charmsHeld'].' '.\Illuminate\Support\Str::plural('charm', $quest['charmsHeld']).' in pocket'
                                : null,
                        ]);
                    @endphp
                    @if ($charmNotes)
                        <p class="mt-1 font-mono-fq text-[10px]" style="color: var(--fq-violet)">{{ implode(' · ', $charmNotes) }}</p>
                    @endif

                    <div class="mt-2 flex gap-2">
                        <button
                            type="button"
                            wire:click="rerollQuest({{ $kid->id }})"
                            @disabled(! $quest['canReroll'])
                            class="flex-1 rounded-[10px] border border-fq-line-3 bg-fq-panel py-[6px] text-xs text-fq-text-3 disabled:opacity-40"
                        >{{ $quest['chore'] ? 'Swap for new cards' : 'Deal a new hand' }}</button>

                        {{-- Never disabled: a charm is handed over, not cast,
                             so there is always a pocket for it to go in even on
                             a day whose chest is long since open. --}}
                        <button
                            type="button"
                            wire:click="giveQuestCharm({{ $kid->id }})"
                            class="flex-shrink-0 rounded-[10px] border px-3 py-[6px] text-xs whitespace-nowrap"
                            style="border-color: var(--fq-violet); color: var(--fq-violet); background: var(--fq-sunk)"
                        >&#10023; Give a charm</button>
                    </div>
                    @if (! empty($questMessages[$kid->id]))
                        <p class="mt-1 text-[11px] text-fq-text-4">{{ $questMessages[$kid->id] }}</p>
                    @endif
                </div>

                <div class="flex items-baseline justify-between rounded-[14px] border border-fq-line-2 bg-fq-sunk p-[14px]">
                    <span class="font-baloo text-[26px] font-extrabold text-fq-lime">{{ $kid->points }}</span>
                    <span class="font-mono-fq text-[10px] text-fq-text-4">PTS · ${{ $dollars }}</span>
                </div>

                {{-- Gold on olive, matching the kid header's ticket tile — a
                     parent handing out tickets should see the same currency the
                     kid does, not a purple lookalike. --}}
                <div class="rounded-[14px] border border-fq-ticket-line p-[14px]" style="background: var(--fq-ticket-bg)">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="font-baloo text-[22px] font-extrabold text-fq-lime">{{ $kid->bonus_tickets }}</span>
                        <span class="font-mono-fq text-[10px] text-fq-ticket-label">TICKETS · LVL {{ $kid->level() }}</span>
                    </div>
                    <div class="mt-[10px] flex gap-2">
                        @foreach ([-1, 1, 5] as $delta)
                            <button
                                type="button"
                                wire:click="adjustTickets({{ $kid->id }}, {{ $delta }})"
                                class="flex-1 rounded-[10px] border border-fq-ticket-line bg-fq-sunk px-2 py-[7px] font-mono-fq text-xs font-semibold text-fq-lime transition hover:brightness-120"
                            >{{ $delta > 0 ? '+' : '' }}{{ $delta }}</button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="mb-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Adjust Points</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ([-100, -25, 25, 100, 500] as $delta)
                            @php $positive = $delta > 0; @endphp
                            <button
                                type="button"
                                wire:click="adjustPoints({{ $kid->id }}, {{ $delta }})"
                                class="min-w-[48px] flex-1 rounded-[12px] px-[6px] py-[10px] font-mono-fq text-xs font-semibold"
                                style="
                                    background: {{ $positive ? 'rgba(255,225,77,0.22)' : 'rgba(255,107,107,0.18)' }};
                                    border: 1px solid {{ $positive ? 'rgba(255,225,77,0.5)' : 'rgba(255,107,107,0.45)' }};
                                    color: {{ $positive ? 'var(--fq-lime)' : 'var(--fq-negative-3)' }};
                                "
                            >{{ $delta > 0 ? '+' : '' }}{{ $delta }}</button>
                        @endforeach
                    </div>
                </div>

                <button
                    type="button"
                    wire:click="recordCashIn({{ $kid->id }})"
                    class="rounded-[14px] border border-dashed border-fq-line-4 bg-fq-sunk py-[10px] text-sm text-fq-text-3"
                >Record $5 cash turned in (+500)</button>

                <button
                    type="button"
                    wire:click="recordPayout({{ $kid->id }})"
                    wire:confirm="Pay out ${{ $dollars }} and zero {{ $kid->name }}'s balance?"
                    class="rounded-[14px] border border-fq-line-3 bg-fq-sunk py-[10px] text-sm text-fq-text-3"
                >Record payout — zero balance</button>

                <div class="flex items-center justify-between gap-2 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[10px]">
                    <div class="min-w-0 flex-1">
                        <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Bonus Wheel</p>
                        @if ($spin)
                            <p class="mt-[2px] flex items-baseline gap-2">
                                <span class="truncate text-sm font-semibold">{{ $spin->chore->name }}</span>
                                <span
                                    class="font-mono-fq text-[11px] font-semibold"
                                    style="color: {{ $spin->multiplier >= 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)' }}"
                                >{{ $spin->multiplier }}x</span>
                            </p>
                        @else
                            <p class="mt-[2px] text-sm text-fq-text-4">Hasn't spun today</p>
                        @endif
                    </div>
                    <button
                        type="button"
                        wire:click="resetSpin({{ $kid->id }})"
                        @disabled(! $spin)
                        class="flex-shrink-0 rounded-[12px] border border-fq-line-3 bg-fq-panel px-3 py-[6px] text-xs text-fq-text-3 disabled:opacity-40"
                    >Reset</button>
                </div>

                {{-- The own-bed card. Off unless a parent switches it on here,
                     and the whole block only appears once the household switch
                     is on — a family not using this shouldn't have to read
                     about it on every kid. --}}
                @if ($household->sleep_card_enabled)
                    <div
                        class="rounded-[14px] border px-3 py-[10px]"
                        style="border-color: {{ $kid->sleep_card_enabled ? 'var(--fq-line-cool)' : 'var(--fq-line-2)' }}; background: var(--fq-sunk)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Own Bed Card</p>
                            <button
                                type="button"
                                wire:click="toggleSleepCard({{ $kid->id }})"
                                class="rounded-[12px] border px-3 py-[6px] text-xs {{ $kid->sleep_card_enabled ? 'border-fq-lime text-fq-lime' : 'border-fq-line-3 bg-fq-panel text-fq-text-3' }}"
                            >{{ $kid->sleep_card_enabled ? 'On' : 'Off' }}</button>
                        </div>

                        @if ($kid->sleep_card_enabled)
                            <p class="mt-[6px] font-mono-fq text-[11px] text-fq-text-2">
                                {{ $kid->sleep_nights }} nights ·
                                {{ $kid->sleep_run }} in a row ·
                                best {{ $kid->sleep_best_run }}
                            </p>

                            <div class="mt-2 flex flex-wrap items-center gap-4">
                                @foreach ([['Nights', 'nights'], ['Run', 'run']] as [$label, $field])
                                    <div wire:key="sleep-{{ $field }}-{{ $kid->id }}" class="flex items-center gap-2">
                                        <span class="font-mono-fq text-[10px] text-fq-text-4 uppercase">{{ $label }}</span>
                                        <button
                                            type="button"
                                            wire:click="adjustSleep({{ $kid->id }}, {{ $field === 'nights' ? '-1, 0' : '0, -1' }})"
                                            class="h-7 w-7 rounded-[9px] border border-fq-line-3 bg-fq-panel text-sm"
                                        >&minus;</button>
                                        <button
                                            type="button"
                                            wire:click="adjustSleep({{ $kid->id }}, {{ $field === 'nights' ? '1, 0' : '0, 1' }})"
                                            class="h-7 w-7 rounded-[9px] border border-fq-line-3 bg-fq-panel text-sm"
                                        >+</button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- What the day's bonus chest paid. Everyone gets one whether
                     or not they've done anything, so an unopened one is a kid
                     who hasn't been in today rather than a kid who missed out. --}}
                <div
                    class="flex items-center justify-between gap-2 rounded-[14px] border px-3 py-[10px]"
                    style="border-color: {{ $chest ? 'var(--fq-chest-blue-line)' : 'var(--fq-line-2)' }}; background: var(--fq-sunk)"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Daily Chest</p>
                        @if ($chest)
                            <p class="mt-[2px] truncate text-sm font-semibold" style="color: var(--fq-chest-blue)">{{ $chest['prize'] }}</p>
                            <p class="font-mono-fq text-[10px] text-fq-text-5 uppercase">
                                Opened {{ $chest['openedAt']->diffForHumans() }}
                                · {{ $chest['questWasDone'] ? 'quest was cleared first' : 'quest was still open' }}
                            </p>
                        @else
                            <p class="mt-[2px] text-sm text-fq-text-4">Not opened today</p>
                        @endif
                    </div>

                    <span
                        class="flex-shrink-0 font-mono-fq text-[11px] font-semibold whitespace-nowrap"
                        style="color: {{ $chest ? 'var(--fq-chest-blue)' : 'var(--fq-text-4)' }}"
                    >{{ $chest ? 'CLAIMED' : 'WAITING' }}</span>
                </div>

                <div class="border-t border-fq-divider pt-3">
                    <p class="mb-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Change PIN</p>
                    <div class="flex gap-2">
                        <input
                            type="text" inputmode="numeric" maxlength="4"
                            wire:model="newPins.{{ $kid->id }}"
                            placeholder="4-digit PIN"
                            class="flex-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none"
                        >
                        <button type="button" wire:click="changePin({{ $kid->id }})" class="rounded-[14px] border border-fq-line-3 bg-fq-sunk px-3 py-2 text-sm text-fq-text-3">Update</button>
                    </div>
                    @if (! empty($pinMessages[$kid->id]))
                        <p class="mt-1 text-xs text-fq-text-4">{{ $pinMessages[$kid->id] }}</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- What the Arena reads. All three are the household's own call, and all
         three are safe to leave alone: the watch hour has a sensible default,
         and the week's target and prize simply don't draw until they're set. --}}
    <div class="mt-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <h3 class="font-baloo text-lg font-bold">The Arena</h3>
        <p class="mt-1 text-sm text-fq-text-2">
            The kids' landing page: whose run is on the line tonight, the streak race, and
            what the house is fighting.
        </p>

        <div class="mt-4 flex flex-wrap gap-[14px]">
            <div class="min-w-[240px] flex-1">
                <label for="watch-hour" class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                    Evening watch hour
                </label>
                <div class="mt-2 flex gap-2">
                    <select
                        id="watch-hour"
                        wire:model="eveningWatchHour"
                        class="flex-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none"
                    >
                        @foreach (range(15, 23) as $hour)
                            <option value="{{ $hour }}">{{ $hour > 12 ? $hour - 12 : $hour }}:00pm</option>
                        @endforeach
                    </select>
                </div>
                {{-- Says outright that it isn't a deadline. A parent reading
                     this as "bedtime" would expect the app to take something
                     away at it, and nothing ever does. --}}
                <p class="mt-2 text-[12.5px] leading-snug text-fq-text-4">
                    When an unfinished quest starts showing as <em>at risk</em> on the Arena.
                    Nothing expires — the day still rolls at
                    {{ $household->day_boundary_hour > 12 ? $household->day_boundary_hour - 12 : $household->day_boundary_hour }}:00am.
                </p>
            </div>

            <div class="min-w-[240px] flex-1">
                <label for="weekly-target" class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                    Weekly chore target
                </label>
                <input
                    id="weekly-target"
                    type="text"
                    inputmode="numeric"
                    wire:model="weeklyChoreTarget"
                    placeholder="e.g. 60"
                    class="mt-2 w-full rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none"
                >
                <p class="mt-2 text-[12.5px] leading-snug text-fq-text-4">
                    Everyone's chores count toward one bar, Sun&ndash;Sat. Leave blank and the
                    bar stays off.
                </p>
            </div>
        </div>

        <div class="mt-[14px] flex flex-wrap gap-[14px]">
            <div class="min-w-[240px] flex-1">
                <label for="weekly-prize" class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                    This week's prize
                </label>
                <input
                    id="weekly-prize"
                    type="text"
                    wire:model="weeklyPrize"
                    maxlength="120"
                    placeholder="e.g. Friday movie pick + $5"
                    class="mt-2 w-full rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none"
                >
            </div>

            <div class="min-w-[240px] flex-1">
                <label for="weekly-prize-note" class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                    Anything to add
                </label>
                <input
                    id="weekly-prize-note"
                    type="text"
                    wire:model="weeklyPrizeNote"
                    maxlength="160"
                    placeholder="e.g. Saturday after tea."
                    class="mt-2 w-full rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none"
                >
                {{-- The rule itself is stated by the app — the prize lands at
                     the weekly target. This is for anything else, so a parent
                     doesn't have to restate what the card already says. --}}
                <p class="mt-2 text-[12.5px] leading-snug text-fq-text-4">
                    The kids are already told they get this at the target. Optional extras only.
                </p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button
                type="button"
                wire:click="saveArenaSettings"
                wire:loading.attr="disabled"
                wire:target="saveArenaSettings"
                class="rounded-[14px] px-5 py-[11px] font-baloo text-[15px] font-bold transition hover:brightness-110 disabled:opacity-60"
                style="background: var(--fq-lime); color: var(--fq-ink)"
            >Save Arena settings</button>

            @if ($arenaMessage)
                <span class="text-[12.5px] text-fq-lime">{{ $arenaMessage }}</span>
            @endif
        </div>

        <p class="mt-3 font-mono-fq text-[10px] leading-snug text-fq-text-5">
            You hand the prize over yourself &mdash; the app shows it and never pays it.
        </p>
    </div>

    {{-- The own-bed feature, off for the whole household until switched on
         here. Two switches on purpose: this one decides whether the family uses
         it at all, and the per-kid one above decides who is asked. --}}
    <div class="mt-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-[240px] flex-1">
                <h3 class="font-baloo text-lg font-bold">Own Bed Card</h3>
                <p class="mt-1 text-sm text-fq-text-2">
                    A morning check-in for a kid working on sleeping in their own bed. Three
                    honest answers, and a bad night never takes anything away — it just
                    doesn't light a star. Seven stars finishes a constellation; nights in a
                    row pay tickets.
                </p>
            </div>

            <button
                type="button"
                wire:click="toggleHouseholdSleepCard"
                class="rounded-[12px] border px-4 py-2 text-sm font-semibold {{ $household->sleep_card_enabled ? 'border-fq-lime text-fq-lime' : 'border-fq-line-3 bg-fq-sunk text-fq-text-3' }}"
            >{{ $household->sleep_card_enabled ? 'On' : 'Off' }}</button>
        </div>

        @if ($household->sleep_card_enabled)
            @php
                $sleep = app(\App\Services\SleepService::class);
                $perPicture = $sleep->constellationPoints($household);
                $rate = max(1, (int) $household->points_per_dollar);

                // One dial per answer. A perfect night and a cuddle at 3am are
                // not worth the same, and neither is worth nothing.
                $dials = [];

                foreach (SleepOutcome::cases() as $outcome) {
                    $dials[] = [
                        'label' => $outcome->shortLabel(),
                        'value' => $sleep->pointsFor($household, $outcome),
                        'action' => "adjustNightPayout('{$outcome->value}', ",
                        'step' => \App\Services\SleepService::NIGHT_STEP,
                    ];
                }

                $dials[] = [
                    'label' => 'Constellation · '.\App\Enums\Constellation::NIGHTS.' nights',
                    'value' => $perPicture,
                    'action' => 'adjustConstellationPayout(',
                    'step' => \App\Services\SleepService::PAYOUT_STEP,
                ];

                // What a perfect week actually costs, which is the number worth
                // watching while calibrating — no single dial says it.
                $weekly = ($sleep->pointsFor($household, SleepOutcome::OwnBed) * 7) + $perPicture;
            @endphp

            {{-- A dial per answer, plus the picture. They do different jobs:
                 the nightly rates are what get a reluctant kid through the hard
                 part, and the picture is what makes a week mean something.
                 Tapering is the expected path — the money is there to start a
                 habit, not to run forever — and nothing already earned changes
                 when any of them moves. --}}
            <div class="mt-4 grid gap-4 border-t border-fq-divider pt-3 sm:grid-cols-2">
                @foreach ($dials as $dial)
                    <div wire:key="sleep-dial-{{ $loop->index }}">
                        <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">{{ $dial['label'] }}</p>

                        <div class="mt-1 flex items-center gap-2">
                            <button
                                type="button"
                                wire:click="{{ $dial['action'] }}-{{ $dial['step'] }})"
                                @disabled($dial['value'] === 0)
                                class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg disabled:opacity-40"
                            >&minus;</button>

                            <span class="w-20 text-center font-baloo text-[17px] font-extrabold text-fq-lime">
                                {{ number_format($dial['value']) }}
                            </span>

                            <button
                                type="button"
                                wire:click="{{ $dial['action'] }}{{ $dial['step'] }})"
                                class="h-8 w-8 rounded-[10px] border border-fq-line-3 bg-fq-sunk text-lg"
                            >+</button>

                            <span class="font-mono-fq text-[11px] text-fq-text-4">
                                {{ $dial['value'] === 0 ? 'nothing' : '$'.number_format($dial['value'] / $rate, 2) }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-3 font-mono-fq text-[11px] text-fq-text-4">
                A perfect week is
                <span class="text-fq-gold">${{ number_format($weekly / $rate, 2) }}</span>
                per kid &middot; about ${{ number_format($weekly / $rate / 7, 2) }} a night
            </p>

            <p class="mt-3 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                Now switch it on for whoever needs it, above
            </p>
        @endif
    </div>

    <div class="mt-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <p class="mb-2 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Your PIN (Parent Console)</p>
        <div class="flex max-w-[320px] gap-2">
            <input
                type="text" inputmode="numeric" maxlength="4"
                wire:model="newPins.{{ $profile->id }}"
                placeholder="4-digit PIN"
                class="flex-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none"
            >
            <button type="button" wire:click="changePin({{ $profile->id }})" class="rounded-[14px] border border-fq-line-3 bg-fq-sunk px-3 py-2 text-sm text-fq-text-3">Update</button>
        </div>
        @if (! empty($pinMessages[$profile->id]))
            <p class="mt-1 text-xs text-fq-text-4">{{ $pinMessages[$profile->id] }}</p>
        @endif
    </div>
</x-parent.shell>
