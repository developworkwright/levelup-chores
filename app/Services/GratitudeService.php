<?php

namespace App\Services;

use App\Enums\TicketKind;
use App\Models\GratitudeEntry;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The gratitude quest — name three things you're grateful for, once a
 * household-day, for three bonus tickets.
 *
 * It's the one quest that pays for something other than work, so it's priced
 * against a chore rather than against effort: three tickets is generous on
 * purpose, because the habit is the reward the household actually wants.
 *
 * The answers are kept, not just the payout. Reading them back at the end of a
 * week is the whole point — see journalFor().
 */
class GratitudeService
{
    /** Tickets paid for a completed entry. */
    public const TICKETS = 3;

    /** How many things have to be named before it counts. */
    public const ITEMS = 3;

    /** Long enough for a sentence, short enough to stay a list. */
    public const MAX_LENGTH = 120;

    public function __construct(private TicketService $tickets) {}

    public function todayFor(Profile $profile): ?GratitudeEntry
    {
        return GratitudeEntry::where('profile_id', $profile->id)
            ->whereDate('entry_date', HouseholdClock::for($profile->household)->today())
            ->first();
    }

    /** Available on any household-day the kid hasn't already written one. */
    public function isAvailable(Profile $profile): bool
    {
        return ! $this->todayFor($profile);
    }

    /**
     * Records the entry and pays the tickets. Null when today's is already
     * written, or when fewer than ITEMS things were actually named — a blank
     * line is not something to be grateful for.
     *
     * @param  array<int, string|null>  $items
     */
    public function record(Profile $profile, array $items): ?GratitudeEntry
    {
        $items = $this->clean($items);

        if (count($items) < self::ITEMS || ! $this->isAvailable($profile)) {
            return null;
        }

        return DB::transaction(function () use ($profile, $items) {
            $entry = GratitudeEntry::create([
                'household_id' => $profile->household_id,
                'profile_id' => $profile->id,
                'entry_date' => HouseholdClock::for($profile->household)->today(),
                'items' => $items,
            ]);

            $this->tickets->record(
                $profile,
                TicketKind::Gratitude,
                self::TICKETS,
                "{$profile->name} — gratitude quest",
                $entry,
            );

            return $entry;
        });
    }

    /**
     * One kid's own journal, newest first. Paged rather than capped — nothing
     * is ever pruned, and the point of keeping them is being able to read back
     * further than the last screenful.
     *
     * @return LengthAwarePaginator<int, GratitudeEntry>
     */
    public function journalFor(Profile $profile, int $perPage = 15): LengthAwarePaginator
    {
        return GratitudeEntry::where('profile_id', $profile->id)
            ->latest('entry_date')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Everyone's, for the parent who was told these things in the first place.
     *
     * @return Collection<int, GratitudeEntry>
     */
    public function journalForHousehold(Household $household, int $limit = 20): Collection
    {
        return GratitudeEntry::where('household_id', $household->id)
            ->with('profile')
            ->latest('entry_date')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Trims, drops the blanks and caps the length, then takes the first ITEMS.
     * Reindexed so the JSON column stores a list rather than an object with
     * holes in it where the empty boxes were.
     *
     * @param  array<int, string|null>  $items
     * @return array<int, string>
     */
    private function clean(array $items): array
    {
        return collect($items)
            ->map(fn (?string $item) => mb_substr(trim((string) $item), 0, self::MAX_LENGTH))
            ->filter(fn (string $item) => $item !== '')
            ->take(self::ITEMS)
            ->values()
            ->all();
    }
}
