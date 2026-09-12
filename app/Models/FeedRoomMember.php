<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the two people in a direct room. Only direct rooms have these — see
 * the create migration for why the group rooms derive their members instead.
 */
class FeedRoomMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'profile_id',
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
