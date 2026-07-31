---
name: xp-and-tickets
description: This skill should be used when the user asks to change XP awards, the level curve, bonus tickets, the Bonus Shop, or perks like wheel respin, quest reroll, streak restore, or mystery hint — or mentions "TicketService", "BonusShopService", "PerkEffect", "bonus_perks", "bonus_ticket_entries", or "XP_PER_LEVEL".
---

# XP, levels and bonus tickets

Covers the progression economy: XP, levels, the ticket currency, and the Bonus Shop perks it buys.

## Core files

- `app/Services/TicketService.php` — minting, spending, the balance/entries invariant
- `app/Services/BonusShopService.php` — catalogue, purchase flow, per-perk effects
- `app/Enums/PerkEffect.php` — the set of behaviours that exist in code
- `app/Models/BonusPerk.php` — the editable catalogue row
- `app/Models/Profile.php` — `XP_PER_LEVEL`, `level()`, `xpBarPercent()`
- `resources/views/pages/kid/bonus.blade.php`, perk section of `pages/parent/loot.blade.php`
- Tests: `BonusTicketTest`, `BonusShopTest`, `BonusPerkCatalogTest`, `PerkStreakAndHintTest`, `BadgeXpTest`

## The three currencies

| | Earned | Spent on |
|---|---|---|
| Points | Approved chores | Loot Shop (real rewards, parent fulfils) |
| **XP** | Chores (+25), badges (`badges.xp_reward`, 50–400) | **Nothing** |
| Tickets | 1 per level crossed, 1 per badge | Bonus Shop perks |

**XP is never spent.** Tickets are *minted by* XP, not converted from it — that's the whole design. Any change that makes XP decrease as a result of a purchase breaks the premise.

Level curve is flat: `Profile::XP_PER_LEVEL = 200`. Flat on purpose — "200 XP is a level" is something a young kid can hold in their head.

## Two high-water marks, and why

Both exist because a value that can go **down** must not re-pay a threshold on the way back up.

- **`profiles.tickets_granted_through_level`** — XP falls when `quest:reset-today` claws back 25 per undone approval. Without the mark, a kid could hover a level boundary and mint a ticket per cycle.
- **`profiles.streak_milestone_paid_through`** — a streak recomputes downward when a day is missed. Gating milestone payouts on the *live* streak let a kid lapse, buy a Streak Restore, and collect every milestone again — $40 for a 5-ticket purchase at day 30.

Both only ever increase. If a third reward-on-threshold appears, it wants the same guard.

## Ticket balance invariant

`bonus_ticket_entries` is the source of truth; `profiles.bonus_tickets` is a cache. `TicketService::record()` writes both in one transaction — same contract `LedgerService` holds for points (see [[points-ledger]]). Never write the column directly.

## Perks: behaviour is code, everything else is data

`PerkEffect` is the registry of implemented behaviours. `bonus_perks` rows hold cost, name, description, glyph and enabled — all parent-editable without a deploy. `effect` joins the two and is seeded, never exposed in the UI.

**Adding a perk means three things in step:** a new `PerkEffect` case, a branch in `BonusShopService::apply()` *and* `unusableReason()`, and a catalogue row (migration for existing households, plus `PerkEffect::defaults()` which both the seeder and `HouseholdFactory` use). `BonusPerkCatalogTest` asserts cases and rows match exactly.

`HouseholdFactory` seeds the catalogue on create — the migration can only seed households that already exist, and tests migrate an empty database.

## Purchase rules

- **Apply first, charge second.** A failed effect leaves the kid's tickets alone.
- **Instant, no approval.** That's the line against the Loot Shop (see [[loot-shop]]): loot is a promise a parent keeps, a perk is a rule bending itself.
- `unusableReason()` powers both the refusal *and* the greyed-out explanation in the shop, so they can't disagree.

Current perks: wheel respin (clears today's `Spin`), quest reroll (reassigns `DailyQuest` and clears `revealed_at` so the chest replays — see [[chores-and-quests]]), streak restore (writes `streak_repairs`, see [[streaks]]), mystery hint (reveals the chore's parent-written `hint`, per-kid via `mystery_hint_purchases`).
