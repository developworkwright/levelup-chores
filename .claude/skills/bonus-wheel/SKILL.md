---
name: bonus-wheel
description: This skill should be used when the user asks to change spin odds, wheel chore selection, spin multipliers, or mentions "SpinService", "spin_date", "MAX_WHEEL_CHORES", or the kid Bonus Wheel page.
---

# Bonus Wheel

Covers the daily spin-for-a-multiplier mechanic, owned by `app/Services/SpinService.php`.

## The wheel only shows what can actually be won

`eligibleChoresFor()` filters to age-appropriate chores, drops today's daily quest, and — since cooldowns are household-wide (see [[chores-and-quests]]) — drops anything `ChoreService::stateFor()` doesn't call `'ready'`. A sibling's claim, or the kid's own pending claim, takes a chore off the wheel; `ChoreCadence::Unlimited` chores never leave it, and a parent rejection puts one back.

Without that filter the wheel happily lands a 3x boost on a chore nobody can claim any more — a prize that pays nothing.

**The chore today's spin already landed on is force-kept**, claimed or not. The wheel has to keep rendering the segment it stopped on, and `mount()`/`spin()` both find their rotation angle by searching for that chore's index in this collection — drop it and the index search returns false, pointing the wheel at segment 0. `multiplierFor()` reads the `spins` row directly, so a boost stays valid regardless.

An empty pool makes `spin()` throw. The wheel page guards *in front of* that call rather than catching it, because nothing else catches it either.

## Core files

- `app/Services/SpinService.php`
- `app/Models/Spin.php`
- `resources/views/pages/kid/wheel.blade.php`
- Tests: `tests/Feature/SpinFlowTest.php`

## Mechanics

One spin per profile per household-day, lazily assigned and persisted the same way as the daily quest — `Spin::where('profile_id', ...)->whereDate('spin_date', HouseholdClock::for($household)->today())`. `hasSpunToday()` checks whether that row exists yet.

`spin()` picks a random chore from `eligibleChoresFor($profile)` via `$eligible->random()` (genuine randomness, same family as the mystery chore's pick — see [[chores-and-quests]]) and rolls the multiplier: **`SpinService::TRIPLE_CHANCE = 0.35`** → 35% chance of a 3x multiplier, otherwise 2x. Throws `RuntimeException` if `household.spin_enabled` is off, already spun today, or there are no eligible chores.

`multiplierFor(Profile, Chore)` is what `ChoreService::claim()` reads to apply the boost — returns today's multiplier only if the chore matches today's spin's chore, otherwise `1`.

## Eligible chores and the >10 cap

`eligibleChoresFor($profile)` starts from chores appropriate for the profile's age, excluding today's assigned daily-quest chore (resolved via `app(ChoreService::class)->questFor($profile)` — lazily resolved with the container, not constructor-injected, specifically to avoid a circular dependency since `ChoreService` itself depends on `SpinService`).

If the household has 10 or fewer eligible chores (`MAX_WHEEL_CHORES = 10`), all of them go on the wheel. Above that cap, a **subset** is chosen — but this subset selection is deliberately **not** re-randomized on every render. It uses a deterministic per-profile-per-day shuffle: `crc32("{profileId}-{today}-{choreId}")` as the sort key. This guarantees:

- The same subset appears on every page load/refresh that day.
- If a spin has already happened today, the landed-on chore is force-included even if it wouldn't otherwise survive the crc32 cut — otherwise the wheel could visually "forget" the chore it already landed on.

Do not confuse this deterministic subset hash with the actual spin *result*, which is genuinely random (`Arr::random()`/`$eligible->random()`). Changing the subset-selection hash to real randomness would make the wheel's visible options change between renders, which is the specific bug this design avoids.

## Badge tie-in

Landing a 3x multiplier at any point unlocks the `wheel_winner` badge — see [[badges]].

## Resetting a spin for testing

`php artisan wheel:reset-spin --kid=Nova` deletes just today's `Spin` row for that kid (omit `--kid` to reset every kid; add `--dry-run` to preview). It only touches the `spins` table — points, chore completions, and badges are untouched, since the spin itself never awards points directly (only chore claims made under its multiplier do, and those are already snapshotted). For a full day wipe across quest/spin/chores/loot, use `quest:reset-today` instead (`app/Console/Commands/ResetTodayCommand.php`).

The same reset is also exposed to parents in the console: `resources/views/pages/parent/kids.blade.php`'s `resetSpin(int $profileId)` method does the identical delete (`Spin::where('profile_id', ...)->whereDate('spin_date', HouseholdClock::for(...)->today())->delete()`), surfaced as a per-kid "Reset spin" button on the Kids & Points tab, disabled when that kid hasn't spun yet today. Keep both in sync if the reset condition ever changes.
