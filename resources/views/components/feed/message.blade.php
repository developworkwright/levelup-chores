{{-- One message. Six kinds, and two of them are deliberately quieter than the
     rest.

     text / stamp / drawing / shout-out all carry an avatar, a name, a time and
     a reaction row. An `event` — a badge, a personal best, a monster killed —
     carries none of them: one mono line, indented to the text column, with
     nothing to tap. A room where the app is the loudest voice is a
     notification tray, and these kids have nowhere else to talk.

     A `quote` is indented the same way and for a different reason: it brings
     its own card, its own attribution and its own reactions with it. --}}
@props(['message', 'viewer', 'reactions' => [], 'tz' => 'UTC'])

@php
    use App\Enums\FeedMessageKind;

    $kind = $message->kind;
    $author = $message->profile;
@endphp

@if ($kind === FeedMessageKind::Quote)
    {{-- Indented to the text column and carrying no avatar of its own: the
         quote card already says who said it, and a second name above it would
         be the app taking credit for the joke.

         The card is the one the Journal's Quote Wall draws, reading the real
         Quote off `source` — so the faces underneath are the *same* reactions,
         not a second set. One quote, one set of people laughing at it,
         whichever screen you happen to be on. --}}
    <div class="pl-[41px] lg:pl-[45px]">
        @if ($message->source)
            <x-quote-line :quote="$message->source" :viewer="$viewer" />
        @else
            {{-- The quote was deleted out from under the row. Say what was
                 said rather than drawing an empty frame. --}}
            <p class="font-baloo text-[15px] font-bold text-fq-text-3">&ldquo;{{ $message->body }}&rdquo;</p>
        @endif
    </div>
@elseif ($kind === FeedMessageKind::Event)
    <div class="pl-[41px] lg:pl-[45px]">
        {{-- The number inside an event line is lit, and the way it gets lit is
             guillemets written by FeedService::event() — escaped first, then
             swapped for a span. A kid's name lands in these strings, and
             printing a name as raw HTML is how a feature like this grows an
             XSS hole six months after anybody last looked at it. --}}
        <span class="font-mono-fq text-[10.5px] text-fq-text-4">{!! str_replace(
            ['«', '»'],
            ['<span style="color: var(--fq-lime)">', '</span>'],
            e($message->body),
        ) !!}</span>
    </div>
@else
    <div class="flex gap-[9px]">
        <x-feed.avatar :profile="$author" :size="36" :radius="11" :text="14" />

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-[7px]">
                <span class="font-baloo text-[16px] font-bold">{{ $author?->name }}</span>

                @if ($kind === FeedMessageKind::Shoutout)
                    <span class="font-mono-fq text-[9px] tracking-[0.16em] uppercase" style="color: var(--fq-coral)">
                        Shout-out &rarr; {{ $message->subject?->name }}
                    </span>
                @else
                    <span class="font-mono-fq text-[9.5px] text-fq-text-5">{{ $message->created_at?->copy()->setTimezone($tz)->format('g:i A') }}</span>
                @endif
            </div>

            @switch ($kind)
                @case (FeedMessageKind::Text)
                    {{-- 16px, and nothing in a message body ever goes below it.
                         A six-year-old is reading this. --}}
                    <div class="text-[16px] leading-[1.45] [text-wrap:pretty]">{{ $message->body }}</div>
                    @break

                @case (FeedMessageKind::Shoutout)
                    <div
                        class="mt-[5px] pl-[10px] text-[16px] leading-[1.45] [text-wrap:pretty]"
                        style="border-left: 2px solid var(--fq-coral)"
                    >{{ $message->body }}</div>
                    @break

                @case (FeedMessageKind::Stamp)
                    {{-- No bubble. A stamp is the whole message. --}}
                    <div class="mt-1 text-[44px] leading-none" role="img" aria-label="{{ $message->stamp?->label() }}">{{ $message->stamp?->glyph() }}</div>
                    @break

                @case (FeedMessageKind::Drawing)
                    <img
                        src="{{ $message->drawingUrl() }}"
                        alt="A drawing by {{ $author?->name }}"
                        width="{{ \App\Services\FeedDrawings::WIDTH }}"
                        height="{{ \App\Services\FeedDrawings::HEIGHT }}"
                        loading="lazy"
                        class="mt-[6px] w-full rounded-[13px] border border-fq-line"
                    >
                    @break
            @endswitch

            {{-- Reactions. One of each per person, and tapping your own takes
                 it back — which is what keeps the number a count of people. --}}
            <div class="mt-[7px] flex flex-wrap items-center gap-[6px]">
                @foreach ($message->reactionPillsFor($viewer) as $pill)
                    <button
                        type="button"
                        wire:click="reactToMessage({{ $message->id }}, '{{ $pill['emoji'] }}')"
                        wire:key="react-{{ $message->id }}-{{ $loop->index }}"
                        @class([
                            'inline-flex h-[34px] items-center gap-[6px] rounded-full border px-3 text-[14.5px] transition',
                            'border-fq-line-3 bg-fq-panel-alt' => ! $pill['mine'],
                            'bg-fq-panel-alt' => $pill['mine'],
                        ])
                        @style(['border: 1px solid var(--fq-magenta)' => $pill['mine']])
                        aria-label="{{ $pill['mine'] ? 'Take back your' : 'Add a' }} {{ $pill['emoji'] }}"
                        aria-pressed="{{ $pill['mine'] ? 'true' : 'false' }}"
                    >
                        <span>{{ $pill['emoji'] }}</span>
                        <span class="font-mono-fq text-[11px]" style="color: var(--fq-magenta)">{{ $pill['count'] }}</span>
                    </button>
                @endforeach

                {{-- The add pill is what makes the 34px pills sit in a 44px
                     row, so the whole strip clears the tap-target floor. --}}
                <div
                    class="relative flex h-[44px] items-center"
                    x-data="{ open: false }"
                    @click.outside="open = false"
                >
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        class="grid h-[34px] w-[38px] place-items-center rounded-full border border-dashed border-fq-line-2 text-[15px] text-fq-text-5 transition hover:text-fq-text-3"
                        aria-label="React to {{ $author?->name }}'s message"
                    >+</button>

                    <div
                        x-cloak
                        x-show="open"
                        x-transition.opacity
                        {{-- Opens downward. On Home the messages scroll inside a
                             capped box with the newest at the top, and a sheet
                             opening upward from that first message would be
                             clipped by the box with no way to scroll to it.
                             Downward, it extends the scroll area instead. --}}
                        class="absolute top-[42px] left-0 z-10 flex gap-1 rounded-[14px] border border-fq-line-2 bg-fq-sunk p-[6px] shadow-lg"
                    >
                        @foreach ($reactions as $emoji)
                            <button
                                type="button"
                                wire:click="reactToMessage({{ $message->id }}, '{{ $emoji }}')"
                                x-on:click="open = false"
                                class="grid size-[38px] place-items-center rounded-[10px] text-[18px] transition hover:bg-fq-panel-alt"
                                aria-label="React with {{ $emoji }}"
                            >{{ $emoji }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
