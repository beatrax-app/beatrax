# `Desktop` — how to test

Practical recipes for exercising the `Desktop` module in isolation.

## Unit tests

- **Location:** `Modules/Desktop/tests/Unit/`
- **What they test:** the focus flag read back by an instance that
  never saw it written (`WindowFocusStateTest`); the OS-notification
  dispatcher's
  suppression-then-focus decision and its detail-free body
  (`DispatchOsNotificationTest`); the rolling crash counter
  (`SurfaceWorkerCrashAlertTest`); the menu's item shape
  (`AppMenuBuilderTest`); the 15-second bound on every Electron call
  (`BoundedNativeApiClientTest`); the safeStorage custody classes
  (`DesktopKeyCustodianTest`, `SafeStorageSecretShieldTest`,
  `DesktopColdStartVaultTest`); the build-patch scripts under
  `scripts/`, `require`d for their helper functions and run as text
  transforms over a stub of the published Electron source
  (`FileOpenIngressScriptsTest`, `InjectPersistentTrayScriptTest`,
  `ForceAdhocSigningScriptTest`); and
  the committed `build/entitlements.mac.plist` those scripts stage
  (`HardenedRuntimeEntitlementsTest`).
- **Common stubs:** these are unit tests by scope, not by isolation.
  They import NativePHP facades and let the facade reach its HTTP
  client, which `Http::fake()` answers — the loopback Electron API is
  never running under test. `DispatchOsNotificationTest` also uses
  `RefreshDatabase`, because the suppression decision it is checking
  reads real `notification_preferences` and `device_registry` rows.
- **What is NOT here:** the pending-file-intent round-trip, the
  close-window-behavior decision and the file-open intake all need a
  booted application and live under `Feature/`, not `Unit/`.

## Feature tests

- **Location:** `Modules/Desktop/tests/Feature/`
- **What they test:**
  - `FirstLaunchBootstrap` end-to-end against a fresh SQLite — the
    migrator runs, the APP_KEY sentinel materialises, a second run
    short-circuits (`FirstLaunchBootstrapTest`).
  - Three of the four Livewire screens: `WelcomeScreen`
    (`WelcomeScreenRedirectTest`), `CloseWindowPrompt`
    (`CloseWindowPromptTest`,
    `TheCloseQuestionCouldBeDismissedWithoutAnsweringItTest`) and
    `FileStagingPage` (`FileOpenedFromOsTest`,
    `TheFileHandOffStartedBelowTheFoldTest`,
    `ThePendingFileIntentIsNotWireWritableTest`).
  - **`SetupScreen` is covered by no test in this repository.** It is
    the screen `EnsureDatabaseReady` redirects to when migrations are
    pending, and its `poll()` is what re-drives the migrator — so the
    one surface that repairs a failed first-launch boot has nothing
    asserting that it does.
  - The file-open intake's extension allow-list, per-extension size
    bound and realpath canonicalisation, and the `FileOpenedFromOs`
    raise / consume flow without the bundle: the NativePHP `OpenFile`
    bridge (`HandleNativeOpenFile`) is bundle-gated, but the `Import`
    and `Receipts` subscriptions to `FileOpenedFromOs` are not, so a
    test raises the event through `FileOpenIntake` and the subscribers
    consume it directly (`FileOpenedFromOsTest`,
    `HandleNativeOpenFileTest`).
  - The pending-file-intent round-trip across the login boundary,
    including the stale intent that is dropped, the cross-user
    isolation, and the staging redirect that fires exactly once
    (`FileOpenedFromOsTest`).
  - The close-window-behavior decision against a fixture `User`, and
    the applicator with both `'tray'` and `'quit'` users
    (`CloseWindowPromptTest`,
    `TheTrayChoiceAskedWhichWindowWasFocusedTest`,
    `CloseActionControllerTest`).
- **Setup:** every test here gets `RefreshDatabase`, and almost none
  of them says so: the root `tests/Pest.php` binds it to
  `Modules/*/tests/Feature` wholesale, along with the module's own
  `TestCase`. `Unit/` is deliberately not bound, which is why a unit
  test that needs a database declares `uses(RefreshDatabase::class)`
  itself. Tests that exercise bundle-gated behaviour set
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
- **OS notifications not firing in the bundle** — there are two
  silencers and the first one is not this module's. Check
  `SuppressionEvaluator::shouldDeliver()` for the user and trigger:
  a per-trigger toggle off, or a quiet-hours window, returns a
  decision that never reaches the focus gate. If it says deliver,
  confirm `WindowFocusState::isFocused()` returns the expected value.
  It is read from the `cache` table, so
  `select value from cache where key like '%window-focused%'` says what
  the shell last reported; an empty result reads as focused, which is
  the launch default `ApplicationBooted` restores.
- **A pending file intent lost across the login boundary** — the read
  half is session-scoped, and the write half is not: the shell has no
  session to write one in, so `FileOpenHandoff` leaves the intent on
  `ShellHandoff` and `PendingFileIntent::pending()` claims it into
  whichever session first asks. An intent already claimed into a
  session does not cross to another user; one still waiting is claimed
  by the reader the window next serves, because nothing about it names
  a user.
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

- **No NativePHP imports outside `Modules\Desktop\`.** Every
  `use Native\Laravel\…` and `use Native\Desktop\…` statement in the
  codebase must live inside this module. Enforced by the repo-wide
  arch invariant `noNativePhpImportsOutsideDesktopModule`.
- **No NativePHP listener fires under local dev / CI.** Every
  subscription registered below the gate in `DesktopServiceProvider`
  is conditional on `config('nativephp-internal.running') === true`,
  and the handlers subscribed above it hold the same check in their own
  bodies. Tests for the in-app behaviour exercise the domain events
  directly without involving NativePHP at all.
  (`TheDeveloperMenuNothingCouldReachTest`, `OsThemeProbeTest`)
- **`OsThemeSignal` is bound only inside the bundle.** In local dev /
  CI the contract is unbound; the layout's `app()->bound(...)` check
  is the documented fallback signal. (The contract's docblock states
  "the absence of a binding is itself the signal".) (`OsThemeProbeTest`)
- **`FirstLaunchBootstrap` is idempotent across launches.** The
  migrator's own `run()` is a no-op when nothing is pending;
  `Core::EnsureAppKey` short-circuits via its sentinel file. Both
  steps may execute on every launch without side effects.
  (`FirstLaunchBootstrapTest`)
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
  presence. (`Modules/Desktop/tests/Feature/FileOpenedFromOsTest.php`)
- **The OS-notification dispatcher asks suppression first and the
  focus gate second.** `DispatchOsNotification` has one handler,
  `handleNotificationDeliverable`, for the Notifications module's
  `NotificationDeliverable`. It calls
  `SuppressionEvaluator::shouldDeliver()` before it looks at
  `WindowFocusState::isFocused()`, so a trigger the reader switched off
  or a quiet-hours window stays silent whether or not the window is
  focused; when the window is focused the in-app `SystemAlertsBanner`
  and the notification inbox are the visible surface instead. The order
  matters because only the first decision also carries the
  hide-details preference the body is swapped for.
  (`DispatchOsNotificationTest`,
  `TheNotificationOnlyEverArrivesWhenNothingIsFocusedTest`)
- **Neither delivery adapter re-implements suppression.**
  `onlyOneSuppressionEvaluator` in
  `tests/Contracts/BoundaryArchTest.php` fails the build if
  `DispatchOsNotification` or its mobile counterpart names a
  quiet-hours or per-trigger-toggle column directly instead of going
  through `SuppressionEvaluator::shouldDeliver()`.
- **The pending-file-intent round-trip works regardless of bundle
  state.** Neither the `Login` subscription for
  `ContinuePendingFileIntentAfterLogin`, nor the `OpenFile`
  subscription that starts the round-trip, nor the
  `ContinueToStagedFile` middleware that ends it is gated; local dev /
  CI runs must be able to drive the whole path from the shell's own
  event route. The same now holds for `WindowHidden` / `WindowClosed`,
  and for the same reason: a guarantee only a bundle can drive is one
  nothing here can prove.
  (`FileOpenedFromOsTest`, `AFileOpenedFromTheOsReachesTheWindowsSessionTest`,
  `AWindowCloseLocksTheWindowsOwnSessionTest`)
- **The close-behavior choice persists per user.**
  `users.close_behavior` is the source of truth; the close-prompt
  modal records the choice once. (`CloseWindowPromptTest`,
  `TheCloseQuestionCouldBeDismissedWithoutAnsweringItTest`)
- **The worker-crash watchdog only escalates on threshold-crossing.**
  `SurfaceWorkerCrashAlert` accumulates `ProcessExited` events in a
  rolling window; a single transient crash does not raise an alert. Two
  of the three suites drive the listener directly, which is the one
  thing the shell never does; the third posts the real event three
  times and throws the listener away between them, which is what a
  bundle does, and it is the only one of the three that failed while
  the counter lived on the object.
  (`SurfaceWorkerCrashAlertTest`, `WorkerCrashAlertTest`,
  `TheWatchdogCountedToOneAndTheWindowWasAlwaysFocusedTest`)
- **A shell event's state outlives the request that wrote it.** The
  same suite blurs the window through the real `_native/api/events`
  POST, discards the resolved instances, and asserts the OS
  notification is dispatched — the discriminating case, because a
  focus flag held on the object made every desktop notification
  suppress itself. `AShellEventKeepsNoStateTheNextEventCannotSeeArchTest`
  holds the shape.
  (`TheWatchdogCountedToOneAndTheWindowWasAlwaysFocusedTest`)
- **`NotificationDeepLink` is the only path that drives in-app
  navigation from outside the bundle's window.** Subscribers
  (`NavigateOnNotificationDeepLink`) call
  `Window::current()->url(...)`; no other module navigates the
  window directly. Nothing enforces the "no other module" half
  mechanically — it is a review convention — but the deep-link path
  itself, including the route it refuses, is held by
  `TheNotificationOnlyEverArrivesWhenNothingIsFocusedTest`.
- **The persistent macOS tray is composed in the Electron main
  process — not in PHP.** The PHP-side `AppMenuBuilder` composes the
  application menu only. The tray script
  `scripts/nativephp_inject_persistent_tray.php` is invoked once at
  bundle assembly time, and what it writes is pinned by
  `InjectPersistentTrayScriptTest`; `AppMenuBuilderTest` holds the
  menu's own item shape.

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
  - [`Notifications`](../notifications/architecture.md) — owns the one
    event `DispatchOsNotification` consumes, `NotificationDeliverable`,
    and the `SuppressionEvaluator` the handler consults before
    delivering. Triggers raised elsewhere — by
    [`DriftAlerts`](../drift-alerts/how-to-test.md),
    [`Forecasting`](../forecasting/how-to-test.md) and the rest —
    reach this module only after Notifications has persisted a row and
    raised that one event.
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

- `users.close_behavior` — per-user `'tray'|'quit'` decision the
  close-intercept reads.
- `users.theme` — per-user theme preference; the OS theme is one
  signal, the user preference overrides.
- `config('nativephp-internal.running')` — the bundle / local-dev gate.
  Set true by the NativePHP runtime; absent under local dev / CI.
- `NATIVEPHP_APP_VERSION` (env) — populates the `/health`
  `app_version` field. Owned by [`Core`](../core/how-to-test.md);
  surfaced to the user via the application-menu's "About" item
  built by `AppMenuBuilder`.
- The per-trigger toggles and quiet hours the `Notifications` module's
  `SuppressionEvaluator` reads are consulted before the focus gate; the
  focus gate is the last silencer, not the only one.
