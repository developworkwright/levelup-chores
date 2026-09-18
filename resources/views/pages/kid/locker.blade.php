<?php

use App\Enums\CosmeticFlavor;
use App\Enums\CosmeticSlot;
use App\Exceptions\CosmeticUnavailableException;
use App\Exceptions\InsufficientTicketsException;
use App\Models\Cosmetic;
use App\Models\Profile;
use App\Services\CosmeticService;
use App\Services\PetService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The cosmetic locker — seven slots, all worn at once, all seen by the house.
 *
 * Mirror first: the top of the page is the kid's card exactly as the house sees
 * it, and it changes the instant they tap something. Below it the slots as a
 * rail, then this week's limiteds, then the shelf for whichever slot is open.
 * One page, no second screen — the preview is the thing being bought rather
 * than a picture of it.
 *
 * Owned is forever and swapping is free: the ticket buys the item, never the
 * wearing of it. See CosmeticService.
 */
new class extends Component
{
    public Profile $profile;

    /** Which slot's shelf is open. */
    public string $slot = 'frame';

    /** Which flavor chip is picked; 'all' is Everything. */
    public string $flavor = 'all';

    /** The item just bought, for the "yours now" card. */
    public ?int $boughtId = null;

    /**
     * Something the kid is trying on before buying. It goes on the mirror —
     * and, for a theme, over the whole page — and costs nothing until they say
     * Buy. Nothing about it is saved: leave the page and it's gone.
     */
    public ?int $tryingId = null;

    public ?string $flashMessage = null;

    /**
     * Said on the egg's own card, not at the top of the page: the egg is
     * bought from well down the Pet tab, and a refusal up at the top — out of
     * sight — looked like the button had done nothing at all.
     */
    public ?string $eggNote = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();
        abort_unless($this->profile->isKid(), 403);
    }

    public function pickSlot(string $slot): void
    {
        if (CosmeticSlot::tryFrom($slot)) {
            $this->slot = $slot;
            $this->flashMessage = null;
        }
    }

    public function pickFlavor(string $flavor): void
    {
        if ($flavor === 'all' || CosmeticFlavor::tryFrom($flavor)) {
            $this->flavor = $flavor;
        }
    }

    /**
     * One tap: wear it if it's yours, try it on if it isn't. Buying is always a
     * second, deliberate tap on the try-on bar — a six-year-old tapping round
     * the shelf to see what things look like must never spend a ticket doing it.
     */
    public function choose(int $cosmeticId): void
    {
        $item = Cosmetic::where('household_id', $this->profile->household_id)->find($cosmeticId);

        if (! $item) {
            return;
        }

        $service = app(CosmeticService::class);
        $this->boughtId = null;
        $this->flashMessage = null;

        if ($service->owns($this->profile, $item)) {
            $this->tryingId = null;

            try {
                $service->wear($this->profile, $item);
            } catch (CosmeticUnavailableException $e) {
                $this->flashMessage = $e->getMessage();
            }

            return;
        }

        if (! $service->isForSale($item)) {
            $this->flashMessage = 'That one isn\'t on sale this week.';

            return;
        }

        $this->tryingId = $item->id;
    }

    /** The second tap: buy what's being tried on, and put it on for real. */
    public function buyTrying(): void
    {
        $item = $this->tryingId
            ? Cosmetic::where('household_id', $this->profile->household_id)->find($this->tryingId)
            : null;

        if (! $item) {
            $this->tryingId = null;

            return;
        }

        try {
            app(CosmeticService::class)->buy($this->profile, $item);
        } catch (InsufficientTicketsException|CosmeticUnavailableException $e) {
            $this->flashMessage = $e->getMessage();

            return;
        }

        $this->tryingId = null;
        $this->flashMessage = null;
        $this->boughtId = $item->id;
        $this->dispatch('celebrate', message: "{$item->name} is yours!", style: 'ticket', motion: 'burst', origin: 'tap');
    }

    public function putBack(): void
    {
        $this->tryingId = null;
    }

    public function takeOff(string $slot): void
    {
        $case = CosmeticSlot::tryFrom($slot);

        if ($case && $case->startsEmpty()) {
            app(CosmeticService::class)->takeOff($this->profile, $case);
        }
    }

    /**
     * Back to a baby, because the kid asked. Never happens any other way —
     * putting a different pet out and back again keeps it as grown as it was.
     */
    public function raiseAgain(int $cosmeticId): void
    {
        $pet = Cosmetic::where('household_id', $this->profile->household_id)->find($cosmeticId);

        if (! $pet || $pet->slot !== CosmeticSlot::Pet || ! app(CosmeticService::class)->owns($this->profile, $pet)) {
            return;
        }

        app(PetService::class)->raiseAgain($this->profile, $pet);
        $this->flashMessage = "{$pet->name} is a baby again.";
    }

    /**
     * A surprise egg: out on their pages straight away, cracked by chores,
     * hatching into a pet that was never in the shop. See PetService.
     */
    public function buyEgg(int $petId): void
    {
        $pet = Cosmetic::where('household_id', $this->profile->household_id)->find($petId);

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

    public function with(): array
    {
        $service = app(CosmeticService::class);
        $household = $this->profile->household;
        $catalog = $service->catalog($household);
        $slot = CosmeticSlot::tryFrom($this->slot) ?? CosmeticSlot::Frame;

        $worn = $service->worn($this->profile);
        $pets = app(PetService::class);

        // A try-on is only honoured while it is still something they could buy.
        $trying = $this->tryingId ? $catalog->get($this->tryingId) : null;

        if ($trying && ($service->owns($this->profile, $trying) || ! $service->isForSale($trying))) {
            $trying = null;
        }

        // What the mirror shows: the wardrobe, with the try-on swapped in.
        $mirror = $worn;

        if ($trying) {
            $mirror[$trying->slot->value] = $trying;
        }

        // What a slot shows when nothing is bought for it: the free house item,
        // which is the app as it already looks. Frames and avatars have none.
        $houseItem = fn (CosmeticSlot $case) => $catalog->first(fn (Cosmetic $item) => $item->slot === $case && $item->isFree() && ! $item->isDraft());

        $shelf = $service->shelfFor($this->profile, $slot);

        if ($this->flavor !== 'all') {
            $shelf = $shelf->filter(fn (Cosmetic $item) => $item->flavorOrHouse()->value === $this->flavor)->values();
        }

        $priced = $shelf->reject(fn (Cosmetic $item) => $item->isFree());

        return [
            'service' => $service,
            'openSlot' => $slot,
            'worn' => $worn,
            'mirror' => $mirror,
            'trying' => $trying,
            'trialThemeCss' => $trying?->slot === CosmeticSlot::Theme ? $service->themeCssFor($trying) : null,
            'shown' => collect(CosmeticSlot::cases())->mapWithKeys(fn (CosmeticSlot $case) => [
                $case->value => $mirror[$case->value] ?? ($case->startsEmpty() ? null : $houseItem($case)),
            ]),
            'limited' => $service->limitedThisWeek($household),
            'daysLeft' => $service->daysLeftInWeek($household),
            'shelf' => $shelf,
            'fromCost' => $priced->min('cost'),
            'bought' => $this->boughtId ? $catalog->get($this->boughtId) : null,
            // How grown up each pet they own is, so a pet put away shows the
            // size it will come back out at.
            'petStages' => $slot === CosmeticSlot::Pet ? $pets->stagesFor($this->profile) : [],
            'egg' => $egg = $slot === CosmeticSlot::Pet ? $pets->eggFor($this->profile) : null,
            // One egg per egg-only pet nobody has yet, each its own colour.
            'eggsForSale' => $slot === CosmeticSlot::Pet ? $pets->eggsForSale($household) : collect(),
            // While an egg is out it stands in for the pet, so the pet's own
            // panel waits until it has hatched.
            'petOut' => $slot === CosmeticSlot::Pet && $worn['pet'] && $egg === null ? [
                'item' => $worn['pet'],
                'growth' => $pets->growthOf($this->profile, $worn['pet']),
                'stage' => $pets->stageOf($this->profile, $worn['pet']),
                'toGo' => $pets->choresToGrow($this->profile, $worn['pet']),
            ] : null,
        ];
    }
}; ?>

@php
    /*
     * Buy / wear / worn / short, as one set of looks — the design's action().
     * A limited is gold-rimmed with a gold button, everything else lilac.
     */
    $look = function (App\Models\Cosmetic $item) use ($service, $profile, $trying) {
        $isWorn = $service->wornIn($profile, $item->slot)?->id === $item->id;

        if ($trying?->id === $item->id) {
            return ['label' => 'Trying', 'bg' => 'transparent', 'rim' => '#54e8d0', 'ink' => '#54e8d0', 'border' => '#54e8d0', 'card' => '#07202c', 'name' => '#c2e6ee'];
        }

        if ($isWorn) {
            return ['label' => 'Worn', 'bg' => '#7dffb0', 'rim' => '#7dffb0', 'ink' => '#05170c', 'border' => '#7dffb0', 'card' => '#0d2a1c', 'name' => '#c7ebd8'];
        }

        if ($service->owns($profile, $item)) {
            return ['label' => 'Wear', 'bg' => 'transparent', 'rim' => '#6a3fb0', 'ink' => '#d8b4ff', 'border' => '#4a2f7a', 'card' => 'var(--fq-panel)', 'name' => 'var(--fq-text)'];
        }

        if ($item->cost > $profile->bonus_tickets) {
            return ['label' => ($item->cost - $profile->bonus_tickets).' short', 'bg' => 'transparent', 'rim' => '#2e1b4d', 'ink' => '#6f6288', 'border' => '#241539', 'card' => '#0b0616', 'name' => '#8c7bab'];
        }

        $gold = $item->isLimited();

        return [
            'label' => $item->cost.' ✦',
            'bg' => $gold ? 'linear-gradient(150deg,#fff6b0,#ffc93d)' : '#d8b4ff',
            'rim' => $gold ? '#ffc93d' : '#d8b4ff',
            'ink' => $gold ? '#231702' : '#0a0512',
            'border' => $gold ? '#ffc93d' : '#6a3fb0',
            'card' => 'var(--fq-panel)',
            'name' => 'var(--fq-text)',
        ];
    };

    $plate = $shown['plate'];
@endphp

<x-kid.shell :profile="$profile" active="locker">
    <div class="mx-auto flex max-w-[720px] flex-col gap-[13px]">
        <div class="flex items-center justify-between gap-[10px]">
            <div>
                <h2 class="font-baloo text-[24px] leading-tight font-extrabold">Locker</h2>
                <p class="text-[12.5px] text-fq-text-3">Wear it all at once</p>
            </div>

            <span class="flex shrink-0 items-center gap-[7px] rounded-[10px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[7px] font-mono-fq text-[11px] whitespace-nowrap text-fq-magenta">
                <i class="fa-solid fa-ticket text-[11px]"></i>{{ $profile->bonus_tickets }} TICKETS
            </span>
        </div>

        {{-- The mirror. What the house sees, repainted on every tap. --}}
        <div class="relative isolate flex items-center gap-[14px] overflow-hidden rounded-[22px] border border-fq-line-2 bg-fq-bg p-4" data-fq-mirror>
            @if ($shown['pattern'])
                <x-cosmetic.art :item="$shown['pattern']" mode="fill" class="absolute inset-0 -z-10" />
            @endif

            <div class="relative h-[86px] w-[86px] shrink-0">
                @unless ($mirror['avatar'])
                    <span
                        class="absolute inset-[11px] grid place-items-center rounded-full font-baloo text-[30px] font-extrabold text-fq-bg"
                        style="background: {{ $profile->color->cssVar() }}"
                    >{{ mb_substr($profile->name, 0, 1) }}</span>
                @endunless

                <x-cosmetic.face :avatar="$mirror['avatar']" :frame="$mirror['frame']" avatar-inset="11px" />
            </div>

            <div class="flex min-w-0 flex-1 flex-col items-start gap-[8px]">
                @if ($plate)
                    <x-cosmetic.plate :item="$plate" class="px-[15px] py-[7px] font-baloo text-[18px] leading-none font-extrabold">{{ $profile->name }}</x-cosmetic.plate>
                @else
                    <span class="font-baloo text-[18px] leading-none font-extrabold">{{ $profile->name }}</span>
                @endif

                <span class="font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-3 uppercase">LVL {{ $profile->level() }} {{ $profile->rank()->label() }}</span>
                <span class="font-mono-fq text-[9px] tracking-[0.12em]" style="color: {{ $trying ? '#54e8d0' : 'var(--fq-text-4)' }}">{{ $trying ? 'TRYING IT ON · NOT BOUGHT YET' : 'THE HOUSE SEES YOUR FACE, FRAME & PLATE' }}</span>
            </div>

            @if ($shown['spark'])
                <x-cosmetic.art :item="$shown['spark']" mode="fill" class="h-[46px] w-[46px] shrink-0 opacity-90" />
            @endif
        </div>

        {{-- A theme on trial repaints the page. After the worn theme's own rule
             in the shell, so it wins, and gone the moment it's put back. --}}
        @if ($trialThemeCss)
            <style data-fq-theme-trial>:root { {!! $trialThemeCss !!} }</style>
        @endif

        @if ($trying)
            @php $short = max(0, $trying->cost - $profile->bonus_tickets); @endphp

            <div
                wire:key="trying-{{ $trying->id }}"
                class="flex flex-wrap items-center gap-[12px] rounded-[18px] border p-[12px]"
                style="border-color: #54e8d0; background: linear-gradient(160deg,#07202c,#150c26 74%)"
                data-fq-trying
            >
                <span class="relative h-[52px] w-[52px] shrink-0 overflow-hidden rounded-[13px] bg-fq-bg">
                    <x-cosmetic.art :item="$trying" :label="mb_strtoupper($profile->name)" class="absolute inset-0" />
                </span>

                <div class="min-w-[120px] flex-1">
                    <p class="font-mono-fq text-[8.5px] tracking-[0.16em]" style="color: #54e8d0">TRYING ON</p>
                    <p class="mt-[2px] font-baloo text-[17px] leading-tight font-extrabold">{{ $trying->name }}</p>
                    <p class="mt-[2px] text-[11.5px] text-fq-text-3">
                        @switch ($trying->slot)
                            @case (App\Enums\CosmeticSlot::Theme)
                                The whole page is wearing it — have a look round.
                                @break
                            @case (App\Enums\CosmeticSlot::Cabinet)
                                That's your arcade machine in it.
                                @break
                            @case (App\Enums\CosmeticSlot::Spark)
                                That flies out every time you win something.
                                @break
                            @case (App\Enums\CosmeticSlot::Pattern)
                                It's behind the mirror — yours goes behind all your own pages.
                                @break
                            @default
                                It's on the mirror — once it's yours, the whole house sees it.
                        @endswitch
                    </p>
                </div>

                <div class="flex shrink-0 gap-[7px]">
                    @if ($trying->slot === App\Enums\CosmeticSlot::Spark)
                        {{-- Its own trigger, so the tap effect already worn
                             stays out of it. The burst comes out of this tap. --}}
                        <fq-spark
                            trigger="fq-spark-preview"
                            @if ($trying->isUpload()) src="{{ $trying->artUrl() }}" @else recipe="{{ $trying->recipe }}" @endif
                        ></fq-spark>
                        <button
                            type="button"
                            x-data
                            x-on:click="window.dispatchEvent(new CustomEvent('fq-spark-preview'))"
                            class="rounded-[10px] border px-[12px] py-[8px] font-baloo text-[13px] font-extrabold"
                            style="border-color: #54e8d0; color: #54e8d0"
                        >See it</button>
                    @endif

                    <button
                        type="button"
                        wire:click="putBack"
                        class="rounded-[10px] border border-fq-line-2 px-[12px] py-[8px] font-baloo text-[13px] font-extrabold text-fq-text-3"
                    >Put back</button>

                    <button
                        type="button"
                        wire:click="buyTrying"
                        @disabled($short > 0)
                        class="rounded-[10px] px-[14px] py-[8px] font-baloo text-[13px] font-extrabold"
                        style="{{ $short > 0 ? 'background: var(--fq-panel-alt-2); color: var(--fq-text-5)' : ($trying->isLimited() ? 'background: linear-gradient(150deg,#fff6b0,#ffc93d); color: #231702' : 'background: #d8b4ff; color: #0a0512') }}"
                    >{{ $short > 0 ? $short.' short' : 'Buy · '.$trying->cost.' ✦' }}</button>
                </div>

                {{-- A pet is twelve poses, so trying one on shows the sheet cut
                     up — the whole animal, not one still tile. It runs about
                     for real once it is bought. --}}
                @if ($trying->slot === App\Enums\CosmeticSlot::Pet)
                    <div class="flex w-full flex-wrap gap-[6px]" data-fq-pose-strip>
                        @foreach (App\Enums\CosmeticSlot::PET_POSES as $pose)
                            <span class="flex flex-col items-center gap-[3px]">
                                <span class="relative block h-[52px] w-[52px] overflow-hidden rounded-[10px] bg-fq-bg">
                                    <x-cosmetic.art :item="$trying" :pose="$pose" mode="fill" still class="absolute inset-0" />
                                </span>
                                <span class="font-mono-fq text-[7px] tracking-[0.08em] text-fq-text-5 uppercase">{{ $pose }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif

                {{-- A cabinet is judged around a game, not as a tile: the same
                     <fq-cabinet> bezel the arcade page draws, round a stand-in
                     screen. --}}
                @if ($trying->slot === App\Enums\CosmeticSlot::Cabinet)
                    <div class="w-full" data-fq-cabinet-preview>
                        <fq-cabinet
                            @if ($trying->isUpload()) src="{{ $trying->artUrl() }}" @else recipe="{{ $trying->recipe }}" @endif
                            class="mx-auto max-w-[320px]"
                        >
                            <div class="flex items-end justify-between gap-2">
                                <span class="font-baloo text-[22px] leading-none font-extrabold text-fq-lime">12 <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-5 uppercase">floors</span></span>
                                <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-5 uppercase">best 31</span>
                            </div>
                            <div class="relative grid aspect-[320/300] place-items-center overflow-hidden rounded-[18px] border-2 border-fq-line-2 bg-fq-bg">
                                <div class="flex flex-col items-center gap-2 text-center">
                                    <span class="font-mono-fq text-[9px] tracking-[0.3em] text-fq-cyan uppercase">Arcade</span>
                                    <span class="font-baloo text-[24px] leading-none font-extrabold">Your machine</span>
                                    <span class="text-[11px] text-fq-text-3">Every game plays in here.</span>
                                </div>
                            </div>
                        </fq-cabinet>
                    </div>
                @endif
            </div>
        @endif

        @if ($flashMessage)
            <div class="rounded-[16px] border border-fq-line-2 bg-fq-sunk px-4 py-3 text-sm text-fq-text-2">{{ $flashMessage }}</div>
        @endif

        {{-- A purchase celebrates with the thing that was bought. A tap effect
             fires itself; anything else fires the kid's own over its art. --}}
        @if ($bought)
            <div
                wire:key="bought-{{ $bought->id }}"
                class="flex items-center gap-[13px] rounded-[18px] border border-fq-gold p-[13px]"
                style="background: linear-gradient(160deg,#2a2405,#150c26 74%); animation: fq-pop .26s ease both"
            >
                <span class="relative h-[58px] w-[58px] shrink-0 overflow-hidden rounded-[14px] bg-fq-bg">
                    <x-cosmetic.art :item="$bought" :label="mb_strtoupper($profile->name)" class="absolute inset-0" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="font-mono-fq text-[8.5px] tracking-[0.16em] text-fq-ticket-label">YOURS NOW</p>
                    <p class="mt-[3px] font-baloo text-[18px] leading-tight font-extrabold text-fq-lime">{{ $bought->name }}</p>
                    <p class="mt-[2px] text-[11.5px] text-fq-notice-text">
                        {{ $bought->slot->seenBy() }}
                    </p>
                </div>
            </div>
        @endif

        {{-- The slots, each showing what's worn in it rather than an icon. --}}
        <div class="flex gap-[5px]" role="tablist" aria-label="Slots">
            @foreach (App\Enums\CosmeticSlot::cases() as $case)
                @php $active = $openSlot === $case; @endphp

                <button
                    type="button"
                    wire:key="slot-{{ $case->value }}"
                    wire:click="pickSlot('{{ $case->value }}')"
                    role="tab"
                    aria-selected="{{ $active ? 'true' : 'false' }}"
                    title="{{ $case->label() }}"
                    class="flex min-w-0 flex-1 flex-col items-center gap-[4px] rounded-[13px] border px-[4px] pt-[6px] pb-[5px] transition"
                    style="border-color: {{ $active ? '#c9a0ff' : 'var(--fq-line)' }}; background: {{ $active ? '#241546' : 'var(--fq-panel)' }}"
                >
                    <span class="relative grid aspect-square w-full place-items-center overflow-hidden rounded-[9px] bg-fq-bg">
                        @if ($shown[$case->value])
                            <x-cosmetic.art :item="$shown[$case->value]" still :label="mb_strtoupper($profile->name)" class="absolute inset-0" />
                        @else
                            <i class="fa-solid {{ $case->icon() }} text-[14px]" style="color: {{ $active ? '#d8b4ff' : 'var(--fq-text-5)' }}"></i>
                        @endif
                    </span>
                    <span class="font-mono-fq text-[7px] tracking-[0.02em] md:text-[9px]" style="color: {{ $active ? '#c9a0ff' : 'var(--fq-text-5-b)' }}">{{ $case->short() }}</span>
                </button>
            @endforeach
        </div>

        @if ($limited->isNotEmpty())
            <div class="flex flex-col gap-[9px] rounded-[16px] border border-fq-ticket-line p-[11px]" style="background: linear-gradient(180deg,#2a2405,#150c26)">
                <div class="flex items-center justify-between gap-[9px]">
                    <span class="font-baloo text-[15px] font-extrabold text-fq-lime">This week only</span>
                    <span class="font-mono-fq text-[9px] tracking-[0.1em] whitespace-nowrap text-fq-ticket-label">
                        {{ $daysLeft === 1 ? 'GONE TONIGHT' : 'UNTIL SUNDAY · '.$daysLeft.' DAYS' }}
                    </span>
                </div>

                <div class="grid grid-cols-3 gap-[7px] sm:grid-cols-4 md:grid-cols-7">
                    @foreach ($limited as $item)
                        @php $l = $look($item); @endphp

                        <button
                            type="button"
                            wire:key="limited-{{ $item->id }}"
                            wire:click="choose({{ $item->id }})"
                            class="flex min-w-0 flex-col items-center gap-[6px] rounded-[12px] border bg-fq-panel p-[7px]"
                            style="border-color: {{ $l['border'] }}"
                        >
                            <span class="relative aspect-square w-full overflow-hidden rounded-[9px] bg-fq-bg">
                                <x-cosmetic.art :item="$item" :label="mb_strtoupper($profile->name)" class="absolute inset-0" />
                            </span>
                            <span class="text-center text-[10.5px] leading-tight font-semibold" style="color: {{ $l['name'] }}">{{ $item->name }}</span>
                            <span class="font-mono-fq text-[7.5px] tracking-[0.08em] text-fq-gold">{{ mb_strtoupper($item->slot->label()) }}</span>
                            <span
                                class="w-full rounded-[7px] border py-[4px] text-center font-baloo text-[11px] font-extrabold"
                                style="background: {{ $l['bg'] }}; border-color: {{ $l['rim'] }}; color: {{ $l['ink'] }}"
                            >{{ $l['label'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex flex-wrap gap-[5px]">
            @foreach (array_merge(['all'], array_map(fn ($f) => $f->value, App\Enums\CosmeticFlavor::cases())) as $key)
                @php
                    $case = App\Enums\CosmeticFlavor::tryFrom($key);
                    $on = $flavor === $key;
                @endphp

                <button
                    type="button"
                    wire:key="flavor-{{ $key }}"
                    wire:click="pickFlavor('{{ $key }}')"
                    class="rounded-full border px-[10px] py-[5px] font-mono-fq text-[8.5px] tracking-[0.08em] uppercase md:text-[10px]"
                    style="border-color: {{ $on ? ($case?->border() ?? '#6a3fb0') : '#241539' }}; background: {{ $on ? '#1d1036' : '#0b0616' }}; color: {{ $on ? ($case?->ink() ?? '#c9a0ff') : '#6f6288' }}"
                >{{ $case?->label() ?? 'Everything' }}</button>
            @endforeach
        </div>

        <div class="flex items-center justify-between gap-[10px]">
            <div>
                <p class="font-baloo text-[16px] font-extrabold">{{ $openSlot->label() }}</p>
                <p class="text-[11px] text-fq-text-4">{{ $openSlot->blurb() }}</p>
            </div>

            <div class="flex shrink-0 items-center gap-[8px]">
                @if ($openSlot->startsEmpty() && $worn[$openSlot->value])
                    <button
                        type="button"
                        wire:click="takeOff('{{ $openSlot->value }}')"
                        class="rounded-[8px] border border-fq-line-2 px-[9px] py-[5px] font-mono-fq text-[8.5px] tracking-[0.1em] text-fq-text-4 uppercase"
                    >Take off</button>
                @endif

                <span class="font-mono-fq text-[8.5px] tracking-[0.1em] whitespace-nowrap text-fq-text-5">
                    @if ($shelf->isNotEmpty())
                        {{ $shelf->count() }}{{ $fromCost ? ' · FROM '.$fromCost.' ✦' : '' }}
                    @elseif ($openSlot === App\Enums\CosmeticSlot::Pet && $flavor === 'all')
                        {{-- Pets are the one slot the app ships nothing for: every
                             one is a sprite sheet a grown-up uploads. --}}
                        NO PETS YET — ASK A GROWN-UP
                    @else
                        NOTHING IN THIS SET YET
                    @endif
                </span>
            </div>
        </div>

        {{-- The surprise egg: bought here, cracked by chores, hatching into a
             pet that has never been in the shop. See PetService. --}}
        @if ($egg)
            @php $eggHue = $egg->hue(); @endphp
            <div wire:key="egg-{{ $egg->id }}" class="flex flex-wrap items-center gap-[13px] rounded-[18px] border p-[13px]" style="border-color: hsl({{ $eggHue }} 100% 72% / .35); background: linear-gradient(160deg, hsl({{ $eggHue }} 45% 12%), #150c26 74%)" data-egg-out>
                <img x-data :src="window.fqEggSvg?.({{ $egg->cracks }}, {{ $eggHue }})" alt="" class="h-[64px] w-[64px] shrink-0">
                <div class="min-w-[150px] flex-1">
                    <p class="font-mono-fq text-[8.5px] tracking-[0.16em] uppercase" style="color: hsl({{ $eggHue }} 100% 72%)">{{ App\Models\PetEgg::colourName($eggHue) }} egg · {{ $egg->cracks }} of {{ App\Models\PetEgg::CRACKS_TO_HATCH }} cracks</p>
                    <p class="mt-[3px] font-baloo text-[17px] leading-tight font-extrabold">
                        {{ $egg->choresToHatch() === 0 ? 'Ready to hatch!' : $egg->choresToHatch().' more '.Str::plural('chore', $egg->choresToHatch()).' and it hatches' }}
                    </p>
                    <p class="mt-[2px] text-[11px] text-fq-text-4">Something in there is a pet that's never been in the shop. It's out on your pages — tap it.</p>
                </div>
            </div>
        @endif

        {{-- The eggs for sale: one per egg-only pet nobody has yet, each its
             own colour, what's inside a secret. The first kid to buy one has
             it — it's gone for everybody. One egg at a time, so while theirs
             is out the rest wait. --}}
        @if ($eggsForSale->isNotEmpty())
            @php $short = App\Models\PetEgg::PRICE - $profile->bonus_tickets; @endphp
            <div class="flex flex-col gap-[9px] rounded-[18px] border p-[13px]" style="border-color: #3a2360; background: #120a22" data-eggs-for-sale>
                <div class="flex flex-wrap items-baseline justify-between gap-[8px]">
                    <p class="font-baloo text-[16px] font-extrabold">Surprise eggs</p>
                    <p class="text-[11px] text-fq-text-4">
                        @if ($egg)
                            Hatch yours first — then pick another.
                        @else
                            {{ App\Models\PetEgg::PRICE }} ✦ each. Crack one with {{ App\Models\PetEgg::CRACKS_TO_HATCH }} chores — it hatches into a pet that's never been in the shop.
                        @endif
                    </p>
                </div>

                <div class="grid grid-cols-3 gap-[8px] sm:grid-cols-4 md:grid-cols-6">
                    @foreach ($eggsForSale as $inside)
                        @php $hue = App\Models\PetEgg::hueFor($inside->id); @endphp
                        <button
                            type="button"
                            wire:key="egg-for-sale-{{ $inside->id }}"
                            @if (! $egg && $short <= 0)
                                wire:click="buyEgg({{ $inside->id }})"
                                wire:confirm="Spend {{ App\Models\PetEgg::PRICE }} tickets on the {{ mb_strtolower(App\Models\PetEgg::colourName($hue)) }} egg?"
                            @endif
                            @disabled($egg || $short > 0)
                            class="flex flex-col items-center gap-[4px] rounded-[12px] border p-[8px] disabled:opacity-50"
                            style="border-color: hsl({{ $hue }} 100% 72% / .35); background: linear-gradient(170deg, hsl({{ $hue }} 45% 13%), #0b0616)"
                            data-egg-colour="{{ mb_strtolower(App\Models\PetEgg::colourName($hue)) }}"
                        >
                            <img x-data :src="window.fqEggSvg?.(0, {{ $hue }})" alt="" class="h-[52px] w-[52px]">
                            <span class="font-mono-fq text-[8px] tracking-[0.1em] uppercase" style="color: hsl({{ $hue }} 100% 72%)">{{ App\Models\PetEgg::colourName($hue) }}</span>
                        </button>
                    @endforeach
                </div>

                @if (! $egg && $short > 0)
                    <p class="text-[11px] text-fq-text-4" data-egg-short>{{ $short }} more {{ Str::plural('ticket', $short) }} and you can pick one.</p>
                @endif

                @if ($eggNote)
                    <p class="text-[11.5px] text-fq-danger" data-egg-note>{{ $eggNote }}</p>
                @endif
            </div>
        @endif

        {{-- The pet that's out: how grown up it is, a snack, and the way back to
             a baby. Growing is the kid's chores; feeding is only play, and is
             done entirely by the pet layer — see feed() in pets.js. --}}
        @if ($petOut)
            @php
                $stageNow = $petOut['stage'];
                $nextStage = $stageNow->next();
                $span = $nextStage ? $nextStage->startsAt() - $stageNow->startsAt() : 1;
                $along = $nextStage ? ($petOut['growth'] - $stageNow->startsAt()) / $span : 1;
            @endphp

            <div wire:key="pet-out-{{ $petOut['item']->id }}" class="flex flex-wrap items-center gap-[13px] rounded-[18px] border border-fq-line-2 bg-fq-panel p-[13px]" data-pet-out>
                <span class="relative grid h-[64px] w-[64px] shrink-0 place-items-end overflow-hidden rounded-[14px] bg-fq-bg">
                    <span class="relative block" style="width: {{ round(64 * $stageNow->pixels() / App\Enums\PetStage::Adult->pixels()) }}px; height: {{ round(64 * $stageNow->pixels() / App\Enums\PetStage::Adult->pixels()) }}px; margin: 0 auto">
                        <x-cosmetic.art :item="$petOut['item']" :stage="$stageNow" mode="fill" class="absolute inset-0" />
                    </span>
                </span>

                <div class="min-w-[150px] flex-1">
                    <p class="font-mono-fq text-[8.5px] tracking-[0.16em] text-fq-text-4 uppercase">{{ $stageNow->label() }}</p>
                    <p class="mt-[2px] font-baloo text-[17px] leading-tight font-extrabold">{{ $petOut['item']->name }}</p>

                    <div class="mt-[7px] h-[7px] overflow-hidden rounded-full bg-fq-track">
                        <div class="h-full rounded-full" style="width: {{ round($along * 100) }}%; background: linear-gradient(90deg,#54e8d0,#7dffb0)"></div>
                    </div>

                    <p class="mt-[5px] text-[11px] text-fq-text-4">
                        @if ($petOut['toGo'] === null)
                            All grown up.
                        @else
                            {{ $petOut['toGo'] }} more {{ Str::plural('chore', $petOut['toGo']) }} and it grows up{{ $nextStage === App\Enums\PetStage::Adult ? ' all the way' : '' }}.
                        @endif
                    </p>
                    <p class="mt-[3px] text-[11px] text-fq-text-4"><i class="fa-solid fa-hand-pointer mr-[4px] text-fq-green"></i>Hungry? Tap anywhere empty on any page and a snack drops right there.</p>
                </div>

                <div class="flex shrink-0 flex-col gap-[6px]">
                    <button
                        type="button"
                        x-on:click="window.dispatchEvent(new CustomEvent('fq-pet-feed'))"
                        class="rounded-[10px] px-[14px] py-[8px] font-baloo text-[14px] font-extrabold text-fq-ink"
                        style="background: linear-gradient(150deg,#b8ffd9,#54e8d0)"
                    ><i class="fa-solid fa-drumstick-bite mr-[5px] text-[12px]"></i>Feed</button>

                    @if ($petOut['growth'] > 0)
                        <button
                            type="button"
                            wire:click="raiseAgain({{ $petOut['item']->id }})"
                            wire:confirm="Make {{ $petOut['item']->name }} a baby again? It will grow back up with your chores."
                            class="rounded-[8px] border border-fq-line-2 px-[9px] py-[5px] font-mono-fq text-[8.5px] tracking-[0.08em] text-fq-text-4 uppercase"
                        >Raise again from a baby</button>
                    @endif
                </div>
            </div>
        @endif

        <div class="grid grid-cols-3 gap-[7px] sm:grid-cols-4 md:grid-cols-5">
            @foreach ($shelf as $item)
                @php
                    $g = $look($item);
                    $petStage = $petStages[$item->id] ?? null;
                @endphp

                <button
                    type="button"
                    wire:key="item-{{ $item->id }}"
                    wire:click="choose({{ $item->id }})"
                    data-cosmetic="{{ $item->slot->value }}:{{ $item->recipe ?? $item->id }}"
                    class="flex min-w-0 flex-col items-center gap-[6px] rounded-[14px] border px-[7px] py-[8px]"
                    style="border-color: {{ $g['border'] }}; background: {{ $g['card'] }}"
                >
                    <span class="relative aspect-square w-full overflow-hidden rounded-[10px] bg-fq-bg">
                        <x-cosmetic.art :item="$item" :stage="$petStage" :label="mb_strtoupper($profile->name)" class="absolute inset-0" />

                        @if ($petStage)
                            <span class="absolute right-[4px] bottom-[4px] z-[2] rounded-full border px-[5px] py-[1px] font-mono-fq text-[6.5px] tracking-[0.08em] uppercase" style="background: rgba(10,5,18,.82); border-color: #7dffb0; color: #7dffb0">{{ $petStage->label() }}</span>
                        @endif

                        @if ($item->motion)
                            <span class="absolute top-[4px] left-[4px] z-[2] rounded-full border px-[5px] py-[1px] font-mono-fq text-[6.5px] tracking-[0.08em]" style="background: rgba(10,5,18,.82); border-color: #54e8d0; color: #54e8d0">MOVES</span>
                        @endif
                    </span>
                    <span class="text-center text-[10.5px] leading-tight font-semibold" style="color: {{ $g['name'] }}">{{ $item->name }}</span>
                    <span
                        class="w-full rounded-[7px] border py-[4px] text-center font-baloo text-[11px] font-extrabold"
                        style="background: {{ $g['bg'] }}; border-color: {{ $g['rim'] }}; color: {{ $g['ink'] }}"
                    >{{ $g['label'] }}</span>
                </button>
            @endforeach
        </div>

        <div class="flex items-start gap-[9px] rounded-[14px] border border-dashed border-fq-line-2 px-3 py-[10px]">
            <i class="fa-solid fa-shirt mt-[2px] text-[12px] text-fq-text-4"></i>
            <span class="flex-1 text-[11.5px] text-fq-text-4">Owned is forever and swapping is free — the ticket buys the item, never the wearing of it. Nothing here is refundable, so buying is the only decision.</span>
        </div>
    </div>
</x-kid.shell>
