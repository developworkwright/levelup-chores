<?php

namespace App\Models;

use App\Enums\AccentColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Badge extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'xp_reward',
        'glyph',
        'color',
        'hidden',
    ];

    protected function casts(): array
    {
        return [
            'color' => AccentColor::class,
            'hidden' => 'boolean',
        ];
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'profile_badges')->withPivot('earned_at');
    }
}
