<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A word somebody added to their own feelings card. See the create migration
 * for why it belongs to a person rather than to the house.
 */
class FeelingWord extends Model
{
    use HasFactory;

    /** Room for a word, not a sentence. */
    public const MAX_LABEL = 24;

    /**
     * The hues a custom word can land on.
     *
     * Deliberately the same non-judgmental spread the built-in words use, and
     * deliberately not chosen by the person adding the word. A color picker
     * would invite "sad is red, happy is green", which is the traffic light the
     * built-in palette exists to avoid — a grid that ranks the answers teaches
     * a kid which one pleases the room.
     *
     * @var array<int, string>
     */
    private const HUES = [
        'var(--fq-gold)',
        'var(--fq-magenta)',
        'var(--fq-cyan)',
        'var(--fq-lime)',
        'var(--fq-green)',
        'var(--fq-violet)',
        'var(--fq-blue)',
        'var(--fq-coral)',
    ];

    /** What a word with no glyph draws. Neutral, and not a face. */
    public const DEFAULT_GLYPH = '•';

    protected $fillable = [
        'household_id',
        'profile_id',
        'label',
        'glyph',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Named `displayGlyph` rather than `glyph` because `glyph` is already the
     * column: a method of the same name works but leaves `$word->glyph` and
     * `$word->glyph()` meaning different things, which is a trap rather than an
     * API.
     */
    public function displayGlyph(): string
    {
        return $this->glyph ?: self::DEFAULT_GLYPH;
    }

    /**
     * The same word always gets the same color, on every card and every day.
     *
     * Derived rather than stored so it can't drift, and seeded off the label so
     * a word that gets retired and re-added comes back looking like itself.
     */
    public function cssVar(): string
    {
        return self::HUES[crc32(mb_strtolower($this->label)) % count(self::HUES)];
    }

    /** "Today I felt wobbly" — the stem the because box finishes. */
    public function stem(): string
    {
        return 'Today I felt '.mb_strtolower($this->label);
    }
}
