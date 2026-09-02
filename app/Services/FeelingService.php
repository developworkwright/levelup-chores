<?php

namespace App\Services;

use App\Enums\Feeling;
use App\Enums\FeelingVisibility;
use App\Models\FeelingEntry;
use App\Models\FeelingWord;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use SensitiveParameter;

/**
 * The feelings card: one question the whole house answers, every day.
 *
 * See the create migration for why it pays nothing, why parents answer too, and
 * why the feeling is public while the reason is not.
 *
 * ## Answer before you can see
 *
 * {@see self::houseToday()} returns null until the viewer has answered. That is
 * not a nicety — it is most of what makes the answers honest. A kid who can
 * read the room first sees three "okay"s and posts a fourth; the same kid,
 * asked to go first, posts what he actually has. The gate lives here rather
 * than in the card so that a view written later can't quietly skip it.
 *
 * ## Nothing happens when the answer is a bad one
 *
 * There is deliberately no notification, no flag, no parent alert and no
 * highlight for a low answer. The instant a hard answer summons a parent,
 * saying something has a consequence attached and the honest answer stops being
 * free. Whatever a parent does about what they read, they do it because they
 * looked — not because the app went and fetched them.
 *
 * For the same reason there is no trend, no average and no history chart. A
 * graph of yourself is a thing to manage, and the kid this was built for is
 * very good at managing.
 */
class FeelingService
{
    /** Long enough for a real reason, short enough to stay a sentence or two. */
    public const MAX_BECAUSE = 500;

    /** The day the card is asking about. */
    public function todaysDate(Household $household): Carbon
    {
        return HouseholdClock::for($household)->today();
    }

    public function todayFor(Profile $profile, ?Carbon $date = null): ?FeelingEntry
    {
        $date ??= $this->todaysDate($profile->household);

        return FeelingEntry::where('profile_id', $profile->id)
            ->whereDate('felt_on', $date)
            ->with('word')
            ->first();
    }

    public function hasAnswered(Profile $profile): bool
    {
        return $this->todayFor($profile) !== null;
    }

    /**
     * Record today's answer, or change it.
     *
     * Updates rather than appends, because a feeling moves during a day and
     * being allowed to say so is part of the point. There is no record kept of
     * what was said earlier: a version history would make changing your mind
     * something that leaves a trace, and then it isn't free either.
     *
     * `NotSaying` clears any reason and forces the entry private — the answer
     * is that they would rather not go into it, and storing a leftover
     * "because" from a previous answer under it would contradict that.
     *
     * ## Locking happens here, before anything is written
     *
     * Pass `$lockPin` and the reason is sealed on its way in, so the plaintext
     * never reaches the database at all. Locking used to be a second step after
     * saving, which left a window — short, but real — where the words sat in
     * the clear, and a kid could feel it: you have just written the truest thing
     * on the page and it is lying there unlocked while you go and find the
     * button. Sealing at entry closes that.
     *
     * A wrong PIN saves **nothing** and returns null. Falling back to saving it
     * unlocked would put the text in the one place they were trying to keep it
     * out of, at the exact moment they had said not to.
     */
    public function record(
        Profile $profile,
        Feeling|FeelingWord $feeling,
        ?string $because = null,
        FeelingVisibility $visibility = FeelingVisibility::Private,
        #[SensitiveParameter] ?string $lockPin = null,
    ): ?FeelingEntry {
        $household = $profile->household;

        $because = mb_substr(trim((string) $because), 0, self::MAX_BECAUSE);

        if ($feeling === Feeling::NotSaying) {
            $because = '';
            $visibility = FeelingVisibility::Private;
            $lockPin = null;
        }

        $sealed = null;

        if ($lockPin !== null && $lockPin !== '' && $because !== '') {
            // Checked before a single byte is written. Sealing under a mistyped
            // PIN would make an entry nobody could ever open, and saving it in
            // the clear instead would be worse still.
            if (! $profile->checkPin($lockPin)) {
                return null;
            }

            $sealed = app(FeelingLock::class)->seal($lockPin, $because);

            // Locked is private by definition — nobody else holds the key, so
            // any other label on it would be a lie.
            $visibility = FeelingVisibility::Private;
        }

        return FeelingEntry::updateOrCreate(
            [
                'profile_id' => $profile->id,
                'felt_on' => $this->todaysDate($household),
            ],
            [
                'household_id' => $household->id,
                // Exactly one of the two, and the other is cleared rather than
                // left behind — an entry changed from a custom word back to a
                // built-in would otherwise carry both and render whichever the
                // model happened to check first.
                'feeling' => $feeling instanceof Feeling ? $feeling : null,
                'feeling_word_id' => $feeling instanceof FeelingWord ? $feeling->id : null,
                // Exactly one of these too. When it was sealed the plaintext
                // column is left empty, which is the whole point — and when it
                // wasn't, the lock columns are cleared, because answering again
                // in the clear must not leave a padlock drawn over text anybody
                // can read.
                'because' => $sealed || $because === '' ? null : $because,
                'because_locked' => $sealed['sealed'] ?? null,
                'lock_salt' => $sealed['salt'] ?? null,
                'locked_at' => $sealed ? now() : null,
                'visibility' => $visibility,
            ],
        );
    }

    /**
     * The household's own words, oldest first.
     *
     * Shared rather than per-person, and **never attributed** — see the
     * household-wide migration. Everyone sees the same list on their own card.
     *
     * @return Collection<int, FeelingWord>
     */
    public function wordsFor(Household $household): Collection
    {
        return FeelingWord::where('household_id', $household->id)
            ->active()
            ->orderBy('id')
            ->get();
    }

    /**
     * Seal today's reason with the writer's own PIN.
     *
     * False when there is nothing to lock, when the PIN is not theirs, or when
     * it is not their entry. Locking is not something a parent can do on a
     * kid's behalf: the whole point is that the person who wrote it is the only
     * one who can open it.
     *
     * Locking forces the entry private, because it now is: "shared with Mom and
     * Dad" over text nobody but the writer can decrypt would be a label that
     * lies. See {@see FeelingLock} for what the lock does and does not promise.
     */
    public function lock(Profile $profile, #[SensitiveParameter] string $pin): bool
    {
        $entry = $this->todayFor($profile);

        if (! $entry || $entry->isLocked() || ! $entry->hasBecause()) {
            return false;
        }

        // Checked against their real PIN before anything is sealed. Encrypting
        // under a mistyped PIN would produce an entry they could never open,
        // and they would have no way of knowing until they tried.
        if (! $profile->checkPin($pin)) {
            return false;
        }

        $sealed = app(FeelingLock::class)->seal($pin, (string) $entry->because);

        $entry->forceFill([
            'because' => null,
            'because_locked' => $sealed['sealed'],
            'lock_salt' => $sealed['salt'],
            'locked_at' => now(),
            'visibility' => FeelingVisibility::Private,
        ])->save();

        return true;
    }

    /**
     * Read a locked reason back. Null when the PIN is wrong.
     *
     * Returns the text rather than storing it: nothing is written back, no
     * cache, no session. Opening it is a look, not a state change — close the
     * page and it is sealed again exactly as it was.
     *
     * Only ever the writer's own. `profile_id` is part of the lookup rather
     * than checked afterwards.
     */
    public function openLocked(Profile $profile, int $entryId, #[SensitiveParameter] string $pin): ?string
    {
        $entry = FeelingEntry::where('profile_id', $profile->id)->find($entryId);

        if (! $entry || ! $entry->isLocked()) {
            return null;
        }

        return app(FeelingLock::class)->open($pin, (string) $entry->lock_salt, (string) $entry->because_locked);
    }

    /** Collapses whitespace, caps the length, and trims what the cap left behind. */
    private function normalizeLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');

        // Trimmed again after the cap: slicing at the limit lands mid-word as
        // often as not, and a label stored with a trailing space compares
        // unequal to the same word typed again.
        return trim(mb_substr($label, 0, FeelingWord::MAX_LABEL));
    }

    /** The built-in feeling of that name, if there is one. */
    private function builtInNamed(string $label): ?Feeling
    {
        foreach (Feeling::cases() as $feeling) {
            if (mb_strtolower($feeling->label()) === mb_strtolower($label)) {
                return $feeling;
            }
        }

        return null;
    }

    /**
     * Turn typed text into something answerable.
     *
     * There is no separate "add" step on the card any more — somebody types
     * their word and presses the one button, exactly as they would with any
     * other answer. Making them add it first meant filling in the whole card
     * and then finding the button dead because of a step nobody thinks to take.
     *
     * Typing the name of a built-in hands back the built-in rather than
     * refusing: a person who types "happy" means happy, and the difference
     * between typing it and tapping it is not one they should have to care
     * about.
     */
    public function resolveTypedWord(Profile $profile, ?string $label, ?string $glyph = null): Feeling|FeelingWord|null
    {
        $label = $this->normalizeLabel((string) $label);

        if ($label === '') {
            return null;
        }

        return $this->builtInNamed($label) ?? $this->addWord($profile, $label, $glyph);
    }

    /**
     * Add a word to the house's card.
     *
     * Null when there is nothing to add or the word is already there — under a
     * built-in name, or one the house already has. Silently doing nothing is
     * right here: the failure a person can cause is a duplicate chip, and an
     * error about it would be a telling-off for trying to name a feeling, which
     * is the one thing this card must never do.
     *
     * A retired word comes back rather than being re-added, so its old entries
     * keep pointing at the same row.
     */
    public function addWord(Profile $profile, string $label, ?string $glyph = null): ?FeelingWord
    {
        $label = $this->normalizeLabel($label);

        if ($label === '') {
            return null;
        }

        // Built-ins already occupy their own names; a second "Happy" chip would
        // be two buttons that mean the same thing.
        if ($this->builtInNamed($label)) {
            return null;
        }

        // Matched across the whole house, so two people can't end up with two
        // spellings of the same word sitting side by side in one grid.
        $existing = FeelingWord::where('household_id', $profile->household_id)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->first();

        if ($existing) {
            $existing->update(['active' => true, 'glyph' => $glyph ?: $existing->glyph]);

            return $existing;
        }

        return FeelingWord::create([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            'label' => $label,
            'glyph' => $glyph ?: null,
            'active' => true,
        ]);
    }

    /**
     * Take a word off the house's card. Retired rather than deleted, because
     * entries already written with it have to keep rendering.
     *
     * **Grown-ups only.** Now that the list is shared and unattributed, a kid
     * tapping the cross would be deleting a word somebody else uses for how
     * they feel — and, since nothing says who added what, without any way of
     * telling that it wasn't theirs to remove. Adding is open to everyone;
     * taking away is not.
     */
    public function retireWord(Profile $profile, int $wordId): bool
    {
        if (! $profile->isParent()) {
            return false;
        }

        $word = FeelingWord::where('household_id', $profile->household_id)->find($wordId);

        return $word ? $word->update(['active' => false]) : false;
    }

    /**
     * Resolve what the card sent into something {@see self::record()} accepts.
     *
     * Built-ins arrive as their enum value; a custom word arrives as its id.
     * The id is looked up *against this profile*, so a hand-edited request
     * can't post somebody else's word — or a retired one, which is no longer on
     * the card it came from.
     */
    public function resolveAnswer(Profile $profile, ?string $answer): Feeling|FeelingWord|null
    {
        // Nullable because that is what the card actually sends. Alpine holds
        // the selection as null until a chip is pressed, and somebody who only
        // types their own word never presses one — so `null` arrives here on
        // the most ordinary path there is, not as an edge case.
        $answer = (string) $answer;

        if ($built = Feeling::tryFrom($answer)) {
            return $built;
        }

        if (! ctype_digit($answer)) {
            return null;
        }

        return FeelingWord::where('household_id', $profile->household_id)
            ->active()
            ->find((int) $answer);
    }

    /**
     * Everyone's answer for today, as the viewer is allowed to see it.
     *
     * Null until the viewer has answered — see the class docblock.
     *
     * Every member of the household gets a row whether or not they have
     * answered, so the strip shows who hasn't yet as an absence rather than
     * silently leaving them out. An absence is not a failure and the card must
     * not draw it as one; it is simply a person who hasn't got to it.
     *
     * @return Collection<int, array{profile: Profile, entry: ?FeelingEntry, because: ?string}>|null
     */
    public function houseToday(Profile $viewer): ?Collection
    {
        if (! $this->hasAnswered($viewer)) {
            return null;
        }

        $household = $viewer->household;

        $entries = FeelingEntry::where('household_id', $household->id)
            ->whereDate('felt_on', $this->todaysDate($household))
            // Eager, because the strip asks every row for its label, glyph and
            // color — a lazy load here is a query per person in the house.
            ->with('word')
            ->get()
            ->keyBy('profile_id');

        return $household->profiles()
            ->orderBy('name')
            ->get()
            ->map(function (Profile $profile) use ($entries, $viewer) {
                $entry = $entries->get($profile->id);

                return [
                    'profile' => $profile,
                    'entry' => $entry,
                    // Resolved here rather than in the view, so a template can
                    // never accidentally print a reason it shouldn't have.
                    'because' => $entry?->becauseVisibleTo($viewer) ? $entry->because : null,
                ];
            })
            ->values();
    }

    /**
     * Everything the card draws.
     *
     * @return array{answered: ?FeelingEntry, house: ?Collection<int, array{profile: Profile, entry: ?FeelingEntry, because: ?string}>, words: Collection<int, FeelingWord>, canRetireWords: bool, waiting: int}
     */
    public function cardFor(Profile $profile): array
    {
        $house = $this->houseToday($profile);

        return [
            'answered' => $this->todayFor($profile),
            'house' => $house,
            // The house's words, the same list on everybody's card and with no
            // sign of who added which — see the household-wide migration.
            'words' => $this->wordsFor($profile->household),
            // Whether to draw the cross beside them. Adding is everyone's;
            // removing is a grown-up's, see retireWord().
            'canRetireWords' => $profile->isParent(),
            // How many people the strip is still waiting on. Said as a count
            // rather than as names: "three still to go" is news about the day,
            // and a list of who hasn't answered is a list of people to chase.
            'waiting' => $house?->whereNull('entry')->count() ?? 0,
        ];
    }
}
