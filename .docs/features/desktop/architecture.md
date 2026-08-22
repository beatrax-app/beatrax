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

NativePHP is the project's desktop shell ([ADR 0006](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0006-nativephp-desktop-shell.md)).
The architecture decision pinpoints why every other module needs to
stay NativePHP-free: the shipped bundle runs inside an Electron host;
the local dev environment, the CI test runs, and any future headless
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
  `config('nativephp-internal.running') === true`, so local dev / CI runs
  never reach the NativePHP HTTP client.
- It never owns the secret-key store. APP_KEY regeneration is owned
  by `Core::EnsureAppKey`; this module's `FirstLaunchBootstrap` only
  chains the call.

## Module boundary

`Public/` exposes the cross-module contracts and events:

- **Contracts/**
  - `OsThemeSignal::currentOsTheme()` — returns the current OS theme
    (`light` / `dark`, or `null` when the OS itself holds no explicit
    preference — NativePHP's `SystemThemesEnum::SYSTEM`). Bound to
    `Internal\Native\OsThemeProbe` ONLY inside the NativePHP bundle;
    under local dev / CI the binding is absent and the app-layout falls
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

- `FirstLaunchBootstrap::runPendingMigrations()` — runs the migrator,
  then runs `Core::EnsureAppKey`. Both steps are idempotent; the chain
  runs on every launch. The same class also answers
  `hasPendingMigrations()` and `isFreshInstall()`, which is what
  `EnsureDatabaseReady` routes the first request on.
- `FileOpenIntake::receive($path)` — the security boundary for every
  OS-supplied path. Validates realpath canonicalisation, the
  extension allow-list and the per-extension size cap before raising
  `FileOpenedFromOs`. A rejected path is dropped in silence: nothing
  is logged and no event is emitted, and `FileOpenController` answers
  the Electron caller `204` either way.
- `DispatchOsNotification` — the sole desktop delivery adapter for the
  Notifications module's `NotificationDeliverable` event. The
  Notifications module decides *what* to notify and persists the row
  first; this one handler decides only *whether/how* to deliver it to
  the OS: `SuppressionEvaluator::shouldDeliver()` first (per-trigger
  toggles + quiet hours), then the focus gate (in-focus = no OS toast,
  the in-app `SystemAlertsBanner`/notification inbox handles it), then
  the per-device hide-details preference (swaps the real body for a
  detail-free fallback).
- `WindowCloseBehavior::choiceFor($user)` — reads
  `users.close_behavior` and returns the choice, with
  `shouldPromptFor($user)` for the null case (never asked) and
  `persistChoice($user, $choice)` for the write, which refuses
  anything outside `{quit, tray}`. The two values are `'quit'` and
  `'tray'` — hide to the menu bar, keeping the bundled worker and
  scheduler alive — not `'minimize'`. The Electron close-intercept
  hook in `NativeAppServiceProvider` reads the choice; the
  `App::quit()` / `Window::current()->hide()` calls themselves live
  in `ApplyCloseWindowChoice`.
- `OsThemeProbe::currentOsTheme()` — concrete `OsThemeSignal` reading the
  OS theme. The binding is registered only inside the bundle, so the
  layout's `app()->bound(OsThemeSignal::class)` check falls through
  to the client-side pre-paint script under local dev / CI.
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
       → FirstLaunchBootstrap::runPendingMigrations
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
       → FileOpenIntake::receive($path)
            → realpath, then extension allow-list, then per-extension cap
            → dispatch FileOpenedFromOs($path, $extension) on success
            → return without a trace on rejection (no event, no log)
  → Import / Receipts listeners pick up FileOpenedFromOs
       → if user logged in: route to ImportPipeline preview
       → if no auth: PendingFileIntent::remember($path); redirect /login
  → after login: ContinuePendingFileIntentAfterLogin::handle
       → PendingFileIntent::consume() → /desktop/file-staging
```

The OS-notification dispatch:

```
Notifications module persists a row + raises NotificationDeliverable
  → DispatchOsNotification::handleNotificationDeliverable
       → SuppressionEvaluator::shouldDeliver? (per-trigger + quiet hours)
            no  → stay quiet (row is already persisted either way)
            yes → WindowFocusState::isFocused?
                     yes → no-op (in-app banner/inbox handles it)
                     no  → Notification::title(…)->message(…)->event(…)->show()
  → user clicks notification
  → NotificationDeepLink raised with the app-emitted route as payload
  → NavigateOnNotificationDeepLink → Window::current()->url(...)
```

The window-close lifecycle (first close only, unless remembered):

```
User clicks the native X button
  → NativeAppServiceProvider's close-intercept hook reads
    WindowCloseBehavior::choiceFor($user)
       → null  → navigate to /desktop/close-prompt (CloseWindowPrompt)
                    → user picks Quit or Keep-in-tray, optionally
                      "remember my choice" (persists close_behavior)
                    → dispatches close-window-choice
                    → POST /desktop/close-action (CloseActionController,
                      re-validates against the {quit, tray} allow-list)
                    → ApplyCloseWindowChoice::apply()
                         → App::quit() or Window::current()->hide()
       → 'quit' / 'tray' → same ApplyCloseWindowChoice path directly
  → WindowHidden / WindowClosed (either outcome)
       → LockOnWindowHideOrClose::handle()
            → AppLockKeyService::withhold() — immediate lock, no grace
              period; the OS app-switcher snapshot must never show data
```

Cross-platform OS file-open ingress: macOS delivers `open-file` as a
native NativePHP event (`HandleNativeOpenFile`); the published Electron
project extends the same intent to Windows/Linux via cold-start
`process.argv` parsing and `app.on('second-instance')`, both of which
POST to `/desktop/file-open` (`FileOpenController`, `['web']`
middleware only — not `auth`, since a logged-out file-open must still
reach `PendingFileIntent`). Both entry paths converge on the single
`FileOpenIntake` validation boundary before `FileOpenedFromOs` fires.

## Key custody (desktop bundle)

`DesktopKeyCustodian` implements the Auth module's `KeyCustodian`
contract and is bound only when `nativephp-internal.running` is true
(`DesktopServiceProvider::register()`); on web/CI the pass-through
`NullKeyCustodian` default applies instead. It holds the already
Auth-unwrapped session data key at rest via Electron `safeStorage` (OS
keychain / DPAPI / Keychain Services): `store()` encrypts and returns
an opaque ciphertext blob, `read()` decrypts it back. When
`System::canEncrypt()` is false (headless CI, an early-boot race
before Electron initialises safeStorage) both methods degrade
gracefully to a pass-through, and the Auth module's own encrypted-
session custody applies unchanged. `DesktopKeyCustodian` never touches
`AppLockKeyWrap`/`AppLockKdf` — the wrap/unwrap KDF + secretbox stay
entirely in the Auth module; this class only protects the key *at
rest while unlocked*, between the moment Auth unwraps it and a later
caller retrieving it. `SafeStorageSecretShield` (the `SecretShield`
contract implementation, same bundle-only gate) delegates to the same
custodian to shield other persisted secrets (a biometric wrap blob, an
OAuth token blob) — no second facade-calling class is needed.

`NativeBiometricUnlock` is the sole caller of NativePHP's
`System::canPromptTouchID()`/`promptTouchID()`. It is registered as a
singleton but has **no caller yet** — native macOS Touch ID unlock is
not wired; the lock screen currently offers only the browser-native
WebAuthn biometric path. Wiring it requires a Desktop→Auth bridge
(an Auth Public contract bound here) plus a lock-screen affordance
inside the bundle. This class never imports any `Modules\Auth\*`
symbol beyond returning a bool — the actual key-release logic (and the
Touch-ID-bypass mitigation) stays entirely in Auth.

## Known risks

`LockOnWindowHideOrClose` locks the session the instant NativePHP
fires `WindowHidden`/`WindowClosed`, dispatched from the Electron main
process over its own internal HTTP channel. This has not been verified
to always carry the focused window's session cookie — if it does not,
the request-scoped `Session` this listener withholds against could be
a *different* (anonymous) session than the one the UI reads, and the
lock-on-close guarantee would silently not hold. This cannot be
verified outside a real desktop bundle build (tests share one
session, so they pass either way). The client-side privacy veil and
the grace-window server lock still cover the backgrounding case in the
meantime; confirming — or fixing, via a session-independent per-user
`locked_at` marker `AppLockMiddleware` could consult instead — is
open follow-up work before this guarantee is relied on in production.

## First-launch route gate

`EnsureDatabaseReady` exempts a small, deliberate set of route names so
the pending-migrations / fresh-install redirects can never loop:
`desktop.setup` / `desktop.welcome` (the gated surfaces themselves),
`signup` (the ceremony the welcome screen leads into), `setup` (the
post-signup wizard, reached only once already past welcome), `sw` (the
service-worker artifact, fetchable before any user exists), and
`site.webmanifest` / `pwa.icon` (the PWA manifest + icon set the
browser needs to offer the install affordance pre-login). The
`livewire.update` suffix exemption is separate: every Livewire
component update — including the signup form's own submit call — POSTs
through that one AJAX endpoint, and without the exemption the gate
would 302 the very request meant to create the first user.
