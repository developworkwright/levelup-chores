<?php

use App\Enums\SiblingOfferKind;
use App\Enums\SiblingOfferStatus;
use App\Exceptions\InsufficientPointsException;
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

    /** Transient — the deal form is open for this visit only. */
    public bool $composingOffer = false;

    public string $offerKind = 'paying';

    public string $offerDescription = '';

    public string $offerPoints = '';

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

    public function setOfferKind(string $kind): void
    {
        $this->offerKind = SiblingOfferKind::tryFrom($kind)?->value ?? SiblingOfferKind::Paying->value;
    }

    /**
     * The sibling buttons double as the submit: a kid picks who it goes to and
     * the deal is sent, rather than choosing and then confirming.
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
                SiblingOfferKind::from($this->offerKind),
                $this->offerDescription,
                (int) $this->offerPoints,
            );
        } catch (InsufficientPointsException|InvalidArgumentException $e) {
            $this->flashMessage = $e->getMessage();

            return;
        }

        $this->reset('offerDescription', 'offerPoints');
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
        } catch (InsufficientPointsException|OfferUnavailableException $e) {
            $this->flashMessage = $e->getMessage();

            return;
        }

        $this->profile->refresh();

        $gained = $offer->kind === SiblingOfferKind::Paying;
        $this->flashMessage = $gained
            ? "Deal! Now go {$offer->description}."
            : "Deal! {$offer->fromProfile->name} owes you: {$offer->description}.";

        if ($gained) {
            $this->dispatch('celebrate', message: "+{$offer->points} from {$offer->fromProfile->name}!");
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

    public function with(): array
    {
        // No scheduler in this app, so lapsed deals are settled lazily off the
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
            // A short tail of settled deals so an answer doesn't just make the
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
            <h2 class="font-baloo text-[26px] font-extrabold">Deals</h2>
            <p class="text-sm text-fq-text-3">Trade points with a sibling for a one-off favour. Deals run out after a day.</p>
        </div>
        <span class="rounded-[10px] border border-fq-line-2 bg-fq-sunk px-3 py-2 font-mono-fq text-xs text-fq-lime">
            BALANCE {{ $profile->points }} PTS
        </span>
    </div>

    @if ($flashMessage)
        <p class="mt-3 text-sm font-semibold text-fq-lime">{{ $flashMessage }}</p>
    @endif

    @if ($siblings->isEmpty())
        <p class="mt-6 rounded-[20px] border border-dashed border-fq-line-4 bg-fq-panel p-6 text-center text-sm text-fq-text-4">
            Deals need a sibling to make them with.
        </p>
    @else
        {{-- Deals a sibling has sent this kid, first: this is the tab's whole
             reason to exist, and the one thing someone else is waiting on. --}}
        @if ($incomingOffers->isNotEmpty())
            <div class="mt-4 rounded-[24px] border p-5" style="border-color: var(--fq-magenta); background: var(--fq-panel)">
                <div class="flex items-baseline gap-[10px]">
                    <h3 class="font-baloo text-[19px] font-extrabold">Deals for you</h3>
                    <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                        {{ $incomingOffers->count() }} waiting
                    </span>
                </div>

                <div class="mt-3 flex flex-col gap-3">
                    @foreach ($incomingOffers as $offer)
                        @php
                            // On a "they pay me" offer this kid is the one paying,
                            // so Accept has to be priced against their balance.
                            $viewerPays = ! $offer->isEscrowed();
                            $shortfall = $viewerPays ? $offer->points - $profile->points : 0;
                        @endphp

                        <div wire:key="incoming-{{ $offer->id }}" class="rounded-[18px] border border-fq-line bg-fq-sunk p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="h-[10px] w-[10px] shrink-0 rounded-full" style="background: {{ $offer->fromProfile->color->cssVar() }}"></span>
                                <span class="font-baloo text-[17px] font-bold">{{ $offer->fromProfile->name }}</span>
                                <span class="rounded-full border border-fq-line-2 px-[11px] py-[5px] font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-4 uppercase">
                                    {{ $offer->kind->incomingLabel() }}
                                </span>
                                {{-- A countdown, not a description of a moment:
                                     "23 hours from now" is what diffForHumans says
                                     about a future timestamp, but what a kid needs
                                     to read here is how long they have left. --}}
                                <span class="ml-auto font-mono-fq text-[10px] text-fq-text-5">
                                    {{ $offer->expires_at->diffForHumans(['parts' => 1, 'syntax' => Carbon\Carbon::DIFF_ABSOLUTE]) }} left
                                </span>
                            </div>

                            <p class="mt-2 text-[15px] leading-[1.35]">{{ $offer->description }}</p>

                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <span class="font-baloo text-[21px] font-extrabold text-fq-gold">{{ $offer->points }} pts</span>

                                @if ($shortfall > 0)
                                    <button type="button" disabled class="ml-auto cursor-default rounded-[13px] bg-fq-panel-alt px-4 py-[10px] text-[13px] font-semibold text-fq-text-4">
                                        Need {{ $shortfall }}
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="acceptOffer({{ $offer->id }})"
                                        class="ml-auto rounded-[13px] px-4 py-[10px] text-[13px] font-semibold text-fq-bg transition hover:brightness-110"
                                        style="background: var(--fq-lime)"
                                    >Deal!</button>
                                @endif

                                <button
                                    type="button"
                                    wire:click="declineOffer({{ $offer->id }})"
                                    class="rounded-[13px] border border-fq-line-2 px-4 py-[10px] text-[13px] font-semibold text-fq-text-2-b transition hover:text-fq-text"
                                >No thanks</button>
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
                    <h3 class="font-baloo text-[19px] font-extrabold">Make a deal with a sibling</h3>
                    <p class="mt-1 text-[13px] text-fq-text-4">Pay them to do something, or get paid to do it yourself.</p>
                </div>

                <button
                    type="button"
                    wire:click="toggleCompose"
                    class="rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[9px] text-[13px] text-fq-text-2-b transition hover:text-fq-text"
                >{{ $composingOffer ? 'Never mind' : 'New deal' }}</button>
            </div>

            @if ($composingOffer)
                <div class="mt-4 border-t border-fq-line pt-[14px]">
                    <div class="flex flex-wrap gap-2">
                        @foreach (App\Enums\SiblingOfferKind::cases() as $kind)
                            @php $picked = $offerKind === $kind->value; @endphp
                            <button
                                type="button"
                                wire:click="setOfferKind('{{ $kind->value }}')"
                                class="rounded-[13px] border bg-fq-sunk px-4 py-[10px] text-[13px] font-semibold transition"
                                style="border-color: {{ $picked ? 'var(--fq-lime)' : 'var(--fq-line-2)' }}; color: {{ $picked ? 'var(--fq-lime)' : 'var(--fq-text-4)' }}"
                            >{{ $kind->label() }}</button>
                        @endforeach
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <input
                            type="text"
                            wire:model="offerDescription"
                            maxlength="{{ App\Services\SiblingOfferService::MAX_DESCRIPTION }}"
                            placeholder="{{ $offerKind === 'paying' ? 'Play a game with me for 30 minutes' : 'I will take out the bins for you' }}"
                            class="min-w-[220px] flex-1 rounded-[13px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[11px] text-[14px] text-fq-text placeholder:text-fq-text-5"
                        />
                        <input
                            type="number"
                            wire:model="offerPoints"
                            min="{{ App\Services\SiblingOfferService::MIN_POINTS }}"
                            max="{{ App\Services\SiblingOfferService::MAX_POINTS }}"
                            placeholder="100"
                            class="w-[110px] rounded-[13px] border border-fq-line-2 bg-fq-sunk px-[14px] py-[11px] text-center font-baloo text-[16px] font-bold text-fq-gold placeholder:font-normal placeholder:text-fq-text-5"
                        />
                    </div>

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
                                <span class="text-[13px]">{{ $offer->toProfile->name }} — {{ $offer->description }}</span>
                                <span class="font-mono-fq text-[11px] text-fq-gold">{{ $offer->points }} pts</span>
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
                    <p class="mb-[10px] font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Recent deals</p>

                    <div class="flex flex-col gap-[6px]">
                        @foreach ($settledOffers as $offer)
                            <div wire:key="settled-{{ $offer->id }}" class="flex flex-wrap items-center gap-2 text-[13px] text-fq-text-4">
                                <span>{{ $offer->fromProfile->name }} → {{ $offer->toProfile->name }}: {{ $offer->description }}</span>
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
