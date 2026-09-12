<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's one reaction to one message. See the create migration.
 */
class FeedReaction extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'message_id',
        'profile_id',
        'emoji',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(FeedMessage::class, 'message_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
