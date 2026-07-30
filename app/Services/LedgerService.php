<?php

namespace App\Services;

use App\Enums\LedgerKind;
use App\Models\Household;
use App\Models\LedgerEntry;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The ledger is the source of truth for balances — every point change goes
 * through here so cached profile.points can never drift from it.
 */
class LedgerService
{
    public function record(
        Household $household,
        ?Profile $profile,
        LedgerKind $kind,
        int $amount,
        string $description,
        ?Model $related = null,
    ): LedgerEntry {
        return DB::transaction(function () use ($household, $profile, $kind, $amount, $description, $related) {
            $entry = LedgerEntry::create([
                'household_id' => $household->id,
                'profile_id' => $profile?->id,
                'kind' => $kind,
                'amount' => $amount,
                'description' => $description,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
            ]);

            if ($profile) {
                $profile->points = max(0, $profile->points + $amount);
                $profile->save();
            }

            return $entry;
        });
    }
}
