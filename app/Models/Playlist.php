<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One profile's own running order through the music library — kid or parent.
 *
 * @see PlaylistService for why a song can be in here and not on the disk.
 */
class Playlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'name',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Its songs, in the order they play.
     *
     * Ordered on the relation rather than at every call site: an unordered
     * playlist is not a playlist, and a forgotten orderBy would show up as
     * songs quietly shuffling themselves after an edit.
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(PlaylistTrack::class)->orderBy('position');
    }
}
