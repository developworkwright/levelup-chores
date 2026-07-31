<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the two perks that need to remember something.
 *
 * streak_milestone_paid_through is a high-water mark, and it closes a real
 * exploit: refreshStreak() used to gate payouts on the profile's *current*
 * streak, so letting a streak lapse and then repairing it would pay every
 * milestone again. Tracking the highest day ever paid makes that impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streak_repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->date('repaired_date');
            $table->timestamp('created_at');

            $table->unique(['profile_id', 'repaired_date']);
        });

        Schema::create('mystery_hint_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->date('hint_date');
            $table->timestamp('created_at');

            // Hints are per-kid: one sibling paying shouldn't hand the rest a
            // free clue, so the race stays fair.
            $table->unique(['profile_id', 'hint_date']);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedInteger('streak_milestone_paid_through')->default(0)->after('streak');
        });

        // Everyone has already been paid for the milestones their current
        // streak covers, so start the mark there rather than at zero.
        DB::table('profiles')->update([
            'streak_milestone_paid_through' => DB::raw('streak'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mystery_hint_purchases');
        Schema::dropIfExists('streak_repairs');

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('streak_milestone_paid_through');
        });
    }
};
