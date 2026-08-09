---
name: streaks
description: This skill should be used when the user asks to change streak counting, streak bonus amounts, the streak chest reveal, or mentions "bumpStreak", "STREAK_BONUSES", "pending_streak_chest", or "openStreakChest".
---

# Streaks

Covers daily-quest streak counting and milestone bonuses, owned by `app/Services/ChoreService.php` (not a separate service — streak logic is private methods on `ChoreService` since it only ever fires from the daily quest flow).

## Core files

- `app/Services/ChoreService.php` — `bumpStreak()` (private), `openStreakChest()`, `nextStreakMilestone()`.
- `App\Models\Profile` — `streak` (int) and `pending_streak_chest` (nullable int, the milestone day awaiting reveal) columns.
- `resources/views/pages/kid/quests.blade.php` — chest UI.

## Mechanics

`bumpStreak()` runs only from `claimQuest()` — completing the **daily quest**, and only the daily quest, advances the streak. Completing side chores does not touch it.

Logic: look up whether *yesterday's* `DailyQuest` (by `quest_date`) for this profile has `completed_at` set. If so, `streak = streak + 1`; otherwise the streak resets to `1` (not `0` — completing today's quest always counts as at least a 1-day streak).

`ChoreService::STREAK_BONUSES` maps milestone day → dollar bonus for **one lap** of the track:

```php
3 => 1, 5 => 3, 7 => 5, 14 => 15, 30 => 40
```

### The track laps — never price a day off STREAK_BONUSES directly

The chests repeat every `STREAK_CYCLE_DAYS` (30) rather than stopping at the last key. Day 30 used to be a dead end, which is the worst possible moment for the app to go quiet on a kid.

**`streakBonusOn(int $day): ?int` is the only correct way to price a milestone day.** `STREAK_BONUSES[$day]` is right only on the first lap and silently misses after it — that bug pays a day-33 chest nothing.

Every lap after the first pays `STREAK_REPEAT_MULTIPLIER` (2) times the base, **flat, not compounding**: day 33, day 63 and day 333 all pay $2; day 60, day 90 and day 360 all pay $80. Compounding is the obvious reading of "bigger each lap" and it is a money bug — points are backed by `points_per_dollar`, so doubling per lap reaches $81,920 on a single chest inside a year.

A day landing exactly on a boundary belongs to the lap *behind* it: day 30 closes lap 1 and pays the base $40, not $80.

Milestone days stay absolute across laps (33, 35, 60…). That is what lets `streak_milestone_paid_through` stay a plain high-water mark, and why `refreshStreak()` walks day by day up from that mark instead of iterating a fixed map.

`streakTrackFor(Profile)` returns `['lap' => int, 'milestones' => [['day', 'dollars', 'points', 'reached'], …]]` — the five chests of the lap the kid is on, which is all the Quests page ever draws. The lap turns over when the closing chest is **opened**, not when the streak ticks past the boundary: swapping the track to days 33-60 while the day-30 chest sits unopened would replace the reward out from under the moment that earned it.

If a day is a milestone, the dollar amount is converted to points (`$bonusDollars * $household->points_per_dollar`) and credited **immediately** via `LedgerService::record()` (see [[points-ledger]]) — the points land in the balance right away, they are not held back pending the chest animation.

## The chest is a reveal gate, not a payment gate

Only the *visual reveal* is deferred: `pending_streak_chest` is set to the milestone day, and stays set until the kid calls `openStreakChest()` client-side, which clears the flag and returns `['day' => ..., 'dollars' => ...]` for the celebration UI. The points were already spent into the ledger the moment the milestone was hit — `openStreakChest()` never touches points, only the reveal flag. If a kid never opens the chest, the points are still theirs; only the animation is pending.

`nextStreakMilestone(Profile): int` returns the smallest milestone day still ahead of the profile's current streak — used for "X days to your next bonus" UI copy. It is **non-nullable**: the track laps, so there is always another chest within 30 days. It used to return null past day 30, and the UI still carries the shape of that (an "all unlocked" branch would now be dead code — don't add one back). There's no interpolation between milestones; days between keys (e.g. day 4, day 10) earn no bonus.

## The cached streak expires on its own

`profiles.streak` is a cache, and `refreshStreak()` only runs on approval. That alone left a kid staring at yesterday's number the morning after a miss — and a repair bought at that point stapled the dead run onto the new one.

`syncStreak(Profile)` is the read-side half, and it is **O(1) on purpose**: a live chain always ends on today or yesterday, so if neither day counts the streak drops to `0` without walking anything back. It never touches `streak_milestone_paid_through`.

It runs from the `sync-streak` middleware on the whole `kid` route group (`App\Http\Middleware\SyncStreak`), and again inside `kid/quests.blade.php`'s `with()` — a Livewire round trip posts to its own endpoint and never passes back through route middleware, and Quests is the page a kid is most likely sitting on when the household day rolls over.

## Streak restore

The Bonus Shop's Streak Restore perk writes a `streak_repairs` row for the missed day. `questApprovedOn()` treats a repaired date exactly like an approved one, so the existing walk-back recompute needs no special casing.

`repairableStreakDate()` only offers yesterday, and only when the day before it counted — repairing a day with nothing behind it would just manufacture a one-day streak rather than saving a real run. Two or more missed days therefore can't be bought back at all.

**The window closes the moment today's quest is cleared.** `repairableStreakDate()` returns null once `isQuestDoneToday()` is true: clearing today starts a fresh chain of one, and buying the broken day back there would splice a finished run onto it. `PerkInventoryService::streakRestoreReason()` says exactly that instead of falling back to "no broken streak to fix", which reads as a bug to a kid who knows they just broke one.

`repairPreview(Profile)` returns `['date' => Carbon, 'restoresTo' => int]` — the day a restore buys back and the streak it would leave behind — so the Quests page's "Streak Rescue" card can quote the number before a perk is spent on it.

**`profiles.streak_milestone_paid_through` is a high-water mark, and it must stay one.** `refreshStreak()` gates payouts on it rather than on the live `streak` value. Gating on the live value was a genuine exploit: let a streak lapse (it recomputes down), buy a repair, and every milestone pays a second time — at day 30 that's $40 for a 5-ticket purchase.

## Badge tie-ins

`streak_3`, `streak_7`, `streak_14` badges are evaluated against `profile->streak` directly in `BadgeService::evaluate()` — see [[badges]]. They key off the raw streak counter, not off whether that specific claim was a milestone-bonus day.
