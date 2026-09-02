<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A grown-up's answer to somebody's day. See the create migration for why it
 * notifies nobody and why a sibling never sees one.
 */
class FeelingReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'feeling_entry_id',
        'profile_id',
        'body',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FeelingEntry::class, 'feeling_entry_id');
    }

    /** The grown-up who wrote it. Always shown — see the migration. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id');
    }
}
