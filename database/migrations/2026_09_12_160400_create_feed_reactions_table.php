<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person's reaction to one message.
 *
 * Unique per (message, person, emoji): one of each per person, and tapping your
 * own takes it back. That is the whole rule, and it is what keeps a reaction
 * from becoming a scoreboard — nobody can stack fifty claps on their own
 * message, so the count under a message is a count of *people*.
 *
 * No updated_at, and no enum column pinning the set. The emoji is stored as it
 * was picked from a short list the page owns; widening that list later must not
 * require a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_reactions', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('message_id')->index();
            $table->unsignedBigInteger('profile_id')->index();

            $table->string('emoji', 16);

            $table->timestamp('created_at')->nullable();

            $table->unique(['message_id', 'profile_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_reactions');
    }
};
