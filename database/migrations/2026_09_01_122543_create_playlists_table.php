<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A kid's own running order through the music library.
 *
 * The library itself is deliberately not a table — it is a folder of mp3s on a
 * disk, and a song's identity is its filename (see MusicService). A playlist is
 * the first thing about the music that is genuinely *somebody's*, which is why
 * it is here and the library is not: it belongs to a kid rather than to the
 * house, and it has to follow them to whatever phone or tablet they pick up.
 *
 * That last part is the whole reason this is a table and not another key in
 * localStorage next to the remembered song and volume. Those are settings for
 * a browser; a playlist is something a kid made, and losing one to a cleared
 * cache or a borrowed tablet would read as the app throwing their work away.
 *
 * Belongs to a profile, not a household. Two siblings listening to the same
 * hundred-track soundtrack want different halves of it, and a shared list would
 * mean one of them editing the other's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id')->index();
            $table->string('name', 40);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlists');
    }
};
