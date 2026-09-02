<?php

use App\Enums\AccentColor;
use App\Enums\ProfileRole;
use App\Models\Profile;
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

        return [
            'kids' => $kids,
            // The fan is centred on the row, so the tilt is symmetrical about
            // the middle tile and the step tightens as more kids are added.
            'tiltStep' => min(6, 14 / max(1, count($kids) - 1)),
            'parents' => Profile::query()
                ->where('role', ProfileRole::Parent)
                ->orderBy('id')
                ->get(),
        ];
    }
}; ?>

<div class="mx-auto flex max-w-[560px] flex-col gap-[34px] px-5 pt-16 pb-10">
    <div class="flex flex-col items-center gap-[14px] text-center">
        <p class="font-mono-fq text-[11px] tracking-[0.34em] text-fq-cyan uppercase">Family Operations</p>
        <h1 class="fq-wordmark font-baloo text-[clamp(38px,11vw,54px)] leading-none font-extrabold">
            {{ config('app.name') }}
        </h1>
        <p class="font-baloo text-xl font-bold text-fq-text-3">Select your avatar</p>
    </div>

    <div class="flex flex-wrap justify-center gap-x-[14px] gap-y-4">
        @foreach ($kids as $i => $kid)
            @php
                $accent = $kid->color->cssVar();
                $angle = round(($i - (count($kids) - 1) / 2) * $tiltStep, 1);
            @endphp

            <a
                href="{{ route('pin', $kid) }}"
                wire:navigate
                class="fq-avatar flex shrink-0 grow-0 basis-[92px] flex-col items-center gap-[14px] text-fq-text"
                style="--fq-tilt: {{ $angle }}deg"
            >
                {{-- The offset shadow is the accent at 58% of each channel;
                     mixing toward black in sRGB is exactly that multiply. --}}
                <div
                    class="fq-avatar-tile relative grid h-[78px] w-[78px] place-items-center rounded-[24px] border-[3px] border-fq-bg font-baloo text-[34px] font-extrabold text-fq-bg"
                    style="background: {{ $accent }}; box-shadow: 5px 6px 0 color-mix(in srgb, {{ $accent }} 58%, #000)"
                >
                    {{ mb_substr($kid->name, 0, 1) }}

                    {{-- A run only shows once there is one. "0d" is the first
                         thing a kid read off their own tile after a bad night,
                         and a scoreboard that opens by telling you that isn't
                         worth the reminder. The chip is absolutely positioned,
                         so dropping it leaves the tile exactly as it was. --}}
                    @if ($kid->streak > 0)
                        <span
                            class="fq-avatar-chip absolute right-[-10px] bottom-[-10px] rounded-full border-2 bg-fq-bg px-[7px] py-[2px] font-mono-fq text-[10px] font-semibold"
                            style="border-color: {{ $accent }}; color: {{ $accent }}"
                        >{{ $kid->streak }}d</span>
                    @endif
                </div>

                {{-- The XP bar used to sit between the name and the level, and
                     it took the eye first — which made the tile about how far
                     along a bar somebody was, a thing that creeps daily and
                     says nothing at a glance. What a kid wants off this screen
                     is where they stand, so the level is the loudest thing on
                     the tile and the rank names it underneath. How close the
                     next one is belongs on a page they can read properly. --}}
                <div class="flex w-full flex-col items-center gap-[5px]">
                    <span class="text-[15px] font-semibold">{{ $kid->name }}</span>
                    @php $rank = $kid->rank(); @endphp
                    <span
                        @class([
                            'font-baloo text-[21px] leading-none font-extrabold',
                            'fq-rainbow-ink' => $rank->isAnimated(),
                        ])
                        @style(['color: '.$rank->ringVar() => ! $rank->isAnimated()])
                    >
                        LVL {{ $kid->level() }}
                    </span>
                    <span
                        @class([
                            'font-mono-fq text-[9px] font-semibold tracking-[0.14em] uppercase',
                            'fq-rainbow-ink' => $rank->isAnimated(),
                        ])
                        @style(['color: '.$rank->ringVar() => ! $rank->isAnimated()])
                    >
                        {{ $rank->label() }}
                    </span>
                </div>
            </a>
        @endforeach
    </div>

    @if ($parents->isNotEmpty())
        {{-- Deliberately understated and set apart from the tiles: the console
             is a door for grown-ups, not one of the avatars to pick. --}}
        <div class="mt-[6px] flex items-center gap-[14px]">
            <span class="h-px flex-1 bg-fq-line"></span>

            @foreach ($parents as $parent)
                <a
                    href="{{ route('pin', $parent) }}"
                    wire:navigate
                    class="flex items-center gap-2 px-[2px] py-[6px] font-mono-fq text-[10px] tracking-[0.22em] whitespace-nowrap text-fq-text-4 uppercase transition hover:text-fq-cyan"
                >{{ $parents->count() > 1 ? $parent->name : 'Grown-ups' }} · Parent console &rarr;</a>
            @endforeach

            <span class="h-px flex-1 bg-fq-line"></span>
        </div>
    @endif

    {{-- The arcade stood here, last on the page, for as long as its board held
         nothing about anybody. It carries real names now — the house's own
         scores, with a bonus-ticket prize on the week — and names on a page
         anybody with the URL can open is exactly what this page is not for.
         The cabinet is behind the PIN in both consoles instead.

         Nothing replaces it. This page is a door, and a door with a game on it
         was always the odder of the two things it was doing. --}}
</div>
