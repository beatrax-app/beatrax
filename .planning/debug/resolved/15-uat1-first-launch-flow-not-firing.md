---
status: verifying
trigger: "UAT-1: First-launch flow does not fire on a fresh install. Packaged macOS app opens directly on signin page even after deleting bundled SQLite DB. Setting up... and Welcome screens never appear."
created: 2026-05-23T00:00:00Z
updated: 2026-05-23T00:00:00Z
---

## Current Focus

reasoning_checkpoint:
  hypothesis: "Two bugs combine to produce UAT-1: (1) EnsureDatabaseReady gates on hasPendingMigrations() only, but NativeAppServiceProvider::boot() calls runPendingMigrations() BEFORE any HTTP request, so the predicate is always false by the time the middleware fires; (2) NOTHING redirects to /welcome — the desktop.welcome route exists but no code path navigates the user there, so even if (1) were fixed, the welcome screen would still be unreachable."
  confirming_evidence:
    - "NativeAppServiceProvider.php line 80: `$this->bootstrap->runPendingMigrations();` is called at the very top of boot(), before windows open."
    - "EnsureDatabaseReady.php line 55: redirect predicate is solely `$this->bootstrap->hasPendingMigrations()` — no fresh-install check."
    - "Grep for 'desktop.welcome' shows only the route definition and a test — no redirect chain ever references it."
    - "FirstLaunchBootstrap exposes isFreshInstall() but it is wired only into a test, never read by any HTTP-time code."
    - "On fresh DB, after boot() migrations run, `/` → auth → guest → /login. The user lands there, never seeing setup or welcome."
  falsification_test: "If middleware predicate also covered isFreshInstall() and welcome route was added to exempt list, a guest hitting `/` on a freshly migrated zero-user DB would redirect to /welcome — observable in a feature test that issues GET / without RefreshDatabase pre-creating a user."
  fix_rationale: "Add isFreshInstall() as a secondary predicate in EnsureDatabaseReady (after pending-migration check, since users table must exist). Exempt desktop.welcome* and signup so the welcome → signup chain isn't a redirect loop. This makes the welcome screen actually reachable on fresh DB; the setup screen still works for the upgrade case where migrations are mid-flight."
  blind_spots: "Test suite uses RefreshDatabase which leaves DB migrated + empty — the existing 'lets requests through' test will now redirect to welcome and need adjustment (insert a user before the assertion). The fresh-install branch is essentially identical between RefreshDatabase tests and prod, so this is a benefit. I have not verified whether any other route between login and dashboard (password reset, etc.) is hit during a fresh install."

next_action: Apply fix to EnsureDatabaseReady, add exempt routes, write new feature test that doesn't use RefreshDatabase shortcut to user existence, update existing affected test, run quality gates.

## Symptoms

expected: On fresh install (no DB exists or DB has no users), the app should open on "Setting up..." poll screen (if migrations pending) then "Welcome to diederik" screen (until user clicks Get started). PKG-04 first-launch UX.
actual: App opens directly on signin page. Setup and Welcome screens never appear.
errors: None reported — silent flow gap.
reproduction: Build packaged macOS app via `php artisan native:build mac arm64`, delete SQLite at `~/Library/Application Support/diederik/database/`, relaunch.
started: Since CR-01 + CR-02 fixes were applied in phase 15.

## Eliminated

(none yet)

## Evidence

- checked: NativeAppServiceProvider::boot() ordering
  found: runPendingMigrations() at line 80 BEFORE windows open and BEFORE any HTTP traffic
  implication: hasPendingMigrations() is reliably false on every HTTP request on a fresh install

- checked: EnsureDatabaseReady middleware predicate
  found: Only checks hasPendingMigrations(); no fresh-install branch
  implication: Middleware is a pass-through on every fresh-install request

- checked: References to desktop.welcome route
  found: Only the route definition (Modules/Desktop/Routes/web.php:28) and one test; no redirect chain ever sends the user there
  implication: Welcome screen is orphaned — no code path navigates to /welcome

- checked: Auth flow from / on guest fresh-install
  found: / requires auth → redirect to login → renders signin page (the UAT-1 symptom)
  implication: Confirmed root cause sequence

## Resolution

root_cause: |
  Two compounding bugs in the first-launch flow:
  (1) `EnsureDatabaseReady` middleware gates on `hasPendingMigrations()` only, but
  `NativeAppServiceProvider::boot()` calls `runPendingMigrations()` before any HTTP
  request — so by the time the middleware runs the predicate is always false on
  fresh install.
  (2) The `desktop.welcome` route exists but nothing redirects to it. Even if (1)
  were fixed, the welcome screen would remain unreachable.
fix: |
  - `EnsureDatabaseReady` now evaluates two predicates in order: pending migrations
    → setup (rare upgrade case); else fresh install (`isFreshInstall()` — `users`
    table empty) → welcome. Exempt prefix list extended with `desktop.welcome` and
    `signup` so the welcome → signup chain does not loop.
  - Added 6 feature tests in `FirstLaunchBootstrapTest.php`: cold-start redirect to
    welcome (the explicit UAT-1 regression catcher), post-signup pass-through,
    welcome route self-exemption, signup route exemption, and end-to-end production
    wiring through `web` group.
  - Adjusted the pre-existing "lets requests through" test to seed a user so it
    exercises the post-signup pass-through (was implicitly relying on the broken
    behaviour).
  - Adjusted `ResetPasswordPageTest::it renders…` to seed an owner + log back out
    before issuing the GET (the page is meaningless when no user exists; the new
    gate funnels that zero-user state to welcome).
verification: |
  - `./vendor/bin/pest Modules/Desktop/tests/Feature/FirstLaunchBootstrapTest.php` — 20 pass
  - `./vendor/bin/pest Modules/Auth Modules/Core` — 296 pass
  - Full suite (`./vendor/bin/pest`) — 2177 pass, 0 fail
  - `composer analyse` (Larastan level 10, --memory-limit=1G) — No errors
  - `./vendor/bin/pint --test …` on changed files — passed
  - Arch tests — 50 pass

## Resolution

root_cause: (pending)
fix: (pending)
verification: (pending)
files_changed:
  - Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php
  - bootstrap/app.php
  - Modules/Desktop/tests/Feature/FirstLaunchBootstrapTest.php
  - Modules/Auth/tests/Feature/ResetPasswordPageTest.php
