<?php

use App\Services\HouseholdClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What's for dinner.
 *
 * The kids ask this every afternoon and the answer lives in a grown-up's head,
 * which means it gets asked five times and answered five times. One row a night
 * puts it on the family feed's "Today in the house" card, where they are already
 * looking.
 *
 * ## One dinner a night, and the unique index says so
 *
 * `(household_id, served_on)` is unique, which makes setting tonight's dinner an
 * updateOrCreate() rather than a find-then-branch, and makes two rows for one
 * evening impossible rather than merely unlikely. A house that wants to record
 * "tacos, and pancakes if the tacos fail" writes that in `note`; it is one
 * dinner with a caveat, not two dinners.
 *
 * `served_on` is a household day (4am boundary, {@see HouseholdClock}) and is
 * separate from `created_at` for the same reason `quotes.said_on` is: the whole
 * point of the parent screen is filling in a week ahead, so the day a row is
 * *about* is never the day it was typed.
 *
 * ## Why `name` is a string and not a foreign key
 *
 * The plan is for this to grow into real meal planning — ingredients on each
 * meal, and a shopping list assembled from a week of them. That arrives as
 * `meal_items` (meal_id, name, quantity, aisle, checked_at), with a reusable
 * `recipes` + `recipe_items` pair behind a nullable `meals.recipe_id` when a
 * meal is one the house makes often.
 *
 * None of that is built yet, and `name` stays a plain string so that none of it
 * has to be. Tonight's "tacos" must not require a recipe row to exist first —
 * the thirty seconds it takes to answer the question is the entire value of this
 * table, and anything that makes a grown-up fill in a form instead loses it.
 *
 * ## Nothing here pays anything
 *
 * Like the feed it is drawn on: no points, no XP, no streak. Dinner is
 * information the kids wanted, not a chore anybody completes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meals', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('household_id')->index();

            // The household day this is dinner for.
            $table->date('served_on');

            $table->string('name', 120);

            // "leftovers night", "eat at 5, game at 6". Optional, and it has to
            // be: a required second box is how a grown-up decides not to bother.
            $table->string('note', 200)->nullable();

            $table->unsignedBigInteger('set_by_profile_id')->nullable();

            $table->timestamps();

            // One dinner a night, per house.
            $table->unique(['household_id', 'served_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meals');
    }
};
