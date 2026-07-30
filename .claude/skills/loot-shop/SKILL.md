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
- `app/Exceptions/InsufficientPointsException.php`
- `resources/views/pages/kid/loot.blade.php` (catalog + redeem) and `resources/views/pages/parent/loot.blade.php` (catalog admin — inline edit, add reward, quick-idea presets).

## Redemption is a two-step queue, not instant deduction

`redeem(Profile, StoreItem)` deducts points **at request time**, not at fulfillment time. This is deliberate: it prevents a kid from firing off several redemption requests that together exceed their balance while parent approval is pending. If `profile->points < item->cost`, it throws `InsufficientPointsException` (constructed with the shortfall amount, `item->cost - profile->points`) — no partial/negative-balance path exists.

The actual deduction + `Redemption::create(status: Pending)` happen together inside one `DB::transaction()` via `StoreService` → `LedgerService::record()` (see [[points-ledger]]) with a negative amount, so a request can never partially deduct without a matching redemption row, or vice versa.

`fulfill(Redemption, Profile $approver)` is purely a parent bookkeeping step — it sets `status = Fulfilled`, `fulfilled_at`, `fulfilled_by_profile_id`. **It does not move any points.** All the balance impact already happened at request time; fulfillment just marks "the parent physically handed over the reward."

`redeem()` sends a `ParentApprovalNeeded` notification to every parent profile in the household, and immediately runs `BadgeService::evaluate($profile)` afterward — spend-based badges (`big_spender`, `big_saver`) can unlock the instant a redemption is requested, before it's ever fulfilled. See [[badges]].

## Where redemptions surface for approval

Redemption fulfillment lives as a second section on the parent **Approvals** tab (`resources/views/pages/parent/approvals.blade.php`), alongside chore-completion approvals — not a separate screen.
