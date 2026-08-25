<?php

namespace App\Services;

use App\Enums\BossSkin;
use App\Models\ArcadeScore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The login-page arcade: codenames, the weekly board, and posting a run.
 *
 * Everything here is shaped by one constraint — `/` has no auth, so a stranger
 * with the URL can read this board and can POST to it. That rules out free
 * text (hence a fixed codename vocabulary), rules out storing anything that
 * identifies a player, and means every number that arrives from the client is
 * treated as a claim rather than a fact.
 */
class ArcadeService
{
    /**
     * The tallest tower the server will believe. Floors get roughly a pixel
     * narrower each imperfect drop from a 180px slab, so even a player who
     * never misses badly runs out of tower long before this; it exists to put
     * a ceiling on what a tampered request can write, not to cap real play.
     */
    public const MAX_SCORE = 999;

    /** Runs a single visitor may post per hour before the board stops listening. */
    public const POSTS_PER_HOUR = 40;

    /**
     * Half of every codename. The other half is the monster roster, so a name
     * lands somewhere between a graffiti tag and something that lives under
     * the stairs — SALTY RATTLE, GRUMPY LONGLEGS, SOGGY BALLOONHEAD.
     *
     * @var list<string>
     */
    public const ADJECTIVES = [
        'SALTY', 'GRIM', 'SLEEPY', 'MUDDY', 'CRUMPLED', 'SOGGY', 'SNEAKY', 'CRUSTY',
        'LOUD', 'TINY', 'GREEDY', 'SPOOKY', 'FUZZY', 'WOBBLY', 'GRUMPY', 'HUNGRY',
        'DUSTY', 'SPIKY', 'SNAPPY', 'SILENT', 'LUCKY', 'ROTTEN', 'SPARKY', 'JUMPY',
        'BOLD', 'CREAKY', 'MOODY', 'STICKY',
    ];

    /**
     * How high the tower got, in words a kid can picture.
     *
     * This is the shared spine of the whole feature: the game's parallax
     * scenery is keyed to these entries *by index*, so the wall, the attic and
     * the sky change exactly where the banner says they do. Adding a milestone
     * here without adding the matching scenery in `resources/js/arcade.js`
     * fails `ArcadeMilestoneTest`.
     *
     * @var list<array{0: int, 1: string}>
     */
    public const MILESTONES = [
        [0, 'On the rug'],
        [3, 'Sofa height'],
        [6, 'Light switch'],
        [9, 'Picture rail'],
        [12, 'Window height'],
        [15, 'Top shelf'],
        [18, 'Ceiling'],
        [22, 'In the attic'],
        [26, 'Through the roof'],
        [31, 'Treetops'],
        [36, 'In the clouds'],
        [42, 'Bird height'],
        [50, 'Stratosphere'],
        [60, 'Moonlit'],
        [75, 'Outer space'],
    ];

    /**
     * The second half of every codename, taken straight off the monster roster
     * so the arcade speaks the same language as the rest of the app. Derived
     * rather than listed: a new skin joins the vocabulary for free, and the
     * two can't drift.
     *
     * @return list<string>
     */
    public static function nouns(): array
    {
        return array_map(
            fn (BossSkin $skin): string => strtoupper(str_replace('-', '', $skin->value)),
            BossSkin::cases()
        );
    }

    /**
     * The word lists handed to the browser so it can roll and re-roll a
     * codename without a round trip. It's public data either way — the point
     * of shipping it is that the client picks *indexes*, and the server
     * rebuilds the name from its own copy.
     *
     * @return array{adjectives: list<string>, nouns: list<string>}
     */
    public static function vocabulary(): array
    {
        return [
            'adjectives' => self::ADJECTIVES,
            'nouns' => self::nouns(),
        ];
    }

    /**
     * Resolve a pair of indexes into a codename.
     *
     * Wraps rather than validates: any two integers name *something* in the
     * vocabulary, so a mangled or malicious request produces a silly name
     * instead of an error path, and nothing outside the word lists can ever
     * reach the column.
     */
    public function codename(int $adjective, int $noun): string
    {
        $nouns = self::nouns();

        $adjective = abs($adjective) % count(self::ADJECTIVES);
        $noun = abs($noun) % count($nouns);

        return self::ADJECTIVES[$adjective].' '.$nouns[$noun];
    }

    /** ISO year-week — the bucket a run is posted into, e.g. "2026-W35". */
    public function currentWeek(?Carbon $at = null): string
    {
        return ($at ?? now())->format('o-\WW');
    }

    /** Human label for the current board's week, e.g. "Mon 24 Aug". */
    public function weekStartedOn(): Carbon
    {
        return now()->startOfWeek();
    }

    /**
     * How high a given number of floors got, in words. Falls back to the first
     * milestone, which is where every run starts.
     */
    public function altitude(int $floors): string
    {
        $label = self::MILESTONES[0][1];

        foreach (self::MILESTONES as [$at, $name]) {
            if ($floors >= $at) {
                $label = $name;
            }
        }

        return $label;
    }

    /**
     * Write a run to the board. Returns null when the claim is not worth
     * keeping — a zero-floor run, or a number no tower could reach.
     */
    public function post(int $score, int $adjective, int $noun): ?ArcadeScore
    {
        if ($score < 1 || $score > self::MAX_SCORE) {
            return null;
        }

        return ArcadeScore::create([
            'codename' => $this->codename($adjective, $noun),
            'score' => $score,
            'week' => $this->currentWeek(),
        ]);
    }

    /**
     * This week's board. Ties break oldest-first: getting there first is the
     * tiebreak everywhere else in this app, and it stops a new run from
     * bumping an equal one that has been sitting on the board all week.
     *
     * @return Collection<int, ArcadeScore>
     */
    public function weeklyTop(int $limit = 10): Collection
    {
        return ArcadeScore::query()
            ->where('week', $this->currentWeek())
            ->orderByDesc('score')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** The tallest tower ever posted, or null if nobody has played yet. */
    public function allTimeBest(): ?ArcadeScore
    {
        return ArcadeScore::query()
            ->orderByDesc('score')
            ->orderBy('id')
            ->first();
    }
}
