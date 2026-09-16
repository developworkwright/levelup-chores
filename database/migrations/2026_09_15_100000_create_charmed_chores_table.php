<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a cast Quest Charm lands, now that there is no quest chest to cast it
 * at: a handful of ordinary board chores that pay this kid more for the rest of
 * the household day.
 *
 * The unique index is the no-stacking rule in the schema rather than only in
 * the service — two charms on one chore on one day would pay the bonus twice
 * for the same work if anything ever read this by counting rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charmed_chores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id')->index();
            $table->unsignedBigInteger('chore_id')->index();
            $table->date('charm_date');
            $table->timestamps();

            // The board's read is (profile, date) and the claim's is
            // (profile, chore, date) — this covers both, and enforces one charm
            // per chore per kid per day.
            $table->unique(['profile_id', 'chore_id', 'charm_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charmed_chores');
    }
};
