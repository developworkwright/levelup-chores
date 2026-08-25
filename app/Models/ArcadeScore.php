<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One posted run of the login-page game. See the migration for why this model
 * has no relationships: a score is not attached to a person on purpose.
 */
class ArcadeScore extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'codename',
        'score',
        'week',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }
}
