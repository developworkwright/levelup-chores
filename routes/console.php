<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Nothing is scheduled here, and that is a decision rather than an omission.
 *
 * The app is hosted scale-to-zero, and Laravel's scheduler works by running
 * `schedule:run` every minute — which would hold a container awake around the
 * clock so that `arcade:last-call` can matter for one hour a week. So the
 * Sunday last call is noticed on the way in instead, by whoever happens to open
 * the app during the window, which is the same answer SyncStreak and
 * ArcadeService::settle() already give for the same reason. See
 * App\Http\Middleware\SendArcadeLastCall.
 *
 * `arcade:last-call` still exists and is still safe to run. If a platform cron
 * that invokes a command directly (rather than a per-minute scheduler) is ever
 * available, pointing it at that command on `0 * * * 0` — hourly, Sundays only,
 * 24 wakeups a week — buys the guarantee that a household where nobody opened
 * the app still gets its last call. It is not required for the feature to work.
 */
