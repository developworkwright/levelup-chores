<?php

namespace App\Models;

use App\Enums\RedemptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Redemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_item_id',
        'profile_id',
        'cost_snapshot',
        'status',
        'requested_at',
        'fulfilled_at',
        'fulfilled_by_profile_id',
        'rejected_at',
        'rejected_by_profile_id',
        'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => RedemptionStatus::class,
            'requested_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'rejected_by_profile_id');
    }

    public function storeItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'fulfilled_by_profile_id');
    }
}
