<?php

namespace App\Services;

use App\Enums\ReactionKind;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Quote;
use App\Models\QuoteReaction;
use App\Notifications\QuoteAdded;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Quote of the Day — the funny things the kids say, written down by a grown-up
 * before everyone forgets them.
 *
 * Nothing is ever crowned. A day with one quote on it has a Quote of the Day; a
 * day with several has *contenders*, and they stay contenders permanently. The
 * label is the joke, not a bracket to settle — see the migration.
 *
 * Entry is parent-only, because the whole feature depends on somebody being in
 * the room when the line lands. Everything after that is for everyone: the day's
 * quotes sit on the kid Home page and the whole archive is on the Journal.
 */
class QuoteService
{
    /** Long enough for a rambling four-year-old, short enough to stay a quote. */
    public const MAX_LENGTH = 300;

    /** The "what was going on" line. Shorter than the quote on purpose. */
    public const MAX_CONTEXT = 200;

    /**
     * What every list of quotes is loaded with. The reactions and their people
     * are read on every row that gets drawn — the faces under a quote name who
     * laughed, which is the entire point of them — so lazy-loading here is a
     * guaranteed N+1 on a page of twenty-five.
     *
     * @var array<int, string>
     */
    private const WITH_REACTIONS = ['profile', 'reactions.profile'];

    /**
     * Writes a quote down and tells the kids about it.
     *
     * `$said` is the kid who said it; `$saidBy` is the fallback for anyone
     * without a profile. Passing both keeps the profile — a real member of the
     * household beats a typed name every time.
     *
     * Null when the quote itself is blank. Everything else is optional: a
     * parent with ten seconds should be able to type the line and nothing else.
     */
    public function record(
        Profile $parent,
        string $text,
        ?Profile $said = null,
        ?string $saidBy = null,
        ?string $context = null,
        ?Carbon $date = null,
    ): ?Quote {
        $text = mb_substr(trim($text), 0, self::MAX_LENGTH);

        if ($text === '') {
            return null;
        }

        $household = $parent->household;
        $clock = HouseholdClock::for($household);

        $quote = Quote::create([
            'household_id' => $household->id,
            // Guarded against a kid from another house being passed in — this
            // is the only place a profile id enters the table.
            'profile_id' => $said?->household_id === $household->id ? $said->id : null,
            'said_by' => $said?->household_id === $household->id
                ? null
                : (mb_substr(trim((string) $saidBy), 0, 100) ?: null),
            'text' => $text,
            'context' => mb_substr(trim((string) $context), 0, self::MAX_CONTEXT) ?: null,
            // Backdatable, so a line remembered on Thursday can be filed under
            // the Tuesday it was actually said on.
            'said_on' => $date ? $clock->dayFor($date) : $clock->today(),
            'added_by_profile_id' => $parent->id,
        ]);

        $this->announce($quote);

        return $quote;
    }

    /**
     * Every quote filed under one household day, oldest first — the order the
     * contenders were said in.
     *
     * @return Collection<int, Quote>
     */
    public function forDay(Household $household, Carbon $date): Collection
    {
        return Quote::where('household_id', $household->id)
            ->whereDate('said_on', $date)
            ->with(self::WITH_REACTIONS)
            ->orderBy('id')
            ->get();
    }

    /**
     * What the Home card shows: today's quotes, or — on the many days nobody
     * says anything quotable — the most recent day that has any.
     *
     * Falling back rather than emptying is deliberate. A card that vanishes for
     * five days at a time is a card nobody learns is there, and the last funny
     * thing your brother said is still worth reading on a quiet Tuesday.
     *
     * @return array{date: Carbon, quotes: Collection<int, Quote>}|null
     */
    public function latestDay(Household $household): ?array
    {
        $date = $this->today($household);

        if ($this->forDay($household, $date)->isEmpty()) {
            $latest = Quote::where('household_id', $household->id)->max('said_on');

            if ($latest === null) {
                return null;
            }

            $date = Carbon::parse($latest);
        }

        $quotes = $this->forDay($household, $date);

        return $quotes->isEmpty() ? null : ['date' => $date, 'quotes' => $quotes];
    }

    /**
     * The whole archive, newest day first.
     *
     * Rows are paged rather than days, so a very busy day can straddle a page
     * boundary. That's accepted: paging distinct dates costs a second query and
     * a hand-built paginator to fix something that only shows up on a day with
     * more than a page of quotes on it.
     *
     * @return LengthAwarePaginator<int, Quote>
     */
    public function archive(Household $household, int $perPage = 20, string $pageName = 'page'): LengthAwarePaginator
    {
        return Quote::where('household_id', $household->id)
            ->with(self::WITH_REACTIONS)
            ->newestDayFirst()
            ->paginate($perPage, ['*'], $pageName);
    }

    public function countFor(Household $household): int
    {
        return Quote::where('household_id', $household->id)->count();
    }

    /** The household day a quote card is talking about. */
    public function today(Household $household): Carbon
    {
        return HouseholdClock::for($household)->today();
    }

    public function isToday(Household $household, Carbon $date): bool
    {
        return $date->isSameDay($this->today($household));
    }

    /**
     * What to call a day's worth of quotes.
     *
     * The one piece of copy this feature turns on, so it lives here rather than
     * being written out three times across Home, the Journal and the parent
     * console — where two of them would eventually disagree.
     */
    public static function heading(int $count): string
    {
        return $count > 1 ? 'Contenders for Quote of the Day' : 'Quote of the Day';
    }

    /**
     * Adds a reaction, or takes it back when the same face is tapped again.
     *
     * Returns whether the reaction is now on. Silently refuses a quote from
     * another household — this is reachable from any kid page, so the scope
     * check belongs here rather than in each of them.
     *
     * A kid may react to their own quote. Policing that would be a rule with
     * nothing behind it: nothing is ranked, so there is no score to inflate.
     */
    public function react(Profile $profile, int $quoteId, ?string $reaction): bool
    {
        $kind = ReactionKind::tryFromValue($reaction);
        $quote = Quote::where('household_id', $profile->household_id)->find($quoteId);

        if (! $kind || ! $quote) {
            return false;
        }

        $existing = QuoteReaction::where('quote_id', $quote->id)
            ->where('profile_id', $profile->id)
            ->where('reaction', $kind->value)
            ->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        QuoteReaction::create([
            'quote_id' => $quote->id,
            'profile_id' => $profile->id,
            'reaction' => $kind,
        ]);

        return true;
    }

    /**
     * The row of faces under one quote: all four, always, whether or not anyone
     * has tapped them.
     *
     * All four rather than only the ones with a count, because the row is the
     * control as well as the readout — a kid who has to find a hidden "add
     * reaction" button before they can laugh at their brother won't.
     *
     * Reads the already-loaded relation rather than querying, so a page of
     * twenty-five quotes costs the one eager load in WITH_REACTIONS.
     *
     * @return array<int, array{kind: ReactionKind, count: int, mine: bool, who: string}>
     */
    public static function reactionSummary(Quote $quote, ?Profile $viewer = null): array
    {
        $grouped = $quote->reactions->groupBy(fn (QuoteReaction $row) => $row->reaction->value);

        return collect(ReactionKind::cases())
            ->map(function (ReactionKind $kind) use ($grouped, $viewer) {
                $rows = $grouped->get($kind->value) ?? collect();

                return [
                    'kind' => $kind,
                    'count' => $rows->count(),
                    'mine' => $viewer !== null && $rows->contains('profile_id', $viewer->id),
                    // Named, not just counted. "Mabel, Otto" is the bit worth
                    // reading — knowing your sister laughed is the reward, and
                    // a bare 2 doesn't say it.
                    'who' => $rows->pluck('profile.name')->filter()->implode(', '),
                ];
            })
            ->all();
    }

    /**
     * Everything that has happened to this kid's quotes since `$since` — what
     * the celebration cards in the kid shell are built from.
     *
     * Two things, one stamp: a quote written down anywhere in the house, and
     * somebody reacting to one of *theirs*. From the kid's side these are the
     * same question, which is why one `quotes_seen_at` covers both.
     *
     * Own reactions are excluded. Being told you laughed at your own quote is
     * the app talking to itself.
     *
     * @return array{quotes: Collection<int, Quote>, reactions: Collection<int, QuoteReaction>}
     */
    public function newsFor(Profile $profile, Carbon $since): array
    {
        return [
            'quotes' => Quote::where('household_id', $profile->household_id)
                ->where('created_at', '>', $since)
                ->with('profile')
                ->orderBy('id')
                ->get(),
            'reactions' => QuoteReaction::whereIn(
                'quote_id',
                Quote::where('household_id', $profile->household_id)
                    ->where('profile_id', $profile->id)
                    ->select('id')
            )
                ->where('profile_id', '!=', $profile->id)
                ->where('created_at', '>', $since)
                ->with('profile', 'quote')
                ->orderBy('id')
                ->get(),
        ];
    }

    /**
     * Tells the whole household — every kid, including whoever said it, and
     * every parent except the one who just wrote it down.
     *
     * Parents are in the audience because a house has more than one grown-up in
     * it and only one of them was in the room. The author is left out for the
     * same reason: they are holding the phone they typed it into.
     *
     * The quote rides in the body: it is the rare notification whose entire
     * content fits in the banner, and making anyone open the app to read one
     * line would be worse than the line. Failures are logged, never thrown — a
     * dead push subscription must not lose the quote that was just written.
     */
    private function announce(Quote $quote): void
    {
        $audience = Profile::where('household_id', $quote->household_id)
            ->when(
                $quote->added_by_profile_id !== null,
                fn ($query) => $query->whereKeyNot($quote->added_by_profile_id),
            )
            ->get();

        try {
            Notification::send($audience, new QuoteAdded(
                $quote->attribution().' said…',
                '“'.$quote->text.'”',
            ));
        } catch (Throwable $e) {
            Log::error('Quote added notification failed.', [
                'quote_id' => $quote->id,
                'exception' => $e,
            ]);
        }
    }
}
