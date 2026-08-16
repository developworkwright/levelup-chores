---
name: chores-and-quests
description: This skill should be used when the user asks to change chore board behavior, daily-quest assignment, chore cadence/cooldown rules, the mystery chore, or mentions "ChoreService", "boardFor", "stateFor", "daily_quests", "daily_mysteries", or the kid Quests page / parent Chores admin page.
---

# Chores and Quests

Covers the chore board, the daily quest, and the automatic mystery chore — all owned by `app/Services/ChoreService.php`.

## Core files

- `app/Services/ChoreService.php` — all logic below lives here.
- `app/Models/{Chore,ChoreCompletion,DailyQuest,DailyMystery}.php`
- `app/Enums/{ChoreCadence,CompletionStatus}.php`
- `resources/views/pages/kid/quests.blade.php` — kid-facing board, quest reveal, mystery reveal.
- `resources/views/pages/parent/chores.blade.php` — chore CRUD (points, cadence, min age, quest eligibility). No mystery controls here — mystery is fully automatic.
- Tests: `tests/Feature/ChoreFlowTest.php`, `tests/Feature/MysteryChoreTest.php`, `tests/Feature/HouseholdCooldownTest.php`, `tests/Feature/BlockedQuestTest.php`, `tests/Feature/OneTimeChoreTest.php`, `tests/Feature/ChoreDeadlineTest.php`.

## Cooldowns are household-wide

**A chore's cooldown belongs to the family, not the kid.** If one kid claims "Load the dishwasher," it goes `'done'` on *everyone's* board until the cadence boundary passes. The dishes don't need doing three times because there are three children.

`claimantFor(Chore)` is the single source of truth: it returns the completion currently holding a chore — anyone's pending claim, or anyone's approved claim inside the cadence window — or null. A rejected claim clears it, reopening the chore for the whole household.

This is the rule that most invites accidental regression. Any new "has this been done" check must go through `claimantFor()` rather than filtering completions by `profile_id`.

## Daily quest

**The quest is chosen, not assigned.** `questFor(Profile)` lazily deals a *hand* of up to `HAND_SIZE` (3) `quest_eligible` chores appropriate for the profile's age, once per `HouseholdClock::for($household)->today()`, persisted in `daily_quests` keyed by `(profile_id, quest_date)`. Repeated calls the same household-day return the same row — never re-deal on refresh, **except** when the whole hand is blocked (below).

Hands are dealt per kid and independently, so two kids can draw the same chore. The deal prefers chores nobody has claimed yet, but falls back to the full eligible pool rather than failing — a kid logging in after the family cleared the board still gets a quest. Only a household with zero eligible chores throws.

### Three columns, three states

- `offered_chore_ids` — the hand, cheapest first. Null on rows written before this existed; **always read it through `DailyQuest::offeredChoreIds()`**, which reads null as a one-card hand of `chore_id`.
- `chore_id` — **a placeholder until the pick, the quest afterwards.** It is deliberately never null, so every existing consumer of `$quest->chore` keeps working. Before the pick it holds the first card and means nothing.
- `dealt_at` — the chest has been opened and the cards are on the table.
- `revealed_at` — a card has been taken. This keeps its exact old meaning of "the kid knows what their quest is": it gates the board and it starts the `speed_runner` timer. `DailyQuest::isPicked()` is the readable form.

Two stamps rather than one because there is a refresh-shaped gap between opening the chest and choosing: without `dealt_at` the chest re-closes on any re-render and replays a 2.6s animation the kid already sat through.

### The hand is spread, not drawn

`dealHand()` sorts the candidate pool by points, `split()`s it into three bands and takes one from each. Three independent random draws routinely produce three near-identical chores, which is a choice in name only. The spread is what guarantees the row always reads left-to-right as "quick and cheap" through to "big and paid for".

The top card is the **bold card** and pays `BOLD_CARD_BONUS_PERCENT` (50%) of its own points on top. Without it the rational move is to take the cheap card every morning, and a choice with one right answer stops being a choice by about day three. Three things about the bonus:

- It is computed off **base** points and added *after* any wheel multiplier, never multiplied by it — a 3x spin on a bold card would otherwise pay 4.5x face value.
- `boldChoreIn(hand)` returns **null when every card pays the same**, which is the common case in a household whose chores are flat-rated. It degrades to a plain three-way choice rather than paying a bonus for a coin flip.
- It rides through `claim()`'s new `$bonusPoints` parameter, resolved by `claimQuest()` **before** it stamps `completed_at` — `boldBonusForQuest()` goes back through `questFor()`, and a completed quest is one `rerollIfUnavailable()` stops rescuing.

### Picking, and the two cards that burn

`chooseQuest(Profile, int $choreId)` validates the card is in today's hand and still takeable, then sets `chore_id` and stamps `revealed_at`. It returns **null** rather than throwing when the card isn't takeable — a stale tab, or a sibling claiming that chore between the deal and the tap — which is how the page knows to say "that one just went" instead of silently no-oping.

`revealQuest(Profile)` still exists and still means "take whatever card the quest is sitting on". Kids never reach it; it is the path for a one-card hand and for tests that only need a quest decided.

**The burned cards stay claimable as side quests.** The burn is drama, not a penalty — choosing costs the household nothing in available work. This is why `boardFor()` rejects `possibleQuestChoreIds()` rather than `$quest->chore_id`: the *whole hand* comes off the board while the kid is deciding (otherwise two of the three cards sit below as claimable side quests, duplicated on screen and takeable out from under them), and the two they don't take drop back in the moment the pick lands.

`SpinService::eligibleChoresFor()` clears the same set for the same reason — any card might turn out to be the quest, so the wheel can't land on one. Note this makes an empty wheel more likely in a small household; `spin()` throws on an empty pool and the wheel page guards in front of the call rather than around it.

If `household.require_quest_first` is true, every other chore on the board is `'locked'` until `quest.completed_at` is set (`claimQuest()`). Claiming the quest also calls `claim()` for the quest's chore, so it flows through the same points/mystery path as any other chore. The streak moves at parent approval, not here (see [[streaks]]).

### A sent-back quest reopens

`sendBack()` clears `completed_at` when the rejected completion is the one that cleared that day's quest. Redoing the work is the entire point of sending it back, and previously the stamp survived rejection — a side quest reopened but the main one, the only one that feeds the streak, dead-ended on a disabled "Sent back" button.

Two things this deliberately does *not* touch:

- **`revealed_at` stays set.** They already know which chore it is; replaying the chest to redo work they were just told off for reads as mockery.
- **Gating comes back**, since the quest genuinely isn't done. Side-quest claims already submitted stay pending; only new ones re-lock.

The hero's CTA stays live and reads "Mark it done again", with a separate `Sent back` marker carrying the bad news — a dead button explains nothing. `questSentBack` comes off the same `$questCompletion` lookup as pending/approved, which is now scoped to **today's household day** rather than to `completed_at` (that stamp is gone by then).

### Auto-reroll of a blocked quest

Because cooldowns are household-wide, a sibling can finish the chore that was handed to you as today's quest. That would dead-end the kid's day: no quest completion, no streak day, and with `require_quest_first` on, a board that never unlocks.

**Before the pick, the hand is what matters, not `chore_id`.** `rerollIfUnavailable()` branches on `isPicked()` first: an unpicked quest is only stuck when `handIsDead()` — every card claimed, expired or deleted — and re-deals then. A sibling taking the placeholder card leaves two perfectly good cards on the table, and re-dealing over a hand the kid may already be reading would be worse than the problem. Everything below applies to a quest that has been picked.

`questFor()` runs `rerollIfUnavailable()` on every read. If the quest is uncompleted and `claimantFor()` returns a completion belonging to **someone else**, the quest silently moves to a different unclaimed chore. It's invisible to the kid beyond the chore changing — no prompt, no cost. An expired quest chore (below) rerolls the same way, and that check runs **before** the Unlimited shortcut — a deadline closes an unlimited chore just as firmly as any other.

Four conditions guard it, each protecting something real:

- **Completed quests are never rerolled.** After `claimQuest()` the chore reads as claimed-by-them, which would otherwise look identical to blocked — rerolling would erase a finished quest and cost a streak day.
- **The kid's own claim doesn't count.** Their pending claim is progress, not a blockage.
- **`ChoreCadence::Unlimited` quest chores are never rerolled.** They don't lock, so a sibling doing one blocks nobody.
- **It only swaps onto unclaimed chores.** Landing on another blocked chore leaves the kid exactly as stuck; if nothing is free, the blocked quest is kept so the page still renders.

`rerollQuest()` (the ticket-priced perk and the parent's manual button) shares the same private `assignDifferentChore()`, so both paths avoid claimed chores identically. **A reroll deals a whole new hand**, excluding the chore the quest was on, and clears *both* `dealt_at` and `revealed_at` — handing back a single replacement chore would make the reroll the one path that takes the choice away, which is precisely the thing being bought back. When nothing is free, `rerollQuest()` returns null, which is how the perk knows to refuse and keep the kid's ticket.

## Chore board state machine

`boardFor(Profile)` returns each appropriate chore as `['chore' => Chore, 'state' => string, 'takenBy' => ?Profile, 'closesAt' => ?Carbon]`, where `takenBy` is the claimant when it isn't this kid and `closesAt` is a live deadline. States:

- `'locked'` — quest not done yet and `require_quest_first` is on.
- `'pending'` — **this** profile holds the in-flight claim.
- `'done'` — someone in the household holds it: another kid's pending claim, or anyone's approved claim inside the cadence boundary (`ChoreCadence::Weekly` → last 7 household-days; otherwise last 1).
- `'expired'` — a parent's deadline has passed (below). Unclaimable for the rest of the household day.
- `'ready'` — claimable now. `ChoreCadence::Unlimited` chores are always `'ready'` short of a deadline — no cooldown, ever.

### One-time chores

`ChoreCadence::Once` is the cadence with no clock. It's up for grabs until someone claims it, then it leaves the board for the whole household until a parent puts it back — `chores.used_at` is that switch, stamped by `claim()` and cleared by `sendBack()` or `reactivate()`.

Everything else falls out of that one column:

- **`claimantFor()` uses `used_at` as its boundary** instead of a cadence window, which is why a spent one-time chore doesn't reopen overnight. An unstamped chore returns null immediately — clearing the stamp is how a rejection or a reactivation releases the chore without reaching back to rewrite old completions.
- **`boardFor()` drops spent ones entirely**, rather than showing them `'done'` like a daily chore on cooldown. The one exception is the kid whose claim is still pending: their card stays so the tap visibly registered.
- **They sort to the top of the board.** They're the only chores with a real deadline, and the card carries a ⚡ One-time flag so the position reads as urgency rather than arbitrary ordering.
- **`Chore::scopeAvailable()`** is the SQL form, used by `questCandidates()` and `BadgeService::clearedWholeBoardToday()`. A spent one-time chore must never be handed out as a quest — including as `questFor()`'s fallback pick, since unlike a blocked daily quest it would never unblock. Nor may it count toward a perfect board, or a sibling taking one would make the badge unwinnable.
- **`claim()` calls `unsetRelation('chores')`** on the household. This is the only place a chore is edited mid-request, so anything already holding `$household->chores` holds the pre-claim copy — without it the re-render straight after a claim reads the chore as still up for grabs.
- **`setCadence()` clears `used_at` when moving off Once**, so a chore parked on Daily doesn't arrive already-used when someone flips it back.

`ChoreCadence` carries its own display strings (`label()`, `summary()`, `kidLabel()`, `next()`) — a fourth case made the hardcoded label maps in both views a place for the cadences to silently drift.

### Deadlines ("closes soon")

A parent can put any chore on a clock from the Chores admin — "beat me to it before dinner" — via `chores.expires_at`. Kids get a live countdown on the board; once it passes the chore is `'expired'` and the job is the parent's. The point is to *offer* work the parent is about to do anyway, so the mechanic is a race, not a punishment.

- **A deadline binds only for the household day it lands in.** `Chore::hasExpiredAt($now, $dayStart)` returns false for a stamp older than `$dayStart`, so it lifts on its own overnight and nobody has to clear it — that's why there is no scheduled job and no `used_at`-style release path. `ChoreService::isExpired()` / `deadlineFor()` wrap it with the clock, exactly as `claimantFor()` does.
- **`Chore::scopeNotExpiredAt()` is the SQL twin** of `hasExpiredAt()`, kept beside it the way `scopeMatching()` and `matches()` are. Used by `questCandidates()` and `BadgeService::clearedWholeBoardToday()` — a closed chore must never be handed out as a quest (it would never reopen today, costing a streak day) nor counted toward a perfect board (which would make the badge unwinnable).
- **A claim outranks a deadline.** `stateFrom()` checks the claimant first: someone who got there before the clock ran out keeps their pending claim rather than watching it flip to "time's up".
- **`'expired'` outranks `'locked'`.** Gating hides the ordinary states behind the main quest, but "Locked" promises the chore is yours once the quest is done — the wrong thing to say about one that has already closed.
- **Closed chores stay on the board** reading "Time's up", rather than vanishing like a spent one-time chore. The countdown only teaches anything if losing it is visible.
- **`drawMysteryChore()` rejects expired chores** — hiding the bonus behind a chore nobody can claim means nobody wins it. `SpinService::eligibleChoresFor()` needs no change; it already filters on `stateFor() === 'ready'`.
- **`HouseholdClock::atTime('17:00')`** maps a wall-clock time onto the household day in progress and returns UTC (same reason `startOf()` does). A time earlier than `day_boundary_hour` belongs to the small hours at the *end* of the day — on a 4am boundary, "2:00" means tonight. It returns null for anything unparseable, so a blank input lifts the deadline rather than resolving to midnight.
- **`setDeadline()` notifies the kids** (`ChoreClosingSoon`, web push, best-effort in a try/catch like `claim()`). A countdown nobody hears about is just a chore quietly vanishing. This is the kid-facing notification hook — anything else aimed at kids should follow it and `SiblingOfferReceived`.

The countdown is `<x-chore-countdown>`, an Alpine block ticking client-side that fires **one** `$wire.$refresh()` when it reaches zero. That's deliberate and must stay: the same no-`wire:poll` reasoning below applies, and zero is the single moment the card's state actually changes.

`'pending'` vs `'done'` is the *only* place the viewing profile matters; both come off the same `claimantFor()` lookup, resolved through the shared private `stateFrom()` so `stateFor()` and `boardFor()` can't drift. `boardFor()` calls `claimantFor()` itself rather than `stateFor()`, so naming the claimant costs no extra queries — `claimantFor()` already eager-loads `profile`. There is no separate mystery-chore branch — mystery exclusivity and ordinary cooldown are now the same mechanism.

## Keeping an open board honest

Kids leave the quests page open for hours, and cooldowns are household-wide, so a board goes stale on its own. The danger is **not** a bad write — `claimChore()` re-checks `stateFor()` server-side and the quest path is covered by the reveal guard, so a stale tap can never double-claim. The danger is a kid *doing the physical work* on a chore a sibling already claimed and only finding out at submit time.

A chore locks the moment it's **claimed**, not when it's approved — `claimantFor()` counts a Pending completion — and `sendBack()` reopens it. So the two events that change the board are claim and reject; approval changes nothing about availability.

Three things address staleness, in order of how much they matter:

1. **The board names the claimant.** A taken chore reads "Taken by Nova" with the title struck through. It previously said "Back tomorrow" — indistinguishable from the kid's *own* completed chore, and the one wording that could send someone off to redo it.
2. **Refresh on focus/visibility**, via a small Alpine block on the board list calling `$wire.$refresh()`, throttled to 2s because returning to a tab fires both events. Paired with an explicit **Refresh button in the kid shell header**, sitting with the points/streak/tickets tiles it also updates — the automatic refresh is invisible, and a kid about to start a chore wants to *check*.

   The shell takes a `refreshAction` prop defaulting to `$refresh`, so all five kid tabs get the button; Quests passes `refresh-action="refreshBoard"` so it can additionally clear `boardMessage`. Anything added to the shell header that calls a component method needs that same treatment — the shell is shared, and a method that exists on only one tab breaks the other four.
3. **`boardMessage`** explains a late tap ("Nova got to Feed animals first!") — a silently no-oping button reads as broken.

**Do not add `wire:poll` here.** It was tried and removed: the production server scales to zero when idle, so a tablet left open on the quests page would hold it awake and billing indefinitely. Websockets are worse for the same reason — Reverb needs an always-on process. Refresh-on-focus costs one request at the only moment a stale board can mislead anyone: when someone looks at it.

Refreshes re-run `with()`, not `mount()` — which is what keeps the mount-snapshotted animation properties (`pendingChestDay`, `questDoneOnArrival`, `dailyChestAvailable`) stable. Anything that must survive a refresh belongs in `mount()`, never in `with()`.

One knock-on: a refresh re-runs `questFor()`, so a quest a sibling took is auto-rerolled then, clearing `revealed_at` and re-closing the quest chest. Correct, but abrupt — if that needs softening, it's the reroll that needs a notice.

Cooldown/pending boundaries always use `HouseholdClock`, never raw `now()` — the household day rolls over at `day_boundary_hour` (default 4am), not midnight.

`boardFor()` still calls `mysteryChoreFor()` once before its loop even though state no longer needs it: that call is what lazily assigns the day's mystery pick, and dropping it would leave the assignment to whichever page happened to ask first.

## Mystery chore

`mysteryChoreFor(Household)` lazily picks one household-wide "secret bonus" chore per household-day, persisted in `daily_mysteries` keyed by `(household_id, mystery_date)`. It is **fully automatic** — there is no parent toggle. Candidacy filter, applied in this order:

1. `min_age === null` only — age-gated chores are never eligible, so the youngest kid always has a fair shot.
2. `cadence !== ChoreCadence::Unlimited` — an unlimited chore is freely repeatable by everyone, which is incompatible with "first to find it wins." This was a real bug caught by a test, not a spec item — keep it if adding new cadences.
3. Not a spent one-time chore — it isn't on anyone's board to find. (A *live* one is a perfectly good pick.)
4. No existing `claimantFor()` — a chore someone has already claimed today (pending or approved-within-cooldown) can't retroactively become the mystery pick.

Among the survivors, chores with a parent-written `hint` win the draw outright; the full pool is only used when none of them has one. That keeps the Bonus Shop's mystery-hint perk sellable — it should never charge tickets for a chore nobody wrote a clue for. Practical consequence: **if only one or two chores have hints, the mystery becomes guessable**, so hints want to be written broadly rather than on a favourite few.

The pick uses genuine randomness (`Arr::random()`), matching the spin's actual result — **not** the bonus wheel's deterministic display-subset hash (see [[bonus-wheel]]); those are unrelated mechanisms.

### The bonus is won at approval, never at claim

`ChoreService::MYSTERY_BONUS_POINTS = 500` is added by **`approve()`**, via the private `awardMysteryBonus()`. It used to be folded into `points_awarded` by `claim()`, and the kid's page called the race off `claimantFor()` — which counts a *pending* claim. Between them, tapping "Mark it done" was enough to be told you'd found it, so submitting every chore on the board was a way of being handed the answer with none of the work verified. Four things follow, and none of them should be undone:

- **The winner is a column, not a lookup.** `daily_mysteries.found_by_profile_id` / `found_at`, stamped by the approval. `mysteryFinderFor(Household)` reads it; **every kid-facing "has it been found?" check must go through that**, never `claimantFor()`.
- **The day is the one the work was submitted in**, resolved with `HouseholdClock::dayFor($completion->submitted_at)` — a chore found at bedtime and approved over breakfast still wins that night's mystery. `mysteryOn()` is the lookup that never draws, precisely so an approval can't conjure a pick for a day that never had one.
- **Nothing at claim time may mention the mystery.** The kid Quests page (`claimChore`, `claimQuest`) and the Bonus Wheel's claim all dispatch ordinary "claimed!" celebrations. `PerkInventoryService::mysteryHintReason()` is the subtle one: it blocks the hint sale on a *sibling's* claim but deliberately not on the kid's own, since a label flipping on their own tap is the same leak in miniature.
- **The win is announced by the kid shell**, from `profiles.pending_mystery_celebration` (the chore's name), queued by the approval and cleared when shown — the same deferred-celebration path as `pending_goal_celebration`, because a parent's approvals screen is not one any kid is looking at.

`claim()` still calls `mysteryChoreFor()`, now purely for the assignment: the draw skips chores that already have a claimant, so a day whose first lookup happened after the claim could never pick that chore, and the kid would be racing for something they'd ruled themselves out of.

Board exclusivity is still household-wide via `claimantFor()` — the same lock every other chore uses, so the mystery needs no special-casing on the board.

`rerollMysteryChore()` lets a parent swap the pick from Kids & Points. It refuses in exactly two cases, and the difference between them matters:

- **A *pending* claim blocks it** — that kid has done the work and is one approval away from the bonus; swapping the chore out from under them takes something they've earned.
- **A recorded winner blocks it** — the payout has happened, and moving the finish line would hang a second +500 on a different chore the same day.

An **approved** claim that won nothing does *not* block it. That's where every mystery decided before the bonus moved to approval sits: the chore is on household-wide cooldown, so nobody can win today's bonus on it any more, and refusing would leave the parent staring at a dead mystery until tomorrow. The parent page reads this state as "NOBODY WON IT" — note it must test the claim's *status*, since `claimantFor()` counts an approved claim inside the cooldown and reading that as "needs approval" tells a parent to sign off work they already signed off.

Both the reroll and the daily draw go through the private `drawMysteryChore()`, so the fairness rules can't drift apart between them. A kid who already bought a hint sees the *new* chore's hint automatically, since `mysteryHintFor()` resolves against the current pick rather than storing the text.

## Searching chores

Search exists on both the parent Chores admin and the kid's side-quest board, via **two implementations of the same idea**:

- `Chore::scopeMatching($term)` — SQL, for the parent page's query
- `Chore::matches($term)` — PHP, for the kid's board, which is already an in-memory collection and shouldn't be re-queried

Both read the **`Chore::SEARCHABLE`** constant, so the field list lives in one place. **Tags are planned and belong there** — add the column to `SEARCHABLE` (or join it in for the tag relation) and both surfaces pick it up. `ChoreSearchTest` runs a set of terms through both paths and asserts they return identical results, so a change to one that doesn't reach the other fails loudly.

Three things exist for a reason and shouldn't be simplified away:

- **The SQL conditions are wrapped in their own `where()` group.** The `orWhere` inside would otherwise escape and swallow the outer `household_id` condition, leaking another family's chores. Tested.
- **`ESCAPE` is stated explicitly** via `whereRaw`. MySQL defaults the LIKE escape character to backslash; SQLite has no default. Since tests run on SQLite and production on MySQL, relying on the default means the escaping works on one engine and silently fails on the other — searching `50%` would return every chore.
- **Filtering the kid's board narrows what `boardFor()` already returned**, never re-queries. That matters: the board has already excluded today's quest and anything age-gated, and search must not reach past those.

### Gotcha: board tests and the daily quest hand

`boardFor()` excludes **every card in today's hand** — up to `HAND_SIZE` (3) chores, not one. A fixture with three eligible chores now yields an *empty* board, which is the trap that caught `test_board_excludes_chores_the_kid_is_too_young_for` when the hand landed. Either give the fixtures one `quest_eligible => true` chore to absorb the deal and mark the rest `quest_eligible => false`, or create `HAND_SIZE + n` of them and assert on `n`. Write the count as `ChoreService::HAND_SIZE + 1` rather than `4`, so changing the hand size doesn't silently empty someone's fixture.

The same arithmetic reaches `SpinService::eligibleChoresFor()`, which clears the hand too: a wheel fixture needs more than three chores or the wheel comes back empty and `spin()` throws.

### Gotcha: `assertDontSee` on a chore name

Once the mystery chore is found, the kid page names it ("Completed by Nova — Rake the leaves!"). So a render test that *approves* something and then asserts a chore has left the *board* can fail at random when that chore happens to win the day's mystery draw. Give the fixture a decoy chore with a `hint` — hinted chores win the draw outright — to pin the pick somewhere harmless. A test that only claims is safe: nothing names the mystery until an approval settles it.

### Gotcha: setting up "already completed" test fixtures

`claim()` calls `mysteryChoreFor()` internally (to make sure the day's pick exists) **before** creating the chore's own `ChoreCompletion`. In real usage this is always safe because a kid must load the Quests page first — which calls `mysteryChoreFor()` via `with()` — before any claim action can fire, so the day's pick is already locked in by the time `claim()` runs. But if a test calls `service()->claim($kid, $chore)` as its *first* mystery-related call of the day, that call can itself make (and persist) the day's random pick — possibly picking the very chore being claimed, before its completion exists to exclude it. To set up an "already completed today" precondition in a test without tripping this, create the `ChoreCompletion` directly:

```php
ChoreCompletion::create([
    'chore_id' => $chore->id,
    'profile_id' => $kid->id,
    'status' => CompletionStatus::Pending,
    'points_awarded' => $chore->points,
    'submitted_at' => now(),
]);
```

rather than routing through `service()->claim()`.

### Gotcha: Volt SFC bare class references

`quests.blade.php`'s Blade template section (below the PHP class block) cannot resolve a bare `ChoreService::MYSTERY_BONUS_POINTS` even with a top-of-file `use App\Services\ChoreService;` — Volt SFC template sections need the fully-qualified form: `\App\Services\ChoreService::MYSTERY_BONUS_POINTS`. Confirmed precedent in `resources/views/pages/parent/loot.blade.php`, which has the same pattern with `\App\Enums\AccentColor::Parent`. This only affects the template half of the file — bare references work fine inside the PHP class block.
