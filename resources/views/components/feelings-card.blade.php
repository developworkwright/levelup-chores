{{-- The feelings card: one question the whole house answers.

     Two halves. The top is yours — a word, and optionally why. The bottom is
     the house, and it stays covered until you've answered, because a person who
     reads the room first answers the room instead of the question.

     It pays nothing and there is nothing here to keep a run of. Everything else
     in this app is worth something; this is the one thing that is worth nothing,
     which is what makes it safe to be honest on.

     No reactions, deliberately. The quote wall has them and they're lovely
     there, but a sibling putting a face on someone's bad day is a real wound —
     and even a kind one turns a feeling into something you perform for a
     response.

     `card` is what FeelingService::cardFor() returns. --}}
@props(['card', 'answerAction' => 'answerFeeling', 'openedFeeling' => null, 'lockMessage' => null])

@php
    use App\Enums\Feeling;
    use App\Enums\FeelingVisibility;
    use App\Services\FeelingService;

    $answered = $card['answered'];
    $house = $card['house'];
    $waiting = $card['waiting'];
    $words = $card['words'];
    $canRetireWords = $card['canRetireWords'];

    // A short row rather than a full picker. Choosing one is the fun part for
    // some kids and friction for others, so it has to stay optional and small
    // enough that nobody browses it.
    $glyphChoices = ['•', '🌧️', '⭐', '🌪️', '🫠', '🧊', '🔋', '🌱', '🎈', '🪨'];
@endphp

<div
    wire:key="feelings-card"
    {{ $attributes->merge(['class' => 'rounded-[24px] border p-5']) }}
    style="background: linear-gradient(160deg, #150d2b, var(--fq-panel) 70%); border-color: var(--fq-line-2)"
>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-violet)">Today</p>
        {{-- No streak, no total, no "you've answered 40 days". Anything
             countable here would become a thing to keep up. --}}
        <span class="font-mono-fq text-[10px] text-fq-text-5">NOTHING HERE IS WORTH POINTS</span>
    </div>

    <div
        x-data="{
            feeling: @js($answered?->feeling_word_id ? (string) $answered->feeling_word_id : $answered?->feeling?->value),
            {{-- Held as plain data, set by whichever button was pressed.

                 This was a lookup map of every stem, built server-side into
                 x-data — which breaks the moment a word is added: Livewire
                 morphs the DOM but Alpine does not re-run x-data, so the map
                 still described the words that existed when the page loaded and
                 a brand new one had no stem at all. A button that carries its
                 own stem cannot go stale, because it arrives with the render
                 that created it. --}}
            stem: @js($answered?->stem() ?? ''),
            because: @js($answered?->because ?? ''),
            visibility: @js(($answered?->visibility ?? FeelingVisibility::Private)->value),
            editing: @js(! $answered),
            adding: false,
            newWord: '',
            newGlyph: '',
            max: {{ FeelingService::MAX_BECAUSE }},
            maxWord: {{ App\Models\FeelingWord::MAX_LABEL }},
            silentValue: @js(Feeling::NotSaying->value),
            get silent() {
                return this.feeling === this.silentValue;
            },
            pick(value, stem) {
                this.feeling = value;
                this.stem = stem;

                {{-- Settling on a chip closes the panel and abandons whatever
                     was half-typed in it. Guarded on `value` so clearing the
                     pick to *open* the panel doesn't immediately shut it again
                     — and it is what stops the typed word and a chip from ever
                     both being set when the answer goes in.

                     A Blade comment rather than a JS one, like every other
                     comment in this attribute. See the note above submit(). --}}
                if (value) {
                    this.adding = false;
                    this.newWord = '';
                    this.newGlyph = '';
                }
            },
            get answerable() {
                return Boolean(this.feeling || this.newWord.trim());
            },
            {{-- The sentence over the because box, following whatever is
                 currently the answer. A word being typed fills it in live, so
                 typing reads as choosing rather than as filling in a form
                 field. It repeats the wording of Feeling::stem() in JS, which
                 is a small duplication bought deliberately: the alternative is
                 a round trip per keystroke. --}}
            get stemLine() {
                const typed = this.newWord.trim();

                if (typed) {
                    return 'Today I felt ' + typed.toLowerCase() + ' because…';
                }

                return this.stem ? this.stem + ' because…' : 'I felt this way today because…';
            },
            saveError: '',
            locking: false,
            lockPin: '',
            privateValue: @js(FeelingVisibility::Private->value),
            {{-- Only a private answer can be locked, so this is the one flag
                 the rest of the card asks about. Reading the visibility here
                 rather than trusting `locking` on its own means a lock left
                 switched on can never survive a change of mind about who reads
                 it. --}}
            get lockingNow() {
                return this.locking && !this.silent && this.visibility === this.privateValue;
            },
            async submit() {
                if (!this.answerable) return;
                if (this.lockingNow && !this.lockPin) return;

                this.saveError = '';

                {{-- One call. A typed word is created server-side as part of
                     answering rather than by a button of its own: filling the
                     whole card in and then finding the button dead because of
                     an Add step nobody thinks to press was going to catch
                     someone every single time.

                     NOTE — every comment inside this x-data must be a Blade
                     comment, never a JS one. This attribute is delimited by
                     double quotes, so a single literal `"` anywhere in it (in a
                     comment as readily as in code) closes the attribute early,
                     Alpine gets a fragment of an object to parse, the whole
                     component fails to initialise, and because both halves of
                     the card carry x-cloak they never un-hide. The card renders
                     as an empty box and the console fills with "x is not
                     defined" for every property in here. Blade comments are
                     stripped before the browser ever sees them. --}}
                {{-- Caught, because a server error here used to be silent: the
                     await rejected, the rest of this method never ran, and the
                     card just sat there having apparently ignored the button.
                     Somebody who has written down how they feel and pressed
                     save deserves to be told it did not land, and to still have
                     their words on screen when they try again. --}}
                let saved = false;

                try {
                    saved = await this.$wire.{{ $answerAction }}(
                        this.feeling,
                        this.because,
                        this.lockingNow ? 'private' : this.visibility,
                        this.newWord,
                        this.newGlyph,
                        this.lockingNow ? this.lockPin : null,
                    );
                } catch (e) {
                    this.saveError = 'That did not save. Nothing has been lost — try again in a moment.';

                    return;
                }

                {{-- A wrong PIN saves nothing at all, so the form has to stay
                     open with everything still in it. The server says why. --}}
                if (!saved) {
                    this.lockPin = '';

                    return;
                }

                this.newWord = '';
                this.newGlyph = '';
                this.lockPin = '';
                this.locking = false;
                this.adding = false;
                this.editing = false;
            },
        }"
    >
        {{-- ANSWERED, and not currently being changed.

             x-show rather than x-if, here and below. Alpine's x-if clones the
             template's contents in as a *sibling* of the template, while
             Livewire morphs against server HTML where those contents are still
             *inside* it — so the two disagree about what the DOM is, and a
             chip added by a round trip repaints without its click handler. Real
             elements that hide keep both halves looking at the same nodes. --}}
        <div x-show="!editing" x-cloak>
            <div class="mt-3">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-[26px]">{{ $answered?->glyph() }}</span>
                    <h3 class="font-baloo text-xl font-bold" style="color: {{ $answered?->color() }}">
                        {{ $answered?->stem() }}
                    </h3>
                </div>

                @if ($answered?->isLocked())
                    {{-- Sealed. The text isn't on this page in any form — not
                         hidden with CSS, not in the payload, not on the model.
                         It has to be fetched with the PIN. --}}
                    {{-- Whether it is currently open is decided server-side, not
                         in x-data. Opening is a Livewire round trip, and Alpine
                         does not re-run x-data on a morph — so an `opened` flag
                         initialised from PHP would stay false forever and the
                         text would never appear. Blade branches on the fresh
                         render instead. --}}
                    <div
                        class="mt-3 rounded-[16px] border px-4 py-3"
                        style="border-color: var(--fq-line-cool); background: var(--fq-sunk)"
                    >
                        @if ($openedFeeling !== null)
                            <p class="text-[14px] leading-relaxed text-fq-text-2">{{ $openedFeeling }}</p>
                            <p class="mt-2 font-mono-fq text-[10px] text-fq-text-5">
                                🔓 Open on this screen only — it seals itself again.
                            </p>
                        @else
                            <div x-data="{ opening: false, pin: '' }">
                                <p class="font-baloo text-[15px] font-bold text-fq-text-2">🔒 Locked with your PIN</p>
                                <p class="mt-1 text-[13px] text-fq-text-4">Nobody opens this but you.</p>

                                <div x-show="opening" x-cloak class="mt-3 flex flex-wrap items-center gap-2">
                                    <input
                                        type="password"
                                        inputmode="numeric"
                                        x-model="pin"
                                        @keydown.enter.prevent="$wire.openFeeling({{ $answered->id }}, pin); pin = ''"
                                        placeholder="Your PIN"
                                        class="w-[130px] rounded-[12px] border border-fq-line-2 bg-fq-panel px-3 py-2 text-[14px] tracking-[0.3em] text-fq-text placeholder:tracking-normal placeholder:text-fq-text-5 focus:border-fq-violet focus:outline-none"
                                    />
                                    <button
                                        type="button"
                                        @click="$wire.openFeeling({{ $answered->id }}, pin); pin = ''"
                                        :disabled="!pin"
                                        class="rounded-[12px] border border-fq-violet px-4 py-2 text-[13px] font-semibold transition enabled:hover:brightness-110 disabled:opacity-40"
                                        style="background: var(--fq-wash-violet); color: var(--fq-text)"
                                    >Open it</button>
                                </div>

                                <button
                                    x-show="!opening"
                                    type="button"
                                    @click="opening = true"
                                    class="mt-3 rounded-[12px] border border-fq-line-3 bg-fq-panel px-3 py-[6px] font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-3 uppercase transition hover:border-fq-violet"
                                >Read it back</button>
                            </div>
                        @endif
                    </div>
                @elseif ($answered?->hasBecause())
                    <p class="mt-2 text-[14px] leading-relaxed text-fq-text-2">{{ $answered->because }}</p>
                    <p class="mt-1 font-mono-fq text-[10px] text-fq-text-5">
                        {{ $answered->visibility->glyph() }} {{ $answered->visibility->description() }}
                    </p>

                    {{-- The act of locking, and it is deliberately an act. A
                         checkbox at writing time would be a setting; typing
                         your PIN over something you have already written is a
                         thing you can feel yourself doing, which is the whole
                         reason it is worth having. --}}
                    <div class="mt-3" x-data="{ locking: false, pin: '' }">
                        <button
                            x-show="!locking"
                            type="button"
                            @click="locking = true"
                            class="rounded-[12px] border border-fq-line-3 bg-fq-panel px-3 py-[6px] font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-3 uppercase transition hover:border-fq-violet"
                        >🔒 Lock this with my PIN</button>

                        <div x-show="locking" x-cloak>
                            <p class="text-[13px] text-fq-text-3">
                                Type your PIN to lock it. After that nobody opens it but you — and if
                                your PIN is ever changed, it can't be opened again by anyone.
                            </p>

                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <input
                                    type="password"
                                    inputmode="numeric"
                                    x-model="pin"
                                    @keydown.enter.prevent="$wire.lockFeeling(pin); pin = ''"
                                    placeholder="Your PIN"
                                    class="w-[130px] rounded-[12px] border border-fq-line-2 bg-fq-panel px-3 py-2 text-[14px] tracking-[0.3em] text-fq-text placeholder:tracking-normal placeholder:text-fq-text-5 focus:border-fq-violet focus:outline-none"
                                />
                                <button
                                    type="button"
                                    @click="$wire.lockFeeling(pin); pin = ''"
                                    :disabled="!pin"
                                    class="rounded-[12px] border border-fq-violet px-4 py-2 text-[13px] font-semibold transition enabled:hover:brightness-110 disabled:opacity-40"
                                    style="background: var(--fq-wash-violet); color: var(--fq-text)"
                                >Lock it</button>
                                <button
                                    type="button"
                                    @click="locking = false; pin = ''"
                                    class="text-[12px] text-fq-text-5 underline"
                                >Not now</button>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($lockMessage)
                    <p class="mt-2 font-mono-fq text-[11px]" style="color: var(--fq-streak)">{{ $lockMessage }}</p>
                @endif

                {{-- Changing your mind is a plain button, not an undo. Feelings
                     move during a day and the card should say so. --}}
                <button
                    type="button"
                    @click="editing = true"
                    class="mt-3 rounded-[12px] border border-fq-line-3 bg-fq-panel px-3 py-[6px] font-mono-fq text-[10px] tracking-[0.1em] text-fq-text-3 uppercase transition hover:border-fq-violet"
                >That's changed</button>
            </div>
        </div>

        {{-- ANSWERING. --}}
        <div x-show="editing" x-cloak>
            <div class="mt-3">
                <h3 class="font-baloo text-xl font-bold">How are you feeling today?</h3>

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach (Feeling::feelings() as $option)
                        <button
                            type="button"
                            wire:key="feeling-{{ $option->value }}"
                            @click="pick('{{ $option->value }}', @js($option->stem()))"
                            class="flex items-center gap-2 rounded-[14px] border px-3 py-[9px] text-[13px] font-semibold transition"
                            :style="feeling === '{{ $option->value }}'
                                ? 'border-color: {{ $option->cssVar() }}; background: color-mix(in srgb, {{ $option->cssVar() }} 16%, transparent); color: {{ $option->cssVar() }}'
                                : 'border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-3)'"
                        >
                            <span class="text-[15px]">{{ $option->glyph() }}</span>
                            <span>{{ $option->label() }}</span>
                        </button>
                    @endforeach
                    {{-- The house's words, in the same grid as the built-ins
                         rather than in a section of their own. A word somebody
                         added is not a lesser kind of answer, and penning them
                         into an "extras" row would say it was. --}}
                    @foreach ($words as $word)
                        <button
                            type="button"
                            wire:key="word-{{ $word->id }}"
                            @click="pick('{{ $word->id }}', @js($word->stem()))"
                            class="flex items-center gap-2 rounded-[14px] border px-3 py-[9px] text-[13px] font-semibold transition"
                            :style="feeling === '{{ $word->id }}'
                                ? 'border-color: {{ $word->cssVar() }}; background: color-mix(in srgb, {{ $word->cssVar() }} 16%, transparent); color: {{ $word->cssVar() }}'
                                : 'border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-3)'"
                        >
                            <span class="text-[15px]">{{ $word->displayGlyph() }}</span>
                            <span>{{ $word->label }}</span>
                        </button>
                    @endforeach

                    {{-- Adding one sits in the grid too, as the last chip.

                         Opening it drops whatever was picked. Reaching for your
                         own word means the one you had chosen is not the right
                         one, and leaving it lit — with its stem still reading
                         "Today I felt proud because…" underneath a box you are
                         using to write a different word — describes a feeling
                         nobody is claiming any more. --}}
                    <button
                        type="button"
                        @click="adding = !adding; if (adding) pick(null, '')"
                        class="flex items-center gap-2 rounded-[14px] border border-dashed px-3 py-[9px] text-[13px] font-semibold transition"
                        {{-- Lights like any other chip once there is something
                             in the box, because at that point it *is* the
                             chosen answer — the button below will create it. --}}
                        :style="newWord.trim()
                            ? 'border-color: var(--fq-violet); background: var(--fq-wash-violet); color: var(--fq-text)'
                            : 'border-color: var(--fq-line-3); background: transparent; color: var(--fq-text-4)'"
                    >
                        <span class="text-[15px]">+</span>
                        <span>Your own word</span>
                    </button>
                </div>

                {{-- The add panel. Type a word and it's yours from then on —
                     the twelve above are a starting vocabulary, not the whole
                     language, and a kid who can't find the word that fits is
                     being asked to round their feeling to the nearest one
                     somebody else picked. --}}
                <div x-show="adding" x-cloak class="mt-3 rounded-[16px] border border-fq-line-2 bg-fq-sunk p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- No Add button beside it, deliberately. Typing the
                             word *is* choosing it; the one button at the bottom
                             creates it and answers with it in a single go. An
                             Add step meant filling the whole card in and then
                             finding the answer button dead, which is a trap
                             that catches the same person every time. --}}
                        <input
                            type="text"
                            x-model="newWord"
                            :maxlength="maxWord"
                            @keydown.enter.prevent="submit()"
                            placeholder="wobbly, homesick, buzzing&hellip;"
                            class="w-full rounded-[12px] border border-fq-line-2 bg-fq-panel px-3 py-2 text-[14px] text-fq-text placeholder:text-fq-text-5 focus:border-fq-violet focus:outline-none"
                        />
                    </div>

                    {{-- Optional, and it says so. Picking a mark is the fun part
                         for some and friction for others; it must never be the
                         thing standing between a kid and naming a feeling. --}}
                    <p class="mt-3 font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-5 uppercase">A mark for it (optional)</p>

                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach ($glyphChoices as $choice)
                            <button
                                type="button"
                                wire:key="glyph-{{ $loop->index }}"
                                @click="newGlyph = (newGlyph === @js($choice) ? '' : @js($choice))"
                                class="h-9 w-9 rounded-[10px] border text-[15px] transition"
                                :style="newGlyph === @js($choice)
                                    ? 'border-color: var(--fq-violet); background: var(--fq-wash-violet)'
                                    : 'border-color: var(--fq-line-2); background: var(--fq-panel)'"
                            >{{ $choice }}</button>
                        @endforeach
                    </div>

                    {{-- The house's list, and it never says who added what. A
                         word is everyone's the moment it exists; naming its
                         author would turn "somebody here needed this word" into
                         a fact about one person, which is precisely the sort of
                         thing that stops the next word being added. --}}
                    @if ($words->isNotEmpty() && $canRetireWords)
                        <p class="mt-3 font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-5 uppercase">The house's words</p>

                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($words as $word)
                                <span
                                    wire:key="own-word-{{ $word->id }}"
                                    class="flex items-center gap-2 rounded-[10px] border border-fq-line-2 bg-fq-panel px-[10px] py-[5px] text-[12px]"
                                >
                                    <span>{{ $word->displayGlyph() }} {{ $word->label }}</span>
                                    {{-- Grown-ups only, and it retires rather
                                         than deletes: days already written with
                                         this word have to keep reading back
                                         correctly. A kid tapping this would be
                                         removing a word somebody else uses for
                                         how they feel, with nothing on screen
                                         to tell them it wasn't theirs. --}}
                                    <button
                                        type="button"
                                        wire:click="retireFeelingWord({{ $word->id }})"
                                        {{-- Clear the selection if the word
                                             being taken off the card was the
                                             one picked, or the form would sit
                                             there offering to submit an answer
                                             whose button has just gone. --}}
                                        @click="if (feeling === '{{ $word->id }}') pick(null, '')"
                                        aria-label="Take {{ $word->label }} off the card"
                                        class="text-fq-text-5 transition hover:text-fq-text-2"
                                    >&times;</button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Its own row, and worded as an answer rather than a refusal.
                     Without it, declining looks like not having done your
                     homework and a kid picks a feeling to avoid the awkwardness
                     — which is the mask going up by a different door. --}}
                <button
                    type="button"
                    @click="pick(@js(Feeling::NotSaying->value), @js(Feeling::NotSaying->stem()))"
                    class="mt-2 flex w-full items-center justify-center gap-2 rounded-[14px] border border-dashed px-3 py-[9px] text-[13px] font-semibold transition"
                    :style="silent
                        ? 'border-color: var(--fq-text-3); background: var(--fq-panel-alt); color: var(--fq-text-2)'
                        : 'border-color: var(--fq-line-3); background: transparent; color: var(--fq-text-4)'"
                >
                    <span>{{ Feeling::NotSaying->glyph() }}</span>
                    <span>{{ Feeling::NotSaying->label() }} today</span>
                </button>

                {{-- The stem, once there's a feeling to finish. "Today I felt
                     sad because…" does something "add a note" doesn't: it asks
                     for a reason without asking a question, which is the whole
                     difference for someone who goes quiet when questioned.

                     While a word is being added there is no word yet to put in
                     the sentence, so it falls back to the wordless form rather
                     than naming a feeling that has just been dropped. The box
                     stays open through it: somebody typing their own word often
                     knows why before they have settled on what to call it. --}}
                <div x-show="!silent && (feeling || adding)" x-cloak class="mt-4">
                    <label class="font-mono-fq text-[11px] text-fq-text-3">
                        <span x-text="stemLine"></span>
                    </label>

                    <textarea
                        x-model="because"
                        :maxlength="max"
                        rows="2"
                        placeholder="You don't have to fill this in."
                        class="mt-2 w-full rounded-[14px] border border-fq-line-2 bg-fq-sunk px-3 py-2 text-[14px] leading-relaxed text-fq-text placeholder:text-fq-text-5 focus:border-fq-violet focus:outline-none"
                    ></textarea>

                    {{-- Who may read the reason — never the feeling, which the
                         house always sees. Asked here, in the moment, rather
                         than living in settings: a kid who has to go and change
                         a setting to be private will never be private. --}}
                    <p class="mt-3 font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">Who can read why</p>

                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (FeelingVisibility::cases() as $level)
                            <button
                                type="button"
                                wire:key="visibility-{{ $level->value }}"
                                {{-- Moving off "just me" puts the lock away with
                                     it. Sharing and sealing are contradictory —
                                     a locked reason has no key anybody else
                                     holds — so the two controls can never be on
                                     at once. --}}
                                @click="visibility = '{{ $level->value }}'; if (visibility !== privateValue) { locking = false; lockPin = ''; }"
                                class="flex items-center gap-2 rounded-[12px] border px-3 py-[7px] text-[12px] font-semibold transition"
                                :style="visibility === '{{ $level->value }}'
                                    ? 'border-color: var(--fq-violet); background: var(--fq-wash-violet); color: var(--fq-text)'
                                    : 'border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-4)'"
                            >
                                <span>{{ $level->glyph() }}</span>
                                <span>{{ $level->label() }}</span>
                            </button>
                        @endforeach
                    </div>

                    <p class="mt-2 font-mono-fq text-[10px] text-fq-text-5">
                        @foreach (FeelingVisibility::cases() as $level)
                            <span x-show="visibility === '{{ $level->value }}'" x-cloak>{{ $level->description() }}</span>
                        @endforeach
                    </p>

                    {{-- Offered only under "just me", because that is the only
                         answer it can strengthen. Against "everyone" it would
                         be a control that contradicts the one above it.

                         And offered here, while they are still writing, rather
                         than as a second step afterwards: the old order saved
                         the words in the clear and *then* asked about sealing
                         them, which left a window — brief, but real, and worse,
                         felt — where the truest thing on the page was lying
                         about unlocked. Sealing on the way in means the
                         plaintext never reaches the database at all. See
                         FeelingService::record(). --}}
                    <div
                        x-show="visibility === privateValue"
                        x-cloak
                        class="mt-3 rounded-[14px] border p-3"
                        :style="lockingNow
                            ? 'border-color: var(--fq-violet); background: var(--fq-wash-violet)'
                            : 'border-color: var(--fq-line-2); background: var(--fq-sunk)'"
                    >
                        <button
                            type="button"
                            @click="locking = !locking; lockPin = ''"
                            class="flex w-full items-center gap-2 text-left text-[13px] font-semibold"
                            :style="lockingNow ? 'color: var(--fq-text)' : 'color: var(--fq-text-3)'"
                        >
                            <span x-text="lockingNow ? '🔒' : '🔓'"></span>
                            <span>Lock it with my PIN</span>
                        </button>

                        <div x-show="lockingNow" x-cloak class="mt-2">
                            <p class="text-[12px] leading-relaxed text-fq-text-3">
                                It gets locked as it saves, so it is never written down unlocked.
                                Nobody opens it but you — and if your PIN is ever changed, nobody
                                opens it at all.
                            </p>

                            <input
                                type="password"
                                inputmode="numeric"
                                x-model="lockPin"
                                @keydown.enter.prevent="submit()"
                                placeholder="Your PIN"
                                class="mt-2 w-[140px] rounded-[12px] border border-fq-line-2 bg-fq-panel px-3 py-2 text-[14px] tracking-[0.3em] text-fq-text placeholder:tracking-normal placeholder:text-fq-text-5 focus:border-fq-violet focus:outline-none"
                            />
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    :disabled="!answerable || (lockingNow && !lockPin)"
                    @click="submit()"
                    class="mt-4 w-full rounded-[16px] border p-[12px_16px] font-baloo text-[15px] font-extrabold transition enabled:hover:brightness-110 disabled:opacity-40"
                    style="border-color: var(--fq-violet); background: var(--fq-wash-violet); color: var(--fq-text)"
                    {{-- Says what the press will actually do. Somebody locking
                         should see the lock named on the button they are about
                         to hit, not find out afterwards. --}}
                    x-text="lockingNow ? 'Lock it and save' : 'That\'s me today'"
                >That's me today</button>

                <p
                    x-show="saveError"
                    x-cloak
                    class="mt-2 font-mono-fq text-[11px]"
                    style="color: var(--fq-streak)"
                    x-text="saveError"
                ></p>
            </div>
        </div>
    </div>

    {{-- The house. Covered until you've answered — see FeelingService. --}}
    <div class="mt-5 border-t border-fq-divider pt-4">
        @if ($house === null)
            <div class="rounded-[16px] border border-dashed border-fq-line-3 px-4 py-5 text-center">
                <p class="font-baloo text-[15px] font-bold text-fq-text-3">How is everyone else doing?</p>
                <p class="mt-1 text-[13px] text-fq-text-4">
                    Say yours first and the rest of the house opens up.
                </p>
            </div>
        @else
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">The house today</p>
                @if ($waiting > 0)
                    <span class="font-mono-fq text-[10px] text-fq-text-5">
                        {{ $waiting }} still to go
                    </span>
                @endif
            </div>

            <div class="mt-3 flex flex-col gap-2">
                @foreach ($house as $row)
                    @php
                        $person = $row['profile'];
                        $entry = $row['entry'];
                    @endphp

                    <div
                        wire:key="house-feeling-{{ $person->id }}"
                        class="rounded-[14px] border px-3 py-[10px]"
                        style="border-color: var(--fq-line-2); background: var(--fq-sunk)"
                    >
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="text-[18px]">{{ $entry?->glyph() ?: '·' }}</span>
                            <span class="font-baloo text-[15px] font-bold">{{ $person->name }}</span>

                            @if ($entry)
                                <span
                                    class="rounded-full border px-[10px] py-[3px] font-mono-fq text-[10px] tracking-[0.08em] uppercase"
                                    style="color: {{ $entry->color() }}; border-color: color-mix(in srgb, {{ $entry->color() }} 45%, transparent)"
                                >{{ $entry->label() }}</span>
                            @else
                                {{-- An absence, drawn as an absence. Not having
                                     answered yet is not a failure and must not
                                     look like one. --}}
                                <span class="font-mono-fq text-[10px] text-fq-text-5">hasn't said yet</span>
                            @endif
                        </div>

                        @if ($row['because'] !== null)
                            <p class="mt-[6px] text-[13px] leading-relaxed text-fq-text-2">{{ $row['because'] }}</p>
                        @elseif ($entry?->hasBecause())
                            {{-- Says a reason exists without leaking it. Better
                                 than silence: it shows the private option is
                                 real and being used, which is what makes it
                                 worth trusting. --}}
                            <p class="mt-[6px] font-mono-fq text-[10px] text-fq-text-5">🔒 kept private</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
