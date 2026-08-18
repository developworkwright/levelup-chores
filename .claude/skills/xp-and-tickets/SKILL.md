---
name: xp-and-tickets
description: This skill should be used when the user asks to change XP awards, the level curve, bonus tickets, the Bonus Shop, or perks like wheel respin, quest reroll, streak restore, mystery hint, or quest charm — or mentions "TicketService", "BonusShopService", "PerkEffect", "bonus_perks", "bonus_ticket_entries", or "XP_PER_LEVEL".
---

# XP, levels and bonus tickets

Covers the progression economy: XP, levels, the ticket currency, and the Bonus Shop perks it buys.

## Core files

- `app/Services/TicketService.php` — minting, spending, the balance/entries invariant
- `app/Services/BonusShopService.php` — catalogue and buying (sells only, never applies)
- `app/Services/PerkInventoryService.php` — what a kid owns, and using it
- `app/Services/ChestService.php` — the daily chest and its loot tables
- `app/Enums/PerkEffect.php` — the set of behaviours that exist in code
- `app/Models/BonusPerk.php` (catalogue row), `OwnedPerk.php` (inventory), `DailyChest.php`
- `app/Models/Profile.php` — `XP_PER_LEVEL`, `level()`, `xpBarPercent()`
- `resources/views/pages/kid/bonus.blade.php`, `components/perk-button.blade.php`, perk section of `pages/parent/loot.blade.php`
- Tests: `BonusTicketTest`, `BonusShopTest`, `BonusPerkCatalogTest`, `PerkStreakAndHintTest`, `DailyChestTest`, `BadgeXpTest`

## The three currencies

| | Earned | Spent on |
|---|---|---|
| Points | Approved chores | Loot Shop (real rewards, parent fulfils) |
| **XP** | Chores (`ChoreService::XP_PER_CHORE`, 50), badges (`badges.xp_reward`, 50–400) | **Nothing** |
| Tickets | 1 per level crossed, **5 more when that level changes the rank**, 1 per badge, and a **boss defeat** payout | Bonus Shop perks |

A monster falling pays every kid in the household 1, the finisher 2 more, and the biggest damage dealer 2 more again — see `MonsterService::TICKETS_FOR_*`. With three tiers in rotation this is now the fastest ticket source in the app, so it's the lever to reach for if perks start feeling cheap.

**XP is never spent.** Tickets are *minted by* XP, not converted from it — that's the whole design. Any change that makes XP decrease as a result of a purchase breaks the premise.

## The curve and the ranks

The curve is **flat inside a band and steps up between them** (`Profile::LEVEL_BANDS`): 200 XP a level through 10, 350 through 20, 500 after that. At 50 XP a chore that's 4 chores a level, then 7, then 10. Flat *within* a band on purpose — a kid can be told "levels cost more from 11" and have that be the whole rule. Use `Profile::levelForXp()`, `xpToReachLevel()` and `xpToClearLevel()`; never divide by `XP_PER_LEVEL`, which is now only the first band's cost.

`App\Enums\Rank` is the title worn every 5 levels, Prowler through Doomlord, derived from the level and stored nowhere. It's what the login tiles, the header chip and the stats page lead with — the number alone never changed colour, so it never read as progress. Rank-ups pay `TicketService::PER_RANK` on top of the level's own ticket, minted in `syncLevelTickets()` off the *same* `tickets_granted_through_level` high-water mark, so a rank can't pay twice either.

**`profiles.xp_adjustment`** is XP granted by the curve change rather than earned. Steepening the curve retroactively would have taken levels back off kids who had already climbed them, so the migration banked the difference here and everyone kept their exact level and bar position. It has no source record, so `ReconcileXpCommand` adds it in as a fourth source — leave it out of any future rebuild and the conversion gets flattened.

## Two high-water marks, and why

Both exist because a value that can go **down** must not re-pay a threshold on the way back up.

- **`profiles.tickets_granted_through_level`** — XP falls when `quest:reset-today` claws back 25 per undone approval. Without the mark, a kid could hover a level boundary and mint a ticket per cycle.
- **`profiles.streak_milestone_paid_through`** — a streak recomputes downward when a day is missed. Gating milestone payouts on the *live* streak let a kid lapse, buy a Streak Restore, and collect every milestone again — $40 for a 5-ticket purchase at day 30.

Both only ever increase. If a third reward-on-threshold appears, it wants the same guard.

## Ticket balance invariant

`bonus_ticket_entries` is the source of truth; `profiles.bonus_tickets` is a cache. `TicketService::record()` writes both in one transaction — same contract `LedgerService` holds for points (see [[points-ledger]]). Never write the column directly.

## Perks: behaviour is code, everything else is data

`PerkEffect` is the registry of implemented behaviours. `bonus_perks` rows hold cost, name, description, glyph and enabled — all parent-editable without a deploy. `effect` joins the two and is seeded, never exposed in the UI.

**Adding a perk means three things in step:** a new `PerkEffect` case, a branch in `PerkInventoryService::apply()` *and* `blockedReason()`, and a catalogue row (migration for existing households, plus `PerkEffect::defaults()` which both the seeder and `HouseholdFactory` use). `BonusPerkCatalogTest` asserts cases and rows match exactly.

`HouseholdFactory` seeds the catalogue on create — the migration can only seed households that already exist, and tests migrate an empty database.

## Perks are owned, not instant

Buying grants an `owned_perks` row; the effect fires when the kid chooses, via `PerkInventoryService::use()`. That's why the shop no longer checks whether a perk is usable — buying a Streak Restore before anything breaks is the point of holding one.

`owned_perks.effect` stores the effect, **not** a `bonus_perks` id: a parent disabling or repricing a perk must not invalidate one already owned.

`blockedReason()` is the single source for both the refusal thrown by `use()` and the greyed-out explanation in the UI, so they can't disagree. A perk that fails to apply is **not** consumed.

## Daily chest

`ChestService` gives one chest per kid per household-day, recorded in `daily_chests`. Everyone gets one regardless of effort — clearing the day's quest doesn't unlock it, it swaps `BASE_TABLE` for `BOOSTED_TABLE`, so work shifts the odds rather than the entitlement.

Rewards are tickets, points, XP, or a perk straight into the inventory. A perk roll in a household with every perk disabled falls back to tickets rather than handing over an empty chest.

`isAvailable()` gates on nothing but "has today's chest already been opened" — a pending streak chest is irrelevant to it. The two chests are independent, and on a milestone day both stack on the Quests page: the daily one turns up regardless, the streak one is earned, and the milestone adds to the day rather than spending what was already coming. The daily chest renders as **its own block**, not as a branch of the streak-chest conditional, so the milestone track stays visible.

## The perks themselves

**No parent approval anywhere in this flow.** That's the line against the Loot Shop (see [[loot-shop]]): loot is a promise a parent has to keep, a perk is a rule bending itself.

| Perk | Effect |
|---|---|
| Wheel respin | Clears today's `Spin` so the wheel is available again |
| Quest reroll | Reassigns `DailyQuest` and clears `revealed_at` so the chest replays — see [[chores-and-quests]] |
| Streak restore | Writes `streak_repairs` for the missed day — see [[streaks]] |
| Mystery hint | Reveals the chore's parent-written `hint`, per-kid via `mystery_hint_purchases` |
| Day Off | Writes `quest_skips` for today: the board opens without the quest, and the streak counts the day as kept. **Once per household week** |
| Name a Monster | Sets `monsters.nickname` on a live monster nobody has named yet — shown everywhere via `Monster::displayName()` |

Hints are deliberately per-kid: one sibling paying must not clue in the rest. Note the mystery chore's own selection now favours chores that *have* a hint, so this perk always has something to sell.

**Day Off is the dearest perk (8) on purpose**, and dearer than Streak Restore (5): a restore buys back one day already lost, while this keeps the chain *and* opens the board with no work done. It pays nothing — skipping the quest skips its points — and it is capped at **one per household week**, because streak milestones pay real money (up to $40 at day 30, doubled on later laps) and an uncapped skip lets a kid climb that ladder having done nothing. `questApprovedOn()` reads `quest_skips` alongside `streak_repairs`, since both answer "does this day count without the quest being done".

The cap lives in **both** `ChoreService::nextQuestSkipDate()` (enforced inside `skipQuestToday()`) and `blockedReason()`. The refusal is a date — "Back on Mon 17 Aug" — not a flat no: a kid who can read when it returns saves it for a day they need.

**`PerkInventoryService::use()` takes an optional `array $input`.** Only Name a Monster reads it (`monster_id`, `name`); every other effect ignores it. That perk is also the one whose tap on the Bonus page opens a form rather than spending immediately — nothing is consumed until a name is submitted, since a half-typed name is a failure to apply and a failed perk stays in the pocket. A parent can strip a name from the Monster Deck, which is the veto that makes it safe to sell.
