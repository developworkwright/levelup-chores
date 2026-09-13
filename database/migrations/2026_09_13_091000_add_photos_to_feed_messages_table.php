<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A seventh kind of message: a photograph.
 *
 * The kids could draw the fort they built and they could describe it. Now they
 * can show it. Same rooms, same audience line, same rule — a photo posted in a
 * room a parent cannot read is a photo a parent cannot read.
 *
 * ## Its own columns rather than `drawing_path`
 *
 * A drawing is a fixed 640x380 PNG this app's own canvas produced. A photo is
 * whatever came off a phone. Sharing one column would leave `kind` as the only
 * thing telling the controller what it is about to serve, on the one code path
 * where being wrong about a content type is a security bug rather than a
 * cosmetic one. Two columns keeps that answer explicit, and leaves the drawing
 * path — which is already running against a live database — untouched.
 *
 * ## Why width and height are stored and the mime type is not
 *
 * Every upload is decoded and re-encoded to JPEG before it is written (see
 * App\Services\FeedPhotos), so there is exactly one output format and the
 * served Content-Type is a constant in the code rather than a value read back
 * out of this table. A mime column would be a way for a row to argue with that
 * constant, which is not a conversation worth allowing.
 *
 * The dimensions, by contrast, have to be stored: a drawing's size is known at
 * compile time and a photo's is not, and without them every photo in the room
 * is a blank space that shoves the conversation down the page when it loads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_messages', function (Blueprint $table) {
            // Path on the drawings disk — the same private disk, under
            // `photos/` rather than `drawings/`. Not a URL: the disk moves
            // between a local folder and a bucket depending on the host, and
            // neither has a public address for one of these.
            $table->string('image_path')->nullable()->after('drawing_path');

            // The stored image's own size, after downscaling. These become the
            // <img> intrinsic dimensions, which is what stops a room of photos
            // reflowing as each one arrives.
            $table->unsignedSmallInteger('image_width')->nullable()->after('image_path');
            $table->unsignedSmallInteger('image_height')->nullable()->after('image_width');
        });
    }

    public function down(): void
    {
        Schema::table('feed_messages', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_width', 'image_height']);
        });
    }
};
