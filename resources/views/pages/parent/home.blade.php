<?php

use App\Enums\BountyKind;
use App\Enums\CompletionStatus;
use App\Enums\RedemptionStatus;
use App\Exceptions\BountyUnavailableException;
use App\Models\Bounty;
use App\Models\ChoreCompletion;
use App\Models\LuckyHit;
use App\Models\Profile;
use App\Models\Redemption;
use App\Enums\Feeling;
use App\Enums\FeelingVisibility;
use App\Services\BountyService;
use App\Services\CelebrationService;
use App\Services\ChoreService;
use App\Services\FeedService;
use App\Services\FeelingService;
use App\Services\HouseholdClock;
use App\Services\LuckyBlockService;
use App\Services\MealService;
use App\Services\StoreService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

/**
 * Parent Home — the queues, the house, and the feed beside them.
 *
 * Built the way the kid's Home is: a 340px column of rows on a desk (a board of
 * tiles on a phone) that opens one panel at a time, and the family feed with
 * the rest of the width. It used to be the feed on top and every queue stacked
 * underneath, so the thing a parent opens this page to do — sign off a chore —
 * was a scroll past the whole conversation away.
 *
 * The rows come in two groups: what is waiting on a grown-up (approvals,
 * redemptions, job offers, lucky wins), then the house (feelings, meals,
 * and a celebration day when there is one). Gratitude has a page of its own in
 * the menu, where every entry is kept rather than today's.
 */
new class extends Component
{
    public Profile $profile;

    /** Which row is open, by key, or null for none. One at a time. */
    public ?string $openRow = null;

    /** The rows that hold something a grown-up has to act on, in page order. */
    private const QUEUE_ROWS = ['approvals', 'redemptions', 'jobs', 'lucky'];

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isParent(), 403);
    }

    /**
     * Opens a row, or shuts the open one.
     *
     * Everything starts shut, every visit. What is waiting is said by the rows
     * themselves — a queue with anything in it is lit in its own colour — so
     * the page doesn't need to open one to make the point.
     */
    public function toggleRow(string $key): void
    {
        $this->openRow = $this->openRow === $key ? null : $key;
    }

    /** Offers of work a grown-up could hire, soonest to lapse first. */
    private function jobOffersQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Bounty::where('household_id', $this->profile->household_id)
            // Offers of work only. A sale runs the same machinery but can't be
            // hired — turning "my blue Lego set" into a one-time chore would
            // mint a chore named after the thing being sold.
            ->whereIn('kind', BountyKind::hireableCases())
            // Aimed at a sibling, so it is theirs to answer — a grown-up
            // taking it would be hijacking a deal between two kids.
            ->whereNull('target_profile_id')
            ->takeable();
    }

    public function approve(int $completionId): void
    {
        $completion = ChoreCompletion::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
            ->where('status', CompletionStatus::Pending)
            ->find($completionId);

        if ($completion) {
            $completion->loadMissing('profile', 'chore');
            app(ChoreService::class)->approve($completion, $this->profile);
            $this->dispatch(
                'celebrate',
                message: "{$completion->profile->name} earned +{$completion->points_awarded} for {$completion->chore->name}!",
                motion: 'burst',
                origin: 'tap',
            );
        }
    }

    public function sendBack(int $completionId): void
    {
        $completion = ChoreCompletion::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
            ->where('status', CompletionStatus::Pending)
            ->find($completionId);

        if ($completion) {
            app(ChoreService::class)->sendBack($completion, $this->profile);
        }
    }

    public function fulfill(int $redemptionId): void
    {
        $redemption = Redemption::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
            ->where('status', RedemptionStatus::Pending)
            ->find($redemptionId);

        if ($redemption) {
            app(StoreService::class)->fulfill($redemption, $this->profile);
        }
    }

    /**
     * Hands over a Lucky Block win.
     *
     * A tick-off and nothing else. There is no "reject" twin here the way
     * there is for a cash-out, because there is nothing to refund: the prize
     * was drawn against three tickets that are already spent, and turning it
     * down would mean un-drawing it. A prize that shouldn't have been in the
     * pool gets switched off on the Lucky Block screen instead.
     */
    public function tickOffLucky(int $hitId): void
    {
        $hit = LuckyHit::where('household_id', $this->profile->household_id)
            ->pending()
            ->find($hitId);

        if ($hit) {
            app(LuckyBlockService::class)->fulfill($hit, $this->profile);
        }
    }

    /**
     * Why a redemption was turned down, keyed by redemption id. Optional —
     * "you already have one" is worth saying, and a form that demands a reason
     * before a parent can undo a misclick is not.
     *
     * @var array<int, string>
     */
    public array $rejectReasons = [];

    /** What just happened to a redemption, so a card vanishing is explained. */
    public ?string $redemptionMessage = null;

    public function reject(int $redemptionId): void
    {
        $this->redemptionMessage = null;

        $redemption = Redemption::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
            ->where('status', RedemptionStatus::Pending)
            ->find($redemptionId);

        if (! $redemption) {
            return;
        }

        $name = $redemption->storeItem->name;
        $kid = $redemption->profile->name;

        if (app(StoreService::class)->reject($redemption, $this->profile, $this->rejectReasons[$redemptionId] ?? null)) {
            unset($this->rejectReasons[$redemptionId]);
            // Names the refund, because that is the part a parent is trusting
            // happened — the card disappearing on its own says nothing.
            $this->redemptionMessage = "{$name} turned down — {$redemption->cost_snapshot} points back to {$kid}.";
        }
    }

    /**
     * What a parent will actually pay for a job a kid has offered to do,
     * keyed by bounty id. Seeded from the asking price so the field is never
     * empty, and editable because a slightly-too-high ask should get met in
     * the middle rather than quietly ignored.
     *
     * @var array<int, int|string>
     */
    public array $hirePrices = [];

    public ?string $hireMessage = null;

    public function hire(int $bountyId): void
    {
        $this->hireMessage = null;

        $bounty = Bounty::where('household_id', $this->profile->household_id)->find($bountyId);

        if (! $bounty) {
            $this->hireMessage = 'That job is no longer on the board.';

            return;
        }

        try {
            // Hiring pays nothing here. It creates a one-time chore already
            // claimed by the kid, which then runs the ordinary approval path —
            // so the points only exist once the work is signed off below.
            app(BountyService::class)->hire($bounty, $this->profile, (int) ($this->hirePrices[$bountyId] ?? $bounty->reward_amount));

            $this->hireMessage = "Hired {$bounty->poster->name}. It's in the list below once they've done it.";
            unset($this->hirePrices[$bountyId]);
        } catch (BountyUnavailableException|InvalidArgumentException $e) {
            $this->hireMessage = $e->getMessage();
        }
    }

    /**
     * Today's feeling, and optionally why. Same call the kids make.
     *
     * Worth a parent knowing: what you put here is the strongest signal in the
     * feature. A house where the grown-ups post "happy" every day teaches the
     * kids what the acceptable answer is, and they will give it to you.
     */
    public function answerFeeling(
        ?string $feeling = null,
        ?string $because = null,
        ?string $visibility = null,
        ?string $newWord = null,
        ?string $newGlyph = null,
        ?string $lockPin = null,
    ): bool {
        $this->feelingLockMessage = null;

        $service = app(FeelingService::class);

        // A typed word is created and used here rather than by a separate
        // button — see FeelingService::resolveTypedWord().
        $choice = $service->resolveTypedWord($this->profile, $newWord, $newGlyph)
            ?? $service->resolveAnswer($this->profile, $feeling);

        if (! $choice) {
            return false;
        }

        $saved = $service->record(
            $this->profile,
            $choice,
            $because,
            FeelingVisibility::tryFrom((string) $visibility) ?? FeelingVisibility::Private,
            $lockPin,
        );

        if (! $saved) {
            $this->feelingLockMessage = 'That PIN did not match. Nothing was saved — your words are still here.';

            return false;
        }

        return true;
    }

    /** Grown-ups can lock a reason too. Same card, same rules. */
    public ?string $openedFeeling = null;

    public ?string $feelingLockMessage = null;

    public function lockFeeling(string $pin): void
    {
        $this->openedFeeling = null;

        $this->feelingLockMessage = app(FeelingService::class)->lock($this->profile, $pin)
            ? null
            : 'That PIN did not match, so nothing was locked.';
    }

    public function openFeeling(int $entryId, string $pin): void
    {
        $this->feelingLockMessage = null;
        $this->openedFeeling = app(FeelingService::class)->openLocked($this->profile, $entryId, $pin);

        if ($this->openedFeeling === null) {
            $this->feelingLockMessage = 'That PIN did not open it.';
        }
    }

    /**
     * Say something back to one of the kids.
     *
     * Notifies nothing and nobody. It appears on their own card when they next
     * open it — the way a note left on a pillow does.
     */
    public function replyToFeeling(int $entryId, string $body): void
    {
        app(FeelingService::class)->reply($this->profile, $entryId, $body);
    }

    /** Take back your own words. Never the other parent's. */
    public function deleteFeelingReply(int $replyId): void
    {
        app(FeelingService::class)->deleteReply($this->profile, $replyId);
    }

    public function retireFeelingWord(int $wordId): void
    {
        app(FeelingService::class)->retireWord($this->profile, $wordId);
    }

    public function with(): array
    {
        $household = $this->profile->household;

        $jobOffers = $this->jobOffersQuery()
            ->with('poster')
            ->oldest('expires_at')
            ->get();

        foreach ($jobOffers as $offer) {
            $this->hirePrices[$offer->id] ??= $offer->reward_amount;
        }

        $completions = ChoreCompletion::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
            ->where('status', CompletionStatus::Pending)
            ->with(['profile', 'chore'])
            ->oldest('submitted_at')
            ->get();

        $redemptions = Redemption::whereHas('profile', fn ($q) => $q->where('household_id', $this->profile->household_id))
            ->where('status', RedemptionStatus::Pending)
            ->with(['profile', 'storeItem'])
            ->oldest('requested_at')
            ->get();

        $luckyHits = app(LuckyBlockService::class)->pendingFor($household);

        // Parents answer this one too, and that is the mechanism rather than a
        // courtesy: a kid who is the only one being asked how he feels is being
        // examined, and answers "fine". See FeelingService.
        $feelingsCard = app(FeelingService::class)->cardFor($this->profile);
        $roster = $household->profiles()->count();
        $meals = app(MealService::class)->upcoming($household);
        $today = HouseholdClock::for($household)->today();
        $tonight = $meals->first(fn ($meal) => $meal->served_on->isSameDay($today));

        // A celebration day, and what the kids said about it. Parents only: a
        // first day that went badly is not something a sibling who had a good
        // one gets to read, which is the rule the feelings card's replies
        // already follow.
        $celebration = app(CelebrationService::class)->activeFor($household);
        $celebrationAnswers = $celebration
            ? app(CelebrationService::class)->houseAnswers($household, $celebration['key'])
            : collect();

        /*
         * A queue row: lit in its own colour while anything is in it, quiet once it
     * is clear.
         * The count is the status, because "how many" is the whole question a
         * parent glancing at this is asking.
         */
        $queue = fn (string $key, string $glyph, string $label, string $tileLabel, string $accent, int $count, string $sub) => [
            'key' => $key,
            'glyph' => $glyph,
            'label' => $label,
            'tileLabel' => $tileLabel,
            'accent' => $accent,
            'sub' => $sub,
            'status' => $count > 0 ? $count.' WAITING' : 'CLEAR',
            'statusColor' => $count > 0 ? $accent : 'var(--fq-text-4)',
            'done' => $count === 0,
            'quiet' => false,
            'attention' => $count > 0,
        ];

        $said = $roster - $feelingsCard['waiting'];

        $rows = collect([
            $queue('approvals', '✅', 'Chore Approvals', 'Chores', 'var(--fq-lime)', $completions->count(),
                $completions->isEmpty() ? "Queue's clear" : $completions->pluck('profile.name')->unique()->join(', ')),
            $queue('redemptions', '🎁', 'Redemption Requests', 'Rewards', 'var(--fq-cyan)', $redemptions->count(),
                $redemptions->isEmpty() ? 'Nothing to hand over' : $redemptions->pluck('storeItem.name')->join(', ')),
            $queue('jobs', '💼', 'Jobs On Offer', 'Jobs', 'var(--fq-gold)', $jobOffers->count(),
                $jobOffers->isEmpty() ? 'Nobody is offering' : $jobOffers->pluck('description')->join(', ')),
            $queue('lucky', '🍀', 'Lucky Block Wins', 'Lucky', 'var(--fq-green)', $luckyHits->count(),
                $luckyHits->isEmpty() ? 'No prizes owed' : $luckyHits->pluck('prize_name')->join(', ')),
            [
                'key' => 'feelings',
                'glyph' => '💬',
                'label' => 'Feelings',
                'tileLabel' => 'Feelings',
                'accent' => 'var(--fq-violet)',
                'sub' => $feelingsCard['answered'] ? 'You said '.$feelingsCard['answered']->label() : 'You have not said yet',
                'status' => $said.' OF '.$roster.' SAID',
                'statusColor' => $feelingsCard['answered'] ? 'var(--fq-text-4)' : 'var(--fq-violet)',
                'done' => false,
                'quiet' => $feelingsCard['answered'] !== null,
            ],
            [
                'key' => 'meals',
                'glyph' => '🍽',
                'label' => 'Meals',
                'tileLabel' => 'Meals',
                'accent' => 'var(--fq-cyan)',
                'sub' => $tonight ? 'Tonight · '.$tonight->name : 'Tonight · not set',
                'status' => $meals->count().' SET',
                // Unset tonight is the one thing on this row a grown-up can fix.
                'statusColor' => $tonight ? 'var(--fq-text-4)' : 'var(--fq-gold)',
                'done' => false,
                'quiet' => $tonight !== null,
            ],
        ]);

        if ($celebration) {
            $rows->push([
                'key' => 'celebration',
                'glyph' => '🎉',
                'label' => $celebration['kicker'],
                'tileLabel' => 'Big day',
                'accent' => $celebration['accent'],
                'sub' => $celebrationAnswers->count().' '.Str::plural('answer', $celebrationAnswers->count()).' so far',
                'status' => 'GROWN-UPS',
                'statusColor' => 'var(--fq-text-4)',
                'done' => false,
                'quiet' => false,
            ]);
        }

        return [
            'rows' => $rows,
            'waitingTotal' => $completions->count() + $redemptions->count() + $jobOffers->count() + $luckyHits->count(),
            'queueCount' => count(self::QUEUE_ROWS),
            'jobOffers' => $jobOffers,
            'completions' => $completions,
            'redemptions' => $redemptions,
            'luckyHits' => $luckyHits,
            'feelingsCard' => $feelingsCard,
            'roster' => $roster,
            'meals' => $meals,
            'mealsToday' => $today,
            'celebration' => $celebration,
            'celebrationAnswers' => $celebrationAnswers,
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="home">
    <livewire:push-toggle audience="parent" />

    {{-- Two columns at desk size, one everywhere else — the kid's Home, for the
         grown-ups. The rows are a 340px rail and the feed gets the rest; on a
         phone the rows are a board of tiles with the feed under it.

         The feed used to sit on top with every queue stacked beneath it, capped
         to a scrolling box so a busy afternoon couldn't push the approvals out
         of reach. With the queues behind rows beside it, nothing is under the
         feed any more, so the box came off — and the quiet half came out of it,
         because feelings and the menu are rows here, and gratitude has a page. --}}
    <div class="mt-4 grid gap-4 lg:grid-cols-[340px_minmax(0,1fr)] lg:items-start">
        @php
            // Where the open panel goes — see <x-day-index>.
            $openIndex = $rows->search(fn (array $row) => $row['key'] === $openRow);
            $panelOrder = $openIndex === false ? 90 : 3 + $openIndex * 2;
        @endphp

        <div class="flex flex-col gap-[11px] lg:gap-[9px]">
            <div class="flex items-center gap-2" style="order: 0">
                <span class="h-[15px] w-[3px] rounded-[2px]" style="background: var(--fq-cyan)"></span>
                <h2 class="font-baloo text-[17px] font-extrabold">Today</h2>
                <span class="flex-1"></span>
                <span class="font-mono-fq text-[9.5px] tracking-[0.1em] uppercase" style="color: {{ $waitingTotal > 0 ? 'var(--fq-lime)' : 'var(--fq-text-4)' }}">
                    {{ $waitingTotal > 0 ? $waitingTotal.' waiting on you' : 'All clear' }}
                </span>
            </div>

            {{-- The queues first, then the house, with a breath between. --}}
            <x-day-index :rows="$rows" :open-row="$openRow" :break-before="$queueCount" />

            <div id="day-panel" class="flex min-w-0 flex-col gap-[11px]" style="order: {{ $panelOrder }}">
                @if ($openRow === 'approvals')
                @if ($completions->isEmpty())
                    <div class="rounded-[20px] border border-dashed border-fq-line bg-fq-panel p-[34px] text-center text-sm text-fq-text-5">
                        Queue's clear. Nothing to approve.
                    </div>
                @else
                    <div class="grid gap-3">
                        @foreach ($completions as $completion)
                            <div wire:key="completion-{{ $completion->id }}" class="flex flex-col gap-[13px] rounded-[20px] border border-fq-line bg-fq-panel p-4">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[12px] font-baloo text-sm font-extrabold text-fq-bg"
                                        style="background:{{ $completion->profile->color->cssVar() }}"
                                    >{{ mb_substr($completion->profile->name, 0, 1) }}</div>
                                    <div class="flex-1">
                                        <p class="text-[15px] font-semibold">{{ $completion->chore->name }}</p>
                                        <p class="font-mono-fq text-[10px] text-fq-text-4">{{ $completion->profile->name }} · {{ $completion->submitted_at->diffForHumans() }}</p>
                                    </div>
                                    <span class="font-baloo text-lg font-extrabold text-fq-lime">+{{ $completion->points_awarded }}</span>
                                </div>
                                <div class="flex gap-2">
                                    <button type="button" wire:click="approve({{ $completion->id }})" class="flex-1 rounded-[13px] py-[11px] text-sm font-bold text-fq-bg" style="background:var(--fq-lime)">Approve</button>
                                    <button type="button" wire:click="sendBack({{ $completion->id }})" class="rounded-[13px] border border-fq-line-3 bg-fq-sunk px-[14px] py-[11px] text-sm text-fq-text-3">Send back</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                @endif

                @if ($openRow === 'redemptions')
                @if ($redemptions->isEmpty())
                    <div class="rounded-[20px] border border-dashed border-fq-line bg-fq-panel p-[34px] text-center text-sm text-fq-text-5">
                        No redemptions waiting.
                    </div>
                @else
                    <div class="grid gap-3">
                        @foreach ($redemptions as $redemption)
                            <div wire:key="redemption-{{ $redemption->id }}" class="flex flex-col gap-[13px] rounded-[20px] border border-fq-line bg-fq-panel p-4">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[12px] font-baloo text-sm font-extrabold text-fq-bg"
                                        style="background:{{ $redemption->profile->color->cssVar() }}"
                                    >{{ mb_substr($redemption->profile->name, 0, 1) }}</div>
                                    <div class="min-w-0 flex-1">
                                        {{-- The name is the link when there is one. A
                                             redemption for a thing a kid picked out is a
                                             shopping errand, and the page you need is the
                                             one they were looking at — retyping it from
                                             the Loot Shop admin is the step this removes.

                                             Same new-tab rules as the kid's card: this
                                             leaves the app for somewhere nobody here
                                             controls. --}}
                                        @if ($redemption->storeItem->url)
                                            <a
                                                href="{{ $redemption->storeItem->url }}"
                                                target="_blank"
                                                rel="noopener noreferrer nofollow"
                                                class="inline-flex items-center gap-[6px] text-[15px] font-semibold transition hover:brightness-125"
                                                style="color: var(--fq-cyan)"
                                            >
                                                {{ $redemption->storeItem->name }}
                                                <i aria-hidden="true" class="fa-fw fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                            </a>
                                        @else
                                            <p class="text-[15px] font-semibold">{{ $redemption->storeItem->name }}</p>
                                        @endif
                                        <p class="font-mono-fq text-[10px] text-fq-text-4">{{ $redemption->profile->name }} · {{ $redemption->requested_at->diffForHumans() }}</p>
                                    </div>
                                    <span class="font-baloo text-lg font-extrabold text-fq-gold">-{{ $redemption->cost_snapshot }}</span>
                                </div>
                                {{-- Optional, and above the buttons so it is obviously
                                     attached to the refusal rather than to the reward. --}}
                                <input
                                    type="text"
                                    wire:model="rejectReasons.{{ $redemption->id }}"
                                    maxlength="160"
                                    placeholder="Why not? (optional)"
                                    class="w-full rounded-[12px] border border-dashed border-fq-line-2 bg-fq-sunk px-3 py-2 text-[13px] outline-none focus:border-fq-coral"
                                >

                                <div class="flex gap-2">
                                    <button type="button" wire:click="fulfill({{ $redemption->id }})" class="flex-1 rounded-[13px] py-[11px] text-sm font-bold text-fq-bg" style="background:var(--fq-cyan)">Mark fulfilled</button>

                                    {{-- Points leave a kid's balance the moment they ask,
                                         so a request nobody meant to grant has already
                                         been paid for. This is what hands it back. --}}
                                    <button
                                        type="button"
                                        wire:click="reject({{ $redemption->id }})"
                                        wire:confirm="Turn down '{{ $redemption->storeItem->name }}' and give {{ $redemption->profile->name }} their {{ $redemption->cost_snapshot }} points back?"
                                        class="rounded-[13px] border px-[14px] py-[11px] text-sm text-fq-danger"
                                        style="border-color: var(--fq-danger-border)"
                                    >Reject</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                    @if ($redemptionMessage)
                        <p class="text-sm text-fq-lime">{{ $redemptionMessage }}</p>
                    @endif
                @endif

                @if ($openRow === 'jobs')
                    {{-- Hiring one doesn't pay anything — it drops a one-time
                         chore into Chore Approvals, where it gets signed off and
                         paid like any other work.

                         The message sits outside the list, because hiring the
                         last offer empties it — and a confirmation that
                         disappears with the thing it is confirming leaves the
                         parent wondering whether the tap landed. --}}
                    @if ($hireMessage)
                        <p class="text-sm font-semibold text-fq-lime">{{ $hireMessage }}</p>
                    @endif

                    @if ($jobOffers->isEmpty())
                        <div class="rounded-[20px] border border-dashed border-fq-line bg-fq-panel p-[34px] text-center text-sm text-fq-text-5">
                            Nobody is offering a job right now.
                        </div>
                    @else
                    <div class="grid gap-3">
                        @foreach ($jobOffers as $offer)
                            <div wire:key="job-offer-{{ $offer->id }}" class="flex flex-col gap-[13px] rounded-[20px] border border-fq-line bg-fq-panel p-4">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="grid h-[30px] w-[30px] shrink-0 place-items-center rounded-[9px] font-baloo text-[13px] font-extrabold text-fq-bg"
                                        style="background: {{ $offer->poster->color->cssVar() }}"
                                    >{{ mb_substr($offer->poster->name, 0, 1) }}</span>

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[15px] font-semibold">{{ $offer->description }}</p>
                                        <p class="font-mono-fq text-[10px] text-fq-text-5">
                                            {{ $offer->poster->name }} asks {{ $offer->rewardText() }} &middot;
                                            {{ $offer->expires_at->diffForHumans(['parts' => 1, 'syntax' => Carbon\Carbon::DIFF_ABSOLUTE]) }} left
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <label class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase" for="hire-{{ $offer->id }}">
                                        Pay
                                    </label>
                                    <input
                                        id="hire-{{ $offer->id }}"
                                        type="number"
                                        wire:model="hirePrices.{{ $offer->id }}"
                                        min="1"
                                        max="1000"
                                        class="w-[100px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-sm outline-none focus:border-fq-cyan"
                                    >
                                    <span class="font-mono-fq text-[10px] text-fq-text-5">PTS</span>

                                    <button
                                        type="button"
                                        wire:click="hire({{ $offer->id }})"
                                        class="ml-auto rounded-[12px] px-4 py-2 text-[13px] font-semibold text-fq-bg transition hover:brightness-110"
                                        style="background: var(--fq-lime)"
                                    >Hire</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @endif
                @endif

                @if ($openRow === 'lucky')
                    {{-- Tickets, not points — the one thing in the queues that
                         cost no money and can't be refunded. --}}
                    @if ($luckyHits->isEmpty())
                        <div class="rounded-[20px] border border-dashed border-fq-line bg-fq-panel p-[34px] text-center text-sm text-fq-text-5">
                            No Lucky Block prizes owed.
                        </div>
                    @else
                    <div class="grid gap-3">
                        @foreach ($luckyHits as $hit)
                            <div
                                wire:key="lucky-hit-{{ $hit->id }}"
                                class="flex flex-col gap-[13px] rounded-[20px] border p-4"
                                style="border-color: var(--fq-ticket-line); background: var(--fq-ticket-bg)"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[12px] font-baloo text-sm font-extrabold text-fq-bg"
                                        style="background:{{ $hit->profile->color->cssVar() }}"
                                    >{{ mb_substr($hit->profile->name, 0, 1) }}</div>

                                    <div class="min-w-0 flex-1">
                                        <p class="flex items-center gap-2 text-[15px] font-semibold">
                                            <x-chore-icon :icon="$hit->iconClass()" class="text-base" style="color: var(--fq-lime)" />
                                            {{ $hit->prize_name }}
                                        </p>
                                        <p class="font-mono-fq text-[10px] text-fq-text-4">
                                            {{ $hit->profile->name }} · {{ $hit->won_at->diffForHumans() }}
                                        </p>
                                    </div>

                                    {{-- Tickets, not points, and the card says so — this is
                                         the one thing in the approvals queue that cost no
                                         money and can't be refunded. --}}
                                    <span class="shrink-0 font-baloo text-lg font-extrabold text-fq-gold">
                                        &minus;{{ $hit->tickets_spent }}<i aria-hidden="true" class="fa-solid fa-ticket ml-1 text-[11px]"></i>
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    wire:click="tickOffLucky({{ $hit->id }})"
                                    class="w-full rounded-[13px] py-[11px] text-sm font-bold text-fq-bg"
                                    style="background: var(--fq-green)"
                                >Handed over</button>
                            </div>
                        @endforeach
                    </div>
                    @endif
                @endif

                @if ($openRow === 'feelings')
                    {{-- The card only does its job if the grown-ups actually fill
                         it in — a house where the adults never answer teaches the
                         kids exactly what the card is worth. Replies to the kids
                         are written from here too. --}}
                    <x-feelings-card :card="$feelingsCard" :opened-feeling="$openedFeeling" :lock-message="$feelingLockMessage" />
                @endif

                @if ($openRow === 'meals')
                    <x-meal-list :meals="$meals" :today="$mealsToday" />

                    <a
                        href="{{ route('parent.meals') }}"
                        wire:navigate
                        class="self-start rounded-[12px] border border-fq-line-3 bg-fq-sunk px-[14px] py-[9px] text-[13px] text-fq-text-2-b transition hover:border-fq-line-4 hover:text-fq-text"
                    >Change the menu &rarr;</a>
                @endif

                @if ($openRow === 'celebration' && $celebration)
                    {{-- What the kids said about the celebration day. Nothing here
                         notifies, flags or highlights a difficult answer, for the
                         same reason nothing does on the feelings card — the
                         instant a hard answer summons a parent, saying it costs
                         something. It sits here and waits to be read. --}}
                    <div
                        class="rounded-[20px] border p-5"
                        style="border-color: {{ $celebration['accent'] }}; background: color-mix(in srgb, {{ $celebration['accent'] }} 10%, var(--fq-panel))"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="font-baloo text-xl font-bold">{{ $celebration['kicker'] }}</h2>
                            <span class="font-mono-fq text-[10px] tracking-[0.16em] text-fq-text-4 uppercase">Grown-ups only</span>
                        </div>

                        @if ($celebrationAnswers->isEmpty())
                            <p class="mt-2 text-sm text-fq-text-3">
                                Nobody has said how it went yet. Their chest is on their Home page, behind the question.
                            </p>
                        @else
                            <ul class="mt-3 flex flex-col gap-3">
                                @foreach ($celebrationAnswers as $entry)
                                    @php $option = app(CelebrationService::class)->answerOption($celebration, $entry->answer); @endphp

                                    <li wire:key="celebration-answer-{{ $entry->id }}" class="rounded-[14px] bg-fq-sunk px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-baloo text-[16px] font-bold">{{ $entry->profile?->name }}</span>

                                            @if ($option)
                                                <span class="font-mono-fq text-[12px]" style="color: {{ $option['color'] }}">
                                                    <span aria-hidden="true">{{ $option['glyph'] }}</span> {{ $option['label'] }}
                                                </span>
                                            @endif

                                            <span class="ml-auto font-mono-fq text-[10px] text-fq-text-5 uppercase">
                                                {{ $entry->isOpened() ? 'Chest opened' : 'Chest still shut' }}
                                            </span>
                                        </div>

                                        @if ($entry->note)
                                            <p class="mt-2 text-sm leading-relaxed text-fq-text-2">“{{ $entry->note }}”</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>{{-- /the open panel --}}
        </div>

        {{-- The family feed, in full. The only place a grown-up reads it — a
             page you have to go to is a page you check when you already suspect
             something is there, and a message from one of the kids is the one
             thing on this screen somebody is waiting on an answer to. --}}
        <div class="flex min-w-0 flex-col gap-3">
            <h2 class="font-baloo text-xl font-bold">Family</h2>

            <livewire:family-feed :embedded="true" :capped="false" :quiet="false" />
        </div>
    </div>
</x-parent.shell>
