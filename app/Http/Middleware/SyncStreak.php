<?php

namespace App\Http\Middleware;

use App\Models\Profile;
use App\Services\ChoreService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expires a streak that ran out overnight.
 *
 * `profiles.streak` is a cached number that only an approval used to move, so
 * a kid who missed a day still saw yesterday's count on their header the next
 * morning. Nothing pushes at a household's day boundary — there is no worker
 * running per household timezone — so the boundary is noticed on the way in
 * instead, before any page has a chance to render a number that isn't true.
 *
 * {@see ChoreService::syncStreak()} is a constant-cost check, not a walk back
 * through the whole chain, which is what makes this cheap enough to sit in
 * front of every kid page.
 */
class SyncStreak
{
    public function __construct(private ChoreService $chores) {}

    public function handle(Request $request, Closure $next): Response
    {
        $profile = $request->user();

        if ($profile instanceof Profile && $profile->isKid()) {
            $this->chores->syncStreak($profile);
        }

        return $next($request);
    }
}
