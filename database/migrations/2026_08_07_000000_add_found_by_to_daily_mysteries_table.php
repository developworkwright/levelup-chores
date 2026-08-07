<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who won the day's mystery bonus, and when.
 *
 * The race used to be settled by ChoreService::claimantFor() — which counts a
 * pending claim — so the bonus was decided the instant a kid tapped "Mark it
 * done". A kid could submit every chore on the board and read straight off
 * their own screen which one carried the bonus, with no work verified by
 * anyone. The winner is now recorded here, at parent approval.
 *
 * Nullable with no backfill: an unfinished race and a race nobody was recorded
 * for look the same, which is the right reading for days that closed under the
 * old rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_mysteries', function (Blueprint $table) {
            // nullOnDelete, not cascade — deleting a profile must not take the
            // day's mystery row with it and re-open a settled race.
            $table->foreignId('found_by_profile_id')->nullable()->after('chore_id')
                ->constrained('profiles')->nullOnDelete();

            $table->timestamp('found_at')->nullable()->after('found_by_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('daily_mysteries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('found_by_profile_id');
            $table->dropColumn('found_at');
        });
    }
};
