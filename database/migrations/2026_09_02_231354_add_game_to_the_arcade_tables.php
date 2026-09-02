<?php

use App\Enums\ArcadeGame;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The arcade gets a second cabinet, so a score has to say which one it is.
 *
 * Without this column both games share one leaderboard, which is nonsense on
 * its face — a tower is floors and a walk is lanes — and one weekly prize,
 * which is worse: whoever is already best at the first game wins the second
 * one too, and the second cabinet is pointless for everybody else. So the game
 * lands on both tables, and every query in `ArcadeService` is scoped to it.
 *
 * The default is the backfill. Every row that predates this column is a Stack
 * the Mess score by definition — it was the only game — so a column default
 * writes the right value into the existing rows without a separate pass over
 * them. It is left on the column afterwards for the same reason it is safe
 * now: `arcade_scores.game` is fillable and `ArcadeService::post()` always sets
 * it, so nothing reaches this table without saying what it played.
 *
 * The prize table's unique key is the interesting one. It was
 * (household, week) — the thing that makes a lazy payout happen exactly once —
 * and a second game means a week now has two winners to settle rather than
 * one. Widened to (household, week, game), which keeps the guarantee and moves
 * it to the right granularity; leaving it alone would have let the first game
 * settled on a Monday close the week for both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arcade_scores', function (Blueprint $table) {
            $table->string('game', 20)->default(ArcadeGame::StackTheMess->value)->after('profile_id');
        });

        Schema::table('arcade_scores', function (Blueprint $table) {
            // The weekly board is "this household, this game, this week" now,
            // so the index that served it is the wrong shape by one column.
            $table->index(['household_id', 'game', 'week', 'score']);
            // And the all-time record line, which asks the same question
            // without the week.
            $table->index(['household_id', 'game', 'score']);
        });

        Schema::table('arcade_week_prizes', function (Blueprint $table) {
            $table->string('game', 20)->default(ArcadeGame::StackTheMess->value)->after('week');
        });

        Schema::table('arcade_week_prizes', function (Blueprint $table) {
            $table->dropUnique(['household_id', 'week']);
            $table->unique(['household_id', 'week', 'game']);
        });
    }

    public function down(): void
    {
        Schema::table('arcade_week_prizes', function (Blueprint $table) {
            $table->dropUnique(['household_id', 'week', 'game']);
            $table->unique(['household_id', 'week']);
        });

        Schema::table('arcade_week_prizes', function (Blueprint $table) {
            $table->dropColumn('game');
        });

        Schema::table('arcade_scores', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'game', 'week', 'score']);
            $table->dropIndex(['household_id', 'game', 'score']);
            $table->dropColumn('game');
        });
    }
};
