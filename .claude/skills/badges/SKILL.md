---
name: badges
description: This skill should be used when the user asks to add a new badge/achievement, change badge unlock conditions, or mentions "BadgeService", "maybeAward", "evaluateHouseholdGoal", or badge keys like "wheel_winner", "busy_bee", "perfect_board", "speed_runner", "team_effort".
---

# Badges

Covers the achievement system, owned by `app/Services/BadgeService.php`. All badge conditions live in one place — there is no per-feature badge logic scattered elsewhere.

## Core files

- `app/Services/BadgeService.php`
- `app/Models/Badge.php` + `profile_badges` pivot (has `earned_at`)
- Seeded badge rows in `database/seeders/DatabaseSeeder.php` — a badge must exist in the `badges` table (by `key`) before `maybeAward()` can attach it; adding a new condition to `evaluate()` without seeding the matching `Badge` row is a silent no-op.

## Badges pay XP and a ticket

Each badge carries an `xp_reward` (50–400, tiered by difficulty). `maybeAward()` adds that XP, mints one bonus ticket, and re-syncs level tickets — all guarded by the same `exists()` check, so a badge pays exactly once no matter how often `evaluate()` runs. See [[xp-and-tickets]].

Adding a badge means seeding a row *with* an `xp_reward`; a zero reward is silently a badge worth nothing.

## How evaluation works

`evaluate(Profile $profile)` fans out to five grouped private methods (`evaluateQuestBadges`, `evaluateChoreBadges`, `evaluatePointBadges`, `evaluateProgressBadges`, `evaluateBonusBadges`), each running `maybeAward($profile, $key, $condition)` checks. `maybeAward()` is idempotent by construction — it first checks `$profile->badges()->where('key', $key)->exists()` and bails immediately if already earned, so `evaluate()` is safe to call repeatedly/liberally.

Tiered sets go through `awardMilestones($profile, MAP, $measure)`, which takes **one** reading of whatever the tier counts and only takes it when `anyMissing()` says a badge in the set is still unwon — so a finished tier costs nothing to walk past. Streak and level badges deliberately bypass it: both numbers are already on the profile, and a badge's XP can carry a kid past the next level inside the same pass.

**This is the important operational rule:** `evaluate()` is not run on a schedule — it must be called explicitly after any event that could satisfy a condition. Current call sites: `ChoreService::approve()`, `StoreService::redeem()`, `SiblingOfferService::accept()`, `SpinService::spin()`, `ChestService::open()` and `PerkInventoryService::use()`. **When adding a new point-affecting or activity-affecting flow, call `$this->badges->evaluate($profile)` at the end of it, or the badges tied to that flow will never unlock.**

`evaluateHouseholdGoal(Household)` is separate and household-scoped: once the household has any beaten `Monster`, it awards `team_effort` to **every kid profile in the household**, not just whoever landed the killing blow. Called from `ChoreService::approve()`. It fires on the *first* kill — `maybeAward` is idempotent per key, so a cheap monster counts as much as a weekend away.

## Current badge keys and conditions

| key | condition | notes |
|---|---|---|
| `first_quest` | any `DailyQuest` with `completed_at` set exists | |
| `streak_3` / `streak_7` / `streak_14` | `profile->streak >= N` | see [[streaks]] |
| `big_spender` | cumulative `LedgerKind::Spend` sum ≥ `BIG_SPENDER_THRESHOLD` (1000) | uses `abs()` since spend amounts are negative |
| `big_saver` | `profile->points >= BIG_SAVER_THRESHOLD` (500) at evaluation time | a live balance check, not cumulative |
| `wheel_winner` | any `Spin` with `multiplier === 3` | see [[bonus-wheel]] |
| `busy_bee` | more than `BUSY_BEE_THRESHOLD` (3) approved completions since household-day start | uses `HouseholdClock`, not midnight |
| `perfect_board` | today's quest completed AND every other appropriate chore (household has >1 total) approved today | see `clearedWholeBoardToday()` |
| `early_bird` | any approved completion submitted before `EARLY_BIRD_HOUR` (7) local household time | checked against `submitted_at`, not `decided_at` |
| `night_owl` | any approved completion submitted at/after `NIGHT_OWL_HOUR` (22) local household time | |
| `speed_runner` | today's quest claimed within `SPEED_RUNNER_SECONDS` (300) of its reveal | needs both `revealed_at` and `completed_at` set |
| `team_effort` | household has beaten any `Monster` | awarded household-wide via `evaluateHouseholdGoal()`, not `evaluate()` |
| `chores_10` / `_50` / `_100` / `_365` | approved completions, all time | `CHORE_MILESTONES` |
| `quest_10` / `quest_50` | `DailyQuest` rows with `completed_at` | `QUEST_MILESTONES` |
| `streak_30` | `profile->streak >= 30` | `STREAK_MILESTONES` alongside 3/7/14 |
| `earner_1000` / `_5000` / `_20000` | cumulative `LedgerKind::Earn` sum | `EARNED_MILESTONES` — Earn only, so cash-in and parent top-ups don't count |
| `level_10` / `level_25` | `profile->level()` | checked individually so one badge's XP can trigger the next |
| `spin_25` | `Spin` count | `SPIN_MILESTONES` |
| `triple_threat` | three `Spin`s with `multiplier === 3` | hidden |
| `chest_7` / `chest_30` | `DailyChest` count | `CHEST_MILESTONES` |
| `first_reward` | any `Redemption` | `REDEMPTION_MILESTONES` |
| `big_ticket` | a `Redemption` with `cost_snapshot >= 500` | |
| `dealmaker` / `trade_10` | accepted `SiblingOffer`s on either side | `TRADE_MILESTONES` |
| `gadgeteer` | five `OwnedPerk` rows with `consumed_at` set | |
| `comeback_kid` | any `StreakRepair` | hidden; see [[streaks]] |
| `weekend_warrior` | a Saturday **and** the Sunday that follows it both worked | hidden; `clearedAWholeWeekend()` |
| `overachiever` | 8+ approved completions in one household day | hidden; walks `approvedByDay()` |
| `all_rounder` | every chore on the kid's board done at least once (board of 3+) | hidden; `hasDoneEveryChore()` |

All thresholds are private constants or milestone maps at the top of `BadgeService` — change them there, not inline in conditions. Badge XP totals about 7,500 across the full set; `BadgeXpTest` guards that range, so retiering means moving the bound with it.
