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

# Just the file-open intake unit
vendor/bin/pest Modules/Desktop/tests/Unit --filter "FileOpenIntake"

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
