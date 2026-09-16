<?php

namespace App\Providers;

use App\Services\ChoreService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * One chore service per request.
         *
         * {@see ChoreService::boardFor()} costs a claimant query per chore, and
         * a single render of the kid's Quests page asks for the board three
         * times over: the page itself, the adding-up card, and the Quest
         * Charm's "is there anything left to charm" check. Each `app()` call
         * used to hand back a new instance, so the memo inside the service
         * could only ever help the caller that built it — three walks of a
         * twenty-chore board, about seventy queries, to draw one page.
         *
         * `scoped` rather than `singleton` deliberately: it is the same
         * instance for the life of a request and a *fresh* one on the next,
         * which is what keeps the memo honest under Octane, where a singleton
         * would quietly hold one household's board across requests.
         *
         * Safe because the service's only state is that memo, and every method
         * that changes what a board says drops it — see
         * {@see ChoreService::forgetBoards()}. The BadgeService it holds lives
         * a request too, and that was already safe: `maybeAward()` confirms
         * against the database before attaching, precisely because several
         * instances of it exist in one request.
         */
        $this->app->scoped(ChoreService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
