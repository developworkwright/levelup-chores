<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Takes the lid off the arcade boards.
 *
 * The per-game score ceiling is gone — see ArcadeGame, where it used to live.
 * It was an estimate, the estimate was wrong, and being wrong cost a kid a
 * 7659m run: `post()` refused it, returned null, and nothing anywhere said a
 * word. He went to fetch his brother and the board had never heard of it.
 *
 * Removing the check on its own would not have fixed that — it would have moved
 * it one layer down and made it worse. This column was an unsigned smallint,
 * which stops at 65535: past that, MySQL either throws on insert or silently
 * truncates depending on strict mode, and a truncated score is a *wrong* number
 * on the board rather than a missing one.
 *
 * So the column goes to an unsigned int (4.29 billion), which is not a limit any
 * of these games can reach in a childhood. The indexes on `score` are rebuilt by
 * the change and need no separate handling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arcade_scores', function (Blueprint $table) {
            $table->unsignedInteger('score')->change();
        });
    }

    /**
     * Deliberately narrowing back would destroy data: any score above 65535
     * cannot survive the trip. Rolling this back is therefore only safe on a
     * database that has never held one, which is the state it was in before
     * `up()` ran.
     */
    public function down(): void
    {
        Schema::table('arcade_scores', function (Blueprint $table) {
            $table->unsignedSmallInteger('score')->change();
        });
    }
};
