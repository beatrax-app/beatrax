---
phase: 15-desktop-shell-nativephp-integration
plan: 01
subsystem: infra
tags: [nativephp, electron, desktop, packaging, code-signing, macos, dmg, arch-test]

# Dependency graph
requires:
  - phase: 13-app-paths
    provides: NativePHP-ready per-OS user-data storage paths so the packaged app writes outside the bundle
  - phase: 14-queue-rewire-horizon-carveout
    provides: database queue driver + Horizon carve-out so the shipped --no-dev bundle ships no Redis
provides:
  - NativePHP desktop ^2.2 installed and verified (nativephp/desktop, nativephp/electron, nativephp/php-bin)
  - New Modules/Desktop bounded module registered in bootstrap/providers.php
  - config/nativephp.php pointing the provider key at Modules\Desktop\Internal\NativeAppServiceProvider
  - noNativePhpImportsOutsideDesktopModule arch invariant + Internal-containment rule + facade carve-out
  - Wave 0 test scaffolding (FileOpenedFromOsTest, NativeAppServiceProviderTest) for plans 15-02 and 15-04
  - NATIVEPHP-FAKES.md recording Window/Menu/MenuBar/Notification fake availability for plan 15-02
  - A launchable macOS .dmg — native window renders the diederik web UI
affects: [15-02-native-chrome, 15-04-file-associations, 17-cicd-pipeline-code-signing]

# Tech tracking
tech-stack:
  added: [nativephp/desktop ^2.2, nativephp/electron, nativephp/php-bin, electron-builder]
  patterns:
    - "Native\\Desktop\\* imports quarantined inside Modules/Desktop via a grep-based arch invariant"
    - "NativeAppServiceProvider uses constructor-injected WindowManager contract — DI-only, no NativePHP facade"
    - "bootstrap/cache/*.php are environment artifacts — gitignored, never committed"
    - "post-autoload-dump composer script lets the in-bundle composer install --no-dev regenerate package discovery"

key-files:
  created:
    - Modules/Desktop/composer.json
    - Modules/Desktop/Providers/DesktopServiceProvider.php
    - Modules/Desktop/Internal/NativeAppServiceProvider.php
    - Modules/Desktop/Public/Events/FileOpenedFromOs.php
    - Modules/Desktop/Routes/web.php
    - Modules/Desktop/Internal/Native/NATIVEPHP-FAKES.md
    - Modules/Desktop/tests/Pest.php
    - Modules/Desktop/tests/TestCase.php
    - Modules/Desktop/tests/Feature/FileOpenedFromOsTest.php
    - Modules/Desktop/tests/Unit/NativeAppServiceProviderTest.php
    - config/nativephp.php
    - scripts/nativephp_force_adhoc_signing.php
  modified:
    - composer.json
    - composer.lock
    - bootstrap/providers.php
    - phpstan.neon
    - phpunit.xml
    - tests/Contracts/BoundaryArchTest.php
    - tests/Pest.php
    - .gitignore

key-decisions:
  - "Forced deterministic ad-hoc macOS code-signing (mac.identity: null) via a prebuild hook — no paid Apple Developer ID needed for dev builds"
  - "bootstrap/cache/*.php removed from git and gitignored — they are per-environment artifacts the build regenerates"
  - "Added the standard Laravel post-autoload-dump composer script so NativePHP's in-bundle composer install --no-dev regenerates package discovery against the --no-dev tree"
  - "NativeAppServiceProvider opens the window via a constructor-injected WindowManager contract, not the Native\\Desktop\\Facades\\Window facade — keeps the project DI-only rule and the new arch invariant both satisfied"

patterns-established:
  - "NativePHP containment: Native\\Desktop\\* imports are forbidden outside Modules/Desktop, enforced by a grep-based BoundaryArchTest invariant mirroring noHorizonImportsInShippedBuildCode"
  - "Native-chrome code is the single sanctioned facade/DI exception: NativeAppServiceProvider + future native builders carve out of the no-facade arch rule and the phpstan ignoreErrors block"

requirements-completed: [PKG-04, PKG-05]

# Metrics
duration: ~3h 25m (incl. 6-cycle debug investigation)
completed: 2026-05-23
---

# Phase 15 Plan 01: Desktop Shell Foundation (NativePHP Integration) Summary

**NativePHP desktop ^2.2 installed, a new quarantined Modules/Desktop bounded module wired in with a Native\Desktop import arch invariant, and a launchable macOS .dmg that opens a native window rendering the diederik web UI.**

## Performance

- **Duration:** ~3h 25m (Task 1 approval → final gitignore commit; includes a 6-cycle debug investigation on the native build)
- **Started:** 2026-05-22T20:53:30+02:00 (first plan commit)
- **Completed:** 2026-05-23T00:16:58+02:00 (final commit)
- **Tasks:** 4 of 4
- **Files modified/created:** ~30 (10 in Modules/Desktop + config/build/test infrastructure)

## Accomplishments

- Installed and verified NativePHP `nativephp/desktop ^2.2` plus its `nativephp/electron` and `nativephp/php-bin` transitives — package legitimacy human-verified at the Task 1 blocking checkpoint (T-15-01 / T-15-SC supply-chain mitigations).
- Created the new `Modules/Desktop/` bounded module — `DesktopServiceProvider` (registered unconditionally in `bootstrap/providers.php`), the NativePHP-booted `NativeAppServiceProvider`, the `FileOpenedFromOs` public event DTO, and a route skeleton — mirroring the `Modules/Receipts` shape.
- Locked the `Native\Desktop\*` containment boundary: `noNativePhpImportsOutsideDesktopModule` arch invariant, an `Internal`-containment rule, a facade carve-out, and a matching `phpstan.neon` ignoreErrors block — all green.
- Scaffolded Wave 0 test stubs (`FileOpenedFromOsTest`, `NativeAppServiceProviderTest`) so plans 15-02 and 15-04 have real verification targets.
- Recorded NativePHP v2 facade-fake availability for `Window`/`Menu`/`MenuBar`/`Notification` in `NATIVEPHP-FAKES.md` so plan 15-02's TDD tasks can stay autonomous.
- Proved the end-to-end build path: `php artisan native:build mac arm64` produces a `.dmg` that installs into `/Applications` and launches a native window rendering the diederik web UI (PKG-04 gate satisfied — human-verified).

## Task Commits

1. **Task 1: Confirm nativephp/desktop package legitimacy** — no commit (blocking-human checkpoint; user typed "approved").
2. **Task 2: Install NativePHP, scaffold Modules/Desktop, register providers, record facade-fake availability** — `6243038` (feat).
3. **Task 3: Add noNativePhpImportsOutsideDesktopModule arch invariant + Wave 0 test scaffolding** — `eb7313d` (test).
4. **Task 4: native:build produces a launchable macOS .dmg** — checkpoint gate; required a 6-cycle debug investigation, fixes committed as `c0f4ef1`, `89c0340`, `37f5dd0`, `2229728`, `18af83d` (reverted), `cd2f113`. Human-verified the rebuilt `.dmg` launches a native window.

**Loose-end fix:** `d616cdd` (chore — gitignore the `nativephp/` Electron working dir + `.DS_Store`).

**Plan metadata:** committed separately with this SUMMARY.

## Files Created/Modified

- `Modules/Desktop/composer.json` — module PSR-4 manifest (`Modules\Desktop\`).
- `Modules/Desktop/Providers/DesktopServiceProvider.php` — module registration entry point; `boot()` loads migrations/routes/views with guards.
- `Modules/Desktop/Internal/NativeAppServiceProvider.php` — NativePHP-booted provider; `boot()` opens the `'main'` window via a constructor-injected `WindowManager` contract.
- `Modules/Desktop/Public/Events/FileOpenedFromOs.php` — `final readonly` file-open intent DTO (`path`, `extension`).
- `Modules/Desktop/Routes/web.php` — `web`+`auth` route skeleton for plan 15-04 staging routes.
- `Modules/Desktop/Internal/Native/NATIVEPHP-FAKES.md` — recorded Window/Menu/MenuBar/Notification fake availability for plan 15-02.
- `Modules/Desktop/tests/{Pest.php,TestCase.php,Feature/FileOpenedFromOsTest.php,Unit/NativeAppServiceProviderTest.php}` — Wave 0 test scaffolding.
- `config/nativephp.php` — NativePHP config; `provider` key points at the module's `NativeAppServiceProvider`; `cleanup_env_keys` strips dev-only env from the bundle.
- `bootstrap/providers.php` — `DesktopServiceProvider` added as an unconditional entry.
- `composer.json` / `composer.lock` — NativePHP deps; `Modules\Desktop\Tests\` autoload-dev entry; standard Laravel `post-autoload-dump` script added.
- `tests/Contracts/BoundaryArchTest.php` — `noNativePhpImportsOutsideDesktopModule` + `Internal`-containment + facade carve-out.
- `tests/Pest.php` — `Modules/Desktop` registered in the module test-bootstrap loop.
- `phpstan.neon` / `phpunit.xml` — Desktop carve-out / test-suite wiring.
- `scripts/nativephp_force_adhoc_signing.php` — prebuild hook forcing deterministic ad-hoc macOS signing.
- `.gitignore` — `bootstrap/cache/*.php`, the `nativephp/` Electron working dir, and `.DS_Store`.

## Decisions Made

- **Deterministic ad-hoc macOS signing.** NativePHP's electron-builder config omits `mac.identity`, so electron-builder auto-discovers a keychain identity — non-deterministic and crash-prone. A prebuild hook now injects `identity: null`, forcing consistent `--deep` ad-hoc signing with no paid Apple Developer ID. Real Developer ID signing is Phase 17's scope.
- **`bootstrap/cache/*.php` are never committed.** They are per-environment artifacts; committing a dev-tree cache poisons the `--no-dev` bundle. Now gitignored, matching the Laravel framework convention.
- **`post-autoload-dump` composer script added.** Lets NativePHP's in-bundle `composer install --no-dev` regenerate package discovery against the bundle's actual `--no-dev` vendor tree.
- **DI over facade for window-open.** `NativeAppServiceProvider` injects the `WindowManager` contract rather than calling the `Native\Desktop\Facades\Window` facade — keeps the project DI-only rule and the new `noNativePhpImportsOutsideDesktopModule` invariant both satisfied.

## Deviations from Plan

The autonomous tasks (2 and 3) executed exactly as written. **The single significant deviation: Task 4's `native:build` checkpoint did not pass on the first attempt — it required a 6-cycle debug investigation before the `.dmg` launched a working native window.** Four distinct root causes were found and fixed; all fixes are committed atomically.

### Auto-fixed Issues (Task 4 debug investigation)

**1. [Rule 1 - Bug] macOS code-signing: Team ID mismatch from electron-builder keychain auto-discovery**
- **Found during:** Task 4 (`native:build` checkpoint — packaged app crashed on launch).
- **Issue:** NativePHP's bundled `electron-builder.mjs` `mac` block has no `identity` key, so electron-builder auto-discovers a keychain signing identity at build time. When an identity is present the bundle is *partially* signed — the app shell stays ad-hoc (empty Team ID) while the nested Electron Framework keeps a non-empty Team ID — and on Apple Silicon `dyld` aborts at launch (`DYLD Library missing`, "different Team IDs") in `dyld4::prepare`, before any window, so macOS shows no crash dialog (looked like a "silent exit"). A first fix (`c0f4ef1`) patched the wrong file: NativePHP's `ElectronServiceProvider::electronPath()` falls back to the *vendor* `electron-builder.mjs` when no `nativephp/electron/package.json` exists, so the hook silently no-op'd.
- **Fix:** Rewrote `scripts/nativephp_force_adhoc_signing.php` to resolve the *same* `electron-builder.mjs` path NativePHP's build reads (project-local when present, else the vendor fallback) and inject `identity: null` — forcing deterministic `--deep` ad-hoc signing regardless of keychain state. The hook now fails loudly (exit 1) if no config file is found.
- **Files modified:** `scripts/nativephp_force_adhoc_signing.php`, `config/nativephp.php`.
- **Verification:** `node --check` confirms the patched config is valid JS; the hook is idempotent; human-verified the rebuilt `.dmg` no longer crashes on launch.
- **Committed in:** `c0f4ef1`, `89c0340`.

**2. [Rule 2 - Missing Critical] NativeAppServiceProvider never opened a window**
- **Found during:** Task 4 (once the signing crash was fixed, the app launched as a live process with no window).
- **Issue:** NativePHP v2 opens no default window — the app's provider `boot()` must explicitly call `WindowManager::open()`. `NativeAppServiceProvider::boot()` was empty (correct for the install-only Task 2 scope), but Task 4's acceptance criterion explicitly requires a visible window rendering the web UI.
- **Fix:** Added a constructor-injected `Native\Desktop\Contracts\WindowManager` and made `boot()` call `$this->windows->open('main')`. The window inherits its constructor defaults — `url` → `url('/')` and `title` → `config('app.name')` ('diederik') — so a bare `open()` renders the diederik web UI. Implemented the previously-`->todo()` `it('configures the application window')` test with `Window::fake()`.
- **Files modified:** `Modules/Desktop/Internal/NativeAppServiceProvider.php`, `Modules/Desktop/tests/Unit/NativeAppServiceProviderTest.php`, `tests/Pest.php`.
- **Verification:** Larastan L10 clean; the window-open test passes; `noNativePhpImportsOutsideDesktopModule` still green (the new import is inside `Modules/Desktop/Internal`).
- **Committed in:** `37f5dd0`.

**3. [Rule 1 - Bug] Stale committed `bootstrap/cache/*.php` referencing a dev-only `SentinelServiceProvider`**
- **Found during:** Task 4 (packaged app showed a blank window; log showed `Class "Laravel\Sentinel\SentinelServiceProvider" not found`).
- **Issue:** Commit `6243038` committed `bootstrap/cache/packages.php` + `services.php`, generated with the full dev tree. They hardcode `Laravel\Sentinel\SentinelServiceProvider` — a transitive dependency of the `require-dev`-only `laravel/horizon`. NativePHP's build runs `composer install --no-dev`, stripping Horizon/Sentinel from the bundle; the stale cache then fatals `ProviderRepository` at boot, 500-ing every request and rendering nothing. `native:serve` was unaffected (dev runs the full vendor tree).
- **Fix:** Removed the three `bootstrap/cache/*.php` files from git and gitignored `/bootstrap/cache/*.php` (Laravel-framework convention). They are per-environment artifacts the build regenerates.
- **Files modified:** `bootstrap/cache/{packages,services,modules}.php` (un-tracked), `.gitignore`.
- **Verification:** `git check-ignore` confirms all three are now ignored; human-verified after subsequent cycles.
- **Committed in:** `2229728`.

**4. [Rule 3 - Blocking] Missing `post-autoload-dump` composer script — true underlying cause of the bundle's stale package manifest**
- **Found during:** Task 4 (an interim cycle-5 fix that excluded the caches from the bundle broke it differently — the bundle's `bootstrap/cache/` *directory* was absent, and `PackageManifest::write()` requires it to exist).
- **Issue:** The project's `composer.json` lacked the standard Laravel `post-autoload-dump` script. NativePHP's in-bundle `composer install --no-dev` runs *with* scripts enabled but, with the script absent, never ran `package:discover` — so the stale dev-tree manifest was never regenerated against the `--no-dev` tree. This was the real reason fix #3 alone was insufficient.
- **Fix:** Added the standard Laravel `post-autoload-dump` script (`ComposerScripts::postAutoloadDump` + `@php artisan package:discover --ansi`) to `composer.json`, and reverted the interim `cleanup_exclude_files` exclusion (`18af83d`) so the bundle's `bootstrap/cache/` directory physically exists — the build then overwrites the stale manifest with a correct `--no-dev` one.
- **Files modified:** `composer.json`, `config/nativephp.php`.
- **Verification:** `composer dump-autoload` confirms `post-autoload-dump` fires `package:discover`; `composer validate` passes; **human-verified the final rebuilt `.dmg` installs and launches a native window rendering the diederik login screen.**
- **Committed in:** `cd2f113` (the interim `18af83d` exclusion was reverted within this cycle).

---

**Total deviations:** 4 root causes auto-fixed across a 6-cycle debug investigation (2× Rule 1 bug, 1× Rule 2 missing-critical, 1× Rule 3 blocking). One interim fix (`18af83d`) was reverted as the investigation refined the root cause.
**Impact on plan:** All four fixes were necessary for the `.dmg` to build and launch — the PKG-04 acceptance criterion. No scope creep: every change is build-correctness work. Native-chrome polish (window geometry, app menu, system tray) remains deferred to plan 15-02 as planned; the login screen with no account is expected (first-launch DB bootstrap is plan 15-05's scope).

## Issues Encountered

- **Larastan memory limit.** `vendor/bin/phpstan analyse` crashes at the default 128M; run with `--memory-limit=1G`. Environment-level, not a code defect — noted for future executors.
- **Headless build limitation.** `native:build` cannot run in CI on the dev box, so Task 4 verification (and each debug cycle) depended on the user rebuilding and relaunching the `.dmg`. This is exactly why Task 4 was planned as a `checkpoint:human-verify` gate.

## Quality Gates (verified on HEAD `d616cdd`)

- **Larastan level 10 strict:** `vendor/bin/phpstan analyse --memory-limit=1G` → **No errors.**
- **Laravel Pint:** `vendor/bin/pint --test` → **passed.**
- **Pest suite:** `composer test` → **2063 passed** (24596 assertions), 6 todos, 6 skipped, 14 notices — **0 failures.** The 6 todos are the Wave 0 scaffolding stubs (`FileOpenedFromOs`, `NativeAppServiceProvider`) intentionally created in Task 3 for plans 15-02 and 15-04.

No fixes were needed — all gates were already green on HEAD.

## User Setup Required

None — no external service configuration required. `php artisan native:build` and `native:serve` work locally; no paid Apple Developer ID is needed for dev builds (ad-hoc signing).

## Next Phase Readiness

- **Ready for plan 15-02 (native chrome):** `Modules/Desktop` module + `NativeAppServiceProvider` + `NATIVEPHP-FAKES.md` + the `NativeAppServiceProviderTest` `->todo()` stubs are all in place. The arch invariant and phpstan carve-out already pre-list the two native builders (`AppMenuBuilder`, `TrayMenuBuilder`) plan 15-02 will create.
- **Ready for plan 15-04 (file associations):** `FileOpenedFromOs` event + `Routes/web.php` skeleton + `FileOpenedFromOsTest` `->todo()` stubs exist.
- **For Phase 17 (CI/CD + code signing):** ad-hoc signing is a deliberate dev-only stand-in; Phase 17 must wire real Apple Developer ID notarization and revisit `scripts/nativephp_force_adhoc_signing.php`.
- **No blockers.** The native build path is proven end-to-end.

## Self-Check: PASSED

- Modules/Desktop files (10) — all FOUND on disk.
- Commits `6243038`, `eb7313d`, `c0f4ef1`, `89c0340`, `37f5dd0`, `2229728`, `18af83d`, `cd2f113`, `d616cdd` — all FOUND in `git log`.
- `config/nativephp.php`, `scripts/nativephp_force_adhoc_signing.php`, `phpstan.neon`, `tests/Contracts/BoundaryArchTest.php` — all FOUND.

---
*Phase: 15-desktop-shell-nativephp-integration*
*Completed: 2026-05-23*
