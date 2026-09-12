<?php

namespace App\Http\Controllers;

use App\Enums\FeedMessageKind;
use App\Models\FeedMessage;
use App\Services\FeedDrawings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands a finger drawing to somebody allowed to see it, and to nobody else.
 *
 * Drawings are the kids' own pictures, and some of them are posted in rooms a
 * parent deliberately cannot read — a direct message between two siblings. So
 * the bucket they live in is private and there is no public URL for one. Every
 * drawing is fetched through here, which asks the one question the whole feed
 * turns on: can this person read the room it was posted in?
 *
 * The answer to "no" is a 404 rather than a 403, so a guessed or forwarded link
 * doesn't even confirm that a drawing exists.
 *
 * Streamed from whichever disk is in use rather than redirected to a signed
 * bucket link: a signed link is a bearer token, and one copied out of a DM would
 * open for anybody until it expired. A drawing is a few dozen kilobytes, so
 * passing it through the app costs nothing worth that.
 */
class FeedDrawingController extends Controller
{
    public function __invoke(Request $request, FeedMessage $message, FeedDrawings $drawings): StreamedResponse
    {
        $viewer = $request->user('profile');

        abort_unless(
            $viewer
                && $message->kind === FeedMessageKind::Drawing
                && $message->drawing_path
                && $message->room?->readableBy($viewer),
            404,
        );

        abort_unless($drawings->disk()->exists($message->drawing_path), 404);

        return $drawings->disk()->response($message->drawing_path, null, [
            'Content-Type' => 'image/png',
            // Private, so no shared cache between here and the browser ever
            // keeps a copy. A week, because the name is random and the file is
            // never rewritten — the same path is always the same picture.
            'Cache-Control' => 'private, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
