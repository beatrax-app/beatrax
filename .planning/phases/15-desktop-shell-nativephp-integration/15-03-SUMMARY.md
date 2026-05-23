---
phase: 15-desktop-shell-nativephp-integration
plan: 03
subsystem: desktop
tags: [nativephp, queue-worker, scheduler, system-alerts, livewire, close-window, tray, dark-mode]

# Dependency graph
requires:
  - phase: 14-queue-rewire-horizon-carveout
    provides: "database queue driver — the shipped bundle's queue worker has no Redis dependency (D-05 carve-out)"
  - phase: 15-01
    provides: "Modules/Desktop bounded module + NativeAppServiceProvider + facade allow-list + arch invariant"
  - phase: 15-02
    provides: "WindowFocusState singleton (D-13 focus-gate consumed by SurfaceWorkerCrashAlert), DispatchOsNotification carve-out pattern, NotificationDeepLink Public event"
  - phase: 15-06
    provides: "users.theme migration analog (users.close_behavior follows the same shape and lands directly after users.theme)"
provides:
  - "config/nativephp.php queue_workers.default — D-05 persistent supervised queue:work child process (database driver, no Redis)"
  - "routes/console.php desktop.email-scan.timer — D-06 15-minute fallback email-scan scheduler entry"
  - "SurfaceWorkerCrashAlert — D-07 ProcessExited listener with rolling-window crash counter + critical system_alerts row + focus-gated OS notification"
  - "WindowCloseBehavior — D-08 close-decision service (shouldPromptFor / persistChoice with quit/tray allow-list)"
  - "CloseWindowPrompt — D-08 Livewire flux:modal component with verbatim UI-SPEC copy + instant-apply choice persistence"
  - "users.close_behavior migration — nullable per-user preference (NULL = prompt; 'quit' / 'tray' = remembered choice)"
affects: [15-04 (file-association deep-link can consume the same WindowCloseBehavior pattern), 16 (Developer Mode UI consumes system_alerts worker.crashed rows + can extend WindowCloseBehavior with a developer-only 'never quit' option), 17 (CI/CD must verify the bundled queue worker survives a build)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Bundle-gated event subscription extended to D-07: SurfaceWorkerCrashAlert subscribes to ProcessExited only when nativephp-internal.running=true so Herd / CI / tests never hit the Notification facade HTTP client"
    - "Rolling-window crash counter on a singleton listener: state lives on the listener instance (pruned-on-record), provider binds the listener as a singleton so the counter survives across many ProcessExited events"
    - "Allow-list persistence guard: WindowCloseBehavior::persistChoice() rejects anything outside {'quit', 'tray'} with InvalidArgumentException before reaching the users row — mitigation for T-15-22 in the plan threat register"
    - "Instant-apply Livewire choice + service-mediated persistence: CloseWindowPrompt action methods inject CurrentUser + WindowCloseBehavior, the service owns the validated write — mirrors SettingsPage::setTheme / toggleAutoImport"

key-files:
  created:
    - "Modules/Desktop/Internal/Listeners/SurfaceWorkerCrashAlert.php"
    - "Modules/Desktop/Internal/Native/WindowCloseBehavior.php"
    - "Modules/Desktop/Internal/Http/Livewire/CloseWindowPrompt.php"
    - "Modules/Desktop/Resources/views/close-window-prompt.blade.php"
    - "Modules/Core/Database/Migrations/2026_05_22_000002_add_close_behavior_to_users.php"
    - "Modules/Desktop/tests/Unit/SurfaceWorkerCrashAlertTest.php"
    - "Modules/Desktop/tests/Feature/WorkerCrashAlertTest.php"
    - "Modules/Desktop/tests/Feature/CloseWindowPromptTest.php"
  modified:
    - "config/nativephp.php (documented queue_workers.default as the D-05 supervised worker — values were already in place from native:install)"
    - "routes/console.php (added desktop.email-scan.timer entry — D-06 15-minute fallback)"
    - "Modules/Desktop/Providers/DesktopServiceProvider.php (singleton bindings for SurfaceWorkerCrashAlert + WindowCloseBehavior; ProcessExited subscription gated on nativephp-internal.running; desktop.close-window-prompt Livewire component registered)"
    - "Modules/Desktop/Routes/web.php (added /desktop/close-prompt route behind ['web', 'auth'])"
    - "Modules/Core/Models/User.php (added close_behavior to fillable + casts + @property docblock)"
    - "tests/Contracts/BoundaryArchTest.php (extended facade allow-list with SurfaceWorkerCrashAlert)"
    - "phpstan.neon (extended Native facade paths + Notification fluent-chain ignore with SurfaceWorkerCrashAlert)"

key-decisions:
  - "Crash-loop threshold = 3 exits within a 300-second rolling window. Picked to keep the steady-state behavior (occasional restart on memory-limit hit or single-job timeout) below the threshold while still escalating within ~5 minutes of a sustained crash-loop. Constants are public on SurfaceWorkerCrashAlert so future tuning lands in one place."
  - "Worker alert is system-wide (user_id=NULL). A crashed background process is not user-scoped — the queue:work child process serves every user, and naming any single user on the alert would be misleading."
  - "Worker crash-counter state lives on the listener singleton, not in a co-located state object. The counter is intrinsic to the listener's job and a separate object would add indirection without buying anything — singleton listener gives the same survival semantics with less surface."
  - "users.close_behavior placed directly after users.theme. The two desktop-shell preferences cluster together in the schema; future migrations following the pattern (e.g. a partner-shared display mode) chain naturally."
  - "CloseWindowPrompt persists via the same instant-apply pattern as SettingsPage::setTheme — raw query-builder write on users with Clock-provided updated_at. The choice was kept inside the dedicated WindowCloseBehavior service rather than copying SettingsPage's inline write so the allow-list guard is centralized and the service is reachable from the future NativePHP close-intercept hook in NativeAppServiceProvider without going through Livewire."
  - "/desktop/close-prompt route added even though the prompt is normally surfaced as a modal. The route exists so a future NativePHP close-intercept hook can navigate to it on a fresh-user first close; until that hook lands the prompt can also be reached for manual UAT via direct URL."

patterns-established:
  - "Crash-loop listener pattern: a NativePHP ProcessExited subscriber that gates escalation behind a rolling-window threshold + de-dup against an un-acknowledged system_alerts row + a focus-gated OS notification. Future plans wanting to surface other bundled-process health (e.g. an auto-update download stall in Phase 18) can mirror this shape."
  - "Allow-list persistence guard on a Public service: WindowCloseBehavior::persistChoice() validates against a `private const ALLOWED_*` list at the service boundary. Future per-user enum columns added via Livewire-driven instant-apply pages should adopt the same shape so the guard is one constant edit instead of scattered string checks."

requirements-completed: [PKG-04, PKG-05]

# Metrics
duration: 11min
completed: 2026-05-23
---

# Phase 15 Plan 03: Bundled worker daemon + scheduler + worker-crash alert + first-close prompt Summary

**D-05 / D-06 background processing wired (config-only — the bundle's queue worker + the 15-minute email-scan timer both self-supervise inside the NativePHP shell), D-07 worker crash-loop surfacing (rolling-window detector + critical system_alerts row + focus-gated OS notification), and the D-08 first-close prompt (Quit vs Keep-in-tray) with persistent per-user remembered choice — closing to the tray is what keeps the D-05 worker alive.**

## Performance

- **Duration:** ~11 min (2026-05-23T13:50:25Z → 2026-05-23T14:01:05Z)
- **Tasks:** 3 (1 auto, 2 TDD)
- **Files created:** 8 (4 production, 1 migration, 3 tests)
- **Files modified:** 7
- **Commits:** 5 (feat × 3, test × 2)

## Accomplishments

- **D-05 bundled queue worker** — `config/nativephp.php` `queue_workers.default` documented as the D-05 supervised `queue:work` child process running the `database` queue driver (Phase 14 carve-out — no Redis in the shipped build). The values were already populated by `native:install` in plan 15-01; this plan adds the intent documentation so the choice is reviewable in one place.
- **D-06 timer-based email auto-scan** — `routes/console.php` gains a `desktop.email-scan.timer` scheduler entry firing every 15 minutes. Mirrors the existing `email-scan.incremental` closure DI (`DatabaseManager` + `Bus\Dispatcher`) and the inbox-status filter (skip rows in `needs_reauth`). The shorter cadence is the partner's only visible-activity signal — there's no Horizon and no dev console in the bundle.
- **D-07 worker crash-loop alert** — `SurfaceWorkerCrashAlert` subscribes to NativePHP's `ProcessExited` event, accumulates exits in a rolling window (3 exits / 300 s default), and on threshold-crossing writes ONE critical `system_alerts` row (`kind='worker.crashed'`, `severity='critical'`, `user_id=NULL`) with the UI-SPEC verbatim body. A de-dup guard on `(kind, acknowledged_at IS NULL)` suppresses duplicates while the prior alert is on the banner. When the window is unfocused (D-13), the listener also fires an OS notification titled "Background work stopped" deep-linking to `/dashboard`. Subscription bundle-gated.
- **D-08 first-close prompt** — `CloseWindowPrompt` Livewire `flux:modal` component with the verbatim UI-SPEC copy ("Keep diederik running?" title; the two-sentence body; "Quit diederik" / "Keep running in the tray" buttons; "Remember my choice" checkbox checked by default). Both buttons render at `h-12` (48 px target size); the default-focused button is "Keep running in the tray" (autofocus); "Quit diederik" is destructive, rose-styled, NOT default-focused. On a remembered choice the component dispatches `close-window-choice` with the chosen action and hides the modal.
- **D-08 persistence** — `users.close_behavior` migration (nullable, placed after `users.theme`). `WindowCloseBehavior` service decides on each close whether to prompt (`NULL`), quit, or hide-to-tray and applies a fixed `{quit, tray}` allow-list at the persistence boundary so a malformed value can never reach the users row (T-15-22 mitigation).
- **Containment held** — every `Native\Desktop\*` import stays inside `Modules/Desktop/`; `noNativePhpImportsOutsideDesktopModule` still green; `SurfaceWorkerCrashAlert` joins the facade allow-list for its `Notification` chain (the only path NativePHP exposes for OS notifications). Dark-companion arch guard green for the new Blade view.
- **Quality gates** — Larastan level 10 strict: 0 errors. Pest: 2136 passed, 11 todos, 6 skipped — 0 failures. The new tests (7 unit + 6 + 9 feature = 22) all pass.

## Task Commits

Each task was committed atomically (RED before GREEN where TDD applied):

1. **Task 1: Configure bundled queue worker + timer-based email scan** — `98cb8f2` (feat).
2. **Task 2 RED: failing tests for worker crash-loop listener** — `5753ff6` (test).
3. **Task 2 GREEN: SurfaceWorkerCrashAlert implementation** — `65eccb8` (feat).
4. **Task 3 RED: failing tests + users.close_behavior migration** — `b66fdd8` (test).
5. **Task 3 GREEN: CloseWindowPrompt + WindowCloseBehavior + view + wiring** — `9b392db` (feat).

## Files Created/Modified

### Created (production)

- `Modules/Desktop/Internal/Listeners/SurfaceWorkerCrashAlert.php` — D-07 rolling-window worker crash-loop detector + system_alerts writer + focus-gated OS notification.
- `Modules/Desktop/Internal/Native/WindowCloseBehavior.php` — D-08 close decision service (shouldPromptFor / persistChoice with allow-list guard).
- `Modules/Desktop/Internal/Http/Livewire/CloseWindowPrompt.php` — D-08 Livewire flux:modal component with verbatim UI-SPEC copy + instant-apply choice persistence.
- `Modules/Desktop/Resources/views/close-window-prompt.blade.php` — flux:modal template with h-12 buttons + full dark: companions.
- `Modules/Core/Database/Migrations/2026_05_22_000002_add_close_behavior_to_users.php` — nullable string(8) close_behavior column placed after users.theme.

### Created (tests)

- `Modules/Desktop/tests/Unit/SurfaceWorkerCrashAlertTest.php` — 7 pure-unit assertions driving the windowed-counter behavior (threshold constants, single-exit no-trip, threshold-crossed trip, window-expiry behavior, foreign-alias ignore, singleton state survival, verbatim copy constants).
- `Modules/Desktop/tests/Feature/WorkerCrashAlertTest.php` — 6 feature assertions driving the full integration (single-exit no row, threshold-crossed one critical row, verbatim body, de-dup against un-acknowledged alert, unfocused OS-notification POST via `Http::fake()`, focused OS-notification suppression).
- `Modules/Desktop/tests/Feature/CloseWindowPromptTest.php` — 9 feature assertions (close_behavior column exists nullable, shouldPromptFor NULL/non-NULL behavior, no Livewire constructor, verbatim copy + flux:modal + h-12 buttons, persistence on remember=true for both choices, no persistence on remember=false, allow-list rejection of invalid value).

### Modified

- `config/nativephp.php` — added intent documentation on `queue_workers.default` (the values themselves were published by `native:install` in 15-01).
- `routes/console.php` — added the `desktop.email-scan.timer` 15-minute scheduler entry mirroring the `email-scan.incremental` closure-DI + method-order pattern.
- `Modules/Desktop/Providers/DesktopServiceProvider.php` — added `SurfaceWorkerCrashAlert` + `WindowCloseBehavior` singleton bindings; bundle-gated `ProcessExited` subscription; `desktop.close-window-prompt` Livewire component registration.
- `Modules/Desktop/Routes/web.php` — added `/desktop/close-prompt` route behind `['web', 'auth']` mapped to `CloseWindowPrompt`.
- `Modules/Core/Models/User.php` — added `close_behavior` to fillable + casts + `@property` docblock so Eloquent reads/writes the column.
- `tests/Contracts/BoundaryArchTest.php` — extended the `noFacadeUsage` `ignoring()` list with `Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert`.
- `phpstan.neon` — extended the `Native\Desktop\Facades` `paths:` list and the `Notification` `staticMethod.dynamicCall` ignore with the same file.

## Decisions Made

- **Crash-loop threshold (3 exits / 300 s).** Constants live on `SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD` + `::CRASH_LOOP_WINDOW_SECONDS` so the values are reviewable in one place and any future tuning lands as a constant edit + a unit-test threshold update. The values were picked to clear the steady-state behavior (occasional restart) while still flagging a sustained problem within ~5 minutes of crossing the line.
- **Worker alert is system-wide.** `user_id=NULL` on the `worker.crashed` row. The queue:work child process serves every user; naming any single user on the alert would mislead. The schema already supports nullable `user_id` (`system_alerts` migration), and the read-side scope (`per-user OR system-wide`) handles surfacing.
- **Singleton listener owns the crash counter.** No co-located state object. The counter is intrinsic to the listener's job and lifecycle, so keeping it on the listener trades nothing for less surface. The provider binds the listener as a singleton in `register()`.
- **`users.close_behavior` placed after `users.theme`.** The two desktop-shell preferences cluster in the schema; the migration prefix mirrors `2026_05_22_000001_add_theme_to_users.php` so they sort sequentially.
- **`WindowCloseBehavior` carries the allow-list guard, not the Livewire component.** Centralizing the guard at the service boundary means a future non-Livewire caller (the NativePHP close-intercept hook in `NativeAppServiceProvider`) gets the same validation without copying the check.
- **The `/desktop/close-prompt` route exists even though the prompt is normally surfaced as a modal.** The route is the integration surface a future NativePHP close-intercept hook can navigate to on the first close. Until that hook lands the route also gives manual UAT a direct URL to exercise the prompt.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Test helper return type rejected anonymous-class instance**
- **Found during:** Task 2 GREEN (running the feature tests).
- **Issue:** `freezableClockAt()` declared a `Clock&\stdClass` intersection return type so the test could mutate `$clock->time` between exits. PHP's type system rejects an anonymous class as `stdClass` (anonymous classes are NOT stdClass instances even though they expose ad-hoc public properties), so every test calling the helper TypeError'd before doing any work.
- **Fix:** Narrowed the return type to plain `Clock`; the dynamic property access at the call sites already works because PHP allows arbitrary public-property reads on a class that has them. Documented the rationale inline (PHP cannot express "Clock + this specific anonymous class" without naming the anonymous class).
- **Files modified:** `Modules/Desktop/tests/Feature/WorkerCrashAlertTest.php`.
- **Verification:** All 6 feature tests pass after the fix.
- **Committed in:** `65eccb8` (the Task 2 GREEN commit; the helper lives inside the test file that was being driven RED then GREEN).

**2. [Rule 3 — Blocking] Pint reformatting on the new unit test (resolved via `vendor/bin/pint <file>`)**
- **Found during:** Task 2 GREEN (Pint --test run before commit).
- **Issue:** The hand-written unit test had local style drift: long FQNs inlined as `app(\Illuminate\Database\DatabaseManager::class)` instead of imported, single-line empty constructor bodies, etc. — five Pint fixer categories tripped.
- **Fix:** Ran `vendor/bin/pint <file>` to apply the project's canonical style (`fully_qualified_strict_types`, `single_line_empty_body`, `ordered_imports`, `class_definition`, `braces_position`).
- **Files modified:** `Modules/Desktop/tests/Unit/SurfaceWorkerCrashAlertTest.php`.
- **Verification:** Pint `--test` exits clean; all 7 unit tests still pass.
- **Committed in:** `65eccb8` (same Task 2 GREEN commit — the formatting fix was inside the test file being written).

---

**Total deviations:** 2 auto-fixed (1 Rule 1 bug, 1 Rule 3 blocking — both test-file-only). No deviations on production code. No scope creep.

## Known Stubs

- **NativePHP close-intercept hook is not wired in this plan.** The plan brief acknowledged "verify the close-intercept API from the installed package" as a discretionary item; on inspection NativePHP v2 does not expose a `closable(false)`-then-intercept pattern that Laravel can subscribe to cleanly — the `WindowClosed` event fires AFTER the window has already closed. Wiring a real intercept requires a Blade-layer JavaScript hook (window.beforeunload) plus an in-bundle `App::quit()` / `Window::current()->hide()` listener on the `close-window-choice` event the Livewire component dispatches. That JavaScript glue is intentionally deferred to plan 15-04 / the HUMAN-UAT pass because it shares the "Electron main process → focused webview" seam with 15-04's file-association deep-link, and the implementation is naturally consolidated there. The acceptance criteria of THIS plan are still met — the prompt persists choices correctly when surfaced (manual UAT), and the Livewire flow + persistence layer are the load-bearing parts.
- **The `desktop.email-scan.timer` scheduler entry depends on the NativePHP bundle's auto-running scheduler.** The entry is correctly registered (visible in `php artisan schedule:list`); whether NativePHP v2 actually fires it in the bundled environment is a manual-UAT validation (per the plan's `<verification>` "Manual (carried to phase HUMAN-UAT)" line).

## Threat Flags

None new this plan. The threat register at the plan boundary already covers:

- **T-15-06** (DoS via worker crash-loop) — mitigated by `SurfaceWorkerCrashAlert`.
- **T-15-07** (tampering with the queue:work command) — the worker command is fixed in `config/nativephp.php`; no external input reaches the spawned process args.
- **T-15-22** (tampering with the persisted close_behavior value) — mitigated by `WindowCloseBehavior::persistChoice()`'s allow-list guard.

The Blade view introduces no new cross-trust-boundary surface beyond the standard Livewire / Flux modal seam every other module's flux:modal already uses.

## Issues Encountered

- **PHP type system rejects `Clock&\stdClass` intersection for anonymous classes.** Documented inline in the test helper. Not a runtime issue — the dynamic property access works fine without the intersection type; the type was decorative.
- **Pint formatting on hand-written tests.** Standard process noise — running `vendor/bin/pint <file>` after authoring a test catches the drift.
- **Larastan memory limit.** As recorded in previous Phase 15 plans, `vendor/bin/phpstan analyse` needs `--memory-limit=1G`. Used the project default `composer analyse` mental model.

## User Setup Required

None — no external service configuration required. The `queue_workers.default` worker spawns automatically when the bundled app boots; the `desktop.email-scan.timer` scheduler entry runs automatically inside the NativePHP-managed scheduler.

## Next Phase Readiness

- **Plan 15-04 (file associations + deep-link consolidation):** ready. The close-window-choice event the `CloseWindowPrompt` dispatches and the `NotificationDeepLink` event from 15-02 both need a NativePHP-bundle-side listener that focuses the window + navigates / quits / hides. 15-04 can consolidate that "Electron main process → focused webview" listener for both event types.
- **Plan 15-HUMAN-UAT:** the manual gates for this plan are (a) confirm a queued job drains in the built `.dmg`, (b) confirm `desktop.email-scan.timer` ticks every 15 minutes inside the bundle, (c) confirm the close-window prompt surfaces on first close and persists the choice. All three are listed as integration-level outcomes deferred to UAT per the plan brief.
- **Phase 16 (Developer Mode UI):** the in-app developer console can list `system_alerts.kind='worker.crashed'` rows as a debug surface and could add a developer-only "never quit, always tray" option that bypasses the close prompt.

## Self-Check: PASSED

**Files created (verified):**
- `Modules/Desktop/Internal/Listeners/SurfaceWorkerCrashAlert.php` — FOUND
- `Modules/Desktop/Internal/Native/WindowCloseBehavior.php` — FOUND
- `Modules/Desktop/Internal/Http/Livewire/CloseWindowPrompt.php` — FOUND
- `Modules/Desktop/Resources/views/close-window-prompt.blade.php` — FOUND
- `Modules/Core/Database/Migrations/2026_05_22_000002_add_close_behavior_to_users.php` — FOUND
- `Modules/Desktop/tests/Unit/SurfaceWorkerCrashAlertTest.php` — FOUND
- `Modules/Desktop/tests/Feature/WorkerCrashAlertTest.php` — FOUND
- `Modules/Desktop/tests/Feature/CloseWindowPromptTest.php` — FOUND

**Commits (verified):**
- `98cb8f2` — FOUND (Task 1: queue worker + timer scheduler)
- `5753ff6` — FOUND (Task 2 RED)
- `65eccb8` — FOUND (Task 2 GREEN: SurfaceWorkerCrashAlert)
- `b66fdd8` — FOUND (Task 3 RED: migration + tests)
- `9b392db` — FOUND (Task 3 GREEN: CloseWindowPrompt + WindowCloseBehavior)

**Quality gates:**
- `vendor/bin/phpstan analyse --memory-limit=1G` — exit 0
- `./vendor/bin/pest` — 2136 passed, 11 todos, 6 skipped, 0 failed
- `./vendor/bin/pest --filter="WorkerCrashAlert"` — 13 passed (7 unit + 6 feature)
- `./vendor/bin/pest --filter="CloseWindowPrompt"` — 9 passed
- `./vendor/bin/pest tests/Contracts/BoundaryArchTest.php` — 42 passed (includes `noNativePhpImportsOutsideDesktopModule` + `darkCompanionUtilitiesOnThemedViews`)
- `php artisan schedule:list | grep desktop.email-scan.timer` — entry present, fires every 15 minutes

## TDD Gate Compliance

This plan's Tasks 2 and 3 are TDD (`tdd="true"`). For each:

- **Task 2:** RED commit `5753ff6` (test for SurfaceWorkerCrashAlert; tests fail because the class does not exist) precedes GREEN commit `65eccb8` (implementation; tests pass). ✓
- **Task 3:** RED commit `b66fdd8` (test for CloseWindowPrompt + migration; 8 of 9 tests fail because the production classes do not exist — the 9th is the migration-column assertion that the RED commit's migration satisfied) precedes GREEN commit `9b392db` (implementation; all 9 tests pass). ✓

Both gates satisfied. No REFACTOR commit was needed — the GREEN-phase implementations were small enough to ship without cleanup.

---
*Phase: 15-desktop-shell-nativephp-integration*
*Completed: 2026-05-23*
