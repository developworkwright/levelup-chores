<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One song's place in one playlist.
 *
 * `track_id` is MusicService's slug of a file path. Nothing in the database
 * guarantees the file is still there — see the migration.
 */
class PlaylistTrack extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'playlist_id',
        'track_id',
        'title',
        'position',
    ];

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
