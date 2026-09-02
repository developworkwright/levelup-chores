<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How everyone in the house felt today.
 *
 * ## Why this table pays nothing
 *
 * There is no points column, no ticket column and no run counter, and that is
 * the design rather than an omission. The moment answering pays, it becomes a
 * task to be discharged — and worse, a kid optimising the payout taps whatever
 * is quickest rather than whatever is true. Everything else a kid does in this
 * app is worth something; this is the one thing that is worth nothing, which is
 * exactly what makes it safe to be honest on.
 *
 * For the same reason there is no streak, no high-water mark and nothing that
 * can be missed. A run of days would turn a hard morning into a second thing to
 * fail at.
 *
 * ## Everyone, not just the kids
 *
 * Parents answer too, and that is load-bearing. Asking one child how he feels
 * makes him the subject of an examination and he will say "fine" — which is
 * what a kid does when he is the only one being asked. A whole house answering,
 * where he can see a parent posted *flat* before he picks, removes the
 * asymmetry that makes the mask necessary.
 *
 * ## Two halves, two rules
 *
 * `feeling` is public to the household, always. `because` carries its own
 * visibility, chosen per entry and defaulting to private. See the
 * FeelingVisibility enum.
 *
 * One row per person per household day, updated rather than appended: feelings
 * move during a day and being able to change your answer says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feeling_entries', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id')->index();

            // The household day (4am boundary, per the HouseholdClock service),
            // so answering at breakfast and answering at bedtime file under the
            // same day rather than either side of midnight.
            $table->date('felt_on');

            $table->string('feeling', 32);

            // The other half of "Today I felt sad because…". Optional on
            // purpose: a kid who wants to name the feeling and stop there has
            // still done the whole thing, and a required box would make the
            // quick honest answer the expensive one.
            $table->text('because')->nullable();

            // Applies to `because` alone. Never to the feeling.
            $table->string('visibility', 16)->default('private');

            $table->timestamps();

            // One answer per person per day, which is what makes the card
            // idempotent and lets a second answer edit the first.
            $table->unique(['profile_id', 'felt_on']);

            // How the house strip is read: everyone's row for one day.
            $table->index(['household_id', 'felt_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feeling_entries');
    }
};
