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
│   │   ├── FirstLaunchBootstrap.php
│   │   ├── AppMenuBuilder.php
│   │   ├── WindowCloseBehavior.php
│   │   ├── WindowFocusState.php
│   │   ├── OsThemeProbe.php
│   │   ├── FileOpenIntake.php
│   │   └── PendingFileIntent.php
│   ├── Listeners/
│   │   ├── ApplyCloseWindowChoice.php
│   │   ├── ContinuePendingFileIntentAfterLogin.php
│   │   ├── DispatchOsNotification.php
│   │   ├── HandleNativeOpenFile.php
│   │   ├── NavigateOnNotificationDeepLink.php
│   │   └── SurfaceWorkerCrashAlert.php
│   └── Http/
│       ├── Middleware/
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
  - `RemembersPendingFileIntent::remember(string $path) /
    consume()` → `?string`. Session-scoped store for the OS-supplied
    file path the user opened before logging in.
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
  shell's bootstrap.
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
- `Internal/Native/WindowFocusState` — singleton holding the
  focused / blurred flag. Flipped by closures registered in the
  provider's boot on `WindowFocused` / `WindowBlurred`.
- `Internal/Native/OsThemeProbe` — concrete `OsThemeSignal`.
- `Internal/Native/FileOpenIntake::receive(string $path)` — the
  security boundary. Canonicalises with realpath, then checks the
  extension allow-list and the per-extension size cap; raises
  `FileOpenedFromOs` on success and returns silently otherwise.
- `Internal/Native/PendingFileIntent` — session-scoped store. Both
  the concrete class and the `RemembersPendingFileIntent` binding
  resolve to it.
- `Internal/Listeners/ApplyCloseWindowChoice` — handles the JS-glued
  POST that follows the close-window prompt. Applies either
  `App::quit()` or `Window::current()->hide()`.
- `Internal/Listeners/ContinuePendingFileIntentAfterLogin::handle($event)`
  — fires on Laravel `Login`. Reads `PendingFileIntent`; redirects
  to the staging page when an intent is present. The subscription is
  NOT bundle-gated — the round-trip must work in local dev / CI / test
  runs.
- `Internal/Listeners/DispatchOsNotification` — four `handle*`
  methods (one per domain event). Each consults `WindowFocusState`
  first and stays quiet when focused.
- `Internal/Listeners/HandleNativeOpenFile::handle($event)` —
  subscribes to the NativePHP `OpenFile` event; feeds the path to
  `FileOpenIntake`.
- `Internal/Listeners/NavigateOnNotificationDeepLink::handle($event)`
  — calls `Window::current()->url($event->url)`.
- `Internal/Listeners/SurfaceWorkerCrashAlert::handle($event)` —
  rolling-window crash counter; raises a `SystemAlert` on
  threshold-crossing.
- `Internal/Http/Livewire/SetupScreen` — first-run setup landing.
- `Internal/Http/Livewire/WelcomeScreen` — first-launch welcome.
- `Internal/Http/Livewire/CloseWindowPrompt` — the modal that
  records the close-behavior choice.
- `Internal/Http/Livewire/FileStagingPage` — `/desktop/file-staging`.
  Renders the dropped file plus the user's import options.

## Models + migrations

This module owns no domain models. The `users.close_behavior`
column it reads is owned by [`Core`'s migration](../core/code.md). The
`PendingFileIntent` state lives in the Laravel session, not a DB
table.

If the module ships migrations in the future (e.g. a file-staging
audit table), they will land under `Database/Migrations/`.

## Provider wiring

`DesktopServiceProvider::register()`:

- Singletons every native-chrome internal: `AppMenuBuilder`,
  `WindowFocusState`, `DispatchOsNotification`,
  `SurfaceWorkerCrashAlert`, `WindowCloseBehavior`,
  `PendingFileIntent`, `ContinuePendingFileIntentAfterLogin`,
  `HandleNativeOpenFile`, `NavigateOnNotificationDeepLink`,
  `ApplyCloseWindowChoice`.
- Binds `RemembersPendingFileIntent` → `PendingFileIntent`.
- Conditionally binds `OsThemeSignal` → `OsThemeProbe` only when
  `config('nativephp-internal.running') === true`. Outside the
  bundle the binding is absent; the layout's gated check decides
  fallback behaviour.

`DesktopServiceProvider::boot()`:

- Loads migrations, routes, views (all file-/dir-existence guarded).
- Registers the four Livewire components under the `desktop.*`
  namespace.
- Subscribes `ContinuePendingFileIntentAfterLogin` to Laravel
  `Login` unconditionally (the round-trip must work in local dev / CI).
- Bundle-gated: subscribes the focus-state flippers, the four
  `DispatchOsNotification` handlers, the `SurfaceWorkerCrashAlert`
  handler, the `HandleNativeOpenFile` handler, and the
  `NavigateOnNotificationDeepLink` handler. The gate is
  `config('nativephp-internal.running') === true`; CI / test runs
  short-circuit the boot and never reach the NativePHP HTTP client.
