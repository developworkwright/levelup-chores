@props(['profile', 'active'])

@php
    $tabs = [
        'quests' => ['label' => 'Quests', 'route' => 'kid.quests'],
        'wheel' => ['label' => 'Bonus Wheel', 'route' => 'kid.wheel'],
        'loot' => ['label' => 'Loot Shop', 'route' => 'kid.loot'],
        'badges' => ['label' => 'Badges', 'route' => 'kid.badges'],
    ];
    $dollars = number_format($profile->points / $profile->household->points_per_dollar, 2);
@endphp

<div class="mx-auto max-w-[1080px] px-[14px] pb-10">
    <div class="top-0 z-20 pt-[14px] pb-[10px]">
        <div class="flex flex-wrap items-center gap-3 rounded-[22px] border border-fq-line bg-fq-panel p-[12px_14px]">
            <div
                class="flex h-[46px] w-[46px] shrink-0 items-center justify-center rounded-[15px] font-baloo text-xl font-extrabold text-fq-bg"
                style="background:{{ $profile->color->cssVar() }}"
            >
                {{ mb_substr($profile->name, 0, 1) }}
            </div>

            <div class="flex min-w-[140px] flex-1 flex-col gap-[6px]">
                <div class="flex items-center gap-2">
                    <span class="font-baloo text-[19px] font-bold">{{ $profile->name }}</span>
                    <span class="rounded-[6px] bg-fq-line px-[7px] py-[3px] font-mono-fq text-[10px] text-fq-cyan">
                        LVL {{ $profile->level() }}
                    </span>
                </div>
                <div class="h-[6px] w-full max-w-[220px] overflow-hidden rounded-full" style="background:#262e4f">
                    <div
                        class="h-full rounded-full"
                        style="width:{{ $profile->xpBarPercent() }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime))"
                    ></div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <div class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[7px] text-right">
                    <div class="font-baloo text-xl font-extrabold text-fq-lime">{{ $profile->points }}</div>
                    <div class="font-mono-fq text-[9px] text-fq-text-4">PTS · ${{ $dollars }}</div>
                </div>
                <div class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[7px] text-right">
                    <div class="font-baloo text-xl font-extrabold text-fq-coral">{{ $profile->streak }}d</div>
                    <div class="font-mono-fq text-[9px] text-fq-text-4">STREAK</div>
                </div>
                <x-sound-toggle />
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button
                        type="submit"
                        class="rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-[7px] text-xs text-fq-text-4 transition hover:text-fq-text"
                    >Exit</button>
                </form>
            </div>
        </div>

        <x-quest-board>
            @foreach ($tabs as $key => $tab)
                <a
                    href="{{ route($tab['route']) }}"
                    wire:navigate
                    class="flex-1 rounded-[14px] border px-4 py-[10px] text-center text-sm font-semibold whitespace-nowrap {{ $active === $key ? 'border-2 border-fq-bg bg-fq-sky text-fq-bg' : 'border-fq-line bg-fq-sunk text-fq-text-3' }}"
                >{{ $tab['label'] }}</a>
            @endforeach
        </x-quest-board>
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
