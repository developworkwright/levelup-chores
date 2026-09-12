<?php

use App\Enums\FeedStamp;
use App\Models\FeedRoom;
use App\Models\Profile;
use App\Services\FeedService;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The family feed — rooms, and the quiet half.
 *
 * One component for both consoles. It is embedded on kid Home and parent Home,
 * and the kids also have it full-height at /kid/family — the same component
 * each time, because this is one feed with one set of rooms in it: a copy per
 * side would be two implementations of FeedRoom::readableBy()'s consequences,
 * and the whole point of that method is that there is exactly one.
 *
 * ## Two screens on a phone, two columns on a laptop
 *
 * Started from `handoff/design_handoff_family_feed` (turn 3's `#3b`, turn 2's
 * `#2b`), then narrowed on sight of the running page. The handoff's three
 * columns became two: one left column holding the rooms, everybody else in the
 * house under "Just you two", and the quiet half — Today in the house and
 * Grateful today — beneath those; and the room itself across all the width to
 * its right. The house is five people, so that column is never long, and the
 * room is the thing that grows.
 *
 * On a phone the left column *is* screen 1 and the room is screen 2. `$roomId`
 * being null is screen 1; on a laptop it simply means "nobody has picked a room
 * yet", which lands on Everyone.
 *
 * ## The who-can-read line is not decoration
 *
 * It is drawn under every room name, always, and it is the only thing standing
 * where a moderation queue would be. The house chose full trust; what that buys
 * has to be paid for by nobody ever discovering their audience after the fact.
 */
new class extends Component
{
    public Profile $profile;

    /** The open room. Null is the phone's room list; a laptop lands on Everyone. */
    public ?int $roomId = null;

    public string $draft = '';

    /** Which composer tray is open, if any: 'stamps', 'draw' or 'shout'. */
    public ?string $tray = null;

    public ?int $shoutSubject = null;

    public string $shoutDraft = '';

    public ?string $notice = null;

    /**
     * Whether this is the copy sitting inside Home's run rather than a page of
     * its own.
     *
     * The only thing it changes is the title: Home draws its own
     * <x-home-section> header above the component, and a second heading inside
     * it would be the word "Family" twice, one on top of the other.
     */
    public bool $embedded = false;

    /**
     * One service instance for the life of a request. It memoises the household
     * roster, and every room in the list asks for it — resolving a fresh one
     * per call would throw that away several times a render. Private, so
     * Livewire never tries to carry it across a round trip.
     */
    private ?FeedService $feed = null;

    public function mount(bool $embedded = false): void
    {
        $this->profile = Auth::guard('profile')->user();
        $this->embedded = $embedded;

        // On the way in rather than on a schedule — nothing in this app is
        // scheduled, the host scales to zero and a cron would never fire. Two
        // lookups on a normal visit; one insert on the day a kid is added.
        $this->feed()->ensureRooms($this->profile->household);
    }

    public function open(int $roomId): void
    {
        $room = $this->feed()->roomFor($this->profile, $roomId);

        if (! $room || (int) $room->id !== $roomId) {
            return;
        }

        $this->roomId = $room->id;
        $this->closeTrays();

        $this->feed()->markRead($this->profile, $room);
    }

    /** Back to the room list. Only the phone has anywhere to go back to. */
    public function back(): void
    {
        $this->roomId = null;
        $this->closeTrays();
    }

    public function showTray(string $tray): void
    {
        $this->tray = $this->tray === $tray ? null : $tray;
        $this->notice = null;
    }

    public function send(): void
    {
        $room = $this->requireRoom();

        if ($this->feed()->say($this->profile, $room, $this->draft)) {
            $this->draft = '';
            $this->closeTrays();
            $this->feed()->markRead($this->profile, $room);
        }
    }

    public function sendStamp(string $stamp): void
    {
        $room = $this->requireRoom();

        $this->feed()->stamp($this->profile, $room, $stamp);
        $this->closeTrays();
        $this->feed()->markRead($this->profile, $room);
    }

    /**
     * Called by the finger pad with a flattened PNG data URL — see
     * resources/js/draw.js. The canvas sits behind `wire:ignore`, so this is
     * the only way the drawing reaches the server.
     */
    public function postDrawing(string $dataUrl): void
    {
        $room = $this->requireRoom();

        try {
            $this->feed()->draw($this->profile, $room, $dataUrl);
        } catch (\RuntimeException $e) {
            // Said out loud rather than swallowed. A drawing that vanishes with
            // no explanation reads as the app having eaten it.
            $this->notice = $e->getMessage();

            return;
        }

        $this->closeTrays();
        $this->feed()->markRead($this->profile, $room);
    }

    public function sendShoutOut(): void
    {
        $room = $this->requireRoom();

        if (! $this->shoutSubject) {
            $this->notice = 'Pick who it\'s about first.';

            return;
        }

        if (! $this->feed()->shoutOut($this->profile, $room, $this->shoutSubject, $this->shoutDraft)) {
            $this->notice = 'Say what they did, and pick somebody who can read this room.';

            return;
        }

        $this->shoutDraft = '';
        $this->shoutSubject = null;
        $this->closeTrays();
        $this->feed()->markRead($this->profile, $room);
    }

    public function reactToMessage(int $messageId, string $emoji): void
    {
        $this->feed()->react($this->profile, $messageId, $emoji);
    }

    /**
     * Laugh at a quote, or take it back.
     *
     * Named `react` because `<x-quote-line>` — the same card the Journal's
     * Quote Wall draws — calls exactly that on whatever component is hosting
     * it. Reusing the card means the faces under a quote in the feed are the
     * *same* reactions as the faces under it on the Quote Wall, which is the
     * whole reason a quote row points at its Quote rather than copying it.
     *
     * The message reactions above are `reactToMessage()` for the same reason:
     * two things called react() on one component is how one of them quietly
     * stops working.
     */
    public function react(int $quoteId, string $reaction): void
    {
        app(QuoteService::class)->react($this->profile, $quoteId, $reaction);
    }

    /** Opens — and, first time round, creates — a one-to-one. */
    public function startWith(int $profileId): void
    {
        $other = $this->profile->household->profiles()->find($profileId);

        if (! $other) {
            return;
        }

        $this->open($this->feed()->directRoomWith($this->profile, $other)->id);
    }

    private function feed(): FeedService
    {
        return $this->feed ??= app(FeedService::class);
    }

    private function closeTrays(): void
    {
        $this->tray = null;
        $this->notice = null;
    }

    private function requireRoom(): FeedRoom
    {
        $room = $this->feed()->roomFor($this->profile, $this->roomId);

        abort_unless($room !== null, 404);

        return $room;
    }

    public function with(): array
    {
        $feed = $this->feed();
        $room = $feed->roomFor($this->profile, $this->roomId);
        $roster = $feed->roster($this->profile->household);

        // Marked read on render rather than only on the tap that opened it, so
        // a message arriving while the room is already on screen doesn't leave
        // a pill behind on the way out.
        if ($room && $this->roomId) {
            $feed->markRead($this->profile, $room);
        }

        $rooms = $feed->roomsFor($this->profile);

        return [
            'rooms' => array_filter($rooms, fn (array $r) => $r['room']->kind->isGroup()),
            // Every other person in the house, each with their one-to-one if
            // it exists yet — see FeedService::peopleFor().
            'people' => $feed->peopleFor($this->profile, $rooms),
            'room' => $room,
            'roomName' => $room?->nameFor($this->profile, $roster),
            'audience' => $room?->audienceLineFor($this->profile, $roster),
            'members' => $room?->membersFrom($roster) ?? collect(),
            'placeholder' => $room?->composerPlaceholderFor($this->profile, $roster) ?? '',
            'messages' => $room ? $feed->messagesIn($this->profile, $room) : collect(),
            'house' => $feed->houseToday($this->profile),
            'gratitude' => $feed->gratitudeToday($this->profile),
            'roster' => $roster,
            'stamps' => FeedStamp::cases(),
            'reactions' => FeedService::REACTIONS,
            'tz' => $this->profile->household->timezone,
        ];
    }
}; ?>

@php
    $opened = $roomId !== null;
    // What each column does on a phone: one of them is the screen you are on.
    $listSide = $opened ? 'hidden lg:flex' : 'flex';
    $roomSide = $opened ? 'flex' : 'hidden lg:flex';
@endphp

<div class="flex flex-col gap-3">
    @unless ($embedded)
        <h1 @class(['font-baloo text-[23px] font-extrabold', 'max-lg:hidden' => $opened])>Family</h1>
    @endunless

    <div class="grid gap-3 lg:grid-cols-[300px_minmax(0,1fr)] lg:items-start lg:gap-4">

        {{-- The left column: the rooms, the people in this house, and the quiet
             half underneath them.

             Two columns rather than the handoff's three. The house is five
             people, so the rail was never going to be long, and the room is the
             thing that grows — it takes all the width to the right, where the
             drawings and the longer messages have room to be read. On a phone
             this column is screen one and the room is screen two. --}}
        <div class="min-w-0 flex-col gap-3 {{ $listSide }}">
            <div class="flex flex-col gap-[6px] max-lg:gap-[7px]">
                <span class="pl-[2px] font-mono-fq text-[9.5px] tracking-[0.2em] text-fq-text-4 uppercase">Rooms</span>

                @foreach ($rooms as $entry)
                    <x-feed.room-row
                        :entry="$entry"
                        :selected="$room && $room->id === $entry['room']->id"
                        :tz="$tz"
                    />
                @endforeach
            </div>

            {{-- Everybody else in the house, already listed. There is no "Start
                 one" and no picker: a family of five does not need to go looking
                 for who it can talk to, and a six-year-old should find his
                 brother's name where it always is. A conversation's room is made
                 the first time somebody taps a name — see peopleFor(). --}}
            <div class="flex flex-col gap-[6px] max-lg:gap-[7px]">
                <span class="pl-[2px] font-mono-fq text-[9.5px] tracking-[0.2em] text-fq-text-4 uppercase">Just you two</span>

                @foreach ($people as $person)
                    <x-feed.room-row
                        :entry="$person['entry']"
                        :selected="$room && $person['entry']['room'] && $room->id === $person['entry']['room']->id"
                        :click="$person['started'] ? null : 'startWith('.$person['profile']->id.')'"
                        :row-key="'person-'.$person['profile']->id"
                        :tz="$tz"
                    />
                @endforeach
            </div>

            <x-feed.house-card :house="$house" :gratitude="$gratitude" />

            <x-feed.grateful-card :gratitude="$gratitude" :roster="$roster->count()" />
        </div>

        {{-- The room. --}}
        <div class="min-w-0 flex-col gap-[11px] {{ $roomSide }}">
            @if ($room)
                <div class="flex items-start gap-[10px] border-b border-[var(--fq-divider)] pb-[11px]">
                    {{-- Only the phone has a screen to go back to; on a laptop
                         the rail is right there and a back button would be a
                         control that undoes nothing. --}}
                    <button
                        type="button"
                        wire:click="back"
                        class="grid size-11 shrink-0 place-items-center rounded-[14px] border border-fq-line-2 bg-fq-sunk text-[17px] text-fq-text-3 lg:hidden"
                        aria-label="Back to rooms"
                    >&lsaquo;</button>

                    <div class="flex min-w-0 flex-1 flex-col gap-px">
                        <span class="font-baloo text-[20px] font-extrabold lg:text-[22px]">
                            @if ($room->kind->glyph()) {{ $room->kind->glyph() }} @endif {{ $roomName }}
                        </span>

                        {{-- Always drawn, never truncated, never a tooltip.
                             Nobody learns their audience after posting. --}}
                        <span class="font-mono-fq text-[9px] tracking-[0.1em] text-fq-text-4 uppercase lg:text-[10px]">{{ $audience }}</span>
                    </div>

                    <span class="flex shrink-0">
                        @foreach ($members->take(4) as $person)
                            <x-feed.avatar
                                :profile="$person"
                                :size="26"
                                :radius="8"
                                :text="11"
                                class="!border-fq-bg"
                                style="margin-left: {{ $loop->first ? '0' : '-7px' }}"
                            />
                        @endforeach

                        @if ($members->count() > 4)
                            <x-feed.avatar
                                :letter="'+'.($members->count() - 4)"
                                accent="var(--fq-text-3)"
                                :size="26"
                                :radius="8"
                                :text="10"
                                class="!border-fq-bg"
                                style="margin-left: -7px"
                            />
                        @endif
                    </span>
                </div>

                {{-- The composer, above the messages. A kid glancing at the room should
                     see first that they can say something, then what has been
                     said — so the box leads and the newest message sits right
                     under it. The placeholder names the room on purpose:
                     the field is the last thing looked at before somebody says
                     something, so it is the last chance to say who will hear
                     it. --}}
                <div class="flex items-center gap-[7px] rounded-[17px] border border-fq-line-2 bg-fq-sunk p-[7px_7px_7px_13px]">
                    <input
                        type="text"
                        wire:model="draft"
                        wire:keydown.enter="send"
                        maxlength="{{ \App\Services\FeedService::MAX_BODY }}"
                        placeholder="{{ $placeholder }}"
                        aria-label="{{ $placeholder }}"
                        class="min-w-0 flex-1 bg-transparent text-[16px] outline-none placeholder:text-fq-text-5"
                    >

                    <button
                        type="button"
                        wire:click="showTray('stamps')"
                        @class([
                            'grid size-11 shrink-0 place-items-center rounded-[13px] border border-fq-line-2 text-[16px]',
                            'bg-fq-panel text-fq-text-3' => $tray !== 'stamps',
                            'bg-fq-panel-alt text-fq-text' => $tray === 'stamps',
                        ])
                        aria-label="Stamps and shout-outs"
                    >&#9733;</button>

                    <button
                        type="button"
                        wire:click="showTray('draw')"
                        @class([
                            'grid size-11 shrink-0 place-items-center rounded-[13px] border border-fq-line-2 text-[16px]',
                            'bg-fq-panel text-fq-text-3' => $tray !== 'draw',
                            'bg-fq-panel-alt text-fq-text' => $tray === 'draw',
                        ])
                        aria-label="Draw something"
                    >&#9998;</button>

                    <button
                        type="button"
                        wire:click="send"
                        class="grid size-11 shrink-0 place-items-center rounded-[13px] text-[16px]"
                        style="background: var(--fq-rail); color: var(--fq-ink)"
                        aria-label="Send"
                    >&#10148;</button>
                </div>

                {{-- The trays. One at a time, under the composer rather than
                     over the room: a six-year-old who opens the stamp sheet
                     should still be able to see where it is going. --}}
                @if ($tray === 'stamps')
                    <div class="flex flex-col gap-2 rounded-[16px] border border-fq-line-2 bg-fq-sunk p-2">
                        <div class="grid grid-cols-6 gap-1">
                            @foreach ($stamps as $stamp)
                                <button
                                    type="button"
                                    wire:click="sendStamp('{{ $stamp->value }}')"
                                    wire:key="stamp-{{ $stamp->value }}"
                                    class="grid h-[52px] place-items-center rounded-[12px] text-[26px] transition hover:bg-fq-panel-alt"
                                    aria-label="Send {{ $stamp->label() }}"
                                    title="{{ $stamp->label() }}"
                                >{{ $stamp->glyph() }}</button>
                            @endforeach
                        </div>

                        <button
                            type="button"
                            wire:click="showTray('shout')"
                            class="flex min-h-[44px] items-center gap-2 rounded-[12px] border border-fq-line-2 px-3 text-left text-[13.5px] text-fq-text-3 transition hover:bg-fq-panel-alt"
                        >
                            <span style="color: var(--fq-coral)">&#9733;</span>
                            Give somebody a shout-out
                        </button>
                    </div>
                @endif

                @if ($tray === 'shout')
                    <div class="flex flex-col gap-2 rounded-[16px] border border-fq-line-2 bg-fq-sunk p-3">
                        <span class="font-mono-fq text-[9px] tracking-[0.16em] uppercase" style="color: var(--fq-coral)">Shout-out</span>

                        <div class="flex flex-wrap gap-[6px]">
                            {{-- Only people who can read this room. A shout-out
                                 about somebody who can't see it is a thing said
                                 behind their back with their name on it. --}}
                            @foreach ($members->reject(fn ($p) => (int) $p->id === (int) $profile->id) as $person)
                                <button
                                    type="button"
                                    wire:click="$set('shoutSubject', {{ $person->id }})"
                                    wire:key="shout-{{ $person->id }}"
                                    @class([
                                        'flex min-h-[44px] items-center gap-2 rounded-full border px-3 text-[14px] transition',
                                        'border-fq-line-2 text-fq-text-3' => $shoutSubject !== $person->id,
                                    ])
                                    @style([
                                        'border: 1px solid var(--fq-coral); color: var(--fq-coral)' => $shoutSubject === $person->id,
                                    ])
                                >{{ $person->name }}</button>
                            @endforeach
                        </div>

                        <textarea
                            wire:model="shoutDraft"
                            rows="2"
                            maxlength="{{ \App\Services\FeedService::MAX_BODY }}"
                            placeholder="What did they do?"
                            aria-label="What the shout-out is for"
                            class="w-full resize-none rounded-[12px] border border-fq-line-2 bg-fq-panel px-3 py-2 text-[16px] outline-none focus:border-fq-coral"
                        ></textarea>

                        <button
                            type="button"
                            wire:click="sendShoutOut"
                            class="grid h-[44px] place-items-center rounded-[12px] font-baloo text-[15px] font-extrabold"
                            style="background: var(--fq-coral); color: var(--fq-ink)"
                        >Say it</button>
                    </div>
                @endif

                @if ($tray === 'draw')
                    {{-- `wire:ignore` and a stable key: the canvas is not
                         Livewire's to reconcile, and a round trip that morphed
                         it would wipe a half-finished drawing off the screen. --}}
                    <div
                        wire:ignore
                        wire:key="draw-pad"
                        x-data="fqDrawPad({{ \App\Services\FeedDrawings::WIDTH }}, {{ \App\Services\FeedDrawings::HEIGHT }})"
                        class="flex flex-col gap-2 rounded-[16px] border border-fq-line-2 bg-fq-sunk p-2"
                    >
                        <canvas
                            x-ref="pad"
                            class="w-full touch-none rounded-[12px] border border-fq-line"
                            style="aspect-ratio: {{ \App\Services\FeedDrawings::WIDTH }} / {{ \App\Services\FeedDrawings::HEIGHT }}"
                        ></canvas>

                        <div class="flex flex-wrap items-center gap-[6px]">
                            @foreach (['#ffe14d', '#ff8ac7', '#d8b4ff', '#7fe6c0', '#ff6b6b', '#f7f0ff'] as $swatch)
                                <button
                                    type="button"
                                    x-on:click="pick('{{ $swatch }}')"
                                    :class="color === '{{ $swatch }}' ? 'outline outline-2 outline-offset-2 outline-fq-text' : ''"
                                    class="size-11 rounded-full border border-fq-line-3"
                                    style="background: {{ $swatch }}"
                                    aria-label="Draw in this colour"
                                ></button>
                            @endforeach

                            <span class="flex-1"></span>

                            <button type="button" x-on:click="undo()" class="grid size-11 place-items-center rounded-[12px] border border-fq-line-2 text-fq-text-3" aria-label="Undo the last line">&#8630;</button>
                            <button type="button" x-on:click="clear()" class="grid size-11 place-items-center rounded-[12px] border border-fq-line-2 text-fq-text-3" aria-label="Clear the drawing">&#10005;</button>
                            <button
                                type="button"
                                x-on:click="send()"
                                :disabled="! drawn"
                                class="grid h-11 place-items-center rounded-[12px] px-4 font-baloo text-[14px] font-extrabold disabled:opacity-50"
                                style="background: var(--fq-rail); color: var(--fq-ink)"
                            >Send it</button>
                        </div>
                    </div>
                @endif

                @if ($notice)
                    <p class="text-[13px]" style="color: var(--fq-gold)">{{ $notice }}</p>
                @endif

                {{-- On Home the messages scroll inside a fixed height rather than
                     growing the page. Unbounded, a busy afternoon pushed the quest,
                     the chests and everything else on Home further down with
                     every line. Moving the feed to the bottom was the other fix and
                     was passed over, because the whole point of it being here is
                     that a new message is seen: the box and the newest message
                     stay at the top of this list, visible at a glance.

                     The full page at /kid/family has no such cap — there the room
                     *is* the page. --}}
                <div
                    data-feed-messages
                    @class([
                        'flex flex-col gap-[13px]',
                        'max-h-[440px] overflow-y-auto overscroll-contain pr-1 [scrollbar-width:thin] [scrollbar-color:var(--fq-line-3)_transparent] lg:max-h-[520px]' => $embedded,
                    ])
                >
                    @forelse ($messages->reverse() as $message)
                        <div wire:key="msg-{{ $message->id }}">
                            <x-feed.message :message="$message" :viewer="$profile" :reactions="$reactions" :tz="$tz" />
                        </div>
                    @empty
                        <p class="py-6 text-center text-[14px] text-fq-text-5">
                            Nobody has said anything here yet. Go first.
                        </p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</div>
