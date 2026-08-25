<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The arcade leaderboard behind the game on the public login page.
 *
 * Deliberately the thinnest table in the schema: a name, a number, and which
 * week it happened in. No `profile_id`, no IP address, no user agent, no
 * session id. `/` is world-readable and this is the only thing on it that
 * writes to the database, so a row here has to be worthless to a stranger who
 * finds the URL — it says somebody stacked 23 floors, and nothing about who
 * lives here. The codename is picked from a fixed vocabulary in ArcadeService
 * rather than typed, so the column can never hold free text either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arcade_scores', function (Blueprint $table) {
            $table->id();
            $table->string('codename', 40);
            $table->unsignedSmallInteger('score');
            // ISO year-week, e.g. "2026-W35". Stored rather than derived from
            // created_at so the weekly board is one index lookup and never
            // depends on the reader's timezone agreeing with the writer's.
            $table->string('week', 8);
            $table->timestamp('created_at');

            $table->index(['week', 'score']);
            $table->index('score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arcade_scores');
    }
};
