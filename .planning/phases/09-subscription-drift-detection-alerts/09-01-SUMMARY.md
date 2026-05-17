---
phase: 09-subscription-drift-detection-alerts
plan: 01
subsystem: testing
tags: [laravel-modules, pest, larastan, arch-tests, fixtures, factories, service-provider, livewire]

# Dependency graph
requires:
  - phase: 08-recurring-detection-fixed-payments-view
    provides: Modules/Recurring substrate (RecurringSeries + occurrences + state machine + Public Query) the DriftAlerts detector reads through
  - phase: 06-email-imap-oauth-inbox
    provides: Wave-0 module-skeleton pattern (composer + ServiceProvider + Routes + tests dir + bootstrap/providers registration) DriftAlerts mirrors
provides:
  - bounded Modules/DriftAlerts module loadable on the autoloader with Public/Internal split
  - DriftAlertsServiceProvider with singleton bindings, listener wire-up to Recurring metrics-refreshed event, top-nav badge composer
  - five BoundaryArchTest invariants guarding DriftAlerts (Internal-only-used-in + no-recurring-series-writes + cross-module-via-public + no-sync-drift-in-request + sole-state-mutator) plus a facade carve-out for DetectDriftAlertsJob
  - 24-scenario synthesised drift fixture corpus + DriftAlertFactory + DriftAlertTransitionFactory ready for Wave 2's contract test
affects: [09-02-schema-models-state-machine, 09-03-evaluator-detector-job, 09-04-drift-page-actions, 09-05-cancellation-impact-revival]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "3-step per-module test discovery (composer autoload-dev + phpunit testsuite + tests/Pest.php map row)"
    - "Filesystem-walk BoundaryArchTest invariant: comment-strip + skip tests/ + skip Migrations dir + regex against verb shapes"
    - "Factory @extends Factory<TModel> with forward-declared model FQN — Eloquent resolves model class at factory-invoke time"
    - "Wave 0 fixture corpus shape: each file returns ['transactions' => [...], 'expected' => ['alerts' => [...]]] for downstream contract reuse"

key-files:
  created:
    - Modules/DriftAlerts/composer.json
    - Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php
    - Modules/DriftAlerts/Routes/web.php
    - Modules/DriftAlerts/tests/TestCase.php
    - Modules/DriftAlerts/tests/Pest.php
    - Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php
    - Modules/DriftAlerts/Database/Factories/DriftAlertTransitionFactory.php
    - Modules/DriftAlerts/tests/Unit/FixtureCorpusTest.php
    - "Modules/DriftAlerts/tests/fixtures/drift-corpus/*.php (24 fixtures)"
  modified:
    - composer.json
    - phpunit.xml
    - tests/Pest.php
    - bootstrap/providers.php
    - bootstrap/cache/services.php
    - tests/Contracts/BoundaryArchTest.php

key-decisions:
  - "Empty middleware group in Routes/web.php — registering the /drift route before DriftPage class exists trips Laravel's RouteAction validation; mirrors the EmailScan Wave 0 precedent (empty group + activation comment)"
  - "DI-injected Dispatcher in boot(LivewireManager, Dispatcher) — cleaner than $this->app['events'] (which trips PHPStan strict-rules offsetAccess + method-on-mixed); matches the ChainsServiceProvider analog shape"
  - "ServiceProvider singleton bindings reference not-yet-existing FQNs as string class constants; Laravel container resolves at runtime, not at boot — PHPStan flags these as class.notFound and they are explicitly accepted Wave 0 baseline errors"
  - "Pint formatting clean across all new files; PHPStan errors limited to the documented forward-declared FQNs (DriftEvaluator + DetectDriftAlertsJob + DriftAlertStateMachine + state-machine internals + DriftPage + DashboardDriftBadge + DriftAlertQuery + CancellationImpactQuery + AcknowledgeDriftAlert + SnoozeDriftAlert + DismissDriftAlertAsCancelled + RecurringSeriesMetricsRefreshed + EvaluateDriftOnMetricsRefreshed + DriftAlert model + DriftAlertTransition model)"
  - "Factory state methods use CarbonImmutable directly (matches the project-wide carbon-immutable cast on every domain timestamp); the snoozed($until) signature takes CarbonImmutable explicitly so the call site cannot pass a mutable Carbon by accident"
  - "Fixture corpus 'expected.alerts' shape uses exact drift_alerts column-name keys so Wave 2's contract test can assert via assertDatabaseHas($expected) directly without remapping"

patterns-established:
  - "Forward-declared FQN imports in Wave 0 ServiceProvider: PHPStan class.notFound errors are explicitly accepted; the implementation classes ship in later waves"
  - "Empty middleware group as Wave 0 routes file: ships the auth+web envelope without referencing not-yet-existing handler classes (mirrors EmailScan 06-01)"
  - "Filesystem-walk arch test skip list: always exclude tests/, Database/Migrations/, and (for sole-mutator rules) the canonical-mutator file itself"
  - "Fixture-shape contract test: assert allowed-key subset + enum-value membership for every alert tuple; covers downstream contract test against drift_alerts.assertDatabaseHas"

requirements-completed: [REC-06]

# Metrics
duration: 16min
completed: 2026-05-17
---

# Phase 09 Plan 01: Wave 0 enablement — DriftAlerts module skeleton + arch invariants + fixture corpus Summary

**Bounded Modules/DriftAlerts module + 5 BoundaryArchTest invariants (sole-state-mutator pattern carried forward) + 24-scenario synthesised drift fixture corpus + factory pair, all green against an empty Wave 0 module.**

## Performance

- **Duration:** ~16 minutes
- **Started:** 2026-05-17T19:22:34Z
- **Completed:** 2026-05-17T19:39:22Z
- **Tasks:** 3
- **Files created:** 35 (1 composer manifest + 1 ServiceProvider + 1 routes + 2 test TestCase/Pest shells + 2 factories + 1 corpus loader test + 24 fixtures + 3 test-discovery edits)
- **Files modified:** 6 (composer.json + phpunit.xml + tests/Pest.php + bootstrap/providers.php + bootstrap/cache/services.php + tests/Contracts/BoundaryArchTest.php)

## Accomplishments

- Modules/DriftAlerts/ bounded module skeleton is loadable on the composer autoloader: `class_exists('Modules\\DriftAlerts\\Providers\\DriftAlertsServiceProvider')` returns `true`, and `class_exists('Modules\\DriftAlerts\\Tests\\TestCase')` returns `true`. `composer dump-autoload` is clean of warnings other than the standard project-wide test-fixture skip notices that appear for every module.
- ServiceProvider registers nine singletons (DriftAlertStateMachine + DriftEvaluator + DetectDriftAlertsJob + DriftAlertQuery + CancellationImpactQuery + AcknowledgeDriftAlert + SnoozeDriftAlert + DismissDriftAlertAsCancelled + DashboardDriftBadge), wires the listener for `Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed`, registers two Livewire components, and installs the top-nav badge composer injecting `driftOpenCount`.
- Five BoundaryArchTest invariants are green against the empty Wave 0 module:
  - `Modules\DriftAlerts\Internal is only used inside Modules\DriftAlerts` (namespace arch)
  - `noRecurringSeriesWritesFromDriftAlerts` (filesystem-walk; synthetic-violation verified)
  - `Modules\Recurring\Internal is never imported from Modules\DriftAlerts` (`crossModuleAccessGoesThroughPublic`)
  - `DriftEvaluator is never imported by Modules\DriftAlerts\Internal\Http` (`noSynchronousDriftDetectionInRequestLifecycle`)
  - `noOtherDriftAlertStateMutator` (filesystem-walk, mirrors `noOtherRecurringSeriesStateMutator`)
  - Plus the facade carve-out `Modules\DriftAlerts\Internal\Jobs\DetectDriftAlertsJob` appended to the existing facade-ignore list, in line with the DetectRecurringSeriesJob carve-out comment block.
- 24 synthesised drift fixtures and a Pest-dataset corpus loader unit test all green: 29/29 cases (412 assertions) covering the shape contract + four targeted math assertions (Spotify-15% canonical, FX-only zero-alert, weekly ×52, multi-drift two-alert count).
- DriftAlertFactory + DriftAlertTransitionFactory parse and produce valid Wave-1-shaped row tuples; the only PHPStan errors against the factories directory are the eight `class.notFound` entries for the two model FQNs (DriftAlert + DriftAlertTransition) the Wave 1 migrations will land.
- `vendor/bin/pint --test Modules/DriftAlerts/` is green; `vendor/bin/pest tests/Contracts/BoundaryArchTest.php Modules/DriftAlerts/tests/Unit/FixtureCorpusTest.php` is 58 passed / 470 assertions / no flakes.

## Task Commits

1. **Task 1: Module skeleton + 3-step test discovery wire-up + ServiceProvider DI graph** — `0a2232e` (feat)
2. **Task 2: Five BoundaryArchTest invariants + facade carve-out for DetectDriftAlertsJob** — `5c66078` (test)
3. **Task 3: 24-scenario fixture corpus + corpus loader test + DriftAlertFactory pair** — `5e9058b` (test)

## Files Created/Modified

### Module skeleton (new)

- `Modules/DriftAlerts/composer.json` — PSR-4 namespace registration (`Modules\\DriftAlerts\\` + `Modules\\DriftAlerts\\Tests\\`)
- `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php` — 9 singleton bindings + Livewire SFC registration + Recurring metrics-refreshed listener wire + top-nav badge composer
- `Modules/DriftAlerts/Routes/web.php` — auth+web middleware envelope only (the `/drift` route activates in a later wave once the DriftPage Livewire SFC class exists)
- `Modules/DriftAlerts/tests/TestCase.php` — module-local TestCase extending the project root TestCase
- `Modules/DriftAlerts/tests/Pest.php` — documented-inert per the project convention

### Test discovery (modified)

- `composer.json` — `autoload-dev.psr-4` gains `"Modules\\DriftAlerts\\Tests\\": "Modules/DriftAlerts/tests/"`
- `phpunit.xml` — adds `Modules/DriftAlerts/tests/Unit` + `Modules/DriftAlerts/tests/Feature` to the unified Unit + Feature testsuites and a dedicated `<testsuite name="DriftAlerts">` entry
- `tests/Pest.php` — adds `'Modules/DriftAlerts' => Modules\DriftAlerts\Tests\TestCase::class` to the per-module map (alphabetical position)
- `bootstrap/providers.php` — registers `DriftAlertsServiceProvider::class` so the boot() wiring fires
- `bootstrap/cache/services.php` — regenerated by composer to pick up the new provider

### Arch tests (modified)

- `tests/Contracts/BoundaryArchTest.php` — appends `Modules\DriftAlerts\Internal\Jobs\DetectDriftAlertsJob` to the facade-ignore list (with carve-out comment), adds the `Modules\DriftAlerts\Internal is only used inside Modules\DriftAlerts` namespace arch, and adds four new bottom-of-file invariants (`crossModuleAccessGoesThroughPublic`, `noSynchronousDriftDetectionInRequestLifecycle`, `noRecurringSeriesWritesFromDriftAlerts`, `noOtherDriftAlertStateMutator`)

### Fixture corpus + factories (new)

- `Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php` — Spotify-15% canonical default + four state factories (open / acknowledged / snoozed($until) / dismissedCancelled)
- `Modules/DriftAlerts/Database/Factories/DriftAlertTransitionFactory.php` — user-action acknowledged transition default
- `Modules/DriftAlerts/tests/Unit/FixtureCorpusTest.php` — 24-dataset shape contract + 4 targeted math assertions
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/stable-monthly.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/small-drift-below-threshold.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/large-drift-above-threshold.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/income-raise.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/income-raise-large.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/income-cut.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/fx-only-swing.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/cadence-changed.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/multi-drift.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/per-series-override.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/prior-null.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/prior-zero.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/volatile-series.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/volatile-with-override.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/weekly-cadence.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/quarterly-cadence.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/yearly-cadence.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/mixed-currency-stable-usd.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/mixed-currency-real-usd-drift.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/pending-state-ignored.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/rejected-state-ignored.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/snoozed-at-series-level-ignored.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/irregular-cadence-ignored.php`
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/snooze-expiry-revival.php`

## Decisions Made

- **Empty middleware group in Routes/web.php.** The plan asked for `Route::get('/drift', DriftPage::class)` but `DriftPage` does not exist until a later wave. Laravel's `RouteAction::makeInvokable()` validates that the handler class has an `__invoke()` method at registration time, triggering autoload — which fails for a class that does not yet exist, and `php artisan` cannot boot. The fix mirrors the EmailScan 06-01 Wave 0 precedent: ship only the `web` + `auth` middleware group with an inline comment showing the `Route::get(...)` line that activates in the wave that lands the Livewire SFC.
- **Boot signature DI on Dispatcher.** Used `boot(LivewireManager $livewire, Dispatcher $events)` rather than the plan-spec `$events = $this->app['events']`. The DI form clears two PHPStan strict-rules errors (`offsetAccess.nonOffsetAccessible` on `$app['events']` and `method.nonObject` on the resulting `->listen()` call) and matches the established ChainsServiceProvider pattern.
- **Module ServiceProvider registered in `bootstrap/providers.php`.** The plan's `<files>` list did not explicitly include `bootstrap/providers.php`, but without registration the new ServiceProvider's `boot()` (and therefore the listener wire-up and migration loading) never fires. Mirrors the existing per-module convention.
- **Synthesised fixture math is deterministic and auditable.** Every drift fixture either has zero alerts (with documented reasoning — below threshold / FX-only / excluded state / divide-by-zero guard) or exactly the documented signed delta + ×N annualized-impact tuple. Wave 2's `DriftEvaluatorTest` Pest dataset can iterate the same fixtures without re-deriving the math.
- **Cadence-to-year weekly multiplier locked at ×52.** The fixture `weekly-cadence.php` asserts `annualized = delta × 52` so the Wave 2 evaluator must implement ×52 (the calendar-integer approximation) rather than 52.18 or 4.33×12.
- **Fixture corpus is GSD-agnostic.** All planning-system references (D-### codes, REQ-IDs, `.planning/` paths) were stripped from fixture comments and the corpus loader test in accordance with the project-wide invariant that committed code does not reference the planning artefacts. "Phase N" historical references remain (precedent: existing analog modules use the same phrasing for back-reference).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Routes file referenced not-yet-existing DriftPage class**

- **Found during:** Task 1 (Module skeleton)
- **Issue:** The plan's spec for `Modules/DriftAlerts/Routes/web.php` includes `Route::get('/drift', DriftPage::class)->name('drift.index')` inside the middleware group. The `DriftPage` Livewire SFC class is a later-wave deliverable. Laravel's `RouteAction::makeInvokable()` validates at registration time that the handler class has `__invoke()`, which triggers autoload — and autoload fails for a class that does not yet exist. Symptom: `php artisan` exits with `UnexpectedValueException: Invalid route action: [Modules\DriftAlerts\Internal\Http\Livewire\DriftPage]`, breaking the whole framework boot and therefore the pest test runner.
- **Fix:** Ship only the `web` + `auth` middleware envelope with an inline comment showing the `Route::get(...)` line that activates in the wave that lands the SFC. Mirrors the EmailScan 06-01 Wave 0 precedent (git-archaeology confirms the same pattern was applied there for the same reason).
- **Files modified:** `Modules/DriftAlerts/Routes/web.php`
- **Verification:** `php artisan about` returns clean output; `vendor/bin/pest --testsuite=DriftAlerts` runs without boot errors.
- **Committed in:** `0a2232e` (part of Task 1)

**2. [Rule 2 - Missing critical functionality] ServiceProvider not registered in bootstrap/providers.php**

- **Found during:** Task 1 (Module skeleton)
- **Issue:** The plan's `<files>` list did not include `bootstrap/providers.php`, but every existing module's ServiceProvider is explicitly registered there. Without registration the ServiceProvider's `register()` and `boot()` never run — meaning the listener wire-up never happens, the Livewire SFC registrations never happen, and the top-nav composer never fires. This is required-for-correctness, not optional.
- **Fix:** Added `use Modules\DriftAlerts\Providers\DriftAlertsServiceProvider;` import and `DriftAlertsServiceProvider::class` array entry to `bootstrap/providers.php` in alphabetical position.
- **Files modified:** `bootstrap/providers.php`, `bootstrap/cache/services.php` (regenerated by composer)
- **Verification:** `php artisan about` confirms the application boots cleanly; the SDK ServiceProvider list grows by one entry.
- **Committed in:** `0a2232e` (part of Task 1)

**3. [Rule 1 - Bug] $this->app['events'] tripped PHPStan strict-rules**

- **Found during:** Task 1 (Module skeleton)
- **Issue:** The plan specified `$events = $this->app['events']` inside `registerListener()`. PHPStan strict-rules flags this with `offsetAccess.nonOffsetAccessible` (Application is not array-accessible at the static-typing level) and `method.nonObject` (the offset-access return is `mixed`, so calling `->listen()` fails the strict rule). The plan's `<acceptance_criteria>` lists the expected class.notFound errors but does not whitelist these two strict-rules errors.
- **Fix:** Switched to constructor-DI form — added `Illuminate\Contracts\Events\Dispatcher` to the imports and changed the boot signature to `boot(LivewireManager $livewire, Dispatcher $events)`, then passed `$events` through to `registerListener(Dispatcher $events)`. Matches the established ChainsServiceProvider analog (`boot(LivewireManager $livewire, Dispatcher $events)`).
- **Files modified:** `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php`
- **Verification:** PHPStan run reports zero offsetAccess/method-on-mixed errors; remaining 15 errors are all the explicitly-accepted class.notFound entries for Wave 1-3 deliverables.
- **Committed in:** `0a2232e` (part of Task 1)

---

**Total deviations:** 3 auto-fixed (1 × Rule 1 + 1 × Rule 2 + 1 × Rule 3)

**Impact on plan:** All three fixes were necessary for the codebase to compile-and-boot. None changed the architectural surface: the route ships in the next wave alongside its handler class, the ServiceProvider registration is conventional cleanup, and the DI-injected Dispatcher is a static-analysis hygiene fix that also reads more clearly. No scope creep.

## Issues Encountered

- **Composer vendor directory empty on the worktree.** The agent's worktree spun up with `vendor/` containing only the composer autoloader skeleton, no installed packages. Resolved by running `composer install --no-interaction --no-progress` once before the verification commands.
- **SQLite database file missing on the worktree.** `php artisan` and `vendor/bin/phpstan` both attempted to open `database/database.sqlite` (configured in `.env`), but the file was gitignored. Resolved by creating an empty file (`touch database/database.sqlite`) and running `php artisan key:generate --force` plus `php artisan migrate --force` to seed the schema for local verification. These files remain gitignored — the resolution was local to the worktree.

## User Setup Required

None — no external service configuration touched in this plan.

## Next Phase Readiness

Wave 1 (Plan 09-02 — schema + models + state machine) can begin immediately. The Wave 1 plan ships:

- `Modules/DriftAlerts/Database/Migrations/*_create_drift_alerts_table.php` (with the BEFORE INSERT/UPDATE trigger pair for `drift_alerts.state` mirroring the recurring_series trigger shape)
- `Modules/DriftAlerts/Database/Migrations/*_create_drift_alert_transitions_table.php`
- `Modules/DriftAlerts/Models/DriftAlert.php` + `DriftAlertTransition.php` (clearing 8 of the 15 PHPStan class.notFound errors)
- `Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php` (clearing 1 more class.notFound; required for the `noOtherDriftAlertStateMutator` rule to bind to a real file)
- Public DTOs (`DriftAlertDto` + `CancellationImpactDto`) and the four Public events

After Wave 1, the 24-fixture corpus + the two factories committed in this plan become live — the contract test consumers in Wave 2 can call `assertDatabaseHas('drift_alerts', $fixture['expected']['alerts'][$i])` directly because the corpus was written with the exact column-name keys the migration will create.

No blockers. No deferred items added to the queue.

## Self-Check: PASSED

**Verified files exist:**

- `Modules/DriftAlerts/composer.json` — FOUND
- `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php` — FOUND
- `Modules/DriftAlerts/Routes/web.php` — FOUND
- `Modules/DriftAlerts/tests/TestCase.php` — FOUND
- `Modules/DriftAlerts/tests/Pest.php` — FOUND
- `Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php` — FOUND
- `Modules/DriftAlerts/Database/Factories/DriftAlertTransitionFactory.php` — FOUND
- `Modules/DriftAlerts/tests/Unit/FixtureCorpusTest.php` — FOUND
- `Modules/DriftAlerts/tests/fixtures/drift-corpus/` — FOUND (24 files)

**Verified commits exist:**

- `0a2232e` (Task 1) — FOUND
- `5c66078` (Task 2) — FOUND
- `5e9058b` (Task 3) — FOUND

---
*Phase: 09-subscription-drift-detection-alerts*
*Completed: 2026-05-17*
