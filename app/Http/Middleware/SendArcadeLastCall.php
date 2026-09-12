<?php

namespace App\Http\Middleware;

use App\Models\Profile;
use App\Services\ArcadeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the arcade last call on the way in, rather than from a scheduler.
 *
 * The same answer {@see SyncStreak} and {@see ArcadeService::settle()} already
 * give, for a sharper reason: the app is hosted scale-to-zero, so the usual
 * `schedule:run` every minute would hold a container awake around the clock to
 * do something that matters for one hour a week. A push worth three tickets is
 * not worth a server that never sleeps.
 *
 * So the Sunday evening is noticed by whoever happens to arrive during it, and
 * the whole household is served by that one visit. Deliberately mounted on the
 * grown-ups' pages as well as the kids': a parent clearing the approval queue
 * after dinner is the likeliest visitor of all on a Sunday, and they are
 * exactly the person whose visit should reach a kid who has stopped coming.
 *
 * The cost of this over a scheduler is that a household where nobody opens the
 * app all Sunday evening gets no last call — which is the same trade `settle()`
 * already makes, and the honest version of what a scale-to-zero host can
 * promise. `arcade:last-call` is still there for a platform cron that wants the
 * guarantee; it just is not required for the feature to work.
 *
 * Cheap enough to sit in front of every page: the first question it asks is
 * whether the board week is near its end at all, which is arithmetic on the
 * clock and touches neither the database nor the cache. Six and a half days a
 * week it stops there.
 */
class SendArcadeLastCall
{
    public function __construct(private ArcadeService $arcade) {}

    public function handle(Request $request, Closure $next): Response
    {
        $profile = $request->user();

        if ($profile instanceof Profile && $this->arcade->lastCallWindowIsOpen()) {
            $this->arcade->sendLastCall($profile->household);
        }

        return $next($request);
    }
}
