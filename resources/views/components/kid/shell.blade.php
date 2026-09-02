{{-- `refreshAction` is the Livewire method the Refresh button calls. Defaults
     to the generic `$refresh` since most kid tabs just need re-rendering; the
     Quests tab passes its own so it can also clear a stale board message. --}}
@props(['profile', 'active', 'refreshAction' => '$refresh'])

@php
    /*
     * Every page in the console, flat.
     *
     * There are no worlds any more. A page belongs to nothing — it is a place,
     * and this map is the one list of them. The sheet enumerates it, the rail
     * and the segment rows look their labels and icons up in it, and adding a
     * thirteenth page means adding one row here and naming it in $sheetGroups.
     * Nothing has to be decided about where it "lives".
     *
     * `short` is the label a page wears as a segment, where two have to share
     * a 390px row; `accent` is its icon colour in the sheet, which is what
     * makes twelve rows scannable before any of them are read.
     */
    $pages = [
        'home' => ['label' => 'Home', 'icon' => 'fa-house', 'route' => 'kid.home', 'accent' => 'var(--fq-cyan)'],
        'quests' => ['label' => 'Quests', 'icon' => 'fa-flag', 'route' => 'kid.quests', 'accent' => 'var(--fq-cyan)'],
        // Restocks were the thing nobody ever found: the shop is a long shelf,
        // the kids don't read it end to end, and a new reward was invisible.
        // The word rides on the count because "3" alone reads as a chore.
        'loot' => ['label' => 'Loot Shop', 'short' => 'Loot', 'icon' => 'fa-gem', 'route' => 'kid.loot', 'accent' => 'var(--fq-blue)', 'countWord' => 'new'],
        'bonus' => ['label' => 'Bonus Shop', 'short' => 'Bonus', 'icon' => 'fa-star', 'route' => 'kid.bonus', 'accent' => 'var(--fq-gold)'],
        'household' => ['label' => 'Household', 'short' => 'House', 'icon' => 'fa-ranking-star', 'route' => 'kid.household', 'accent' => 'var(--fq-green)'],
        'trades' => ['label' => 'Trades & Jobs', 'short' => 'Trades', 'icon' => 'fa-right-left', 'route' => 'kid.trades', 'accent' => 'var(--fq-coral)'],
        'journal' => ['label' => 'Journal', 'icon' => 'fa-feather', 'route' => 'kid.journal', 'accent' => 'var(--fq-green)'],
        // `new` is a flag rather than something the kid's row remembers: the
        // arcade is news for as long as it is news, and taking the rim off is
        // a one-line change here rather than a column and a migration.
        'arcade' => ['label' => 'Arcade', 'icon' => 'fa-gamepad', 'route' => 'kid.arcade', 'accent' => 'var(--fq-green)', 'new' => true],
        'stats' => ['label' => 'Stats', 'icon' => 'fa-chart-simple', 'route' => 'kid.stats', 'accent' => 'var(--fq-magenta)'],
        'goal' => ['label' => 'Goals', 'icon' => 'fa-bullseye', 'route' => 'kid.goal', 'accent' => 'var(--fq-magenta)'],
        'badges' => ['label' => 'Badges', 'icon' => 'fa-award', 'route' => 'kid.badges', 'accent' => 'var(--fq-coral)'],
        // The twelfth, and the page that proved the sheet was worth landing:
        // under the old rail it had to be filed inside "Me" to exist at all.
        // Playing music is the header's job from every page; this is only
        // where the lists get built, which is a now-and-then errand.
        'music' => ['label' => 'Music', 'icon' => 'fa-music', 'route' => 'kid.music', 'accent' => 'var(--fq-blue)'],
    ];

    /*
     * The rail: four real pages and, beside them, the sheet.
     *
     * Every button navigates. That is the whole point of the change — the old
     * rail's "Spend" opened a second row of pills instead of a page, so half
     * the buttons went somewhere and half revealed something, and a row that
     * appeared and disappeared moved the page under the kid as they browsed.
     *
     * A button holding two pages lights for either of them and lands on the
     * one you are already on, so tapping the button you are standing on can
     * never move you. Its two pages are reached from the segment row below,
     * which is a switcher rather than a folder.
     *
     * A constant, not derived: which four pages a six-year-old should reach
     * without reading is a decision, and deriving it from the page map would
     * quietly re-make that decision every time a page was added.
     */
    $rail = [
        ['label' => 'Home', 'icon' => 'fa-house', 'pages' => ['home']],
        ['label' => 'Quests', 'icon' => 'fa-flag', 'pages' => ['quests']],
        ['label' => 'Shop', 'icon' => 'fa-gem', 'pages' => ['loot', 'bonus']],
        /*
         * The fourth slot was House — Household and the trades — and it is the
         * Arcade instead.
         *
         * Not because Household matters less, but because a rail button is for
         * the thing a kid opens the app *to do*, and those two are things that
         * happen to them: a sibling sends a swap, a monster loses some health,
         * and either way the news finds them through a count or a card on Home.
         * The arcade is the opposite — nothing about it ever comes looking, so
         * it is worth nothing at all unless it is one tap from everywhere.
         *
         * Both keep their rows in the sheet under "The house", and the trades
         * count that used to ride on this button moved onto the sheet's own
         * glyph, which is exactly what $sheetCount below is for.
         */
        ['label' => 'Arcade', 'icon' => 'fa-gamepad', 'pages' => ['arcade']],
    ];

    /*
     * The sheet's headings. Editorial, not structural: they carry no routing
     * and no state, and exist only so twelve rows can be skimmed. A page under
     * the wrong heading costs a moment's hunting, which is exactly why the
     * sheet is allowed to hold every page in the app.
     */
    $sheetGroups = [
        'Every day' => ['home', 'quests', 'loot', 'journal'],
        'The house' => ['household', 'trades'],
        'Now and then' => ['bonus', 'arcade', 'music'],
    ];

    // The tail of the last group, two to a row — a footnote rather than four
    // more equal destinations. Exit is drawn alongside them by the sheet.
    $sheetGrid = ['stats', 'goal', 'badges'];

    $dollars = number_format($profile->points / $profile->household->points_per_dollar, 2);

    // The count lives in the shell rather than on the Trades page itself, so a
    // kid sees a trade waiting from Quests or Home — not only once they have
    // already gone looking for it.
    $offersWaiting = App\Models\SiblingOffer::where('to_profile_id', $profile->id)->live()->count();

    // One page now, so one count: swaps sent to this kid plus jobs stuck
    // behind them. The job half is the Quests page's bounty-board pill too, so
    // it lives in the service rather than being written out twice.
    // Loot joins it because new rewards were the thing nobody ever found: a
    // number on the tab is seen before the page is.
    $counts = [
        'trades' => $offersWaiting + app(App\Services\BountyService::class)->waitingOn($profile),
        'loot' => app(App\Services\StoreService::class)->newCountFor($profile),
    ];

    /*
     * The open page's siblings, if it has any. Derived from the rail rather
     * than written out again: a segment row is exactly "the other pages behind
     * the button I am standing on". A rail button holding one page draws no
     * row at all — a lone segment marked as the open page is a control with
     * nowhere to go.
     */
    $segmentGroup = collect($rail)->first(
        fn (array $button) => count($button['pages']) > 1 && in_array($active, $button['pages'], true)
    );

    $segments = $segmentGroup['pages'] ?? [];

    // What the sheet glyph carries: anything waiting that no rail button is
    // already showing. It was zero while the rail held House, and this is the
    // half of swapping that button for the Arcade that had to keep working —
    // a swap from a sibling is now news that arrives on the ☰ or nowhere.
    $railPages = collect($rail)->flatMap(fn (array $button) => $button['pages'])->all();
    $sheetCount = collect($counts)->reject(fn (int $count, string $key) => in_array($key, $railPages, true))->sum();

    // The journal's row in the sheet advertises the payout rather than a
    // backlog, and only while today's entry is still unwritten.
    $journalTickets = app(App\Services\GratitudeService::class)->isAvailable($profile)
        ? App\Services\GratitudeService::TICKETS
        : 0;

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

    /*
     * Quotes. Two kinds of news off one marker, because from the kid's side
     * they are the same question: what happened with the quotes while I wasn't
     * looking? A quote written down anywhere in the house, and anybody reacting
     * to one of *theirs*.
     *
     * A push already goes out the moment a quote is saved, and this is not a
     * duplicate of it — it is what catches the kid with notifications off, or
     * the one who was asleep. Without it the only way to find a new quote is to
     * scroll to the bottom of Home, which is exactly where the card sits and
     * exactly why nobody would.
     *
     * Reactions get the soft style; a new quote gets confetti. Neither is
     * `big`: a level and a monster are set pieces, and a joke that took over
     * the screen would be the app overselling itself.
     */
    $quoteNews = $profile->quotes_seen_at === null
        ? ['quotes' => collect(), 'reactions' => collect()]
        : app(App\Services\QuoteService::class)->newsFor($profile, $profile->quotes_seen_at);

    if ($profile->quotes_seen_at === null || $quoteNews['quotes']->isNotEmpty() || $quoteNews['reactions']->isNotEmpty()) {
        $markers['quotes_seen_at'] = now();
    }

    /*
     * One card, however many quotes are waiting.
     *
     * A kid back after a weekend can have four of these, and four cards fired
     * one after another turns a nice surprise into something to sit through —
     * the queue holds each for three seconds, so the fourth joke lands on a kid
     * who stopped looking at the second. The single-quote card is unchanged;
     * everything past one collapses into a card that leads with the newest and
     * says how many more are behind it.
     *
     * The newest rather than the oldest, because it is the one most likely to
     * be about today, and because the rest are a scroll away on the Quote Wall.
     * `newsFor()` orders by id, so last() is the freshest.
     */
    $newQuotes = $quoteNews['quotes'];
    $newest = $newQuotes->last();
    $mineCount = $newQuotes->filter(fn ($row) => $row->profile_id === $profile->id)->count();

    if ($newQuotes->isNotEmpty()) {
        $quietRewards->push([
            // Being quoted is the better half of this feature, so it keeps its
            // own line rather than "Someone said something" with your own name
            // in it — and the combined card still says when one of them is
            // yours, which is the part a kid actually wants to know.
            'message' => match (true) {
                $newQuotes->count() > 1 && $mineCount > 0 => $newQuotes->count().' new quotes, including yours!',
                $newQuotes->count() > 1 => $newQuotes->count().' new quotes!',
                $mineCount > 0 => 'Your line got written down!',
                default => $newest->attribution().' said something!',
            },
            'big' => false,
            'style' => 'confetti',
            'card' => [
                'accent' => 'var(--fq-gold)',
                // Always the feature's own name, whoever said it. The card's
                // kicker is what the thing *is*, not who it happened to —
                // that's the toast's job, one line above.
                'sub' => 'Quote of the Day',
                // Trimmed: the card draws its label at 28px, and a rambling
                // three-line quote pushes the note off the bottom of it. The
                // whole thing is a scroll away on the Quote Wall.
                'label' => Str::limit($newest->text, 90),
                'note' => '— '.$newest->attribution()
                    .($newQuotes->count() > 1 ? ' · +'.($newQuotes->count() - 1).' more on the Quote Wall' : ''),
            ],
        ]);
    }

    // Grouped by quote, so three siblings piling onto one line is one card
    // rather than three. Keyed on the quote id because a kid can have several
    // quotes reacted to between visits.
    foreach ($quoteNews['reactions']->groupBy('quote_id') as $reactions) {
        $quote = $reactions->first()->quote;

        if (! $quote) {
            continue;
        }

        $count = $reactions->count();

        $quietRewards->push([
            'message' => $count === 1
                ? $reactions->first()->profile?->name.' reacted to your quote!'
                : 'Your quote got '.$count.' reactions!',
            'big' => false,
            // Soft, and the one place in the app that voice is right: nothing
            // was earned here, somebody just liked something you said.
            'style' => 'heart',
            'card' => [
                'accent' => 'var(--fq-magenta)',
                'sub' => 'Your Quote',
                'label' => Str::limit($quote->text, 90),
                // The faces and the names, which is the whole reward — knowing
                // your sister laughed, not that a counter went up.
                'note' => $reactions
                    ->map(fn ($row) => $row->reaction->emoji().' '.($row->profile?->name ?? 'Someone'))
                    ->implode(' · '),
            ],
        ]);
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

    // Tonight's clock, for the header tile. In the shell rather than on the
    // pages because the question it answers — how long have I got — is one a
    // kid has while standing on the shop or the badge wall, not only on Home.
    $streakWindow = app(App\Services\StreakService::class)->streakWindowFor($profile);
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
    <div class="flex flex-col gap-[9px] pt-[14px] pb-[10px]">
        {{-- Shorter than it was: the badge count left for Me and the sheet, and
             the mute, the alerts bell and Exit left for the sheet — none of
             them is anything a kid reaches for mid-day. The XP bar was dropped
             with them and has been put back; see it below for why that one was
             the wrong call.

             What is here is the identity, the three numbers, the refresh and
             the music. It is one row on anything wider than a phone and two on
             a phone, by design rather than by luck: the identity and the bank
             are separate flex lines, so a narrow screen drops the whole bank
             onto its own row instead of crushing a kid's name down to two
             letters. Nothing shrinks to make that happen — every tile is
             `shrink-0`, and the wrap is what gives instead.

             Everything in here is phone-sized with an `md:` twin at full size.
             That is not decoration: the compact sizes exist to survive 390px,
             and the first landing of this row shipped them at *every* width,
             which put a 34px header on a 1080px page and read as a phone
             screenshot pasted into a desktop. If a control is added here, it
             needs both sizes or it will look wrong on one of them. --}}
        <div class="flex flex-wrap items-center gap-x-[8px] gap-y-[7px] rounded-[16px] border border-fq-nav-line bg-fq-panel px-[10px] py-[9px] md:gap-x-[10px] md:rounded-[22px] md:px-[14px] md:py-[12px]">
            <span
                class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[11px] font-baloo text-[15px] font-extrabold text-fq-bg md:h-[46px] md:w-[46px] md:rounded-[15px] md:text-[20px]"
                style="background:{{ $profile->color->cssVar() }}"
            >{{ mb_substr($profile->name, 0, 1) }}</span>

            @php $rank = $profile->rank(); @endphp

            {{-- `basis` rather than a bare `flex-1`, because with wrapping on,
                 the basis is what decides where the line breaks: at 110px the
                 bank stays beside the name on a tablet and drops below it on a
                 phone, which is the behaviour this row wants. --}}
            <div class="min-w-0 flex-1 basis-[110px]">
                <div class="truncate font-baloo text-[14.5px] font-bold md:text-[19px]">{{ $profile->name }}</div>
                {{-- The number alone never changed colour, so it never read as
                     progress. The rank does: it repaints every fifth level, and
                     the title is what a kid actually calls themselves.

                     The top rank's colours move, and they can only do that from
                     the keyframes — an inline colour would outrank them and
                     freeze the whole thing on one hue, which is why the style
                     attribute is withheld for exactly that case. --}}
                <div
                    @class([
                        'mt-[1px] truncate font-mono-fq text-[8px] tracking-[0.11em] uppercase md:text-[10px]',
                        'fq-rainbow-ink' => $rank->isAnimated(),
                    ])
                    @if (! $rank->isAnimated()) style="color:{{ $rank->ringVar() }}" @endif
                >{{ $rank->label() }} · LVL {{ $profile->level() }}</div>

                {{-- The XP bar, put back.

                     The one-row header dropped it on the argument that the rank
                     chip above already carries progress — which is true of the
                     *rank* and not of the level: the chip repaints every fifth
                     level and says nothing at all on the four in between. The
                     bar is the only thing on any page that shows a kid they are
                     part of the way to the next one, and that is the number
                     they are actually climbing. --}}
                <div class="mt-[4px] h-[4px] w-full max-w-[220px] overflow-hidden rounded-full bg-fq-track md:h-[6px]">
                    <div
                        class="h-full rounded-full"
                        style="width:{{ $profile->xpBarPercent() }}%;background:linear-gradient(90deg, var(--fq-cyan), var(--fq-lime))"
                    ></div>
                </div>
            </div>

            {{-- The bank, and one flex item rather than five, so it wraps as a
                 block. `ml-auto` keeps it hard right on the line it lands on,
                 whichever line that is. --}}
            <div class="ml-auto flex items-center gap-[8px] md:gap-[10px]">
            {{-- The two currency tiles announce their own changes: a chest that
                 pays out while the counter silently swaps to a new number reads
                 as having paid nothing at all. --}}
            <span
                x-data="fqTicker"
                data-fq-value="{{ $profile->points }}"
                {{-- The bump rides on :class for the same reason as the rank's
                     colour: a :style binding is one more thing that rewrites
                     the attribute out from under a morph. --}}
                :class="delta !== 0 ? 'fq-bump' : ''"
                class="relative flex shrink-0 flex-col items-end rounded-[10px] border border-fq-line bg-fq-sunk px-[8px] py-[5px] md:rounded-[15px] md:px-3 md:py-[8px]"
            >
                <span
                    {{-- Entirely Alpine's, so Livewire is told to leave it
                         alone. x-show hides by writing style.display, and a
                         morph rewriting the style attribute from the server
                         markup wipes it — which stranded a chip reading 0 above
                         every tile on a refresh. For the same reason the colour
                         rides on :class: a :style binding would clobber it from
                         the other side. --}}
                    wire:ignore
                    x-show="delta !== 0"
                    x-text="deltaLabel"
                    :class="delta < 0 ? 'text-fq-coral' : 'text-fq-green'"
                    class="pointer-events-none absolute -top-[7px] right-1 font-baloo text-[12px] leading-none font-extrabold"
                    style="animation: fq-rise 1.6s ease-out both"
                ></span>

                <span class="font-baloo text-[14px] leading-none font-extrabold md:text-[19px]" style="color:var(--fq-green)">{{ $profile->points }}</span>
                <span class="mt-[2px] font-mono-fq text-[7px] whitespace-nowrap text-fq-text-4 md:mt-[3px] md:text-[9px]">PTS · ${{ $dollars }}</span>
            </span>

            {{-- Same tile shape as the one beside it, but gold-rimmed, because
                 unlike it this one is a door to somewhere. --}}
            <a
                href="{{ route('kid.bonus') }}"
                wire:navigate
                x-data="fqTicker"
                data-fq-value="{{ $profile->bonus_tickets }}"
                :class="delta !== 0 ? 'fq-bump' : ''"
                title="Your bonus tickets"
                class="relative flex shrink-0 flex-col items-end rounded-[10px] border border-fq-ticket-line px-[8px] py-[5px] transition hover:border-fq-lime md:rounded-[15px] md:px-3 md:py-[8px]"
                style="background:var(--fq-gold-fill)"
            >
                <span
                    wire:ignore
                    x-show="delta !== 0"
                    x-text="deltaLabel"
                    :class="delta < 0 ? 'text-fq-coral' : 'text-fq-green'"
                    class="pointer-events-none absolute -top-[7px] right-1 font-baloo text-[12px] leading-none font-extrabold"
                    style="animation: fq-rise 1.6s ease-out both"
                ></span>

                <span class="font-baloo text-[14px] leading-none font-extrabold text-fq-lime md:text-[19px]">{{ $profile->bonus_tickets }}</span>
                <span class="mt-[2px] font-mono-fq text-[7px] text-fq-ticket-label md:mt-[3px] md:text-[9px]">TICKETS</span>
            </a>

            {{-- The third number, and the only one that runs out. It earns its
                 place in a row this tight because the question it answers —
                 how long have I got — is one a kid has while standing on the
                 shop or the badge wall, never only on Home. --}}
            <x-kid.streak-tile
                compact
                :href="route('kid.home').'#streak'"
                :closes-at="$streakWindow['closesAt']"
                :secured="$streakWindow['secured']"
                :overtime="$streakWindow['overtime']"
            />

            {{-- Music stayed in the row while the mute and the alerts bell went
                 into the sheet, and the split is not arbitrary: those two are
                 settings, set once and forgotten, while starting a song is
                 something a kid does on whatever page they are standing on.
                 Burying the app's most-used control two taps deep to save
                 54px would have been the wrong 54px. --}}
            <x-music-toggle compact :profile="$profile" />

            {{-- Pulls down points, tickets and — on the Quests tab — the chore
                 board. The page already refreshes itself when it regains focus,
                 but that's invisible; this is the version a kid can reach for
                 to check nobody beat them to a chore. --}}
            <button
                type="button"
                wire:click="{{ $refreshAction }}"
                wire:loading.attr="disabled"
                wire:target="{{ $refreshAction }}"
                title="Check for the latest points and chores"
                aria-label="Refresh"
                class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[10px] border border-fq-line bg-fq-sunk text-[13px] text-fq-text-4 transition hover:text-fq-text disabled:opacity-60 md:h-[46px] md:w-[46px] md:rounded-[15px] md:text-[16px]"
            >
                <i
                    wire:loading.class="animate-spin"
                    wire:target="{{ $refreshAction }}"
                    class="fa-solid fa-rotate-right"
                ></i>
            </button>
            </div>
        </div>

        {{-- Four destinations and the sheet, on every page, always one row.
             The active tab is the only gold thing here, and it is a tile rather
             than the whole bar: gold is the app's bonus colour everywhere else,
             and permanent chrome shouldn't own it. --}}
        <div class="flex gap-[4px] rounded-[15px] border border-fq-nav-line bg-fq-panel p-[4px]">
            {{-- `contents` so the four links stay flex items of the bar above
                 while the sheet, which is not one of them, sits outside the
                 landmark. It is a way to everywhere rather than a fifth page,
                 and it is never drawn as active. --}}
            <nav aria-label="Pages" data-fq-rail class="contents">
            @foreach ($rail as $button)
                @php
                    $open = in_array($active, $button['pages'], true);
                    // Landing on the page you are already on, so the button you
                    // are standing on is inert rather than a trapdoor back to
                    // its first page.
                    $lands = $open ? $active : $button['pages'][0];
                    // A button carries the sum of its pages' counts, so a
                    // waiting trade is visible from anywhere in the console.
                    $waiting = collect($button['pages'])->sum(fn (string $page) => $counts[$page] ?? 0);
                @endphp

                <a
                    href="{{ route($pages[$lands]['route']) }}"
                    wire:navigate
                    @if ($open) aria-current="page" @endif
                    class="relative flex flex-1 flex-col items-center gap-[4px] rounded-[11px] border py-[9px] transition"
                    style="{{ $open ? 'background:var(--fq-gold-fill); border-color:var(--fq-ticket-line)' : 'border-color:transparent' }}"
                >
                    <i
                        class="fa-solid {{ $button['icon'] }} text-[15px]"
                        style="color:{{ $open ? 'var(--fq-lime)' : 'var(--fq-text-4)' }}"
                    ></i>
                    <span
                        class="text-[10px] leading-none {{ $open ? 'font-bold' : '' }}"
                        style="color:{{ $open ? 'var(--fq-lime)' : 'var(--fq-text-3)' }}"
                    >{{ $button['label'] }}</span>

                    @if ($waiting > 0)
                        <span
                            class="absolute top-[4px] right-[9px] inline-flex h-[14px] min-w-[14px] items-center justify-center rounded-full px-[3px] font-mono-fq text-[8.5px] leading-none font-bold"
                            style="background:var(--fq-count); color:var(--fq-count-ink)"
                            title="{{ $waiting }} {{ Str::plural('thing', $waiting) }} waiting on you"
                        >{{ $waiting }}</span>
                    @endif
                </a>
            @endforeach
            </nav>

            <x-kid.nav-sheet
                :pages="$pages"
                :active="$active"
                :counts="$counts"
                :groups="$sheetGroups"
                :grid="$sheetGrid"
                :journal-tickets="$journalTickets"
                :count="$sheetCount"
            />
        </div>

        @if ($segments !== [])
            {{-- A switcher, not a subheading. The row *replaces* the panel
                 below it rather than scrolling to a section of one long
                 document — which is what makes a 200-item loot catalogue
                 survivable, since anchoring to a Bonus heading underneath 200
                 cards would leave a kid unable to scroll back.

                 An inactive segment keeps its count. That is the point of
                 putting counts here at all. --}}
            <nav
                aria-label="{{ $segmentGroup['label'] }} pages"
                class="flex gap-[5px] rounded-[14px] border border-fq-nav-line bg-fq-panel p-[4px]"
            >
                @foreach ($segments as $key)
                    @php $open = $active === $key; @endphp

                    <a
                        href="{{ route($pages[$key]['route']) }}"
                        wire:navigate
                        {{-- Read by the scroll keeper in app.js, which is what
                             gives each panel its own scroll position. --}}
                        data-fq-segment
                        @if ($open) aria-current="page" @endif
                        class="flex flex-1 items-center justify-center gap-[7px] rounded-[10px] border py-[11px] text-[13.5px] transition"
                        style="{{ $open ? 'background:var(--fq-panel-alt); border-color:var(--fq-line-3); color:var(--fq-text)' : 'border-color:transparent; color:var(--fq-text-3)' }}"
                    >
                        <i
                            class="fa-solid {{ $pages[$key]['icon'] }} text-[13px]"
                            style="color:{{ $open ? $pages[$key]['accent'] : 'var(--fq-text-4)' }}"
                        ></i>
                        <span class="{{ $open ? 'font-bold' : '' }}">{{ $pages[$key]['short'] }}</span>
                        <x-count-badge
                            small
                            :count="$counts[$key] ?? 0"
                            :title="($counts[$key] ?? 0).' '.Str::plural('thing', $counts[$key] ?? 0).' waiting on you'"
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
