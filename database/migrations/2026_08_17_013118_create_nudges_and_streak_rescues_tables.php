<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two things a kid can do about somebody *else's* run.
 *
 * A nudge is free and capped at one per nudger per target per night, so it
 * stays a poke rather than a pile-on. A rescue costs the rescuer tickets and
 * keeps the target's run alive through the rollover.
 *
 * `streak_rescues` is deliberately its own table rather than a column on
 * `streak_repairs`, which it otherwise resembles. A repair is bought by the
 * kid whose run it saves and advances the milestone ladder like any other
 * night. A rescue is paid for by somebody else and must **not** advance it —
 * the run continues, the ladder does not — and folding two different payout
 * rules into one table is how one of them quietly starts obeying the other.
 *
 * Both carry the household day (`quest_date` / `rescued_date`) rather than
 * leaning on `created_at`: the household day rolls at `day_boundary_hour`, so
 * a nudge at 1am belongs to the night before and a timestamp can't say that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nudges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_profile_id')->index();
            $table->unsignedBigInteger('to_profile_id')->index();
            $table->date('quest_date');
            $table->timestamp('created_at')->nullable();

            // The nightly cap, enforced by the schema rather than only by the
            // service — two taps racing each other would otherwise both pass a
            // "have they nudged yet" check and both insert.
            $table->unique(['from_profile_id', 'to_profile_id', 'quest_date']);
        });

        Schema::create('streak_rescues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id')->index();
            $table->unsignedBigInteger('rescued_by_profile_id')->index();
            $table->date('rescued_date');
            $table->unsignedSmallInteger('tickets_paid');
            $table->timestamp('created_at')->nullable();

            // One rescue per kid per night, whoever pays for it. A second would
            // charge a second rescuer for a night already saved.
            $table->unique(['profile_id', 'rescued_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streak_rescues');
        Schema::dropIfExists('nudges');
    }
};
