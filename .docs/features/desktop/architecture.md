# `Desktop` — architecture

The `Desktop` module is the NativePHP quarantine: every
`use Native\Laravel\*` / `use Native\Desktop\*` import in the codebase
lives inside this module and nowhere else. It owns the first-launch
bootstrap (migrations + APP_KEY mint), the application menu, the
window-close behaviour, the OS-notification dispatcher, the OS
file-open intake, the OS-theme probe, the worker-crash-loop watchdog,
and the file-staging Livewire page that lets a user route a
dropped-onto-the-app file through the import pipeline.

## What this module is for

NativePHP is the project's desktop shell ([ADR 0006](../../adr/0006-nativephp-desktop-shell.md)).
The architecture decision pinpoints why every other module needs to
stay NativePHP-free: the shipped bundle runs inside an Electron host;
the Herd dev environment, the CI test runs, and any future headless
deployment do not. If `Modules/Forecasting` or `Modules/Categorization`
imported `Native\Laravel\Window`, those modules would only run under
the bundle, and the test suite would have to mock NativePHP across the
whole codebase. Quarantining the imports here keeps every other
module unit-testable under plain Laravel.

The `noNativePhpImportsOutsideDesktopModule` arch invariant is the
contract: any module outside `Modules\Desktop\` that imports
`Native\…` fails the test suite.

What the module explicitly does NOT do:

- It never owns business logic. The dispatchers and listeners here
  glue NativePHP events to in-app domain events; the response to a
  domain event lives in the owning module.
- It never bypasses the bundle gate. Every NativePHP-coupled
  subscription is registered only when
  `config('nativephp-internal.running') === true`, so Herd / CI runs
  never reach the NativePHP HTTP client.
- It never owns the secret-key store. APP_KEY regeneration is owned
  by `Core::EnsureAppKey`; this module's `FirstLaunchBootstrap` only
  chains the call.

## Module boundary

`Public/` exposes the cross-module contracts and events:

- **Contracts/**
  - `OsThemeSignal::current()` — returns the current OS theme
    (`light` / `dark` / `null` for "unknown"). Bound to
    `Internal\Native\OsThemeProbe` ONLY inside the NativePHP bundle;
    under Herd / CI the binding is absent and the app-layout falls
    through to the client-side `prefers-color-scheme` pre-paint
    script. "Absence of a binding is itself the signal."
  - `RemembersPendingFileIntent::remember($path) / consume()` — the
    session-scoped pending-intent store the file-open listeners
    persist into.
- **Events/**
  - `FileOpenedFromOs` — raised by `Internal\Native\FileOpenIntake`
    after a validated file path is admitted. Listeners in `Import`
    and `Receipts` subscribe and either start an import or stage the
    file pending login.
  - `NotificationDeepLink` — raised when the user clicks an OS
    notification with a deep-link payload. The internal listener
    `NavigateOnNotificationDeepLink` handles it via
    `Window::current()->url(...)`.

`Internal/` houses every NativePHP-coupled implementation:

- **Internal/Native/**
  - `FirstLaunchBootstrap` — chains the migration runner with
    `Core::EnsureAppKey`. Idempotent on every launch.
  - `AppMenuBuilder` — the macOS / Windows application menu
    composition.
  - `WindowCloseBehavior` — the minimize-to-tray vs quit-on-close
    decision service. Reads `users.close_behavior`.
  - `WindowFocusState` — singleton holding the current
    focused / blurred flag, flipped by the `WindowFocused` /
    `WindowBlurred` event subscriptions.
  - `OsThemeProbe` — concrete `OsThemeSignal` reading the OS
    setting.
  - `FileOpenIntake` — the security boundary for OS-supplied paths.
    Validates the path is admissible (extension allow-list, size
    bound, no path traversal) before raising `FileOpenedFromOs`.
  - `PendingFileIntent` — session-scoped store the
    `RemembersPendingFileIntent` contract binds to.
- **Internal/NativeAppServiceProvider** — the NativePHP-side
  provider. Registers the close-intercept hook, the persistent
  macOS tray (composed directly in the Electron main process by
  `scripts/nativephp_inject_persistent_tray.php`), and the desktop
  shell's NativePHP-specific bootstrap.
- **Internal/Listeners/**
  - `ApplyCloseWindowChoice` — handles the JS-glued POST that
    follows the close-window prompt.
  - `ContinuePendingFileIntentAfterLogin` — fires on Laravel
    `Login`; routes the user to the staging page when an intent is
    pending.
  - `DispatchOsNotification` — the four `handle*` methods for
    `TransactionImported`, `DriftAlertOpened`,
    `ForecastShortfallDetected`, and the worker-crash alert.
    Gated by `WindowFocusState` so an in-focus window stays quiet.
  - `HandleNativeOpenFile` — bridges the NativePHP `OpenFile` event
    to `FileOpenIntake`.
  - `NavigateOnNotificationDeepLink` — handles
    `NotificationDeepLink` via `Window::current()->url(...)`.
  - `SurfaceWorkerCrashAlert` — accumulates `ProcessExited` events
    in a rolling window; raises a `SystemAlert` when the threshold
    is crossed.
- **Internal/Http/Livewire/**
  - `SetupScreen` — first-run setup landing.
  - `WelcomeScreen` — first-launch welcome.
  - `CloseWindowPrompt` — the modal that asks the user once whether
    Cmd-W minimises or quits.
  - `FileStagingPage` — the surface a dropped-onto-the-app file
    lands on after login.

## Key services + events

- `FirstLaunchBootstrap::run()` — runs the migrator, then runs
  `Core::EnsureAppKey`. Both steps are idempotent; the chain runs on
  every launch.
- `FileOpenIntake::admit($path)` — the security boundary for every
  OS-supplied path. Validates extension allow-list, size bound, and
  realpath canonicalisation before raising `FileOpenedFromOs`. A
  rejected path is logged at `warning` and dropped.
- `DispatchOsNotification` — the OS-notification dispatcher. Each of
  four in-app domain events maps to one `handle*` method; every method
  consults `WindowFocusState` first (in-focus = no notification, the
  in-app `SystemAlertsBanner` handles the visible signal).
- `WindowCloseBehavior::decide($user)` — reads
  `users.close_behavior` and returns the choice. The Electron close-
  intercept hook in `NativeAppServiceProvider` calls this and
  delegates to either `App::quit()` or `Window::current()->hide()`.
- `OsThemeProbe::current()` — concrete `OsThemeSignal` reading the
  OS theme. The binding is registered only inside the bundle, so the
  layout's `app()->bound(OsThemeSignal::class)` check falls through
  to the client-side pre-paint script under Herd / CI.
- `SurfaceWorkerCrashAlert::handle($event)` — handles the NativePHP
  `ProcessExited` event. Accumulates crashes in a rolling window; on
  threshold-crossing, raises a `system_alerts` row that
  `SystemAlertsBanner` will render.
- `FileOpenedFromOs` event — the cross-module surface for "the OS
  just handed us a file". Subscribed by `Import` (starts an import
  preview) and `Receipts` (starts a receipt match).

## Data flow

The first-launch boot chain:

```
NativePHP main process boots
  → boot Laravel kernel
       → CoreServiceProvider boots
       → DesktopServiceProvider boots (registers NativePHP bindings)
       → FirstLaunchBootstrap::run
            → Migrator::run                      (idempotent)
            → Core::EnsureAppKey::run            (sentinel-guarded)
  → Electron renders the first window
  → WelcomeScreen or LoginPage
```

The OS-file-open flow:

```
User opens a .csv from Finder / Explorer
  → Electron main fires App::OpenFile($path)
  → Laravel event bus receives OpenFile
  → HandleNativeOpenFile::handle
       → FileOpenIntake::admit($path)
            → extension allow-list, size, realpath
            → dispatch FileOpenedFromOs($path) on success
            → log warning on rejection
  → Import / Receipts listeners pick up FileOpenedFromOs
       → if user logged in: route to ImportPipeline preview
       → if no auth: PendingFileIntent::remember($path); redirect /login
  → after login: ContinuePendingFileIntentAfterLogin::handle
       → PendingFileIntent::consume() → /desktop/file-staging
```

The OS-notification dispatch:

```
Domain event raised (e.g. TransactionImported)
  → DispatchOsNotification::handleTransactionImported
       → WindowFocusState::isFocused?
            yes → no-op (SystemAlertsBanner handles it visibly)
            no  → Notification::title(…)->body(…)->deepLink(…)->send()
  → user clicks notification
  → NotificationDeepLink raised with payload
  → NavigateOnNotificationDeepLink → Window::current()->url(...)
```
