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

    /*
     * Anything that quietly moved a balance since this kid last looked.
     *
     * It lives in the shell rather than on the pages because the shell is the
     * one thing that re-renders on every kid page load *and* every Livewire
     * round trip, whoever caused it — a chest the kid opened, a chore a parent
     * approved overnight, a family goal a sibling finished off. Each of those
     * hands over tickets, and a balance that climbs with nothing to explain it
     * reads as the app shortchanging them.
     *
     * The markers are columns rather than session keys: almost none of this
     * happens while the kid is watching, and a session only lasts as long as a
     * login does. A null marker seeds itself here without celebrating, so a kid
     * who already holds twenty badges isn't met with twenty cards.
     */
    $quietRewards = collect();
    $tickets = fn (int $count) => '+'.$count.' '.Str::plural('ticket', $count);
    $markers = [];

    $unseenBadges = $profile->badges_seen_at === null
        ? collect()
        : $profile->badges()->wherePivot('earned_at', '>', $profile->badges_seen_at)->get();

    if ($profile->badges_seen_at === null || $unseenBadges->isNotEmpty()) {
        $markers['badges_seen_at'] = now();
    }

    foreach ($unseenBadges as $badge) {
        $quietRewards->push([
            'message' => $badge->name.' badge unlocked!',
            'big' => false,
            'card' => [
                'accent' => $badge->color->cssVar(),
                'sub' => 'Badge Unlocked',
                'label' => $badge->name,
                'note' => $tickets(App\Services\TicketService::PER_BADGE)
                    .($badge->xp_reward > 0 ? ' · +'.number_format($badge->xp_reward).' XP' : ''),
            ],
        ]);
    }

    // Levels mint tickets too, and they're the one reward nothing in the app
    // ever stopped to announce — the bar just crept round and the balance went
    // up. Levels crossed together are one card rather than a queue of them.
    $level = $profile->level();
    $levelsGained = $profile->level_seen === null ? 0 : max(0, $level - $profile->level_seen);

    if ($profile->level_seen !== $level) {
        $markers['level_seen'] = $level;
    }

    if ($levelsGained > 0) {
        $quietRewards->push([
            'message' => 'Level '.$level.'!',
            'big' => true,
            'card' => [
                'accent' => 'var(--fq-gold)',
                'sub' => 'Level Up',
                'label' => 'Level '.$level,
                'note' => $tickets($levelsGained * App\Services\TicketService::PER_LEVEL),
            ],
        ]);
    }

    // Queued by ChoreService the moment a parent's approval crossed the goal —
    // which is why it survives the kid being signed out at the time.
    if ($profile->pending_goal_celebration !== null) {
        $quietRewards->push([
            'message' => 'Family goal reached!',
            'big' => true,
            'card' => [
                'accent' => 'var(--fq-lime)',
                'sub' => 'Family Goal',
                'label' => $profile->pending_goal_celebration,
                'note' => 'Everyone pulled together',
            ],
        ]);

        $markers['pending_goal_celebration'] = null;
    }

    // One write, and only when something actually moved — the shell renders on
    // every round trip and most of them have nothing to report.
    if ($markers !== []) {
        $profile->forceFill($markers)->save();
    }
@endphp

<div class="mx-auto max-w-[1080px] px-[14px] pb-10">
    {{-- Keyed on what it's announcing, so Livewire tears the element down and
         builds a new one whenever the news changes — which is what re-runs
         x-init. A re-render carrying nothing new renders nothing at all. --}}
    @if ($quietRewards->isNotEmpty())
        <div
            wire:key="rewards-{{ md5($quietRewards->pluck('message')->implode('|')) }}"
            x-data
            x-init="$dispatch('rewards-earned', { rewards: @js($quietRewards->all()) })"
        ></div>
    @endif

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
                {{-- The two currency tiles announce their own changes: a chest
                     that pays out while the counter silently swaps to a new
                     number reads as having paid nothing at all. --}}
                <div
                    x-data="fqTicker"
                    data-fq-value="{{ $profile->points }}"
                    {{-- The bump rides on :class for the same reason as the
                         chip's colour: a :style binding is one more thing that
                         rewrites the attribute out from under a morph. --}}
                    :class="delta !== 0 ? 'fq-bump' : ''"
                    class="relative flex h-[52px] w-[92px] flex-col items-end justify-center rounded-[15px] border border-fq-line-2 bg-fq-sunk px-3"
                >
                    <span
                        {{-- Entirely Alpine's, so Livewire is told to leave it
                             alone. x-show hides by writing style.display, and
                             a morph rewriting the style attribute from the
                             server markup wipes it — which stranded a chip
                             reading 0 above every tile on a refresh. For the
                             same reason the colour rides on :class: a :style
                             binding would clobber it from the other side. --}}
                        wire:ignore
                        x-show="delta !== 0"
                        x-text="deltaLabel"
                        :class="delta < 0 ? 'text-fq-coral' : 'text-fq-lime'"
                        class="pointer-events-none absolute -top-[6px] right-2 font-baloo text-[14px] leading-none font-extrabold"
                        style="animation: fq-rise 1.6s ease-out both"
                    ></span>

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
                    x-data="fqTicker"
                    data-fq-value="{{ $profile->bonus_tickets }}"
                    :class="delta !== 0 ? 'fq-bump' : ''"
                    class="relative flex h-[52px] w-[86px] flex-col items-end justify-center rounded-[15px] border border-fq-ticket-line px-3 transition hover:border-fq-lime"
                    style="background: var(--fq-ticket-bg); box-shadow: var(--fq-shadow-ticket)"
                >
                    <span
                        {{-- Entirely Alpine's, so Livewire is told to leave it
                             alone. x-show hides by writing style.display, and
                             a morph rewriting the style attribute from the
                             server markup wipes it — which stranded a chip
                             reading 0 above every tile on a refresh. For the
                             same reason the colour rides on :class: a :style
                             binding would clobber it from the other side. --}}
                        wire:ignore
                        x-show="delta !== 0"
                        x-text="deltaLabel"
                        :class="delta < 0 ? 'text-fq-coral' : 'text-fq-lime'"
                        class="pointer-events-none absolute -top-[6px] right-2 font-baloo text-[14px] leading-none font-extrabold"
                        style="animation: fq-rise 1.6s ease-out both"
                    ></span>

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
