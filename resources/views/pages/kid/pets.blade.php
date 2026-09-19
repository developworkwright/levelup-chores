<?php

use App\Enums\ArcadeGame;
use App\Enums\CosmeticSlot;
use App\Enums\PetStage;
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
 * its knack and a Power Treat; what its style does in each game; the other
 * pets the kid owns, to swap between; surprise eggs; and pets for sale.
 *
 * Underneath it is still a cosmetic — CosmeticSlot::Pet, bought with tickets,
 * owned forever, grown on the kid's own copy — so buying and wearing go
 * through CosmeticService exactly as the Locker's do. See PetService and
 * KnackService for the rest.
 *
 * A first cut, laid out to be redesigned.
 */
new class extends Component
{
    public Profile $profile;

    public ?string $flashMessage = null;

    /** A pet for sale being looked at before buying, by id. */
    public ?int $lookingAt = null;

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
        $this->flashMessage = null;
    }

    public function stopLooking(): void
    {
        $this->lookingAt = null;
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

        return [
            'egg' => $egg,
            'out' => $egg === null ? $out : null,
            'growth' => $out ? $pets->growthOf($this->profile, $out) : 0,
            'stage' => $out ? $pets->stageOf($this->profile, $out) : null,
            'toGo' => $out ? $pets->choresToGrow($this->profile, $out) : null,
            'knack' => $knack,
            'treatPrice' => $knack ? $knacks->treatPrice($this->profile, $knack['knack']) : null,
            // What its style does, game by game — only the games given styles.
            'styleGames' => $out?->pet_style
                ? collect(ArcadeGame::ranked())->mapWithKeys(fn (ArcadeGame $game) => [$game->label() => $game->styleHelp($out->pet_style)])->filter()->all()
                : [],
            'gear' => app(PrizeCounterService::class)->gearFor($this->profile),
            'owned' => $owned,
            'stages' => $pets->stagesFor($this->profile),
            'forSale' => $shelf->reject(fn (Cosmetic $pet) => $cosmetics->owns($this->profile, $pet))->values(),
            'looking' => $this->lookingAt ? $shelf->firstWhere('id', $this->lookingAt) : null,
            'eggsForSale' => $pets->eggsForSale($household),
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="pets">
    <div class="flex flex-col gap-4">
        <div>
            <h2 class="font-baloo text-[26px] leading-none font-extrabold">Pets</h2>
            <p class="mt-[4px] text-[12.5px] text-fq-text-4">Raise them with chores. Grown-up pets help out more.</p>
        </div>

        @if ($flashMessage)
            <div class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2" data-pets-flash>{{ $flashMessage }}</div>
        @endif

        {{-- The egg in the pet's place: cracked by chores, a surprise inside. --}}
        @if ($egg)
            @php $eggHue = $egg->hue(); @endphp
            <div wire:key="egg-{{ $egg->id }}" class="flex flex-wrap items-center gap-[13px] rounded-[20px] border p-[14px]" style="border-color: hsl({{ $eggHue }} 100% 72% / .35); background: linear-gradient(160deg, hsl({{ $eggHue }} 45% 12%), #150c26 74%)" data-egg-out>
                <img x-data :src="window.fqEggSvg?.({{ $egg->cracks }}, {{ $eggHue }}, '{{ PetEgg::patternFor($egg->pet) }}')" alt="" class="h-[76px] w-[76px] shrink-0">
                <div class="min-w-[150px] flex-1">
                    <p class="font-mono-fq text-[8.5px] tracking-[0.16em] uppercase" style="color: hsl({{ $eggHue }} 100% 72%)">{{ PetEgg::colourName($eggHue) }} egg · {{ $egg->cracks }} of {{ PetEgg::CRACKS_TO_HATCH }} cracks</p>
                    <p class="mt-[3px] font-baloo text-[19px] leading-tight font-extrabold">
                        {{ $egg->choresToHatch() === 0 ? 'Ready to hatch!' : $egg->choresToHatch().' more '.Str::plural('chore', $egg->choresToHatch()).' and it hatches' }}
                    </p>
                    <p class="mt-[2px] text-[11.5px] text-fq-text-4">Something in there is a pet that's never been in the shop. It's out on your pages — tap it.</p>
                </div>
            </div>
        @endif

        {{-- The pet that's out. --}}
        @if ($out)
            @php
                $tier = $out->rarity();
                $style = $out->pet_style;
                $next = $stage->next();
                $span = $next ? $next->startsAt() - $stage->startsAt() : 1;
                $along = $next ? ($growth - $stage->startsAt()) / $span : 1;
                $px = round(120 * $stage->pixels() / PetStage::Adult->pixels());
            @endphp

            <div wire:key="pet-out-{{ $out->id }}" class="flex flex-col gap-[12px] rounded-[22px] border p-[15px]" style="border-color: {{ $tier->color() }}66; background: linear-gradient(165deg, var(--fq-panel-alt), var(--fq-panel) 70%)" data-pet-out>
                <div class="flex flex-wrap items-center gap-[15px]">
                    <span class="relative grid h-[128px] w-[128px] shrink-0 place-items-end overflow-hidden rounded-[18px] bg-fq-bg">
                        <span class="relative mx-auto block" style="width: {{ $px }}px; height: {{ $px }}px">
                            <x-cosmetic.art :item="$out" :stage="$stage" mode="fill" class="absolute inset-0" />
                        </span>
                    </span>

                    <div class="min-w-[170px] flex-1">
                        <p class="flex flex-wrap items-center gap-[6px] font-mono-fq text-[8.5px] tracking-[0.16em] uppercase">
                            <span class="text-fq-text-4">{{ $stage->label() }}</span>
                            <span class="rounded-full border px-[6px] py-[1px]" style="border-color: {{ $tier->color() }}; color: {{ $tier->color() }}" data-pet-tier>{{ $tier->label() }}</span>
                            @if ($style)
                                <span class="rounded-full border border-fq-line-3 px-[6px] py-[1px] text-fq-text-3" data-pet-style><i class="fa-solid {{ $style->icon() }} mr-[3px]"></i>{{ $style->label() }}</span>
                            @endif
                        </p>
                        <p class="mt-[3px] font-baloo text-[24px] leading-tight font-extrabold">{{ $out->name }}</p>

                        <div class="mt-[8px] h-[8px] overflow-hidden rounded-full bg-fq-track">
                            <div class="h-full rounded-full" style="width: {{ round($along * 100) }}%; background: linear-gradient(90deg,#54e8d0,#7dffb0)"></div>
                        </div>
                        <p class="mt-[5px] text-[11.5px] text-fq-text-4">
                            @if ($toGo === null)
                                All grown up.
                            @else
                                {{ $toGo }} more {{ Str::plural('chore', $toGo) }} and it grows up{{ $next === PetStage::Adult ? ' all the way' : '' }}.
                            @endif
                        </p>

                        <div class="mt-[9px] flex flex-wrap gap-[7px]">
                            <button
                                type="button"
                                x-data
                                x-on:click="window.dispatchEvent(new CustomEvent('fq-pet-feed'))"
                                class="rounded-[10px] px-[14px] py-[7px] font-baloo text-[14px] font-extrabold text-fq-ink"
                                style="background: linear-gradient(150deg,#b8ffd9,#54e8d0)"
                            ><i class="fa-solid fa-drumstick-bite mr-[5px] text-[12px]"></i>Feed</button>

                            @if ($growth > 0)
                                <button
                                    type="button"
                                    wire:click="raiseAgain({{ $out->id }})"
                                    wire:confirm="Make {{ $out->name }} a baby again? It will grow back up with your chores."
                                    class="rounded-[10px] border border-fq-line-2 px-[11px] py-[7px] font-mono-fq text-[9px] tracking-[0.08em] text-fq-text-4 uppercase"
                                >Raise again from a baby</button>
                            @endif
                        </div>
                        <p class="mt-[6px] text-[11px] text-fq-text-5"><i class="fa-solid fa-hand-pointer mr-[4px] text-fq-green"></i>Tap anywhere empty on any page and a snack drops right there.</p>
                    </div>
                </div>

                {{-- Its knack, the uses left as paw prints, and a Power Treat. --}}
                @if ($knack)
                    <div class="rounded-[16px] border px-[13px] py-[11px]" style="border-color: {{ $tier->color() }}55; background: #0b0616" data-pet-knack="{{ $knack['knack']->value }}">
                        <p class="flex flex-wrap items-center gap-[7px]">
                            <i class="fa-solid {{ $knack['knack']->icon() }} text-[13px]" style="color: {{ $tier->color() }}"></i>
                            <span class="font-baloo text-[17px] font-extrabold">{{ $knack['knack']->label() }}</span>
                            @if ($knack['strength'] === 'half')
                                <span class="font-mono-fq text-[8px] tracking-[0.1em] text-fq-gold uppercase">Half strength</span>
                            @elseif ($knack['strength'] === 'full')
                                <span class="font-mono-fq text-[8px] tracking-[0.1em] text-fq-green uppercase">Full strength</span>
                            @endif
                            @if ($knack['doubled'])
                                <span class="rounded-full border border-fq-green px-[6px] py-[1px] font-mono-fq text-[8px] tracking-[0.1em] text-fq-green uppercase" data-knack-doubled>Doubled today</span>
                            @endif
                        </p>
                        <p class="mt-[3px] text-[12px] text-fq-text-3">{{ $knack['description'] }}</p>

                        @if ($knack['uses'] !== null)
                            <p class="mt-[6px] flex flex-wrap items-center gap-[4px] text-[11px] text-fq-text-4" data-knack-left="{{ $knack['left'] }}">
                                @foreach (range(1, max($knack['uses'], $knack['left'])) as $paw)
                                    <i class="fa-solid fa-paw text-[12px]" style="color: {{ $paw <= $knack['left'] ? ($paw > $knack['left'] - $knack['treats'] ? '#7dffb0' : $tier->color()) : '#3a2360' }}"></i>
                                @endforeach
                                <span class="ml-[3px]">
                                    {{ $knack['left'] }} left
                                    @if ($knack['treats'] > 0) ({{ $knack['treats'] }} from treats) @endif
                                    @if ($knack['backAt'] && $knack['left'] - $knack['treats'] < $knack['uses'])
                                        · next free one {{ $knack['backAt']->isToday() ? 'later today' : $knack['backAt']->format('l') }}
                                    @endif
                                    · {{ $knack['automatic'] ? 'it goes off by itself' : 'it offers when it can help' }}
                                </span>
                            </p>
                        @elseif ($knack['unlocked'])
                            <p class="mt-[6px] text-[11px] text-fq-text-4">Always on.</p>
                        @endif

                        @if ($knack['choresToUnlock'] !== null)
                            <p class="mt-[6px] text-[11px] text-fq-text-4">Learns it in {{ $knack['choresToUnlock'] }} more {{ Str::plural('chore', $knack['choresToUnlock']) }}.</p>
                        @elseif ($knack['choresToFull'] !== null)
                            <p class="mt-[6px] text-[11px] text-fq-text-4">Full strength in {{ $knack['choresToFull'] }} more {{ Str::plural('chore', $knack['choresToFull']) }}.</p>
                        @endif

                        {{-- Power Treat: one more use, or double today. --}}
                        @if ($knack['unlocked'])
                            @php $canAfford = $profile->bonus_tickets >= $treatPrice; @endphp
                            <div class="mt-[10px] flex flex-wrap items-center gap-[9px] border-t border-fq-line pt-[10px]" data-power-treat>
                                <button
                                    type="button"
                                    wire:click="buyTreat"
                                    wire:confirm="Give {{ $out->name }} a Power Treat for {{ $treatPrice }} {{ Str::plural('ticket', $treatPrice) }}? You'd have {{ $profile->bonus_tickets - $treatPrice }} left."
                                    @disabled(! $canAfford)
                                    class="flex items-center gap-[7px] rounded-[11px] px-[13px] py-[8px] font-baloo text-[14px] font-extrabold text-fq-ink disabled:opacity-40"
                                    style="background: linear-gradient(150deg,#fff6b0,#ffc93d)"
                                ><i class="fa-solid fa-cookie-bite"></i>Power Treat · {{ $treatPrice }} ✦</button>
                                <span class="min-w-[140px] flex-1 text-[11px] text-fq-text-4">
                                    {{ $knack['knack']->alwaysOn() ? 'Doubles '.$knack['knack']->label().' for the rest of today.' : 'One more '.$knack['knack']->label().', on top of the free ones. It keeps until you need it.' }}
                                </span>
                            </div>
                            @if ($treatNote)
                                <p class="mt-[5px] text-[11.5px] text-fq-danger" data-treat-note>{{ $treatNote }}</p>
                            @endif
                        @endif
                    </div>
                @endif

                {{-- Its style, game by game. --}}
                @if ($style && $styleGames)
                    <div class="rounded-[16px] border border-fq-line bg-fq-sunk px-[13px] py-[11px]" data-pet-style-games>
                        <p class="flex items-center gap-[7px] font-baloo text-[15px] font-extrabold"><i class="fa-solid fa-gamepad text-fq-violet"></i>{{ $style->label() }} in the arcade</p>
                        <ul class="mt-[5px] flex flex-col gap-[4px] text-[12px] text-fq-text-3">
                            @foreach ($styleGames as $game => $help)
                                <li><strong class="text-fq-text-2">{{ $game }}:</strong> {{ $out->name }} {{ $help }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- What it has out from the arcade's prize counter. --}}
                <div class="flex flex-wrap items-center gap-[10px] text-[11.5px] text-fq-text-4" data-pet-gear>
                    <span class="font-mono-fq text-[9px] tracking-[0.14em] uppercase">Gear</span>
                    @foreach (['snack' => 'Snack', 'toy' => 'Toy', 'bed' => 'Bed'] as $kind => $name)
                        @if ($gear[$kind])
                            <span class="flex items-center gap-[5px] rounded-full border border-fq-line-2 px-[8px] py-[3px]">
                                <fq-prize kind="{{ $kind }}" key="{{ $gear[$kind] }}" class="h-[18px] w-[18px]"></fq-prize>{{ $name }}
                            </span>
                        @endif
                    @endforeach
                    <a href="{{ route('kid.arcade') }}" wire:navigate class="text-fq-cyan underline-offset-2 hover:underline">More at the arcade's prize counter →</a>
                </div>
            </div>
        @elseif (! $egg)
            <div class="rounded-[20px] border border-dashed border-fq-line-2 px-4 py-6 text-center text-[13px] text-fq-text-4" data-no-pet>
                No pet out yet — {{ $owned->isNotEmpty() ? 'pick one of yours below' : 'pick one from the pets for sale, or crack a surprise egg' }}.
            </div>
        @endif

        {{-- Their other pets, to swap between. Swapping is free; nothing resets. --}}
        @if ($owned->count() > ($out ? 1 : 0))
            <div class="flex flex-col gap-[8px]">
                <p class="font-baloo text-[17px] font-extrabold">Your pets</p>
                <div class="grid grid-cols-3 gap-[8px] sm:grid-cols-4 md:grid-cols-6">
                    @foreach ($owned as $pet)
                        @php $isOut = $out?->id === $pet->id; $petStage = $stages[$pet->id] ?? PetStage::Baby; @endphp
                        <button
                            type="button"
                            wire:key="owned-{{ $pet->id }}"
                            @unless ($isOut) wire:click="wear({{ $pet->id }})" @endunless
                            class="flex flex-col items-center gap-[5px] rounded-[14px] border px-[7px] py-[8px]"
                            style="border-color: {{ $isOut ? '#7dffb0' : $pet->rarity()->color().'66' }}; background: {{ $isOut ? '#0d2a1c' : 'var(--fq-panel)' }}"
                            data-owned-pet="{{ $pet->id }}"
                        >
                            <span class="relative aspect-square w-full overflow-hidden rounded-[10px] bg-fq-bg">
                                <x-cosmetic.art :item="$pet" :stage="$petStage" class="absolute inset-0" />
                                <span class="absolute right-[4px] bottom-[4px] rounded-full border px-[5px] py-[1px] font-mono-fq text-[6.5px] tracking-[0.08em] uppercase" style="background: rgba(10,5,18,.82); border-color: #7dffb0; color: #7dffb0">{{ $petStage->label() }}</span>
                            </span>
                            <span class="text-center text-[10.5px] leading-tight font-semibold">{{ $pet->name }}</span>
                            <span class="font-mono-fq text-[8px] tracking-[0.1em] uppercase" style="color: {{ $isOut ? '#7dffb0' : '#8c7bab' }}">{{ $isOut ? 'Out now' : 'Put out' }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Surprise eggs: one per egg-only pet nobody has yet. --}}
        @if ($eggsForSale->isNotEmpty())
            @php $cheapest = $eggsForSale->map(fn ($pet) => PetEgg::priceFor($pet))->min(); @endphp
            <div class="flex flex-col gap-[9px] rounded-[18px] border p-[13px]" style="border-color: #3a2360; background: #120a22" data-eggs-for-sale>
                <div class="flex flex-wrap items-baseline justify-between gap-[8px]">
                    <p class="font-baloo text-[17px] font-extrabold">Surprise eggs</p>
                    <p class="text-[11px] text-fq-text-4">
                        {{ $egg ? 'Hatch yours first — then pick another.' : 'The pattern shows how rare the pet inside is. Crack one with '.PetEgg::CRACKS_TO_HATCH.' chores.' }}
                    </p>
                </div>

                <div class="grid grid-cols-3 gap-[8px] sm:grid-cols-4 md:grid-cols-6">
                    @foreach ($eggsForSale as $inside)
                        @php
                            $hue = PetEgg::hueFor($inside->id);
                            $eggTier = $inside->rarity();
                            $price = PetEgg::priceFor($inside);
                            $affordable = $price <= $profile->bonus_tickets;
                            $colour = mb_strtolower(PetEgg::colourName($hue));
                        @endphp
                        <button
                            type="button"
                            wire:key="egg-for-sale-{{ $inside->id }}"
                            @if (! $egg && $affordable)
                                wire:click="buyEgg({{ $inside->id }})"
                                wire:confirm="Spend {{ $price }} tickets on the {{ mb_strtolower($eggTier->label()) }} {{ $colour }} egg? You'd have {{ $profile->bonus_tickets - $price }} left."
                            @endif
                            @disabled($egg || ! $affordable)
                            class="flex flex-col items-center gap-[4px] rounded-[12px] border p-[8px] disabled:opacity-50"
                            style="border-color: {{ $eggTier === App\Enums\PetRarity::Common ? 'hsl('.$hue.' 100% 72% / .35)' : $eggTier->color() }}; background: linear-gradient(170deg, hsl({{ $hue }} 45% 13%), #0b0616)"
                            data-egg-tier="{{ $eggTier->value }}"
                            data-egg-colour="{{ $colour }}"
                        >
                            <img x-data :src="window.fqEggSvg?.(0, {{ $hue }}, '{{ $eggTier->eggPattern() }}')" alt="" class="h-[52px] w-[52px]">
                            <span class="font-mono-fq text-[8px] tracking-[0.1em] uppercase" style="color: {{ $eggTier->color() }}">{{ $eggTier->label() }}</span>
                            <span class="font-baloo text-[12px] font-extrabold text-fq-lime">{{ $price }} ✦</span>
                        </button>
                    @endforeach
                </div>

                @if (! $egg && $cheapest > $profile->bonus_tickets)
                    @php $short = $cheapest - $profile->bonus_tickets; @endphp
                    <p class="text-[11px] text-fq-text-4" data-egg-short>{{ $short }} more {{ Str::plural('ticket', $short) }} and you can pick one.</p>
                @endif

                @if ($eggNote)
                    <p class="text-[11.5px] text-fq-danger" data-egg-note>{{ $eggNote }}</p>
                @endif
            </div>
        @endif

        {{-- Pets for sale: a tap opens it up, a second tap buys. --}}
        @if ($forSale->isNotEmpty())
            <div class="flex flex-col gap-[8px]">
                <p class="font-baloo text-[17px] font-extrabold">Pets for sale</p>

                @if ($looking)
                    @php
                        $lookTier = $looking->rarity();
                        $short = $looking->cost - $profile->bonus_tickets;
                    @endphp
                    <div class="flex flex-col gap-[10px] rounded-[18px] border-2 p-[13px]" style="border-color: {{ $lookTier->color() }}; background: var(--fq-panel)" data-looking-at="{{ $looking->id }}">
                        <div class="flex flex-wrap items-start gap-[10px]">
                            <div class="min-w-[160px] flex-1">
                                <p class="font-baloo text-[19px] leading-tight font-extrabold">{{ $looking->name }}</p>
                                <p class="mt-[3px] flex flex-wrap items-center gap-[6px] font-mono-fq text-[8.5px] tracking-[0.14em] uppercase">
                                    <span class="rounded-full border px-[6px] py-[1px]" style="border-color: {{ $lookTier->color() }}; color: {{ $lookTier->color() }}">{{ $lookTier->label() }}</span>
                                    @if ($looking->pet_style)
                                        <span class="text-fq-text-3"><i class="fa-solid {{ $looking->pet_style->icon() }} mr-[3px]"></i>{{ $looking->pet_style->label() }}</span>
                                    @endif
                                </p>
                                @if ($looking->knack())
                                    <p class="mt-[5px] text-[12px] text-fq-text-3"><strong class="text-fq-text-2">{{ $looking->knack()->label() }}:</strong> {{ $looking->knack()->describe(PetStage::Adult) }} <span class="text-fq-text-5">(once it's grown up)</span></p>
                                @endif
                            </div>
                            <button type="button" wire:click="stopLooking" aria-label="Close" class="px-[6px] text-fq-text-4"><i class="fa-solid fa-xmark"></i></button>
                        </div>

                        <div class="flex flex-wrap gap-[6px]">
                            @foreach (array_diff($looking->pet_rig ? App\Enums\CosmeticSlot::PET_POSES : App\Enums\CosmeticSlot::LEGACY_PET_POSES, ['blink', 'walk2']) as $pose)
                                <span class="relative block h-[52px] w-[52px] overflow-hidden rounded-[10px] bg-fq-bg">
                                    <x-cosmetic.art :item="$looking" :pose="$pose" mode="fill" still class="absolute inset-0" />
                                </span>
                            @endforeach
                        </div>

                        <button
                            type="button"
                            @if ($short <= 0) wire:click="buy" wire:confirm="Buy {{ $looking->name }} for {{ $looking->cost }} tickets? You'd have {{ $profile->bonus_tickets - $looking->cost }} left." @endif
                            @disabled($short > 0)
                            class="self-start rounded-[12px] px-[18px] py-[9px] font-baloo text-[15px] font-extrabold text-fq-ink disabled:opacity-40"
                            style="background: linear-gradient(150deg,#fff6b0,#ffc93d)"
                        >{{ $short > 0 ? $short.' more '.Str::plural('ticket', $short) : 'Buy · '.$looking->cost.' ✦' }}</button>
                    </div>
                @endif

                <div class="grid grid-cols-3 gap-[8px] sm:grid-cols-4 md:grid-cols-6">
                    @foreach ($forSale as $pet)
                        <button
                            type="button"
                            wire:key="for-sale-{{ $pet->id }}"
                            wire:click="look({{ $pet->id }})"
                            class="flex flex-col items-center gap-[5px] rounded-[14px] border px-[7px] py-[8px]"
                            style="border-color: {{ $looking?->id === $pet->id ? $pet->rarity()->color() : $pet->rarity()->color().'55' }}; background: var(--fq-panel)"
                            data-for-sale="{{ $pet->id }}"
                        >
                            <span class="relative aspect-square w-full overflow-hidden rounded-[10px] bg-fq-bg">
                                <x-cosmetic.art :item="$pet" class="absolute inset-0" />
                                @if ($pet->rarity() !== App\Enums\PetRarity::Common)
                                    <span class="absolute top-[4px] right-[4px] rounded-full border px-[5px] py-[1px] font-mono-fq text-[6.5px] tracking-[0.08em] uppercase" style="background: rgba(10,5,18,.82); border-color: {{ $pet->rarity()->color() }}; color: {{ $pet->rarity()->color() }}">{{ $pet->rarity()->label() }}</span>
                                @endif
                            </span>
                            <span class="text-center text-[10.5px] leading-tight font-semibold">{{ $pet->name }}</span>
                            <span class="font-baloo text-[12px] font-extrabold text-fq-lime">{{ $pet->cost }} ✦</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-kid.shell>
