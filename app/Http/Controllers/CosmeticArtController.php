<?php

namespace App\Http\Controllers;

use App\Models\Cosmetic;
use App\Services\CosmeticArt;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands out an uploaded cosmetic picture.
 *
 * Unlike a feed picture this one is meant to be seen: a bought frame is worn on
 * the public login door. So a published item is served to anybody, and only a
 * draft is held back — to a grown-up of its own household, since uploading must
 * never put anything in front of a kid.
 */
class CosmeticArtController extends Controller
{
    public function __invoke(Request $request, Cosmetic $cosmetic, CosmeticArt $art): StreamedResponse
    {
        if ($cosmetic->isDraft()) {
            $viewer = $request->user('profile');

            abort_unless($viewer && $viewer->isParent() && $viewer->household_id === $cosmetic->household_id, 404);
        }

        $disk = $art->disk();

        abort_unless($cosmetic->art_path !== null && $disk->exists($cosmetic->art_path), 404);

        return $disk->response($cosmetic->art_path, null, [
            // Always PNG: CosmeticArt redraws every upload as one.
            'Content-Type' => 'image/png',
            // The URL carries the row's timestamp, so a replaced picture is a
            // new address and this can be cached hard. Drafts are private.
            'Cache-Control' => $cosmetic->isDraft() ? 'private, no-store' : 'public, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
