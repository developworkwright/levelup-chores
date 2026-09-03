<?php

namespace App\Console\Commands;

use App\Enums\ArcadeGame;
use App\Models\Household;
use App\Services\ArcadeService;
use Illuminate\Console\Command;
use ValueError;

/**
 * Tells the kids a new game has gone into the arcade.
 *
 * A command rather than something automatic because a game arrives in a
 * deploy: there is no row being written and no form being submitted that could
 * fire it, and a game often goes in a day or two before anybody is meant to
 * find it. This is the moment somebody decides it is ready.
 *
 * The in-app half needs no command at all — a game wears a "new" flash until
 * each kid next opens the arcade, off `profiles.arcade_seen_at` against the
 * game's release date. This is only the push, for the kids who are not in the
 * app to see it.
 */
class AnnounceArcadeGameCommand extends Command
{
    protected $signature = 'arcade:announce
        {game? : Which game, e.g. windy_walkies. Defaults to the newest one.}
        {--household= : Only one household, by name}
        {--dry-run : Show who would be told without sending anything}';

    protected $description = 'Push "new game in the arcade" to every kid — the half of the announcement that reaches a phone nobody is holding.';

    public function handle(): int
    {
        try {
            $game = $this->argument('game')
                ? ArcadeGame::from($this->argument('game'))
                : ArcadeGame::newest();
        } catch (ValueError) {
            $this->error('No game called "'.$this->argument('game').'".');
            $this->line('Try one of: '.collect(ArcadeGame::cases())->pluck('value')->join(', '));

            return self::FAILURE;
        }

        $households = Household::all();

        if ($name = $this->option('household')) {
            $households = $households->filter(fn (Household $h) => strcasecmp($h->name, $name) === 0)->values();

            if ($households->isEmpty()) {
                $this->error("No household named \"{$name}\" found.");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $arcade = app(ArcadeService::class);
        $rows = [];

        foreach ($households as $household) {
            $told = $dryRun
                ? $household->profiles()->where('role', 'kid')->count()
                : $arcade->announceNewGame($household, $game);

            $rows[] = [$household->name, $told];
        }

        $this->table(['Household', 'Kids told'], $rows);
        $this->info($dryRun
            ? 'Dry run — nothing was actually sent.'
            : $game->label().' announced. The "new" flash in the app runs off its release date and needs nothing from here.');

        return self::SUCCESS;
    }
}
