---
phase: 15-desktop-shell-nativephp-integration
plan: 02
subsystem: desktop
tags: [nativephp, electron, native-chrome, menu, system-tray, notifications, os-theme, dark-mode]

# Dependency graph
requires:
  - phase: 15-01
    provides: "NativeAppServiceProvider skeleton, WindowManager DI seam, Native\\Desktop\\* arch invariant, facade allow-list carve-out pattern"
  - phase: 15-05
    provides: "Brand-assets prebuild hook stages resources/brand/tray-icon.png + logo.svg into the working dir on every native:build"
  - phase: 15-06
    provides: "OsThemeSignal Public contract (Modules/Desktop/Public/Contracts/OsThemeSignal.php) — the seam OsThemeProbe implements; app-layout dark-class resolution reads it"
provides:
  - "AppMenuBuilder — D-11 application-menu composition (verbatim UI-SPEC labels for File and Help additions)"
  - "TrayMenuBuilder — D-09 system-tray context-menu composition (three-row verbatim order)"
  - "OsThemeProbe — D-16 OS-theme read wrapping Native\\Desktop\\Facades\\System::theme() behind the OsThemeSignal contract; binding gated on nativephp-internal.running"
  - "WindowFocusState — D-13 shared focus-flag singleton; flipped by NativePHP WindowFocused / WindowBlurred event subscribers"
  - "NotificationDeepLink — Public event carrying the in-app screenRoute URL; fired back into Laravel when a native notification is clicked (D-14 deep-link)"
  - "DispatchOsNotification — D-12 listener subscribing to TransactionImported / DriftAlertOpened / ForecastShortfallDetected; D-13 focus-gate suppresses OS notifications when the window is focused"
  - "Native chrome wiring in NativeAppServiceProvider.boot() — Window::open()->rememberState() (D-10), Menu::create(...) (D-11), MenuBar::create()->icon()->withContextMenu(...) (D-09)"
affects: [15-03 (worker daemon health surface — wires ProcessExited into the same OS-notification dispatcher), 15-04 (file-association deep-link), 16 (Developer Mode UI consumes WindowFocusState + NotificationDeepLink shape), 17 (CI/CD), 18 (auto-update notifications)]

# Tech tracking
tech-stack:
  added: ["Native\\Desktop\\Facades\\{Menu, MenuBar, Notification, System} usage (quarantined inside Modules/Desktop)"]
  patterns:
    - "Native-chrome carve-out pattern extended from 15-01: facade allow-list (BoundaryArchTest + phpstan.neon) admits OsThemeProbe + DispatchOsNotification alongside the existing provider + two builders"
    - "Bundle-gated binding/subscription pattern: OsThemeSignal contract binding AND OS-notification event subscriptions both gated on nativephp-internal.running=true so Herd / CI / tests never reach the NativePHP HTTP client"
    - "Listener handler-method-per-event-category pattern: DispatchOsNotification exposes handleTransactionImported / handleDriftAlert / handleForecastShortfall as separate public methods so each event class can be subscribed independently and unit-tested by direct method call"

key-files:
  created:
    - "Modules/Desktop/Internal/Native/AppMenuBuilder.php"
    - "Modules/Desktop/Internal/Native/TrayMenuBuilder.php"
    - "Modules/Desktop/Internal/Native/OsThemeProbe.php"
    - "Modules/Desktop/Internal/Native/WindowFocusState.php"
    - "Modules/Desktop/Internal/Listeners/DispatchOsNotification.php"
    - "Modules/Desktop/Public/Events/NotificationDeepLink.php"
    - "Modules/Desktop/tests/Unit/AppMenuBuilderTest.php"
    - "Modules/Desktop/tests/Unit/TrayMenuBuilderTest.php"
    - "Modules/Desktop/tests/Unit/OsThemeProbeTest.php"
    - "Modules/Desktop/tests/Unit/WindowFocusStateTest.php"
    - "Modules/Desktop/tests/Unit/DispatchOsNotificationTest.php"
  modified:
    - "Modules/Desktop/Internal/NativeAppServiceProvider.php (extended boot() with window/menu/tray wiring)"
    - "Modules/Desktop/Providers/DesktopServiceProvider.php (singleton bindings + event subscriptions, both gated on nativephp-internal.running)"
    - "Modules/Desktop/tests/Unit/NativeAppServiceProviderTest.php (resolved two ->todo() stubs from 15-01)"
    - "tests/Contracts/BoundaryArchTest.php (carve-out extended to admit OsThemeProbe + DispatchOsNotification)"
    - "phpstan.neon (facade-rule ignores extended with the new files; Notification fluent-chain dynamic-call ignore added)"

key-decisions:
  - "Test names lock the plan filter — `it('notification suppressed when focused', ...)` matches the plan acceptance-criteria `--filter='notification suppressed when focused'`."
  - "Listener subscriptions are bundle-gated (nativephp-internal.running=true). Outside the bundle, in-app SystemAlertsBanner handles every event — there is no native shell to push notifications into, and an ungated subscription would HTTP-POST into a non-existent NativePHP HTTP API on every test that fires DriftAlertOpened / TransactionImported / ForecastShortfallDetected."
  - "AppMenuBuilder::build() returns an array of MenuItem objects (not a wrapped Menu) so NativeAppServiceProvider can spread them through Menu::create(...) — the canonical NativePHP installation entry point."
  - "TrayMenuBuilder::build() returns a wrapped Menu (NativeMenu) so MenuBar::create()->withContextMenu(Menu $menu) accepts it directly (per the v2 facade signature)."
  - "OsThemeProbe and DispatchOsNotification join the native-chrome facade allow-list — both genuinely need a NativePHP facade with no constructor-injection seam (System::theme() and Notification::title()->...->show()). Listener owns one collaborator + one URL generator via constructor DI; everything else stays facade-free."
  - "TransactionImported handles BOTH the 'Import finished' (CSV/CAMT/MT940/PDF) and 'New receipts found' (eml/mbox) UI-SPEC categories — switched on the row's source_format. No upstream event change required."
  - "Menu routes use existing route names with SC3 routing caveat: File 'Scan email now' → inboxes.index (the user-facing flow that owns the scan-now button); Help 'About diederik' → settings (the surface that owns app metadata today); Tray 'Open diederik' → dashboard."

patterns-established:
  - "Bundle-gated subscriptions: any cross-module event handler that triggers a Native\\Desktop\\Facades\\* call must gate its DesktopServiceProvider::boot() subscription on nativephp-internal.running=true — otherwise CI / Herd test runs that fire the upstream event will HTTP-POST to a non-existent NativePHP API."
  - "Verbatim copy constants: every locked UI-SPEC label (menu titles, notification titles) lives as a public class constant on the builder/listener so a single edit changes both the production label and the test assertion source-of-truth."
  - "Native facade chain ignore: the noFacadeRule and staticMethod.dynamicCall ignores in phpstan.neon use precise per-facade regex + per-file path lists so a future leak (a new file using a Native\\Desktop facade) fails CI loudly instead of being silently absorbed."

requirements-completed: [PKG-05]

# Metrics
duration: 92min
completed: 2026-05-23
---

# Phase 15 Plan 02: Native window, app menu, system tray, OS-theme probe and context-aware OS notifications Summary

**Full native chrome (D-09 / D-10 / D-11 / D-12 / D-13 / D-14 / D-16 / D-19) — stateful window, complete app menu with diederik-specific File/Help additions, system-tray with verbatim three-row context menu, OS-theme probe behind the OsThemeSignal contract, and bundle-gated OS notifications that fire only when unfocused and deep-link on click.**

## Performance

- **Duration:** 92 min (2026-05-23T12:10:00Z → 2026-05-23T13:42:58Z)
- **Started:** 2026-05-23T12:10:00Z
- **Completed:** 2026-05-23T13:42:58Z
- **Tasks:** 2 (both TDD)
- **Files created:** 11 (5 production, 5 unit tests, 1 Public event)
- **Files modified:** 5
- **Commits:** 3 (feat × 2, test × 1)

## Accomplishments

- **D-10 stateful native window** — `NativeAppServiceProvider::boot()` opens the main window at 1100×800 with `rememberState()`, so size + position persist across launches.
- **D-11 application menu** — `AppMenuBuilder::build()` composes the standard App/File/Edit/View/Window/Help set plus the diederik-specific File "Import file…" / "Scan email now" and Help "GitHub repo" / "Report an issue" / "About diederik" entries (verbatim UI-SPEC copy, locked as public class constants).
- **D-09 system tray** — `TrayMenuBuilder::build()` returns the three-row context menu in exact order: "Open diederik" / "Scan email now" / "Quit"; the tray icon is `resources/brand/tray-icon.png` (monochrome template image staged by plan 15-05).
- **D-13 / D-14 context-aware OS notifications** — `DispatchOsNotification` subscribes to `TransactionImported`, `DriftAlertOpened`, and `ForecastShortfallDetected`; the focus-gate (`WindowFocusState`) suppresses the OS notification when the window is focused (in-app `SystemAlertsBanner` handles it instead); fired notifications carry a `NotificationDeepLink` click event with the in-app route URL as the reference so clicking deep-links into the focused window.
- **D-16 OS-theme read** — `OsThemeProbe` implements the `OsThemeSignal` Public contract (created in plan 15-06) by wrapping `Native\Desktop\Facades\System::theme()`; the layout consumes the contract, never the facade. The binding lights up only inside the NativePHP bundle (`nativephp-internal.running=true`) so Herd / CI / tests never reach the NativePHP HTTP client.
- **Native-chrome containment held** — every `Native\Desktop\*` import stays inside `Modules/Desktop/`; `noNativePhpImportsOutsideDesktopModule` arch invariant still green. The facade allow-list (`BoundaryArchTest` + `phpstan.neon`) extended to admit `OsThemeProbe` + `DispatchOsNotification` alongside the existing 15-01 carve-out (provider + two builders).
- **Quality gates** — Larastan level 10 strict: 0 errors. Pest: 2114 passed, 11 todos, 6 skipped (the 5 new `->todo()` deferrals here are documented `no v2 fake for Notification/Menu/MenuBar` cases per the NATIVEPHP-FAKES.md gate from 15-01).

## Task Commits

Each task was committed atomically:

1. **Task 1: Native window + app menu + system tray + OS-theme probe** — `5eaa579` (feat)
2. **Task 2: Context-aware OS notifications with deep-link click** — `1808b83` (feat)
3. **Test name alignment with plan filter** — `793ee2e` (test)

_Note: Task 1 includes the resolved `->todo()` cases from plan 15-01's `NativeAppServiceProviderTest` (Window-fake-backed automated, Menu/MenuBar-fake-absent explicit `->todo()` deferrals per NATIVEPHP-FAKES.md)._

## Files Created/Modified

### Created (production)

- `Modules/Desktop/Internal/Native/AppMenuBuilder.php` — D-11 application-menu composition (App/File/Edit/View/Window/Help + verbatim UI-SPEC additions on File and Help)
- `Modules/Desktop/Internal/Native/TrayMenuBuilder.php` — D-09 system-tray context-menu composition (three rows in exact order)
- `Modules/Desktop/Internal/Native/OsThemeProbe.php` — D-16 OS-theme read, OsThemeSignal contract implementation
- `Modules/Desktop/Internal/Native/WindowFocusState.php` — D-13 shared focus-flag singleton
- `Modules/Desktop/Internal/Listeners/DispatchOsNotification.php` — D-12 / D-13 / D-14 OS-notification dispatcher with per-event handler methods
- `Modules/Desktop/Public/Events/NotificationDeepLink.php` — final readonly Public event carrying the screen-route URL for click-deep-link

### Created (tests)

- `Modules/Desktop/tests/Unit/AppMenuBuilderTest.php` — 3 pure-composition assertions (standard set + File entries + Help entries)
- `Modules/Desktop/tests/Unit/TrayMenuBuilderTest.php` — 1 pure-composition assertion (three rows in exact order)
- `Modules/Desktop/tests/Unit/OsThemeProbeTest.php` — 3 assertions (bundle-gated binding, default-not-bound under Herd, contract surface)
- `Modules/Desktop/tests/Unit/WindowFocusStateTest.php` — 4 assertions (default unfocused, markFocused, markBlurred, singleton sharing)
- `Modules/Desktop/tests/Unit/DispatchOsNotificationTest.php` — 5 automated (forecast focused-suppression, forecast unfocused-fire, drift focused-suppression, drift unfocused-fire, NotificationDeepLink shape) + 5 `->todo()` deferrals (Notification-fake-absent payload-detail assertions per NATIVEPHP-FAKES.md)

### Modified

- `Modules/Desktop/Internal/NativeAppServiceProvider.php` — extended `boot()` with window dimensions + `rememberState()`, `Menu::create(...)` from `AppMenuBuilder`, `MenuBar::create()->icon()->withContextMenu(...)` from `TrayMenuBuilder`
- `Modules/Desktop/Providers/DesktopServiceProvider.php` — singleton bindings for `AppMenuBuilder` / `TrayMenuBuilder` / `WindowFocusState` / `DispatchOsNotification`; `OsThemeSignal` contract binding + event subscriptions gated on `nativephp-internal.running=true`
- `Modules/Desktop/tests/Unit/NativeAppServiceProviderTest.php` — resolved 2 `->todo()` stubs from 15-01 by adding `Http::fake()` to swallow `Menu`/`MenuBar` facade HTTP calls and renaming the deferral-case copy to name the missing fakes explicitly
- `tests/Contracts/BoundaryArchTest.php` — `noFacadeUsage` ignoring-list extended with `OsThemeProbe` + `DispatchOsNotification`
- `phpstan.neon` — `noFacadeRule` regex extended to admit `Menu|MenuBar|Window|System|Notification` facades on the per-file allow-list; `Notification` fluent-chain `staticMethod.dynamicCall` ignore added (the `@method static` docblock returning `static` triggers it on every link after the first); `width|height|rememberState` `method.nonObject` ignore added for the `Window::open()->...` fluent chain (untyped return per WindowManager contract)

## Decisions Made

- **Bundle-gated subscriptions/bindings.** Both the `OsThemeSignal` contract binding (`register()`) AND the four event subscriptions (`boot()`) gate on `nativephp-internal.running=true`. Under Herd / in tests / before the Electron shell is ready, the binding is absent and the subscriptions never fire — the in-app `SystemAlertsBanner` handles every event. This pattern is the right primitive for any future native-shell side-effect (auto-update prompts, ProcessExited surface in plan 15-03, file-open intent routing in 15-04).
- **`route()` global helper is permitted in module code.** CLAUDE.md forbids `auth()` / `request()` / `config()` / `app()` / `now()` but does not name `route()`. Constants for outbound URLs (GitHub repo, issue tracker) are absolute strings; in-app navigation uses `Menu::route('imports.new', ...)` (which delegates to the global `route()` helper inside the NativePHP MenuBuilder). The `UrlGenerator` is constructor-injected into `DispatchOsNotification` for the same purpose.
- **Listener exposes per-event-category handler methods publicly** rather than a single `handle(mixed $event)` switch — keeps each event/handler pair independently testable by direct method call.
- **No new route names invented this plan.** Menu and notification deep-links use existing route names (`imports.new`, `inboxes.index`, `dashboard`, `settings`, `drift.index`, `forecast.index`); a dedicated `about` route is out of phase scope and "About diederik" routes to `settings` (SC3 routing caveat).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Bundle-gated event subscriptions to avoid HTTP-POST regressions in unrelated test suites**
- **Found during:** Task 2 (post-implementation full-suite run)
- **Issue:** Subscribing `DispatchOsNotification` to `DriftAlertOpened` / `TransactionImported` / `ForecastShortfallDetected` unconditionally in `DesktopServiceProvider::boot()` caused every test in `DriftAlerts` / `Import` / `Forecasting` that fired one of those events to attempt an outbound HTTP POST to `http://localhost:4000/api/notification` (the NativePHP HTTP client). 7+ existing tests in `DriftEvaluatorTest` regressed with `ConnectionException`.
- **Fix:** Gate the four subscriptions on `nativephp-internal.running=true` in `boot()` — mirrors the OsThemeSignal contract-binding gate in `register()`. The early `return` short-circuits the subscription wiring entirely when not running inside the NativePHP bundle.
- **Files modified:** `Modules/Desktop/Providers/DesktopServiceProvider.php`
- **Verification:** Full Pest run (2114 passed) green after the gate; previously red tests in `DriftEvaluatorTest`, `EvaluateDriftListenerTest`, etc. now pass.
- **Committed in:** `1808b83` (Task 2 commit)

**2. [Rule 2 — Missing Critical] Added `Http::fake()` to `NativeAppServiceProviderTest` so the existing Window-fake test still passes after extending `boot()` with Menu/MenuBar facade calls**
- **Found during:** Task 1 (boot extension)
- **Issue:** Plan 15-01's `NativeAppServiceProviderTest` asserts the Window facade fake; extending `boot()` to also call `Menu::create(...)` and `MenuBar::create()->...->withContextMenu(...)` made the test attempt outbound HTTP POSTs (the Menu/MenuBar facades have no v2 fake and route to the NativePHP HTTP client).
- **Fix:** Added `Http::fake()` at the top of the Window-fake-backed test case so the Menu/MenuBar HTTP calls are swallowed; the Window assertion still runs against the fake. The two remaining `->todo()` cases now name the missing v2 fakes explicitly ("deferred to manual UAT — no v2 fake for Menu/MenuBar") per the NATIVEPHP-FAKES.md gate from 15-01.
- **Files modified:** `Modules/Desktop/tests/Unit/NativeAppServiceProviderTest.php`
- **Verification:** Test passes; the two `->todo()` cases continue to surface in test output as documented deferrals.
- **Committed in:** `5eaa579` (Task 1 commit)

**3. [Rule 3 — Blocking] phpstan ignore patterns rewritten to match larastan-strict-rules error messages**
- **Found during:** Task 1 (phpstan run)
- **Issue:** The 15-01-era phpstan ignore used `Static call to instance method Native\\Desktop\\Facades\\.*` and `Call to an undefined static method Native\\Desktop\\Facades\\.*` patterns. The active rule is `larastanStrictRules.noFacadeRule` with message shape `Native\Desktop\Facades\<Facade> facade should not be used.` — neither legacy pattern matched.
- **Fix:** Single regex matching all admitted facades (`Menu|MenuBar|Window|System|Notification` after Task 2) with `reportUnmatched: false` so the entry stays valid even when a facade is not used in a given file. Added a separate Notification-fluent-chain ignore (`staticMethod.dynamicCall`) for the `@method static static …` docblock shape that triggers on every chained call, and a Window-fluent-chain ignore (`method.nonObject`) for the untyped `PendingOpenWindow|void` return of the WindowManager contract.
- **Files modified:** `phpstan.neon`
- **Verification:** `vendor/bin/phpstan analyse --memory-limit=1G` exits 0 across the whole project.
- **Committed in:** `5eaa579` (Task 1 commit; the Notification-chain ignore added in `1808b83`)

---

**Total deviations:** 3 auto-fixed (1 Rule 1 bug, 1 Rule 2 critical, 1 Rule 3 blocking)
**Impact on plan:** All auto-fixes essential for correctness (deviation #1 unblocked existing tests, #2 unblocked the inherited test from 15-01, #3 made the facade carve-out actually work). No scope creep.

## Known Stubs

The deep-link click handler that consumes `NotificationDeepLink` is NOT shipped this plan. The event is fired with the right reference URL, but the in-app listener that navigates the focused window via `Window::current()->url(...)` belongs in plan 15-04 (file-association deep-link) alongside the analogous `FileOpenedFromOs` handler — both event types share the "focus the window and navigate it" seam, so the implementation is consolidated there.

`NotificationDeepLink` is therefore a complete Public event surface (final readonly, typed `screenRoute`, payload contract documented), but the listener is intentionally absent until 15-04. The plan acceptance criterion "Clicking an OS notification deep-links to the relevant screen" is the integration-level outcome, deferred to the manual UAT pass after 15-04 lands.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: navigation | `Modules/Desktop/Public/Events/NotificationDeepLink.php` | New cross-trust-boundary event surface from OS notification click → in-app navigation. Mitigated as T-15-03 in the plan threat model: the `screenRoute` is an app-emitted route URL produced when firing the notification (never user input); the destination screens sit behind `auth` middleware (cross-user 404 rules from Phase 12 still apply). Listener implementation lands in 15-04. |

## Issues Encountered

- **`Menu` and `MenuBar` facades have no v2 fake** — NATIVEPHP-FAKES.md (committed by plan 15-01) recorded this; the plan handled it cleanly by automating the builder-output assertions (pure composition, no facade fake needed) and marking the live-facade assertion `->todo()` with an explicit "deferred to manual UAT — no v2 fake for <Facade>" copy. No surprises.
- **`Window::open()` returns `PendingOpenWindow|void`** per the WindowManager contract — the fluent chain `->width()->height()->rememberState()` is untyped at the call site. A type-narrowing `assert(...)` would force a NativePHP-internal import; the cleaner choice is a `method.nonObject` ignore on the three method names in `phpstan.neon`.

## User Setup Required

None — no external service configuration required. The native chrome is configured by code at NativePHP boot; the brand assets (`resources/brand/tray-icon.png`, logo) were staged by plan 15-05.

## Next Phase Readiness

- **Plan 15-03 (worker daemon + scheduler):** ready. `DispatchOsNotification` already gates on `WindowFocusState`; the D-07 worker-crash handler can subscribe to NativePHP's `ProcessExited` event and reuse the same focus-gate + `Notification::title("Background work stopped")->...->show()` shape.
- **Plan 15-04 (file associations + `FileOpenedFromOs` deep-link):** ready. `NotificationDeepLink` already establishes the click-deep-link seam — 15-04's listener for both event types can consolidate the "focus the window and navigate it" implementation.
- **Plan 16 (Developer Mode UI):** ready. `WindowFocusState` is a stable Public-shape singleton it can read; `NotificationDeepLink` is the canonical click-event shape Dev Mode toasts can mimic.
- **Manual UAT carry-over:** `15-HUMAN-UAT.md` (lands as plan 15-04 / 15 closure) needs the live-facade assertions deferred here (`Menu::create()` actually installs the application menu, `MenuBar::create()` actually shows the tray icon, `Notification::title()->...->show()` actually fires an OS notification, the click actually fires `NotificationDeepLink`).

## Self-Check: PASSED

**Files created (verified):**
- `Modules/Desktop/Internal/Native/AppMenuBuilder.php` — FOUND
- `Modules/Desktop/Internal/Native/TrayMenuBuilder.php` — FOUND
- `Modules/Desktop/Internal/Native/OsThemeProbe.php` — FOUND
- `Modules/Desktop/Internal/Native/WindowFocusState.php` — FOUND
- `Modules/Desktop/Internal/Listeners/DispatchOsNotification.php` — FOUND
- `Modules/Desktop/Public/Events/NotificationDeepLink.php` — FOUND
- `Modules/Desktop/tests/Unit/AppMenuBuilderTest.php` — FOUND
- `Modules/Desktop/tests/Unit/TrayMenuBuilderTest.php` — FOUND
- `Modules/Desktop/tests/Unit/OsThemeProbeTest.php` — FOUND
- `Modules/Desktop/tests/Unit/WindowFocusStateTest.php` — FOUND
- `Modules/Desktop/tests/Unit/DispatchOsNotificationTest.php` — FOUND

**Commits (verified):**
- `5eaa579` — FOUND (Task 1: native window + app menu + system tray + OS-theme probe)
- `1808b83` — FOUND (Task 2: context-aware OS notifications with deep-link click)
- `793ee2e` — FOUND (test name alignment with plan filter)

**Quality gates:**
- `vendor/bin/phpstan analyse --memory-limit=1G` — exit 0
- `./vendor/bin/pest` — 2114 passed, 11 todos, 6 skipped
- `./vendor/bin/pest --filter="NativeAppServiceProvider"` — passes (1 fake-backed automated + 2 documented `->todo()` deferrals)
- `./vendor/bin/pest --filter="DispatchOsNotification"` — passes (5 automated + 5 documented `->todo()` deferrals)
- `./vendor/bin/pest --filter="notification suppressed when focused"` — passes (plan acceptance filter)
- `./vendor/bin/pest --filter="noNativePhpImportsOutsideDesktopModule"` — passes
- `./vendor/bin/pest tests/Contracts/BoundaryArchTest.php` — 42 passed

---
*Phase: 15-desktop-shell-nativephp-integration*
*Completed: 2026-05-23*
