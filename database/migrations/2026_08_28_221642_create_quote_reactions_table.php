<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who laughed at what.
 *
 * A reaction is not a vote and must never become one: quotes are never ranked
 * (see the quotes migration), and a kid tapping 😂 is saying "I saw this and it
 * got me" rather than nominating anything. The absence of a score column is
 * what keeps the two apart — nothing here is ever summed across quotes into a
 * standing, and if a count ever starts reading as a leaderboard the fix is in
 * the view, not another column.
 *
 * The unique key is the toggle: one row per kid per reaction per quote, so
 * tapping the same face twice removes it rather than stacking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quote_id')->index();
            $table->unsignedBigInteger('profile_id')->index();
            // An App\Enums\ReactionKind value. A short string rather than an
            // enum column so adding a fifth face is a deploy, not a migration.
            $table->string('reaction', 16);
            // Never updated — a reaction is added or taken away, never edited.
            $table->timestamp('created_at');

            // The toggle, enforced where it can't be raced.
            $table->unique(['quote_id', 'profile_id', 'reaction']);
            // How the shell asks "did anyone react to my quotes since I last
            // looked" — the one query that isn't scoped to a single quote.
            $table->index(['profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_reactions');
    }
};
