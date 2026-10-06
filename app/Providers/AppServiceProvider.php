<?php

namespace App\Providers;

use App\Models\ChoreCompletion;
use App\Models\StreakRepair;
use App\Models\StreakRescue;
use App\Services\ChoreService;
use App\Services\CosmeticService;
use App\Services\StreakService;
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

        // Scoped for the same reason: the header, the feed and the boards all
        // ask what somebody is wearing on one request, and the household's
        // catalog should be one query however many of them ask.
        $this->app->scoped(CosmeticService::class);

        // Scoped so its memo of earned and waiting days is shared by everything
        // that asks on one request — see StreakService::$earnedWindows.
        $this->app->scoped(StreakService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * What the streak service remembers is only as good as the three tables
         * it read. They are written from four services, so the forgetting hangs
         * off the models rather than off each writer — a fifth writer cannot
         * forget to forget. Only an instance this request already built has
         * anything to drop.
         */
        foreach ([ChoreCompletion::class, StreakRepair::class, StreakRescue::class] as $model) {
            $model::saved(fn () => $this->forgetStreakDays());
            $model::deleted(fn () => $this->forgetStreakDays());
        }
    }

    private function forgetStreakDays(): void
    {
        if ($this->app->resolved(StreakService::class)) {
            $this->app->make(StreakService::class)->forgetDays();
        }
    }
}
