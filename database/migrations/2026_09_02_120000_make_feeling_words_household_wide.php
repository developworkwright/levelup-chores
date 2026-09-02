<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Words become the household's rather than one person's.
 *
 * They were per-profile on the reasoning that choosing what you can say should
 * belong to you. In use that was wrong: the first word anybody added was
 * "Anxious", added by a parent, and no kid could reach it — which is the one
 * word this whole card exists for. A vocabulary that only its author can use
 * makes every other person round their feeling to the nearest built-in.
 *
 * So the list is shared, and **who added a word is never shown**. `profile_id`
 * stays as provenance for anyone reading the table directly, and nothing in the
 * app surfaces it: a word is the house's the moment it exists, and attributing
 * it would turn "somebody here needed this word" into a fact about one person.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Two people may each have added the same word while the list was
        // private, and the new index would refuse to build over that. The
        // oldest row wins and any entry pointing at a duplicate is repointed
        // at it, so no day loses the word it was written with.
        $keep = [];

        foreach (DB::table('feeling_words')->orderBy('id')->get() as $word) {
            $key = $word->household_id.'|'.mb_strtolower($word->label);

            if (isset($keep[$key])) {
                DB::table('feeling_entries')
                    ->where('feeling_word_id', $word->id)
                    ->update(['feeling_word_id' => $keep[$key]]);

                DB::table('feeling_words')->where('id', $word->id)->delete();

                continue;
            }

            $keep[$key] = $word->id;
        }

        Schema::table('feeling_words', function (Blueprint $table) {
            $table->dropUnique(['profile_id', 'label']);
            $table->unique(['household_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::table('feeling_words', function (Blueprint $table) {
            $table->dropUnique(['household_id', 'label']);
            $table->unique(['profile_id', 'label']);
        });
    }
};
