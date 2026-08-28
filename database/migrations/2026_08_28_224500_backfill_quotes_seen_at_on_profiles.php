<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every profile that predates the quote feature a starting marker.
 *
 * `quotes_seen_at` shipped nullable, and null means *seed me without
 * celebrating* — the guard that stops a kid joining a household with two years
 * of quotes and meeting a queue of every one of them. Correct for a new
 * profile, and wrong for every profile that already existed when the column
 * landed: their first kid-page load after the deploy burned the marker
 * silently, so a quote written in the window between the deploy and that first
 * load was never announced to them.
 *
 * That is exactly what happened on the first live quote — two of three kids had
 * not opened a page since the migration, so only the third was told.
 *
 * Backfilling to now() draws the line here: nothing already written is news,
 * everything from this point is. The null guard stays for genuinely new
 * profiles, which is the case it was actually for.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('profiles')->whereNull('quotes_seen_at')->update(['quotes_seen_at' => now()]);
    }

    /**
     * Deliberately empty. Rolling this back would mean putting nulls into rows
     * that may since have been legitimately stamped by the shell, and there is
     * nothing to restore them from.
     */
    public function down(): void {}
};
