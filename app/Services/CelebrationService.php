<?php

namespace App\Services;

use App\Enums\LedgerKind;
use App\Enums\TicketKind;
use App\Models\CelebrationChest;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Celebration days — the handful of days in a year that are a big deal outside
 * the app, marked inside it.
 *
 * The first one is the first day of school. It exists because that day is the
 * hardest one of the year in this house, and because everything else the app
 * rewards is something a kid chose to do — this is the one that turns up
 * whether they wanted it or not.
 *
 * ## The answer is never priced
 *
 * The chest is gated on *answering* how the day went, and on nothing else.
 * Every answer opens the same chest for the same amount: "Brilliant" and "It
 * was hard" and "Rather not say" all pay {@see self::REWARD}. This is
 * the same rule the feelings card is built on, and it is load-bearing rather
 * than a nicety — the moment a good day pays better than a bad one, the app is
 * buying good answers, and it will get them.
 *
 * What is being paid for is coming back and telling us. That is the part that
 * was hard, and it is a thing the kid did.
 *
 * ## It is not the feelings card
 *
 * That card pays nothing at all, forever, and must keep doing so — a chest
 * hanging off it would end it. This is a separate one-off card that turns up on
 * one day a year, asks one question, and then goes away. The two sit beside
 * each other on Home on the day.
 *
 * ## Nobody misses it
 *
 * The window runs for {@see self::GRACE_DAYS} days past the date. A kid who
 * doesn't open the app until the day after still gets asked and still gets the
 * chest; only the balloons are for the day itself. Losing the good thing by
 * being too flat to open an app is the exact failure this is here for.
 */
class CelebrationService
{
    /**
     * What a chest pays when its day doesn't say otherwise. Denominated in
     * dollars for the same reason the streak chest is — points are backed by
     * `points_per_dollar`, so a number written here in points would silently
     * change value if a household ever re-priced its currency.
     *
     * Deliberately OP: as much as a seven-day streak, plus a full level of XP
     * and a fistful of tickets. These happen a handful of times a year.
     *
     * @var array{dollars: int, tickets: int, xp: int}
     */
    public const REWARD = ['dollars' => 5, 'tickets' => 5, 'xp' => 200];

    /** How long past the date the card keeps asking. */
    public const GRACE_DAYS = 2;

    /** As long as the feelings card's, and for the same reasons. */
    public const MAX_NOTE = 500;

    /**
     * The days themselves. Adding one is adding a row here — a key, a date, the
     * words, and optionally a question. Nothing else in the app knows what the
     * occasion is.
     *
     * Keyed and dated rather than derived, because a celebration is a judgement
     * about one particular day — the app has no way of knowing when school goes
     * back or when somebody's birthday is, and a wrong guess throws a party at
     * a kid on an ordinary Tuesday.
     *
     * Optional keys, and what leaving them out means:
     *
     * - `answers` — the question that gates the chest. **Omit it entirely and
     *   the chest just opens**, which is the right shape for a day that is
     *   nobody's achievement (a birthday, the last day of term). Only ask when
     *   the answer is worth having.
     * - `reward` — overrides {@see self::REWARD}, in the same shape.
     * - `decor` — 'balloons' or 'none'. Defaults to balloons.
     * - `submitLabel` — the button that sends the answer. Defaults to "Send
     *   it". The word and the note go up together, so there is one button and
     *   picking a word does not send anything on its own.
     *
     * `answers` are words, in an order that means nothing: the list is not a
     * scale and must never be sorted best-to-worst, because a kid reading a
     * ranked row learns which end of it the grown-ups want. The colors separate
     * them and say nothing else — see the Feeling enum, which this follows.
     *
     * @var array<string, array<string, mixed>>
     */
    public const DAYS = [
        'first-day-of-school-2026' => [
            'date' => '2026-09-09',
            'kicker' => 'First Day of School',
            'title' => 'You did it!',
            'blurb' => 'The first day is the hardest one of the whole year, and it is behind you now.',
            'question' => 'How did the first day go?',
            'questionNote' => 'Any answer opens the chest. There is no wrong one, and "rather not say" counts.',
            'noteLabel' => 'Anything you want to tell us about it? You do not have to.',
            'submitLabel' => 'Send it and open the chest',
            'chestTitle' => 'Your You Did It chest',
            'chestText' => 'For going, and for coming back and telling us how it went.',
            'lockedText' => 'Tell us how the day went up above, and this one is yours.',
            'accent' => 'var(--fq-magenta)',
            'answers' => [
                ['key' => 'brilliant', 'label' => 'Brilliant', 'glyph' => '🎉', 'color' => 'var(--fq-gold)'],
                ['key' => 'pretty_good', 'label' => 'Pretty good', 'glyph' => '🙂', 'color' => 'var(--fq-lime)'],
                ['key' => 'up_and_down', 'label' => 'Up and down', 'glyph' => '🎢', 'color' => 'var(--fq-cyan)'],
                ['key' => 'long', 'label' => 'Long', 'glyph' => '🥱', 'color' => 'var(--fq-violet)'],
                ['key' => 'hard', 'label' => 'Hard', 'glyph' => '😣', 'color' => 'var(--fq-blue)'],
                ['key' => 'not_saying', 'label' => 'Rather not say', 'glyph' => '🤐', 'color' => 'var(--fq-text-4)'],
            ],
        ],
    ];

    public function __construct(
        private TicketService $tickets,
        private LedgerService $ledger,
        private BadgeService $badges,
    ) {}

    /**
     * The map, read through a method so that a subclass can stand a different
     * day up — which is how the tests exercise a celebration that asks no
     * question without editing the real calendar. `static::` rather than
     * `self::`, so the override actually takes.
     *
     * @return array<string, array<string, mixed>>
     */
    public function days(): array
    {
        return static::DAYS;
    }

    /**
     * The celebration a household is inside right now, or null on all the
     * ordinary days — which is almost all of them.
     *
     * @return array<string, mixed>|null
     */
    public function activeFor(Household $household): ?array
    {
        $today = HouseholdClock::for($household)->today();

        foreach ($this->days() as $key => $day) {
            $date = $this->dateOf($household, $day);

            if ($today->betweenIncluded($date, $date->copy()->addDays(self::GRACE_DAYS))) {
                return ['key' => $key] + $day;
            }
        }

        return null;
    }

    /**
     * Whether it is the day itself rather than one of the grace days. Only the
     * day gets balloons: decoration that outstays the occasion stops reading as
     * an occasion.
     *
     * @param  array<string, mixed>  $day
     */
    public function isTheDay(Household $household, array $day): bool
    {
        return HouseholdClock::for($household)->today()->isSameDay($this->dateOf($household, $day));
    }

    /**
     * Whether this day asks anything before it hands the chest over. A day with
     * no question is a day nobody has to earn.
     *
     * @param  array<string, mixed>  $day
     */
    public function asksAQuestion(array $day): bool
    {
        return ($day['answers'] ?? []) !== [];
    }

    /**
     * What this day's chest pays, in points for this household.
     *
     * @param  array<string, mixed>  $day
     * @return array{points: int, tickets: int, xp: int}
     */
    public function rewardFor(Household $household, array $day): array
    {
        $reward = ($day['reward'] ?? []) + self::REWARD;

        return [
            'points' => $reward['dollars'] * $household->points_per_dollar,
            'tickets' => $reward['tickets'],
            'xp' => $reward['xp'],
        ];
    }

    public function entryFor(Profile $profile, string $key): ?CelebrationChest
    {
        return CelebrationChest::where('profile_id', $profile->id)
            ->where('celebration_key', $key)
            ->first();
    }

    /**
     * Record how the day went, or change the answer.
     *
     * Updates rather than appends, and stays editable after the chest has been
     * opened: the reward is already banked and cannot be taken back, so nothing
     * rides on the answer and a kid is free to say the truer thing an hour
     * later. No history is kept of what was said first.
     *
     * An answer that isn't one of the day's own is dropped rather than stored —
     * a hand-edited request must not be able to file a kid under a word nobody
     * offered them.
     */
    public function answer(Profile $profile, string $key, string $answer, ?string $note = null): ?CelebrationChest
    {
        $day = $this->days()[$key] ?? null;

        if ($day === null || ! $this->isValidAnswer($day, $answer)) {
            return null;
        }

        $note = trim((string) $note);

        return CelebrationChest::updateOrCreate(
            ['profile_id' => $profile->id, 'celebration_key' => $key],
            [
                'household_id' => $profile->household_id,
                'answer' => $answer,
                'note' => $note === '' ? null : mb_substr($note, 0, self::MAX_NOTE),
                'answered_at' => now(),
            ],
        );
    }

    /**
     * Whether the chest is sitting there waiting to be opened — answered on a
     * day that asks, and any time at all on a day that doesn't.
     */
    public function isOpenable(Profile $profile, string $key): bool
    {
        $day = $this->days()[$key] ?? null;

        if ($day === null) {
            return false;
        }

        $entry = $this->entryFor($profile, $key);

        if ($entry?->isOpened()) {
            return false;
        }

        return ! $this->asksAQuestion($day) || $entry?->isAnswered() === true;
    }

    /**
     * Hand it over. Null when there is nothing to open — unanswered, already
     * opened, or a key that is not a celebration.
     *
     * Every kid gets the same three rewards at once rather than a roll. A
     * celebration that could come up short is one some kid remembers as the
     * year they got the bad one.
     */
    public function open(Profile $profile, string $key): ?CelebrationChest
    {
        if (! isset($this->days()[$key]) || ! $this->isOpenable($profile, $key)) {
            return null;
        }

        $day = $this->days()[$key];
        $reward = $this->rewardFor($profile->household, $day);

        // A day that asks nothing has no row yet, because nothing has been
        // written on one — opening is the first thing that happens.
        $entry = $this->entryFor($profile, $key) ?? new CelebrationChest([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            'celebration_key' => $key,
        ]);

        $entry->forceFill([
            'household_id' => $profile->household_id,
            'profile_id' => $profile->id,
            'celebration_key' => $key,
            'opened_at' => now(),
            'reward_points' => $reward['points'],
            'reward_tickets' => $reward['tickets'],
            'reward_xp' => $reward['xp'],
        ])->save();

        $label = $day['kicker'];

        $this->ledger->record(
            $profile->household,
            $profile,
            LedgerKind::Earn,
            $reward['points'],
            "{$profile->name} — {$label}",
            $entry,
        );

        $this->tickets->record($profile, TicketKind::Adjustment, $reward['tickets'], $label, $entry);

        $profile->xp += $reward['xp'];
        $profile->save();

        // XP can cross a level, which mints a ticket of its own.
        $this->tickets->syncLevelTickets($profile);

        // The points can tick a badge off, so this runs after the reward lands.
        $this->badges->evaluate($profile);

        return $entry->refresh();
    }

    /**
     * The headline number on the reveal card.
     *
     * @param  array<string, mixed>  $day
     */
    public function describeReward(Household $household, array $day): string
    {
        return '+'.number_format($this->rewardFor($household, $day)['points']).' PTS';
    }

    /**
     * The rest of the payout, spelled out under it.
     *
     * @param  array<string, mixed>  $day
     */
    public function describeExtras(Household $household, array $day): string
    {
        $reward = $this->rewardFor($household, $day);

        return $reward['tickets'].' '.Str::plural('ticket', $reward['tickets'])
            .' · +'.number_format($reward['xp']).' XP';
    }

    /**
     * The option a stored answer came from, for reading it back. Null for an
     * answer whose day has since been edited out from under it.
     *
     * @param  array<string, mixed>  $day
     * @return array<string, string>|null
     */
    public function answerOption(array $day, ?string $answer): ?array
    {
        return collect($day['answers'])->firstWhere('key', $answer);
    }

    /**
     * Everyone's answers for one celebration, for the parent console.
     *
     * Parents only. A kid sees their own row and nothing else — a first day
     * that went badly is not something to be read by a sibling whose day went
     * well, which is the rule the feelings card's replies already follow.
     *
     * @return Collection<int, CelebrationChest>
     */
    public function houseAnswers(Household $household, string $key): Collection
    {
        return CelebrationChest::where('household_id', $household->id)
            ->where('celebration_key', $key)
            ->whereNotNull('answered_at')
            ->with('profile')
            ->get()
            ->sortBy(fn (CelebrationChest $entry) => $entry->profile?->name ?? '')
            ->values();
    }

    /** @param array<string, mixed> $day */
    private function dateOf(Household $household, array $day): Carbon
    {
        return Carbon::parse($day['date'], $household->timezone)->startOfDay();
    }

    /** @param array<string, mixed> $day */
    private function isValidAnswer(array $day, string $answer): bool
    {
        return collect($day['answers'])->contains(fn (array $option) => $option['key'] === $answer);
    }
}
