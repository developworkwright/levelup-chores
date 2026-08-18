---
name: loot-shop
description: This skill should be used when the user asks to change reward redemption, the loot shop catalog, or mentions "StoreService", "redeem", "Redemption", "fulfill", "InsufficientPointsException", or the Loot Shop pages (kid or parent admin).
---

# Loot Shop

Covers the reward catalog and redemption queue, owned by `app/Services/StoreService.php`.

## Core files

- `app/Services/StoreService.php`
- `app/Models/{StoreItem,Redemption}.php`
- `app/Enums/RedemptionStatus.php`
- `app/Exceptions/InsufficientPointsException.php`, `app/Exceptions/LevelTooLowException.php`
- `resources/views/pages/kid/loot.blade.php` (catalog + redeem) and `resources/views/pages/parent/loot.blade.php` (catalog admin — inline edit, add reward, quick-idea presets).

## Redemption is a two-step queue, not instant deduction

`redeem(Profile, StoreItem)` deducts points **at request time**, not at fulfillment time. This is deliberate: it prevents a kid from firing off several redemption requests that together exceed their balance while parent approval is pending. If `profile->points < item->cost`, it throws `InsufficientPointsException` (constructed with the shortfall amount, `item->cost - profile->points`) — no partial/negative-balance path exists.

## Rewards can be gated by level

`store_items.min_level` (0 = no gate) locks a reward until the kid reaches that level, checked by `StoreItem::isLockedFor()` and thrown as `LevelTooLowException` from `redeem()` **before** the points check — a kid who can't have the thing yet must not be told they're merely short of points for it. Parents step the gate a rank at a time from the loot admin.

Locked rewards stay on the shelf, dimmed and labelled with the level and rank that opens them. Never filter them out: a reward a kid can see and can't have yet is the whole point of the gate, and it's the main thing making levels worth caring about. Saving toward a locked reward is allowed for the same reason — the goal card just says "saved up, unlocks at LVL n" instead of offering a button that would refuse. See [[xp-and-tickets]] for the curve and ranks.

The actual deduction + `Redemption::create(status: Pending)` happen together inside one `DB::transaction()` via `StoreService` → `LedgerService::record()` (see [[points-ledger]]) with a negative amount, so a request can never partially deduct without a matching redemption row, or vice versa.

`fulfill(Redemption, Profile $approver)` is purely a parent bookkeeping step — it sets `status = Fulfilled`, `fulfilled_at`, `fulfilled_by_profile_id`. **It does not move any points.** All the balance impact already happened at request time; fulfillment just marks "the parent physically handed over the reward."

`redeem()` sends a `ParentApprovalNeeded` notification to every parent profile in the household, and immediately runs `BadgeService::evaluate($profile)` afterward — spend-based badges (`big_spender`, `big_saver`) can unlock the instant a redemption is requested, before it's ever fulfilled. See [[badges]].

## Where redemptions surface for approval

Redemption fulfillment lives as a second section on the parent **Approvals** tab (`resources/views/pages/parent/approvals.blade.php`), alongside chore-completion approvals — not a separate screen.
