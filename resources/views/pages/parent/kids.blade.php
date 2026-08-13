<?php

use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Enums\TicketKind;
use App\Models\ChoreCompletion;
use App\Models\Monster;
use App\Models\Profile;
use App\Services\BadgeService;
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\HouseholdClock;
use App\Services\LedgerService;
use App\Services\MonsterService;
use App\Services\SpinService;
use App\Services\TicketService;
use Illuminate\Support\Facades\Auth;
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

        $this->questMessages[$profileId] = $quest
            ? "Swapped to \"{$quest->chore->name}\"."
            : 'Nothing to swap — the quest is already cleared, or there is no other eligible chore.';
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
     *
     * A bought day off gets its own state for the same reason: the kid's page
     * says "Day Off · Board Open" and the parent's said "Not opened yet", which
     * reads as a kid who has done nothing rather than one who spent tickets on
     * a rest day.
     */
    private function questSummaryFor(Profile $kid): array
    {
        $chores = app(ChoreService::class);
        $quest = $chores->questFor($kid);

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
            $chores->hasSkippedQuestToday($kid) => 'skipped',
            $quest->revealed_at !== null => 'opened',
            default => 'not_started',
        };

        return [
            'chore' => $quest->chore,
            'status' => $status,
            // Mirrors what rerollQuest() will actually do, so the button isn't
            // dead on a quest that is still swappable — opened and sent-back
            // quests both still are.
            'canReroll' => $quest->completed_at === null,
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
        $kids->each(fn (Profile $kid) => $chores->syncStreak($kid));
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
            // A glance only — naming rewards, pricing them and setting health
            // all live on the Monster Deck, which is where the per-tier
            // dollars-per-point readout can sit beside them.
            'arenaStates' => app(MonsterService::class)
                ->live($household)
                ->map(fn (Monster $monster) => app(MonsterService::class)->stateFor($monster))
                ->all(),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="kids">
    {{-- The family goal is three monsters now, each with its own reward and its
         own health, so it has a page of its own. What is left here is the
         glance: how the arena stands, and the way through to change it. --}}
    <div class="mb-[14px] rounded-[22px] border border-fq-line bg-fq-panel p-[18px]">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h3 class="font-baloo text-lg font-bold">Family Goals</h3>
                <p class="mt-1 text-[13px] text-fq-text-3">
                    @if ($arenaStates)
                        What the kids are fighting for right now.
                    @else
                        Nothing standing, so the kids have nothing to aim at.
                    @endif
                </p>
            </div>

            <a
                href="{{ route('parent.monsters') }}"
                wire:navigate
                class="rounded-[12px] border border-fq-line-3 bg-fq-sunk px-4 py-2 text-xs text-fq-text-3 transition hover:border-fq-lime hover:text-fq-text"
            >Open the Monster Deck</a>
        </div>

        @if ($arenaStates)
            <div class="mt-3 flex flex-col gap-[10px]">
                @foreach ($arenaStates as $tierValue => $state)
                    <div wire:key="goal-tier-{{ $tierValue }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-[13px] font-semibold">
                                {{ $state['tier']->label() }} &middot; {{ $state['reward'] }}
                            </span>
                            <span class="font-mono-fq text-[10px] text-fq-text-4">
                                {{ number_format($state['damage']) }} / {{ number_format($state['maxHealth']) }} PTS
                                &middot; {{ $state['damagePercent'] }}%
                            </span>
                        </div>

                        <div class="mt-[6px] h-[10px] overflow-hidden rounded-full border border-fq-line bg-fq-track">
                            <div
                                class="h-full rounded-full transition-[width] duration-500"
                                style="width:{{ $state['damagePercent'] }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime), var(--fq-gold))"
                            ></div>
                        </div>
                    </div>
                @endforeach
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
                    'not_started' => ['label' => 'Not opened yet', 'color' => 'var(--fq-text-4)'],
                    'opened' => ['label' => 'Opened, not done', 'color' => 'var(--fq-cyan)'],
                    'skipped' => ['label' => 'Day off — bought', 'color' => 'var(--fq-violet)'],
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
                        <span class="text-sm font-semibold">{{ $quest['chore']->name }}</span>
                        <span class="font-mono-fq text-[10px] font-semibold whitespace-nowrap" style="color: {{ $questLabels['color'] }}">{{ $questLabels['label'] }}</span>
                    </div>
                    <button
                        type="button"
                        wire:click="rerollQuest({{ $kid->id }})"
                        @disabled(! $quest['canReroll'])
                        class="mt-2 w-full rounded-[10px] border border-fq-line-3 bg-fq-panel py-[6px] text-xs text-fq-text-3 disabled:opacity-40"
                    >Swap for a different chore</button>
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
                                    style="color: {{ $spin->multiplier === 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)' }}"
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
