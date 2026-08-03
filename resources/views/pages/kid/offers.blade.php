<?php

use App\Enums\SiblingOfferStatus;
use App\Enums\TradeAsset;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\OfferUnavailableException;
use App\Models\Profile;
use App\Models\SiblingOffer;
use App\Services\SiblingOfferService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    public ?string $flashMessage = null;

    /** Transient — the trade form is open for this visit only. */
    public bool $composingOffer = false;

    public string $giveAsset = 'points';

    public string $giveAmount = '';

    public string $getAsset = 'favour';

    public string $getAmount = '';

    public string $offerDescription = '';

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    public function toggleCompose(): void
    {
        $this->composingOffer = ! $this->composingOffer;
        $this->flashMessage = null;
    }

    /**
     * Picking an asset on one side pushes the other side off it if they now
     * match: trading points for points is never what a kid meant, and letting
     * the form reach a state the service will only reject is worse than moving
     * the picker they aren't looking at.
     */
    public function setGiveAsset(string $asset): void
    {
        $this->giveAsset = TradeAsset::tryFrom($asset)?->value ?? TradeAsset::Points->value;

        if ($this->getAsset === $this->giveAsset) {
            $this->getAsset = $this->giveAsset === TradeAsset::Favour->value
                ? TradeAsset::Points->value
                : TradeAsset::Favour->value;
        }
    }

    public function setGetAsset(string $asset): void
    {
        $this->getAsset = TradeAsset::tryFrom($asset)?->value ?? TradeAsset::Favour->value;

        if ($this->giveAsset === $this->getAsset) {
            $this->giveAsset = $this->getAsset === TradeAsset::Favour->value
                ? TradeAsset::Points->value
                : TradeAsset::Favour->value;
        }
    }

    /**
     * The sibling buttons double as the submit: a kid picks who it goes to and
     * the trade is sent, rather than choosing and then confirming.
     */
    public function sendOffer(int $siblingId): void
    {
        $sibling = $this->profile->siblings()->find($siblingId);

        if (! $sibling) {
            return;
        }

        try {
            app(SiblingOfferService::class)->offer(
                $this->profile,
                $sibling,
                TradeAsset::from($this->giveAsset),
                (int) $this->giveAmount,
                TradeAsset::from($this->getAsset),
                (int) $this->getAmount,
                $this->offerDescription,
            );
        } catch (InsufficientPointsException|InsufficientTicketsException|InvalidArgumentException $e) {
            $this->flashMessage = $e->getMessage();

            return;
        }

        $this->reset('offerDescription', 'giveAmount', 'getAmount');
        $this->composingOffer = false;
        $this->flashMessage = "Sent to {$sibling->name}. They have a day to answer.";
        $this->profile->refresh();
    }

    public function acceptOffer(int $offerId): void
    {
        $offer = $this->incomingOffer($offerId);

        if (! $offer) {
            return;
        }

        try {
            app(SiblingOfferService::class)->accept($offer, $this->profile);
        } catch (InsufficientPointsException|InsufficientTicketsException|OfferUnavailableException $e) {
            $this->flashMessage = $e->getMessage();

            return;
        }

        $this->profile->refresh();

        $sender = $offer->fromProfile->name;

        // Written from the accepter's side, and only one of the three shapes
        // leaves them owing anything.
        $this->flashMessage = match (true) {
            $offer->get_asset === TradeAsset::Favour => "Traded! You got {$offer->giveText()}. Now go {$offer->description}.",
            $offer->give_asset === TradeAsset::Favour => "Traded! {$sender} owes you: {$offer->description}.",
            default => "Traded! You swapped {$offer->getText()} for {$offer->giveText()}.",
        };

        if ($offer->isEscrowed()) {
            $this->dispatch('celebrate', message: "+{$offer->giveText()} from {$sender}!");
        }
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

    private function incomingOffer(int $offerId): ?SiblingOffer
    {
        return SiblingOffer::where('to_profile_id', $this->profile->id)
            ->live()
            ->with('fromProfile')
            ->find($offerId);
    }

    /** True while either side of the compose form is a favour to be typed. */
    public function needsDescription(): bool
    {
        return $this->giveAsset === TradeAsset::Favour->value
            || $this->getAsset === TradeAsset::Favour->value;
    }

    public function with(): array
    {
        // No scheduler in this app, so lapsed trades are settled lazily off the
        // page that owns them. Household-wide, so whoever opens this tab first
        // releases everybody's held points.
        app(SiblingOfferService::class)->expireStale($this->profile->household);
        $this->profile->refresh();

        return [
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
            // A short tail of settled trades so an answer doesn't just make the
            // offer vanish with no explanation.
            'settledOffers' => SiblingOffer::where(fn ($q) => $q
                ->where('from_profile_id', $this->profile->id)
                ->orWhere('to_profile_id', $this->profile->id))
                ->whereNot('status', SiblingOfferStatus::Pending)
                ->with(['fromProfile', 'toProfile'])
                ->latest('responded_at')
                ->limit(6)
                ->get(),
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="offers">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-baloo text-[26px] font-extrabold">Trades</h2>
            <p class="text-sm text-fq-text-3">Swap points, tickets or a one-off favour with a sibling. Trades run out after a day.</p>
        </div>
        {{-- Both currencies, because either one can now be on either side of a
             trade and a kid needs to price an offer against both. --}}
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

    @if ($siblings->isEmpty())
        <p class="mt-6 rounded-[20px] border border-dashed border-fq-line-4 bg-fq-panel p-6 text-center text-sm text-fq-text-4">
            Trades need a sibling to make them with.
        </p>
    @else
        {{-- Trades a sibling has sent this kid, first: this is the tab's whole
             reason to exist, and the one thing someone else is waiting on. --}}
        @if ($incomingOffers->isNotEmpty())
            <div class="mt-4 rounded-[24px] border p-5" style="border-color: var(--fq-magenta); background: var(--fq-panel)">
                <div class="flex items-baseline gap-[10px]">
                    <h3 class="font-baloo text-[19px] font-extrabold">Trades for you</h3>
                    <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                        {{ $incomingOffers->count() }} waiting
                    </span>
                </div>

                <div class="mt-3 flex flex-col gap-3">
                    @foreach ($incomingOffers as $offer)
                        @php
                            // Whatever the sender asked for is what this kid
                            // hands over, so Accept is priced against it.
                            $shortfall = $offer->shortfallFor($profile);
                        @endphp

                        <div wire:key="incoming-{{ $offer->id }}" class="rounded-[18px] border border-fq-line bg-fq-sunk p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="h-[10px] w-[10px] shrink-0 rounded-full" style="background: {{ $offer->fromProfile->color->cssVar() }}"></span>
                                <span class="font-baloo text-[17px] font-bold">{{ $offer->fromProfile->name }}</span>
                                <span class="rounded-full border border-fq-line-2 px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-4 uppercase">
                                    {{ $offer->isSwap() ? 'Swap' : 'Favour' }}
                                </span>
                                {{-- A countdown, not a description of a moment:
                                     "23 hours from now" is what diffForHumans says
                                     about a future timestamp, but what a kid needs
                                     to read here is how long they have left. --}}
                                <span class="ml-auto font-mono-fq text-[10px] text-fq-text-5">
                                    {{ $offer->expires_at->diffForHumans(['parts' => 1, 'syntax' => Carbon\Carbon::DIFF_ABSOLUTE]) }} left
                                </span>
                            </div>

                            @if ($offer->description)
                                <p class="mt-2 text-[15px] leading-[1.35]">{{ $offer->description }}</p>
                            @endif

                            {{-- Both halves side by side: with two currencies in
                                 play, "100 pts" alone no longer says who owes
                                 what to whom. Boxed and ruled down the middle
                                 because points gold and ticket lime are close
                                 enough that colour alone can't separate them —
                                 and `shrink-0` so a long amount can't collapse
                                 onto its neighbour on a narrow screen. --}}
                            <div class="mt-3 flex flex-wrap items-center justify-center gap-x-4 gap-y-3 sm:justify-start">
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

                                {{-- On a phone the buttons take a row of their
                                     own under a centred price, rather than
                                     being shoved to the right edge by `ml-auto`
                                     with the box stranded on the left. --}}
                                <div class="flex w-full flex-wrap items-center justify-center gap-2 pt-1 sm:ml-auto sm:w-auto sm:justify-end sm:pt-0">
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
                                        >Trade!</button>
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
                </div>
            </div>
        @endif

        {{-- The favour is different every time — "play a game with me for 30 min",
             "swap dish night" — so it is typed, not picked from a catalogue. --}}
        <div class="mt-4 rounded-[24px] border border-dashed border-fq-line-4 bg-fq-panel p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="font-baloo text-[19px] font-extrabold">Make a trade with a sibling</h3>
                    <p class="mt-1 text-[13px] text-fq-text-4">Put up points, tickets or a favour — and say what you want back.</p>
                </div>

                <button
                    type="button"
                    wire:click="toggleCompose"
                    class="rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[9px] text-[13px] text-fq-text-2-b transition hover:text-fq-text"
                >{{ $composingOffer ? 'Never mind' : 'New trade' }}</button>
            </div>

            @if ($composingOffer)
                <div class="mt-4 border-t border-fq-line pt-[14px]">
                    {{-- One row per side, same controls on both, so "points for
                         tickets" is built the same way as "points for a favour". --}}
                    @foreach ([['You give', 'giveAsset', $giveAsset, 'giveAmount'], ['You want back', 'getAsset', $getAsset, 'getAmount']] as [$heading, $assetProperty, $pickedAsset, $amountProperty])
                        <p class="mb-[10px] font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">{{ $heading }}</p>
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            @foreach (App\Enums\TradeAsset::cases() as $asset)
                                @php $picked = $pickedAsset === $asset->value; @endphp
                                <button
                                    type="button"
                                    wire:click="set{{ ucfirst($assetProperty) }}('{{ $asset->value }}')"
                                    class="rounded-[13px] border bg-fq-sunk px-4 py-[10px] text-[13px] font-semibold transition"
                                    style="border-color: {{ $picked ? $asset->cssVar() : 'var(--fq-line-2)' }}; color: {{ $picked ? $asset->cssVar() : 'var(--fq-text-4)' }}"
                                >{{ $asset->label() }}</button>
                            @endforeach

                            @if ($pickedAsset !== App\Enums\TradeAsset::Favour->value)
                                @php $range = App\Enums\TradeAsset::from($pickedAsset); @endphp
                                <input
                                    type="number"
                                    wire:model="{{ $amountProperty }}"
                                    min="{{ $range->minAmount() }}"
                                    max="{{ $range->maxAmount() }}"
                                    placeholder="{{ $range->maxAmount() >= 100 ? 100 : 2 }}"
                                    aria-label="How many {{ strtolower($range->label()) }}"
                                    class="w-[110px] rounded-[13px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[11px] text-center font-baloo text-[16px] font-bold placeholder:font-normal placeholder:text-fq-text-5"
                                    style="color: {{ $range->cssVar() }}"
                                />
                            @endif
                        </div>
                    @endforeach

                    @if ($this->needsDescription())
                        <input
                            type="text"
                            wire:model="offerDescription"
                            maxlength="{{ App\Services\SiblingOfferService::MAX_DESCRIPTION }}"
                            placeholder="{{ $giveAsset === 'favour' ? 'I will take out the bins for you' : 'Play a game with me for 30 minutes' }}"
                            class="mb-1 w-full rounded-[13px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[11px] text-[14px] text-fq-text placeholder:text-fq-text-5"
                        />
                    @endif

                    <p class="mt-3 mb-[10px] font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                        Send it to
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($siblings as $sibling)
                            <button
                                type="button"
                                wire:click="sendOffer({{ $sibling->id }})"
                                class="flex items-center gap-2 rounded-[13px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[9px] text-[13px] text-fq-chip-text transition hover:brightness-120"
                            >
                                <span class="h-2 w-2 rounded-full" style="background: {{ $sibling->color->cssVar() }}"></span>
                                {{ $sibling->name }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($outgoingOffers->isNotEmpty())
                <div class="mt-4 border-t border-fq-line pt-[14px]">
                    <p class="mb-[10px] font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Waiting on an answer</p>

                    <div class="flex flex-col gap-2">
                        @foreach ($outgoingOffers as $offer)
                            <div wire:key="outgoing-{{ $offer->id }}" class="flex flex-wrap items-center gap-2 rounded-[14px] border border-fq-line bg-fq-sunk px-[13px] py-[10px]">
                                <span class="h-2 w-2 shrink-0 rounded-full" style="background: {{ $offer->toProfile->color->cssVar() }}"></span>
                                <span class="text-[13px]">{{ $offer->toProfile->name }} — {{ $offer->summary() }}</span>
                                <button
                                    type="button"
                                    wire:click="cancelOffer({{ $offer->id }})"
                                    class="ml-auto rounded-[10px] border border-fq-line-2 px-[11px] py-[6px] font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-4 uppercase transition hover:text-fq-text"
                                >Take it back</button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($settledOffers->isNotEmpty())
                <div class="mt-4 border-t border-fq-line pt-[14px]">
                    <p class="mb-[10px] font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Recent trades</p>

                    <div class="flex flex-col gap-[6px]">
                        @foreach ($settledOffers as $offer)
                            <div wire:key="settled-{{ $offer->id }}" class="flex flex-wrap items-center gap-2 text-[13px] text-fq-text-4">
                                <span>{{ $offer->fromProfile->name }} → {{ $offer->toProfile->name }}: {{ $offer->summary() }}</span>
                                <span class="font-mono-fq text-[10px] tracking-[0.1em] uppercase" style="color: {{ $offer->status === App\Enums\SiblingOfferStatus::Accepted ? 'var(--fq-lime)' : 'var(--fq-text-5)' }}">
                                    {{ $offer->status->label() }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</x-kid.shell>
