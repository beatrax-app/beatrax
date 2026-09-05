# `Desktop` — code

The file-level map for the module.

## Directory layout

```
Modules/Desktop/
├── Public/
│   ├── Contracts/
│   │   ├── OsThemeSignal.php
│   │   └── RemembersPendingFileIntent.php
│   └── Events/
│       ├── FileOpenedFromOs.php
│       └── NotificationDeepLink.php
├── Internal/
│   ├── NativeAppServiceProvider.php
│   ├── Native/
│   │   ├── AppMenuBuilder.php
│   │   ├── AppWindow.php
│   │   ├── BoundedNativeApiClient.php
│   │   ├── DesktopColdStartVault.php
│   │   ├── DesktopKeyCustodian.php
│   │   ├── FileOpenHandoff.php
│   │   ├── FileOpenIntake.php
│   │   ├── FirstLaunchBootstrap.php
│   │   ├── LoopbackProbe.php
│   │   ├── NativeBiometricUnlock.php
│   │   ├── OsThemeProbe.php
│   │   ├── PendingFileIntent.php
│   │   ├── RelayListenerProcess.php
│   │   ├── SafeStorageSecretShield.php
│   │   ├── ShellHandoff.php
│   │   ├── ShellState.php
│   │   ├── SubmenuItem.php
│   │   ├── SyncListenerProcess.php
│   │   ├── WindowCloseBehavior.php
│   │   └── WindowFocusState.php
│   ├── Listeners/
│   │   ├── ApplyCloseWindowChoice.php
│   │   ├── ContinuePendingFileIntentAfterLogin.php
│   │   ├── DispatchOsNotification.php
│   │   ├── ForgetColdStartVaultOnKeyRotation.php
│   │   ├── DemandLockOnWindowHideOrClose.php
│   │   ├── HandleNativeOpenFile.php
│   │   ├── NavigateOnNotificationDeepLink.php
│   │   ├── RebuildAppMenuOnAuthChange.php
│   │   ├── StartSyncListenerOnEnable.php
│   │   ├── SurfaceWorkerCrashAlert.php
│   │   ├── TrackWindowFocus.php
│   │   ├── TriggerUpdateDownload.php
│   │   ├── VerifyAndAnnounceUpdate.php
│   │   └── VerifyAndInstallDownload.php
│   └── Http/
│       ├── CloseActionController.php
│       ├── Middleware/
│       │   ├── ContinueToStagedFile.php
│       │   ├── EnsureDatabaseReady.php
│       │   └── RecoverSealedLedger.php
│       └── Livewire/
│           ├── SetupScreen.php
│           ├── WelcomeScreen.php
│           ├── CloseWindowPrompt.php
│           └── FileStagingPage.php
├── Routes/
│   └── web.php
├── Resources/views/
├── Providers/
│   └── DesktopServiceProvider.php
└── tests/
    ├── Unit/
    └── Feature/
```

The persistent macOS tray is composed directly in the Electron main
process by `scripts/nativephp_inject_persistent_tray.php` — not in
PHP — because the tray must survive PHP-side restarts. The
`AppMenuBuilder` here composes the application menu; the tray is a
separate, longer-lived UI element.

## Public API

- **Contracts/**
  - `OsThemeSignal::currentOsTheme()` → `'light'|'dark'|null`. Bound only
    inside the NativePHP bundle. The layout's
    `app()->bound(OsThemeSignal::class)` check is the documented
    discoverability gate.
  - `RemembersPendingFileIntent::remember(string $path, string $extension): void`
    — the contract's only method, for the OS-supplied file path the
    user opened before logging in. It is called from a shell event and
    so binds to `FileOpenHandoff`, which writes a device-local slot
    rather than a session. Reading it back is Internal
    (`PendingFileIntent::pending()`, then `clear()`), so across the
    module boundary the contract is write-only.
- **Events/**
  - `FileOpenedFromOs` — `(string $path, string $extension)`. Raised
    by `FileOpenIntake::receive()` after validation succeeds — the
    already-canonicalised path and its lower-cased extension, so no
    subscriber re-checks either. Subscribed by `Import` and
    `Receipts`.
  - `NotificationDeepLink` — `(string $url)`. Raised when the user
    clicks an OS notification carrying a deep link.

## Internal services

- `Internal/NativeAppServiceProvider` — the second provider this
  module registers. Owns the NativePHP-side bindings: the
  close-intercept hook, the persistent menu, and the desktop
  shell's bootstrap. `phpIni()` is the bundle's whole ini override
  set — the 20M upload pair, the 120s execution ceiling, and
  `zend.exception_ignore_args=1`, without which anything rendering a
  stack trace writes the first 15 characters of every string
  argument into the daily log.
- `Internal/Native/FirstLaunchBootstrap` — `runPendingMigrations()`
  chains the framework migrator with `Core::EnsureAppKey`. Both steps
  idempotent on every launch.
- `Internal/Native/AppMenuBuilder` — composes the application menu
  shown by the bundle on macOS / Windows.
- `Internal/Native/WindowCloseBehavior::choiceFor($user)` — reads
  `users.close_behavior` and returns `'quit'|'tray'|null`, `null`
  meaning the user has not been asked yet (`shouldPromptFor($user)`
  is the same question phrased for the caller). `persistChoice($user,
  $choice)` is the write and throws on anything outside
  `{quit, tray}`.
- `Internal/Native/WindowFocusState` — the focused / blurred flag,
  written by `TrackWindowFocus` on `WindowFocused` / `WindowBlurred`
  and kept on `ShellState`, because the requests that read it are not
  the requests that write it. Absent reads as focused.
- `Internal/Native/OsThemeProbe` — concrete `OsThemeSignal`.
- `Internal/Native/BoundedNativeApiClient` — bound over NativePHP's
  `Client` in `DesktopServiceProvider`, holding every Electron API
  call to 15 seconds against the vendor's hour. `system/prompt-touch-id`
  is widened to 120s because that one waits on a person.
- `Internal/Native/FileOpenIntake::receive(string $path)` — the
  security boundary. Canonicalises with realpath, then checks the
  extension allow-list and the per-extension size cap; raises
  `FileOpenedFromOs` on success and returns silently otherwise.
- `Internal/Native/PendingFileIntent` — the session-scoped read half.
  `pending()` claims what `FileOpenHandoff` left on `ShellHandoff` into
  the reader's own session.
- `Internal/Native/FileOpenHandoff` — the write half, and the
  `RemembersPendingFileIntent` binding. It holds no session, because
  its one caller is a shell event that has none.
- `Internal/Native/ShellHandoff` — the slot a shell event leaves a fact
  in for the window to claim, for the app lock and the file intent
  alike. Reading spends it.
- `Internal/Native/ShellState` — the store underneath it: slot-keyed
  facts in the `database` cache store, read without spending, written
  forever or with a TTL. Everything a shell event has to leave behind
  goes through here, because the event's own process does not outlive
  the request.
- `Internal/Listeners/ApplyCloseWindowChoice` — handles the JS-glued
  POST that follows the close-window prompt. Applies either
  `App::quit()` or `Window::current()->hide()`.
- `Internal/Listeners/ContinuePendingFileIntentAfterLogin::handle()`
  — fires on Laravel `Login` and takes no arguments. It reads
  `PendingFileIntent::pending()` for both of that reader's effects: it
  claims the shell's hand-off into this session, and it drops an intent
  whose file has since gone so nobody is sent to a staging screen with
  nothing on it. The redirect itself is
  the `ContinueToStagedFile` middleware's, on the next HTML GET. The
  subscription is NOT bundle-gated — the round-trip must work in local
  dev / CI / test runs.
- `Internal/Listeners/DispatchOsNotification::handleNotificationDeliverable($event)`
  — the module's only notification handler, and the only one it needs:
  the Notifications module decides what to notify and raises one
  `NotificationDeliverable` for every trigger. It asks
  `SuppressionEvaluator::shouldDeliver()` first, `WindowFocusState`
  second, and swaps in a detail-free body when the decision carries the
  per-device hide-details preference.
  (`Modules/Desktop/tests/Unit/DispatchOsNotificationTest.php`)
- `Internal/Listeners/HandleNativeOpenFile::handle($event)` —
  subscribes to the NativePHP `OpenFile` event; feeds the path to
  `FileOpenIntake`.
- `Internal/Listeners/NavigateOnNotificationDeepLink::handle($event)`
  — calls `Window::current()->url($event->url)`.
- `Internal/Listeners/SurfaceWorkerCrashAlert::handle($event)` —
  rolling-window crash counter on `ShellState`; raises a `SystemAlert`
  on threshold-crossing. The window is pruned on the write and on the
  read, and only the newest three stamps are kept.
- `Internal/Listeners/TrackWindowFocus` — writes the focus flag on
  `WindowFocused` / `WindowBlurred`, and clears it on
  `ApplicationBooted` so a launch starts from the default.
- `Internal/Http/Livewire/SetupScreen` — first-run setup landing.
- `Internal/Http/Livewire/WelcomeScreen` — first-launch welcome.
- `Internal/Http/Livewire/CloseWindowPrompt` — the modal that
  records the close-behavior choice.
- `Internal/Http/Livewire/FileStagingPage` — `/desktop/file-staging`.
  Renders the dropped file plus the user's import options.

## Models + migrations

This module owns no domain models. The `users.close_behavior`
column it reads is owned by [`Core`'s migration](../core/code.md). The
`PendingFileIntent` state lives in the Laravel session. Everything
`ShellState` holds — the hand-off slots, the focus flag, the per-alias
crash window — lives in the `cache` table, which
`SyncCoverageIsDeclaredTest` lists as a framework table, so it never
travels. That is the right answer for all three: a crash on the desktop
is not a crash on the phone.

If the module ships migrations in the future (e.g. a file-staging
audit table), they will land under `Database/Migrations/`.

## Provider wiring

`DesktopServiceProvider::register()`:

- Singletons the internals whose identity matters: `AppMenuBuilder`,
  `ShellState`, `ShellHandoff`, `PendingFileIntent`,
  `ContinuePendingFileIntentAfterLogin`,
  `NavigateOnNotificationDeepLink`, `ApplyCloseWindowChoice`,
  `NativeBiometricUnlock`, `SafeStorageBackendProbe` and
  `DesktopKeyCustodian`. `WindowFocusState` and
  `SurfaceWorkerCrashAlert` are deliberately **not** on that list any
  more: both were bound as singletons to carry state from one shell
  event to the next, which a per-request container cannot do, and
  neither holds any state now. See
  [State a shell event writes does not outlive its request](architecture.md#state-a-shell-event-writes-does-not-outlive-its-request).
- Binds `RemembersPendingFileIntent` → `FileOpenHandoff`, the half
  that holds no session.
- Binds NativePHP's `Client` → `BoundedNativeApiClient`, globally and
  unconditionally, so no call can inherit the vendor's hour-long
  timeout.
- Behind `config('nativephp-internal.running') === true`, binds
  `OsThemeSignal` → `OsThemeProbe`, `KeyCustodian` →
  `DesktopKeyCustodian`, `ColdStartVault` → `DesktopColdStartVault`
  and `SecretShield` → `SafeStorageSecretShield`, and re-asserts
  `nativephp-internal.secret` from the live process environment.
  Outside the bundle none of those bindings exist: the layout's gated
  `app()->bound()` check decides the theme fallback, and Auth's
  pass-through defaults hold the session key.

`DesktopServiceProvider::boot()`:

- Pushes `ContinueToStagedFile` onto the `web` middleware group. Not
  bundle-gated, for the same reason the `Login` subscription below is
  not.
- Loads the module's migrations, routes, views and translations (each
  guarded on the directory existing).
- Registers the four Livewire components under the `desktop.*`
  namespace.
- Registers a queue `looping` callback that clears the per-process time
  limit and exits when the host app has gone. Not bundle-gated, and it
  must precede the gate: it has to light up inside the spawned
  `queue:work` process, which never sets `nativephp-internal.running`.
- Subscribes, above the gate: `ContinuePendingFileIntentAfterLogin` and
  `RebuildAppMenuOnAuthChange` to `Login` (the latter also to
  `Logout`), `ForgetColdStartVaultOnKeyRotation` to
  `AppLockPassphraseChanged`, and `StartSyncListenerOnEnable` to
  `DeviceSyncEnabled`, `SyncTransportCredentialsAvailable` and
  `AppLockUnlocked`. These have to work off-bundle, so nothing gates the
  subscriptions; whatever NativePHP each one touches is gated inside its
  own handler instead, and two of them touch none at all.
- Subscribes `HandleNativeOpenFile`, `DemandLockOnWindowHideOrClose` and
  `TrackWindowFocus` above the gate as well, and for a stronger reason
  than convenience: a shell event nothing off-bundle can drive is a
  guarantee nothing off-bundle can prove, which is how the
  session-writing versions of the first two shipped and how the focus
  flag came to be read as a constant. None of the three reaches Electron
  — one validates a path, the other two write a device-local slot.
- Returns at the gate unless `config('nativephp-internal.running')` is
  `true`. Everything after it is bundle-only: `DispatchOsNotification`,
  `SurfaceWorkerCrashAlert`, `NavigateOnNotificationDeepLink`, and the
  three auto-update listeners. Those are the *readers* of the focus flag;
  the writer sits above. CI and test runs stop at the return and never
  reach the NativePHP HTTP client.
