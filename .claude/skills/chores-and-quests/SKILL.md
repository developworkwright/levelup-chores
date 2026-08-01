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
- Tests: `tests/Feature/ChoreFlowTest.php`, `tests/Feature/MysteryChoreTest.php`.

## Daily quest

`questFor(Profile)` lazily assigns one random `quest_eligible` chore appropriate for the profile's age, once per `HouseholdClock::for($household)->today()`, persisted in `daily_quests` keyed by `(profile_id, quest_date)`. Repeated calls the same household-day return the same row — never re-roll on refresh.

If `household.require_quest_first` is true, every other chore on the board is `'locked'` until `quest.completed_at` is set (`claimQuest()`). Claiming the quest also runs `bumpStreak()` (see [[streaks]]) and calls `claim()` for the quest's chore, so it flows through the same points/mystery path as any other chore.

## Chore board state machine

`boardFor(Profile)` returns each appropriate chore annotated with a state computed by `stateForChore()`:

- `'locked'` — quest not done yet and `require_quest_first` is on.
- `'pending'` — the profile (or, for the mystery chore, anyone) has an unapproved claim in flight.
- `'done'` — on cooldown: an `Approved` completion exists within the cadence boundary (`ChoreCadence::Weekly` → last 7 household-days; otherwise last 1).
- `'ready'` — claimable now. Always `'ready'` for `ChoreCadence::Unlimited` chores — no cooldown, ever.

Cooldown/pending boundaries always use `HouseholdClock`, never raw `now()` — the household day rolls over at `day_boundary_hour` (default 4am), not midnight.

**Performance rule:** `boardFor()` resolves `mysteryChoreFor()` **once** before its loop and passes the result into `stateForChore()`. Never call `mysteryChoreFor()` (or any other per-board lookup) inside a per-chore loop — that reintroduces an N+1. `stateFor(Profile, Chore)` (the single-chore convenience wrapper) is the only place that resolves it fresh.

## Mystery chore

`mysteryChoreFor(Household)` lazily picks one household-wide "secret bonus" chore per household-day, persisted in `daily_mysteries` keyed by `(household_id, mystery_date)`. It is **fully automatic** — there is no parent toggle. Candidacy filter, applied in this order:

1. `min_age === null` only — age-gated chores are never eligible, so the youngest kid always has a fair shot.
2. `cadence !== ChoreCadence::Unlimited` — an unlimited chore is freely repeatable by everyone, which is incompatible with "first to find it wins." This was a real bug caught by a test, not a spec item — keep it if adding new cadences.
3. No existing `mysteryClaimant()` — a chore someone has already claimed today (pending or approved-within-cooldown) can't retroactively become the mystery pick.

Among the survivors, chores with a parent-written `hint` win the draw outright; the full pool is only used when none of them has one. That keeps the Bonus Shop's mystery-hint perk sellable — it should never charge tickets for a chore nobody wrote a clue for. Practical consequence: **if only one or two chores have hints, the mystery becomes guessable**, so hints want to be written broadly rather than on a favourite few.

The pick uses genuine randomness (`Arr::random()`), matching the spin's actual result — **not** the bonus wheel's deterministic display-subset hash (see [[bonus-wheel]]); those are unrelated mechanisms. `ChoreService::MYSTERY_BONUS_POINTS = 500` is added on top of normal points (with spin multiplier) when the claimed chore matches today's pick — see `claim()`.

Exclusivity is household-wide via `mysteryClaimant()`: whoever claims it first locks it for everyone else until a parent rejects the claim (which reopens it) or the cadence cooldown resets.

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
