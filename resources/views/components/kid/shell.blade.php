{{-- `refreshAction` is the Livewire method the Refresh button calls. Defaults
     to the generic `$refresh` since most kid tabs just need re-rendering; the
     Quests tab passes its own so it can also clear a stale board message. --}}
@props(['profile', 'active', 'refreshAction' => '$refresh'])

@php
    $tabs = [
        'quests' => ['label' => 'Quests', 'route' => 'kid.quests'],
        'wheel' => ['label' => 'Bonus Wheel', 'route' => 'kid.wheel'],
        'loot' => ['label' => 'Loot Shop', 'route' => 'kid.loot'],
        'offers' => ['label' => 'Deals', 'route' => 'kid.offers'],
        'bonus' => ['label' => 'Bonus Shop', 'route' => 'kid.bonus'],
        'badges' => ['label' => 'Badges', 'route' => 'kid.badges'],
    ];
    $dollars = number_format($profile->points / $profile->household->points_per_dollar, 2);

    // The count lives in the shell rather than on the Deals page itself, so a
    // kid sees a deal waiting from Quests or the Wheel — not only once they
    // have already gone looking for it.
    $offersWaiting = App\Models\SiblingOffer::where('to_profile_id', $profile->id)->live()->count();
@endphp

<div class="mx-auto max-w-[1080px] px-[14px] pb-10">
    <div class="pt-[14px] pb-[10px]">
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
                <div class="h-[6px] w-full max-w-[220px] overflow-hidden rounded-full bg-fq-track">
                    <div
                        class="h-full rounded-full"
                        style="width:{{ $profile->xpBarPercent() }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime))"
                    ></div>
                </div>
            </div>

            {{-- Every control from here right is 52px tall, so the row reads as
                 one bank of buttons rather than a ragged mix of sizes. --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex h-[52px] w-[92px] flex-col items-end justify-center rounded-[15px] border border-fq-line-2 bg-fq-sunk px-3">
                    <span class="font-baloo text-[19px] leading-none font-extrabold text-fq-lime">{{ $profile->points }}</span>
                    <span class="font-mono-fq text-[9px] text-fq-text-4">PTS · ${{ $dollars }}</span>
                </div>

                <div class="flex h-[52px] w-[86px] flex-col items-end justify-center rounded-[15px] border border-fq-line-2 bg-fq-sunk px-3">
                    <span class="font-baloo text-[19px] leading-none font-extrabold text-fq-streak">{{ $profile->streak }}d</span>
                    <span class="font-mono-fq text-[9px] text-fq-text-4">STREAK</span>
                </div>

                {{-- Same tile shape as the two above, but gold-rimmed and lit,
                     because unlike them it's a door to somewhere. --}}
                <a
                    href="{{ route('kid.bonus') }}"
                    wire:navigate
                    class="flex h-[52px] w-[86px] flex-col items-end justify-center rounded-[15px] border border-fq-ticket-line px-3 transition hover:border-fq-lime"
                    style="background: var(--fq-ticket-bg); box-shadow: var(--fq-shadow-ticket)"
                >
                    <span class="font-baloo text-[19px] leading-none font-extrabold text-fq-lime">{{ $profile->bonus_tickets }}</span>
                    <span class="font-mono-fq text-[9px] text-fq-ticket-label">TICKETS</span>
                </a>

                {{-- Pulls down points, streak, tickets and — on the Quests tab —
                     the chore board. The page already refreshes itself when it
                     regains focus, but that's invisible; this is the version a
                     kid can reach for to check nobody beat them to a chore. --}}
                <button
                    type="button"
                    wire:click="{{ $refreshAction }}"
                    wire:loading.attr="disabled"
                    wire:target="{{ $refreshAction }}"
                    title="Check for the latest points and chores"
                    aria-label="Refresh"
                    class="flex h-[52px] w-[52px] items-center justify-center rounded-[15px] border border-fq-line-2 bg-fq-sunk text-[17px] text-fq-text-4 transition hover:text-fq-text disabled:opacity-60"
                >
                    <span
                        wire:loading.class="animate-spin"
                        wire:target="{{ $refreshAction }}"
                        class="block"
                    >&#8635;</span>
                </button>

                <x-sound-toggle />

                <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                    @csrf
                    <button
                        type="submit"
                        title="Exit"
                        aria-label="Exit"
                        class="flex h-[52px] w-[52px] items-center justify-center rounded-[15px] border border-fq-line-2 bg-fq-sunk text-fq-text-4 transition hover:text-fq-text"
                    ><x-power-icon /></button>
                </form>
            </div>
        </div>

        <x-quest-board>
            @foreach ($tabs as $key => $tab)
                <a
                    href="{{ route($tab['route']) }}"
                    wire:navigate
                    class="flex flex-1 items-center justify-center gap-[6px] rounded-[14px] border border-transparent px-4 py-[10px] text-center text-sm font-semibold whitespace-nowrap"
                    style="{{ $active === $key ? 'background: var(--fq-tab-active); color: var(--fq-lime)' : 'background:transparent; color: var(--fq-ink)' }}"
                >
                    {{ $tab['label'] }}
                    @if ($key === 'offers' && $offersWaiting > 0)
                        <span
                            class="inline-flex items-center justify-center font-mono-fq font-bold"
                            {{-- Geometry inline rather than in arbitrary-value
                                 utilities: a count badge that silently degrades
                                 to a bare superscript numeral when the CSS is a
                                 build behind is worse than no badge at all. --}}
                            style="background: var(--fq-magenta); color: var(--fq-bg); min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; font-size: 11px; line-height: 1"
                            title="{{ $offersWaiting }} sibling {{ Str::plural('deal', $offersWaiting) }} waiting on you"
                        >{{ $offersWaiting }}</span>
                    @endif
                </a>
            @endforeach
        </x-quest-board>
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
