<?php

use App\Enums\CosmeticSlot;
use App\Enums\SiblingOfferStatus;
use App\Models\SiblingOffer;
use App\Services\CosmeticArt;
use App\Services\SiblingOfferService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every pet has all three ages now — baby, young and grown up — so the pets
 * uploaded before that, with only the one sheet, are taken out of the game.
 *
 * Out entirely, not just off the shelf: whoever owns one loses it, and anyone
 * who has it out goes back to having no pet. Tickets are NOT refunded here —
 * there was one purchase, and a grown-up refunds it by hand.
 *
 * Everything that points at one goes with it: ownership, the worn column, the
 * week it dropped, and any trade still open with it on either side. A trade
 * is cancelled through SiblingOfferService, so whatever the other side put up
 * is handed back the way any cancelled offer's is. The art files are deleted
 * last and best-effort: a file left behind costs a few hundred kilobytes, and
 * a missing one must not stop the deploy.
 *
 * One-way. There is nothing to put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pets = DB::table('cosmetics')
            ->where('slot', CosmeticSlot::Pet->value)
            ->where(fn ($query) => $query->whereNull('baby_art_path')->orWhereNull('young_art_path'))
            ->get(['id', 'art_path', 'baby_art_path', 'young_art_path']);

        if ($pets->isEmpty()) {
            return;
        }

        $ids = $pets->pluck('id')->all();

        SiblingOffer::where('status', SiblingOfferStatus::Pending)
            ->where(fn ($query) => $query->whereIn('give_cosmetic_id', $ids)->orWhereIn('get_cosmetic_id', $ids))
            ->get()
            ->each(fn (SiblingOffer $offer) => app(SiblingOfferService::class)->cancelForRemovedItem($offer));

        DB::transaction(function () use ($ids) {
            DB::table('profiles')->whereIn(CosmeticSlot::Pet->wornColumn(), $ids)->update([CosmeticSlot::Pet->wornColumn() => null]);
            DB::table('owned_cosmetics')->whereIn('cosmetic_id', $ids)->delete();
            DB::table('cosmetic_drops')->whereIn('cosmetic_id', $ids)->delete();
            DB::table('cosmetics')->whereIn('id', $ids)->delete();
        });

        $disk = app(CosmeticArt::class)->disk();

        foreach ($pets as $pet) {
            foreach (array_filter([$pet->art_path, $pet->baby_art_path, $pet->young_art_path]) as $path) {
                rescue(fn () => $disk->delete($path), report: false);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
