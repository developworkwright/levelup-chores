<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyMystery extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'mystery_date',
        'chore_id',
    ];

    protected function casts(): array
    {
        return [
            'mystery_date' => 'date',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function chore(): BelongsTo
    {
        return $this->belongsTo(Chore::class);
    }
}
