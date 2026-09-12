<?php

namespace App\Console\Commands;

use App\Enums\ProfileRole;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each kid actually still opens, and when they stopped.
 *
 * The app has accumulated a lot of separate things to do, and "the kids lost
 * interest" is not one fact — a kid who stopped opening the app at all and a
 * kid who opens it daily and only taps the arcade need opposite fixes. This
 * counts every kid-initiated record we keep, bucketed by week, so the decay
 * shows up as a shape rather than a guess.
 *
 * Read-only. Nothing here writes, so it is safe to run against production.
 *
 * One caveat worth knowing before trusting the "last seen" column: there is no
 * visit log. The nearest thing is the session row, which is overwritten on
 * every request and pruned on expiry, so it answers "has this kid opened the
 * app recently" and nothing about history. Everything else here counts
 * *actions*, which means a kid who opens the app, looks around and does
 * nothing is indistinguishable from a kid who never opened it at all. If that
 * distinction matters, we need a visit log before this report can show it.
 */
class EngagementReportCommand extends Command
{
    protected $signature = 'engagement:report
        {--days=60 : How far back to count}
        {--weeks=8 : How many weekly buckets to chart}
        {--kid= : Only report one kid, by name}
        {--csv= : Also write the per-feature table to this path}';

    protected $description = 'Report which features each kid still uses and when they stopped, to find where engagement actually dropped.';

    /**
     * Every kid-initiated record the app keeps, as [label, table, profile
     * column, date column, optional [column, value] filter].
     *
     * Tables and columns are checked before use rather than assumed, because
     * this is meant to run against a production database that may be a
     * migration or two behind this branch — a report that fatals on one missing
     * column tells you nothing about the other twenty-eight features.
     *
     * @var list<array{0:string,1:string,2:string,3:string,4?:array{0:string,1:mixed}}>
     */
    private const FEATURES = [
        ['Chores submitted', 'chore_completions', 'profile_id', 'submitted_at'],
        ['Chores approved', 'chore_completions', 'profile_id', 'decided_at', ['status', 'approved']],
        ['Help Wanted raised', 'chore_completions', 'profile_id', 'submitted_at', ['help_wanted', 1]],
        ['Daily quest done', 'daily_quests', 'profile_id', 'completed_at'],
        ['Quest hand dealt', 'daily_quests', 'profile_id', 'dealt_at'],
        ['Quest charmed', 'daily_quests', 'profile_id', 'charmed_at'],
        ['Quest skipped', 'quest_skips', 'profile_id', 'skip_date'],
        ['Mystery found', 'daily_mysteries', 'found_by_profile_id', 'found_at'],
        ['Bonus wheel spun', 'spins', 'profile_id', 'spin_date'],
        ['Daily chest opened', 'daily_chests', 'profile_id', 'chest_date'],
        ['Celebration chest', 'celebration_chests', 'profile_id', 'opened_at'],
        ['Badges earned', 'profile_badges', 'profile_id', 'earned_at'],
        ['Monster hits', 'monster_hits', 'profile_id', 'created_at'],
        ['Lucky Block won', 'lucky_hits', 'profile_id', 'won_at'],
        ['Arcade plays', 'arcade_scores', 'profile_id', 'created_at'],
        ['Loot redeemed', 'redemptions', 'profile_id', 'requested_at'],
        ['Loot favourited', 'loot_favorites', 'profile_id', 'created_at'],
        ['Perks bought', 'owned_perks', 'profile_id', 'acquired_at'],
        ['Perks used', 'owned_perks', 'profile_id', 'consumed_at'],
        ['Tickets earned', 'bonus_ticket_entries', 'profile_id', 'created_at'],
        ['Trades offered', 'sibling_offers', 'from_profile_id', 'created_at'],
        ['Bounties claimed', 'bounties', 'claimed_by_profile_id', 'claimed_at'],
        ['Nudges sent', 'nudges', 'from_profile_id', 'created_at'],
        ['Streak rescues given', 'streak_rescues', 'rescued_by_profile_id', 'created_at'],
        ['Sleep logged', 'sleep_nights', 'profile_id', 'night_date'],
        ['Gratitude written', 'gratitude_entries', 'profile_id', 'entry_date'],
        ['Feelings logged', 'feeling_entries', 'profile_id', 'felt_on'],
        ['Quotes reacted to', 'quote_reactions', 'profile_id', 'created_at'],
        ['Playlists made', 'playlists', 'profile_id', 'created_at'],
    ];

    /**
     * Memoised results of usable(), keyed by table and columns.
     *
     * @var array<string, bool>
     */
    private array $usableCache = [];

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));
        $weeks = max(2, (int) $this->option('weeks'));

        $kids = Profile::where('role', ProfileRole::Kid)->orderByDesc('age')->get();

        if ($name = $this->option('kid')) {
            $kids = $kids->filter(fn (Profile $kid): bool => strcasecmp($kid->name, $name) === 0)->values();

            if ($kids->isEmpty()) {
                $this->error("No kid named \"{$name}\" found.");

                return self::FAILURE;
            }
        }

        if ($kids->isEmpty()) {
            $this->error('No kids on this household.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();

        $this->roster($kids, $now);
        $this->weeklyShape($kids, $now, $weeks);
        $rows = $this->featureTable($kids, $now, $days);
        $this->verdicts($kids, $now, $days);

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $rows);
        }

        return self::SUCCESS;
    }

    /**
     * Who the kids are, and the last time a request of theirs hit the app.
     *
     * @param  Collection<int, Profile>  $kids
     */
    private function roster(Collection $kids, CarbonImmutable $now): void
    {
        $this->newLine();
        $this->info('Roster');

        $lastSeen = $this->lastSeenByProfile();

        $this->table(
            ['Kid', 'Age', 'Level', 'Points', 'Tickets', 'Streak', 'Last seen'],
            $kids->map(fn (Profile $kid): array => [
                $kid->name,
                $kid->age,
                $kid->level(),
                $kid->points,
                $kid->bonus_tickets ?? 0,
                $kid->streak,
                isset($lastSeen[$kid->id])
                    ? $lastSeen[$kid->id]->diffForHumans($now)
                    : 'no live session',
            ])->all()
        );

        $this->line('  <fg=gray>"Last seen" is the session row, which expires — "no live session" means not');
        $this->line('  within the session lifetime, not never.</>');
    }

    /**
     * Total actions per kid per week, oldest week first.
     *
     * The point of this table is the shape of each column, not any one number.
     * A kid whose weekly total halves four weeks running has been drifting away
     * for a month, which is a different problem from one who fell off a cliff
     * in the week something specific changed.
     *
     * @param  Collection<int, Profile>  $kids
     */
    private function weeklyShape(Collection $kids, CarbonImmutable $now, int $weeks): void
    {
        $this->newLine();
        $this->info("Actions per week (last {$weeks})");

        $rows = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $anchor = $now->subWeeks($i);
            $start = $anchor->startOfWeek();
            $end = $anchor->endOfWeek();

            $row = [$start->format('M j')];

            foreach ($kids as $kid) {
                $total = 0;

                foreach (self::FEATURES as $feature) {
                    $total += $this->countBetween($feature, $kid, $start, $end);
                }

                $row[] = $total.'  '.$this->bar($total);
            }

            $rows[] = $row;
        }

        $this->table(array_merge(['Week of'], $kids->pluck('name')->all()), $rows);
    }

    /**
     * Per kid, every feature: this week, last week, the whole window, and the
     * last time they touched it.
     *
     * @param  Collection<int, Profile>  $kids
     * @return array<string, array<string, array{0:int,1:int,2:int,3:?string}>>
     */
    private function featureTable(Collection $kids, CarbonImmutable $now, int $days): array
    {
        $collected = [];

        foreach ($kids as $kid) {
            $this->newLine();
            $this->info("{$kid->name} — features, last {$days} days");

            $rows = [];
            $collected[$kid->name] = [];

            foreach (self::FEATURES as $feature) {
                if (! $this->usable($feature)) {
                    continue;
                }

                $thisWeek = $this->countBetween($feature, $kid, $now->subDays(7), $now);
                $lastWeek = $this->countBetween($feature, $kid, $now->subDays(14), $now->subDays(7));
                $window = $this->countBetween($feature, $kid, $now->subDays($days), $now);
                $last = $this->lastUsed($feature, $kid);

                $collected[$kid->name][$feature[0]] = [$thisWeek, $lastWeek, $window, $last?->toDateString()];

                // A feature a kid has never once touched is a different problem
                // from one that decayed, and listing twenty zero rows buries
                // the handful that actually moved. The never-touched ones are
                // summarised on one line underneath instead.
                if ($window === 0 && $last === null) {
                    continue;
                }

                $rows[] = [
                    $feature[0],
                    $thisWeek ?: '-',
                    $lastWeek ?: '-',
                    $window ?: '-',
                    $last ? $last->diffForHumans($now, short: true) : 'never',
                    $this->trend($thisWeek, $lastWeek),
                ];
            }

            $this->table(['Feature', '7d', 'prev 7d', "{$days}d", 'Last', ''], $rows);

            $never = array_keys(array_filter(
                $collected[$kid->name],
                fn (array $counts): bool => $counts[2] === 0 && $counts[3] === null
            ));

            if ($never !== []) {
                $this->line('  <fg=gray>Never touched: '.implode(', ', $never).'</>');
            }
        }

        return $collected;
    }

    /**
     * The one-line read on each kid, so the tables above make a claim you can
     * argue with rather than leaving it as an exercise.
     *
     * @param  Collection<int, Profile>  $kids
     */
    private function verdicts(Collection $kids, CarbonImmutable $now, int $days): void
    {
        $this->newLine();
        $this->info('Read');

        foreach ($kids as $kid) {
            $recent = 0;
            $opening = 0;
            $liveFeatures = 0;

            foreach (self::FEATURES as $feature) {
                $week = $this->countBetween($feature, $kid, $now->subDays(7), $now);
                $recent += $week;

                // The first week of the window, as the baseline this week is
                // measured against.
                $opening += $this->countBetween($feature, $kid, $now->subDays($days), $now->subDays($days - 7));

                if ($week > 0) {
                    $liveFeatures++;
                }
            }

            $verdict = match (true) {
                $recent === 0 => 'gone — nothing at all in 7 days',
                $opening > 0 && $recent * 3 < $opening => 'fading — down to '.round($recent / $opening * 100).'% of where the window started',
                $liveFeatures <= 2 => "narrowed — still here, but only {$liveFeatures} feature(s) in play",
                default => 'engaged',
            };

            $this->line("  <options=bold>{$kid->name}</>: {$verdict} — {$recent} actions this week across {$liveFeatures} features");
        }

        $this->newLine();
    }

    /**
     * Whether the table and every column this feature needs actually exist.
     *
     * Cached per command instance rather than statically: every feature is
     * asked about once per kid per week bucket, so the lookups are worth
     * keeping, but a cache that outlived the instance would answer for a
     * schema that is no longer the one in front of it.
     *
     * @param  array{0:string,1:string,2:string,3:string,4?:array{0:string,1:mixed}}  $feature
     */
    private function usable(array $feature): bool
    {
        [, $table, $profileColumn, $dateColumn] = $feature;

        $key = "{$table}.{$profileColumn}.{$dateColumn}";

        return $this->usableCache[$key] ??= Schema::hasTable($table)
            && Schema::hasColumn($table, $profileColumn)
            && Schema::hasColumn($table, $dateColumn)
            && (! isset($feature[4]) || Schema::hasColumn($table, $feature[4][0]));
    }

    /**
     * @param  array{0:string,1:string,2:string,3:string,4?:array{0:string,1:mixed}}  $feature
     */
    private function countBetween(array $feature, Profile $kid, CarbonImmutable $from, CarbonImmutable $to): int
    {
        if (! $this->usable($feature)) {
            return 0;
        }

        [, $table, $profileColumn, $dateColumn] = $feature;

        $query = DB::table($table)
            ->where($profileColumn, $kid->id)
            ->whereBetween($dateColumn, [$from, $to]);

        if (isset($feature[4])) {
            $query->where($feature[4][0], $feature[4][1]);
        }

        return $query->count();
    }

    /**
     * @param  array{0:string,1:string,2:string,3:string,4?:array{0:string,1:mixed}}  $feature
     */
    private function lastUsed(array $feature, Profile $kid): ?CarbonImmutable
    {
        if (! $this->usable($feature)) {
            return null;
        }

        [, $table, $profileColumn, $dateColumn] = $feature;

        $query = DB::table($table)
            ->where($profileColumn, $kid->id)
            ->whereNotNull($dateColumn);

        if (isset($feature[4])) {
            $query->where($feature[4][0], $feature[4][1]);
        }

        $value = $query->max($dateColumn);

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /**
     * Most recent activity per profile, from the session table.
     *
     * @return array<int, CarbonImmutable>
     */
    private function lastSeenByProfile(): array
    {
        if (! Schema::hasTable('sessions') || ! Schema::hasColumn('sessions', 'user_id')) {
            return [];
        }

        return DB::table('sessions')
            ->whereNotNull('user_id')
            ->selectRaw('user_id, MAX(last_activity) as seen')
            ->groupBy('user_id')
            ->pluck('seen', 'user_id')
            ->map(fn ($seen): CarbonImmutable => CarbonImmutable::createFromTimestamp((int) $seen))
            ->all();
    }

    private function trend(int $thisWeek, int $lastWeek): string
    {
        return match (true) {
            $thisWeek === 0 && $lastWeek > 0 => '<fg=red>stopped</>',
            $thisWeek > $lastWeek => '<fg=green>up</>',
            $thisWeek < $lastWeek => '<fg=yellow>down</>',
            default => '',
        };
    }

    private function bar(int $total): string
    {
        return str_repeat('#', min(20, (int) ceil($total / 2)));
    }

    /**
     * @param  array<string, array<string, array{0:int,1:int,2:int,3:?string}>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');

        fputcsv($handle, ['kid', 'feature', 'last_7d', 'prev_7d', 'window', 'last_used']);

        foreach ($rows as $kidName => $features) {
            foreach ($features as $feature => $counts) {
                fputcsv($handle, [$kidName, $feature, $counts[0], $counts[1], $counts[2], $counts[3] ?? '']);
            }
        }

        fclose($handle);

        $this->line("  Wrote <options=bold>{$path}</>");
    }
}
