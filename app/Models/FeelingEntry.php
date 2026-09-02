<?php

namespace App\Models;

use App\Enums\Feeling;
use App\Enums\FeelingVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's answer for one day. See the create migration for why it pays
 * nothing and why parents fill one in too.
 */
class FeelingEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'profile_id',
        'felt_on',
        'feeling',
        'feeling_word_id',
        'because',
        'because_locked',
        'lock_salt',
        'locked_at',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'felt_on' => 'date',
            'feeling' => Feeling::class,
            'visibility' => FeelingVisibility::class,
            'locked_at' => 'datetime',
        ];
    }

    /**
     * Hidden so a locked reason can't leak through a model serialised into a
     * Livewire payload, an API response or a log line. The only way to see it
     * is FeelingService::openLocked(), which needs the writer's PIN.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'because_locked',
        'lock_salt',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Set only when the answer was one of the writer's own words. */
    public function word(): BelongsTo
    {
        return $this->belongsTo(FeelingWord::class, 'feeling_word_id');
    }

    /*
     * The four below are what every view calls, so that nothing outside this
     * model has to know whether today's answer was a built-in feeling or a word
     * somebody added. Exactly one of `feeling` and `word` is ever set — see the
     * feeling_words migration for why it is an either/or rather than one table.
     */

    public function label(): string
    {
        return $this->word?->label ?? $this->feeling?->label() ?? '';
    }

    public function glyph(): string
    {
        return $this->word?->displayGlyph() ?? $this->feeling?->glyph() ?? '';
    }

    public function color(): string
    {
        return $this->word?->cssVar() ?? $this->feeling?->cssVar() ?? 'var(--fq-text-4)';
    }

    public function stem(): string
    {
        return $this->word?->stem() ?? $this->feeling?->stem() ?? '';
    }

    /** Whether the reason has been sealed with the writer's PIN. */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Whether anything was written past the feeling word itself.
     *
     * True for a locked entry too: there is a reason, it just can't be read
     * from here. The card needs to know one exists so it can draw the padlock
     * rather than nothing at all — an entry that hid the fact it had been
     * locked would make the lock look like it had lost the text.
     */
    public function hasBecause(): bool
    {
        return $this->isLocked() || trim((string) $this->because) !== '';
    }

    /**
     * Whether `$viewer` may read this entry's *because*.
     *
     * The feeling word is never gated by this — it is public to the household
     * by design. Only the reason has a door on it.
     *
     * Written as an allow-list with the author first: the author always sees
     * their own, and every other case has to be granted. Getting this backwards
     * in either direction is the one bug in this feature that would actually
     * matter, so it is one method with one test per branch rather than a
     * condition inlined at each call site.
     */
    public function becauseVisibleTo(Profile $viewer): bool
    {
        // A locked reason is visible to nobody through this path — not even to
        // the person who wrote it. Opening it needs their PIN and goes through
        // FeelingService::openLocked(). This is the check that keeps the house
        // strip, and anything else that renders a reason, from ever printing a
        // sealed one.
        if ($this->isLocked()) {
            return false;
        }

        if (! $this->hasBecause()) {
            return false;
        }

        // Never across households, whatever the visibility says.
        if ((int) $viewer->household_id !== (int) $this->household_id) {
            return false;
        }

        if ((int) $viewer->id === (int) $this->profile_id) {
            return true;
        }

        return match ($this->visibility) {
            FeelingVisibility::House => true,
            FeelingVisibility::Parents => $viewer->isParent(),
            FeelingVisibility::Private => false,
        };
    }
}
