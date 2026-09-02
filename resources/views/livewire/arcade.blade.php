<?php

use App\Enums\ArcadeGame;
use App\Models\Profile;
use App\Services\ArcadeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;

/**
 * The arcade: two cabinets, a switcher, and the week's board for whichever one
 * is showing.
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
 * The switcher is server-side state rather than an Alpine toggle, and that is
 * load-bearing: `$game` is what `post()` writes a run to, so which cabinet you
 * are playing is never something the browser gets to say. It also means the
 * board below swaps with the game in the same round trip.
 */
new class extends Component
{
    public ArcadeGame $game;

    /** Highlights the row the player just put there, for the rest of the visit. */
    public ?int $postedId = null;

    public function mount(): void
    {
        $this->game = ArcadeGame::default();

        // Whoever opens the arcade pays out the weeks nobody has collected, on
        // both cabinets — see ArcadeService::settle() for why there is no
        // scheduler behind it. On mount rather than in with(), so it happens
        // once a visit rather than on every round trip the game makes.
        app(ArcadeService::class)->settle($this->player()->household);
    }

    /**
     * Move to the other cabinet.
     *
     * `postedId` is dropped on the way: it points at a row on the board we are
     * leaving, and an id from one game's board would highlight whichever row of
     * the other's happened to share it.
     */
    public function switchTo(string $game): void
    {
        $this->game = ArcadeGame::from($game);
        $this->postedId = null;
    }

    /**
     * Post a finished run to the board of the cabinet on screen.
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

        // Per profile and per cabinet: a shared tablet is one session and
        // several players, and a run is a very different length in each game —
        // see ArcadeGame::postsPerHour().
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
        $best = $arcade->allTimeBest($household, $this->game);

        return [
            'arcade' => $arcade,
            'player' => $player,
            'scores' => $arcade->weeklyTop($household, $this->game),
            'best' => $best,
            'bestAltitude' => $best ? $arcade->altitude($this->game, $best->score) : null,
            'weekStart' => $arcade->weekStartedOn(),
            'champion' => $arcade->lastChampion($household, $this->game),
            'prize' => ArcadeService::PRIZE_TICKETS,
            'milestones' => ArcadeService::milestonesFor($this->game),
            'cabinets' => ArcadeGame::cases(),
            'personalBests' => collect(ArcadeGame::cases())
                ->mapWithKeys(fn (ArcadeGame $game) => [$game->value => $arcade->personalBest($player, $game)])
                ->all(),
        ];
    }
}; ?>

<div class="flex flex-col gap-[13px]">
    <div class="flex items-baseline justify-between gap-[10px]">
        <h2 class="font-baloo text-[22px] leading-none font-extrabold text-fq-text">Arcade</h2>
        <span class="shrink-0 font-mono-fq text-[10px] tracking-[0.14em] whitespace-nowrap text-fq-lime uppercase">
            Week&rsquo;s board
        </span>
    </div>

    {{-- Side by side only from `lg`. Below that the two columns would split a
         tablet into a cramped game and a cramped board; stacked, the game gets
         the whole width and the board reads underneath it. --}}
    <div class="flex flex-col items-start gap-[13px] lg:flex-row lg:items-start">
        {{-- The cabinet takes the room, and the board is the sidebar.

             Both games draw a fixed 320x460 board scaled to whatever box they
             are given, so width is the only dial and a wider cabinet is a
             bigger game. Height is what actually limits it: at the shell's
             1080px the board would stand 1550px tall, so the max-width below is
             a *height* budget converted back through the aspect ratio. 88vh
             gives the game very nearly the whole window and costs a little
             scrolling on a short one, which is the right way round on the one
             page that exists to be looked at. A phone is narrower than the
             result and keeps its full width. --}}
        <div
            class="flex w-full min-w-0 flex-col gap-[13px] lg:flex-1"
            style="max-width: calc(88vh * 320 / 460)"
        >
            {{-- The switcher. Each card carries that player's own best on that
                 cabinet rather than the house's, because the number under a
                 button you are about to press should be the one you are about
                 to try to beat. --}}
            <div class="flex gap-[6px]">
                @foreach ($cabinets as $cabinet)
                    <button
                        type="button"
                        wire:click="switchTo('{{ $cabinet->value }}')"
                        @class([
                            'flex min-h-[44px] min-w-0 flex-1 flex-col items-center gap-[3px] rounded-[16px] px-[6px] py-[8px]',
                            'border-2 border-fq-lime' => $cabinet === $game,
                            'border border-fq-line-2 bg-fq-sunk' => $cabinet !== $game,
                        ])
                        @if ($cabinet === $game)
                            style="background: linear-gradient(180deg, var(--fq-gold-fill), var(--fq-sunk))"
                        @endif
                    >
                        <span
                            @class([
                                'text-[13.5px] whitespace-nowrap',
                                'font-bold text-fq-lime' => $cabinet === $game,
                                'font-semibold text-fq-text' => $cabinet !== $game,
                            ])
                        >{{ $cabinet->label() }}</span>

                        <span
                            @class([
                                'font-mono-fq text-[8.5px] tracking-[0.1em] whitespace-nowrap uppercase',
                                'text-fq-gold-dim' => $cabinet === $game,
                                'text-fq-text-5' => $cabinet !== $game,
                            ])
                        >Best {{ $personalBests[$cabinet->value] }}</span>
                    </button>
                @endforeach
            </div>

            {{-- The cabinet.

                 Keyed on the game so switching *replaces* this subtree instead
                 of morphing one game's markup into the other's — the canvases
                 inside are `wire:ignore` and would otherwise be handed to the
                 wrong game. It is also what unmounts the outgoing game: both
                 hold an animation frame, and `<fart-dash>` holds a window-level
                 keydown listener that would eat the arrow keys of whatever
                 replaced it. --}}
            <div
                wire:key="cabinet-{{ $game->value }}"
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

                            <div class="flex items-center gap-2">
                                <span
                                    class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-5 uppercase"
                                    x-text="'best ' + best"
                                ></span>
                                <button
                                    type="button"
                                    x-on:pointerdown.stop.prevent="toggleMute()"
                                    :aria-label="muted ? 'Sound off' : 'Sound on'"
                                    :style="muted ? 'opacity:0.3' : ''"
                                    class="flex h-6 w-6 items-center justify-center rounded-lg border border-fq-line-2 bg-fq-sunk text-[11px] text-fq-text-4"
                                >&#9834;</button>
                            </div>
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
                                     beside it, for as long as this cabinet stood on a page
                                     a stranger could open. Behind the PIN the board is the
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

                                <div class="mt-2 flex items-center gap-2">
                                    <button
                                        type="button"
                                        x-show="!posted"
                                        x-on:pointerdown.stop.prevent="post()"
                                        :disabled="posting"
                                        class="rounded-full bg-fq-lime px-4 py-2 font-baloo text-[14px] font-extrabold text-fq-ink disabled:opacity-50"
                                    >Post score</button>

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
                        x-data="{
                            posted: false,
                            score: 0,
                            muted: localStorage.getItem('fq-muted') === '1',
                            toggleMute() {
                                this.muted = !this.muted;
                                localStorage.setItem('fq-muted', this.muted ? '1' : '0');
                            },
                        }"
                        x-on:fd-over="score = $event.detail.score; posted = false; if (score > 0) { $wire.post(score).then(() => posted = true) }"
                        class="relative w-full select-none"
                    >
                        <div class="mb-2 flex items-center justify-end gap-2">
                            <p
                                x-show="posted"
                                x-cloak
                                class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-lime uppercase"
                            ><span x-text="score"></span> lanes &middot; on the board &#10003;</p>

                            <button
                                type="button"
                                x-on:click="toggleMute()"
                                :aria-label="muted ? 'Sound off' : 'Sound on'"
                                :style="muted ? 'opacity:0.3' : ''"
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg border border-fq-line-2 bg-fq-sunk text-[11px] text-fq-text-4"
                            >&#9834;</button>
                        </div>

                        <fart-dash aria-label="Windy Walkies — tap or press space to hop"></fart-dash>
                    </div>
                @endif

                <div class="flex items-center gap-[9px] border-t border-fq-line pt-[10px]">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-[10px] bg-fq-sunk">
                        <i class="fa-solid {{ $game->icon() }} text-[14px] text-fq-fart"></i>
                    </span>

                    <span class="min-w-0 flex-1 text-[12.5px] leading-[1.4] text-pretty text-fq-text-3">
                        @if ($game === ArcadeGame::WindyWalkies)
                            Swipe or use the arrows. Tap to hop forward &mdash;
                            <span class="text-fq-fart">beans give you a super fart</span> worth three lanes.
                        @else
                            Tap to drop each floor. Whatever hangs over the edge
                            falls off &mdash; so line it up.
                        @endif
                    </span>
                </div>
            </div>
        </div>

        {{-- A fixed sidebar rather than a second flexible column: two `flex-1`s
             split the row in half and the cabinet never reached the size its
             height budget allowed. The board takes what it needs and the game
             takes the rest. --}}
        <div class="flex w-full flex-col gap-[10px] lg:w-[300px] lg:shrink-0">
            <div class="flex items-baseline justify-between gap-2">
                <span class="font-mono-fq text-[9.5px] tracking-[0.2em] text-fq-text-3 uppercase">
                    {{ $game->label() }} &middot; this week
                </span>
                <span class="shrink-0 font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-6 uppercase">
                    from {{ $weekStart->format('D j M') }}
                </span>
            </div>

            {{-- What the week is worth, said before the board rather than
                 after it: a prize nobody knows about is not a prize. The
                 grown-ups' half is a joke rather than fine print — they are on
                 the board to be beaten, and it works better said out loud. --}}
            <p class="rounded-[12px] border border-fq-ticket-line px-3 py-[7px] text-[11.5px] leading-snug text-fq-text-3" style="background: var(--fq-gold-fill)">
                <span class="font-bold text-fq-lime">{{ $prize }} bonus {{ Str::plural('ticket', $prize) }}</span>
                to the top of each game&rsquo;s board when the week ends on Sunday &mdash; one prize per
                cabinet, so both are worth playing.
                <span class="text-fq-text-5">Grown-ups can win the week, but not the tickets.</span>
            </p>

            @if ($champion)
                <p class="font-mono-fq text-[9px] leading-relaxed tracking-[0.12em] text-fq-text-6 uppercase">
                    Last champion &middot; {{ $champion->profile?->name }} &middot; {{ $champion->score }} {{ $game->unit() }}
                    @if ($champion->tickets > 0)
                        &middot; won {{ $champion->tickets }} {{ Str::plural('ticket', $champion->tickets) }}
                    @endif
                </p>
            @endif

            @if ($scores->isEmpty())
                <p class="rounded-[14px] border border-dashed border-fq-line-2 px-4 py-6 text-center text-[12px] text-fq-text-4">
                    {{ $game->emptyBoard() }} The board resets every Monday.
                </p>
            @else
                <ol class="flex flex-col gap-[5px]">
                    @foreach ($scores as $i => $score)
                        <li
                            @class([
                                'flex items-center gap-3 rounded-[15px] border px-3 py-[8px]',
                                'border-fq-lime bg-fq-panel-alt' => $score->id === $postedId,
                                'border-fq-line bg-fq-panel' => $score->id !== $postedId,
                            ])
                        >
                            <span
                                @class([
                                    'w-[18px] shrink-0 font-baloo text-[16px] font-extrabold',
                                    'text-fq-gold' => $i === 0,
                                    'text-fq-text-5' => $i > 0,
                                ])
                            >{{ $i + 1 }}</span>

                            <span class="flex min-w-0 flex-1 flex-col gap-px">
                                <span class="truncate text-[14px] leading-[1.2] font-semibold text-fq-text">
                                    {{ $score->displayName() }}
                                </span>
                                <span class="truncate font-mono-fq text-[9px] tracking-[0.06em] text-fq-text-5 uppercase">
                                    {{ $arcade->altitude($game, $score->score) }}
                                </span>
                            </span>

                            <span class="shrink-0 font-baloo text-[19px] font-extrabold text-fq-lime">
                                {{ $score->score }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ($best)
                <p class="font-mono-fq text-[9px] leading-relaxed tracking-[0.12em] text-fq-text-6 uppercase">
                    All-time record &middot; {{ $best->score }} {{ $game->unit() }} &middot; {{ $best->displayName() }}
                    <br>{{ $bestAltitude }}
                </p>
            @endif
        </div>
    </div>
</div>
