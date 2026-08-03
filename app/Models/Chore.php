<?php

namespace App\Models;

use App\Enums\ChoreCadence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Chore extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'name',
        'hint',
        'points',
        'cadence',
        'min_age',
        'quest_eligible',
        'wheel_eligible',
        'used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'cadence' => ChoreCadence::class,
            'quest_eligible' => 'boolean',
            'wheel_eligible' => 'boolean',
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(ChoreCompletion::class);
    }

    public function cooldownDays(): int
    {
        return $this->cadence === ChoreCadence::Weekly ? 7 : 1;
    }

    public function isOneTime(): bool
    {
        return $this->cadence === ChoreCadence::Once;
    }

    /**
     * A one-time chore that's already been taken. Unlike every other cadence
     * this doesn't expire on its own — only a parent reactivating it (or the
     * claim being sent back) puts it back on the board.
     */
    public function isUsedUp(): bool
    {
        return $this->isOneTime() && $this->used_at !== null;
    }

    /** Chores that can still be earned — a spent one-time chore drops out entirely. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('cadence', '!=', ChoreCadence::Once->value)
                ->orWhereNull('used_at');
        });
    }

    /**
     * Whether a parent's deadline has already passed on this chore.
     *
     * A deadline binds only for the household day it lands in. It exists so a
     * parent can say "beat me to it before dinner" and then do the job
     * themselves — not to impose a standing curfew — so a stamp left over from
     * an earlier day is inert and the chore is back on its ordinary cadence.
     *
     * $dayStart is the instant today's household day began; $now is compared
     * against it, so both must be in the app timezone (see HouseholdClock).
     */
    public function hasExpiredAt(Carbon $now, Carbon $dayStart): bool
    {
        if ($this->expires_at === null || $this->expires_at->lt($dayStart)) {
            return false;
        }

        return $this->expires_at->lte($now);
    }

    /**
     * The live deadline a kid's countdown ticks down to, or null when there
     * isn't one — either none was set, it has already passed, or it's a stale
     * stamp from an earlier day.
     */
    public function closesAt(Carbon $now, Carbon $dayStart): ?Carbon
    {
        if ($this->expires_at === null || $this->expires_at->lt($dayStart)) {
            return null;
        }

        return $this->expires_at->gt($now) ? $this->expires_at : null;
    }

    /**
     * The SQL twin of hasExpiredAt(), inverted — chores whose deadline hasn't
     * bitten. Kept alongside its in-memory counterpart the same way
     * scopeMatching() and matches() are, so the two can't drift.
     */
    public function scopeNotExpiredAt(Builder $query, Carbon $now, Carbon $dayStart): Builder
    {
        return $query->where(function (Builder $q) use ($now, $dayStart) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', $now)
                ->orWhere('expires_at', '<', $dayStart);
        });
    }

    public function isAppropriateFor(Profile $profile): bool
    {
        return $this->min_age === null || ($profile->age !== null && $profile->age >= $this->min_age);
    }

    public function scopeAppropriateFor(Builder $query, Profile $profile): Builder
    {
        return $query->where(function (Builder $q) use ($profile) {
            $q->whereNull('min_age')->orWhere('min_age', '<=', $profile->age ?? 0);
        });
    }

    public function scopeQuestEligible(Builder $query): Builder
    {
        return $query->where('quest_eligible', true);
    }

    public function scopeWheelEligible(Builder $query): Builder
    {
        return $query->where('wheel_eligible', true);
    }

    /**
     * Columns free-text search looks at.
     *
     * Searching happens two ways — as SQL on the parent admin, and in PHP on
     * the kid's board, which is already an in-memory collection. Both read
     * this list so they can't drift, and tags only need adding here plus the
     * matching join.
     *
     * @var array<int, string>
     */
    public const SEARCHABLE = ['name'];

    /**
     * Free-text search across a chore's searchable columns.
     *
     * The conditions are wrapped in their own group deliberately: the
     * orWhere below would otherwise escape and swallow the household scope on
     * the outer query, leaking another family's chores into the results.
     */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // Escape the LIKE wildcards so searching for "50%" doesn't match
        // everything, and a literal underscore stays a literal underscore.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);

        return $query->where(function (Builder $group) use ($escaped) {
            foreach (self::SEARCHABLE as $column) {
                // ESCAPE has to be stated outright: MySQL defaults to
                // backslash but SQLite has no default at all, so without this
                // the escaping above would quietly stop working on one engine
                // and not the other. Bound as a parameter rather than a SQL
                // literal, since the two also disagree about backslashes
                // inside quotes. Column names come from the constant above,
                // never from user input.
                $group->orWhereRaw("{$column} LIKE ? ESCAPE ?", ["%{$escaped}%", '\\']);
            }
        });
    }

    /**
     * The in-memory twin of scopeMatching(), for filtering a board that's
     * already been loaded rather than issuing a second query.
     */
    public function matches(?string $term): bool
    {
        $term = trim((string) $term);

        if ($term === '') {
            return true;
        }

        $needle = mb_strtolower($term);

        foreach (self::SEARCHABLE as $column) {
            if (str_contains(mb_strtolower((string) $this->{$column}), $needle)) {
                return true;
            }
        }

        return false;
    }
}
