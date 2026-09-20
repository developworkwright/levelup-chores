<?php

use App\Enums\ArcadeGame;
use App\Enums\CosmeticSlot;
use App\Enums\PetStage;
use App\Enums\PetStyle;
use App\Exceptions\CosmeticUnavailableException;
use App\Exceptions\InsufficientTicketsException;
use App\Exceptions\PerkUnavailableException;
use App\Models\Cosmetic;
use App\Models\PetEgg;
use App\Models\Profile;
use App\Services\CosmeticService;
use App\Services\KnackService;
use App\Services\PetService;
use App\Services\PrizeCounterService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The pet's own page. Pets outgrew the Locker once they grew up, got knacks
 * and started helping in the arcade: a pet is looked after, not worn.
 *
 * Top to bottom: the pet that's out (or the egg in its place) with its age,
 * its knack (a "perk" to the kid) and a Power Treat; what its style does in each game; the other
 * pets the kid owns, to swap between; surprise eggs; and pets for sale.
 *
 * Underneath it is still a cosmetic — CosmeticSlot::Pet, bought with tickets,
 * owned forever, grown on the kid's own copy — so buying and wearing go
 * through CosmeticService exactly as the Locker's do. See PetService and
 * KnackService for the rest.
 *
 * Laid out from the "its room" design: the pet on its own floor, gear drawn
 * in, the shop beside it from lg up so the pet never scrolls away.
 */
new class extends Component
{
    public Profile $profile;

    public ?string $flashMessage = null;

    /** A pet for sale being looked at before buying, by id. */
    public ?int $lookingAt = null;

    /** The age the pet being looked at is out on this screen at, to try it out. */
    public ?string $trialAge = null;

    /** Said on the egg card, where the tap was — see the Locker for why. */
    public ?string $eggNote = null;

    /** Said on the knack card, where the Power Treat button is. */
    public ?string $treatNote = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    private function pet(int $id): ?Cosmetic
    {
        $pet = Cosmetic::where('household_id', $this->profile->household_id)->find($id);

        return $pet?->slot === CosmeticSlot::Pet ? $pet : null;
    }

    /** Puts one of their own pets out. Swapping is free, and nothing resets. */
    public function wear(int $id): void
    {
        $pet = $this->pet($id);
        $service = app(CosmeticService::class);

        if (! $pet || ! $service->owns($this->profile, $pet)) {
            return;
        }

        try {
            $service->wear($this->profile, $pet);
            $this->flashMessage = "{$pet->name} is out.";
        } catch (CosmeticUnavailableException $e) {
            $this->flashMessage = $e->getMessage();
        }
    }

    /** A pet for sale: open it up to see it before buying. Nothing spent. */
    public function look(int $id): void
    {
        $pet = $this->pet($id);

        $this->lookingAt = $pet && app(CosmeticService::class)->isForSale($pet) ? $pet->id : null;
        $this->trialAge = null;
        $this->flashMessage = null;
    }

    public function stopLooking(): void
    {
        $this->lookingAt = null;
        $this->trialAge = null;
    }

    /**
     * Puts the pet being looked at out on this screen, loose — running about,
     * pettable, feedable — at any age, before a ticket is spent. Nothing is
     * saved. The same try-out a grown-up gets on an upload.
     */
    public function tryOut(string $age = 'baby'): void
    {
        if ($this->lookingAt === null || ! PetStage::tryFrom($age)) {
            return;
        }

        $this->trialAge = $age;
    }

    public function stopTrying(): void
    {
        $this->trialAge = null;
    }

    /** The second, deliberate tap: buy the pet being looked at, and put it out. */
    public function buy(): void
    {
        $pet = $this->lookingAt ? $this->pet($this->lookingAt) : null;

        if (! $pet) {
            return;
        }

        try {
            app(CosmeticService::class)->buy($this->profile, $pet);
        } catch (InsufficientTicketsException|CosmeticUnavailableException $e) {
            $this->flashMessage = $e->getMessage();

            return;
        }

        $this->profile->refresh();
        $this->lookingAt = null;
        $this->trialAge = null;
        $this->flashMessage = "{$pet->name} is yours — it starts as a baby.";
        $this->dispatch('celebrate', message: "{$pet->name} is yours!", style: 'ticket', motion: 'burst', origin: 'tap');
    }

    /** Back to a baby, because the kid asked. See PetService::raiseAgain(). */
    public function raiseAgain(int $id): void
    {
        $pet = $this->pet($id);

        if (! $pet || ! app(CosmeticService::class)->owns($this->profile, $pet)) {
            return;
        }

        app(PetService::class)->raiseAgain($this->profile, $pet);
        $this->flashMessage = "{$pet->name} is a baby again.";
    }

    /** A surprise egg, out on their pages straight away. See PetService::buyEgg(). */
    public function buyEgg(int $id): void
    {
        $pet = $this->pet($id);

        if (! $pet) {
            return;
        }

        try {
            app(PetService::class)->buyEgg($this->profile, $pet);
        } catch (InsufficientTicketsException|CosmeticUnavailableException $e) {
            $this->eggNote = $e->getMessage();

            return;
        }

        $this->profile->refresh();
        $this->eggNote = null;
        $this->dispatch('celebrate', message: 'A surprise egg! Do chores to crack it.', style: 'ticket', motion: 'burst', origin: 'tap');
    }

    /**
     * A Power Treat: fed to the pet on the spot, for one more use of its
     * knack — or double strength today, for an always-on one. See
     * KnackService::buyTreat().
     */
    public function buyTreat(): void
    {
        try {
            app(KnackService::class)->buyTreat($this->profile);
        } catch (InsufficientTicketsException|PerkUnavailableException $e) {
            $this->treatNote = $e instanceof InsufficientTicketsException ? 'Not enough tickets for a Power Treat yet.' : $e->getMessage();

            return;
        }

        $this->profile->refresh();
        $this->treatNote = null;
        // The pet eats it in front of them — see pets.js.
        $this->dispatch('fq-pet-treat');
    }

    /**
     * What a style does in each game that has styles, by game name.
     *
     * @return array<string, string>
     */
    private function styleGames(?PetStyle $style): array
    {
        if ($style === null) {
            return [];
        }

        return collect(ArcadeGame::ranked())
            ->mapWithKeys(fn (ArcadeGame $game) => [$game->label() => $game->styleHelp($style)])
            ->filter()
            ->all();
    }

    /**
     * The pet being tried out, drawn the way it would be as the kid's own at
     * that age — see PetService::spriteFor().
     *
     * @return array{age: PetStage, src: ?string, rig: ?array, scale: float, effect: ?string}|null
     */
    private function trial(?Cosmetic $pet): ?array
    {
        $age = $this->trialAge ? PetStage::tryFrom($this->trialAge) : null;

        if ($pet === null || $age === null) {
            return null;
        }

        return [
            'age' => $age,
            'src' => $pet->artUrl($age),
            'rig' => $pet->rig($age),
            'scale' => $pet->drawScale($age),
            'effect' => $pet->effect?->cssClass(),
        ];
    }

    public function with(): array
    {
        $cosmetics = app(CosmeticService::class);
        $pets = app(PetService::class);
        $knacks = app(KnackService::class);
        $household = $this->profile->household;

        $out = $cosmetics->wornIn($this->profile, CosmeticSlot::Pet);
        $egg = $pets->eggFor($this->profile);
        $shelf = $cosmetics->shelfFor($this->profile, CosmeticSlot::Pet);
        $owned = $shelf->filter(fn (Cosmetic $pet) => $cosmetics->owns($this->profile, $pet))->values();
        $knack = $egg === null ? $knacks->stateFor($this->profile) : null;
        $looking = $this->lookingAt ? $shelf->firstWhere('id', $this->lookingAt) : null;

        return [
            'egg' => $egg,
            'out' => $egg === null ? $out : null,
            'growth' => $out ? $pets->growthOf($this->profile, $out) : 0,
            'stage' => $out ? $pets->stageOf($this->profile, $out) : null,
            'toGo' => $out ? $pets->choresToGrow($this->profile, $out) : null,
            'knack' => $knack,
            'treatPrice' => $knack ? $knacks->treatPrice($this->profile, $knack['knack']) : null,
            // What its style does, game by game — only the games given styles.
            'styleGames' => $this->styleGames($out?->pet_style),
            // All four, so a kid can see what the word on a pet is worth
            // before they own that pet — see the styles block.
            'allStyles' => collect(PetStyle::cases())
                ->map(fn (PetStyle $style) => ['style' => $style, 'games' => $this->styleGames($style)])
                ->filter(fn (array $row) => $row['games'] !== [])
                ->values()
                ->all(),
            'gear' => app(PrizeCounterService::class)->gearFor($this->profile),
            'owned' => $owned,
            'stages' => $pets->stagesFor($this->profile),
            'forSale' => $shelf->reject(fn (Cosmetic $pet) => $cosmetics->owns($this->profile, $pet))->values(),
            'looking' => $looking,
            'lookingStyleGames' => $this->styleGames($looking?->pet_style),
            'trial' => $this->trial($looking),
            'eggsForSale' => $pets->eggsForSale($household),
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="pets">
    <div class="flex flex-col gap-[12px] lg:gap-[16px]">
        <div class="flex items-start justify-between gap-[10px] lg:items-end">
            <div>
                <h2 class="font-baloo text-[26px] leading-none font-extrabold lg:text-[28px]">Pets</h2>
                <p class="mt-[4px] text-[12.5px] text-fq-text-4 lg:text-[13px]">Raise them with chores. Grown-up pets help out more.</p>
            </div>
            <span class="flex items-center gap-[5px] rounded-full border px-[10px] py-[6px] font-baloo text-[14px] font-extrabold whitespace-nowrap lg:px-[12px] lg:py-[7px] lg:text-[15px]" style="border-color: #3a2360; color: #ffe14d" data-pets-tickets>{{ $profile->bonus_tickets }} ✦</span>
        </div>

        @if ($flashMessage)
            <div class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2" data-pets-flash>{{ $flashMessage }}</div>
        @endif

        <div class="grid grid-cols-1 items-start gap-[12px] lg:grid-cols-[1fr_320px] lg:gap-[18px]">
            {{-- Left: the pet, and what it does. --}}
            <div class="flex min-w-0 flex-col gap-[12px]">
                {{-- The egg in the pet's place: cracked by chores, a surprise inside. --}}
                @if ($egg)
                    @php $eggHue = $egg->hue(); @endphp
                    <div wire:key="egg-{{ $egg->id }}" class="relative h-[214px] overflow-hidden rounded-[20px] border lg:h-[260px] lg:rounded-[22px]" style="border-color: hsl({{ $eggHue }} 100% 72% / .55); background: linear-gradient(180deg,#160c2a 0%,#0e0719 58%,#1d1036 58%,#150c26 100%)" data-egg-out>
                        <span class="absolute top-[11px] left-[12px] flex max-w-[calc(100%-24px)] flex-col gap-[4px] lg:top-[14px] lg:left-[16px]">
                            <span class="font-mono-fq text-[8.5px] tracking-[0.16em] uppercase lg:text-[9px]" style="color: hsl({{ $eggHue }} 100% 72%)">{{ PetEgg::colourName($eggHue) }} egg · {{ $egg->cracks }} of {{ PetEgg::CRACKS_TO_HATCH }} cracks</span>
                            <span class="font-baloo text-[22px] leading-none font-extrabold lg:text-[28px]">
                                {{ $egg->choresToHatch() === 0 ? 'Ready to hatch!' : $egg->choresToHatch().' more '.Str::plural('chore', $egg->choresToHatch()).' and it hatches' }}
                            </span>
                            <span class="text-[11.5px] text-fq-text-4">Something in there is a pet that's never been in the shop. It's out on your pages — tap it.</span>
                        </span>
                        @if ($gear['bed'])
                            <fq-prize kind="bed" key="{{ $gear['bed'] }}" class="absolute bottom-[22px] left-[14px] block h-[96px] w-[96px] lg:bottom-[26px] lg:left-[22px] lg:h-[124px] lg:w-[124px]"></fq-prize>
                        @endif
                        <img x-data :src="window.fqEggSvg?.({{ $egg->cracks }}, {{ $eggHue }}, '{{ PetEgg::patternFor($egg->pet) }}')" alt="" class="absolute bottom-[24px] left-1/2 h-[76px] w-[76px] -translate-x-1/2" style="animation: fqbob 1.8s ease-in-out infinite">
                    </div>
                @endif

                {{-- The pet that's out, in its room. --}}
                @if ($out)
                    @php
                        $tier = $out->rarity();
                        $style = $out->pet_style;
                        $next = $stage->next();
                        $span = $next ? $next->startsAt() - $stage->startsAt() : 1;
                        $along = $next ? ($growth - $stage->startsAt()) / $span : 1;
                    @endphp

                    <div
                        wire:key="pet-out-{{ $out->id }}"
                        x-data="{
                            fed: null,
                            timer: null,
                            munch(kind) {
                                this.fed = null;
                                clearTimeout(this.timer);
                                requestAnimationFrame(() => {
                                    this.fed = kind;
                                    this.timer = setTimeout(() => this.fed = null, 1400);
                                });
                            },
                        }"
                        x-on:fq-pet-feed.window="munch('snack')"
                        x-on:fq-pet-treat.window="munch('treat')"
                        class="relative h-[214px] overflow-hidden rounded-[20px] border lg:h-[260px] lg:rounded-[22px]"
                        style="border-color: {{ $tier->color() }}; background: linear-gradient(180deg,#160c2a 0%,#0e0719 58%,#1d1036 58%,#150c26 100%); --pet-px: {{ $stage->pixels() }}px"
                        data-pet-out
                    >
                        <span class="absolute top-[11px] left-[12px] z-10 flex w-max flex-col gap-[4px] lg:top-[14px] lg:left-[16px] lg:gap-[5px]">
                            <span class="flex items-center gap-[5px] font-mono-fq text-[8.5px] tracking-[0.16em] whitespace-nowrap uppercase lg:gap-[6px] lg:text-[9px]">
                                <span class="text-fq-text-4">{{ $stage->label() }}</span>
                                <span class="rounded-full border px-[6px] py-[1px] lg:px-[7px]" style="border-color: {{ $tier->color() }}; color: {{ $tier->color() }}" data-pet-tier>{{ $tier->label() }}</span>
                                @if ($style)
                                    <span class="rounded-full border px-[6px] py-[1px] lg:px-[7px]" style="border-color: #3a2360; color: #c8bade" data-pet-style><i class="fa-solid {{ $style->icon() }} mr-[3px]"></i>{{ $style->label() }}</span>
                                @endif
                            </span>
                            <span class="font-baloo text-[24px] leading-none font-extrabold lg:text-[30px]">{{ $out->name }}</span>
                        </span>

                        {{-- Its gear from the arcade's prize counter, drawn into the room. --}}
                        <span class="contents" data-pet-gear>
                            @if ($gear['bed'])
                                <fq-prize kind="bed" key="{{ $gear['bed'] }}" class="absolute bottom-[22px] left-[14px] block h-[96px] w-[96px] lg:bottom-[26px] lg:left-[22px] lg:h-[124px] lg:w-[124px]"></fq-prize>
                            @endif
                            @if ($gear['toy'])
                                <fq-prize kind="toy" key="{{ $gear['toy'] }}" class="absolute right-[110px] bottom-[26px] block h-[34px] w-[34px] lg:right-[150px] lg:bottom-[30px] lg:h-[44px] lg:w-[44px]" style="animation: fqbob 1.8s ease-in-out infinite"></fq-prize>
                            @endif
                        </span>

                        <span class="absolute bottom-[24px] left-1/2 block h-[calc(var(--pet-px)*1.15)] w-[calc(var(--pet-px)*1.15)] -translate-x-[40%] lg:bottom-[28px] lg:h-[calc(var(--pet-px)*1.6)] lg:w-[calc(var(--pet-px)*1.6)]">
                            <span class="absolute inset-0 block origin-bottom" x-bind:style="fed && 'animation: fq-hop .6s ease-out'">
                                <x-cosmetic.art :item="$out" :stage="$stage" mode="fill" class="absolute inset-0" />
                            </span>
                        </span>

                        {{-- The snack (or treat) dropped beside it, and eaten. --}}
                        <span x-show="fed" x-cloak class="absolute bottom-[30px] left-1/2 block h-[26px] w-[26px] translate-x-[30px] lg:bottom-[34px] lg:h-[32px] lg:w-[32px] lg:translate-x-[44px]">
                            <span class="block h-full w-full" style="animation: fq-munch 1.4s ease-in forwards">
                                <i x-show="fed === 'treat'" class="fa-solid fa-cookie-bite grid h-full w-full place-items-center text-[20px] text-fq-gold lg:text-[24px]"></i>
                                <span x-show="fed === 'snack'" class="block h-full w-full">
                                    @if ($gear['snack'])
                                        <fq-prize kind="snack" key="{{ $gear['snack'] }}" class="block h-full w-full"></fq-prize>
                                    @else
                                        <i class="fa-solid fa-drumstick-bite grid h-full w-full place-items-center text-[18px] text-fq-green lg:text-[22px]"></i>
                                    @endif
                                </span>
                            </span>
                        </span>

                        <button
                            type="button"
                            x-on:click="window.dispatchEvent(new CustomEvent('fq-pet-feed'))"
                            class="absolute right-[12px] bottom-[20px] flex items-center gap-[6px] rounded-[11px] px-[14px] py-[9px] font-baloo text-[14px] font-extrabold lg:right-[16px] lg:bottom-[24px] lg:gap-[7px] lg:rounded-[12px] lg:px-[18px] lg:py-[10px] lg:text-[15px]"
                            style="background: linear-gradient(150deg,#b8ffd9,#54e8d0); color: #05170c"
                        ><i class="fa-solid fa-drumstick-bite text-[12px] lg:text-[13px]"></i>Feed</button>

                        {{-- Its age, as the room's bottom edge. --}}
                        <span class="absolute inset-x-0 bottom-0 block h-[8px] lg:h-[9px]" style="background: #1b0f30">
                            <span class="block h-full transition-[width] duration-400" style="width: {{ round($along * 100) }}%; background: linear-gradient(90deg,#54e8d0,#7dffb0)"></span>
                        </span>
                    </div>

                    <div class="-mt-[4px] flex items-center justify-between gap-[10px] text-[11.5px] text-fq-text-4 lg:text-[12px]">
                        <span>
                            @if ($toGo === null)
                                All grown up.
                            @else
                                {{ $toGo }} more {{ Str::plural('chore', $toGo) }} and it grows up{{ $next === PetStage::Adult ? ' all the way' : '' }}.
                            @endif
                        </span>
                        @if ($growth > 0)
                            <button
                                type="button"
                                wire:click="raiseAgain({{ $out->id }})"
                                wire:confirm="Make {{ $out->name }} a baby again? It will grow back up with your chores."
                                class="shrink-0 font-mono-fq text-[8.5px] tracking-[0.08em] whitespace-nowrap uppercase lg:text-[9px]"
                                style="color: #8c7bab"
                            >Raise again</button>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 gap-[10px] min-[380px]:grid-cols-2">
                        {{-- Its perk, the uses left as paw prints, and a Power Treat. --}}
                        @if ($knack)
                            @php
                                [$strengthLabel, $strengthInk] = match (true) {
                                    $knack['doubled'] => ['Doubled today', '#7dffb0'],
                                    ! $knack['unlocked'] => ['Still learning', '#8c7bab'],
                                    $knack['strength'] === 'full' => ['Full strength', '#7dffb0'],
                                    default => ['Half strength', '#ffe14d'],
                                };
                            @endphp
                            <div class="flex flex-col gap-[6px] rounded-[16px] border p-[12px]" style="border-color: {{ $tier->color() }}55; background: #0b0616" data-pet-knack="{{ $knack['knack']->value }}">
                                <span class="flex items-center gap-[7px]">
                                    <i class="fa-solid {{ $knack['knack']->icon() }} text-[13px]" style="color: {{ $tier->color() }}"></i>
                                    <span class="font-baloo text-[16px] leading-tight font-extrabold">{{ $knack['knack']->label() }}</span>
                                </span>
                                <span class="font-mono-fq text-[8px] tracking-[0.1em] uppercase" style="color: {{ $strengthInk }}" @if ($knack['doubled']) data-knack-doubled @endif>{{ $strengthLabel }}</span>
                                <x-perk-by-age :knack="$knack['knack']" :stage="$knack['unlocked'] ? $knack['stage'] : null" :ink="$tier->color()" class="flex-1" />

                                @if ($knack['uses'] !== null)
                                    <span class="flex flex-wrap items-center gap-[4px]" data-knack-left="{{ $knack['left'] }}">
                                        @foreach (range(1, max($knack['uses'], $knack['left'])) as $paw)
                                            <i class="fa-solid fa-paw text-[12px]" style="color: {{ $paw <= $knack['left'] ? ($paw > $knack['left'] - $knack['treats'] ? '#7dffb0' : $tier->color()) : '#3a2360' }}"></i>
                                        @endforeach
                                        <span class="ml-[3px] text-[10.5px] text-fq-text-4">
                                            {{ $knack['left'] }} left
                                            @if ($knack['treats'] > 0) ({{ $knack['treats'] }} from treats) @endif
                                            @if ($knack['backAt'] && $knack['left'] - $knack['treats'] < $knack['uses'])
                                                · next free one {{ $knack['backAt']->isToday() ? 'later today' : $knack['backAt']->format('l') }}
                                            @endif
                                            · {{ $knack['automatic'] ? 'it goes off by itself' : 'it offers when it can help' }}
                                        </span>
                                    </span>
                                @elseif ($knack['unlocked'])
                                    <span class="text-[10.5px] text-fq-text-4">Always on.</span>
                                @endif

                                @if ($knack['choresToUnlock'] !== null)
                                    <span class="text-[10.5px] text-fq-text-4">Learns it in {{ $knack['choresToUnlock'] }} more {{ Str::plural('chore', $knack['choresToUnlock']) }}.</span>
                                @elseif ($knack['choresToFull'] !== null)
                                    <span class="text-[10.5px] text-fq-text-4">Full strength in {{ $knack['choresToFull'] }} more {{ Str::plural('chore', $knack['choresToFull']) }}.</span>
                                @endif

                                {{-- Power Treat: one more use, or double today. --}}
                                @if ($knack['unlocked'] && ! $knack['knack']->takesTreat())
                                    <span class="mt-[2px] text-[10px] text-pretty" style="color: #8c7bab" data-no-treat>No Power Treat for this one — it pays in tickets already.</span>
                                @elseif ($knack['unlocked'])
                                    <button
                                        type="button"
                                        wire:click="buyTreat"
                                        wire:confirm="Give {{ $out->name }} a Power Treat for {{ $treatPrice }} {{ Str::plural('ticket', $treatPrice) }}? You'd have {{ $profile->bonus_tickets - $treatPrice }} left."
                                        @disabled($profile->bonus_tickets < $treatPrice)
                                        class="mt-[2px] flex items-center justify-center gap-[6px] rounded-[10px] px-[10px] py-[8px] font-baloo text-[13px] font-extrabold disabled:opacity-40"
                                        style="background: linear-gradient(150deg,#fff6b0,#ffc93d); color: #1a1200"
                                        data-power-treat
                                    ><i class="fa-solid fa-cookie-bite"></i>Power Treat · {{ $treatPrice }} ✦</button>
                                    <span class="text-[10px] text-pretty" style="color: #8c7bab">
                                        {{ $knack['knack']->alwaysOn() ? 'Doubles '.$knack['knack']->label().' for the rest of today.' : 'One more '.$knack['knack']->label().', on top of the free ones. It keeps until you need it.' }}
                                    </span>
                                    @if ($treatNote)
                                        <span class="text-[11px] text-fq-danger" data-treat-note>{{ $treatNote }}</span>
                                    @endif
                                @endif
                            </div>
                        @else
                            <div class="flex flex-col gap-[6px] rounded-[16px] border p-[12px]" style="border-color: #241539; background: #0b0616" data-pet-no-perk>
                                <span class="flex items-center gap-[7px]">
                                    <i class="fa-solid fa-paw text-[13px]" style="color: #3a2360"></i>
                                    <span class="font-baloo text-[16px] leading-tight font-extrabold">No perk</span>
                                </span>
                                <span class="font-mono-fq text-[8px] tracking-[0.1em] uppercase" style="color: #8c7bab">{{ $tier->label() }} · style only</span>
                                <span class="text-[11.5px] text-pretty" style="color: #c8bade">A Rare, Epic or Legendary pet has a perk too. {{ $out->name }} still helps in the arcade.</span>
                            </div>
                        @endif

                    @if ($allStyles)
                        <x-pet-styles-card :styles="$allStyles" :mine="$style" :pet-name="$out->name" />
                    @endif
                    </div>
                @elseif (! $egg)
                    <div class="grid h-[214px] place-items-center rounded-[20px] border border-dashed border-fq-line-2 px-4 text-center text-[13px] text-fq-text-4 lg:h-[260px]" data-no-pet>
                        No pet out yet — {{ $owned->isNotEmpty() ? 'pick one of yours' : 'pick one from the pets for sale, or crack a surprise egg' }}.
                    </div>
                @endif

                {{-- With a pet out this sits beside its perk; with none — or an
                     egg — it stands alone, which is exactly when a kid is
                     reading style names in the shop. --}}
                @if ($allStyles && ! $out)
                    <x-pet-styles-card :styles="$allStyles" />
                @endif
            </div>

            {{-- Right: everything to swap to or buy. --}}
            <div class="flex min-w-0 flex-col gap-[12px] lg:gap-[14px]">
                {{-- Their other pets, to swap between. Swapping is free; nothing resets. --}}
                @if ($owned->count() > ($out ? 1 : 0))
                    <div class="flex flex-col gap-[7px] lg:gap-[8px]">
                        <span class="flex items-baseline justify-between gap-[8px]">
                            <span class="font-baloo text-[16px] font-extrabold">Your pets</span>
                            <span class="text-[10.5px]" style="color: #8c7bab">Swapping is free. Nothing resets.</span>
                        </span>
                        <div class="flex gap-[8px] overflow-x-auto pb-[2px] lg:grid lg:grid-cols-3 lg:overflow-visible lg:pb-0">
                            @foreach ($owned as $pet)
                                @php $isOut = $out?->id === $pet->id; $petStage = $stages[$pet->id] ?? PetStage::Baby; @endphp
                                <button
                                    type="button"
                                    wire:key="owned-{{ $pet->id }}"
                                    @unless ($isOut) wire:click="wear({{ $pet->id }})" @endunless
                                    class="flex w-[88px] flex-none flex-col items-center gap-[4px] rounded-[14px] border p-[7px] lg:w-auto lg:px-[6px] lg:py-[8px]"
                                    style="border-color: {{ $isOut ? '#7dffb0' : $pet->rarity()->color().'66' }}; background: {{ $isOut ? '#0d2a1c' : '#0b0616' }}"
                                    data-owned-pet="{{ $pet->id }}"
                                >
                                    <span class="relative block h-[60px] w-[60px] overflow-hidden rounded-[10px] lg:aspect-square lg:h-auto lg:w-full" style="background: #07030f">
                                        <x-cosmetic.art :item="$pet" :stage="$petStage" class="absolute inset-[8%]" />
                                    </span>
                                    <span class="text-center text-[11px] leading-tight font-semibold">{{ $pet->name }}</span>
                                    <span class="font-mono-fq text-[7.5px] tracking-[0.1em] uppercase" style="color: {{ $isOut ? '#7dffb0' : '#8c7bab' }}">{{ $isOut ? 'Out now' : 'Put out' }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Surprise eggs: one per egg-only pet nobody has yet. --}}
                @if ($eggsForSale->isNotEmpty())
                    @php $cheapest = $eggsForSale->map(fn ($pet) => PetEgg::priceFor($pet))->min(); @endphp
                    <div class="flex flex-col gap-[9px] rounded-[18px] border p-[12px]" style="border-color: #3a2360; background: #120a22" data-eggs-for-sale>
                        <span class="flex flex-wrap items-baseline justify-between gap-[8px] lg:flex-col lg:gap-[2px]">
                            <span class="font-baloo text-[16px] font-extrabold">Surprise eggs</span>
                            <span class="text-[10.5px]" style="color: #8c7bab">
                                {{ $egg ? 'Hatch yours first — then pick another.' : 'The pattern shows how rare it is. '.PetEgg::CRACKS_TO_HATCH.' chores crack it.' }}
                            </span>
                        </span>

                        <div class="grid grid-cols-4 gap-[8px]">
                            @foreach ($eggsForSale as $inside)
                                @php
                                    $hue = PetEgg::hueFor($inside->id);
                                    $eggTier = $inside->rarity();
                                    $price = PetEgg::priceFor($inside);
                                    $affordable = $price <= $profile->bonus_tickets;
                                    $colour = mb_strtolower(PetEgg::colourName($hue));
                                    $inTheShell = $eggTier === App\Enums\PetRarity::Common
                                        ? "It'll have a style for the arcade — which one is a surprise."
                                        : "It'll have a style and ".($eggTier === App\Enums\PetRarity::Epic ? 'an' : 'a')." {$eggTier->label()} perk — which one is a surprise.";
                                @endphp
                                <button
                                    type="button"
                                    wire:key="egg-for-sale-{{ $inside->id }}"
                                    @if (! $egg && $affordable)
                                        wire:click="buyEgg({{ $inside->id }})"
                                        wire:confirm="Spend {{ $price }} tickets on the {{ mb_strtolower($eggTier->label()) }} {{ $colour }} egg? {{ $inTheShell }}"
                                    @endif
                                    @disabled($egg || ! $affordable)
                                    class="flex flex-col items-center gap-[3px] rounded-[12px] border px-[4px] py-[8px] disabled:opacity-45 lg:px-[4px]"
                                    style="border-color: {{ $eggTier === App\Enums\PetRarity::Common ? 'hsl('.$hue.' 100% 72% / .35)' : $eggTier->color() }}; background: linear-gradient(170deg, hsl({{ $hue }} 45% 13%), #0b0616)"
                                    data-egg-tier="{{ $eggTier->value }}"
                                    data-egg-colour="{{ $colour }}"
                                >
                                    <img x-data :src="window.fqEggSvg?.(0, {{ $hue }}, '{{ $eggTier->eggPattern() }}')" alt="" class="h-[52px] w-[46px] object-contain lg:h-[46px] lg:w-[40px]">
                                    <span class="font-mono-fq text-[7.5px] tracking-[0.1em] uppercase lg:text-[7px]" style="color: {{ $eggTier->color() }}">{{ $eggTier->label() }}</span>
                                    <span class="font-baloo text-[12px] font-extrabold text-fq-lime">{{ $price }} ✦</span>
                                </button>
                            @endforeach
                        </div>

                        @if (! $egg && $cheapest > $profile->bonus_tickets)
                            @php $short = $cheapest - $profile->bonus_tickets; @endphp
                            <span class="text-[10.5px] text-fq-text-4" data-egg-short>{{ $short }} more {{ Str::plural('ticket', $short) }} and you can pick one.</span>
                        @endif

                        @if ($eggNote)
                            <span class="text-[11px] text-fq-danger" data-egg-note>{{ $eggNote }}</span>
                        @endif

                        <span class="border-t pt-[8px] text-[10.5px] text-pretty text-fq-text-4" style="border-color: #241539">
                            Every egg hatches a pet with a <strong class="text-fq-text">style</strong> for the arcade. A Rare, Epic or Legendary shell also means a <strong class="text-fq-text">perk</strong> of that level — which one is the surprise.
                        </span>
                    </div>
                @endif

                {{-- Pets for sale: a tap opens it up, a second tap buys. --}}
                @if ($forSale->isNotEmpty())
                    <div class="flex flex-col gap-[9px]">
                        <span class="order-1 flex items-baseline justify-between gap-[8px]">
                            <span class="font-baloo text-[16px] font-extrabold">Pets for sale</span>
                            <span class="text-[10.5px]" style="color: #8c7bab">Tap to look. Nothing spent.</span>
                        </span>

                        {{-- Above the grid on a phone, below it from lg up. --}}
                        @if ($looking)
                            @php
                                $lookTier = $looking->rarity();
                                $short = $looking->cost - $profile->bonus_tickets;
                            @endphp
                            <div class="order-2 flex flex-col gap-[8px] rounded-[14px] border-2 p-[11px] lg:order-4" style="border-color: {{ $lookTier->color() }}; background: #0b0616" data-looking-at="{{ $looking->id }}">
                                <div class="flex items-start gap-[10px]">
                                    <span class="flex flex-1 flex-col gap-[3px]">
                                        <span class="font-baloo text-[18px] leading-none font-extrabold">{{ $looking->name }}</span>
                                        <span class="flex flex-wrap gap-[5px] font-mono-fq text-[8px] tracking-[0.14em] uppercase">
                                            <span class="rounded-full border px-[6px] py-[1px]" style="border-color: {{ $lookTier->color() }}; color: {{ $lookTier->color() }}">{{ $lookTier->label() }}</span>
                                            @if ($looking->pet_style)
                                                <span style="color: #c8bade"><i class="fa-solid {{ $looking->pet_style->icon() }} mr-[3px]"></i>{{ $looking->pet_style->label() }}</span>
                                            @endif
                                        </span>
                                    </span>
                                    <button type="button" wire:click="stopLooking" aria-label="Close" class="px-[4px] py-[2px]" style="color: #8c7bab"><i class="fa-solid fa-xmark"></i></button>
                                </div>

                                {{-- Try it out: loose on this screen at any age, nothing spent. --}}
                                <div class="flex flex-col gap-[5px]" data-try-out>
                                    <span class="font-mono-fq text-[8px] tracking-[0.12em] uppercase" style="color: #8c7bab">Try it out · nothing spent</span>
                                    <div class="flex flex-wrap gap-[5px]" role="radiogroup" aria-label="Age">
                                        @foreach (PetStage::cases() as $age)
                                            @php $on = $trial && $trial['age'] === $age; @endphp
                                            <button
                                                type="button"
                                                role="radio"
                                                aria-checked="{{ $on ? 'true' : 'false' }}"
                                                wire:click="{{ $on ? 'stopTrying' : "tryOut('{$age->value}')" }}"
                                                class="flex items-center gap-[5px] rounded-full border px-[11px] py-[6px] font-baloo text-[13px] font-extrabold"
                                                style="border-color: {{ $on ? '#54e8d0' : '#3a2360' }}; color: {{ $on ? '#54e8d0' : '#c8bade' }}; background: {{ $on ? '#0d2a24' : 'transparent' }}"
                                            ><i class="fa-solid {{ $on ? 'fa-eye' : 'fa-paw' }} text-[10px]"></i>{{ $age->label() }}</button>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- What its style does, game by game. --}}
                                @if ($looking->pet_style && $lookingStyleGames)
                                    <div class="flex flex-col gap-[6px] border-t pt-[8px]" style="border-color: #1b1030" data-looking-style-games>
                                        <span class="flex items-center gap-[7px]">
                                            <i class="fa-solid fa-gamepad text-[12px]" style="color: #c9a0ff"></i>
                                            <span class="font-baloo text-[14px] leading-tight font-extrabold">{{ $looking->pet_style->label() }} in the arcade</span>
                                        </span>
                                        <span class="text-[11px]" style="color: #8c7bab">{{ $looking->pet_style->blurb() }}</span>
                                        @foreach ($lookingStyleGames as $game => $help)
                                            <span class="flex flex-col gap-[1px]">
                                                <span class="font-mono-fq text-[8px] tracking-[0.12em] uppercase" style="color: #8c7bab">{{ $game }}</span>
                                                <span class="text-[11.5px]" style="color: #ded0f5">{{ $looking->name }} {{ $help }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($looking->knack())
                                    <div class="flex flex-col gap-[6px] border-t pt-[8px]" style="border-color: #1b1030">
                                        <span class="font-baloo text-[14px] leading-tight font-extrabold"><i class="fa-solid {{ $looking->knack()->icon() }} mr-[5px] text-[12px]" style="color: {{ $lookTier->color() }}"></i>{{ $looking->knack()->label() }}</span>
                                        <x-perk-by-age :knack="$looking->knack()" />
                                    </div>
                                @endif

                                <button
                                    type="button"
                                    @if ($short <= 0) wire:click="buy" wire:confirm="Buy {{ $looking->name }} for {{ $looking->cost }} tickets? You'd have {{ $profile->bonus_tickets - $looking->cost }} left." @endif
                                    @disabled($short > 0)
                                    class="self-start rounded-[11px] px-[16px] py-[9px] font-baloo text-[14px] font-extrabold disabled:opacity-40"
                                    style="background: linear-gradient(150deg,#fff6b0,#ffc93d); color: #1a1200"
                                >{{ $short > 0 ? $short.' more '.Str::plural('ticket', $short) : 'Buy · '.$looking->cost.' ✦' }}</button>
                            </div>
                        @endif

                        <div class="order-3 grid grid-cols-3 gap-[8px]">
                            @foreach ($forSale as $pet)
                                @php $saleTier = $pet->rarity(); @endphp
                                <button
                                    type="button"
                                    wire:key="for-sale-{{ $pet->id }}"
                                    wire:click="look({{ $pet->id }})"
                                    class="flex flex-col items-center gap-[4px] rounded-[14px] border px-[7px] py-[8px]"
                                    style="border-color: {{ $looking?->id === $pet->id ? $saleTier->color() : $saleTier->color().'55' }}; background: #0b0616"
                                    data-for-sale="{{ $pet->id }}"
                                >
                                    <span class="relative block aspect-square w-full rounded-[10px]" style="background: #07030f">
                                        <x-cosmetic.art :item="$pet" class="absolute inset-[8%]" />
                                        <span class="absolute top-[4px] right-[4px] rounded-full border px-[5px] py-[1px] font-mono-fq text-[6.5px] tracking-[0.08em] uppercase" style="background: rgba(10,5,18,.85); border-color: {{ $saleTier->color() }}; color: {{ $saleTier->color() }}">{{ $saleTier->label() }}</span>
                                    </span>
                                    <span class="text-center text-[11px] leading-tight font-semibold">{{ $pet->name }}</span>
                                    @if ($pet->pet_style || $pet->knack())
                                        <span class="text-center text-[9.5px] leading-[1.25] text-pretty" style="color: #c8bade" data-for-sale-perk>
                                            @if ($pet->pet_style)<i class="fa-solid {{ $pet->pet_style->icon() }} mr-[3px]"></i>@endif{{ $pet->pet_style?->label() }}{{ $pet->knack() ? ($pet->pet_style ? ' + ' : '').$pet->knack()->label() : '' }}
                                        </span>
                                    @endif
                                    <span class="font-baloo text-[12px] font-extrabold text-fq-lime">{{ $pet->cost }} ✦</span>
                                </button>
                            @endforeach
                        </div>

                        <a href="{{ route('kid.trades') }}" wire:navigate class="order-5 flex items-center gap-[7px] rounded-[12px] border border-dashed px-[11px] py-[9px] text-[11.5px] text-fq-text-4" style="border-color: #3a2360">
                            <i class="fa-solid fa-right-left text-[11px]" style="color: #ff8098"></i>
                            <span class="flex-1">Short on tickets? Trade a limited with a sibling, or take a job.</span>
                            <span class="whitespace-nowrap" style="color: #c9a0ff">Trades &amp; Jobs →</span>
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- A pet for sale being tried out, loose on this screen as the kid's own
         would be: pettable, draggable, feedable, its toy out. Keyed on the
         pet and the age, so switching either is a fresh animal. Their own pet
         stays out beside it — see the shell. --}}
    @if ($trial && $trial['src'])
        <div wire:key="trial-{{ $looking->id }}-{{ $trial['age']->value }}" class="pointer-events-none fixed inset-0 z-30" data-pet-trial="{{ $trial['age']->value }}">
            <fq-pets
                sheet="{{ $trial['src'] }}"
                @if ($trial['rig']) rig="{{ json_encode($trial['rig'], JSON_UNESCAPED_SLASHES) }}" @endif
                scale="{{ $trial['scale'] }}"
                @if ($trial['effect']) effect="{{ $trial['effect'] }}" @endif
                toy
                drag
                feed-on-tap
            ></fq-pets>
        </div>

        {{-- At the top: the pets live along the bottom of the window. --}}
        <div class="fixed inset-x-0 top-0 z-40 flex justify-center px-3 pt-3">
            <div class="flex max-w-[640px] flex-1 flex-wrap items-center gap-[8px] rounded-[16px] border px-[12px] py-[10px] shadow-2xl" style="border-color: {{ $looking->rarity()->color() }}; background: rgba(14,7,25,.96)">
                <div class="min-w-[150px] flex-1">
                    <p class="font-mono-fq text-[8.5px] tracking-[0.14em] uppercase" style="color: #54e8d0">Trying it out · not bought yet</p>
                    <p class="text-[12px] text-fq-text-3">{{ $looking->name }}, as a {{ mb_strtolower($trial['age']->label()) }}. Pet it, pick it up, tap anywhere empty to feed it.</p>
                </div>

                <div class="flex gap-[4px]" role="radiogroup" aria-label="Age">
                    @foreach (PetStage::cases() as $age)
                        @php $on = $trial['age'] === $age; @endphp
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $on ? 'true' : 'false' }}"
                            wire:click="tryOut('{{ $age->value }}')"
                            class="rounded-full border px-[10px] py-[5px] font-mono-fq text-[9px] tracking-[0.08em] uppercase"
                            style="border-color: {{ $on ? '#54e8d0' : '#3a2360' }}; color: {{ $on ? '#54e8d0' : '#b0a3cc' }}"
                        >{{ $age->label() }}</button>
                    @endforeach
                </div>

                @php $trialShort = $looking->cost - $profile->bonus_tickets; @endphp
                <button
                    type="button"
                    @if ($trialShort <= 0) wire:click="buy" wire:confirm="Buy {{ $looking->name }} for {{ $looking->cost }} tickets? You'd have {{ $profile->bonus_tickets - $looking->cost }} left." @endif
                    @disabled($trialShort > 0)
                    class="rounded-[10px] px-[12px] py-[7px] font-baloo text-[13px] font-extrabold disabled:opacity-40"
                    style="background: linear-gradient(150deg,#fff6b0,#ffc93d); color: #1a1200"
                >{{ $trialShort > 0 ? $trialShort.' more '.Str::plural('ticket', $trialShort) : 'Buy · '.$looking->cost.' ✦' }}</button>

                <button type="button" wire:click="stopTrying" aria-label="Put it away" class="px-[6px] py-[7px] text-[13px] text-fq-text-4"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
    @endif
</x-kid.shell>
