<?php

use App\Enums\CosmeticSlot;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\Profile;
use App\Services\CosmeticService;
use App\Services\KnackService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * The menu over a kid's own pet, wherever it is running about.
 *
 * A tap on the pet still pets it; this pops up over its head for a few
 * seconds as well (`fq-pet-menu`, from resources/js/pets.js) with the things
 * a kid would otherwise have to leave the page for: its own page, a Power
 * Treat for its knack — bought where the knack is about to be used, not three
 * taps away — and tucking it in, for when it is in the way.
 *
 * Tucking in is the pet layer's alone (`fq-pet-tuck`): kept in the browser
 * for the day, nothing saved here. A component of its own, in the shell,
 * because the pet is on every page and the treat is a server action.
 */
new class extends Component
{
    /** On the pets page already — no link to where they are standing. */
    #[Locked]
    public bool $onPetsPage = false;

    /** Said in the menu, where the tap was. */
    public ?string $note = null;

    public function mount(bool $onPetsPage = false): void
    {
        $this->onPetsPage = $onPetsPage;
    }

    private function profile(): Profile
    {
        $profile = Auth::guard('profile')->user();

        abort_unless($profile instanceof Profile && $profile->isKid(), 403);

        return $profile;
    }

    /** A Power Treat, fed on the spot. See KnackService::buyTreat(). */
    public function buyTreat(): void
    {
        try {
            app(KnackService::class)->buyTreat($this->profile());
        } catch (InsufficientTicketsException|PerkUnavailableException $e) {
            $this->note = $e instanceof InsufficientTicketsException ? 'Not enough tickets for a Power Treat yet.' : $e->getMessage();

            return;
        }

        $this->note = null;
        // The pet eats it in front of them — see pets.js — and the page they
        // are on draws again, so an offer the treat just paid for turns up.
        $this->dispatch('fq-pet-treat');
        $this->dispatch('pet-treat-bought');
    }

    /**
     * @return array{pet: ?string, knack: ?string, price: ?int, tickets: int}
     */
    public function with(): array
    {
        $kid = $this->profile();
        $knacks = app(KnackService::class);
        $state = $knacks->stateFor($kid);
        $sells = $state !== null && $state['knack']->takesTreat();

        return [
            'pet' => app(CosmeticService::class)->wornIn($kid, CosmeticSlot::Pet)?->name,
            'knack' => $sells ? $state['knack']->label() : null,
            'price' => $sells ? $knacks->treatPrice($kid, $state['knack']) : null,
            'tickets' => (int) $kid->bonus_tickets,
        ];
    }
}; ?>

{{-- State that is the pet layer's to know — where it is, whether it is tucked
     in, whether it is bedtime — arrives with each tap, so nothing here is
     baked in at first paint. `shown`, not `open`: no Alpine key may share a
     name with a Livewire action inside it. --}}
<div
    x-data="{
        shown: false,
        tucked: false,
        bedtime: false,
        left: 0,
        top: 0,
        since: 0,
        timer: null,
        pop(detail) {
            this.tucked = Boolean(detail.tucked);
            this.bedtime = Boolean(detail.bedtime);
            this.left = Math.min(Math.max(detail.x, 104), window.innerWidth - 104);
            this.top = Math.max(detail.y - 6, 190);
            this.since = Date.now();
            this.shown = true;
            this.linger(7000);
        },
        linger(ms) {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.shown = false, ms);
        },
        {{-- The tap that opened it also lands as a click on the page. --}}
        away() {
            if (Date.now() - this.since > 400) {
                this.shown = false;
            }
        },
    }"
    x-on:fq-pet-menu.window="pop($event.detail)"
    x-on:pet-treat-bought.window="shown = false; $wire.$parent.$refresh()"
    x-on:keydown.escape.window="shown = false"
    data-pet-menu
>
    <div
        x-show="shown"
        x-cloak
        x-on:click.outside="away()"
        x-on:pointerdown="linger(12000)"
        class="fixed z-[45] flex w-[200px] -translate-x-1/2 -translate-y-full flex-col gap-[6px] rounded-[16px] border border-fq-nav-line bg-fq-panel p-[8px] shadow-[0_10px_30px_rgba(0,0,0,.45)]"
        x-bind:style="`left: ${left}px; top: ${top}px`"
        style="animation: fq-pop .2s ease both"
        role="menu"
        aria-label="{{ $pet ?? 'Your pet' }}"
    >
        <span class="px-[4px] font-mono-fq text-[8.5px] tracking-[0.12em] text-fq-text-4 uppercase">{{ $pet ?? 'Your pet' }}</span>

        @unless ($onPetsPage)
            <a
                href="{{ route('kid.pets') }}"
                wire:navigate
                role="menuitem"
                class="flex items-center gap-[8px] rounded-[10px] border border-fq-line-3 px-[10px] py-[8px] font-baloo text-[13.5px] font-extrabold text-fq-text-2"
                data-pet-menu-page
            ><i class="fa-solid fa-paw w-[14px] text-fq-green"></i>Pets page</a>
        @endunless

        @if ($price !== null)
            <button
                type="button"
                role="menuitem"
                wire:click="buyTreat"
                wire:confirm="Give {{ $pet }} a Power Treat for {{ $price }} {{ Str::plural('ticket', $price) }}? You'd have {{ $tickets - $price }} left."
                @disabled($tickets < $price)
                class="flex items-center gap-[8px] rounded-[10px] px-[10px] py-[8px] text-left font-baloo text-[13.5px] font-extrabold disabled:opacity-40"
                style="background: linear-gradient(150deg,#fff6b0,#ffc93d); color: #1a1200"
                data-pet-menu-treat
            ><i class="fa-solid fa-cookie-bite w-[14px]"></i>Power Treat · {{ $price }} ✦</button>
            <span class="px-[4px] text-[10px] text-pretty text-fq-text-4">
                @if ($tickets < $price)
                    {{ $price - $tickets }} more {{ Str::plural('ticket', $price - $tickets) }} and {{ $pet }} can have one.
                @else
                    For {{ $knack }}.
                @endif
            </span>
        @endif

        @if ($note)
            <span class="px-[4px] text-[11px] text-fq-danger" data-pet-menu-note>{{ $note }}</span>
        @endif

        {{-- Not at bedtime: it is asleep because of the hour, and stays so. --}}
        <button
            type="button"
            role="menuitem"
            x-show="! bedtime"
            x-on:click="tucked = ! tucked; $dispatch('fq-pet-tuck', { tucked }); shown = false"
            class="flex items-center gap-[8px] rounded-[10px] border border-fq-line-3 px-[10px] py-[8px] text-left font-baloo text-[13.5px] font-extrabold text-fq-text-2"
            data-pet-menu-tuck
        >
            <i class="fa-solid w-[14px] text-fq-cyan" x-bind:class="tucked ? 'fa-sun' : 'fa-moon'"></i>
            <span x-text="tucked ? 'Wake up' : 'Tuck in'">Tuck in</span>
        </button>
    </div>
</div>
