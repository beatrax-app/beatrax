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
  is logged and no event is emitted. The Electron main process is
  best-effort either way — it forwards the path and does not wait to
  learn whether it was accepted.
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

### Submenus never hang off a role

`AppMenuBuilder` composes the application menu from two kinds of item, and
the difference is load-bearing:

- **Role items** — `Menu::app()`, `Menu::edit()`, `Menu::view()`,
  `Menu::window()`. These hand the whole menu to Electron, which supplies
  its own stock contents (Undo/Redo, Minimize/Zoom, and so on). They never
  carry a submenu.
- **`SubmenuItem`** — Beatrax's own menus: File, Help, and the
  developer-only submenu. It emits `type: 'submenu'` with its entries
  inline.

The developer submenu is gated on the signed-in user, and the boot-time
`Menu::create()` runs from `POST /_native/api/booted` — a route with no `web`
group, therefore no session and no user. `RebuildAppMenuOnAuthChange` listens
to `Login` and `Logout` and re-runs `AppMenuBuilder::install()`, which is the
only reason those entries can ever appear; outside the Electron bundle it
returns without touching the facade.

Two NativePHP shapes look like they would work here and neither does.

`Menu::file()->submenu(...)` loses its entries. The shell compiles the menu
payload in `nativephp/electron/electron-plugin/src/server/api/helper/index.ts`,
and `compileMenu()` rebuilds any `type: 'role'` item as `{ role, label }`
alone — the `submenu` key is dropped before `Menu.buildFromTemplate()` sees
it. File then renders Electron's stock "Close Window / Close All", and
`Menu::help()->submenu(...)` resolves to a top-level item with no submenu,
which macOS does not draw at all: the Help menu disappears from the menu bar.

`Menu::label($title)->submenu(...)` survives `compileMenu()` but is typed
`normal`, and Electron draws a `normal` item as a flat entry rather than a
menu — so that menu disappears from the bar too. `SubmenuItem` exists
because no NativePHP item type emits `type: 'submenu'`.

Nothing in the PHP layer reports either failure: the builder serialises the
submenu correctly, so a test that inspects `toArray()` passes while the
shipped menu bar is missing whole menus. `AppMenuBuilderTest` therefore
asserts the *shape* — no item holds both `role` and `submenu`, and every
item that owns entries is typed `submenu` — rather than only the labels.

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

Each pre-window step is attempted, logged and carried past. The window IS the
recovery for a failed migration — `EnsureDatabaseReady` redirects to
`desktop.setup`, whose `poll()` re-drives it — so a throw before `open()` took
the one screen that repairs it down with the window, and left the sync and
relay listeners unstarted for the rest of the launch. The mobile mirror
swallows the same failure for the same reason.

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
       → PendingFileIntent::remember($path)
  → after login: ContinuePendingFileIntentAfterLogin::handle
       → PendingFileIntent::pending() drops an intent whose file has gone
  → next HTML GET: ContinueToStagedFile (pushed onto the `web` group by
    DesktopServiceProvider) redirects to /desktop/file-staging, which
    consumes the intent on mount so it redirects exactly once
```

The staging page still hands the Import wizard no reference to the staged
path: `startImport()` navigates to `Destination::Imports` and the wizard
starts empty. Nothing outside Desktop reads the intent — the
`RemembersPendingFileIntent` contract is write-only — so closing that gap is
an Import-side change, not a Desktop one.

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

### Cross-platform OS file-open ingress

Everything above needs an `App::OpenFile` to exist in the first place, and
for a long time none could. The whole path — intake, intent, staging screen,
the per-extension caps whose comment cited Info.plist entries — was
unreachable on every platform at once, for two independent reasons.

**Nothing told the OS that Beatrax opens these files.** electron-builder
writes the macOS `CFBundleDocumentTypes`, the Windows registry entries and
the Linux `.desktop` `MimeType=` line from a `fileAssociations` key, and
NativePHP's published `electron-builder.mjs` declares none. Without that
binding no shell ever hands the app a document, so macOS's `open-file` — the
one leg NativePHP already forwards — could not fire either.
`scripts/nativephp_inject_file_associations.php` declares it as a `prebuild`
hook. It cannot be a committed edit: `nativephp/` is gitignored and
regenerated by `native:install --publish`, which is exactly how the original
hand-applied version of this wiring disappeared.

**Windows and Linux never emit `open-file` at all.** They put the path on
`process.argv` at cold start, and hand it to the already-running instance
through `app.on('second-instance')` — which needs a single-instance lock
NativePHP takes only when a deep-link scheme is configured, and forwards as
`OpenedFromURL`, a deep link rather than a document.
`scripts/nativephp_inject_file_open_ingress.php` adds the lock
unconditionally, scans argv last-first for a `.csv`/`.eml` argument, and
forwards both cases through NativePHP's own `notifyLaravel('events', …)`
transport as the same `App\OpenFile` the macOS leg raises.

That transport choice is the reason there is **no Beatrax HTTP route** here.
A `POST /desktop/file-open` did exist, in the `web` group, and could only
ever answer `419`: an Electron main-process POST carries no session and no
CSRF token. It was invisible because `ValidateCsrfToken` short-circuits on
`runningUnitTests()`, so the suite saw `204` where a real build saw `419`.
`notifyLaravel` already authenticates itself to the loopback PHP server with
`X-NativePHP-Secret`, so reusing it keeps one PHP-side subscriber, one
ingress and one validation boundary — `FileOpenIntake`, which both entry
paths still converge on before `FileOpenedFromOs` fires.

Both injections are idempotent and fail the build loudly on an anchor a
future NativePHP release has moved, rather than leaving a main process that
silently drops every document path. What PHP can pin about them is pinned in
`Modules/Desktop/tests/Unit/FileOpenIngressScriptsTest.php`; the Electron
runtime half — that a double-clicked export on a packaged Windows or Linux
build reaches `FileOpenIntake` — is only observable from a real installer and
belongs in the cross-OS smoke pass.

## Bounding a call into Electron

Every PHP-side NativePHP call — spawning a child process, opening a window,
asking for the OS theme — goes through one `Native\Desktop\Client\Client`,
which builds its HTTP client with `->timeout(60 * 60)`. An hour is a hang
budget, not a call budget: the API is on loopback and answers in milliseconds
when it answers at all.

That matters here because the desktop backend serves one request at a time,
and repo code reaches that client **from an ordinary web request** —
`StartSyncListenerOnEnable::handle` spawns `sync:serve` the moment sync is
enabled. An Electron process that accepts the connection and then stops
answering therefore takes the whole application down for an hour, from a
click in the settings screen.

`BoundedNativeApiClient` is bound over `Client` in `DesktopServiceProvider`
and holds every call to 15 seconds. Vendor is not patched — the subclass
re-applies the timeout on each call, which is also why it has to *re*-apply
it: NativePHP reuses one `PendingRequest` for the life of the client, so a
widening left in place would be inherited by the next call.

One endpoint is widened on purpose. `system/prompt-touch-id` is open until
the reader answers the sheet or macOS dismisses it, and a machine-scale bound
there would cancel the prompt out from under them — on a lock screen they
then cannot clear. It gets 120 seconds, which is still two orders of
magnitude off the hour.

## Key custody (desktop bundle)

`DesktopKeyCustodian` implements the Auth module's `KeyCustodian`
contract and is bound only when `nativephp-internal.running` is true
(`DesktopServiceProvider::register()`); on web/CI the pass-through
`NullKeyCustodian` default applies instead. It holds the already
Auth-unwrapped session data key at rest via Electron `safeStorage` (OS
keychain / DPAPI / Keychain Services): `store()` encrypts and returns
an opaque ciphertext blob under a `nativephp:safestorage:v1:` marker,
`read()` decrypts it back. When `System::canEncrypt()` is false (headless CI,
an early-boot race before Electron initialises safeStorage) `store()` degrades
to a pass-through and the Auth module's own encrypted-session custody applies
unchanged. `read()` degrades only for an *unmarked* handle: the marker is what
tells a raw key stored while safeStorage was down from ciphertext that has to
be opened, and handing the second back unchanged released a 56-byte non-key
into `DeviceIdentityLoader`, the GDK keyring and the sensitive-column codec
instead of the `null` that forces a PIN unlock. `DesktopKeyCustodian` never touches
`AppLockKeyWrap`/`AppLockKdf` — the wrap/unwrap KDF + secretbox stay
entirely in the Auth module; this class only protects the key *at
rest while unlocked*, between the moment Auth unwraps it and a later
caller retrieving it. `SafeStorageSecretShield` (the `SecretShield`
contract implementation, same bundle-only gate) delegates to the same
custodian to shield other persisted secrets (a biometric wrap blob, an
OAuth token blob) — no second facade-calling class is needed.

`NativeBiometricUnlock` is the sole caller of NativePHP's
`System::canPromptTouchID()`/`promptTouchID()`. `DesktopColdStartVault` is what
calls it, through the Auth `ColdStartVault` contract that `LockScreen` asks on
a cold start. This class never imports any `Modules\Auth\*` symbol beyond
returning a bool — the actual key-release logic (and the Touch-ID-bypass
mitigation) stays entirely in Auth.

`ForgetColdStartVaultOnKeyRotation` drops the enclave blob only when
`AppLockPassphraseChanged` carries two *different* keys. A PIN change re-wraps
the same data key, so the blob still opens it; forgetting unconditionally
turned Touch ID unlock off every time a reader changed their PIN. The event's
only producer today is `AppLockProvisioner::changePin()`, which passes the same
key twice by design — so the listener's `forget()` arm is currently reached by
nothing, and the paths that genuinely rotate the data key do not raise the
event at all.

## Known risks

`LockOnWindowHideOrClose` locks the session the instant NativePHP
fires `WindowHidden`/`WindowClosed`, dispatched from the Electron main
process over its own internal HTTP channel. That channel is
`POST _native/api/events`, declared in `nativephp/desktop/routes/api.php`
behind `OptionalNightwatchNever` and `PreventRegularBrowserAccess` and
**no `web` group** — so no `StartSession`, and the store this listener
withholds against was never started from the WebView cookie and is never
saved. The main process holds NativePHP's own security cookie, not a Laravel
session cookie, so there is no session for that route to start: attaching
`StartSession` to it would begin a fresh anonymous session rather than the
reader's. The downstream lock failure is not reproducible in-process (tests
share one session, so they pass either way). The client-side privacy veil and
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
