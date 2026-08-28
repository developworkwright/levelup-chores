<?php

use App\Services\HouseholdClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The things the kids say — written down by a grown-up the moment they're said,
 * kept for good, read back by everyone.
 *
 * There is no winner column, and that absence is the design. Nothing here is
 * ever crowned: several quotes on one day are *contenders for Quote of the Day*
 * and stay contenders forever, because the label is a joke about the day being
 * a good one rather than a competition somebody has to settle. Adding a
 * `won_at` later would also mean deciding what happens to every day already in
 * the archive that nobody ever judged.
 *
 * `said_on` is a household day (4am boundary, {@see HouseholdClock})
 * and is separate from `created_at` on purpose: a parent remembering Tuesday's
 * line on Thursday should be able to file it under Tuesday, and the day is what
 * groups the contenders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            // Who said it. Nullable because the funniest thing said in a house
            // is not always said by someone with a login — a toddler, a
            // grandparent, a visiting friend — in which case `said_by` carries
            // the name instead. Exactly one of the two is set.
            $table->unsignedBigInteger('profile_id')->nullable()->index();
            $table->string('said_by')->nullable();
            $table->string('text', 300);
            // "Funny out of context" needs the context, and it has to be
            // optional: half of these land on their own and a required box
            // would stop a parent writing the line down in ten seconds.
            $table->string('context', 200)->nullable();
            $table->date('said_on');
            $table->unsignedBigInteger('added_by_profile_id')->nullable();
            $table->timestamps();

            // How the archive and the day's contenders are both read.
            $table->index(['household_id', 'said_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
