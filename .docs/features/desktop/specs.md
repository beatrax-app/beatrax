# `Desktop` — specs

The behavioural contract for the `Desktop` module.

## Behavioral contracts

- **No NativePHP imports outside `Modules\Desktop\`.** Every
  `use Native\Laravel\…` and `use Native\Desktop\…` statement in the
  codebase must live inside this module. Enforced by the repo-wide
  arch invariant `noNativePhpImportsOutsideDesktopModule`.
- **No NativePHP listener fires under Herd / CI.** Every
  bundle-coupled listener subscription is gated by
  `config('nativephp-internal.running') === true`. Tests for the
  in-app behaviour exercise the domain events directly without
  involving NativePHP at all.
- **`OsThemeSignal` is bound only inside the bundle.** Under Herd /
  CI the contract is unbound; the layout's `app()->bound(...)` check
  is the documented fallback signal. (The contract's docblock states
  "the absence of a binding is itself the signal".)
- **`FirstLaunchBootstrap` is idempotent across launches.** The
  migrator's own `run()` is a no-op when nothing is pending;
  `Core::EnsureAppKey` short-circuits via its sentinel file. Both
  steps may execute on every launch without side effects.
- **`FileOpenIntake::admit` rejects every inadmissible path.**
  Extension allow-list (`.csv`, `.xlsx`, `.pdf`, `.eml`, `.mbox`,
  `.json`, `.qif`, `.ofx` — confirm against the source), size bound
  (no >50 MB file is admitted), and realpath canonicalisation
  (no `..` traversal) are all enforced. A rejected path is logged at
  `warning` and dropped silently — the OS never sees an error
  payload, which would betray the app's presence.
- **The OS-notification dispatcher stays quiet while the window is
  focused.** Every `handle*` method on `DispatchOsNotification`
  consults `WindowFocusState::isFocused()` first; when true, the
  in-app `SystemAlertsBanner` is the visible surface and no OS
  notification is fired.
- **The pending-file-intent round-trip works regardless of bundle
  state.** The `Login` subscription for
  `ContinuePendingFileIntentAfterLogin` is NOT gated; Herd / CI runs
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
  intake logs `warning` and drops silently.
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
  - [`Core`](../core/specs.md) — `EnsureAppKey`, `UserDataPathService`,
    `SystemAlert` writes, `AcknowledgeSystemAlert` (the alert
    surface).
  - [`Import`](../import/specs.md) + [`Receipts`](../receipts/specs.md)
    — both subscribe to `FileOpenedFromOs`.
  - [`DriftAlerts`](../drift-alerts/specs.md),
    [`Forecasting`](../forecasting/specs.md) — both raise events
    the `DispatchOsNotification` consumes.
  - NativePHP packages (`nativephp/laravel`, `nativephp/electron`,
    `Native\Desktop\Contracts\Shell`).
- **Depended on by**
  - [`Community`](../community/specs.md) — consumes
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
- `config('nativephp-internal.running')` — the bundle / Herd gate.
  Set true by the NativePHP runtime; absent under Herd / CI.
- `NATIVEPHP_APP_VERSION` (env) — populates the `/health`
  `app_version` field. Owned by [`Core`](../core/specs.md);
  surfaced to the user via the application-menu's "About" item
  built by `AppMenuBuilder`.
- No per-user OS-notification opt-out today; the focus-gate is the
  silencer.
