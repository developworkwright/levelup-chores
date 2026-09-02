<?php

namespace App\Models;

use App\Enums\ArcadeGame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One posted run of one of the arcade's cabinets.
 *
 * A score used to be attached to nobody on purpose, because the cabinet stood
 * on the public login page. It is behind the PIN now and rows name the person
 * who played — see the migration that added the columns.
 *
 * `game` says which cabinet, and no query here should ever go without it: the
 * two games score different things, so a board that mixes them is measuring
 * nothing.
 */
class ArcadeScore extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'household_id',
        'profile_id',
        'game',
        'codename',
        'score',
        'week',
    ];

    protected function casts(): array
    {
        return [
            'game' => ArcadeGame::class,
            'score' => 'integer',
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

    /**
     * The name on the board.
     *
     * The live profile name first, so a kid who renames themselves renames
     * every run they ever posted — the same call `Quote::attribution()` makes,
     * and for the same reason. `codename` is the fallback and holds two kinds
     * of row: the rolled names from the years the board was public, and a
     * snapshot of the player's name at the time of posting, which is what is
     * left to read if a profile is ever deleted.
     */
    public function displayName(): string
    {
        return $this->profile?->name ?? $this->codename;
    }
}
