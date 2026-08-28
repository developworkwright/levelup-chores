---
name: streaks
description: This skill should be used when the user asks to change streak counting, streak bonus amounts, the streak chest reveal, or mentions "streakDayEarnedOn", "STREAK_BONUSES", "pending_streak_chest", or "openStreakChest".
---

# Streaks

Covers streak counting and milestone bonuses, owned by `app/Services/StreakService.php`.

It lived on `ChoreService` for as long as the streak was a property of the daily quest. Once any approved chore started earning the day, nothing in it reached back into chores, quests or the board any more — it needs `HouseholdClock`, three tables and `LedgerService`, and that is all — so it moved out. **The dependency runs one way**: `ChoreService::approve()` calls `StreakService::recordApproval()`, never the reverse. Keep it that way; a call back into `ChoreService` from here would create the cycle the split exists to avoid.

## Core files

- `app/Services/StreakService.php` — `streakDayEarnedOn()`, `streakDaySecuredToday()`, `earnedDaysBetween()`, `recordApproval()`, `syncStreak()`, `repairStreak()`, `openStreakChest()`, `nextStreakMilestone()`, plus the private `refreshStreak()`, `currentStreak()` and `walkBackFrom()`.
- `App\Models\Profile` — `streak` (int) and `pending_streak_chest` (nullable int, the milestone day awaiting reveal) columns.
- `resources/views/pages/kid/quests.blade.php` — chest UI.
- Tests: `ChoreStreakTest` is the one that pins **what earns a day**; `StreakCycleTest`, `StreakDecayTest` and `StreakRestoreOfferTest` cover the track, decay and repairs.

## Mechanics

### Any approved chore earns the day

**`streakDayEarnedOn(Profile, Carbon): bool` is the single chokepoint.** Every walk — `currentStreak()`, `runLengthOn()`, `rescuedNightsInRun()`, `repairPreview()`, `syncStreak()`, and the Arena's `brokeAtLastRollover()` — goes through it, so the rule for what counts lives in exactly one place. It returns true when the day has a `streak_repairs` row, a `streak_rescues` row, or **any** `ChoreCompletion` for that profile with status `Approved`.

The main quest has **no special standing**. It used to: the walk looked up that day's `DailyQuest` and required an approved completion of *that specific chore*, so a kid could clear six side quests and still lose their run overnight. The quest keeps its pull through the chest, the bold card and the charm; it no longer holds the streak hostage.

Two things that follow and are easy to break:

- **The day is keyed on `submitted_at`, not `decided_at`.** The day belongs to the kid who did the work, not to the evening a parent got round to signing it off. A chore submitted at bedtime and approved over breakfast still counts for the night it was done.
- **A day with no `DailyQuest` row at all can still count.** The old code returned false early when the quest lookup missed; that guard is gone, and removing it is the whole point.

### `streakDaySecuredToday()` is the generous twin

Same question for *today*, but a **`Pending`** completion also counts. The kid has done their part, and a screen that shows them at risk over a parent's response time is blaming them for somebody else's inbox.

Use it for anything kid-facing — the Arena's safe/at-risk lanes, nudge and rescue refusals, the repair window, the streak card's copy. Use `streakDayEarnedOn()` for the walk-back itself: letting a pending claim into the run would prop up a chain nobody ever signed off.

### One query window, not one per day

Every walk goes through `earnedDaysBetween($profile, $from, $to)`, which returns the earned days in a window as a `Y-m-d` set. `walkBackFrom()` then counts backwards **in memory**. That is the shape to preserve: asking day by day meant three queries per day walked, so a single approval on a 60-day run cost 622 and a parent clearing a backlog paid it per chore. It is now 44, flat, however long the run.

Days are bucketed in PHP, not SQL — the boundary hour belongs to the household and `HouseholdClock::dayFor()` is what knows how it combines with the timezone. And the date columns are filtered with **`whereDate`, never `whereBetween`** on raw strings: Laravel writes a `date` column through the model's datetime format, so the stored value carries a `00:00:00` and `'2026-05-01 00:00:00' BETWEEN '2026-05-01' AND '2026-05-01'` is false. That silently dropped every repair and rescue when it was written the obvious way.

### Recompute, never increment

`refreshStreak()` sets `streak = currentStreak()` rather than incrementing: a parent clearing a backlog can approve days in any order and every path has to land on the same number.

`ChoreService::approve()` offers **every** approval to `recordApproval()`, which recomputes only when the completion's day was not already earned — otherwise the walk is guaranteed to land on the number already stored. That guard is safe precisely because the first approval of any day always recomputes and nothing else raises a streak (`syncStreak()` only ever drops one). Milestone payouts are gated on the `streak_milestone_paid_through` high-water mark on top of that, so a recompute can never double-pay.

`StreakService::STREAK_BONUSES` maps milestone day → dollar bonus for **one lap** of the track:

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

The Bonus Shop's Streak Restore perk writes a `streak_repairs` row for the missed day. `streakDayEarnedOn()` treats a repaired date exactly like an approved one, so the existing walk-back recompute needs no special casing.

`repairableStreakDate()` only offers yesterday, and only when the day before it counted — repairing a day with nothing behind it would just manufacture a one-day streak rather than saving a real run. Two or more missed days therefore can't be bought back at all.

**The window closes the moment today is secured.** `repairableStreakDate()` returns null once `streakDaySecuredToday()` is true — any chore claimed or approved today, not just the quest: today starts a fresh chain of one, and buying the broken day back there would splice a finished run onto it. `PerkInventoryService::streakRestoreReason()` says exactly that instead of falling back to "no broken streak to fix", which reads as a bug to a kid who knows they just broke one.

`repairPreview(Profile)` returns `['date' => Carbon, 'restoresTo' => int]` — the day a restore buys back and the streak it would leave behind — so the Quests page's "Streak Rescue" card can quote the number before a perk is spent on it.

**`profiles.streak_milestone_paid_through` is a high-water mark, and it must stay one.** `refreshStreak()` gates payouts on it rather than on the live `streak` value. Gating on the live value was a genuine exploit: let a streak lapse (it recomputes down), buy a repair, and every milestone pays a second time — at day 30 that's $40 for a 5-ticket purchase.

## Badge tie-ins

`streak_3`, `streak_7`, `streak_14` badges are evaluated against `profile->streak` directly in `BadgeService::evaluate()` — see [[badges]]. They key off the raw streak counter, not off whether that specific claim was a milestone-bonus day.
