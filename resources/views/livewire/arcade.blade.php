<?php

use App\Models\Profile;
use App\Services\ArcadeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;

/**
 * The arcade cabinet: the game, and this week's board.
 *
 * Its own component rather than part of a page, for one practical reason —
 * posting a score re-renders whatever component owns the board, and nothing
 * around it should be re-rendering while somebody is mid-run. The canvas sits
 * behind `wire:ignore` besides.
 *
 * It stood on the public login page until the board started carrying real
 * names; both consoles draw it now, which is the whole of what "parents
 * included" cost. See ArcadeService for what moving behind the PIN changed and
 * what it deliberately did not.
 */
new class extends Component
{
    /** Highlights the row the player just put there, for the rest of the visit. */
    public ?int $postedId = null;

    public function mount(): void
    {
        // Whoever opens the cabinet pays out the weeks nobody has collected —
        // see ArcadeService::settle() for why there is no scheduler behind it.
        // On mount rather than in with(), so it happens once a visit rather
        // than on every round trip the game makes.
        app(ArcadeService::class)->settle($this->player()->household);
    }

    /**
     * Post a finished run to the board.
     *
     * The score arrives from the browser, so it is a claim and `ArcadeService`
     * decides whether it is a believable one. The *name* never arrives at all:
     * it is read off the signed-in profile here, which is what keeps a typed
     * string off a board the whole house reads.
     */
    public function post(int $score): void
    {
        $player = $this->player();

        // Per profile rather than per session, now that there is a profile to
        // count against: a shared tablet is one session and several players.
        $throttle = 'arcade-post:'.$player->id;

        if (RateLimiter::tooManyAttempts($throttle, ArcadeService::POSTS_PER_HOUR)) {
            return;
        }

        RateLimiter::hit($throttle, 3600);

        $this->postedId = app(ArcadeService::class)->post($player, $score)?->id;
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
        $best = $arcade->allTimeBest($household);

        return [
            'arcade' => $arcade,
            'player' => $player,
            'scores' => $arcade->weeklyTop($household),
            'best' => $best,
            'bestAltitude' => $best ? $arcade->altitude($best->score) : null,
            'weekStart' => $arcade->weekStartedOn(),
            'champion' => $arcade->lastChampion($household),
            'prize' => ArcadeService::PRIZE_TICKETS,
            'milestones' => ArcadeService::MILESTONES,
        ];
    }
}; ?>

<div class="flex flex-col gap-4">
    <div class="flex items-center gap-[14px]">
        <span class="h-px flex-1 bg-fq-line"></span>
        <span class="font-mono-fq text-[10px] tracking-[0.22em] whitespace-nowrap text-fq-text-5 uppercase">
            The Arcade
        </span>
        <span class="h-px flex-1 bg-fq-line"></span>
    </div>

    <div class="flex flex-col items-center gap-5 sm:flex-row sm:items-start">
        {{-- `wire:ignore` because everything inside is drawn by hand and held in
             Alpine state. Posting a score re-renders the board beside it, and
             without this the canvas element would be morphed out from under a
             running animation frame. --}}
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
            class="relative w-full max-w-[300px] shrink-0 cursor-pointer touch-manipulation select-none focus:outline-none"
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
                    <h2 class="font-baloo text-[30px] leading-none font-extrabold text-fq-text">Stack<br>the Mess</h2>
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

        <div class="flex w-full flex-col gap-[10px]">
            <div class="flex items-baseline justify-between gap-2">
                <h3 class="font-baloo text-[17px] font-extrabold text-fq-text">This week's tallest</h3>
                <span class="font-mono-fq text-[9px] tracking-[0.14em] text-fq-text-6 uppercase">
                    from {{ $weekStart->format('D j M') }}
                </span>
            </div>

            {{-- What the week is worth, said before the board rather than
                 after it: a prize nobody knows about is not a prize. The
                 grown-ups' half is a joke rather than fine print — they are on
                 the board to be beaten, and it works better said out loud. --}}
            <p class="rounded-[12px] border border-fq-ticket-line px-3 py-[7px] text-[11.5px] leading-snug text-fq-text-3" style="background: var(--fq-gold-fill)">
                <span class="font-bold text-fq-lime">{{ $prize }} bonus {{ Str::plural('ticket', $prize) }}</span>
                to the tallest tower when the week ends on Sunday.
                <span class="text-fq-text-5">Grown-ups can win the week, but not the tickets.</span>
            </p>

            @if ($champion)
                <p class="font-mono-fq text-[9px] leading-relaxed tracking-[0.12em] text-fq-text-6 uppercase">
                    Last champion &middot; {{ $champion->profile?->name }} &middot; {{ $champion->score }} floors
                    @if ($champion->tickets > 0)
                        &middot; won {{ $champion->tickets }} {{ Str::plural('ticket', $champion->tickets) }}
                    @endif
                </p>
            @endif

            @if ($scores->isEmpty())
                <p class="rounded-[14px] border border-dashed border-fq-line-2 px-4 py-6 text-center text-[12px] text-fq-text-4">
                    Nobody has stacked anything yet this week. The board resets every Monday.
                </p>
            @else
                <ol class="flex flex-col gap-[5px]">
                    @foreach ($scores as $i => $score)
                        <li
                            @class([
                                'flex items-center gap-3 rounded-[12px] border px-3 py-[7px]',
                                'border-fq-lime bg-fq-panel-alt' => $score->id === $postedId,
                                'border-fq-line bg-fq-panel' => $score->id !== $postedId,
                            ])
                        >
                            <span
                                @class([
                                    'w-[18px] shrink-0 font-baloo text-[15px] font-extrabold',
                                    'text-fq-gold' => $i === 0,
                                    'text-fq-text-5' => $i > 0,
                                ])
                            >{{ $i + 1 }}</span>

                            <span class="min-w-0 flex-1 truncate font-mono-fq text-[11px] font-semibold tracking-[0.04em] text-fq-chip-text">
                                {{ $score->displayName() }}
                            </span>

                            <span class="shrink-0 font-mono-fq text-[9px] tracking-[0.1em] text-fq-text-6 uppercase">
                                {{ $arcade->altitude($score->score) }}
                            </span>

                            <span class="w-[26px] shrink-0 text-right font-baloo text-[16px] font-extrabold text-fq-lime">
                                {{ $score->score }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ($best)
                <p class="font-mono-fq text-[9px] leading-relaxed tracking-[0.12em] text-fq-text-6 uppercase">
                    All-time record &middot; {{ $best->score }} floors &middot; {{ $best->displayName() }}
                    <br>{{ $bestAltitude }}
                </p>
            @endif
        </div>
    </div>
</div>
