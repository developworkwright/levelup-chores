{{-- A celebration day, on Home: the occasion, the one question it asks, and the
     chest that answering opens.

     Every word on it comes from the day's own row in CelebrationService::DAYS —
     this component knows there is an occasion and a chest, and nothing about
     which one. A day that asks no question skips straight to the chest.

     ## Why the chest is behind a question

     Not to make the kid work for it. The thing being paid for is coming back
     and telling us how it went, which on the day this was built for is the hard
     part — and every answer opens the same chest for the same amount, "Rather
     not say" included. The service is where that rule is enforced and explained.

     `day` is CelebrationService::activeFor(); `entry` is this kid's row, or
     null before they've said anything. --}}
@props([
    'day',
    'entry' => null,
    'reward',
    'extras',
    'answerAction' => 'answerCelebration',
    'openAction' => 'openCelebrationChest',
])

@php
    $answers = $day['answers'] ?? [];
    $asks = $answers !== [];
    $answered = $entry?->answered_at !== null;
    $opened = $entry?->opened_at !== null;
    $chosen = collect($answers)->firstWhere('key', $entry?->answer);
    $accent = $day['accent'];
    $submitLabel = $day['submitLabel'] ?? 'Send it';
@endphp

<div class="flex flex-col gap-3">
    <x-home-section
        :title="$day['kicker']"
        :accent="$accent"
        :done="$opened"
        :status="$opened ? 'Chest opened' : ($asks && ! $answered ? 'Tell us how it went' : 'Chest ready')"
        :status-color="$opened ? 'var(--fq-lime)' : $accent"
    />

    {{-- The banner. Deliberately the loudest thing on the page for one day, and
         gone the day after. --}}
    <div
        class="relative overflow-hidden rounded-[24px] border p-5 text-center sm:p-6"
        style="background: linear-gradient(160deg, color-mix(in srgb, {{ $accent }} 22%, var(--fq-panel)), var(--fq-panel) 72%); border-color: {{ $accent }}"
    >
        <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: {{ $accent }}">{{ $day['kicker'] }}</p>

        <h2 class="mt-2 font-baloo text-[34px] leading-[1.05] font-extrabold sm:text-[42px]">{{ $day['title'] }}</h2>

        <p class="mx-auto mt-[10px] max-w-[46ch] text-[14px] text-fq-text-2">{{ $day['blurb'] }}</p>
    </div>

    @if ($asks)
        {{-- The question.

             Picking a word is a local choice, not a save. It was a save at
             first — one tap wrote the answer and collapsed the card — and that
             took the box for saying more off the screen before anybody had
             read it, which put the whole point of asking behind a "Change it"
             button nobody would press. The word and the sentence about it are
             one answer, so they are submitted together.

             This is the one place in the app where the extra step is right.
             Everywhere else a separate submit is a trap (see the feelings card,
             which has none), because there the tap *is* the whole answer. --}}
        <div
            wire:key="celebration-question-{{ $day['key'] }}"
            class="rounded-[24px] border bg-fq-panel p-5"
            style="border-color: var(--fq-line-2)"
            x-data="{
                {{-- Local until submitted. Seeded from the server so re-opening
                     an answered card finds the word and the note where they
                     were left. --}}
                picked: @js($entry?->answer),
                note: @js($entry?->note ?? ''),
                {{-- Open until answered, and re-openable afterwards. Changing
                     your mind costs nothing here: the chest has already paid
                     and cannot un-pay, so there is nothing riding on the word. --}}
                editing: @js(! $answered),
                saving: false,
                async submit() {
                    if (! this.picked || this.saving) return;

                    this.saving = true;
                    await $wire.{{ $answerAction }}(this.picked, this.note);
                    this.saving = false;
                    this.editing = false;
                },
            }"
        >
            <h3 class="font-baloo text-[21px] leading-tight font-extrabold">{{ $day['question'] }}</h3>

            <div x-show="! editing" x-cloak class="mt-3 flex flex-wrap items-center gap-3">
                @if ($chosen)
                    <span
                        class="inline-flex items-center gap-2 rounded-full border px-4 py-2 font-baloo text-[16px] font-extrabold"
                        style="border-color: {{ $chosen['color'] }}; color: {{ $chosen['color'] }}"
                    >
                        <span aria-hidden="true">{{ $chosen['glyph'] }}</span>{{ $chosen['label'] }}
                    </span>
                @endif

                <button
                    type="button"
                    @click="editing = true"
                    class="cursor-pointer rounded-full border border-fq-line-2 px-4 py-2 font-mono-fq text-[11px] tracking-[0.12em] text-fq-text-3 uppercase transition hover:text-fq-text-1"
                >Change it</button>
            </div>

            @if ($entry?->note)
                <p x-show="! editing" x-cloak class="mt-3 text-[13.5px] leading-relaxed text-fq-text-2">“{{ $entry->note }}”</p>
            @endif

            <div x-show="editing" x-cloak>
                <p class="mt-[6px] text-[13px] text-fq-text-3">{{ $day['questionNote'] }}</p>

                <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($answers as $answer)
                        {{-- The selected look goes through :style rather than a
                             Blade class, because the choice is now a client-side
                             one — a server-rendered highlight would not move
                             until the answer had already been sent. --}}
                        <button
                            type="button"
                            wire:key="celebration-answer-{{ $answer['key'] }}"
                            @click="picked = @js($answer['key'])"
                            :aria-pressed="picked === @js($answer['key']) ? 'true' : 'false'"
                            class="flex cursor-pointer items-center gap-2 rounded-[16px] border px-3 py-[13px] text-left transition hover:brightness-125"
                            :style="picked === @js($answer['key'])
                                ? @js('border-color: '.$answer['color'].'; background: color-mix(in srgb, '.$answer['color'].' 30%, transparent); box-shadow: 0 0 0 2px color-mix(in srgb, '.$answer['color'].' 45%, transparent)')
                                : @js('border-color: color-mix(in srgb, '.$answer['color'].' 55%, transparent); background: color-mix(in srgb, '.$answer['color'].' 12%, transparent)')"
                        >
                            <span class="text-[20px] leading-none" aria-hidden="true">{{ $answer['glyph'] }}</span>
                            <span class="font-baloo text-[15px] leading-tight font-bold" style="color: {{ $answer['color'] }}">{{ $answer['label'] }}</span>
                        </button>
                    @endforeach
                </div>

                {{-- Optional, and said so. A required box makes the quick honest
                     answer the expensive one, which is the whole failure the
                     feelings card was built to avoid. Alpine-local rather than
                     wire:model so it isn't a round trip per keystroke; it goes
                     up with the word when Send is pressed. --}}
                <label class="mt-4 block">
                    <span class="font-mono-fq text-[10px] tracking-[0.16em] text-fq-text-4 uppercase">{{ $day['noteLabel'] }}</span>
                    <textarea
                        x-model="note"
                        rows="3"
                        maxlength="{{ App\Services\CelebrationService::MAX_NOTE }}"
                        class="mt-2 w-full rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-[14px] text-fq-text-1 outline-none focus:border-fq-line-3"
                        placeholder="Only if you want to."
                    ></textarea>
                </label>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        @click="submit()"
                        :disabled="! picked || saving"
                        class="cursor-pointer rounded-[16px] px-[26px] py-[14px] font-baloo text-[17px] font-extrabold transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40"
                        style="background: {{ $accent }}; color: var(--fq-bg)"
                        x-text="saving ? 'Sending...' : @js($submitLabel)"
                    ></button>

                    {{-- Says which half is missing, so a greyed-out button is
                         never a dead end with no explanation. --}}
                    <p class="font-mono-fq text-[11px] text-fq-text-4" x-show="! picked">Pick a word first — the writing is optional.</p>

                    @if ($answered)
                        <button
                            type="button"
                            @click="editing = false"
                            class="ml-auto cursor-pointer font-mono-fq text-[11px] tracking-[0.12em] text-fq-text-4 uppercase transition hover:text-fq-text-2"
                        >Cancel</button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if (! $asks || $answered)
        <x-chest
            wire-key="celebration-chest-{{ $day['key'] }}"
            :revealed="$opened"
            :open-action="$openAction"
            :accent="$accent"
            wash="color-mix(in srgb, {{ $accent }} 14%, transparent)"
            fill="linear-gradient(180deg, #ffd9f4, #d94fb0)"
            band="#4a1038"
            lock="#2f0a24"
            :kicker="$day['kicker'].' · OP'"
            :closed-title="$day['chestTitle']"
            :closed-text="$day['chestText']"
            cta="Open it"
            :prize-label="$reward"
            :prize-sub="$day['title']"
            :celebrate-message="$day['title']"
            {{-- The loudest reveal in the app, and the only chest that asks for
                 it: shells going off across the screen rather than one burst.
                 It happens once a year, and the monster kill — the only other
                 thing that gets this — happens less often than that. --}}
            celebrate-tier="epic"
            celebrate-motion="fireworks"
            celebrate-style="star"
        >
            <div
                class="rounded-[24px] border p-5 text-center"
                style="animation: fq-pop .3s ease both; background: color-mix(in srgb, {{ $accent }} 14%, transparent); border-color: {{ $accent }}"
            >
                <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: {{ $accent }}">{{ $day['kicker'] }}</p>
                <p class="mt-[6px] font-baloo text-[26px] leading-[1.1] font-extrabold">{{ $reward }} banked</p>
                <p class="mt-[6px] font-mono-fq text-[12px] text-fq-text-3">{{ $extras }}</p>
            </div>
        </x-chest>
    @else
        {{-- The chest, visible and shut. It is the reason to answer, so hiding
             it until they have would be hiding the offer. --}}
        <div
            class="flex items-center gap-4 rounded-[24px] border border-dashed p-5"
            style="border-color: color-mix(in srgb, {{ $accent }} 45%, transparent); background: var(--fq-panel)"
        >
            <x-chest-block
                fill="linear-gradient(180deg, #ffd9f4, #d94fb0)"
                band="#4a1038"
                lock="#2f0a24"
                radius="12px"
                class="h-[52px] w-[68px] opacity-50 grayscale"
            />

            <div class="min-w-0">
                <h3 class="font-baloo text-[19px] leading-tight font-extrabold">{{ $day['chestTitle'] }}</h3>
                <p class="mt-1 text-[13.5px] text-fq-text-2">{{ $day['lockedText'] }}</p>
                <p class="mt-1 font-mono-fq text-[11px]" style="color: {{ $accent }}">{{ $reward }} · {{ $extras }}</p>
            </div>
        </div>
    @endif
</div>
