<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A grown-up saying something back.
 *
 * ## Nothing here notifies anybody
 *
 * No unread flag, no count, no push. The card is the one thing in this app that
 * asks nothing and pays nothing, and the instant a hard answer starts summoning
 * a parent, saying something has a consequence attached. A reply is written
 * because somebody looked, and read because the kid opened their own page — the
 * same way they would find a note left on their pillow.
 *
 * ## Parents only, and never in front of the siblings
 *
 * Written by a parent, read by the person it is about and by the other parent.
 * A sibling never sees one. Being answered in front of the whole house turns a
 * private moment into a scene, and a kid who has been publicly parented once on
 * this card will not write anything true on it again.
 *
 * One row per reply rather than one per entry, because Mom and Dad are separate
 * logins now and both may want to say something. `profile_id` is the author and
 * is always shown — this is the one place in the feature where a name matters,
 * since "somebody said this" is worth much less than "Dad said this".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feeling_replies', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('feeling_entry_id')->index();

            // The grown-up who wrote it. Named on the reply, always.
            $table->unsignedBigInteger('profile_id')->index();

            $table->text('body');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feeling_replies');
    }
};
