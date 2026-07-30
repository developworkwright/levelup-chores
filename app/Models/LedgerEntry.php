<?php

namespace App\Models;

use App\Enums\LedgerKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory;

    /**
     * Append-only: entries are never updated after creation.
     */
    public $timestamps = false;

    protected $fillable = [
        'household_id',
        'profile_id',
        'kind',
        'amount',
        'description',
        'related_type',
        'related_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => LedgerKind::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LedgerEntry $entry) {
            $entry->created_at ??= now();
        });
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
