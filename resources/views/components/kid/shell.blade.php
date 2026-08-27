{{-- `refreshAction` is the Livewire method the Refresh button calls. Defaults
     to the generic `$refresh` since most kid tabs just need re-rendering; the
     Quests tab passes its own so it can also clear a stale board message. --}}
@props(['profile', 'active', 'refreshAction' => '$refresh'])

@php
    $pages = [
        'home' => ['label' => 'Home', 'glyph' => '⌂', 'route' => 'kid.home'],
        'arena' => ['label' => 'Arena', 'glyph' => '⚔', 'route' => 'kid.arena'],
        'quests' => ['label' => 'Quests', 'glyph' => '⚑', 'route' => 'kid.quests'],
        'loot' => ['label' => 'Loot Shop', 'glyph' => '◈', 'route' => 'kid.loot'],
        'goal' => ['label' => 'Goals', 'glyph' => '◔', 'route' => 'kid.goal'],
        'trades' => ['label' => 'Trades & Jobs', 'glyph' => '⇄', 'route' => 'kid.trades'],
        'bonus' => ['label' => 'Bonus Shop', 'glyph' => '✦', 'route' => 'kid.bonus'],
        'badges' => ['label' => 'Badges', 'glyph' => '★', 'route' => 'kid.badges'],
        'stats' => ['label' => 'Stats', 'glyph' => '▤', 'route' => 'kid.stats'],
        'journal' => ['label' => 'Journal', 'glyph' => '♡', 'route' => 'kid.journal'],
    ];

    /*
     * The pages grouped into ideas a kid already has words for. Each world's
     * pill row is justified under its own rail button, so the pages stay
     * visually attached to the world that opened them.
     *
     * Home is first and holds one page on purpose. It is not a world so much as
     * the way back to the front of the day, and a kid who is lost should be
     * able to reach for the first button on the rail without reading any of the
     * others. Worlds holding a single page draw no pill row at all — a lone
     * pill that is always the open page is a control that can't do anything.
     *
     * House is the reason the middle three moved. Trades used to sit in *both*
     * Earn and Spend, on the grounds that points flow both ways — true, and not
     * why a kid opens it. They open it because it is about their siblings,
     * which is what House holds: the Arena and the trades. Goals went back to
     * being personal, the family half of it having become the Arena's job.
     */
    $worlds = [
        'home' => ['label' => 'Home', 'glyph' => '⌂', 'justify' => 'justify-start', 'pages' => ['home']],
        // Just the board now. The bonus wheel moved to Home, where it is step
        // three of the day rather than a page to remember to go to.
        'earn' => ['label' => 'Earn', 'glyph' => '⚑', 'justify' => 'justify-start', 'pages' => ['quests']],
        'spend' => ['label' => 'Spend', 'glyph' => '◈', 'justify' => 'justify-center', 'pages' => ['loot', 'bonus']],
        'house' => ['label' => 'House', 'glyph' => '⚔', 'justify' => 'justify-center', 'pages' => ['arena', 'trades']],
        // Stats first, and so the world lands there: a rail button opens its
        // first page, and "Me" is a question about how you're doing rather
        // than about what you're saving for. Goals is where you go once the
        // numbers have given you a reason to.
        'me' => ['label' => 'Me', 'glyph' => '★', 'justify' => 'justify-end', 'pages' => ['stats', 'goal', 'badges', 'journal']],
    ];

    $dollars = number_format($profile->points / $profile->household->points_per_dollar, 2);

    // The count lives in the shell rather than on the Trades page itself, so a
    // kid sees a trade waiting from Quests or the Wheel — not only once they
    // have already gone looking for it.
    $offersWaiting = App\Models\SiblingOffer::where('to_profile_id', $profile->id)->live()->count();

    // One page now, so one count: swaps sent to this kid plus jobs stuck
    // behind them. The job half is the Quests page's bounty-board pill too, so
    // it lives in the service rather than being written out twice.
    // Loot joins it because new rewards were the thing nobody ever found: the
    // shop is a long shelf, the kids don't read it end to end, and a restock
    // was invisible. A number on the tab is seen before the page is.
    $counts = [
        'trades' => $offersWaiting + app(App\Services\BountyService::class)->waitingOn($profile),
        'loot' => app(App\Services\StoreService::class)->newCountFor($profile),
    ];

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
            // A badge pays in tickets, so it rains tickets. The style picks the
            // voice on its own — see CELEBRATION_VOICES.
            'style' => 'ticket',
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
        // A level that changes the title is a different event from a level
        // that doesn't, and gets announced as one — same card, but wearing the
        // new rank's colour and naming it rather than the number.
        $rankGained = App\Enums\Rank::fromLevel($level);
        $ranksGained = App\Enums\Rank::countBetween($profile->level_seen ?? $level, $level);
        $rankedUp = $ranksGained > 0;

        $quietRewards->push([
            'message' => $rankedUp ? 'You are a '.$rankGained->label().'!' : 'Level '.$level.'!',
            'big' => true,
            'style' => 'star',
            // Fired up from the bottom corners rather than dropped from the
            // top: a level is the one reward that is unambiguously about
            // going up, and rain says the opposite of that.
            'motion' => 'cannon',
            'hero' => 'level',
            'card' => [
                'accent' => $rankedUp ? $rankGained->ringVar() : 'var(--fq-gold)',
                'sub' => $rankedUp ? 'Rank Up' : 'Level Up',
                'label' => $rankedUp ? $rankGained->label() : 'Level '.$level,
                'note' => $tickets(
                    $levelsGained * App\Services\TicketService::PER_LEVEL
                        + $ranksGained * App\Services\TicketService::PER_RANK
                ).($rankedUp ? ' · Level '.$level : ''),
            ],
        ]);
    }

    // Queued by ChoreService when a parent's approval won the mystery bonus.
    // The find is announced here rather than on the tap that submitted the
    // chore: telling a kid at claim time which chore carried the bonus made
    // submitting the whole board a way of being told the answer.
    if ($profile->pending_mystery_celebration !== null) {
        $quietRewards->push([
            'message' => 'You found the Mystery Chore!',
            'big' => true,
            // Thrown outward from the middle of the screen, where the card
            // announcing it is — nothing was tapped to earn this, so there is
            // no better origin than the news itself.
            'motion' => 'burst',
            'card' => [
                'accent' => 'var(--fq-magenta)',
                'sub' => 'Mystery Chore',
                'label' => $profile->pending_mystery_celebration,
                'note' => '+'.number_format(App\Services\ChoreService::MYSTERY_BONUS_POINTS).' pts',
            ],
        ]);

        $markers['pending_mystery_celebration'] = null;
    }

    // Every monster that has fallen since this kid last looked. A list, because
    // a kid can be days late to any of it.
    //
    // Everything the card says was stamped at the kill, not looked up now: by
    // the time a kid reads this a parent has very likely stood the next monster
    // up, and asking the arena who died would name the wrong one.
    foreach ($profile->pending_monster_kills ?? [] as $kill) {
        $blow = $kill['finisher'] ? $kill['finisher'].' landed the final blow' : 'Everyone pulled together';

        // What this kid personally walked away with, and why. Named rather than
        // just totalled: "+5 tickets" alone reads as a number the app decided
        // on, while "final blow · most damage" is the reason they earned it and
        // the thing worth telling a sibling about.
        $why = collect([
            ($kill['finisherBonus'] ?? false) ? 'final blow' : null,
            ($kill['topDamageBonus'] ?? false) ? 'most damage' : null,
        ])->filter();

        $payout = ($kill['tickets'] ?? 0) > 0
            ? $tickets($kill['tickets']).($why->isNotEmpty() ? ' · '.$why->implode(' · ') : '')
            : null;

        $quietRewards->push([
            'message' => $kill['name'].' is down!',
            // The rarest thing that happens in the app, and the only one the
            // whole household worked on — so it gets the tier nothing else
            // uses, and shells going off across the screen rather than one
            // burst.
            'tier' => 'epic',
            'motion' => 'fireworks',
            'sound' => 'impact',
            'hero' => 'boss',
            'card' => [
                'accent' => 'var(--fq-lime)',
                'sub' => 'Monster Defeated',
                'label' => $kill['name'],
                // The monster is the headline, the reward is why anyone was
                // fighting it, and the tickets are what this particular kid got
                // out of it — beating Gnash otherwise reads as its own prize
                // rather than as ice cream.
                'note' => collect([$blow, $kill['reward'], $payout])->filter()->implode(' · '),
                'skin' => $kill['skin'],
            ],
        ]);
    }

    if ($profile->pending_monster_kills) {
        $markers['pending_monster_kills'] = null;
    }

    // One write, and only when something actually moved — the shell renders on
    // every round trip and most of them have nothing to report.
    if ($markers !== []) {
        $profile->forceFill($markers)->save();
    }

    // The constellations this kid has earned, behind every page of theirs. Only
    // for a kid the own-bed card is switched on for — nobody else has a sky,
    // and an empty one renders nothing at all.
    $sleep = app(App\Services\SleepService::class);
    $sky = $sleep->isEnabledFor($profile) ? $sleep->earnedConstellations($profile) : [];
@endphp

{{-- `isolate` so the watching monster's negative z-index puts it behind the
     page's content without dropping it behind the page background entirely. --}}
<div class="relative isolate mx-auto max-w-[1080px] px-[14px] pb-10">
    {{-- Inside the isolate, like the watching monster: a negative z-index puts
         it behind the page's content without dropping it behind the page
         background entirely. --}}
    <x-kid.sky :constellations="$sky" />
    {{-- Keyed on what it's announcing, so Livewire tears the element down and
         builds a new one whenever the news changes — which is what re-runs
         x-init. A re-render carrying nothing new renders nothing at all. --}}
    {{-- Deferred a tick, and this matters more than it looks.

         Alpine initialises roots in document order and runs x-init as it goes,
         so on a fresh page load this fired while <x-overlays> — further down the
         layout — had not yet registered the window listener that catches it.
         The event went nowhere. Worse, the marker saying "this kid has been
         told" is cleared by the render that produced this element, so the news
         wasn't retried: it was lost outright. It only ever worked when the
         rewards arrived over a Livewire round trip, with the overlay already
         alive from an earlier load — which is why the kid sitting on the app
         when a goal was finished saw it and the one logging in fresh did not.

         $nextTick runs after every root is initialised. The layout also now
         puts the overlay ahead of the page, so this has two independent reasons
         to work rather than one. --}}
    @if ($quietRewards->isNotEmpty())
        <div
            wire:key="rewards-{{ md5($quietRewards->pluck('message')->implode('|')) }}"
            x-data
            x-init="$nextTick(() => $dispatch('rewards-earned', { rewards: @js($quietRewards->all()) }))"
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
                    {{-- The number alone never changed colour, so it never read
                         as progress. The rank does: the chip repaints every
                         fifth level, and the title is what a kid actually
                         calls themselves. --}}
                    @php $rank = $profile->rank(); @endphp
                    <span
                        @class([
                            'flex items-center gap-[5px] rounded-[6px] border px-[7px] py-[3px] font-mono-fq text-[10px]',
                            'fq-rank-doomlord' => $rank->isAnimated(),
                        ])
                        style="background:{{ $rank->fillVar() }};border-color:{{ $rank->ringVar() }};color:{{ $rank->ringVar() }}"
                    >
                        <span class="tracking-[0.12em] uppercase">{{ $rank->label() }}</span>
                        <span class="opacity-60">LVL {{ $profile->level() }}</span>
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

                {{-- Same tile shape as the points tile, but gold-rimmed and lit,
                     because unlike it this one is a door to somewhere. --}}
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

                {{-- Pulls down points, tickets and — on the Quests tab —
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

                {{-- Alerts belong in the header rather than on a page of their
                     own. Everything a kid can be notified about — work signed
                     off, a trade, new loot on the shelves — happens while
                     somebody else is acting and the app is shut, so there is no
                     one page it is about; and like the refresh button beside
                     it, this is a switch you want to see the state of from
                     wherever you happen to be standing. --}}
                <livewire:push-toggle audience="kid" />

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
                    {{-- Home made the rail five buttons wide, which is one more
                         than a 375px phone can hold at the old size. The glyph
                         is what gives way rather than the label: a kid picks
                         the button by the word on it, and a rail that wraps to
                         two rows stops reading as one bank of controls. --}}
                    class="flex flex-1 items-center justify-center gap-[6px] rounded-[14px] border border-transparent px-[6px] py-[10px] text-center text-[13px] font-bold whitespace-nowrap sm:gap-2 sm:px-[14px] sm:py-[11px] sm:text-[15px]"
                    style="{{ $activeWorld === $key ? 'background: var(--fq-tab-active); color: var(--fq-lime)' : 'background:transparent; color: var(--fq-ink)' }}"
                >
                    <span class="hidden text-sm sm:inline">{{ $world['glyph'] }}</span>{{ $world['label'] }}
                    {{-- Generic wording now that the badge covers trades and
                         jobs both: a world's count is the sum of its pages'. --}}
                    <x-count-badge
                        :count="$waiting"
                        :title="$waiting.' '.Str::plural('thing', $waiting).' waiting on you'"
                    />
                </a>
            @endforeach
        </x-quest-board>

        {{-- The open world's pages, justified under the rail button that opened
             them. No `world` parameter on these: the session already holds the
             world, and a pill never changes it.

             A world holding one page draws nothing — the rail button already
             took the kid there, and a single pill marked as the open page is a
             control with nowhere to go. --}}
        @if (count($worlds[$activeWorld]['pages']) > 1)
        <nav
            aria-label="Pages in {{ $worlds[$activeWorld]['label'] }}"
            class="mt-[10px] flex flex-wrap gap-[6px] px-[2px] sm:gap-2 {{ $worlds[$activeWorld]['justify'] }}"
        >
            @php
                /*
                 * Three pills share a phone row comfortably; four don't, and
                 * left to wrap they strand the last one full-width on a row of
                 * its own. Four go 2x2 instead, which reads as a deliberate
                 * block rather than an overflow. Width rather than flex-1 in
                 * that branch, so the two rules can't fight over flex-basis.
                 *
                 * The underscores are Tailwind's escape for the spaces CSS
                 * requires around calc's minus — `calc(50%-3px)` is a syntax
                 * error, and half the row would silently go full width.
                 *
                 * `basis-0 grow` rather than `flex-1` in the three-pill case:
                 * both share the row evenly, but a label longer than its third
                 * ("Trades & Jobs", with a count badge beside it) takes its own
                 * width and wraps to a second line instead of pushing the row
                 * wider than the phone. Nothing is ever truncated either way.
                 */
                $pillWidth = count($worlds[$activeWorld]['pages']) > 3
                    ? 'w-[calc(50%_-_3px)] sm:w-auto'
                    : 'basis-0 grow';
            @endphp

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
                    class="inline-flex {{ $pillWidth }} items-center justify-center gap-[6px] rounded-full border px-3 py-[11px] text-[13px] whitespace-nowrap transition hover:border-fq-line-focus sm:flex-none sm:gap-2 sm:px-[22px] sm:py-[10px] sm:text-sm {{ $open ? 'border-fq-line-focus font-semibold text-fq-text' : 'border-fq-line text-fq-text-3' }}"
                    style="background: {{ $open ? 'var(--fq-tab-active)' : 'var(--fq-panel)' }}"
                    @if ($open) aria-current="page" @endif
                >
                    <span class="{{ $open ? 'text-fq-gold' : 'text-fq-text-4' }}">{{ $pages[$key]['glyph'] }}</span>
                    {{ $pages[$key]['label'] }}
                    <x-count-badge
                        :count="$counts[$key] ?? 0"
                        :title="($counts[$key] ?? 0).' '.Str::plural('thing', $counts[$key] ?? 0).' waiting on you'"
                        small
                    />
                </a>
            @endforeach
        </nav>
        @endif
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
