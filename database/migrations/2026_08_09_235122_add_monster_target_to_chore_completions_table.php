<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the kid was aiming at, recorded when they tapped "done".
 *
 * Both columns are decided at submit and honoured at approval, which is the
 * point of storing them at all. A parent signs work off hours later, by which
 * time the tier may have been beaten and this week's weak chore may have been
 * swapped — and neither should quietly change what a kid was promised when
 * they chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chore_completions', function (Blueprint $table) {
            // Which monster this hit is meant for. A tier rather than a monster
            // id: a sibling can finish the one standing there before this gets
            // approved, and the kid's answer to "which of the three" survives
            // that where a row id would not.
            //
            // Nullable for completions submitted before the arena existed, and
            // for a household with nothing standing to aim at.
            $table->unsignedTinyInteger('target_tier')->nullable()->after('points_awarded');

            // Whether this chore was that monster's weak point at the moment it
            // was claimed. Stored rather than recomputed at approval: swapping
            // a weak chore mid-week must not halve a hit somebody has already
            // aimed on the strength of it.
            $table->boolean('struck_weak_point')->default(false)->after('target_tier');
        });
    }

    public function down(): void
    {
        Schema::table('chore_completions', function (Blueprint $table) {
            $table->dropColumn(['target_tier', 'struck_weak_point']);
        });
    }
};
