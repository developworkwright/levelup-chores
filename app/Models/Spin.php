<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Spin extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'spin_date',
        'chore_id',
        'multiplier',
    ];

    protected function casts(): array
    {
        return [
            'spin_date' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function chore(): BelongsTo
    {
        return $this->belongsTo(Chore::class);
    }
}
