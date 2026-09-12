@props(['profile', 'active'])

@php
    /*
     * Every page in the console, flat — the same shape the kid shell uses, and
     * for the same reason: the sheet enumerates this map, the rail looks its
     * labels and icons up in it, and a twelfth page is one row here plus a
     * name in $sheetGroups.
     *
     * `accent` is the icon colour in the sheet, which is what makes eleven rows
     * scannable before any of them are read. Where a page has a counterpart on
     * the kid side it borrows that page's icon on purpose — a parent editing
     * the board should be looking at the same flag the kids see.
     */
    $pages = [
        'home' => ['label' => 'Home', 'icon' => 'fa-house', 'route' => 'parent.home', 'accent' => 'var(--fq-cyan)'],
        'chores' => ['label' => 'Quests', 'icon' => 'fa-flag', 'route' => 'parent.chores', 'accent' => 'var(--fq-cyan)'],
        'kids' => ['label' => 'Kids & Points', 'icon' => 'fa-children', 'route' => 'parent.kids', 'accent' => 'var(--fq-magenta)'],
        'activity' => ['label' => 'Activity', 'icon' => 'fa-clock-rotate-left', 'route' => 'parent.activity', 'accent' => 'var(--fq-magenta)'],
        'loot' => ['label' => 'Loot Shop', 'icon' => 'fa-gem', 'route' => 'parent.loot', 'accent' => 'var(--fq-blue)'],
        'lucky' => ['label' => 'Lucky Block', 'icon' => 'fa-dice', 'route' => 'parent.lucky', 'accent' => 'var(--fq-gold)'],
        // The skull is the app's monster mark everywhere else — see the Lucky
        // Block's prize sources.
        'monsters' => ['label' => 'Monsters', 'icon' => 'fa-skull', 'route' => 'parent.monsters', 'accent' => 'var(--fq-violet)'],
        'standings' => ['label' => 'Standings', 'icon' => 'fa-ranking-star', 'route' => 'parent.standings', 'accent' => 'var(--fq-green)'],
        'quotes' => ['label' => 'Quotes', 'icon' => 'fa-quote-left', 'route' => 'parent.quotes', 'accent' => 'var(--fq-green)'],
        'arcade' => ['label' => 'Arcade', 'icon' => 'fa-gamepad', 'route' => 'parent.arcade', 'accent' => 'var(--fq-green)'],
        'music' => ['label' => 'Music', 'icon' => 'fa-music', 'route' => 'parent.music', 'accent' => 'var(--fq-blue)'],
    ];

    /*
     * The rail: four pages, and beside them the sheet holding all eleven.
     *
     * Eleven tabs in a wrapping strip was three rows of gold and a third of a
     * phone screen, and every one of them carried the same weight — the queue a
     * parent opens the app *for* looked exactly like the Lucky Block's prize
     * list. These four are the ones with a reason to be reached without
     * reading: the queue, the board the queue comes off, the kid you are about
     * to give or take points from, and the ledger that answers "why does she
     * have four hundred points".
     *
     * The other seven are places you go to *set the game up* — a shelf of
     * rewards, a deck of monsters, a library of songs. Those are errands, and
     * an errand can afford a second tap.
     *
     * A constant rather than something derived from $pages, so adding a page
     * doesn't quietly re-make this decision. Unlike the kid rail no button here
     * holds two pages: there is no pair of parent pages you flip between the
     * way a kid flips between the two shops, and a segment row would be putting
     * back the chrome this change is removing.
     */
    $rail = ['home', 'chores', 'kids', 'activity'];

    /*
     * The sheet's headings. Editorial, not structural: they carry no routing
     * and no state, and exist only so eleven rows can be skimmed.
     */
    $sheetGroups = [
        'Every day' => ['home', 'chores', 'kids', 'activity'],
        'Set up the game' => ['loot', 'lucky', 'monsters'],
        'Now and then' => [],
    ];

    // The tail, two to a row: nothing here is administration. Standings and the
    // arcade are for looking at, quotes and music are for feeding the kids'
    // side. Exit is not among them — the header keeps its power button, which
    // is the one control a parent uses on a shared laptop.
    $sheetGrid = ['standings', 'quotes', 'arcade', 'music'];

    /*
     * Everything waiting on a grown-up, on the one page that holds all of it.
     *
     * This is the number that used to sit in the header as a PENDING tile. It
     * reads better on the Home button: a count belongs on the thing that takes
     * you to the count, and a parent glancing at the rail from the Monsters
     * page now learns both that something is waiting and where to go for it.
     */
    $counts = [
        'home' => \App\Models\ChoreCompletion::whereHas('profile', fn ($q) => $q->where('household_id', $profile->household_id))
            ->where('status', \App\Enums\CompletionStatus::Pending)
            ->count()
            + \App\Models\Redemption::whereHas('profile', fn ($q) => $q->where('household_id', $profile->household_id))
                ->where('status', \App\Enums\RedemptionStatus::Pending)
                ->count()
            // A Lucky Block win is a promise until somebody keeps it, so it
            // counts here exactly as a cash-out does.
            + \App\Models\LuckyHit::where('household_id', $profile->household_id)->pending()->count(),
    ];

    // Anything waiting on a page the rail doesn't show. Nothing does today —
    // every queue in the console lands on Home — but the glyph carries it if a
    // later page grows one, the same way the kid console's does.
    $sheetCount = collect($counts)->reject(fn (int $count, string $key) => in_array($key, $rail, true))->sum();
@endphp

<div class="mx-auto max-w-[1080px] px-[14px] pb-10">
    <div class="flex flex-col gap-[9px] pt-[14px] pb-[10px]">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-[22px] border border-fq-line bg-fq-panel p-[12px_14px]">
            <div>
                <p class="font-mono-fq text-[10px] tracking-[0.22em] text-fq-cyan uppercase">Parent Console</p>
                <h1 class="font-baloo text-2xl font-extrabold">{{ config('app.name') }} HQ</h1>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                {{-- Background music, the same control the kids have. A parent
                     signing off a board of chores at nine at night is doing the
                     same kind of thing they are, and the playlist it plays is
                     the parent's own — see the music page. --}}
                <x-music-toggle :profile="$profile" />

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

        {{-- Four destinations and the sheet, on every page, always one row. The
             active tile is the only gold thing here rather than the whole bar
             being gold: gold is the app's points colour everywhere else, and
             permanent chrome shouldn't own it. --}}
        <div class="flex gap-[4px] rounded-[15px] border border-fq-nav-line bg-fq-panel p-[4px]">
            {{-- `contents` so the four links stay flex items of the bar above
                 while the sheet, which is a way to everywhere rather than a
                 fifth page, sits outside the landmark. --}}
            <nav aria-label="Pages" data-fq-rail class="contents">
                @foreach ($rail as $key)
                    @php
                        $open = $active === $key;
                        $waiting = $counts[$key] ?? 0;
                    @endphp

                    <a
                        href="{{ route($pages[$key]['route']) }}"
                        wire:navigate
                        @if ($open) aria-current="page" @endif
                        class="relative flex flex-1 flex-col items-center gap-[4px] rounded-[11px] border py-[9px] transition"
                        style="{{ $open ? 'background:var(--fq-gold-fill); border-color:var(--fq-ticket-line)' : 'border-color:transparent' }}"
                    >
                        <i
                            class="fa-solid {{ $pages[$key]['icon'] }} text-[15px]"
                            style="color:{{ $open ? 'var(--fq-lime)' : 'var(--fq-text-4)' }}"
                        ></i>
                        <span
                            class="text-[10px] leading-none {{ $open ? 'font-bold' : '' }}"
                            style="color:{{ $open ? 'var(--fq-lime)' : 'var(--fq-text-3)' }}"
                        >{{ $pages[$key]['short'] ?? $pages[$key]['label'] }}</span>

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

            {{-- No controls in the sheet's header: the parent's mute lives in
                 the music button and the approval alerts switch is a labelled
                 button on Home, where the queue it announces is. --}}
            <x-nav-sheet
                :pages="$pages"
                :active="$active"
                :counts="$counts"
                :groups="$sheetGroups"
                :grid="$sheetGrid"
                :count="$sheetCount"
                :current="! in_array($active, $rail, true)"
            />
        </div>
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
