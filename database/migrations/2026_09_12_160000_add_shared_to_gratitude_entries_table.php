<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a gratitude list is shown to the rest of the house.
 *
 * ## Why this defaults to true, when FeelingVisibility defaults to private
 *
 * The two look like the same column and they are the opposite one. A feeling is
 * about you; a gratitude line is nearly always *about somebody else in this
 * house* — "Raylan helped me", "the dog slept on my bed", "nobody fought at
 * breakfast" — and its entire value is that the person in it gets to hear it. A
 * grateful list nobody reads is a form.
 *
 * So this is shared by default with a per-entry opt-out on the quest card, and
 * the opt-out is per entry rather than a setting for the same reason the
 * FeelingVisibility docblock gives: a kid who has to go and change a setting in
 * order to be private will never be private. The box is in front of them at the
 * moment they are deciding, or it may as well not exist.
 *
 * Backfilled true, which is what the column default does for existing rows too.
 * Everything already written was written before anybody was asked, and the
 * honest reading of that is not "they chose to share" — but these lists have
 * only ever been visible to the writer and to the grown-ups, who could already
 * read them all through GratitudeService::journalForHousehold(). Opening them
 * to the siblings is the change, and the kids are told on the card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gratitude_entries', function (Blueprint $table) {
            $table->boolean('shared')->default(true)->after('items');
        });
    }

    public function down(): void
    {
        Schema::table('gratitude_entries', function (Blueprint $table) {
            $table->dropColumn('shared');
        });
    }
};
