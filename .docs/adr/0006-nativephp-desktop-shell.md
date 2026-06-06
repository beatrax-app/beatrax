# ADR 0006 — NativePHP as the desktop shell

- **Status:** Accepted
- **Date:** 2026-05-27
- **Graduated from:** Phase 17, decision D-32

## Context

beatrax has to install like a desktop app. A finance dashboard the user
opens every morning cannot demand a terminal session, a `php artisan
serve` command, and a browser bookmark. The target user installs from
a `.dmg` on macOS, an `.msi` on Windows, or an `.AppImage` / `.deb` on
Linux, double-clicks an icon, and lands in the dashboard.

The codebase, however, is Laravel — server-rendered Blade plus Livewire,
not React. The shell choices were:

- **Tauri / Electron with a separate frontend rewrite** — would have
  forced a parallel codebase in React / Vue, throwing away the Livewire
  investment and adding a second testing pipeline.
- **A static native installer wrapping PHP-CLI plus the user's browser**
  — works on Linux, but on macOS and Windows the "open the browser at
  localhost:8000" experience never feels native enough to recommend to a
  non-technical partner.
- **NativePHP** — bundles PHP + the application + an Electron-based
  Chromium shell into a per-platform installer. The Blade-and-Livewire
  app renders inside the shell as if it were a desktop window. The PHP
  runtime, the SQLite store, and the user's data all stay on the
  machine.

NativePHP exists specifically to ship Laravel apps as desktop
binaries. It is not a generic library that beatrax happens to use; it
is a load-bearing decision about the shipping format.

## Decision

beatrax ships as a desktop application via [NativePHP](https://nativephp.com/).
Specifically:

- **The desktop bundle** is produced by `php artisan native:build`
  invoked from the release workflow on three platform runners (macOS,
  Windows, Linux). Each runner produces its platform's native installer.
- **The bundled runtime** carries its own PHP binary, its own
  Composer-installed dependencies, and the application code. The user
  installs none of these by hand.
- **Storage paths** resolve through `UserDataPathService`
  (see [ADR 0005](0005-sqlite-wal.md)) to per-OS user-data directories
  so the SQLite file survives app upgrades.
- **OAuth callback** uses the loopback redirect URI scheme
  `http://127.0.0.1:PORT/oauth/callback/{provider}` — the only flow
  that needs an HTTP listener also works inside the shell because
  NativePHP exposes one.

The NativePHP code lives quarantined inside `Modules/Desktop/`. Every
`Native\Laravel\*` import outside that module is forbidden by the
[`noNativePhpImportsOutsideDesktopModule`](#) arch invariant, and the
narrower `Native\Desktop\Contracts\Shell` import is restricted to a
single allow-listed action plus a fallback by
[`noShellContractOutsideAllowList`](#). The rest of the application
runs unchanged whether it is hosted by NativePHP or by the local dev environment.

## Consequences

- **Three platform runners, one source tree.** The release workflow
  builds in parallel on `macos-latest`, `windows-latest`, and
  `ubuntu-latest` runners. Each produces signed (where applicable) and
  hash-verified platform installers. The
  [`cicd/release-workflow.md`](../cicd/release-workflow.md) document
  describes the per-platform sequence.
- **Local dev remains identical.** Day-to-day development runs through
  the Docker toolchain; the NativePHP-specific code
  paths only activate when running inside the desktop bundle.
  `BEATRAX_RUNTIME=local` vs `BEATRAX_RUNTIME=desktop` switches the
  handful of surfaces that differ (the Horizon iframe is allowed in
  local dev; the embedded Horizon UI does not ship in the desktop bundle).
- **PHP version is pinned to what NativePHP supports.** NativePHP's
  bundled PHP runtime determines the floor; v1.0 ships on PHP 8.5 and
  the development environment matches. Diverging would mean shipping a
  build that runs against a PHP version the developer never tested
  against.
- **No PHP extensions outside the NativePHP-supported set.** Anything
  that requires `pecl install` is unavailable in the shipped bundle.
  The codebase uses no native extensions beyond what PHP 8.5 plus
  NativePHP's bundle already carries.
- **Auto-update path.** The Electron auto-updater that ships with the
  NativePHP shell fetches release manifests from GitHub Releases,
  verifies the Ed25519 manifest signature against a public key embedded
  in the bundle, verifies the SHA-512 of each downloaded binary, and
  applies the update on next launch. See
  [`runbooks/verify-release.md`](../runbooks/verify-release.md) for the
  manual-verification recipe.

## Alternatives considered

- **Tauri with a React rewrite** — rejected: would have thrown away
  the Livewire 4 investment that earned its keep over eleven phases.
- **Electron with a React rewrite** — same rejection as Tauri.
- **A static binary that opens the user's browser at localhost:8000**
  — rejected on macOS / Windows for the non-native first-launch
  experience; works on Linux but inconsistency across platforms was
  worse than a single shell choice.
- **PWA installed via Chrome / Safari** — rejected. Service-worker
  install flows are unreliable; the OAuth loopback URI requires a
  local HTTP listener the PWA model does not provide.

## Related

- [ADR 0004 — Local-only hosting](0004-local-only-hosting.md) — the
  privacy posture that NativePHP supports by keeping everything local.
- [ADR 0005 — SQLite with WAL](0005-sqlite-wal.md) — the storage layer
  that lives in NativePHP's per-OS user-data directory.
- [Architecture — Module boundaries](../architecture/module-boundaries.md)
  — describes the `Modules/Desktop/` quarantine.
- [`cicd/release-workflow.md`](../cicd/release-workflow.md) — the
  three-platform-runner build sequence.
