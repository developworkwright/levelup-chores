<?php

use App\Enums\ArcadeGame;
use App\Models\Profile;
use App\Services\ArcadeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;

/**
 * The arcade: a rail of games, the one that is showing, and its board.
 *
 * Its own component rather than part of a page, for one practical reason —
 * posting a score re-renders whatever component owns the board, and nothing
 * around it should be re-rendering while somebody is mid-run. Both canvases sit
 * behind `wire:ignore` besides.
 *
 * It stood on the public login page until the board started carrying real
 * names; both consoles draw it now, which is the whole of what "parents
 * included" cost. See ArcadeService for what moving behind the PIN changed and
 * what it deliberately did not.
 *
 * The rail is server-side state rather than an Alpine toggle, and that is
 * load-bearing: `$game` is what `post()` writes a run to, so which game you are
 * playing is never something the browser gets to say. It also means the board
 * beside it swaps with the game in the same round trip.
 *
 * Laid out from `handoff/design_handoff_arcade_shell` — see that README for why
 * the rail carries the week's leader rather than the reader's own best, and why
 * there is no lobby screen in front of it.
 */
new class extends Component
{
    public ArcadeGame $game;

    /** Highlights the row the player just put there, for the rest of the visit. */
    public ?int $postedId = null;

    /**
     * Which games were new when this visit started.
     *
     * A snapshot taken at mount and held for the visit, because the marker it
     * came from is stamped a line later — read live, the flash would clear
     * itself before the kid it was for had looked up. The same ordering the
     * Loot Shop's "new" chips need, and for the same reason.
     *
     * @var list<string>
     */
    public array $newGames = [];

    public function mount(): void
    {
        $arcade = app(ArcadeService::class);
        $player = $this->player();

        $this->newGames = $arcade->newGamesFor($player)->pluck('value')->all();
        $arcade->markGamesSeen($player);

        // Open on a game they have not met, if there is one — the flash says it
        // is new and this is what makes that mean something without a tap.
        $this->game = ArcadeGame::tryFrom($this->newGames[0] ?? '') ?? ArcadeGame::default();

        // Whoever opens the arcade pays out the weeks nobody has collected, on
        // every game — see ArcadeService::settle() for why there is no scheduler
        // behind it. On mount rather than in with(), so it happens once a visit
        // rather than on every round trip the game makes.
        $arcade->settle($player->household);
    }

    /**
     * Move to another game on the rail.
     *
     * `postedId` is dropped on the way: it points at a row on the board we are
     * leaving, and an id from one game's board would highlight whichever row of
     * another's happened to share it.
     */
    public function switchTo(string $game): void
    {
        $this->game = ArcadeGame::from($game);
        $this->postedId = null;
    }

    /**
     * Post a finished run to the board of the game on screen.
     *
     * The score arrives from the browser, so it is a claim and `ArcadeService`
     * decides whether it is a believable one. The *name* never arrives at all:
     * it is read off the signed-in profile here, which is what keeps a typed
     * string off a board the whole house reads. Nor does the *game* — see the
     * class comment.
     */
    public function post(int $score): void
    {
        $player = $this->player();

        // Per profile and per game: a shared tablet is one session and several
        // players, and a run is a very different length in each game — see
        // ArcadeGame::postsPerHour().
        $throttle = 'arcade-post:'.$player->id.':'.$this->game->value;

        if (RateLimiter::tooManyAttempts($throttle, $this->game->postsPerHour())) {
            return;
        }

        RateLimiter::hit($throttle, 3600);

        $this->postedId = app(ArcadeService::class)->post($player, $this->game, $score)?->id;
    }

    private function player(): Profile
    {
        $profile = Auth::guard('profile')->user();

        abort_unless($profile instanceof Profile, 403);

        return $profile;
    }

    public function with(): array
    {
        $arcade = app(ArcadeService::class);
        $player = $this->player();
        $household = $player->household;

        $leaders = $arcade->weeklyLeaders($household);
        $leader = $leaders[$this->game->value] ?? null;

        $standings = $this->game->isRanked()
            ? $arcade->weeklyStandings($household, $this->game)
            : collect();

        // Where the reader sits this week, so their own row can be shown even
        // when the board only has room for three. Null when they have not
        // played this game this week — there is no row to pull up.
        $myRank = $standings->search(fn (object $score) => $score->profile_id === $player->id);

        return [
            'arcade' => $arcade,
            'player' => $player,
            'rankedGames' => ArcadeGame::ranked(),
            'toys' => ArcadeGame::toys(),
            'leaders' => $leaders,
            'leader' => $leader,
            'beat' => $arcade->beatTarget($leader),
            'youLead' => $leader !== null && $leader->profile_id === $player->id,
            // A grown-up can top the week and gets nothing for it, so the target
            // strip must not promise them tickets. See ArcadeService.
            'canWinTickets' => $player->isKid(),
            'standings' => $standings,
            'myRank' => $myRank === false ? null : $myRank,
            'best' => $arcade->allTimeBest($household, $this->game),
            'yourBest' => $arcade->personalBest($player, $this->game),
            'champion' => $arcade->lastChampion($household, $this->game),
            'prize' => ArcadeService::PRIZE_TICKETS,
            'milestones' => ArcadeService::milestonesFor($this->game),
        ];
    }
}; ?>

<div class="flex flex-col gap-[13px]">
    <div class="flex items-center justify-between gap-[10px]">
        <h2 class="font-baloo text-[22px] leading-none font-extrabold text-fq-text">Arcade</h2>

        {{-- The one sound control on the page, and the only one there ever
             needed to be. Both games read the same `fq-muted` key at the moment
             they play a sound rather than holding their own mute state, so a
             toggle out here mutes whichever one is running — and it does not
             have to be re-drawn inside every game that arrives later. It sits
             outside the keyed subtree below, so switching games never takes the
             control away mid-run. --}}
        <x-sound-toggle small />
    </div>

    {{-- Three rails from `lg`: the games down the left, the one that is showing
         in the middle, its board on the right. Below that they stack — strip,
         target, game, board — because splitting a tablet three ways leaves
         nothing legible.

         Both side rails are fixed widths and the middle one takes what is left,
         so adding a fourth or fifth game lengthens the left rail without taking
         a pixel off the game. --}}
    <div class="flex flex-col items-stretch gap-[13px] lg:flex-row lg:items-start">
        {{-- The rail, which is the catalog and the switcher at once.

             Each entry carries *this week's* leader rather than the reader's own
             best, which is the change this layout is built around: the number
             under a name nobody has tapped yet is the number somebody is
             currently winning with, so the list of games is a standings glance
             before anything has been opened.

             A vertical rail on desktop and a scrolling strip on a phone. The
             strip is what makes this survive a fifth game: a row that divides
             the width between however many games exist is unreadable by the
             fourth, so the entries keep a fixed width and the row scrolls
             instead. It bleeds to the screen edges so a half-visible entry says
             there is more to swipe to, and hides its scrollbar because that
             half-visible entry has already said it. --}}
        <div class="no-scrollbar -mx-[14px] flex shrink-0 snap-x snap-mandatory gap-[8px] overflow-x-auto px-[14px] pb-[2px] lg:mx-0 lg:w-[186px] lg:flex-col lg:overflow-visible lg:px-0 lg:pb-0">
            <span class="hidden font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-5 uppercase lg:block">
                Games
            </span>

            @foreach ($rankedGames as $entry)
                @php($entryLeader = $leaders[$entry->value] ?? null)

                <button
                    type="button"
                    wire:key="rail-{{ $entry->value }}"
                    wire:click="switchTo('{{ $entry->value }}')"
                    @class([
                        'flex w-[168px] shrink-0 snap-start flex-col items-start gap-[6px] rounded-[12px] px-[11px] py-[10px] text-left lg:w-full',
                        'border-2 border-fq-lime' => $entry === $game,
                        'border border-fq-line-3 bg-fq-sunk' => $entry !== $game,
                    ])
                    @if ($entry === $game)
                        style="background: linear-gradient(180deg, rgba(255, 201, 61, 0.2), var(--fq-sunk))"
                    @endif
                >
                    <span class="flex w-full items-center gap-[5px]">
                        <span
                            @class([
                                'min-w-0 flex-1 truncate text-[14px] leading-tight',
                                'font-extrabold text-fq-lime' => $entry === $game,
                                'font-semibold text-fq-text' => $entry !== $game,
                            ])
                        >{{ $entry->label() }}</span>

                        @if (in_array($entry->value, $newGames, true))
                            {{-- Beside the name rather than instead of the line
                                 below it: a kid who has never been here would
                                 otherwise be shown a flash where the standings
                                 should be, on every game at once. Only until
                                 they have been once — the marker is stamped on
                                 mount, so this is the last visit it shows on. --}}
                            <span class="shrink-0 rounded-full bg-fq-coral px-[5px] py-px font-mono-fq text-[8px] font-semibold tracking-[0.1em] text-fq-ink uppercase">
                                New
                            </span>
                        @endif
                    </span>

                    @if ($entryLeader)
                        <span class="flex w-full items-center gap-[5px]">
                            <span
                                class="grid h-[19px] w-[19px] shrink-0 place-items-center rounded-full font-baloo text-[9.5px] font-extrabold text-fq-bg"
                                style="background: {{ $entryLeader->profile?->color->cssVar() ?? 'var(--fq-line-3)' }}"
                            >{{ mb_substr($entryLeader->displayName(), 0, 1) }}</span>

                            <span class="min-w-0 flex-1 truncate font-mono-fq text-[10.5px] text-fq-text-4">
                                best {{ $entryLeader->score }}<span class="hidden lg:inline"> this wk</span>
                            </span>
                        </span>
                    @else
                        {{-- "Nobody yet" rather than "best 0": one is an
                             invitation and the other is a scoreboard. --}}
                        <span class="font-mono-fq text-[10.5px] text-fq-magenta">nobody yet</span>
                    @endif
                </button>
            @endforeach

            {{-- The toys, in their own group with no heading of the ranked kind.
                 A toy keeps no score, so it has no leader line, no board and no
                 week to win — see ArcadeGame::isRanked(). Nothing in the arcade
                 is one yet, which is why this renders nothing today. --}}
            @if ($toys !== [])
                <span class="mt-[5px] hidden font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-5 uppercase lg:block">
                    Toys
                </span>

                @foreach ($toys as $toy)
                    <button
                        type="button"
                        wire:key="rail-{{ $toy->value }}"
                        wire:click="switchTo('{{ $toy->value }}')"
                        @class([
                            'flex w-[168px] shrink-0 snap-start flex-col items-start gap-[6px] rounded-[12px] px-[11px] py-[10px] text-left lg:w-full',
                            'border-2 border-fq-lime' => $toy === $game,
                            'border border-fq-line-4 bg-fq-sunk' => $toy !== $game,
                        ])
                    >
                        <span
                            @class([
                                'w-full truncate text-[14px] leading-tight',
                                'font-extrabold text-fq-lime' => $toy === $game,
                                'font-semibold text-fq-text' => $toy !== $game,
                            ])
                        >{{ $toy->label() }}</span>

                        <span class="font-mono-fq text-[10.5px] text-fq-magenta">toy</span>
                    </button>
                @endforeach
            @endif
        </div>

        {{-- The game, and the middle rail.

             Both games draw a fixed 320x460 board scaled to whatever box they
             are given, so width is the only dial and a wider box is a bigger
             game. Height is the other limit: at full width the board would stand
             1550px tall, so the max-width below is a *height* budget converted
             back through the aspect ratio, which is what keeps a short window or
             a phone in landscape from losing the bottom of the game off the
             screen. --}}
        <div
            class="flex w-full min-w-0 flex-col gap-[8px] lg:flex-1"
            style="max-width: calc(88vh * 320 / 460)"
        >
            {{-- The stage: the title, the phone-only target and the machine,
                 wrapped so all three can be handed to the screen at once.

                 It is the wrapper rather than the machine that carries the
                 overlay, because the button to come back out has to still be on
                 it — Escape works, but a six-year-old on a tablet has no Escape.

                 `--fq-stage-chrome` is the height each game stacks around its own
                 canvas — a score line here, a d-pad there — which is what the
                 stage subtracts before sizing the board. See .fq-stage-full. --}}
            <div
                x-data="fqStage"
                :class="full ? 'fq-stage-full' : ''"
                class="flex flex-col gap-[8px]"
                style="--fq-stage-chrome: {{ $game === ArcadeGame::WindyWalkies ? 155 : 110 }}px"
            >
                {{-- The title line, and the button that is the way in and back
                     out. Full screen it drops everything but the button and
                     floats that in the corner, so the only thing left on screen
                     is the game — the cabinet around it is what the height was
                     going to. See .fq-stage-bar. --}}
                <div class="fq-stage-bar flex items-center justify-between gap-[8px]">
                    <span class="fq-full-hide flex min-w-0 items-baseline gap-[8px]">
                        <span class="truncate font-baloo text-[15px] font-extrabold text-fq-lime">{{ $game->label() }}</span>
                        <span class="shrink-0 font-mono-fq text-[9px] tracking-[0.1em] text-fq-text-5 uppercase">
                            {{ $game->scoreLabel() }}
                        </span>
                    </span>

                    <button
                        type="button"
                        x-on:click="toggle()"
                        :aria-label="full ? 'Leave full screen' : 'Play full screen'"
                        class="flex shrink-0 items-center gap-[6px] rounded-[10px] border border-fq-line-3 bg-fq-sunk px-[10px] py-[6px] font-mono-fq text-[9px] tracking-[0.12em] text-fq-text-3 uppercase"
                    >
                        <i class="fa-solid fa-expand text-[12px] text-fq-cyan" :class="{ 'fa-expand': ! full, 'fa-compress': full }"></i>
                        <span x-text="full ? 'Exit' : 'Full screen'">Full screen</span>
                    </button>
                </div>

                {{-- On a phone the target sits here rather than on the board,
                     because by the time a thumb reaches the start button the board
                     has scrolled off the bottom of the screen. Same component and
                     the same four states — see x-arcade-beat. --}}
                @if ($game->isRanked())
                    <div class="lg:hidden">
                        <x-arcade-beat
                            :leader="$leader"
                            :beat="$beat"
                            :you-lead="$youLead"
                            :can-win-tickets="$canWinTickets"
                            :prize="$prize"
                        />
                    </div>
                @endif

                {{-- The machine — the box the game is drawn in.

                     Keyed on the game so switching *replaces* this subtree instead
                     of morphing one game's markup into another's — the canvases
                     inside are `wire:ignore` and would otherwise be handed to the
                     wrong game. It is also what unmounts the outgoing game: both
                     hold an animation frame, and `<fart-dash>` holds a window-level
                     keydown listener that would eat the arrow keys of whatever
                     replaced it. --}}
                <div
                    wire:key="machine-{{ $game->value }}"
                    class="flex flex-col gap-[11px] rounded-[24px] border border-fq-line-3 p-[12px]"
                    style="background: linear-gradient(160deg, var(--fq-cabinet), var(--fq-panel))"
                >
                    @if ($game === ArcadeGame::StackTheMess)
                        {{-- `wire:ignore` because everything inside is drawn by hand
                             and held in Alpine state. Posting a score re-renders the
                             board beside it, and without this the canvas element
                             would be morphed out from under a running animation
                             frame. --}}
                        <div
                            wire:ignore
                            x-data="fqStacker(@js($milestones))"
                            x-on:pointerdown.prevent="$el.focus(); tap()"
                            x-on:keydown.space.prevent="tap()"
                            x-on:keydown.enter.prevent="tap()"
                            x-on:keydown.up.prevent="tap()"
                            {{-- W beside the up arrow, so the hand a Windy Walkies
                                 player already has on WASD works here too. The tower
                                 has one input, so the other three do nothing. --}}
                            x-on:keydown.w.prevent="tap()"
                            tabindex="0"
                            role="application"
                            aria-label="Stack the Mess — tap or press space to drop a floor"
                            class="relative w-full cursor-pointer touch-manipulation select-none focus:outline-none"
                        >
                            <div class="mb-2 flex items-end justify-between gap-2">
                                <div class="flex items-baseline gap-2">
                                    <span class="font-baloo text-[26px] leading-none font-extrabold text-fq-lime" x-text="score"></span>
                                    <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-5 uppercase">floors</span>
                                </div>

                                <span
                                    class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-5 uppercase"
                                    x-text="'best ' + best"
                                ></span>
                            </div>

                            <div class="relative overflow-hidden rounded-[18px] border-2 border-fq-line-2 bg-fq-bg">
                                <canvas
                                    x-ref="canvas"
                                    class="block w-full"
                                    style="aspect-ratio: 320 / 460"
                                ></canvas>

                                {{-- Idle --}}
                                <div
                                    x-show="phase === 'idle'"
                                    class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-fq-bg/72 px-6 text-center"
                                >
                                    <p class="font-mono-fq text-[10px] tracking-[0.3em] text-fq-cyan uppercase">Arcade</p>
                                    <h3 class="font-baloo text-[30px] leading-none font-extrabold text-fq-text">Stack<br>the Mess</h3>
                                    <p class="text-[12px] leading-snug text-fq-text-3">
                                        Tap to drop each floor. Whatever hangs over the edge falls off — so line it up.
                                    </p>
                                    <span class="mt-1 rounded-full bg-fq-lime px-5 py-2 font-baloo text-[15px] font-extrabold text-fq-ink">
                                        Tap to start
                                    </span>
                                </div>

                                {{-- Game over --}}
                                <div
                                    x-show="phase === 'over'"
                                    x-cloak
                                    class="absolute inset-0 flex flex-col items-center justify-center gap-[10px] bg-fq-bg/85 px-5 text-center"
                                >
                                    <p class="font-mono-fq text-[10px] tracking-[0.28em] text-fq-coral uppercase">Tower down</p>

                                    <p class="font-baloo text-[46px] leading-none font-extrabold text-fq-lime" x-text="score"></p>
                                    <p class="-mt-1 font-mono-fq text-[10px] tracking-[0.18em] text-fq-text-5 uppercase">floors</p>
                                    <p class="font-baloo text-[17px] font-bold text-fq-text-2" x-text="altitude"></p>

                                    {{-- The player's own name, and no way to change it.

                                         There was a rolled codename here with a re-roll button
                                         beside it, for as long as this game stood on a page a
                                         stranger could open. Behind the PIN the board is the
                                         family's, so a run says who did it — and it still isn't
                                         typed: the name comes off the profile server-side, so
                                         nothing anybody enters can reach the board. --}}
                                    <div class="mt-1 flex w-full flex-col items-center gap-[6px]" x-show="!posted">
                                        <p class="font-mono-fq text-[9px] tracking-[0.18em] text-fq-text-5 uppercase">Posting as</p>

                                        <span
                                            class="rounded-full border px-3 py-[5px] font-baloo text-[13px] font-bold"
                                            style="border-color: {{ $player->color->cssVar() }}; color: {{ $player->color->cssVar() }}"
                                        >{{ $player->name }}</span>
                                    </div>

                                    <p
                                        x-show="posted"
                                        x-cloak
                                        class="mt-1 font-mono-fq text-[10px] tracking-[0.14em] text-fq-lime uppercase"
                                    >On the board &#10003;</p>

                                    {{-- One button, and it is the one they were
                                         already aiming at. The Post button that used
                                         to sit beside it is gone: the run posts
                                         itself, so a thumb going for "again" can no
                                         longer throw away the score it just
                                         earned. --}}
                                    <div class="mt-2 flex items-center gap-2">
                                        <button
                                            type="button"
                                            x-on:pointerdown.stop.prevent="play()"
                                            class="rounded-full border border-fq-line-3 bg-fq-sunk px-4 py-2 font-baloo text-[14px] font-extrabold text-fq-text"
                                        >Play again</button>
                                    </div>
                                </div>
                            </div>

                            <p class="mt-2 text-center font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-6 uppercase" x-cloak x-show="phase === 'playing'">
                                <span x-text="altitude"></span>
                            </p>
                        </div>
                    @else
                        {{-- Windy Walkies builds its own canvas, its own d-pad and
                             its own HUD, so the page only has to give it a box and
                             listen. `wire:ignore` for the same reason as the tower:
                             the board beside it re-renders on every posted run.

                             There is no Post button because there is nowhere to put
                             one — the game-over screen is drawn inside the canvas,
                             and a tap on it restarts. `fd-over` fires once per
                             death, which is exactly the moment the tower's button
                             would have been pressed. --}}
                        <div
                            wire:ignore
                            x-data="{ posted: false, score: 0 }"
                            x-on:fd-over="score = $event.detail.score; posted = false; if (score > 0) { $wire.post(score).then(() => posted = true) }"
                            class="relative w-full select-none"
                        >
                            <p
                                x-show="posted"
                                x-cloak
                                class="mb-2 text-right font-mono-fq text-[9px] tracking-[0.14em] text-fq-lime uppercase"
                            ><span x-text="score"></span> lanes &middot; on the board &#10003;</p>

                            <fart-dash aria-label="Windy Walkies — tap or press space to hop"></fart-dash>
                        </div>
                    @endif

                    {{-- Read once and then never again, so full screen is where
                         it stops earning its share of the height. --}}
                    <div class="fq-full-hide flex items-center gap-[9px] border-t border-fq-line pt-[10px]">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-[10px] bg-fq-sunk">
                            <i class="fa-solid {{ $game->icon() }} text-[14px] text-fq-fart"></i>
                        </span>

                        <span class="min-w-0 flex-1 text-[12.5px] leading-[1.4] text-pretty text-fq-text-3">
                            @if ($game === ArcadeGame::WindyWalkies)
                                Swipe, or use the arrows or WASD. Tap to hop forward &mdash;
                                <span class="text-fq-fart">beans give you a super fart</span> worth three lanes.
                            @else
                                Tap, space or W to drop each floor. Whatever hangs
                                over the edge falls off &mdash; so line it up.
                            @endif
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- The board. A fixed sidebar rather than a second flexible column: two
             `flex-1`s split the row in half and the game never reached the size
             its height budget allowed. Both rails are fixed and the game takes
             what is left.

             Absent entirely on a toy, which keeps no score — there would be
             three empty blocks where the standings go. --}}
        @if ($game->isRanked())
            <div class="flex w-full flex-col gap-[8px] lg:w-[228px] lg:shrink-0">
                <div class="hidden lg:block">
                    <x-arcade-beat
                        :leader="$leader"
                        :beat="$beat"
                        :you-lead="$youLead"
                        :can-win-tickets="$canWinTickets"
                        :prize="$prize"
                    />
                </div>

                {{-- This week: one row per player rather than one per run, which
                     is what makes three rows worth reading — see
                     ArcadeService::weeklyStandings(). --}}
                <div class="flex flex-col gap-[6px] rounded-[11px] border border-fq-line-3 bg-fq-sunk p-[11px]">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-lime uppercase">This week</span>
                        <span class="shrink-0 font-mono-fq text-[9px] text-fq-text-5">ends Sun</span>
                    </div>

                    @forelse ($standings->take(3) as $i => $row)
                        <x-arcade-standing
                            :rank="$i + 1"
                            :score="$row"
                            :mine="$row->profile_id === $player->id"
                            :posted="$row->id === $postedId"
                        />
                    @empty
                        <p class="py-[6px] text-[12.5px] leading-snug text-fq-text-4">
                            {{ $game->emptyBoard() }}
                        </p>
                    @endforelse

                    {{-- Their own row, pulled up from wherever it actually is. A
                         board of three that a fourth-placed kid is missing from
                         tells them nothing about their own week. --}}
                    @if ($myRank !== null && $myRank > 2)
                        <div class="border-t border-fq-line pt-[6px]">
                            <x-arcade-standing
                                :rank="$myRank + 1"
                                :score="$standings[$myRank]"
                                mine
                                :posted="$standings[$myRank]->id === $postedId"
                            />
                        </div>
                    @endif
                </div>

                {{-- All-time, deliberately quieter than the block above it. It is
                     a record rather than something winnable this week, and the
                     weekly reset is what gives a new player a shot at all — so it
                     must not out-shout the number that is still open. --}}
                <div class="flex flex-col gap-[5px] rounded-[11px] border border-fq-line bg-fq-panel p-[11px]">
                    <span class="font-mono-fq text-[9.5px] tracking-[0.14em] text-fq-text-5 uppercase">All-time record</span>

                    @if ($best)
                        <div class="flex items-baseline gap-[6px]">
                            <span class="shrink-0 text-[12.5px] text-fq-text-3">House</span>
                            <span class="font-baloo text-[15px] font-extrabold text-fq-magenta">
                                {{ $best->score }} {{ $game->unit() }}
                            </span>
                            <span class="min-w-0 truncate font-mono-fq text-[9px] text-fq-text-5">
                                {{ $best->displayName() }}
                            </span>
                        </div>
                    @else
                        <p class="text-[12.5px] text-fq-text-4">Nobody has set one yet.</p>
                    @endif

                    <div class="flex items-baseline gap-[6px]">
                        <span class="shrink-0 text-[12.5px] text-fq-text-3">Yours</span>
                        <span class="font-baloo text-[15px] font-extrabold text-fq-text">
                            {{ $yourBest }} {{ $game->unit() }}
                        </span>
                    </div>
                </div>

                {{-- The two quiet lines the board ends on. The grown-ups one is a
                     joke rather than fine print, and it is also the rule that
                     keeps the prize pointing at the people it is for — it works
                     better said out loud than left in ArcadeService. --}}
                @if ($champion)
                    <p class="font-mono-fq text-[10.5px] leading-relaxed tracking-[0.12em] text-fq-text-6 uppercase">
                        Last champion &middot; {{ $champion->profile?->name }} &middot; {{ $champion->score }} {{ $game->unit() }}
                        @if ($champion->tickets > 0)
                            &middot; won {{ $champion->tickets }} {{ Str::plural('ticket', $champion->tickets) }}
                        @endif
                    </p>
                @endif

                <p class="font-mono-fq text-[10.5px] leading-relaxed tracking-[0.12em] text-fq-text-6 uppercase">
                    {{ $prize }} bonus {{ Str::plural('ticket', $prize) }} every Sunday, one prize per game.
                    Grown-ups can win the week, but not the tickets.
                </p>
            </div>
        @endif
    </div>
</div>
