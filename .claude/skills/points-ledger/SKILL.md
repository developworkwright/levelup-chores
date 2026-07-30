---
name: points-ledger
description: This skill should be used when the user asks to change how points/balances are earned, spent, or adjusted, or mentions "LedgerService", "LedgerEntry", "LedgerKind", the parent Activity feed, or "profile.points" drift.
---

# Points Ledger

Covers the append-only source of truth for every balance change, owned by `app/Services/LedgerService.php`. This is the foundational piece [[chores-and-quests]], [[streaks]], [[loot-shop]], and [[badges]] all sit on top of.

## Core files

- `app/Services/LedgerService.php`
- `app/Models/LedgerEntry.php`
- `app/Enums/LedgerKind.php`
- `resources/views/pages/parent/activity.blade.php` — reads `LedgerEntry` rows directly as the activity feed; there is no separate "activity log" table.

## The one invariant that matters

`Profile::points` is a **cached** value. `LedgerEntry` rows are the actual source of truth. `LedgerService::record()` is the only place allowed to change `profile->points` — it inserts the `LedgerEntry` and updates `profile->points` together inside a single `DB::transaction()`, so the cache can never drift from the ledger.

**Never write `$profile->points += $x; $profile->save();` anywhere else in the codebase.** Every earn, spend, cash-in/cash-out, or manual parent adjustment must go through `LedgerService::record()`. If a new feature needs to move points, call this service — do not touch the column directly, even for "just this one small case."

## Signature

```php
record(Household $household, ?Profile $profile, LedgerKind $kind, int $amount, string $description, ?Model $related = null): LedgerEntry
```

- `$amount` is **signed** — positive for earns/credits, negative for spends. `profile->points` is floored at `0` via `max(0, $profile->points + $amount)`, so it can never go negative even if a caller passes a spend larger than the balance (callers like `StoreService::redeem()` are still expected to pre-check affordability themselves — the floor is a safety net, not a substitute for that check).
- `$profile` is nullable — pass `null` for household-level entries that aren't attributed to one kid (rare; most entries are per-profile).
- `$related` takes any `Model` and stores it as a polymorphic reference (`related_type`/`related_id`) via `getMorphClass()`/`getKey()` — pass the `ChoreCompletion`, `StoreItem`, etc. that caused the entry so the Activity feed can link back to it. Omit only when there's genuinely no source record (e.g. a manual parent point adjustment).

## LedgerKind values

Check `app/Enums/LedgerKind.php` for the current case list (earn, spend, cash_in, cash_out, adjustment at last count) before adding a new one — prefer reusing an existing kind over adding a new case unless the Activity feed genuinely needs to distinguish it.
