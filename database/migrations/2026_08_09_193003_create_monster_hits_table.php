<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every blow landed on a monster, and the single source of its health.
 *
 * A hit is its own row rather than a column on `chore_completions` because one
 * chore can produce two of them: a weak-point strike doubles, and any excess
 * past a monster's last hit point spills onto the tier above. One completion,
 * two monsters, two damage figures.
 *
 * It is also what makes a parent's corrections honest. Re-aiming a mis-tapped
 * chore deletes that completion's rows and re-applies them to the monster the
 * kid meant, so the kid keeps credit for their own work; a hand nudge writes an
 * `Adjust` row with nobody attached, so it moves the bar without quietly
 * inflating anyone's share of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monster_hits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('monster_id')->index();

            // The approved chore behind this hit. Null for a parent's manual
            // adjustment, which is exactly the row a re-aim must not touch.
            $table->unsignedBigInteger('chore_completion_id')->nullable()->index();

            // Who swung. Null on adjustments, so a hand-moved number never
            // lands on a kid's contribution.
            $table->unsignedBigInteger('profile_id')->nullable()->index();

            // Signed: a parent nudging a tier back down writes a negative row
            // rather than deleting history that actually happened.
            $table->integer('damage');

            // hit | spill | adjust — a spill being the overflow rolled up from
            // the tier below, which the arena calls out as its own event.
            $table->string('kind');

            $table->timestamps();

            // Summing a monster's health, and the hit feed's ordering.
            $table->index(['monster_id', 'created_at']);

            // The per-monster leaderboard.
            $table->index(['monster_id', 'profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monster_hits');
    }
};
