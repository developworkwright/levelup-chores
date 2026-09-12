<?php

namespace App\Console\Commands;

use App\Enums\ArcadeGame;
use App\Enums\ProfileRole;
use App\Models\Profile;
use App\Services\ArcadeService;
use Illuminate\Console\Command;

/**
 * Puts a run on the board that the app threw away.
 *
 * Written for one specific afternoon: a kid slid 7659m on the penguin launch,
 * the per-game ceiling was 4000, and `post()` refused the score and returned
 * null without a word to anybody. He went to fetch his brother and the board
 * had never heard of it. The ceiling is gone now, but that row was never
 * written and no amount of fixing the rule brings it back.
 *
 * Deliberately a command rather than a screen. Writing a score nobody watched
 * being played is exactly the thing the board exists not to do, and it should
 * cost a grown-up a trip to a terminal every single time — a button on the
 * parent console would get used for "he nearly got it" within a fortnight, and
 * a board that can be nudged is not a board.
 *
 * Use it to repair what the app got wrong. Not to be kind.
 */
class ArcadePostScoreCommand extends Command
{
    protected $signature = 'arcade:post-score
        {kid : The kid\'s name}
        {game : The game key, e.g. penguin_launch}
        {score : The score to record}
        {--week= : ISO year-week to file it under, e.g. 2026-W37. Defaults to this week.}';

    protected $description = "Record an arcade run the app refused, on a kid's behalf.";

    public function handle(ArcadeService $arcade): int
    {
        $kid = Profile::where('role', ProfileRole::Kid)
            ->get()
            ->first(fn (Profile $profile): bool => strcasecmp($profile->name, $this->argument('kid')) === 0);

        if ($kid === null) {
            $this->error("No kid named \"{$this->argument('kid')}\" found.");

            return self::FAILURE;
        }

        $game = ArcadeGame::tryFrom($this->argument('game'));

        if ($game === null || ! $game->isRanked()) {
            $this->error('Not a game with a board. One of: '
                .implode(', ', array_map(fn (ArcadeGame $g): string => $g->value, ArcadeGame::ranked())));

            return self::FAILURE;
        }

        $score = (int) $this->argument('score');

        if ($score < 1) {
            $this->error('A run has to be worth at least 1.');

            return self::FAILURE;
        }

        // Through the service rather than straight to the model, so a restored
        // run is the same kind of row as a played one — and so it still takes
        // the board off whoever is holding it, and still tells them it has.
        $run = $arcade->post($kid, $game, $score);

        if ($run === null) {
            $this->error('The board refused it. That is now a bug — say so.');

            return self::FAILURE;
        }

        if ($week = $this->option('week')) {
            $run->forceFill(['week' => $week])->save();
        }

        $this->info("Recorded {$score} {$game->unit()} for {$kid->name} on {$game->label()} ({$run->week}).");

        return self::SUCCESS;
    }
}
