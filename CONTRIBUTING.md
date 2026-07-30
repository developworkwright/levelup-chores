# Contributing

Thanks for taking an interest. This started as one family's chore app, so contributions that keep it simple and self-hostable are especially welcome.

## Getting set up

```bash
git clone https://github.com/developworkwright/levelup-chores.git
cd levelup-chores
composer setup
```

`composer setup` installs PHP and npm dependencies, creates `.env`, generates an app key, runs migrations, and builds assets. Point the `DB_*` values in `.env` at a database first — MySQL or MariaDB.

Then seed the demo household and start the app:

```bash
php artisan migrate --seed
composer dev
```

`composer dev` runs the server, queue listener, log tailer, and Vite together. Log in with any seeded profile — PINs are in the [README](README.md#demo-profiles).

### Local `.env` gotcha

`.env.example` targets production, where HTTPS terminates at the load balancer. If you're serving over plain `http://` locally, set:

```dotenv
SESSION_SECURE_COOKIE=false
```

Leave it `true` and the browser refuses to store the session cookie, so every request fails CSRF and you get **419 | Page Expired** on the PIN pad. Setting `APP_DEBUG=true` locally is also worth it.

## Before you open a PR

```bash
php artisan test          # full suite
vendor/bin/pint           # fix code style
```

Both run in CI on every pull request, across PHP 8.4 and 8.5. Tests use an in-memory SQLite database and never touch your real one.

PHP **8.4.1** is the hard floor — Laravel 13 depends on Symfony 8, which requires it. Anything older fails at `composer install`.

Add or update a test for anything you change. The suite is the main defence against subtle regressions in the points and streak maths, which are easy to break in ways nobody notices for a week.

## House rules

**Never edit or delete an existing migration.** Some of them have already run against live databases holding real family data. Schema changes always go in a *new* migration, with a working `down()`.

**Everything that moves points goes through `LedgerService`.** `profiles.points` is a cache; `ledger_entries` is the truth. Never write to the column directly — the service keeps the two in sync inside a transaction.

**Use `HouseholdClock`, never `now()`, for anything day-based.** The household day rolls over at 4am by default, so cooldowns, streaks, and daily assignments must resolve through the clock or they'll be wrong for anyone doing chores late at night.

**Keep real people out of the repo.** Seeders and tests use placeholder names. Don't commit `.env`, real PINs, or anything identifying.

## Branches and commits

Branch feature branches off `main`:

```
feat/weekly-leaderboard
fix/419-on-pin-pad
chore/bump-livewire
docs/clarify-setup
```

Open a PR against `main`. PRs are squash-merged, so don't worry about tidying your commit history — write a clear PR title instead, since that becomes the commit message.

## Where the logic lives

Domain rules live in `app/Services`, not in components, so they can be tested without the UI:

| Service | Owns |
|---|---|
| `ChoreService` | Daily quests, the chore board, mystery chore, streaks |
| `SpinService` | Bonus wheel eligibility and multipliers |
| `StoreService` | Loot shop redemptions |
| `LedgerService` | Every balance change |
| `BadgeService` | Achievement conditions |
| `HouseholdClock` | The 4am day boundary |

The UI is [Volt](https://livewire.laravel.com/docs/volt) single-file components under `resources/views/pages`. One quirk worth knowing: in the Blade half of a Volt file, class references must be fully qualified (`\App\Services\ChoreService::FOO`) even when imported at the top — a bare reference silently fails to resolve.

`.claude/skills/` documents the non-obvious mechanics of each subsystem — the fairness rules behind mystery-chore selection, why the wheel's displayed options are deterministic while its result isn't, how streaks recompute. Useful reading whether or not you use an AI assistant.

## Reporting bugs

Open an issue with steps to reproduce, what you expected, and what happened. If it involves points or streaks going wrong, the Activity tab shows the full ledger and is usually the fastest way to see what actually occurred.

For anything security-related — authentication, PIN handling, one profile accessing another's data — please report it privately rather than in a public issue.
