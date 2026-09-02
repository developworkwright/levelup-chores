<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Words a person added to their own feelings card.
 *
 * ## Why these belong to a person and not to the house
 *
 * The twelve built-in words are a starting vocabulary, not the whole language.
 * A kid who has a word that fits — wobbly, homesick, buzzing, meh — and can't
 * find it on the card is being asked to round their feeling to the nearest
 * option somebody else picked for them, which is a small version of the exact
 * thing this feature exists to stop.
 *
 * So a word belongs to the profile that added it and appears only on their own
 * card. Household-wide would make it a shared vocabulary, which sounds nicer
 * and isn't: one kid's private word turns up on their sibling's grid, the list
 * grows until picking from it is work, and a word somebody added for a reason
 * becomes everyone's business. The *answer* is public to the house — that never
 * changed — but choosing what you can say is yours.
 *
 * ## Retired rather than deleted
 *
 * `active` follows `lucky_prizes`. A word taken off the card has usually
 * already been used on entries that must keep rendering, and hard-deleting it
 * would rewrite days a person actually had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feeling_words', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id')->index();

            // Room for a word, not for a sentence. The "because" box is where
            // an explanation goes; this is the thing being explained.
            $table->string('label', 24);

            // Optional. A word with no glyph draws a neutral mark rather than
            // demanding a decision — picking an emoji is the fun part for some
            // kids and friction for others, and it must not gate adding a word.
            $table->string('glyph', 8)->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            // One of each word per person. Case is folded before this is
            // checked, so "Wobbly" doesn't sit next to "wobbly".
            $table->unique(['profile_id', 'label']);
        });

        Schema::table('feeling_entries', function (Blueprint $table) {
            // Exactly one of these two is set — the same either/or shape
            // `sleep_nights` uses for outcome/minutes and `quotes` uses for
            // profile_id/said_by. Keeping the built-ins as an enum is what
            // preserves their glyphs, colors and stems in code rather than
            // seeding twelve rows into every household.
            $table->unsignedBigInteger('feeling_word_id')->nullable()->index()->after('feeling');
        });

        Schema::table('feeling_entries', function (Blueprint $table) {
            $table->string('feeling', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('feeling_entries', function (Blueprint $table) {
            $table->dropColumn('feeling_word_id');
        });

        Schema::dropIfExists('feeling_words');
    }
};
