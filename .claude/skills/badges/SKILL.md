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

## How evaluation works

`evaluate(Profile $profile)` runs a fixed list of `maybeAward($profile, $key, $condition)` checks. `maybeAward()` is idempotent by construction — it first checks `$profile->badges()->where('key', $key)->exists()` and bails immediately if already earned, so `evaluate()` is safe to call repeatedly/liberally.

**This is the important operational rule:** `evaluate()` is not run on a schedule — it must be called explicitly after any event that could satisfy a condition. Current call sites: `ChoreService::approve()` (after a chore completion is approved) and `StoreService::redeem()` (after a redemption request). **When adding a new point-affecting or activity-affecting flow, call `$this->badges->evaluate($profile)` at the end of it, or the badges tied to that flow will never unlock.**

`evaluateHouseholdGoal(Household)` is separate and household-scoped: once `goal_now >= goal_target` (and `goal_target > 0`), it awards `team_effort` to **every kid profile in the household**, not just whoever's action crossed the finish line. Call this wherever `goal_now` is incremented (currently only inside `ChoreService::approve()`).

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
| `team_effort` | household `goal_now >= goal_target` | awarded household-wide via `evaluateHouseholdGoal()`, not `evaluate()` |

All thresholds are private constants at the top of `BadgeService` — change them there, not inline in conditions.
