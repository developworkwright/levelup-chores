{{-- `refreshAction` is the Livewire method the Refresh button calls. Defaults
     to the generic `$refresh` since most kid tabs just need re-rendering; the
     Quests tab passes its own so it can also clear a stale board message. --}}
@props(['profile', 'active', 'refreshAction' => '$refresh'])

@php
    $pages = [
        'quests' => ['label' => 'Quests', 'glyph' => '⚑', 'route' => 'kid.quests'],
        'wheel' => ['label' => 'Bonus Wheel', 'glyph' => '◎', 'route' => 'kid.wheel'],
        'loot' => ['label' => 'Loot Shop', 'glyph' => '◈', 'route' => 'kid.loot'],
        'goal' => ['label' => 'Goals', 'glyph' => '◔', 'route' => 'kid.goal'],
        'offers' => ['label' => 'Trades', 'glyph' => '⇄', 'route' => 'kid.offers'],
        'bonus' => ['label' => 'Bonus Shop', 'glyph' => '✦', 'route' => 'kid.bonus'],
        'badges' => ['label' => 'Badges', 'glyph' => '★', 'route' => 'kid.badges'],
        'stats' => ['label' => 'Stats', 'glyph' => '▤', 'route' => 'kid.stats'],
    ];

    /*
     * Eight pages grouped into three ideas a kid already has words for. Trades
     * sits in both Earn and Spend because points flow both ways. Each world's
     * pill row is justified under its own rail button, so the pages stay
     * visually attached to the world that opened them.
     */
    $worlds = [
        'earn' => ['label' => 'Earn', 'glyph' => '⚑', 'justify' => 'justify-start', 'pages' => ['quests', 'wheel', 'offers']],
        'spend' => ['label' => 'Spend', 'glyph' => '◈', 'justify' => 'justify-center', 'pages' => ['loot', 'offers', 'bonus']],
        'me' => ['label' => 'Me', 'glyph' => '★', 'justify' => 'justify-end', 'pages' => ['goal', 'badges', 'stats']],
    ];

    $dollars = number_format($profile->points / $profile->household->points_per_dollar, 2);

    // The count lives in the shell rather than on the Trades page itself, so a
    // kid sees a trade waiting from Quests or the Wheel — not only once they
    // have already gone looking for it.
    $offersWaiting = App\Models\SiblingOffer::where('to_profile_id', $profile->id)->live()->count();
    $counts = ['offers' => $offersWaiting];

    /*
     * Which world the rail lights up. A page can belong to two worlds, so the
     * one the kid came in through wins for as long as it still holds the open
     * page, and only then does it fall back to the page's own world. It rides
     * in the session rather than the query string because a Livewire update
     * posts to its own endpoint and would arrive with the parameter stripped —
     * accepting a trade would kick the rail back to Earn mid-page.
     */
    $holdsPage = fn (mixed $world) => is_string($world)
        && isset($worlds[$world])
        && in_array($active, $worlds[$world]['pages'], true);

    $activeWorld = collect([request()->query('world'), session('kid_world')])->first($holdsPage)
        ?: collect($worlds)->search(fn (array $world) => in_array($active, $world['pages'], true)) ?: 'earn';

    session(['kid_world' => $activeWorld]);
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
            @foreach ($worlds as $key => $world)
                @php
                    // Switching worlds keeps you where you are when the page
                    // you're on belongs to both, rather than bouncing you to
                    // the top of the new one for no reason.
                    $lands = in_array($active, $world['pages'], true) ? $active : $world['pages'][0];
                    // A world carries the sum of its pages' badges, so a waiting
                    // trade is visible from any page in the console.
                    $waiting = collect($world['pages'])->sum(fn (string $page) => $counts[$page] ?? 0);
                @endphp

                <a
                    href="{{ route($pages[$lands]['route']) }}?world={{ $key }}"
                    wire:navigate
                    class="flex flex-1 items-center justify-center gap-2 rounded-[14px] border border-transparent px-[14px] py-[11px] text-center text-[15px] font-bold whitespace-nowrap"
                    style="{{ $activeWorld === $key ? 'background: var(--fq-tab-active); color: var(--fq-lime)' : 'background:transparent; color: var(--fq-ink)' }}"
                >
                    <span class="text-sm">{{ $world['glyph'] }}</span>{{ $world['label'] }}
                    <x-count-badge
                        :count="$waiting"
                        :title="$waiting.' sibling '.Str::plural('trade', $waiting).' waiting on you'"
                    />
                </a>
            @endforeach
        </x-quest-board>

        {{-- The open world's pages, justified under the rail button that opened
             them. No `world` parameter on these: the session already holds the
             world, and a pill never changes it. --}}
        <nav
            aria-label="Pages in {{ $worlds[$activeWorld]['label'] }}"
            class="mt-[10px] flex flex-wrap gap-[6px] px-[2px] sm:gap-2 {{ $worlds[$activeWorld]['justify'] }}"
        >
            @foreach ($worlds[$activeWorld]['pages'] as $key)
                @php $open = $active === $key; @endphp

                <a
                    href="{{ route($pages[$key]['route']) }}"
                    wire:navigate
                    {{-- On a phone the pills split the row evenly, so every page
                         is a thumb-sized target rather than a word-sized one;
                         the padding and type step down to keep all three on one
                         line. From `sm` up they go back to sizing to their own
                         label, which is what lets the row sit justified under
                         the world button that opened it. The label is never
                         wrapped or shrunk in either mode. --}}
                    class="inline-flex flex-1 items-center justify-center gap-[6px] rounded-full border px-3 py-[11px] text-[13px] whitespace-nowrap transition hover:border-fq-line-focus sm:flex-none sm:gap-2 sm:px-[22px] sm:py-[10px] sm:text-sm {{ $open ? 'border-fq-line-focus font-semibold text-fq-text' : 'border-fq-line text-fq-text-3' }}"
                    style="background: {{ $open ? 'var(--fq-tab-active)' : 'var(--fq-panel)' }}"
                    @if ($open) aria-current="page" @endif
                >
                    <span class="{{ $open ? 'text-fq-gold' : 'text-fq-text-4' }}">{{ $pages[$key]['glyph'] }}</span>
                    {{ $pages[$key]['label'] }}
                    <x-count-badge
                        :count="$counts[$key] ?? 0"
                        :title="($counts[$key] ?? 0).' sibling '.Str::plural('trade', $counts[$key] ?? 0).' waiting on you'"
                        small
                    />
                </a>
            @endforeach
        </nav>
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
