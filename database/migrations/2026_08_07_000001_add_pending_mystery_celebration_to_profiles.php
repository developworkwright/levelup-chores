<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mystery-chore win a kid hasn't been shown yet.
 *
 * The bonus is settled by a parent tapping approve, which is a screen no kid is
 * looking at — the same problem pending_goal_celebration solves, and this
 * follows it exactly: the name of the chore rather than a bare flag, so a
 * rerolled or renamed chore can't be announced as the one they found, and a
 * column rather than the session, so signing out doesn't lose the moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('pending_mystery_celebration')->nullable()->after('pending_goal_celebration');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('pending_mystery_celebration');
        });
    }
};
