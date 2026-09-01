<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One song's place in one playlist.
 *
 * `track_id` is MusicService's slug of the file's path, which is the same id
 * the kid header already writes to localStorage for the remembered song. It is
 * deliberately not a foreign key to anything, because there is nothing to point
 * at: the library is a folder, and the only record that a song exists is the
 * file itself.
 *
 * So a row here can outlive the song it names — a parent deletes an mp3, or
 * renames one and changes its slug. Both are handled where they happen, on the
 * parent music screen: a rename remaps every playlist entry, a delete removes
 * them. What survives that is a file moved in the bucket by hand, and for those
 * `title` is the safety net — a stale row can still be *drawn*, greyed out and
 * removable, rather than silently vanishing out of a kid's list.
 *
 * `position` rather than a linked list or a fractional index: a playlist is
 * twenty songs a kid nudges up and down, so a small integer resequenced on
 * every change is both the simplest thing and the easiest to read in the
 * database by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_tracks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('playlist_id')->index();
            // Long enough for the slug of an album folder plus a song title,
            // both of which MusicService caps at 80 characters.
            $table->string('track_id', 191);
            // The title as it read when the song was added. A label for a row
            // whose file has gone missing, never what gets played.
            $table->string('title', 191);
            $table->unsignedSmallInteger('position');
            // Added or taken away, never edited — position is rewritten in
            // place, which is not a change anybody needs the time of.
            $table->timestamp('created_at');

            // A song is in a playlist or it isn't. Enforced here as well as in
            // the service, since "add" is one tap and kids double-tap.
            $table->unique(['playlist_id', 'track_id']);
            // The only ordering anything ever asks for.
            $table->index(['playlist_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_tracks');
    }
};
