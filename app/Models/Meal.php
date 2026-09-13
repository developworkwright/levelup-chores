<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night's dinner.
 *
 * See the create migration for why there is one row a night, why `served_on` is
 * a household day rather than a timestamp, and why `name` is a plain string
 * rather than a pointer at a recipe.
 */
class Meal extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'served_on',
        'name',
        'note',
        'set_by_profile_id',
    ];

    protected function casts(): array
    {
        return [
            'served_on' => 'date',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** The grown-up who wrote it down. Null on a row set before this was kept. */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'set_by_profile_id');
    }
}
