<?php

use App\Enums\CompletionStatus;
use App\Enums\Feeling;
use App\Enums\FeelingVisibility;
use App\Enums\PerkEffect;
use App\Exceptions\PerkUnavailableException;
use App\Models\Chore;
use App\Models\Profile;
use App\Services\CelebrationService;
use App\Services\ChestService;
use App\Services\ChoreService;
use App\Services\FeedService;
use App\Services\FeelingService;
use App\Services\GiftService;
use App\Services\GratitudeService;
use App\Services\HouseholdClock;
use App\Services\HouseholdService;
use App\Services\MealService;
use App\Services\PerkInventoryService;
use App\Services\SpinService;
use App\Services\StreakService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

/**
 * Home — the day, in the order it usually goes.
 *
 * The bonus chest and the streak chest — the two a kid opens themselves — then
 * the weekly prize the house chases together. Every
 * other kid page is organised by *what kind of thing* it holds, which works
 * fine once you know what you're looking for and is no help at all to a kid
 * asking "what now?" — this one is organised by when.
 *
 * Three things have left it, all for the same reason: a page that answers
 * "what do I do now" should hold nothing that only answers "what happened".
 * The house standings went to Household, where the full table already was; the
 * Quote of the Day went into the family feed, which is where the house is
 * talking; and the feelings card folds to a line once it has been answered,
 * because everybody's answers are on the Family page now.
 *
 * The boss fight left too, watcher and all, for Quests: every hit on the
 * monster is a chore off that board, so that is where it stands.
 *
 * Deliberately not numbered. The order is the habit, not a rule: nothing here is
 * gated on anything above it, and a kid who wants to open the second chest
 * first is not doing it wrong.
 *
 * Everything acts in place — a landing page that can only point at things is a
 * menu rather than a start. The one exception is the work itself: the daily
 * quest used to sit at the top of this page as a chest to open, and with it
 * gone there is no chore on Home at all. That is deliberate rather than an
 * omission. A single card saying "here is today's chore" was the whole of the
 * mechanic being removed; what replaced it is a board with everything on it,
 * and a board belongs on Quests.
 *
 * Quests keeps what this page doesn't: the bonus wheel, the board and its
 * charms, the boss fight, the mystery chore, the bounty board, the sleep card.
 *
 * Under the five rows of the day sit the ones about the house rather than the
 * work — the Daily Gift (for a kid with a sibling), Feelings, Gratitude and
 * Meals. They open like the rest but are not part of the "n of 5": a feeling is
 * never a task to tick off, nobody finishes dinner by reading the menu, and a
 * gift that counted toward your own day would be given for the tick.
 */
new class extends Component
{
    public Profile $profile;

    public ?string $perkMessage = null;

    /**
     * Snapshotted at mount, NOT recomputed in with(): opening banks the reward
     * and re-renders, and a `revealed` flag recomputed on that round trip would
     * yank the chest out from under its own 2.6s animation.
     */
    public bool $chestOpened = false;

    /** What the bonus chest held — carried into the reveal card and the overlay. */
    public ?string $dailyChestPrize = null;

    /**
     * The milestone waiting to be opened, and what it pays. Snapshotted at mount
     * for the same reason as the bonus chest — opening clears the underlying
     * flag, and recomputing would pull the chest out mid-animation.
     */
    public ?int $pendingChestDay = null;

    public ?int $pendingChestPoints = null;

    /**
     * Which row of "Your day" is open, by key, or null for none.
     *
     * One at a time, and every visit starts with none, the way parent Home
     * does. The index is the page: a kid arriving should read every row's
     * status at a glance, and an open panel pushes most of them off a phone.
     * Nothing is remembered and nothing opens itself, not even a waiting streak
     * chest — its row says READY, which is the nudge.
     *
     * The one way in with a row open is a link that names it (`?row=`).
     */
    public ?string $openRow = null;

    /** Every row a link may ask for with `?row=`. */
    private const ROWS = ['work', 'chest', 'wheel', 'streak', 'prize', 'gift', 'feelings', 'gratitude', 'meals'];

    /**
     * The three boxes of the gratitude quest. Deferred rather than live —
     * nothing on the page reacts to a half-typed answer, so there's no reason
     * to spend a round trip per keystroke.
     *
     * @var array<int, string>
     */
    public array $gratitude = ['', '', ''];

    /**
     * Whether today's list goes up on the family feed's Grateful today card.
     *
     * On by default, and a box rather than a setting: a kid who has to go and
     * find a preference in order to keep one line to themselves will never keep
     * anything to themselves. See the `shared` column's migration.
     */
    public bool $gratitudeShared = true;

    public ?string $gratitudeMessage = null;

    /** Why the suggested job couldn't be taken, when a tap on it bounces. */
    public ?string $workMessage = null;

    /**
     * What a pet saved while nobody was looking — a Guard Dog's streak, a
     * Night Owl's run — told once, on the visit after it happened. Taken at
     * mount so a round trip does not lose it. See KnackService::takeRescues().
     *
     * @var array<int, string>
     */
    public array $petRescues = [];

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isKid(), 403);

        $this->petRescues = app(App\Services\KnackService::class)->takeRescues($this->profile);

        $this->feelingsAnsweredOnArrival = app(FeelingService::class)->hasAnswered($this->profile);
        // A link that names a row opens it — the Journal's "write today's", the
        // feed's gratitude arrow and the gift push land a kid on the thing they
        // were sent to rather than on a shut index.
        $requested = request()->query('row');

        if (is_string($requested) && in_array($requested, self::ROWS, true)) {
            $this->toggleRow($requested);
        }

        $chests = app(ChestService::class);
        $openedChest = $chests->openedToday($this->profile);

        $this->chestOpened = $openedChest !== null;
        $this->dailyChestPrize = $openedChest ? $chests->describe($openedChest) : null;

        $this->pendingChestDay = $this->profile->pending_streak_chest;
        // Milestone bonuses are denominated in dollars, but every other number a
        // kid sees is points — so convert once here and never show dollars. Read
        // through pendingStreakChestDollars() rather than off the base map or
        // even off the day on the lid: the track repeats past day 30, and a
        // chest can be holding more than one milestone.
        $this->pendingChestPoints = $this->pendingChestDay
            ? app(StreakService::class)->pendingStreakChestDollars($this->profile) * $this->profile->household->points_per_dollar
            : null;
    }

    /**
     * Opens a row, or shuts the open one. Only ever one at a time: without that
     * the column grows back into the page of stacked heroes this replaced.
     *
     * Opening the gift row is what reads the gifts in it — the row's alert is
     * for gifts nobody has looked at yet.
     */
    public function toggleRow(string $key): void
    {
        $this->openRow = $this->openRow === $key ? null : $key;

        if ($this->openRow === 'gift') {
            app(GiftService::class)->markSeen($this->profile);
        }
    }

    /**
     * Takes the one job the Work row suggests, without leaving Home.
     *
     * Everything on this page acts in place, and this is the piece that has to:
     * a suggestion a kid has to go to another page to accept is a link, and the
     * quest it replaces never needed one.
     */
    public function claimSuggested(int $choreId): void
    {
        $this->workMessage = null;

        $service = app(ChoreService::class);
        $chore = Chore::find($choreId);

        // Re-checked server-side rather than trusted from the button: the
        // suggestion may be minutes old, and a sibling can have taken it since.
        if (! $chore
            || $chore->household_id !== $this->profile->household_id
            || ! $chore->isAppropriateFor($this->profile)
            || $service->stateFor($this->profile, $chore) !== 'ready') {
            $this->workMessage = 'That one just went — here is another.';

            return;
        }

        $service->claim($this->profile, $chore);

        $this->dispatch(
            'celebrate',
            message: "{$chore->name} claimed! Waiting on parent.",
            motion: 'burst',
            origin: 'tap',
        );
    }

    /**
     * The gratitude quest. Both refusals are worth their own wording: one is
     * "you missed a box", the other is "you already did this today", and a
     * button that silently does nothing explains neither.
     */
    public function logGratitude(): void
    {
        $service = app(GratitudeService::class);

        if ($service->record($this->profile, $this->gratitude, $this->gratitudeShared)) {
            $this->gratitude = ['', '', ''];
            $this->gratitudeShared = true;
            $this->gratitudeMessage = null;

            // Hearts, not coins — this is the one quest that isn't about
            // earning anything, and the tickets are a thank-you rather than
            // the point of it.
            $this->dispatch(
                'celebrate',
                message: 'Gratitude logged! +'.GratitudeService::TICKETS.' tickets.',
                style: 'heart',
                motion: 'burst',
                origin: 'tap',
            );

            return;
        }

        $this->gratitudeMessage = $service->isAvailable($this->profile)
            ? 'Fill in all three before you hand it in.'
            : "Today's gratitude quest is already done — back tomorrow!";
    }

    /**
     * Today's gift, to the sibling picked. The service re-checks both the
     * sibling and the day, so a stale page can only ever fail to give — never
     * give twice.
     */
    public function giveGift(int $recipientId): void
    {
        $gift = app(GiftService::class)->give($this->profile, $recipientId);

        if ($gift === null) {
            return;
        }

        $this->dispatch(
            'celebrate',
            message: "{$gift->recipient->name} got your ticket!",
            style: 'heart',
            motion: 'burst',
            origin: 'tap',
        );
    }

    public function openDailyChest(): void
    {
        $chests = app(ChestService::class);

        // Nothing to open means today's chest went elsewhere — another tab, or
        // a back-button visit to a page rendered before it was. Describe the
        // one that actually exists rather than revealing an empty card: a prize
        // overlay with nothing on it is the one outcome a chest must never show.
        $chest = $chests->open($this->profile) ?? $chests->openedToday($this->profile);

        if ($chest) {
            $this->dailyChestPrize = $chests->describe($chest);
        }

        // Moves the snapshot on with the animation rather than against it. The
        // chest itself is already revealing client-side by the time this lands;
        // this is what keeps it revealed on the next full page load.
        $this->chestOpened = true;
    }

    public function openStreakChest(): void
    {
        app(StreakService::class)->openStreakChest($this->profile);
    }

    /**
     * How a celebration day went, in that day's own words.
     *
     * Recorded and then never read by anything that pays — see
     * CelebrationService. This exists so the chest below it can open; what was
     * said has no bearing on what it holds.
     */
    public function answerCelebration(string $answer, ?string $note = null): void
    {
        $celebrations = app(CelebrationService::class);
        $day = $celebrations->activeFor($this->profile->household);

        if ($day === null) {
            return;
        }

        $celebrations->answer($this->profile, $day['key'], $answer, $note);
    }

    public function openCelebrationChest(): void
    {
        $celebrations = app(CelebrationService::class);
        $day = $celebrations->activeFor($this->profile->household);

        if ($day === null) {
            return;
        }

        $celebrations->open($this->profile, $day['key']);
    }

    /**
     * Today's feeling, and optionally why.
     *
     * No celebration on purpose — not even a quiet one. A card that throws
     * confetti at an answer is paying for it, and the moment answering pays,
     * the fastest answer beats the true one. The reward for pressing the button
     * is that the rest of the house opens up underneath it.
     *
     * Anything unrecognised is dropped rather than defaulted: a feeling nobody
     * picked must never end up recorded as one they did.
     *
     * Returns whether it saved, so the card knows whether to close the form. A
     * wrong PIN on a locked answer saves nothing at all, and the words have to
     * still be on screen when they try again.
     */
    public function answerFeeling(
        ?string $feeling = null,
        ?string $because = null,
        ?string $visibility = null,
        ?string $newWord = null,
        ?string $newGlyph = null,
        ?string $lockPin = null,
    ): bool {
        $this->feelingLockMessage = null;

        $service = app(FeelingService::class);

        // A word typed into the box wins over a chip, and is created here
        // rather than by a separate button — see resolveTypedWord(). The two
        // can't normally both be set, because picking a chip clears the box.
        //
        // Falling back to the chip: either a built-in or one of the house's
        // words, resolved against this household so a hand-edited request can't
        // post a word from somebody else's family.
        $choice = $service->resolveTypedWord($this->profile, $newWord, $newGlyph)
            ?? $service->resolveAnswer($this->profile, $feeling);

        if (! $choice) {
            return false;
        }

        $saved = $service->record(
            $this->profile,
            $choice,
            $because,
            FeelingVisibility::tryFrom((string) $visibility) ?? FeelingVisibility::Private,
            $lockPin,
        );

        if (! $saved) {
            $this->feelingLockMessage = 'That PIN did not match. Nothing was saved — your words are still here.';

            return false;
        }

        return true;
    }

    /**
     * What a locked entry currently reads, for this render only.
     *
     * Deliberately not a persisted property that survives the round trip: the
     * text is held for exactly as long as it takes to draw it once, and any
     * other action on the page puts it away again. Opening a locked entry is a
     * look, not a state change.
     */
    public ?string $openedFeeling = null;

    public ?string $feelingLockMessage = null;

    /**
     * Whether the kid has asked for the answered card back on this visit.
     *
     * Home shows the feelings card as a *prompt*, which is a job it stops doing
     * once it has been answered — the house's answers live on the Family page
     * now, and a second copy of that strip here would be two screens that can
     * disagree. What is left in its place is one line saying what you picked,
     * and this, which puts the card back: feelings move during a day and
     * changing your answer has to stay possible from where you answered it.
     */
    public bool $showFeelings = false;

    /**
     * Whether today was already answered when this visit started.
     *
     * Snapshotted at mount for the same reason the chest's `chestOpened` is:
     * answering *during* this visit must not fold the card out from under the
     * moment. The house opening up underneath is the whole reward for pressing
     * the button, and locking the reason is reached from the card too — both
     * would vanish mid-tap if this were recomputed in with().
     */
    public bool $feelingsAnsweredOnArrival = false;

    /** Seals today's reason with this kid's own PIN. */
    public function lockFeeling(string $pin): void
    {
        $this->openedFeeling = null;

        $this->feelingLockMessage = app(FeelingService::class)->lock($this->profile, $pin)
            ? null
            : 'That PIN did not match, so nothing was locked.';
    }

    /** Reads a locked entry back. The PIN is the key; there is no other way in. */
    public function openFeeling(int $entryId, string $pin): void
    {
        $this->feelingLockMessage = null;
        $this->openedFeeling = app(FeelingService::class)->openLocked($this->profile, $entryId, $pin);

        if ($this->openedFeeling === null) {
            $this->feelingLockMessage = 'That PIN did not open it.';
        }
    }

    public function retireFeelingWord(int $wordId): void
    {
        app(FeelingService::class)->retireWord($this->profile, $wordId);
    }

    /**
     * The one perk with a button on this page: the Streak Restore on the rescue
     * card. The charm is cast over the board, so it is bought and spent on
     * Quests, where the board is.
     */
    public function usePerk(string $effect): void
    {
        $case = PerkEffect::tryFrom($effect);

        if (! $case) {
            return;
        }

        try {
            $outcome = app(PerkInventoryService::class)->use($this->profile, $case);
            $this->perkMessage = null;
        } catch (PerkUnavailableException $e) {
            $this->perkMessage = $e->getMessage();

            return;
        }

        // Same styles as the Bonus Shop's own copy of this — a perk used from
        // here and one used from the shop are the same moment and must not
        // celebrate differently.
        $this->dispatch('celebrate', message: $outcome, style: $case->celebrationStyle(), motion: 'burst', origin: 'tap');
    }

    public function with(): array
    {
        $streaks = app(StreakService::class);
        $spins = app(SpinService::class);
        $inventory = app(PerkInventoryService::class);

        // A Livewire round trip doesn't pass back through the route middleware
        // that expires a lapsed streak, and a kid can sit on this page across
        // the household rollover.
        app(StreakService::class)->syncStreak($this->profile);

        $boost = $spins->today($this->profile);

        $household = $this->profile->household;
        $chores = app(ChoreService::class);
        $chests = app(ChestService::class);

        $rate = max(1, (int) $household->points_per_dollar);
        $money = fn (int $points) => '$'.number_format($points / $rate, 2);

        // --- Work: today's tally, and the one job to point at when it is empty.
        $work = $chores->workTodayFor($this->profile);
        $earned = (int) $work->where('status', '!=', CompletionStatus::Rejected)->sum('points_awarded');
        $waiting = $work->where('status', CompletionStatus::Pending)->count();

        $daySecured = $streaks->streakDaySecuredToday($this->profile);
        $chestAvailable = $chests->isAvailable($this->profile);
        $spunToday = $boost !== null;
        $houseWeek = app(HouseholdService::class)->houseWeek($household);
        $streakWindow = $streaks->streakWindowFor($this->profile);

        // The countdown the streak row wears when nothing is in yet. Rendered
        // server-side as a coarse "4h 51m": the live tick belongs to the timer
        // inside the panel, and a row that re-rendered every minute would be a
        // round trip a minute for a number nobody is watching.
        $closesAt = $streakWindow['closesAt'] ?? null;
        $leftInWords = $closesAt && $closesAt->isFuture()
            ? $closesAt->diffForHumans(['parts' => 2, 'short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE])
            : null;

        /*
         * The five rows of "Your day": the work, the two things waiting to be
         * opened, the run, and then the prize the house is chasing together.
         * The fight was the sixth and went to Quests, beside the board that
         * hurts it.
         *
         * `done` is what greys a row and what the "n of 5" counts. The prize
         * is news rather than a task, so it is drawn quiet from the start —
         * see `quiet`.
         */
        $dayRows = [
            [
                'key' => 'work',
                'glyph' => '⚒',
                'label' => 'Work',
                'accent' => 'var(--fq-gold)',
                'tileLabel' => 'Work',
                'sub' => $work->isEmpty()
                    ? 'Nothing in yet'
                    : $work->count().' '.Str::plural('job', $work->count()).($waiting > 0 ? ' · '.$waiting.' waiting' : ''),
                'status' => $earned > 0 ? $money($earned).' TODAY' : 'NOTHING YET',
                'statusColor' => $earned > 0 ? 'var(--fq-lime)' : 'var(--fq-text-4)',
                'done' => $daySecured,
                'quiet' => false,
            ],
            [
                'key' => 'chest',
                'glyph' => '🎁',
                'label' => 'Bonus Chest',
                'accent' => 'var(--fq-chest-blue)',
                'tileLabel' => 'Chest',
                'sub' => $chests->isBoosted($this->profile) ? 'Rolling on the good table' : 'Better after a chore',
                'status' => $chestAvailable ? 'READY' : 'OPENED',
                'statusColor' => $chestAvailable ? 'var(--fq-chest-blue)' : 'var(--fq-text-4)',
                'done' => ! $chestAvailable,
                'quiet' => false,
            ],
            [
                'key' => 'wheel',
                'glyph' => '🍪',
                'label' => 'Bonus Wheel',
                'accent' => 'var(--fq-magenta)',
                'tileLabel' => 'Spin',
                'sub' => $boost ? $boost->multiplier.'x on '.$boost->chore->name : 'Doubles one chore',
                'status' => $spunToday ? 'USED' : '1 WAITING',
                'statusColor' => $spunToday ? 'var(--fq-text-4)' : 'var(--fq-magenta)',
                'done' => $spunToday,
                'quiet' => false,
            ],
            [
                'key' => 'streak',
                'glyph' => '🔥',
                'label' => 'Streak Chest',
                'accent' => 'var(--fq-streak)',
                'tileLabel' => 'Day '.$this->profile->streak,
                'sub' => 'Day '.$this->profile->streak.' · chest at day '.$streaks->nextStreakMilestone($this->profile),
                'status' => match (true) {
                    (bool) $this->pendingChestDay => 'READY',
                    $daySecured => 'SAFE',
                    default => strtoupper($leftInWords ?? 'TONIGHT'),
                },
                'statusColor' => $this->pendingChestDay || ! $daySecured ? 'var(--fq-streak)' : 'var(--fq-lime)',
                'done' => $daySecured && ! $this->pendingChestDay,
                'quiet' => false,
            ],
            [
                'key' => 'prize',
                'glyph' => '🏆',
                'label' => 'Weekly Prize',
                'accent' => 'var(--fq-gold)',
                'tileLabel' => 'Prize',
                'sub' => $houseWeek
                    ? 'House is '.$houseWeek['done'].' of '.$houseWeek['target']
                    : 'Nothing set this week',
                'status' => $houseWeek
                    ? $houseWeek['done'].' OF '.$houseWeek['target']
                    : 'NONE SET',
                'statusColor' => 'var(--fq-text-4)',
                'done' => $houseWeek ? $houseWeek['done'] >= $houseWeek['target'] : false,
                // News, not a task: drawn quiet from the start, however it goes.
                'quiet' => true,
            ],
        ];

        $feelingsCard = app(FeelingService::class)->cardFor($this->profile);
        $feeling = $feelingsCard['answered'];
        $gratitudeToday = app(GratitudeService::class)->todayFor($this->profile);
        $gratitudeHouse = app(FeedService::class)->gratitudeToday($this->profile);
        $meals = app(MealService::class)->upcoming($household);
        $tonight = $meals->first(fn ($meal) => $meal->served_on->isSameDay(HouseholdClock::for($household)->today()));

        $gifts = app(GiftService::class);
        $giftSiblings = $gifts->siblingsOf($this->profile);
        $giftGiven = $gifts->givenToday($this->profile);
        $giftsReceived = $gifts->receivedToday($this->profile);
        $giftsUnseen = $giftsReceived->whereNull('seen_at');

        /*
         * The house rows, under the day's five. They open like the rest but are
         * left out of the "n of 5" — see the class docblock — so `done` only
         * greys them.
         */
        $houseRows = [
            // Only with somebody to give to — an only child gets no row rather
            // than a picker with nobody in it.
            ...($giftSiblings->isEmpty() ? [] : [[
                'key' => 'gift',
                'glyph' => '🎟',
                'label' => 'Daily Gift',
                'accent' => 'var(--fq-lime)',
                'tileLabel' => 'Gift',
                'sub' => match (true) {
                    $giftsUnseen->isNotEmpty() => $giftsUnseen->last()->giver->name.' gave you a ticket!',
                    $giftsReceived->isNotEmpty() && ! $giftGiven => $giftsReceived->last()->giver->name.' gave you one — give one too',
                    $giftGiven !== null => 'You gave '.$giftGiven->recipient->name.' a ticket',
                    default => 'Pick a sibling to get a ticket',
                },
                'status' => match (true) {
                    $giftsUnseen->count() > 1 => $giftsUnseen->count().' NEW GIFTS',
                    $giftsUnseen->isNotEmpty() => 'NEW GIFT',
                    $giftGiven !== null => 'GIVEN',
                    default => '1 TO GIVE',
                },
                'statusColor' => $giftGiven ? 'var(--fq-text-4)' : 'var(--fq-lime)',
                'done' => $giftGiven !== null,
                'quiet' => false,
                // A ticket from a sibling that this kid hasn't opened the row to
                // see yet. Cleared by opening it — see toggleRow().
                'attention' => $giftsUnseen->isNotEmpty(),
            ]]),
            [
                'key' => 'feelings',
                'glyph' => '💬',
                'label' => 'Feelings',
                'accent' => 'var(--fq-violet)',
                'tileLabel' => 'Feelings',
                'sub' => $feeling ? 'Today you said '.$feeling->label() : 'How are you today?',
                'status' => $feeling ? 'SAID' : 'ASK ME',
                'statusColor' => $feeling ? 'var(--fq-text-4)' : 'var(--fq-violet)',
                'done' => $feeling !== null,
                'quiet' => false,
            ],
            [
                'key' => 'gratitude',
                'glyph' => '🙏',
                'label' => 'Gratitude',
                'accent' => 'var(--fq-magenta)',
                'tileLabel' => 'Grateful',
                'sub' => $gratitudeToday
                    ? $gratitudeHouse['total'].' in the house today'
                    : 'Three things for '.GratitudeService::TICKETS.' tickets',
                'status' => $gratitudeToday ? 'DONE' : '+'.GratitudeService::TICKETS.' TICKETS',
                'statusColor' => $gratitudeToday ? 'var(--fq-text-4)' : 'var(--fq-lime)',
                'done' => $gratitudeToday !== null,
                'quiet' => false,
            ],
            [
                'key' => 'meals',
                'glyph' => '🍽',
                'label' => 'Meals',
                'accent' => 'var(--fq-cyan)',
                'tileLabel' => 'Meals',
                'sub' => $tonight ? 'Tonight · '.$tonight->name : 'Tonight · nobody has said yet',
                'status' => $meals->count().' SET',
                'statusColor' => 'var(--fq-text-4)',
                'done' => false,
                // A menu is something to read, never something to finish.
                'quiet' => true,
            ],
        ];

        $allRows = collect($dayRows)->map(fn (array $row) => [...$row, 'counted' => true])
            ->concat(collect($houseRows)->map(fn (array $row) => [...$row, 'counted' => false]));

        return [
            'household' => $household,
            'dayRows' => $allRows,
            'daysDone' => $allRows->where('counted', true)->where('done', true)->count(),
            'dayCount' => $allRows->where('counted', true)->count(),
            'giftSiblings' => $giftSiblings,
            'giftGiven' => $giftGiven,
            'giftsReceived' => $giftsReceived,
            'gratitudeToday' => $gratitudeToday,
            'gratitudeHouse' => $gratitudeHouse,
            // Every night a grown-up has filled in, tonight first.
            'meals' => $meals,
            'mealsToday' => HouseholdClock::for($household)->today(),
            'money' => $money,
            // What the Work panel draws: today's jobs, what they came to, and —
            // when there are none — the one job worth pointing at.
            'workJobs' => $work,
            'workEarned' => $earned,
            'workSuggestion' => $work->isEmpty() ? $chores->suggestedChoreFor($this->profile) : null,
            'boardSpan' => $chores->boardSpanFor($this->profile),
            // Whether tonight is in the bag. Nothing on this page is guarded on
            // a quest existing any more — every one of these used to be, because
            // asking for a quest in a household with nothing eligible threw.
            'daySecured' => $streaks->streakDaySecuredToday($this->profile),
            'streakWindow' => $streaks->streakWindowFor($this->profile),
            // The streak chest's own card: the track, the rescue window, and
            // what a milestone is worth. It came off the Quests page with the
            // chest itself — the tray slot there was the only thing that could
            // open one, so leaving the track behind would have split the reward
            // from the explanation of it.
            'nextMilestone' => $streaks->nextStreakMilestone($this->profile),
            'streakBonuses' => collect($streaks->streakTrackFor($this->profile)['milestones']),
            'streakLap' => $streaks->streakTrackFor($this->profile)['lap'],
            // Null unless a broken chain is still savable — which stops being
            // true the moment anything is signed off today, so the offer has to
            // be on the page a kid is looking at when they decide.
            'streakRepair' => $streaks->repairPreview($this->profile),
            // Just the rescue: it is the only perk with a button on this page.
            'heldPerks' => collect([PerkEffect::StreakRestore])
                ->filter(fn (PerkEffect $effect) => $inventory->holds($this->profile, $effect))
                ->mapWithKeys(fn (PerkEffect $effect) => [$effect->value => [
                    'effect' => $effect,
                    'count' => $inventory->countOf($this->profile, $effect),
                    'blocked' => $inventory->blockedReason($this->profile, $effect),
                ]]),
            // Recomputed rather than read off the mount snapshot: the snapshot's
            // job is to hold the *animation* still, and this decides whether
            // there is a chest to animate at all.
            'chestAvailable' => app(ChestService::class)->isAvailable($this->profile),
            // Any chore in for today rolls the chest on the good table, so the
            // copy asks the chest's own question.
            'chestBoosted' => app(ChestService::class)->isBoosted($this->profile),
            'boost' => $boost,
            // Only for the section header's "n waiting" pill. The feed itself
            // is a nested component and reads its own rooms — this page holds
            // none of its state.
            'feedUnread' => app(FeedService::class)->unreadTotal($this->profile),
            // The house's feelings for today. The service returns the strip as
            // null until this kid has answered — see FeelingService for why the
            // gate lives there rather than in the card.
            'feelingsCard' => $feelingsCard,
            // Whether the card folds to a line. Three things have to be true,
            // and the third is the interesting one: a grown-up's reply is read
            // *on this card* and nowhere else in the app, so a card with one
            // waiting on it never folds. Folding it would be the app quietly
            // hiding the one message here that was written by hand.
            'feelingsFolded' => $this->feelingsAnsweredOnArrival
                && ! $this->showFeelings
                && $feelingsCard['answered'] !== null
                && $feelingsCard['answered']->replies()->doesntExist(),
            // The week's shared chore target and what hitting it pays. Null
            // when a parent hasn't set one, which takes the bar with it.
            'houseWeek' => app(HouseholdService::class)->houseWeek($household),
            // The celebration day, if today is one — almost always null. The
            // card is the first thing on the page when it isn't, above even the
            // feelings card: for the couple of days it exists it is the reason
            // the kid is looking at the app.
            'celebration' => $celebration = app(CelebrationService::class)->activeFor($household),
            'celebrationEntry' => $celebration
                ? app(CelebrationService::class)->entryFor($this->profile, $celebration['key'])
                : null,
        ];
    }
}; ?>

<x-kid.shell :profile="$profile" active="home">
    {{-- A pet's rescue, told once: Guard Dog and Night Owl go off by
         themselves, usually overnight. --}}
    @foreach ($petRescues as $rescue)
        <div
            class="mb-[11px] flex items-center gap-[10px] rounded-[16px] border-2 border-fq-green px-[14px] py-[11px]"
            style="background: color-mix(in srgb, var(--fq-green) 10%, var(--fq-panel)); animation: fq-pop .35s ease both"
            data-pet-rescue
        >
            <i class="fa-solid fa-shield-dog text-[18px] text-fq-green"></i>
            <span class="flex-1 font-baloo text-[16px] leading-tight font-extrabold">{{ $rescue }}</span>
        </div>
    @endforeach

    {{-- A celebration day, on the two or three days a year there is one. Above
         both columns, because for as long as it is on the page it is the thing
         the page is about. --}}
    @if ($celebration)
        @php $celebrations = app(CelebrationService::class); @endphp

        <x-celebration-card
            :day="$celebration"
            :entry="$celebrationEntry"
            :reward="$celebrations->describeReward($household, $celebration)"
            :extras="$celebrations->describeExtras($household, $celebration)"
            class="mb-[11px]"
        />
    @endif

    {{-- Two columns at desk size, one everywhere else.

         Half the house is on a PC, where the shell is 1080px wide: every
         phone-shaped fix for "the feed is long and the day was under it" —
         a pinned strip, a thumb dock — wastes about 700px of it. So the day
         gets a fixed 340px rail and the room gets everything left over, and
         neither has to scroll past the other.

         One markup for both. Below `lg` this is a single column and the order
         falls out exactly as the phone wants it: the day, then the room. The
         feeling, gratitude and the menu are rows of the day rather than cards
         under it, so nothing tall sits between a kid and the feed. --}}
    <div class="grid gap-4 lg:grid-cols-[340px_minmax(0,1fr)] lg:items-start">

        {{-- ================= Your day ================= --}}
        @php
            // Where the open panel goes. The rows and the panel are siblings in
            // one flex column, ordered rather than nested: on desktop the panel
            // takes the slot straight after its own row, and on a phone — where
            // the rows are hidden and the tile board is the handle — it simply
            // follows the board. Nesting it inside the row instead would mean a
            // second copy of every panel in the markup, and two copies of the
            // bonus chest is two Alpine instances of one chest.
            $openIndex = $dayRows->search(fn (array $row) => $row['key'] === $openRow);
            $panelOrder = $openIndex === false ? 90 : 3 + $openIndex * 2;
        @endphp

        <div class="flex flex-col gap-[11px] lg:gap-[9px]">
            <div class="flex items-center gap-2" style="order: 0">
                <span class="h-[15px] w-[3px] rounded-[2px]" style="background: var(--fq-gold)"></span>
                <h2 class="font-baloo text-[17px] font-extrabold">Your day</h2>
                <span class="flex-1"></span>
                <span class="font-mono-fq text-[9.5px] tracking-[0.1em] uppercase" style="color: var(--fq-ticket-label)">
                    {{ $daysDone }} of {{ $dayCount }} done
                </span>
            </div>

            {{-- The board on a phone, the rows at desk size — see <x-day-index>.
                 The house rows get a breath above them on a desk. --}}
            <x-day-index :rows="$dayRows" :open-row="$openRow" :break-before="$dayCount" />

            {{-- The one open panel, ordered into place — see $panelOrder above. --}}
            <div id="day-panel" class="flex min-w-0 flex-col gap-[11px]" style="order: {{ $panelOrder }}">
                {{-- Work: what today came to, in money.

                     The row that stands where the daily quest did, and the one
                     genuinely new thing on the page. It is a *tally*, not a
                     card the app dealt: the board is the work now, and this
                     says how much of it is in.

                     Dollars are the headline and points the footnote — the same
                     decision the board shipped with, because he thinks in
                     dollars and the points are what the rest of the app counts
                     in. --}}
                @if ($openRow === 'work')
                    <div
                        wire:key="panel-work"
                        class="flex flex-col gap-[10px] rounded-[18px] border p-3"
                        style="border-color: var(--fq-ticket-line); background: linear-gradient(160deg, var(--fq-gold-fill), var(--fq-panel) 72%)"
                    >
                        <div class="flex items-center gap-2">
                            <span class="font-mono-fq text-[9px] tracking-[0.2em] uppercase" style="color: var(--fq-ticket-label)">Work today</span>
                            <span class="flex-1"></span>
                            <button
                                type="button"
                                wire:click="toggleRow('work')"
                                aria-label="Close"
                                class="grid h-8 w-8 place-items-center rounded-[11px] border text-[12px]"
                                style="border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-3)"
                            >▲</button>
                        </div>

                        <div class="flex items-end gap-[10px]">
                            <span
                                class="font-baloo text-[34px] leading-none font-extrabold"
                                style="color: {{ $workEarned > 0 ? 'var(--fq-lime)' : 'var(--fq-text-4)' }}"
                            >{{ $money($workEarned) }}</span>
                            <span class="pb-[5px] font-mono-fq text-[10px]" style="color: var(--fq-ticket-label)">
                                {{ number_format($workEarned) }} pts · {{ $workJobs->count() }} {{ Str::plural('job', $workJobs->count()) }}
                            </span>
                        </div>

                        @if ($workJobs->isNotEmpty())
                            <div class="flex flex-col gap-[7px]">
                                @foreach ($workJobs as $job)
                                    @php
                                        // A job says one of three things, and the
                                        // glyph, the colour and the value all have
                                        // to agree on which.
                                        [$mark, $markInk, $markBg, $value, $valueInk] = match (true) {
                                            $job->status === CompletionStatus::Approved => ['✓', 'var(--fq-lime)', '#0d3323', $money($job->points_awarded), 'var(--fq-lime)'],
                                            $job->status === CompletionStatus::Rejected => ['✕', 'var(--fq-coral)', 'var(--fq-wash-coral)', 'SENT BACK', 'var(--fq-coral)'],
                                            default => ['⏳', 'var(--fq-gold)', 'var(--fq-gold-fill)', 'WAITING', 'var(--fq-ticket-label)'],
                                        };
                                    @endphp

                                    <div
                                        wire:key="job-{{ $job->id }}"
                                        class="flex items-center gap-[9px] rounded-[13px] border px-[10px] py-[9px]"
                                        style="border-color: var(--fq-line); background: var(--fq-sunk)"
                                    >
                                        <span
                                            class="grid h-[26px] w-[26px] flex-none place-items-center rounded-[9px] text-[12px]"
                                            style="background: {{ $markBg }}; color: {{ $markInk }}"
                                            aria-hidden="true"
                                        >{{ $mark }}</span>
                                        <span class="min-w-0 flex-1 truncate text-[14.5px] text-fq-text-2">{{ $job->chore?->name ?? 'A chore' }}</span>
                                        <span class="flex-none font-mono-fq text-[10.5px]" style="color: {{ $valueInk }}">{{ $value }}</span>
                                    </div>
                                @endforeach
                            </div>

                            <a
                                href="{{ route('kid.quests') }}"
                                wire:navigate
                                class="flex h-12 items-center justify-center gap-[9px] rounded-[14px] font-baloo text-[17px] font-extrabold transition hover:brightness-110"
                                style="background: var(--fq-fill-gold); color: var(--fq-ink)"
                            >Pick another job ›</a>
                        @else
                            {{-- Nothing in yet, so the panel answers the question
                                 the quest used to: *this one, now*.

                                 The cheapest thing they can claim, because cheap
                                 is predictable and never intimidating. It
                                 suggests and nothing more — it is not assigned,
                                 it does not expire, and it pays exactly what the
                                 board says it pays. --}}
                            @if ($workSuggestion)
                                <div
                                    class="flex items-center gap-[10px] rounded-[13px] border px-[10px] py-[9px]"
                                    style="border-color: var(--fq-line-2); background: var(--fq-sunk)"
                                >
                                    <span
                                        class="grid h-[34px] w-[34px] flex-none place-items-center rounded-[11px] border"
                                        style="border-color: var(--fq-line-2); background: var(--fq-panel-alt); color: var(--fq-gold)"
                                    >
                                        @if ($workSuggestion->icon)
                                            <x-chore-icon :icon="$workSuggestion->icon" class="text-[17px]" />
                                        @else
                                            <span class="font-baloo text-[15px] font-extrabold">{{ mb_substr($workSuggestion->name, 0, 1) }}</span>
                                        @endif
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[14.5px] font-semibold">{{ $workSuggestion->name }}</span>
                                        <span class="block font-mono-fq text-[10px] text-fq-text-4">
                                            {{ $money($workSuggestion->points) }} · {{ number_format($workSuggestion->points) }} pts
                                        </span>
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    wire:click="claimSuggested({{ $workSuggestion->id }})"
                                    class="flex h-12 items-center justify-center gap-[9px] rounded-[14px] font-baloo text-[17px] font-extrabold transition hover:brightness-110"
                                    style="background: var(--fq-fill-gold); color: var(--fq-ink)"
                                >Do this one</button>

                                <a
                                    href="{{ route('kid.quests') }}"
                                    wire:navigate
                                    class="text-center font-mono-fq text-[10px] tracking-[0.14em] uppercase"
                                    style="color: var(--fq-text-4)"
                                >See all {{ $boardSpan['count'] }} →</a>
                            @else
                                <p class="text-[13.5px] text-fq-text-4">
                                    Nothing on the board right now — a grown-up adds the jobs, so check back later.
                                </p>
                            @endif
                        @endif

                        @if ($workMessage)
                            <p class="text-[13px]" style="color: var(--fq-gold)">{{ $workMessage }}</p>
                        @endif

                        {{-- Read live off the board, so a house that added ten
                             chores this morning says so. --}}
                        @if ($boardSpan['count'] > 0 && $workJobs->isNotEmpty())
                            <span class="text-center font-mono-fq text-[9px] tracking-[0.14em] uppercase" style="color: var(--fq-text-5)">
                                {{ $boardSpan['count'] }} on the board · {{ $money($boardSpan['min']) }} to {{ $money($boardSpan['max']) }}
                            </span>
                        @endif
                    </div>
                @endif

                @if ($openRow === 'chest')
                {{-- The bonus chest. Opens right here — it is one tap and it has no page
                     of its own, so sending a kid somewhere to take it would be the
                     errand this whole page exists to remove. --}}
                <div class="flex flex-col gap-3">

                    {{-- Always the chest, never a "come back tomorrow" panel in its
                         place. A chest already opened from the Quests tray still draws
                         here and still opens: openDailyChest() finds the one that
                         exists and describes it, so the tap tells the kid what they got
                         rather than dead-ending. --}}
                    <x-chest
                        narrow
                        wire-key="home-bonus-chest"
                        :revealed="$chestOpened"
                        open-action="openDailyChest"
                        accent="var(--fq-chest-blue)"
                        wash="var(--fq-chest-blue-bg)"
                        fill="var(--fq-chest-blue-fill)"
                        kicker="Free Every Single Day"
                        :closed-title="$chestBoosted ? 'Your chest is OP today' : 'Open today\'s bonus chest'"
                        :closed-text="$chestBoosted
                            ? 'You got a chore done, so this one rolls on the good table — more tickets, more perks.'
                            : 'Tickets, points, or a perk. Do any chore first and it rolls on a much better table.'"
                        cta="Open it"
                        :prize-label="$dailyChestPrize ?? 'A prize!'"
                        :prize-sub="$chestBoosted ? 'Bonus Chest · OP' : 'Bonus Chest'"
                        prize-property="dailyChestPrize"
                        {{-- The kids were opening this first thing every morning
                             and never finding out that doing a chore makes it
                             better. So: stop once and ask. --}}
                        :confirm="$chestAvailable && ! $chestBoosted"
                    >
                        @if ($chestAvailable && ! $chestBoosted)
                            <x-slot:confirm-panel>
                                <p class="mb-2 font-mono-fq text-[10px] tracking-[0.16em] uppercase" style="color: var(--fq-lime)">
                                    Hold on &mdash; OP loot
                                </p>
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <a
                                        href="{{ route('kid.quests') }}"
                                        wire:navigate
                                        class="rounded-[16px] px-[20px] py-[13px] text-center font-baloo text-[16px] font-extrabold transition hover:brightness-110"
                                        style="background: var(--fq-lime); color: var(--fq-ink)"
                                    >Do a chore first</a>

                                    <button
                                        type="button"
                                        @click="begin()"
                                        class="cursor-pointer rounded-[16px] border px-[20px] py-[13px] font-baloo text-[16px] font-bold transition hover:brightness-125"
                                        style="border-color: var(--fq-line-3); color: var(--fq-text-3)"
                                    >Open now anyway</button>
                                </div>
                            </x-slot:confirm-panel>
                        @endif

                        <div
                            class="rounded-[24px] border p-5"
                            style="animation: fq-pop .3s ease both; background: var(--fq-chest-blue-bg); border-color: var(--fq-chest-blue-line)"
                        >
                            <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-chest-blue)">
                                Today's Bonus Chest
                            </p>
                            <p class="mt-[6px] font-baloo text-[22px] leading-[1.15] font-extrabold">
                                {{ $dailyChestPrize ?? 'Banked!' }}
                            </p>
                            <p class="mt-[6px] text-[13px] text-fq-text-4">Banked. There's another one tomorrow.</p>
                        </div>
                    </x-chest>
                </div>

                @endif

                @if ($openRow === 'wheel')
                {{-- The spin, which lives on the Quests page now.

                     It was a full section here and the kids went looking for it on
                     Quests anyway — which was them being right. The wheel lands on a
                     chore and multiplies it, and every one of those rows is over
                     there, so that is where the wheel went too.

                     What's left is a strip rather than a section, for the same reason
                     the Lucky Block above the run is one: it points at something on
                     another page. It still carries the news, which is the half worth
                     having here — a spin waiting, or the boost that's live. --}}
                <a
                    href="{{ route('kid.quests') }}#bonus-wheel"
                    wire:navigate
                    class="flex min-h-[64px] items-center gap-3 rounded-[16px] border px-[14px] py-[13px] transition hover:brightness-110"
                    style="border-color: {{ $boost ? 'var(--fq-line-2)' : 'var(--fq-magenta)' }};
                           background: {{ $boost ? 'var(--fq-sunk)' : 'color-mix(in srgb, var(--fq-magenta) 14%, transparent)' }}"
                >
                    {{-- The wheel at strip size: the same palette the segments cycle
                         through, run round once, with the hub pin in the middle. Small
                         enough that a face of real segments would just be noise. --}}
                    <span
                        class="grid h-[38px] w-[38px] shrink-0 place-items-center rounded-full"
                        style="background: conic-gradient(var(--fq-lime), var(--fq-cyan), var(--fq-gold), var(--fq-magenta), var(--fq-coral), var(--fq-violet), var(--fq-lime));
                               box-shadow: 0 0 0 2px var(--fq-wheel-ring)"
                        aria-hidden="true"
                    >
                        <span
                            class="h-[12px] w-[12px] rounded-full"
                            style="background: var(--fq-wheel-hub); box-shadow: inset 0 0 0 2px var(--fq-wheel-hub-line)"
                        ></span>
                    </span>

                    <span class="min-w-0 flex-1">
                        @if ($boost)
                            <span
                                class="block font-baloo text-base leading-tight font-extrabold"
                                style="color: {{ $boost->multiplier >= 3 ? 'var(--fq-gold)' : 'var(--fq-magenta)' }}"
                            >{{ $boost->multiplier }}x on {{ $boost->chore->name }}</span>
                            <span class="mt-[2px] block text-xs text-fq-text-4">Claim it on the Quests page.</span>
                        @else
                            <span class="block font-baloo text-base leading-tight font-extrabold" style="color: var(--fq-magenta)">
                                Your Bonus Wheel spin is waiting
                            </span>
                            <span class="mt-[2px] block text-xs text-fq-text-4">It's on the Quests page, above the board.</span>
                        @endif
                    </span>

                    <i aria-hidden="true" class="fa-fw fa-solid fa-arrow-right text-[13px]" style="color: var(--fq-magenta)"></i>
                </a>

                @endif

                @if ($openRow === 'streak')
                {{-- The streak chest, moved off the Quests page along with the loot
                     tray that used to be the only thing able to open one. The chest and
                     the track that explains what it pays belong together, and this is
                     the page the rest of the daily loop is on. --}}
                @if ($streakBonuses->isNotEmpty())
                    @php
                        $daysToChest = max(0, $nextMilestone - $profile->streak);

                        // No "all unlocked" any more: the track laps, so there is always
                        // another chest ahead of whatever they're on.
                        [$streakStatus, $streakStatusColor] = match (true) {
                            (bool) $pendingChestDay => ['Ready to open', 'var(--fq-streak)'],
                            (bool) $streakRepair => ['Streak ended', 'var(--fq-streak)'],
                            $profile->streak === 0 => ['Start a run', 'var(--fq-text-4)'],
                            default => [$daysToChest.' '.Str::plural('day', $daysToChest).' to go', 'var(--fq-text-4)'],
                        };
                    @endphp

                    {{-- `id` rather than a bare anchor: the header's streak tile links
                         straight here from whatever page a kid is standing on, and
                         scroll-mt keeps the section title clear of the top edge instead
                         of butted against it. --}}
                    <div id="streak" class="flex scroll-mt-4 flex-col gap-3">

                        {{-- The clock, first thing in the section it is about. It sat
                             above the whole page for a while, which put the day's
                             deadline in front of a kid before they'd been told what a
                             streak was — the timer and the track explain each other, so
                             they live together and the header tile is what carries the
                             number everywhere else. --}}
                        <x-streak-timer
                            wire:key="streak-timer"
                            :closes-at="$streakWindow['closesAt']"
                            :resets-at="$streakWindow['resetsAt']"
                            :bedtime="$streakWindow['bedtime']"
                            :timezone="$household->timezone"
                            :streak="$profile->streak"
                            :secured="$streakWindow['secured']"
                            :urgent="$streakWindow['urgent']"
                            :overtime="$streakWindow['overtime']"
                        />

                        {{-- Only when there is one to open. Unlike the bonus chest this
                             is earned rather than handed out, so on every other day the
                             card below is the whole of it — a track, not a shut box. --}}
                        @if ($pendingChestDay)
                            <x-chest
                                narrow
                                wire-key="home-streak-chest"
                                :revealed="false"
                                open-action="openStreakChest"
                                accent="var(--fq-streak)"
                                wash="var(--fq-wash-streak)"
                                fill="var(--fq-chest-streak-fill)"
                                :kicker="$pendingChestDay.'-Day Streak · Earned'"
                                closed-title="Your streak chest is waiting"
                                :closed-text="'You cleared '.$pendingChestDay.' '.Str::plural('night', $pendingChestDay).' in a row. This one is yours.'"
                                cta="Open it"
                                :prize-label="'+'.number_format((int) $pendingChestPoints).' PTS'"
                                :prize-sub="$pendingChestDay.'-Day Streak Bonus!'"
                            >
                                <div
                                    class="rounded-[24px] border p-5"
                                    style="animation: fq-pop .3s ease both; background: var(--fq-wash-streak); border-color: color-mix(in srgb, var(--fq-streak) 55%, transparent)"
                                >
                                    <p class="font-mono-fq text-[10px] tracking-[0.24em] uppercase" style="color: var(--fq-streak)">
                                        {{ $pendingChestDay }}-Day Streak Bonus
                                    </p>
                                    <p class="mt-[6px] font-baloo text-[22px] leading-[1.15] font-extrabold">
                                        +{{ number_format((int) $pendingChestPoints) }} PTS banked
                                    </p>
                                </div>
                            </x-chest>
                        @endif

                        <div
                            wire:key="streak-track"
                            class="rounded-[20px] border bg-fq-panel p-[18px]"
                            style="border-color: color-mix(in srgb, var(--fq-streak) 35%, transparent)"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-baloo text-lg font-bold">Streak Chest</h3>

                                    {{-- Only from the second lap on. On the first it would be
                                         labelling a thing that has no other version yet. --}}
                                    @if ($streakLap > 1)
                                        <span
                                            class="rounded-full border px-[10px] py-1 font-mono-fq text-[9.5px] tracking-[0.1em] whitespace-nowrap uppercase"
                                            style="border-color: color-mix(in srgb, var(--fq-streak) 55%, transparent); background: var(--fq-wash-streak); color: var(--fq-streak)"
                                        >Round {{ $streakLap }} · Double payouts</span>
                                    @endif
                                </div>

                                <span class="font-mono-fq text-[11px] whitespace-nowrap text-fq-streak uppercase">
                                    {{ $profile->streak }}-day streak · Next chest at day {{ $nextMilestone }}
                                </span>
                            </div>

                            <p class="mt-1 text-sm text-fq-text-2">
                                @if ($streakRepair)
                                    Your streak ran out — but it isn't gone yet.
                                @elseif ($profile->streak + 1 === $nextMilestone && ! $daySecured)
                                    {{-- The one day the general advice isn't the useful thing to
                                         say: the chest is one signed-off chore away, so say that
                                         instead of explaining how streaks work. --}}
                                    Get one chore signed off and come back tomorrow to open the chest!
                                @elseif ($profile->streak === 0)
                                    {{-- Nothing to keep alive yet. "Keep the streak alive" to
                                         somebody on nought days is advice about a thing they
                                         don't have. --}}
                                    Get any chore signed off to start a streak. Keep it going and the chests get bigger —
                                    the first one is day {{ $nextMilestone }}.
                                @elseif ($streakLap > 1)
                                    {{-- The reason the numbers on the track just changed. Worth
                                         saying outright: a kid who cleared day 30 and found a
                                         fresh row of chests deserves to know they're worth more
                                         rather than having to remember last month's figures. --}}
                                    You went all the way round — every chest on this lap pays double.
                                    Miss a day and you drop back to the last one you cleared.
                                @else
                                    Keep the streak alive and the chests get bigger — miss a day and you drop back to
                                    the last one you cleared.
                                @endif
                            </p>

                            {{-- The rescue window, and it really is a window: getting
                                 anything signed off today starts a fresh chain and closes it,
                                 so the copy has to say that before a kid taps past it. --}}
                            @if ($streakRepair)
                                <div
                                    wire:key="streak-repair"
                                    class="mt-4 rounded-[18px] border p-4"
                                    style="background: var(--fq-wash-streak); border-color: color-mix(in srgb, var(--fq-streak) 55%, transparent)"
                                >
                                    <p class="font-mono-fq text-[10px] tracking-[0.24em] text-fq-streak uppercase">Streak Rescue</p>

                                    <p class="mt-2 text-sm text-fq-text-2">
                                        You missed {{ $streakRepair['date']->toFormattedDateString() }}. A Streak Restore buys that day
                                        back and puts you on a
                                        <span class="font-bold text-fq-streak">{{ $streakRepair['restoresTo'] }}-day streak</span>.
                                    </p>

                                    <p class="mt-1 font-mono-fq text-[11px] text-fq-text-4">
                                        Use it before anything you do today gets signed off — after that the day is gone for good.
                                    </p>

                                    <div class="mt-3">
                                        @if (isset($heldPerks['streak_restore']))
                                            <x-perk-button :entry="$heldPerks['streak_restore']" />
                                        @else
                                            <a
                                                href="{{ route('kid.bonus') }}"
                                                wire:navigate
                                                class="inline-flex items-center gap-2 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[13px] py-[8px] text-[13px] text-fq-text-2-b transition hover:border-fq-line-4 hover:text-fq-text"
                                            >Get a Streak Restore &rarr;</a>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            {{-- No button in the branch below on purpose. The rescue card above
                                 owns every case where a restore can actually be spent, so
                                 anything reaching it is a perk with nothing to fix — and a
                                 permanently greyed-out "Use Streak Restore" under a healthy
                                 streak reads as the app being broken rather than as the kid
                                 having nothing to repair. --}}
                            @if (isset($heldPerks['streak_restore']) && ! $streakRepair)
                                @php
                                    $restoresHeld = $heldPerks['streak_restore']['count'];
                                    // Built here rather than across template lines, so the
                                    // sentence renders as one run of text instead of picking up
                                    // the indentation between its clauses.
                                    $restoreNote = $restoresHeld > 1
                                        ? "{$restoresHeld} Streak Restores are in your pocket. Nothing to fix right now — they'll be here if you ever miss a day."
                                        : "A Streak Restore is in your pocket. Nothing to fix right now — it'll be here if you ever miss a day.";
                                @endphp

                                <div class="mt-4 flex flex-wrap items-center gap-3 rounded-[14px] border border-fq-steel-line bg-fq-sunk p-[13px]">
                                    <span class="font-baloo text-sm" style="color: var(--fq-steel-text)">
                                        {{ App\Enums\PerkEffect::StreakRestore->defaults()['glyph'] }}
                                    </span>
                                    <p class="min-w-0 flex-1 text-[13px] text-fq-text-2">{{ $restoreNote }}</p>
                                </div>
                            @endif

                            {{-- Chests rather than numbered circles, growing along the rail.
                                 "Day 30 pays 4000 and day 3 pays 100" is a sentence you have to
                                 read and compare; a row of chests getting bigger is the same
                                 fact at a glance, which is the half of the audience that can't
                                 comfortably do the first. The numbers stay underneath for the
                                 kids who do want them. --}}
                            {{-- The rail spans the card rather than huddling on the left. It's
                                 a track, so the connectors stretch to fill whatever width there
                                 is and the chests space themselves out along it; on a narrow
                                 screen they fall back to their own widths and it scrolls. --}}
                            <div class="mt-4 flex items-start gap-2 overflow-x-auto pb-1 sm:gap-3">
                                @php
                                    // Chests are sized off position in the run rather than off
                                    // the payout: the amounts are set per household and a
                                    // generous day-3 bonus shouldn't draw a bigger chest than
                                    // the day-30 one. The rail is a sequence, and that's what
                                    // the sizes have to say.
                                    $rungs = max(1, $streakBonuses->count() - 1);
                                @endphp

                                @foreach ($streakBonuses as $milestone)
                                    @php
                                        // Three states, not two: reached, the one being worked
                                        // towards, and the ones after it. The middle one is what
                                        // makes the rail a track rather than a scoreboard.
                                        $isNext = $nextMilestone === $milestone['day'];
                                        $reached = $milestone['reached'];

                                        $chestWidth = (int) round(26 + $loop->index / $rungs * 26);
                                        $chestHeight = (int) round($chestWidth * 0.78);

                                        [$chestFill, $labelColour] = match (true) {
                                            $reached => ['var(--fq-chest-streak-fill)', 'var(--fq-streak)'],
                                            $isNext => ['var(--fq-chest-streak-next)', 'var(--fq-text-2)'],
                                            default => ['var(--fq-chest-locked-fill)', 'var(--fq-text-5)'],
                                        };
                                    @endphp

                                    {{-- Node and connector are siblings rather than a nested
                                         pair, so the connector is a flex child of the rail and
                                         can grow into the space left over. --}}
                                    <div class="flex flex-shrink-0 flex-col items-center gap-[6px]">
                                            {{-- Fixed-height box with the chests bottom-aligned,
                                                 so they grow upwards off a shared line instead
                                                 of drifting around their own centres. --}}
                                            <div class="relative flex h-[46px] items-end justify-center">
                                                <x-chest-block
                                                    :fill="$chestFill"
                                                    :width="$chestWidth.'px'"
                                                    :height="$chestHeight.'px'"
                                                    radius="8px"
                                                    class="{{ $reached ? '' : ($isNext ? 'ring-2 ring-fq-streak' : 'opacity-45') }}"
                                                />

                                                @if ($reached)
                                                    <span
                                                        class="absolute -top-[2px] -right-[4px] flex h-[15px] w-[15px] items-center justify-center rounded-full font-baloo text-[10px] font-extrabold"
                                                        style="background: var(--fq-streak); color: var(--fq-streak-ink)"
                                                        title="Opened"
                                                    >&#10003;</span>
                                                @endif
                                            </div>

                                        <span class="font-mono-fq text-[9px] whitespace-nowrap text-fq-text-4">Day {{ $milestone['day'] }}</span>
                                        <span
                                            class="font-baloo text-[11px] leading-none font-extrabold whitespace-nowrap"
                                            style="color: {{ $labelColour }}"
                                        >{{ number_format($milestone['points']) }} pts</span>
                                    </div>

                                    @unless ($loop->last)
                                        {{-- Grows into the leftover width, and sits just above
                                             the shared baseline so it reads as a rail the chests
                                             stand on. --}}
                                        <div class="mt-[38px] h-[2px] min-w-[10px] flex-1" style="background: {{ $reached ? 'var(--fq-streak)' : 'var(--fq-line-2)' }}"></div>
                                    @endunless
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                @endif

                @if ($openRow === 'prize')
                {{-- The weekly prize. A card of its own rather than a strip inside the
                     standings, which is where it started and where nobody found it:
                     this is the only thing on the page the whole house is chasing
                     together, and it has a deadline, so it can't be a footnote under
                     something else.

                     The bar is segmented per kid because the target is shared — one
                     undivided bar would hide that somebody did most of it — and the
                     number rides inside each segment so the colours need no legend. --}}
                @if ($houseWeek)
                    @php
                        $weekLeft = max(0, $houseWeek['target'] - $houseWeek['done']);
                        $weekPrize = $household->weekly_prize ?: 'a house bonus';
                        $weekDaysLeft = (int) ceil(now($household->timezone)->diffInDays($houseWeek['resetsAt'], absolute: true));
                    @endphp

                    <div wire:key="house-week" class="flex flex-col gap-3">

                        <div
                            class="flex flex-col gap-[11px] rounded-[24px] border p-5"
                            style="background: var(--fq-wash-gold); border-color: {{ $weekLeft === 0 ? 'var(--fq-lime)' : 'var(--fq-gold)' }}"
                        >
                            {{-- The prize is the headline. A bar promising an unnamed
                                 reward is a bar nobody chases, and "80 chores" is the
                                 price rather than the thing being bought. --}}
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="font-baloo text-[22px] leading-[1.15] font-extrabold sm:text-[26px]">{{ $weekPrize }}</h3>
                                <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-4">
                                    {{ number_format($houseWeek['done']) }} / {{ number_format($houseWeek['target']) }} CHORES · SUN&ndash;SAT
                                </span>
                            </div>

                            <div class="flex h-[30px] overflow-hidden rounded-[10px]" style="background: var(--fq-sunk)">
                                @foreach ($houseWeek['segments'] as $segment)
                                    @if ($segment['chores'] > 0)
                                        <div
                                            wire:key="week-{{ $segment['profile']->id }}"
                                            title="{{ $segment['profile']->name }} — {{ $segment['chores'] }}"
                                            class="grid place-items-center font-mono-fq text-[11px] font-semibold"
                                            style="width: {{ min(100, $segment['chores'] / max(1, $houseWeek['target']) * 100) }}%;
                                                   color: var(--fq-bg);
                                                   background: linear-gradient(90deg, color-mix(in srgb, {{ $segment['profile']->color->cssVar() }} 62%, #000), {{ $segment['profile']->color->cssVar() }})"
                                        >{{ $segment['chores'] }}</div>
                                    @endif
                                @endforeach
                            </div>

                            <p class="text-[13px] leading-snug text-fq-text-2" style="text-wrap: pretty">
                                @if ($weekLeft === 0)
                                    Target smashed — the whole house gets {{ $weekPrize }}. Nobody had to win it.
                                @else
                                    {{ number_format($weekLeft) }} more {{ Str::plural('chore', $weekLeft) }} between all of you and
                                    the whole house gets {{ $weekPrize }}. Everyone's chores count towards the same bar —
                                    nobody has to win it.
                                @endif
                            </p>

                            <p class="font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-5 uppercase">
                                @if ($weekDaysLeft <= 1)
                                    Last day &mdash; the bar resets on Sunday
                                @else
                                    {{ $weekDaysLeft }} days left &middot; the bar resets on Sunday
                                @endif
                            </p>
                        </div>
                    </div>
                @endif

                @endif

                @if ($openRow === 'gift' && $giftSiblings->isNotEmpty())
                {{-- The daily gift: one ticket, minted by the house, for whichever
                     sibling this kid picks — see GiftService. The house hears
                     about each one in the feed; this panel is the two kids' own
                     view of it. --}}
                <div
                    wire:key="panel-gift"
                    class="flex flex-col gap-[10px] rounded-[18px] border p-3"
                    style="border-color: var(--fq-line-2); background: linear-gradient(160deg, color-mix(in srgb, var(--fq-lime) 12%, transparent), var(--fq-panel) 72%)"
                >
                    <div class="flex items-center gap-2">
                        <span class="font-mono-fq text-[9px] tracking-[0.2em] uppercase" style="color: var(--fq-lime)">Daily gift</span>
                        <span class="flex-1"></span>
                        <button
                            type="button"
                            wire:click="toggleRow('gift')"
                            aria-label="Close"
                            class="grid h-8 w-8 place-items-center rounded-[11px] border text-[12px]"
                            style="border-color: var(--fq-line-2); background: var(--fq-sunk); color: var(--fq-text-3)"
                        >▲</button>
                    </div>

                    @foreach ($giftsReceived as $received)
                        <div
                            wire:key="gift-in-{{ $received->id }}"
                            class="flex items-center gap-[10px] rounded-[13px] border px-[10px] py-[9px]"
                            style="border-color: var(--fq-line); background: var(--fq-sunk)"
                        >
                            <x-feed.avatar :profile="$received->giver" :size="30" :radius="10" :text="13" />
                            <span class="min-w-0 flex-1 text-[14px] text-fq-text-2">
                                <span class="font-semibold">{{ $received->giver->name }}</span> gave you a ticket
                            </span>
                            <span class="flex-none font-mono-fq text-[10.5px]" style="color: var(--fq-lime)">+{{ GiftService::TICKETS }} 🎟</span>
                        </div>
                    @endforeach

                    @if ($giftGiven)
                        <p class="font-baloo text-[18px] leading-tight font-extrabold">
                            You gave {{ $giftGiven->recipient->name }} a ticket today.
                        </p>
                        <p class="text-[13px] text-fq-text-4">You get another one to give tomorrow.</p>
                    @else
                        <p class="font-baloo text-[18px] leading-tight font-extrabold">Who gets your ticket today?</p>
                        <p class="text-[13px] text-fq-text-4">
                            It costs you nothing, but it's gone at the end of the day if you don't give it.
                        </p>

                        <div class="flex flex-col gap-[7px]">
                            @foreach ($giftSiblings as $sibling)
                                <button
                                    type="button"
                                    wire:key="gift-to-{{ $sibling->id }}"
                                    wire:click="giveGift({{ $sibling->id }})"
                                    wire:loading.attr="disabled"
                                    class="flex h-12 items-center gap-[10px] rounded-[14px] border px-[10px] text-left transition hover:brightness-110 disabled:opacity-60"
                                    style="border-color: var(--fq-line-2); background: var(--fq-panel-alt)"
                                >
                                    <x-feed.avatar :profile="$sibling" :size="30" :radius="10" :text="13" />
                                    <span class="min-w-0 flex-1 truncate font-baloo text-[16px] font-extrabold">{{ $sibling->name }}</span>
                                    <span class="flex-none font-mono-fq text-[10px] tracking-[0.12em] uppercase" style="color: var(--fq-lime)">Give 🎟</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
                @endif

                @if ($openRow === 'feelings')
                {{-- Today's feeling. Behind a row now rather than a card of its
                     own: open, it is the tallest thing on the page, and drawn
                     unasked it pushed the feed a phone-length down.

                     It still folds to a line on the visit *after* it is
                     answered — an answered card is something to read, not to
                     do — but never on the visit it is answered on: the house
                     opening up underneath is the whole reward for pressing the
                     button, and the lock is reached from the card. See
                     `feelingsFolded` in with() for the third condition, which is
                     a waiting reply.

                     Folded, not removed. Feelings move during a day and being
                     able to change your answer says so — see the
                     feeling_entries migration. --}}
                @if ($feelingsFolded)
                    @php $mine = $feelingsCard['answered']; @endphp

                    <button
                        type="button"
                        wire:click="$toggle('showFeelings')"
                        class="flex min-h-[44px] items-center gap-3 rounded-[18px] border border-fq-line-2 bg-fq-panel px-4 py-3 text-left transition hover:border-fq-line-4"
                    >
                        <span
                            class="grid size-8 shrink-0 place-items-center rounded-full text-[15px]"
                            style="border: 1.5px solid {{ $mine->color() }}"
                        >{{ $mine->glyph() }}</span>

                        <span class="min-w-0 flex-1 text-[13.5px] text-fq-text-4">
                            Today you said
                            <span class="font-baloo font-bold" style="color: {{ $mine->color() }}">{{ $mine->label() }}</span>
                            &mdash; the rest of the house is on
                            <span class="text-fq-text-3">Family</span>.
                        </span>

                        <span class="shrink-0 font-mono-fq text-[10px] tracking-[0.12em] text-fq-text-5 uppercase">Change it</span>
                    </button>
                @else
                    <x-feelings-card :card="$feelingsCard" :opened-feeling="$openedFeeling" :lock-message="$feelingLockMessage" />
                @endif
                @endif

                @if ($openRow === 'gratitude')
                {{-- The gratitude quest, moved off Quests, with what the rest of
                     the house wrote underneath it. The feed's own copy of that
                     card is left off this page — see the feed's `quiet`. --}}
                <x-gratitude-quest :today="$gratitudeToday" :message="$gratitudeMessage" />

                <x-feed.grateful-card :gratitude="$gratitudeHouse" :roster="$household->profiles()->count()" />
                @endif

                @if ($openRow === 'meals')
                <x-meal-list :meals="$meals" :today="$mealsToday" />
                @endif

            </div>{{-- /the open panel --}}
        </div>{{-- /Your day --}}

        {{-- ================= The room ================= --}}
        <div class="flex min-w-0 flex-col gap-[14px]">
        {{-- The family feed, in full, and the first thing in this column.

             A one-line strip pointing at /kid/family was tried here first and
             rejected, in one sentence: "otherwise new messages will get missed".
             That is right, and it is the whole argument. Everything else on this
             page is a thing waiting patiently — a chest does not mind being
             opened tomorrow — but a message is somebody's brother having asked
             them a question an hour ago, and a link is something you tap only
             when you already suspect there is something behind it.

             So it is not a link, and nothing goes above it: the rooms, the
             messages and the composer, at the top of the room's own column,
             where they cannot be scrolled past.

             The same component the page at /kid/family draws — see
             resources/views/livewire/family-feed.blade.php. `embedded` drops
             its own <h1>, folds the room rail into the picker so this is one
             column beside the day rather than a third one, and leaves dinner,
             the feelings and gratitude to "Your day" — see `quiet`. --}}
        <div class="flex flex-col gap-3">
            <x-home-section
                title="Family"
                accent="var(--fq-coral)"
                :status="$feedUnread > 0 ? $feedUnread.' waiting' : null"
                status-color="var(--fq-coral)"
            />

            <livewire:family-feed :embedded="true" :show-dinner="false" :capped="false" :quiet="false" />
        </div>
        </div>{{-- /the room --}}
    </div>
</x-kid.shell>
