<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is in a `direct` room. Exactly two rows, always.
 *
 * ## Only direct rooms are listed here
 *
 * Everyone, Kids and a kid's line to the grown-ups have no rows in this table
 * at all: their membership is *derived* from role and from the room's
 * `for_profile_id` — see FeedRoom::members(). That is deliberate. Stored
 * membership for a room called "Everyone" is a copy of the household that can
 * go stale, and the day a fourth kid is added they would be silently missing
 * from the room whose entire name says otherwise.
 *
 * A direct room is the opposite: its two members *are* its identity, there is
 * nothing to derive them from, and they never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_room_members', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('room_id')->index();
            $table->unsignedBigInteger('profile_id')->index();

            $table->timestamps();

            // One row per person per room, which is what makes "find the direct
            // room holding these two" a count rather than a guess.
            $table->unique(['room_id', 'profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_room_members');
    }
};
