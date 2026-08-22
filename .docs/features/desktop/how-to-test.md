# `Desktop` — how to test

Practical recipes for exercising the `Desktop` module in isolation.

## Unit tests

- **Location:** `Modules/Desktop/tests/Unit/`
- **What they test:** the focus-state singleton flip; the
  pending-file-intent session round-trip in isolation; the
  close-window-behavior decision against a fixture `User`; the
  file-open intake's extension allow-list + size bound + realpath
  sanity.
- **Common stubs:** the tests build the listeners with stub
  collaborators (a `Session` array implementation, an in-memory
  `LoggerInterface` spy). No NativePHP packages are touched.

## Feature tests

- **Location:** `Modules/Desktop/tests/Feature/`
- **What they test:**
  - `FirstLaunchBootstrap` end-to-end against a fresh SQLite (the
    migrator runs, the APP_KEY sentinel materialises, a second run
    short-circuits).
  - The four Livewire screens (`SetupScreen`, `WelcomeScreen`,
    `CloseWindowPrompt`, `FileStagingPage`).
  - The `FileOpenedFromOs` raise / consume flow without the bundle
    (the listener is unconditional; subscribers in
    `Import` / `Receipts` consume it directly).
  - The continue-pending-intent-after-login flow.
  - The close-window applicator with both `'minimize'` and
    `'quit'` users.
- **Setup:** every test uses `RefreshDatabase`. Tests that exercise
  bundle-gated behaviour set
  `config(['nativephp-internal.running' => true])` BEFORE booting
  the application kernel — setting it after boot is a no-op (the
  subscriptions already ran).

## Contract / arch invariants

- The repo-wide `noNativePhpImportsOutsideDesktopModule` invariant
  is the load-bearing one for this module. It scans every PHP file
  under `Modules/` and (excluding `Modules/Desktop/`) fails on any
  `use Native\…` import.
- The repo-wide `noStoragePathHardCodedOutsideUserDataPathService`
  invariant covers the `FirstLaunchBootstrap` path: it must call the
  path service, never `database_path()` / `storage_path()` /
  `base_path()` directly.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Desktop/tests

# Just the first-launch bootstrap
vendor/bin/pest Modules/Desktop/tests/Feature --filter "FirstLaunch"

# Just the file-open intake
vendor/bin/pest Modules/Desktop/tests/Feature --filter "FileOpenIntake"

# Stop on first failure
vendor/bin/pest Modules/Desktop/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A new file extension is rejected by `FileOpenIntake`** — extend
  the allow-list in the intake AND add a feature-test case proving
  the new extension is admitted. Never silently widen the allow-list
  without a covering test; the intake is the OS-supplied-path
  security boundary.
- **OS notifications not firing in the bundle** — confirm
  `WindowFocusState::isFocused()` returns the expected value. The
  most common cause is the `WindowFocused` / `WindowBlurred`
  closures registered in the boot didn't fire because the bundle
  gate (`config('nativephp-internal.running')`) is false; the
  closures only register inside the bundle.
- **A pending file intent lost across the login boundary** — the
  intent is session-scoped. If the session cookie is regenerated on
  login (the default Laravel behaviour), the intent must persist
  across that regeneration. `PendingFileIntent` reads from a
  Session contract that the framework rebinds after regeneration
  for the same user; cross-user intent transfer is not supported by
  design.
- **`Window::current()` returning null in a deep-link handler** —
  the bundle has no focused window (e.g. the user closed it). The
  fallback is to spawn a new window via `Window::open(...)`; the
  current implementation logs `warning` and drops, matching the
  "absence of a binding is itself the signal" pattern used for
  `OsThemeSignal`.
- **A test failing because a NativePHP listener fired under CI** —
  the gate is missing. Wrap the subscription in
  `if ($config->get('nativephp-internal.running') !== true) { return; }`
  inside the boot method; the existing subscriptions in the
  provider are the template.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Desktop` module.

## Behavioral contracts

- **No NativePHP imports outside `Modules\Desktop\`.** Every
  `use Native\Laravel\…` and `use Native\Desktop\…` statement in the
  codebase must live inside this module. Enforced by the repo-wide
  arch invariant `noNativePhpImportsOutsideDesktopModule`.
- **No NativePHP listener fires under local dev / CI.** Every
  bundle-coupled listener subscription is gated by
  `config('nativephp-internal.running') === true`. Tests for the
  in-app behaviour exercise the domain events directly without
  involving NativePHP at all.
- **`OsThemeSignal` is bound only inside the bundle.** In local dev /
  CI the contract is unbound; the layout's `app()->bound(...)` check
  is the documented fallback signal. (The contract's docblock states
  "the absence of a binding is itself the signal".)
- **`FirstLaunchBootstrap` is idempotent across launches.** The
  migrator's own `run()` is a no-op when nothing is pending;
  `Core::EnsureAppKey` short-circuits via its sentinel file. Both
  steps may execute on every launch without side effects.
- **`FileOpenIntake::receive` rejects every inadmissible path.**
  The allow-list is exactly two extensions, `csv` and `eml`
  (`FileOpenIntake::SUPPORTED_EXTENSIONS`) — the document types the
  published Electron project registers with the OS, and nothing wider.
  The size bound is per-extension rather than one number
  (`FileOpenIntake::MAX_BYTES`): 50 MB for `csv`, because bank exports
  get large, and 5 MB for `eml`, so the same 6 MB file is admitted as
  a `.csv` and refused as an `.eml`. Realpath canonicalisation runs
  first, before the allow-list is consulted at all, so a `..`
  traversal resolving to nothing is refused on the spot. A rejected
  path is dropped in silence — no event, and no log line either; the
  OS never sees an error payload, which would betray the app's
  presence. (`tests/Feature/FileOpenedFromOsTest.php`)
- **The OS-notification dispatcher stays quiet while the window is
  focused.** Every `handle*` method on `DispatchOsNotification`
  consults `WindowFocusState::isFocused()` first; when true, the
  in-app `SystemAlertsBanner` is the visible surface and no OS
  notification is fired.
- **The pending-file-intent round-trip works regardless of bundle
  state.** The `Login` subscription for
  `ContinuePendingFileIntentAfterLogin` is NOT gated; local dev / CI runs
  must be able to exercise the staging-page route from a dropped
  file.
- **The close-behavior choice persists per user.**
  `users.close_behavior` is the source of truth; the close-prompt
  modal records the choice once.
- **The worker-crash watchdog only escalates on threshold-crossing.**
  `SurfaceWorkerCrashAlert` accumulates `ProcessExited` events in a
  rolling window; a single transient crash does not raise an alert.
- **`NotificationDeepLink` is the only path that drives in-app
  navigation from outside the bundle's window.** Subscribers
  (`NavigateOnNotificationDeepLink`) call
  `Window::current()->url(...)`; no other module navigates the
  window directly.
- **The persistent macOS tray is composed in the Electron main
  process — not in PHP.** The PHP-side `AppMenuBuilder` composes the
  application menu only. The tray script
  `scripts/nativephp_inject_persistent_tray.php` is invoked once at
  bundle assembly time.

## Edge cases

- **An OS-supplied path that points at a non-existent file** —
  `FileOpenIntake` realpath canonicalisation returns `false`; the
  intake drops it silently, without a log line.
- **A pending intent whose file was deleted by the time the user
  logs in** — `ContinuePendingFileIntentAfterLogin` re-checks the
  file before redirecting; a missing file clears the intent and the
  user lands on the dashboard.
- **The window is unfocused but the user closes the app immediately
  after** — the OS-notification fire-and-forget is fine; the
  notification surfaces in the OS notification centre after the app
  process exits.
- **A `ProcessExited` storm during a stuck migration** — the
  watchdog window-counts the exits; once the threshold is crossed,
  the `system_alerts` row is raised once (the alert's own
  acknowledged_at writes by `Core::AcknowledgeSystemAlert` are the
  dedup signal; the rolling counter is internal to the listener).
- **The user clicks an OS notification while another window of the
  app is focused** — `Window::current()` returns the focused window;
  the deep-link navigates that one. (The behaviour matches every
  other macOS multi-window app — the user's expectation.)
- **`config('nativephp-internal.running')` flips false mid-test** —
  the gate runs at boot; a test that needs to flip it must re-boot
  the application kernel. Setting the config at runtime AFTER boot
  has no effect (the subscriptions are already in place or already
  absent).
- **`FirstLaunchBootstrap` running before the database file exists**
  — the migrator creates the file as a side effect of the first
  `connection()` resolution; `EnsureAppKey` runs against the
  freshly-migrated DB. The chain order is intentional.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `EnsureAppKey`, `UserDataPathService`,
    `SystemAlert` writes, `AcknowledgeSystemAlert` (the alert
    surface).
  - [`Import`](../import/how-to-test.md) + [`Receipts`](../receipts/how-to-test.md)
    — both subscribe to `FileOpenedFromOs`.
  - [`DriftAlerts`](../drift-alerts/how-to-test.md),
    [`Forecasting`](../forecasting/how-to-test.md) — both raise events
    the `DispatchOsNotification` consumes.
  - NativePHP packages (`nativephp/laravel`, `nativephp/electron`,
    `Native\Desktop\Contracts\Shell`).
- **Depended on by**
  - [`Community`](../community/how-to-test.md) — consumes
    `Native\Desktop\Contracts\Shell`. The actual binding here lives
    inside `NativeAppServiceProvider`; Community's
    `NoOpShell` is the fallback when this module's binding is absent.
  - Every module that raises an OS-notification-able event — the
    publishing module never imports anything from `Desktop`; the
    subscription wiring lives here so the publisher can stay
    bundle-agnostic.

## Configuration + feature flags

- `users.close_behavior` — per-user `'minimize'|'quit'` decision the
  close-intercept reads.
- `users.theme` — per-user theme preference; the OS theme is one
  signal, the user preference overrides.
- `config('nativephp-internal.running')` — the bundle / local-dev gate.
  Set true by the NativePHP runtime; absent under local dev / CI.
- `NATIVEPHP_APP_VERSION` (env) — populates the `/health`
  `app_version` field. Owned by [`Core`](../core/how-to-test.md);
  surfaced to the user via the application-menu's "About" item
  built by `AppMenuBuilder`.
- No per-user OS-notification opt-out today; the focus-gate is the
  silencer.
