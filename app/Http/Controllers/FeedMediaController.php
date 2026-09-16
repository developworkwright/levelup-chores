<?php

namespace App\Http\Controllers;

use App\Enums\FeedMessageKind;
use App\Models\FeedMessage;
use App\Services\FeedDrawings;
use App\Services\FeedPhotos;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands a drawing or a photograph to somebody allowed to see it, and to nobody
 * else.
 *
 * These are the kids' own pictures, and some of them are posted in rooms a
 * parent deliberately cannot read — a direct message between two siblings. So
 * the bucket they live in is private and there is no public URL for one. Every
 * picture is fetched through here, which asks the one question the whole feed
 * turns on: can this person read the room it was posted in?
 *
 * The answer to "no" is a 404 rather than a 403, so a guessed or forwarded link
 * doesn't even confirm that a picture exists.
 *
 * **One controller for both kinds, on purpose.** That readability check is the
 * security-critical line in this feature, and a second controller for photos
 * would be a second copy of it — which is how one of the two quietly stops
 * matching the other six months from now. Two route names reach this, so that
 * a URL still says what it is pointing at; there is only ever one gate behind
 * them.
 *
 * Streamed from whichever disk is in use rather than redirected to a signed
 * bucket link: a signed link is a bearer token, and one copied out of a DM would
 * open for anybody until it expired. A picture is a few dozen kilobytes, so
 * passing it through the app costs nothing worth that.
 */
class FeedMediaController extends Controller
{
    public function __invoke(
        Request $request,
        FeedMessage $message,
        FeedDrawings $drawings,
        FeedPhotos $photos,
    ): StreamedResponse {
        $viewer = $request->user('profile');

        abort_unless($viewer && $message->room?->readableBy($viewer), 404);

        /*
         * The content type comes from this match and from nowhere else.
         *
         * Never from the request, and never from a column — a photo is decoded
         * and re-encoded to one format before it is ever written (see
         * FeedPhotos), precisely so that this can be a constant rather than
         * something a row could argue with.
         *
         * @var array{0: ?string, 1: ?string, 2: ?Filesystem}
         */
        [$path, $type, $disk] = match ($message->kind) {
            FeedMessageKind::Drawing => [$message->drawing_path, 'image/png', $drawings->disk()],
            FeedMessageKind::Photo => [$message->image_path, FeedPhotos::MIME, $photos->disk()],
            default => [null, null, null],
        };

        /*
         * A readable message whose file is not on the disk is the one 404 here
         * that is a fault rather than a refusal, and it used to look identical
         * to a stranger guessing a URL.
         *
         * It has a real cause: the disk moves. A picture written while
         * `DRAWINGS_DISK` was the local folder is on a container that no longer
         * exists the moment the app is pointed at a bucket, and the row outlives
         * the file. So say which path on which disk came up empty — the answer
         * is either "the bucket never had it" or "the name is wrong", and those
         * are different bugs.
         */
        // Asked once and reused. On a bucket this is a HEAD request, and every
        // picture in every room pays for it — checking twice to log a failure
        // would double that for the pictures that are perfectly fine.
        $readable = $path !== null && $disk instanceof Filesystem && $disk->exists($path);

        if (! $readable && $path !== null && $disk instanceof Filesystem) {
            Log::warning('Feed picture is missing from the disk it is filed on.', [
                'message_id' => $message->id,
                'kind' => $message->kind->value,
                'path' => $path,
                'disk' => config('filesystems.drawings_disk'),
            ]);
        }

        abort_unless($readable, 404);

        return $disk->response($path, null, [
            'Content-Type' => $type,
            // Private, so no shared cache between here and the browser ever
            // keeps a copy. A week, because the name is random and the file is
            // never rewritten — the same path is always the same picture.
            'Cache-Control' => 'private, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // Shown, never handed to the operating system as a download.
            'Content-Disposition' => 'inline',
            // Belt and braces over the type above: even if something that was
            // not an image ever reached this response, a document served under
            // this policy can load nothing and run nothing.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
