<?php

use App\Enums\ArcadeGame;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When this kid last looked at the arcade, so a new cabinet can announce itself.
 *
 * The arcade's "new" rim used to be a hardcoded flag in the kid shell, with a
 * comment saying a column and a migration were not worth it because the arcade
 * would only ever be news once. That stopped being true the moment there were
 * two games and a plan for more: a flag is the same for everybody and stays lit
 * until somebody remembers to delete the line, which is exactly the announcement
 * nobody trusts by the third time.
 *
 * The same shape as `loot_seen_at` next to it — see
 * `add_browsing_to_store_items_table` — and read the same way: a cabinet is new
 * to a kid while its release date is later than this marker.
 *
 * **The backfill is the load-bearing half.** Left nullable and empty, every
 * existing profile would have its marker stamped silently on the next page
 * load, and the cabinet this column was added *for* would never be announced to
 * anybody — the failure `quotes_seen_at` shipped with and had to be fixed after
 * the fact. Backfilled to the first cabinet's release date rather than to now,
 * which draws the line in the one place that makes the old game old and the new
 * one new.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded because the first cut of this file threw between the column
        // being added and the row being recorded, which leaves a database
        // holding the column and a migrations table that has never heard of it
        // — a state no rollback can reach, because there is nothing recorded to
        // roll back. The backfill below is idempotent for the same reason: it
        // only touches profiles that have no marker yet.
        if (! Schema::hasColumn('profiles', 'arcade_seen_at')) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->timestamp('arcade_seen_at')->nullable()->after('loot_seen_at');
            });
        }

        DB::table('profiles')->whereNull('arcade_seen_at')->update([
            'arcade_seen_at' => ArcadeGame::StackTheMess->releasedOn(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('profiles', 'arcade_seen_at')) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->dropColumn('arcade_seen_at');
            });
        }
    }
};
