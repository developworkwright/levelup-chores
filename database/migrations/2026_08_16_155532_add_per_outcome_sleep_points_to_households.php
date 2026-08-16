<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One rate per answer, replacing the single "a night pays this" figure.
 *
 * A perfect night and a cuddle at 3am are not the same thing and shouldn't pay
 * the same, but they also shouldn't be all-or-nothing — a kid who got most of
 * the way there has done something. So each outcome carries its own rate, and
 * each is tapered from the console independently.
 *
 * `sleep_night_points` is dropped rather than left lying about: it meant "the
 * own-bed rate" and would read as "the rate for a night" beside the three new
 * columns, which is exactly the sort of name that gets used wrongly later. Its
 * value carries over, so a household mid-taper keeps what it had set.
 *
 * Columns and dollar figures are written out rather than read from
 * `SleepOutcome` — a migration records what happened on the day it ran, and an
 * enum that later gains, loses or renames a case must not change it in
 * retrospect or break a rebuild of the schema.
 */
return new class extends Migration
{
    /** outcome column => starting dollars. Mirrors SleepOutcome as of today. */
    private const RATES = [
        'sleep_points_own_bed' => 2,
        'sleep_points_visited' => 1,
        'sleep_points_rough' => 0,
    ];

    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            foreach (array_keys(self::RATES) as $column) {
                $table->unsignedInteger($column)->nullable()->after('sleep_constellation_points');
            }
        });

        foreach (self::RATES as $column => $dollars) {
            // The own-bed rate inherits whatever the old single figure was, so
            // a deliberate taper survives. The other two start from scratch.
            $seed = $column === 'sleep_points_own_bed'
                ? "COALESCE(sleep_night_points, {$dollars} * points_per_dollar)"
                : "{$dollars} * points_per_dollar";

            DB::table('households')->update([$column => DB::raw($seed)]);
        }

        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('sleep_night_points');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->unsignedInteger('sleep_night_points')->nullable()->after('sleep_constellation_points');
        });

        DB::table('households')->update([
            'sleep_night_points' => DB::raw('sleep_points_own_bed'),
        ]);

        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::RATES));
        });
    }
};
