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
- Tests: `tests/Feature/ChoreFlowTest.php`, `tests/Feature/MysteryChoreTest.php`, `tests/Feature/HouseholdCooldownTest.php`, `tests/Feature/BlockedQuestTest.php`.

## Cooldowns are household-wide

**A chore's cooldown belongs to the family, not the kid.** If one kid claims "Load the dishwasher," it goes `'done'` on *everyone's* board until the cadence boundary passes. The dishes don't need doing three times because there are three children.

`claimantFor(Chore)` is the single source of truth: it returns the completion currently holding a chore — anyone's pending claim, or anyone's approved claim inside the cadence window — or null. A rejected claim clears it, reopening the chore for the whole household.

This is the rule that most invites accidental regression. Any new "has this been done" check must go through `claimantFor()` rather than filtering completions by `profile_id`.

## Daily quest

`questFor(Profile)` lazily assigns one random `quest_eligible` chore appropriate for the profile's age, once per `HouseholdClock::for($household)->today()`, persisted in `daily_quests` keyed by `(profile_id, quest_date)`. Repeated calls the same household-day return the same row — never re-roll on refresh, **except** when the quest is blocked (below).

Quests are assigned per kid and independently, so two kids can draw the same chore. Assignment prefers chores nobody has claimed yet, but falls back to the full eligible pool rather than failing — a kid logging in after the family cleared the board still gets a quest. Only a household with zero eligible chores throws.

If `household.require_quest_first` is true, every other chore on the board is `'locked'` until `quest.completed_at` is set (`claimQuest()`). Claiming the quest also calls `claim()` for the quest's chore, so it flows through the same points/mystery path as any other chore. The streak moves at parent approval, not here (see [[streaks]]).

### Auto-reroll of a blocked quest

Because cooldowns are household-wide, a sibling can finish the chore that was handed to you as today's quest. That would dead-end the kid's day: no quest completion, no streak day, and with `require_quest_first` on, a board that never unlocks.

`questFor()` therefore runs `rerollIfTaken()` on every read. If the quest is uncompleted and `claimantFor()` returns a completion belonging to **someone else**, the quest silently moves to a different unclaimed chore. It's invisible to the kid beyond the chore changing — no prompt, no cost.

Four conditions guard it, each protecting something real:

- **Completed quests are never rerolled.** After `claimQuest()` the chore reads as claimed-by-them, which would otherwise look identical to blocked — rerolling would erase a finished quest and cost a streak day.
- **The kid's own claim doesn't count.** Their pending claim is progress, not a blockage.
- **`ChoreCadence::Unlimited` quest chores are never rerolled.** They don't lock, so a sibling doing one blocks nobody.
- **It only swaps onto unclaimed chores.** Landing on another blocked chore leaves the kid exactly as stuck; if nothing is free, the blocked quest is kept so the page still renders.

`rerollQuest()` (the ticket-priced perk and the parent's manual button) shares the same private `assignDifferentChore()`, so both paths avoid claimed chores identically and both clear `revealed_at` — a swapped quest replays the chest animation rather than silently relabelling a card the kid already opened. When nothing is free, `rerollQuest()` returns null, which is how the perk knows to refuse and keep the kid's ticket.

## Chore board state machine

`boardFor(Profile)` returns each appropriate chore as `['chore' => Chore, 'state' => string, 'takenBy' => ?Profile]`, where `takenBy` is the claimant when it isn't this kid. States:

- `'locked'` — quest not done yet and `require_quest_first` is on.
- `'pending'` — **this** profile holds the in-flight claim.
- `'done'` — someone in the household holds it: another kid's pending claim, or anyone's approved claim inside the cadence boundary (`ChoreCadence::Weekly` → last 7 household-days; otherwise last 1).
- `'ready'` — claimable now. Always `'ready'` for `ChoreCadence::Unlimited` chores — no cooldown, ever.

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
3. No existing `claimantFor()` — a chore someone has already claimed today (pending or approved-within-cooldown) can't retroactively become the mystery pick.

Among the survivors, chores with a parent-written `hint` win the draw outright; the full pool is only used when none of them has one. That keeps the Bonus Shop's mystery-hint perk sellable — it should never charge tickets for a chore nobody wrote a clue for. Practical consequence: **if only one or two chores have hints, the mystery becomes guessable**, so hints want to be written broadly rather than on a favourite few.

The pick uses genuine randomness (`Arr::random()`), matching the spin's actual result — **not** the bonus wheel's deterministic display-subset hash (see [[bonus-wheel]]); those are unrelated mechanisms. `ChoreService::MYSTERY_BONUS_POINTS = 500` is added on top of normal points (with spin multiplier) when the claimed chore matches today's pick — see `claim()`.

Exclusivity is household-wide via `claimantFor()` — the same lock every other chore now uses, so the mystery needs no special-casing on the board.

`rerollMysteryChore()` lets a parent swap the pick from Kids & Points. It refuses once anyone has claimed it — moving the finish line after someone crossed it would rob the winner. Both it and the daily draw go through the private `drawMysteryChore()`, so the fairness rules can't drift apart between them. A kid who already bought a hint sees the *new* chore's hint automatically, since `mysteryHintFor()` resolves against the current pick rather than storing the text.

## Searching chores

Search exists on both the parent Chores admin and the kid's side-quest board, via **two implementations of the same idea**:

- `Chore::scopeMatching($term)` — SQL, for the parent page's query
- `Chore::matches($term)` — PHP, for the kid's board, which is already an in-memory collection and shouldn't be re-queried

Both read the **`Chore::SEARCHABLE`** constant, so the field list lives in one place. **Tags are planned and belong there** — add the column to `SEARCHABLE` (or join it in for the tag relation) and both surfaces pick it up. `ChoreSearchTest` runs a set of terms through both paths and asserts they return identical results, so a change to one that doesn't reach the other fails loudly.

Three things exist for a reason and shouldn't be simplified away:

- **The SQL conditions are wrapped in their own `where()` group.** The `orWhere` inside would otherwise escape and swallow the outer `household_id` condition, leaking another family's chores. Tested.
- **`ESCAPE` is stated explicitly** via `whereRaw`. MySQL defaults the LIKE escape character to backslash; SQLite has no default. Since tests run on SQLite and production on MySQL, relying on the default means the escaping works on one engine and silently fails on the other — searching `50%` would return every chore.
- **Filtering the kid's board narrows what `boardFor()` already returned**, never re-queries. That matters: the board has already excluded today's quest and anything age-gated, and search must not reach past those.

### Gotcha: board tests and the daily quest

`boardFor()` excludes whichever chore became today's quest, so a test that creates three chores and asserts on all three will flake — one of them is randomly the quest and won't appear. Give the fixtures one `quest_eligible => true` chore to absorb the assignment and mark the rest `quest_eligible => false`.

### Gotcha: setting up "already completed" test fixtures

`claim()` calls `mysteryChoreFor()` internally (to compute the bonus) **before** creating the chore's own `ChoreCompletion`. In real usage this is always safe because a kid must load the Quests page first — which calls `mysteryChoreFor()` via `with()` — before any claim action can fire, so the day's pick is already locked in by the time `claim()` runs. But if a test calls `service()->claim($kid, $chore)` as its *first* mystery-related call of the day, that call can itself make (and persist) the day's random pick — possibly picking the very chore being claimed, before its completion exists to exclude it. To set up an "already completed today" precondition in a test without tripping this, create the `ChoreCompletion` directly:

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
