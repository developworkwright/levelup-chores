<?php

use App\Enums\AccentColor;
use App\Enums\ProfileRole;
use App\Models\Profile;
use App\Services\CosmeticService;
use App\Services\StreakService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public function mount(): void
    {
        $profile = Auth::guard('profile')->user();

        if ($profile) {
            $this->redirect($profile->isParent() ? '/parent' : '/kid', navigate: true);
        }
    }

    /**
     * Kids in the order the avatar row should fan them out. Source order is
     * oldest first, as everywhere else, except that the lilac kid is pulled
     * into the middle slot — otherwise the two yellow accents land side by
     * side and read as one tile. This reordering is local to the login row.
     *
     * @return array<int, Profile>
     */
    private function fannedKids(): array
    {
        $kids = Profile::query()
            ->where('role', ProfileRole::Kid)
            ->orderByDesc('age')
            ->get()
            ->all();

        $lilac = null;

        foreach ($kids as $index => $kid) {
            if ($kid->color === AccentColor::Cyan) {
                $lilac = $index;
                break;
            }
        }

        $middle = intdiv(count($kids) - 1, 2);

        if ($lilac !== null && $lilac !== $middle) {
            array_splice($kids, $middle, 0, array_splice($kids, $lilac, 1));
        }

        return $kids;
    }

    public function with(): array
    {
        $kids = $this->fannedKids();

        $streaks = app(StreakService::class);
        $cosmetics = app(CosmeticService::class);

        // Who has put work in today, keyed by id.
        //
        // This page is public, so the bar for putting anything on it is what a
        // stranger with the URL learns. A glowing tile says "this kid did a
        // chore today" — less than the level and the rank this tile used to
        // print, and nothing like the real names and scores that got the arcade moved
        // behind the PIN. It earns its place by being the only thing here that
        // changes during a day: a door that looked identical at bedtime and at
        // breakfast was the whole complaint.
        $poweredUp = collect($kids)
            ->mapWithKeys(fn (Profile $kid): array => [$kid->id => $streaks->hasWorkedToday($kid)])
            ->all();

        return [
            'kids' => $kids,
            'poweredUp' => $poweredUp,
            // How fiercely each run burns, 0-6. Keyed to the milestone ladder,
            // so the flame grows on the mornings a chest is waiting.
            'fireTiers' => collect($kids)
                ->mapWithKeys(fn (Profile $kid): array => [$kid->id => $streaks->fireTier($kid->streak)])
                ->all(),
            // The sky reacts to the house as a whole rather than to one kid,
            // because nobody is logged in yet — there is no "you" to power up.
            'anyPoweredUp' => in_array(true, $poweredUp, true),
            // The fan is centred on the row, so the tilt is symmetrical about
            // the middle tile and the step tightens as more kids are added.
            'tiltStep' => min(6, 14 / max(1, count($kids) - 1)),
            // What each kid is wearing from the locker — a face, a frame and a
            // plate. Themes and patterns never apply here: the door belongs to
            // the house, not to whoever tapped last.
            'worn' => collect($kids)
                ->mapWithKeys(fn (Profile $kid): array => [$kid->id => $cosmetics->worn($kid)])
                ->all(),
            // The pets, one per kid who has one out. `home` is a fraction of the
            // row's width rather than a pixel count, so each pen still lines up
            // with its kid's tile however the row wraps.
            // Each at its own kid's stage, so a baby looks like one here too.
            'pets' => collect($kids)
                ->map(fn (Profile $kid) => app(App\Services\PetService::class)->spriteFor($kid))
                ->map(fn (?array $pet, int $index) => $pet === null ? null : [
                    ...$pet,
                    'home' => round(($index + 0.5) / max(1, count($kids)), 4),
                    'roam' => 64,
                    // Every pet has its toy out on the door. The powered-up
                    // rule — the toy drops in with the day's first chore — is
                    // for a kid's own pages, once they are in.
                    'toy' => true,
                    // An egg is a solid, wide thing, and at a pet's size it sat
                    // right over its kid's tile. Smaller here, on the door.
                    ...(isset($pet['egg']) ? ['scale' => round($pet['scale'] * 0.6, 3)] : []),
                ])
                ->filter()
                ->values(),
            'parents' => Profile::query()
                ->where('role', ProfileRole::Parent)
                ->orderBy('id')
                ->get(),
        ];
    }
}; ?>

<div class="mx-auto flex max-w-[560px] flex-col gap-[34px] px-5 pt-16 pb-10">
    @if ($anyPoweredUp)
        <x-shooting-stars />
    @endif

    <div class="flex flex-col items-center gap-[14px] text-center">
        <p class="font-mono-fq text-[11px] tracking-[0.34em] text-fq-cyan uppercase">Family Operations</p>
        <h1 class="fq-wordmark font-baloo text-[clamp(38px,11vw,54px)] leading-none font-extrabold">
            {{ config('app.name') }}
        </h1>
        <p class="font-baloo text-xl font-bold text-fq-text-3">Select your avatar</p>
    </div>

    {{-- The pets, one per kid who has one out, each penned around its own tile
         so the row reads as one animal per child. Petting works; dragging does
         not — this page is public, and a stranger who found the URL should not
         be able to rearrange the family's pets.

         `home` is a fraction of the row's width rather than a pixel, so the
         pens still line up with the tiles when the row wraps on a phone. --}}
    <div class="relative flex flex-wrap justify-center gap-x-[14px] gap-y-4">
        @if ($pets->isNotEmpty())
            <fq-pets sheets="{{ $pets->toJson(JSON_UNESCAPED_SLASHES) }}"></fq-pets>
        @endif

        @foreach ($kids as $i => $kid)
            @php
                $accent = $kid->color->cssVar();
                $angle = round(($i - (count($kids) - 1) / 2) * $tiltStep, 1);
            @endphp

            {{-- The idle bob rides the anchor, not the tile: the tile's
                 transform is already carrying the fan's tilt, and an animation
                 on the same property would win and flatten the fan. Staggered
                 off the index so the row breathes rather than pulsing in
                 lockstep. --}}
            <a
                href="{{ route('pin', $kid) }}"
                wire:navigate
                class="fq-avatar fq-avatar-idle relative flex shrink-0 grow-0 basis-[92px] flex-col items-center gap-[14px] text-fq-text"
                style="--fq-tilt: {{ $angle }}deg; animation-delay: {{ round($i * 0.45, 2) }}s"
            >
                {{-- The run, burning. A sibling of the tile rather than a child
                     of it, because a negative-z child would paint *over* the
                     tile's own background inside the overlap — the fire has to
                     be behind the whole tile, not behind its letter. --}}
                @if (($fireTiers[$kid->id] ?? 0) > 0)
                    <span
                        class="fq-streak-fire"
                        aria-hidden="true"
                        style="--fq-fire-tier: {{ $fireTiers[$kid->id] }}"
                    ></span>
                @endif

                {{-- The offset shadow is the accent at 58% of each channel;
                     mixing toward black in sRGB is exactly that multiply.

                     A bought frame sits over the tile and a bought face inside
                     it; the accent block stays underneath, because it is what a
                     parent points at. Under a frame the tile face gains a dark
                     7px band, so a frame never has to out-contrast the kid's own
                     colour — a gold frame on the gold kid would otherwise vanish.

                     Powered up with a frame on, the frame is the status light
                     and the rainbow ring stands down: two concentric rings read
                     as decorated rather than lit. --}}
                @php
                    $avatar = $worn[$kid->id]['avatar'];
                    $frame = $worn[$kid->id]['frame'];
                    $powered = $poweredUp[$kid->id] ?? false;
                    $plate = $worn[$kid->id]['plate'];
                @endphp
                <div
                    @class([
                        'fq-avatar-tile relative z-10 grid h-[78px] w-[78px] place-items-center rounded-[24px] border-[3px] border-fq-bg font-baloo text-[34px] font-extrabold text-fq-bg',
                        // Wider than the header's ring, because this tile
                        // already wears a 3px background-coloured border and a
                        // hairline behind that would read as a rendering
                        // artefact rather than a halo.
                        'fq-powered-token' => $powered && ! $frame,
                    ])
                    @style([
                        'background: '.$accent,
                        'box-shadow: 5px 6px 0 color-mix(in srgb, '.$accent.' 58%, #000)'.($frame ? ', inset 0 0 0 7px rgba(7, 3, 15, 0.66)' : ''),
                        '--fq-powered-inset: -7px' => $powered && ! $frame,
                    ])
                >
                    @unless ($avatar){{ mb_substr($kid->name, 0, 1) }}@endunless

                    <x-cosmetic.face
                        :avatar="$avatar"
                        :frame="$frame"
                        avatar-inset="7px"
                        frame-inset="-4px"
                        :glow="$powered && $frame ? $accent : null"
                    />
                </div>

                {{-- A first name, on whatever plate it was bought. Nothing else
                     in words: this page is public, and a level and a rank tell a
                     stranger how a named child is doing, where a bought face
                     tells them nothing. The level, the rank and the streak chip
                     all came off with the locker — see login-page-privacy. --}}
                @if ($plate && ! $plate->isFree())
                    <x-cosmetic.plate :item="$plate" class="px-[11px] py-[4px] font-baloo text-[14px] leading-none font-extrabold">{{ $kid->name }}</x-cosmetic.plate>
                @else
                    <span class="rounded-full border border-fq-line-2 bg-fq-sunk px-[11px] py-[4px] font-baloo text-[14px] leading-none font-extrabold">{{ $kid->name }}</span>
                @endif
            </a>
        @endforeach
    </div>

    @if ($parents->isNotEmpty())
        {{-- Deliberately understated and set apart from the tiles: the console
             is a door for grown-ups, not one of the avatars to pick.

             The rule is one full-width line above the links rather than
             hairlines threaded between them. Two nowrap links sharing a row
             leave the flex-1 rules nothing to occupy on a phone: they collapse
             to zero and the links run edge to edge. A rule with its own line
             can't be squeezed, and the links below wrap instead of overflowing
             however many grown-ups the house has. --}}
        <div class="mt-[6px] flex flex-col items-center gap-3">
            <span class="h-px w-full bg-fq-line"></span>

            <div class="flex flex-wrap items-center justify-center gap-x-7 gap-y-1">
                @foreach ($parents as $parent)
                    <a
                        href="{{ route('pin', $parent) }}"
                        wire:navigate
                        class="px-2 py-[10px] font-mono-fq text-[10px] tracking-[0.22em] whitespace-nowrap text-fq-text-4 uppercase transition hover:text-fq-cyan"
                    >{{ $parents->count() > 1 ? $parent->name : 'Grown-ups' }} Console &rarr;</a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- The arcade stood here, last on the page, for as long as its board held
         nothing about anybody. It carries real names now — the house's own
         scores, with a bonus-ticket prize on the week — and names on a page
         anybody with the URL can open is exactly what this page is not for.
         The game is behind the PIN in both consoles instead.

         Nothing replaces it. This page is a door, and a door with a game on it
         was always the odder of the two things it was doing. --}}
</div>
