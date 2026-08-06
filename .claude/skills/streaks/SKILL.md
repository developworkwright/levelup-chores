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

`ChoreService::STREAK_BONUSES` maps milestone day → dollar bonus:

```php
3 => 1, 5 => 3, 7 => 5, 14 => 15, 30 => 40
```

If the new streak value is an exact key in that array, the dollar amount is converted to points (`$bonusDollars * $household->points_per_dollar`) and credited **immediately** via `LedgerService::record()` (see [[points-ledger]]) — the points land in the balance right away, they are not held back pending the chest animation.

## The chest is a reveal gate, not a payment gate

Only the *visual reveal* is deferred: `pending_streak_chest` is set to the milestone day, and stays set until the kid calls `openStreakChest()` client-side, which clears the flag and returns `['day' => ..., 'dollars' => ...]` for the celebration UI. The points were already spent into the ledger the moment the milestone was hit — `openStreakChest()` never touches points, only the reveal flag. If a kid never opens the chest, the points are still theirs; only the animation is pending.

`nextStreakMilestone(Profile)` returns the smallest key in `STREAK_BONUSES` still greater than the profile's current streak, or `null` past day 30 — used for "X days to your next bonus" UI copy. There's no interpolation between milestones; days between keys (e.g. day 4, day 10) earn no bonus.

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
