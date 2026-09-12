<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far each person has read in each room.
 *
 * ## A table rather than a marker column, and absent means "nothing read"
 *
 * A nullable `..._seen_at` column on profiles has bitten this app before: null
 * has to mean either "has seen everything" or "has seen nothing", and whichever
 * is chosen the other case is wrong on the day the feature ships. Here absence
 * means **nothing read**, which is the truthful reading — somebody who has
 * never opened a room has not read it — and it is safe to ship because the
 * rooms are empty on the day they are created. No first visit is greeted by a
 * pile of unread nobody was ever shown.
 *
 * `last_read_message_id` rather than a timestamp: the unread count is then a
 * plain `id >` comparison against the same column the room list orders by, with
 * no clock in it and no tie-breaking between two messages in the same second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_room_reads', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('room_id')->index();
            $table->unsignedBigInteger('profile_id')->index();

            $table->unsignedBigInteger('last_read_message_id')->default(0);

            $table->timestamps();

            $table->unique(['room_id', 'profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_room_reads');
    }
};
