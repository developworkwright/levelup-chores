<?php

use App\Enums\BountyKind;
use App\Enums\BountyStatus;
use App\Enums\SiblingOfferStatus;
use App\Enums\TradeAsset;
use App\Exceptions\BountyUnavailableException;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\OfferUnavailableException;
use App\Models\Bounty;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Services\BountyService;
use App\Services\SiblingOfferService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Trades and jobs on one page, because they were only ever one idea.
 *
 * Named for the two things a kid comes here to do. The code below still says
 * "bounty" for the job half — that is {@see BountyService}'s vocabulary and it
 * is not worth a rename across a service, a model and a table for a label.
 *
 * A kid has two questions: what is the deal, and who is it for. The first has
 * three answers — swap currency, pay for a job, offer to do a job — and only a
 * job has a second answer worth asking about, since a swap is inherently
 * between two people while a job can go to the whole household.
 *
 * The two engines behind it stay separate on purpose. A swap settles the
 * instant it is accepted because there is no work in it; a job is claimed,
 * reported done and confirmed. Splitting them by *audience* was the old
 * mistake — the same deal paid up front when aimed at a sibling and ran the
 * full cycle when posted openly.
 */
new class extends Component
{
    public Profile $profile;

    public ?string $flashMessage = null;

    public ?string $errorMessage = null;

    /** null, or one of swap|wanted|offered. Transient — this visit only. */
    public ?string $mode = null;

    public string $giveAsset = 'points';

    public string $giveAmount = '';

    public string $getAsset = 'tickets';

    public string $getAmount = '';

    public string $jobDescription = '';

    public string $jobAsset = 'points';

    /**
     * A string, like the two swap amounts, because a number input that the kid
     * clears posts back an empty string — and an `int` property takes that as
     * a type error rather than as an empty box. Cast at the point of use, where
     * the service's range check catches whatever it comes out as.
     */
    public string $jobAmount = '100';

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    public function choose(?string $mode): void
    {
        $this->mode = in_array($mode, ['swap', 'wanted', 'offered'], true) ? $mode : null;
        $this->clearMessages();
    }

    /**
     * Picking an asset on one side pushes the other side off it if they now
     * match: swapping points for points is never what a kid meant, and letting
     * the form reach a state the service will only reject is worse than moving
     * the picker they aren't looking at.
     */
    public function setGiveAsset(string $asset): void
    {
        $this->giveAsset = TradeAsset::tryFrom($asset)?->value ?? TradeAsset::Points->value;

        if ($this->getAsset === $this->giveAsset) {
            $this->getAsset = $this->giveAsset === TradeAsset::Points->value
                ? TradeAsset::Tickets->value
                : TradeAsset::Points->value;
        }
    }

    public function setGetAsset(string $asset): void
    {
        $this->getAsset = TradeAsset::tryFrom($asset)?->value ?? TradeAsset::Tickets->value;

        if ($this->giveAsset === $this->getAsset) {
            $this->giveAsset = $this->getAsset === TradeAsset::Points->value
                ? TradeAsset::Tickets->value
                : TradeAsset::Points->value;
        }
    }

    public function setJobAsset(string $asset): void
    {
        $chosen = TradeAsset::tryFrom($asset);

        if ($chosen && $chosen->isCurrency()) {
            $this->jobAsset = $asset;
            // Points and tickets are nowhere near the same scale — 100 tickets
            // is four times what a kid could ever hold — so the amount comes
            // back inside the new range rather than carrying across.
            $this->jobAmount = (string) min(max(1, (int) $this->jobAmount), $chosen->maxAmount());
        }
    }

    /**
     * The sibling buttons double as the submit: a kid picks who it goes to and
     * the swap is sent, rather than choosing and then confirming.
     */
    public function sendSwap(int $siblingId): void
    {
        $sibling = $this->profile->siblings()->find($siblingId);

        if (! $sibling) {
            return;
        }

        // Same reasoning as postJob(): both sides are public properties, and a
        // bad value must come back as a message rather than a ValueError.
        $giveAsset = TradeAsset::tryFrom($this->giveAsset);
        $getAsset = TradeAsset::tryFrom($this->getAsset);

        if (! $giveAsset || ! $getAsset) {
            $this->errorMessage = 'Pick what you are swapping first.';

            return;
        }

        $this->run(function () use ($sibling, $giveAsset, $getAsset) {
            app(SiblingOfferService::class)->offer(
                $this->profile,
                $sibling,
                $giveAsset,
                (int) $this->giveAmount,
                $getAsset,
                (int) $this->getAmount,
            );

            $this->reset('giveAmount', 'getAmount');
            $this->mode = null;
            $this->flashMessage = "Sent to {$sibling->name}. They have a day to answer.";
            $this->profile->refresh();
        });
    }

    /** @param  int|null  $siblingId  null posts it to the whole household */
    public function postJob(?int $siblingId = null): void
    {
        $target = $siblingId ? $this->profile->siblings()->find($siblingId) : null;

        if ($siblingId && ! $target) {
            return;
        }

        // `mode` is a public property, so it can arrive as anything a crafted
        // request cares to send — and `from()` on a bad value throws a
        // ValueError, which is an Error rather than an Exception and would sail
        // straight past the handler below as a 500.
        $kind = BountyKind::tryFrom((string) $this->mode);
        $asset = TradeAsset::tryFrom($this->jobAsset);

        if (! $kind || ! $asset) {
            $this->errorMessage = 'Pick what kind of job it is first.';

            return;
        }

        $this->run(function () use ($target, $kind, $asset) {
            app(BountyService::class)->post(
                $this->profile,
                $kind,
                $asset,
                (int) $this->jobAmount,
                $this->jobDescription,
                $target,
            );

            $this->reset('jobDescription');
            $this->mode = null;
            $this->flashMessage = $target
                ? "Sent to {$target->name}."
                : 'Posted to the board!';
            $this->profile->refresh();
        });
    }

    public function acceptOffer(int $offerId): void
    {
        $offer = $this->incomingOffer($offerId);

        if (! $offer) {
            return;
        }

        $this->run(function () use ($offer) {
            app(SiblingOfferService::class)->accept($offer, $this->profile);
            $this->profile->refresh();

            $sender = $offer->fromProfile->name;
            $this->flashMessage = "Swapped! You gave {$offer->getText()} for {$offer->giveText()}.";
            $this->dispatch('celebrate', message: "+{$offer->giveText()} from {$sender}!", motion: 'burst', origin: 'tap');
        });
    }

    public function declineOffer(int $offerId): void
    {
        $offer = $this->incomingOffer($offerId);

        if ($offer) {
            app(SiblingOfferService::class)->decline($offer, $this->profile);
            $this->flashMessage = 'Turned it down.';
        }
    }

    public function cancelOffer(int $offerId): void
    {
        $offer = SiblingOffer::where('from_profile_id', $this->profile->id)->live()->find($offerId);

        if ($offer) {
            app(SiblingOfferService::class)->cancel($offer, $this->profile);
            $this->profile->refresh();
            $this->flashMessage = 'Took it back.';
        }
    }

    public function takeJob(int $bountyId): void
    {
        $this->onJob($bountyId, function (Bounty $bounty) {
            app(BountyService::class)->claim($bounty, $this->profile);

            $this->flashMessage = $bounty->kind->posterPays()
                ? "It's yours — go and do it!"
                : 'Hired! They will let you know when it is done.';
        });
    }

    public function markJobDone(int $bountyId): void
    {
        $this->onJob($bountyId, function (Bounty $bounty) {
            app(BountyService::class)->markDone($bounty, $this->profile);
            $this->flashMessage = 'Sent for checking.';
        });
    }

    public function payJob(int $bountyId): void
    {
        $this->onJob($bountyId, function (Bounty $bounty) {
            app(BountyService::class)->confirm($bounty, $this->profile);
            $this->flashMessage = 'Paid up. Nice one!';
        });
    }

    public function sendJobBack(int $bountyId): void
    {
        $this->onJob($bountyId, function (Bounty $bounty) {
            app(BountyService::class)->sendBack($bounty, $this->profile);
            $this->flashMessage = 'Back on the board.';
        });
    }

    public function cancelJob(int $bountyId): void
    {
        $this->onJob($bountyId, function (Bounty $bounty) {
            app(BountyService::class)->cancel($bounty, $this->profile);
            $this->profile->refresh();
            $this->flashMessage = 'Taken back off the board.';
        });
    }

    private function onJob(int $bountyId, callable $action): void
    {
        $bounty = Bounty::where('household_id', $this->profile->household_id)->find($bountyId);

        if (! $bounty) {
            $this->errorMessage = 'That job is no longer there.';

            return;
        }

        $this->run(fn () => $action($bounty));
    }

    private function incomingOffer(int $offerId): ?SiblingOffer
    {
        return SiblingOffer::where('to_profile_id', $this->profile->id)
            ->live()
            ->with('fromProfile')
            ->find($offerId);
    }

    /**
     * Every action refuses the same way: a message on the page rather than an
     * exception, since all of these can lose a race with a sibling.
     */
    private function run(callable $action): void
    {
        $this->clearMessages();

        try {
            $action();
        } catch (InsufficientPointsException|InsufficientTicketsException
            |BountyUnavailableException|OfferUnavailableException|InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    private function clearMessages(): void
    {
        $this->flashMessage = null;
        $this->errorMessage = null;
    }

    public function with(): array
    {
        // No scheduler in this app, so lapsed deals are settled lazily off the
        // page that owns them. Household-wide, so whoever looks first clears
        // everybody's — and the kid with something tied up is the one most
        // motivated to look.
        app(SiblingOfferService::class)->expireStale($this->profile->household);
        app(BountyService::class)->sweep($this->profile->household);
        $this->profile->refresh();

        $jobs = Bounty::where('household_id', $this->profile->household_id)
            ->live()
            ->with(['poster', 'claimant', 'target'])
            ->oldest('expires_at')
            ->get()
            // A job aimed at somebody else is none of this kid's business.
            ->filter(fn (Bounty $job) => $job->isVisibleTo($this->profile));

        return [
            // Validated here rather than read straight off the property: `mode`
            // is public and arrives as whatever was sent, and the template
            // looks a heading up by it. An unknown value was an undefined array
            // key — a 500 on render, before any action had even been called.
            'composeMode' => in_array($this->mode, ['swap', 'wanted', 'offered'], true)
                ? $this->mode
                : null,
            'siblings' => $this->profile->siblings(),
            'incomingOffers' => SiblingOffer::where('to_profile_id', $this->profile->id)
                ->live()
                ->with('fromProfile')
                ->oldest('expires_at')
                ->get(),
            'outgoingOffers' => SiblingOffer::where('from_profile_id', $this->profile->id)
                ->live()
                ->with('toProfile')
                ->oldest('expires_at')
                ->get(),
            'board' => $jobs->filter(fn (Bounty $job) => $job->isTakeable()
                && $job->isOpenTo($this->profile)),
            'onTheGo' => $jobs->filter(fn (Bounty $job) => $job->status !== BountyStatus::Open
                && ($job->isWorker($this->profile) || $job->isPayer($this->profile))),
            'myJobs' => $jobs->filter(fn (Bounty $job) => $job->poster_profile_id === $this->profile->id
                && $job->status === BountyStatus::Open),
            'settledOffers' => SiblingOffer::where(fn ($q) => $q
                ->where('from_profile_id', $this->profile->id)
                ->orWhere('to_profile_id', $this->profile->id))
                ->whereNot('status', SiblingOfferStatus::Pending)
                ->with(['fromProfile', 'toProfile'])
                ->latest('responded_at')
                ->limit(4)
                ->get(),
            'settledJobs' => Bounty::where('household_id', $this->profile->household_id)
                ->whereNotIn('status', [BountyStatus::Open, BountyStatus::Claimed, BountyStatus::Done])
                ->where(fn ($q) => $q
                    ->where('poster_profile_id', $this->profile->id)
                    ->orWhere('claimed_by_profile_id', $this->profile->id))
                ->latest('settled_at')
                ->limit(4)
                ->get(),
            'assets' => TradeAsset::currencies(),
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="trades">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-baloo text-[26px] font-extrabold">Trades &amp; Jobs</h2>
            <p class="text-sm text-fq-text-3">
                Swap points and tickets with a sibling, or put a job up &mdash; for one of them, or for anyone.
            </p>
        </div>
        <div class="flex gap-2">
            <span class="rounded-[10px] border border-fq-line-2 bg-fq-sunk px-3 py-2 font-mono-fq text-xs text-fq-gold">
                {{ $profile->points }} PTS
            </span>
            <span class="rounded-[10px] border border-fq-line-2 bg-fq-sunk px-3 py-2 font-mono-fq text-xs text-fq-lime">
                {{ $profile->bonus_tickets }} TICKETS
            </span>
        </div>
    </div>

    @if ($flashMessage)
        <p class="mt-3 text-sm font-semibold text-fq-lime">{{ $flashMessage }}</p>
    @endif

    @if ($errorMessage)
        <p class="mt-3 text-sm font-semibold text-fq-coral">{{ $errorMessage }}</p>
    @endif

    {{-- Compose. One question first — what is the deal — because the answer
         decides which of the two forms below makes any sense. --}}
    <div class="mt-4 rounded-[24px] border border-fq-line bg-fq-panel p-5">
        @if ($siblings->isEmpty())
            <p class="text-center text-sm text-fq-text-4">Deals need a sibling to make them with.</p>
        @elseif (! $composeMode)
            <h3 class="font-baloo text-[19px] font-extrabold">Start a deal</h3>
            <div class="mt-3 grid gap-2 sm:grid-cols-3">
                <button
                    type="button"
                    wire:click="choose('swap')"
                    class="rounded-[16px] border border-fq-line-2 bg-fq-sunk p-4 text-left transition hover:border-fq-lime"
                >
                    <p class="font-baloo text-[16px] font-bold">Swap</p>
                    <p class="mt-1 text-[13px] text-fq-text-4">Points for tickets with one sibling.</p>
                </button>
                <button
                    type="button"
                    wire:click="choose('wanted')"
                    class="rounded-[16px] border border-fq-line-2 bg-fq-sunk p-4 text-left transition hover:border-fq-lime"
                >
                    <p class="font-baloo text-[16px] font-bold">Pay for a job</p>
                    <p class="mt-1 text-[13px] text-fq-text-4">Get something done for you.</p>
                </button>
                <button
                    type="button"
                    wire:click="choose('offered')"
                    class="rounded-[16px] border border-fq-line-2 bg-fq-sunk p-4 text-left transition hover:border-fq-lime"
                >
                    <p class="font-baloo text-[16px] font-bold">Do a job</p>
                    <p class="mt-1 text-[13px] text-fq-text-4">Offer to work for points.</p>
                </button>
            </div>
        @else
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-baloo text-[19px] font-extrabold">
                    {{ ['swap' => 'Swap', 'wanted' => 'Pay for a job', 'offered' => 'Do a job'][$composeMode] }}
                </h3>
                <button
                    type="button"
                    wire:click="choose(null)"
                    class="rounded-[13px] border border-fq-line-3 bg-fq-sunk px-4 py-[9px] text-[13px] text-fq-text-2-b transition hover:text-fq-text"
                >Back</button>
            </div>

            @if ($composeMode === 'swap')
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ([['You give', 'giveAsset', 'giveAmount', $giveAsset], ['You want', 'getAsset', 'getAmount', $getAsset]] as [$label, $assetProperty, $amountProperty, $current])
                        <div wire:key="swap-{{ $assetProperty }}">
                            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">{{ $label }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @foreach ($assets as $asset)
                                    <button
                                        type="button"
                                        wire:key="{{ $assetProperty }}-{{ $asset->value }}"
                                        wire:click="set{{ ucfirst($assetProperty) }}('{{ $asset->value }}')"
                                        class="rounded-[13px] border px-4 py-[10px] text-[13px] font-semibold transition {{ $current === $asset->value ? 'border-fq-lime text-fq-lime' : 'border-fq-line-2 text-fq-text-3 hover:text-fq-text' }}"
                                    >{{ $asset->label() }}</button>
                                @endforeach
                                <input
                                    type="number"
                                    wire:model="{{ $amountProperty }}"
                                    min="{{ TradeAsset::from($current)->minAmount() }}"
                                    max="{{ TradeAsset::from($current)->maxAmount() }}"
                                    placeholder="0"
                                    class="w-[100px] rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-[10px] text-sm outline-none focus:border-fq-cyan"
                                >
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                    {{ $composeMode === 'wanted' ? 'What do you want done?' : 'What will you do?' }}
                </p>
                <input
                    type="text"
                    wire:model="jobDescription"
                    maxlength="{{ App\Models\Bounty::MAX_DESCRIPTION }}"
                    placeholder="{{ $composeMode === 'wanted' ? 'Make my bed' : 'Wash the car' }}"
                    class="mt-2 w-full rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-[10px] text-sm outline-none focus:border-fq-cyan"
                >

                <p class="mt-4 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                    {{ $composeMode === 'wanted' ? "You'll pay" : 'You want paid' }}
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @foreach ($assets as $asset)
                        <button
                            type="button"
                            wire:key="job-asset-{{ $asset->value }}"
                            wire:click="setJobAsset('{{ $asset->value }}')"
                            class="rounded-[13px] border px-4 py-[10px] text-[13px] font-semibold transition {{ $jobAsset === $asset->value ? 'border-fq-lime text-fq-lime' : 'border-fq-line-2 text-fq-text-3 hover:text-fq-text' }}"
                        >{{ $asset->label() }}</button>
                    @endforeach
                    <input
                        type="number"
                        wire:model="jobAmount"
                        min="{{ TradeAsset::from($jobAsset)->minAmount() }}"
                        max="{{ TradeAsset::from($jobAsset)->maxAmount() }}"
                        class="w-[100px] rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-[10px] text-sm outline-none focus:border-fq-cyan"
                    >
                </div>
            @endif

            {{-- Where it goes, and the last step: tapping one of these is what
                 sends the deal. There is deliberately no separate save button —
                 the destination and the commit are one tap, which is how the
                 trade form has always worked.

                 That only reads as an action if the buttons look like actions,
                 though. They are all styled alike and all lead with a verb: an
                 odd one out in the accent colour reads as a *selected* option,
                 which is exactly the wrong promise — it implies something else
                 still has to be pressed. The line underneath says it outright,
                 because a form with no obvious submit is worth one sentence. --}}
            <p class="mt-5 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                {{ $composeMode === 'swap' ? 'Send it' : 'Post it' }}
            </p>
            <div class="mt-2 flex flex-wrap gap-2">
                @if ($composeMode !== 'swap')
                    <button
                        type="button"
                        wire:click="postJob"
                        class="rounded-[13px] border border-fq-line-3 bg-fq-sunk px-4 py-[10px] text-[13px] font-semibold transition hover:border-fq-lime hover:text-fq-text"
                    >Post for anyone</button>
                @endif

                @foreach ($siblings as $sibling)
                    <button
                        type="button"
                        wire:key="send-{{ $sibling->id }}"
                        wire:click="{{ $composeMode === 'swap' ? "sendSwap({$sibling->id})" : "postJob({$sibling->id})" }}"
                        class="flex items-center gap-2 rounded-[13px] border border-fq-line-3 bg-fq-sunk px-4 py-[10px] text-[13px] font-semibold transition hover:border-fq-lime hover:text-fq-text"
                    >
                        <span class="h-[10px] w-[10px] rounded-full" style="background: {{ $sibling->color->cssVar() }}"></span>
                        Send to {{ $sibling->name }}
                    </button>
                @endforeach
            </div>

            <p class="mt-2 text-[13px] text-fq-text-5">
                {{ $composeMode === 'swap'
                    ? 'Tap a sibling to send it — no other button needed.'
                    : 'Tap where it goes to put it up — no other button needed.' }}
            </p>
        @endif
    </div>

    {{-- Anything somebody else is stuck behind. --}}
    @if ($incomingOffers->isNotEmpty() || $onTheGo->isNotEmpty())
        <div class="mt-4 rounded-[24px] border p-5" style="border-color: var(--fq-magenta); background: var(--fq-panel)">
            <h3 class="font-baloo text-[19px] font-extrabold">Waiting on you</h3>

            <div class="mt-3 flex flex-col gap-3">
                @foreach ($incomingOffers as $offer)
                    @php $shortfall = $offer->shortfallFor($profile); @endphp

                    <div wire:key="incoming-{{ $offer->id }}" class="rounded-[18px] border border-fq-line bg-fq-sunk p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="h-[10px] w-[10px] shrink-0 rounded-full" style="background: {{ $offer->fromProfile->color->cssVar() }}"></span>
                            <span class="font-baloo text-[17px] font-bold">{{ $offer->fromProfile->name }}</span>
                            <span class="rounded-full border border-fq-line-2 px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-4 uppercase">Swap</span>
                            <span class="ml-auto font-mono-fq text-[10px] text-fq-text-5">
                                {{ $offer->expires_at->diffForHumans(['parts' => 1, 'syntax' => Carbon\Carbon::DIFF_ABSOLUTE]) }} left
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-3">
                            <div class="flex items-stretch gap-4 rounded-[14px] border border-fq-line-2 bg-fq-panel px-4 py-[10px]">
                                <div class="shrink-0">
                                    <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-5 uppercase">You get</p>
                                    <p class="mt-[2px] font-baloo text-[21px] leading-none font-extrabold" style="color: {{ $offer->give_asset->cssVar() }}">{{ $offer->giveText() }}</p>
                                </div>
                                <span class="w-px shrink-0 self-stretch bg-fq-line-2"></span>
                                <div class="shrink-0">
                                    <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-5 uppercase">You give</p>
                                    <p class="mt-[2px] font-baloo text-[21px] leading-none font-extrabold" style="color: {{ $offer->get_asset->cssVar() }}">{{ $offer->getText() }}</p>
                                </div>
                            </div>

                            <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto sm:justify-end">
                                @if ($shortfall > 0)
                                    <button type="button" disabled class="cursor-default rounded-[13px] bg-fq-panel-alt px-4 py-[10px] text-[13px] font-semibold text-fq-text-4">
                                        Need {{ $offer->get_asset->format($shortfall) }}
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="acceptOffer({{ $offer->id }})"
                                        class="rounded-[13px] px-4 py-[10px] text-[13px] font-semibold text-fq-bg transition hover:brightness-110"
                                        style="background: var(--fq-lime)"
                                    >Swap!</button>
                                @endif
                                <button
                                    type="button"
                                    wire:click="declineOffer({{ $offer->id }})"
                                    class="rounded-[13px] border border-fq-line-2 px-4 py-[10px] text-[13px] font-semibold text-fq-text-2-b transition hover:text-fq-text"
                                >No thanks</button>
                            </div>
                        </div>
                    </div>
                @endforeach

                @foreach ($onTheGo as $job)
                    @php
                        $isWorker = $job->isWorker($profile);
                        $isPayer = $job->isPayer($profile);
                        $other = $isWorker ? $job->payer() : $job->worker();
                    @endphp

                    <div wire:key="job-{{ $job->id }}" class="rounded-[18px] border border-fq-line bg-fq-sunk p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full border border-fq-line-2 px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-4 uppercase">
                                {{ $isWorker ? 'You do it' : 'You pay' }}
                            </span>
                            <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $job->status->label() }}</span>
                            @if ($other)
                                <span class="ml-auto font-mono-fq text-[10px] text-fq-text-5">with {{ $other->name }}</span>
                            @endif
                        </div>

                        <p class="mt-2 text-[15px] leading-[1.35]">{{ $job->description }}</p>

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <span class="font-baloo text-[21px] leading-none font-extrabold" style="color: {{ $job->reward_asset->cssVar() }}">
                                {{ $job->rewardText() }}
                            </span>

                            <div class="ml-auto flex flex-wrap gap-2">
                                @if ($isWorker && $job->status === BountyStatus::Claimed)
                                    <button
                                        type="button"
                                        wire:click="markJobDone({{ $job->id }})"
                                        class="rounded-[13px] px-4 py-[10px] text-[13px] font-semibold text-fq-bg transition hover:brightness-110"
                                        style="background: var(--fq-lime)"
                                    >I've done it</button>
                                @elseif ($isPayer && $job->status === BountyStatus::Done)
                                    <button
                                        type="button"
                                        wire:click="payJob({{ $job->id }})"
                                        class="rounded-[13px] px-4 py-[10px] text-[13px] font-semibold text-fq-bg transition hover:brightness-110"
                                        style="background: var(--fq-lime)"
                                    >Pay up</button>
                                    <button
                                        type="button"
                                        wire:click="sendJobBack({{ $job->id }})"
                                        class="rounded-[13px] border border-fq-line-2 px-4 py-[10px] text-[13px] font-semibold text-fq-text-2-b transition hover:text-fq-text"
                                    >Not done yet</button>
                                @elseif ($job->status === BountyStatus::Done)
                                    <span class="font-mono-fq text-[10px] text-fq-text-5">Waiting on {{ $job->payer()?->name }}</span>
                                @else
                                    <span class="font-mono-fq text-[10px] text-fq-text-5">Waiting on the work</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- The board. --}}
    <div class="mt-4 rounded-[24px] border border-fq-line bg-fq-panel p-5">
        <h3 class="font-baloo text-[19px] font-extrabold">Up for grabs</h3>

        <div class="mt-3 flex flex-col gap-3">
            @forelse ($board as $job)
                @php $shortfall = $job->kind->posterPays() ? 0 : $job->shortfallFor($profile); @endphp

                <div wire:key="board-{{ $job->id }}" class="rounded-[18px] border border-fq-line bg-fq-sunk p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="h-[10px] w-[10px] shrink-0 rounded-full" style="background: {{ $job->poster->color->cssVar() }}"></span>
                        <span class="font-baloo text-[17px] font-bold">{{ $job->poster->name }}</span>
                        <span class="rounded-full border border-fq-line-2 px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-4 uppercase">
                            {{ $job->kind->headline() }}
                        </span>
                        @if ($job->isTargeted())
                            {{-- Only ever rendered to the kid it is aimed at,
                                 so "just for you" is the whole point of it. --}}
                            <span class="rounded-full px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] uppercase" style="background: var(--fq-tab-active); color: var(--fq-magenta)">
                                Just for you
                            </span>
                        @endif
                        <span class="ml-auto font-mono-fq text-[10px] text-fq-text-5">
                            {{ $job->expires_at->diffForHumans(['parts' => 1, 'syntax' => Carbon\Carbon::DIFF_ABSOLUTE]) }} left
                        </span>
                    </div>

                    <p class="mt-2 text-[15px] leading-[1.35]">{{ $job->description }}</p>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <div class="rounded-[14px] border border-fq-line-2 bg-fq-panel px-4 py-[10px]">
                            <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-5 uppercase">
                                {{ $job->kind->posterPays() ? 'You get' : 'You pay' }}
                            </p>
                            <p class="mt-[2px] font-baloo text-[21px] leading-none font-extrabold" style="color: {{ $job->reward_asset->cssVar() }}">
                                {{ $job->rewardText() }}
                            </p>
                        </div>

                        <div class="ml-auto">
                            @if ($shortfall > 0)
                                <button type="button" disabled class="cursor-default rounded-[13px] bg-fq-panel-alt px-4 py-[10px] text-[13px] font-semibold text-fq-text-4">
                                    Need {{ $job->reward_asset->format($shortfall) }}
                                </button>
                            @else
                                <button
                                    type="button"
                                    wire:click="takeJob({{ $job->id }})"
                                    class="rounded-[13px] px-4 py-[10px] text-[13px] font-semibold text-fq-bg transition hover:brightness-110"
                                    style="background: var(--fq-lime)"
                                >{{ $job->kind->takeLabel() }}</button>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="rounded-[18px] border border-dashed border-fq-line-4 p-5 text-center text-sm text-fq-text-4">
                    Nothing up for grabs. Post a job and see who takes it.
                </p>
            @endforelse
        </div>
    </div>

    @if ($outgoingOffers->isNotEmpty() || $myJobs->isNotEmpty())
        <div class="mt-4 rounded-[24px] border border-fq-line bg-fq-panel p-5">
            <h3 class="font-baloo text-[19px] font-extrabold">Yours, still waiting</h3>

            <div class="mt-3 flex flex-col gap-3">
                @foreach ($outgoingOffers as $offer)
                    <div wire:key="outgoing-{{ $offer->id }}" class="flex flex-wrap items-center gap-3 rounded-[18px] border border-fq-line bg-fq-sunk p-4">
                        <div class="min-w-[160px] flex-1">
                            <p class="text-[15px]">{{ $offer->summary() }}</p>
                            <p class="mt-1 font-mono-fq text-[10px] text-fq-text-5">
                                Swap &middot; to {{ $offer->toProfile->name }} &middot;
                                {{ $offer->expires_at->diffForHumans(['parts' => 1, 'syntax' => Carbon\Carbon::DIFF_ABSOLUTE]) }} left
                            </p>
                        </div>
                        <button
                            type="button"
                            wire:click="cancelOffer({{ $offer->id }})"
                            class="rounded-[13px] border border-fq-line-2 px-4 py-[10px] text-[13px] font-semibold text-fq-text-2-b transition hover:text-fq-text"
                        >Take it back</button>
                    </div>
                @endforeach

                @foreach ($myJobs as $job)
                    <div wire:key="mine-{{ $job->id }}" class="flex flex-wrap items-center gap-3 rounded-[18px] border border-fq-line bg-fq-sunk p-4">
                        <div class="min-w-[160px] flex-1">
                            <p class="text-[15px]">{{ $job->description }}</p>
                            <p class="mt-1 font-mono-fq text-[10px] text-fq-text-5">
                                {{ $job->kind->label() }} &middot;
                                {{ $job->isTargeted() ? 'for '.$job->target->name : 'anyone' }} &middot;
                                {{ $job->expires_at->diffForHumans(['parts' => 1, 'syntax' => Carbon\Carbon::DIFF_ABSOLUTE]) }} left
                            </p>
                        </div>
                        <span class="font-baloo text-[19px] font-extrabold" style="color: {{ $job->reward_asset->cssVar() }}">
                            {{ $job->rewardText() }}
                        </span>
                        <button
                            type="button"
                            wire:click="cancelJob({{ $job->id }})"
                            class="rounded-[13px] border border-fq-line-2 px-4 py-[10px] text-[13px] font-semibold text-fq-text-2-b transition hover:text-fq-text"
                        >Take it back</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($settledOffers->isNotEmpty() || $settledJobs->isNotEmpty())
        <div class="mt-4 rounded-[24px] border border-fq-line bg-fq-panel p-5">
            <h3 class="font-baloo text-[17px] font-extrabold">Finished with</h3>

            <div class="mt-3 flex flex-col gap-2">
                @foreach ($settledOffers as $offer)
                    <div wire:key="settled-offer-{{ $offer->id }}" class="flex flex-wrap items-center gap-3 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-[10px]">
                        <span class="min-w-0 flex-1 truncate text-[13px] text-fq-text-2">{{ $offer->summary() }}</span>
                        <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $offer->status->label() }}</span>
                    </div>
                @endforeach

                @foreach ($settledJobs as $job)
                    <div wire:key="settled-job-{{ $job->id }}" class="flex flex-wrap items-center gap-3 rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-[10px]">
                        <span class="min-w-0 flex-1 truncate text-[13px] text-fq-text-2">{{ $job->description }}</span>
                        <span class="font-mono-fq text-[10px] text-fq-text-5">{{ $job->status->label() }}</span>
                        <span class="font-mono-fq text-[11px]" style="color: {{ $job->reward_asset->cssVar() }}">{{ $job->rewardText() }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-kid.shell>
