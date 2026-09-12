<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How far one person has read in one room. See the create migration for why a
 * missing row means "nothing read" rather than "all read".
 */
class FeedRoomRead extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'profile_id',
        'last_read_message_id',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(FeedRoom::class, 'room_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
