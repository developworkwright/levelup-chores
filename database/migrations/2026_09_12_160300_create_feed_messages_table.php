<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything said in a room.
 *
 * ## Nothing here pays anything
 *
 * No points, no tickets, no streak, no count that feeds a badge. The house was
 * asked and chose full trust, and the same reasoning that keeps the feelings
 * card free applies twice over here: the moment talking to your brother pays,
 * the kid who wants the payout posts "hi" forty times and the room is worthless
 * to everybody including him.
 *
 * For the same reason there is no moderation queue, no approval step and no
 * report button. What there is instead is a who-can-read line on every room
 * header, which is the control that actually prevents the thing worth
 * preventing: somebody saying a thing to an audience they misjudged.
 *
 * ## Five kinds, one table
 *
 * `text` is a message. `stamp` is a single picture with no bubble, which is how
 * a six-year-old who cannot type yet says something. `drawing` is a flattened
 * PNG off the finger canvas. `shoutout` is a message *about* somebody
 * (`subject_id`), drawn behind a coral rule. `event` is the app talking — a
 * badge, a new personal best, a streak milestone, a monster killed.
 *
 * Events are drawn as one mono line with no avatar and no reaction affordance:
 * deliberately the lightest thing on the screen, so that a real message from a
 * real person always outweighs the app congratulating somebody. A feed where
 * the machine is the loudest voice is a notification tray.
 *
 * ## Feelings and gratitude are *not* messages
 *
 * They never become rows in this table. They stay in `feeling_entries` and
 * `gratitude_entries` and are rendered by the landing screen's own cards,
 * through FeelingEntry::becauseVisibleTo() and `gratitude_entries.shared`. Two
 * reasons: that privacy logic already exists and must not be reimplemented
 * beside it, and a feeling must not be reply-able, reaction-able or
 * scrollable-past. "I felt awful today" is not a post.
 *
 * ## No updated_at
 *
 * A message is not edited. Saying something and then changing what you said is
 * the one thing a room between siblings cannot support, because the argument
 * that follows it is unresolvable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_messages', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('room_id')->index();

            // The author. Set even on an `event`, because an event is always
            // about somebody — it is only the avatar that is dropped.
            $table->unsignedBigInteger('profile_id')->index();

            $table->string('kind', 16);

            // text and shoutout, and the sentence an event reads out.
            $table->text('body')->nullable();

            // The stamp key — see the FeedStamp enum. The picture itself lives
            // in the enum rather than here, so a stamp can be redrawn without
            // rewriting every message that used it.
            $table->string('stamp', 32)->nullable();

            // Path on the drawings disk. Not a URL: the disk moves between a
            // local folder and a bucket depending on the host.
            $table->string('drawing_path')->nullable();

            // Who a shout-out is about.
            $table->unsignedBigInteger('subject_id')->nullable()->index();

            // What an event came off — the score, the badge, the monster. Kept
            // so the once-a-day throttle has something to count, and so an
            // event could one day link back to the thing it is about.
            $table->nullableMorphs('source');

            // No updated_at — see above.
            $table->timestamp('created_at')->nullable();

            // How a room is read: one room's messages, oldest first.
            $table->index(['room_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_messages');
    }
};
