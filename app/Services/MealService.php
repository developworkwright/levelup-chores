<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Meal;
use App\Models\Profile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What's for dinner, and who gets to say.
 *
 * A thin service over one table, and it stays thin on purpose — see the meals
 * migration for the ingredients-and-shopping-list shape this is meant to grow
 * into, and for why none of that is here yet.
 *
 * Every date in here resolves through {@see HouseholdClock}, never `now()`.
 * That is a house rule, and this is exactly the feature it was written for: the
 * household day rolls at 4am, so a kid opening the app at 1am on Saturday is
 * still in Friday evening and "tonight's dinner" is still Friday's.
 */
class MealService
{
    /** Long enough for a real dinner, short enough to stay a line on a card. */
    public const MAX_NAME = 120;

    public const MAX_NOTE = 200;

    /** How many days the parent screen plans at once. */
    public const WEEK = 7;

    /** Tonight's dinner, or null when nobody has said. */
    public function tonight(Household $household): ?Meal
    {
        return $this->on($household, HouseholdClock::for($household)->today());
    }

    /**
     * Tomorrow's, if it has been set.
     *
     * The kids' card shows this under tonight's, and only when it exists — a
     * standing "tomorrow: not set yet" line would be the app nagging a grown-up
     * on a screen the grown-up isn't the audience for.
     */
    public function tomorrow(Household $household): ?Meal
    {
        return $this->on($household, HouseholdClock::for($household)->today()->addDay());
    }

    /**
     * Every night a grown-up has filled in, from tonight on, soonest first.
     *
     * What the kids' Meals panel lists. Unset nights are left out rather than
     * drawn as gaps — see tomorrow() for why a "not set yet" line is a nag.
     *
     * @return Collection<int, Meal>
     */
    public function upcoming(Household $household): Collection
    {
        return Meal::where('household_id', $household->id)
            ->whereDate('served_on', '>=', HouseholdClock::for($household)->today()->toDateString())
            ->orderBy('served_on')
            ->get();
    }

    public function on(Household $household, Carbon $date): ?Meal
    {
        return Meal::where('household_id', $household->id)
            ->whereDate('served_on', $date->toDateString())
            ->first();
    }

    /**
     * The week the parent screen edits: today first, then the six days after it.
     *
     * Today first rather than Monday first, because the question this screen
     * answers most often is "what did I say we were having tonight" and the
     * answer should not be four rows down on a Thursday.
     *
     * One query for the whole week rather than one per row.
     *
     * @return array<int, array{date: Carbon, meal: ?Meal}>
     */
    public function week(Household $household, ?Carbon $from = null): array
    {
        $start = ($from ?? HouseholdClock::for($household)->today())->copy()->startOfDay();

        $days = collect(range(0, self::WEEK - 1))->map(fn (int $offset) => $start->copy()->addDays($offset));

        // whereDate on both ends rather than a whereBetween on the raw column.
        // `served_on` is a date column but Eloquent's date cast writes it back
        // as 'Y-m-d H:i:s', so a string comparison against a bare 'Y-m-d' puts
        // the last day of the week *after* the upper bound and drops it.
        /** @var Collection<string, Meal> $meals */
        $meals = Meal::where('household_id', $household->id)
            ->whereDate('served_on', '>=', $days->first()->toDateString())
            ->whereDate('served_on', '<=', $days->last()->toDateString())
            ->get()
            ->keyBy(fn (Meal $meal) => $meal->served_on->toDateString());

        return $days
            ->map(fn (Carbon $date) => [
                'date' => $date,
                'meal' => $meals->get($date->toDateString()),
            ])
            ->all();
    }

    /**
     * Set — or clear — what's for dinner on a day.
     *
     * A blank name clears the night rather than storing an empty row, because
     * emptying the box is the only gesture the parent screen offers for "we
     * don't know yet" and it has to mean that. Returns null when it cleared.
     *
     * Idempotent through the table's unique index: setting the same night twice
     * updates the one row rather than growing a second.
     */
    public function set(Household $household, Profile $by, Carbon $date, string $name, string $note = ''): ?Meal
    {
        $name = $this->clean($name, self::MAX_NAME);

        if ($name === '') {
            $this->clear($household, $date);

            return null;
        }

        $attributes = [
            'name' => $name,
            'note' => $this->clean($note, self::MAX_NOTE) ?: null,
            'set_by_profile_id' => $by->id,
        ];

        // Found through on(), which matches on the date part, rather than
        // through updateOrCreate(). The date cast writes `served_on` back as
        // 'Y-m-d H:i:s', so updateOrCreate's exact-match lookup on a bare
        // 'Y-m-d' never finds the row it just wrote — and then trips the unique
        // index trying to insert a second one.
        $meal = $this->on($household, $date);

        if ($meal) {
            $meal->fill($attributes)->save();

            return $meal;
        }

        return Meal::create([
            'household_id' => $household->id,
            'served_on' => $date->toDateString(),
            ...$attributes,
        ]);
    }

    public function clear(Household $household, Carbon $date): void
    {
        Meal::where('household_id', $household->id)
            ->whereDate('served_on', $date->toDateString())
            ->delete();
    }

    /** Same shape as FeedService::clean() — collapse the spaces, then cap it. */
    private function clean(string $value, int $limit): string
    {
        $value = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? '');

        return trim(mb_substr($value, 0, $limit));
    }
}
