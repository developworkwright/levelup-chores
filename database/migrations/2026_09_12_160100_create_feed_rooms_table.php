<?php

use App\Enums\FeedRoomKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rooms of the family feed.
 *
 * The kids have this app and no phones, no accounts and nowhere to talk to each
 * other. These are the rooms they talk in.
 *
 * ## No name column, and no room creation
 *
 * A room is named by its kind and, where it has one, by the person it is for.
 * There is no "new room" button, no renaming and no deleting, because a family
 * does not need to organise itself into channels — it needs three or four
 * obvious places and nothing to administer. The group rooms are created with
 * the household; a `direct` room is created the first time one member messages
 * another and never before.
 *
 * ## Who can read what, and why it is drawn rather than hidden
 *
 * | Kind      | Who reads it                                   |
 * |-----------|------------------------------------------------|
 * | everyone  | the whole household                            |
 * | kids      | the kids — and the grown-ups too               |
 * | parents   | one kid and the grown-ups (`for_profile_id`)   |
 * | direct    | exactly the two members, grown-ups included    |
 *
 * The Kids room being open to parents is a decision, not an oversight: it is a
 * quieter room, not a secret one. The asymmetry is *drawn* — every room header
 * carries a who-can-read line, always, so a kid learns the audience before they
 * post rather than discovering it afterwards. If this policy is ever changed,
 * {@see FeedRoomKind::audienceLine()} changes in the same commit.
 *
 * A `direct` room between two kids is **not** readable by a grown-up. That was
 * asked and answered directly: full trust. It is the one rule here that cannot
 * be quietly changed later without breaking a promise the header already made.
 *
 * ## `parents` is a kid's line to the grown-ups, not the grown-ups' own room
 *
 * There is one per kid, and it holds that kid plus Mom and Dad. The first sketch
 * had a grown-ups-only room instead, drawn on a kid's screen with a padlock and
 * "You can't read this one"; it was rejected, because Mom and Dad already have
 * phones and the only thing that room did was show a kid a door they can't open.
 *
 * ## `last_message_at`
 *
 * Denormalised so the room list can be ordered and previewed without a
 * correlated subquery per room on the page the kids open most. Written by
 * FeedService::post(); nothing else touches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_rooms', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('household_id')->index();

            $table->string('kind', 16);

            // The kid a `parents` room belongs to. Null for every other kind.
            $table->unsignedBigInteger('for_profile_id')->nullable()->index();

            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            // How the room list is read: every room in one house, newest first.
            $table->index(['household_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_rooms');
    }
};
