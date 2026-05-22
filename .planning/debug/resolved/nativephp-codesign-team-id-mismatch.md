---
status: resolved
trigger: "macOS code-signing failure: NativePHP-packaged diederik.app crashes on launch with DYLD Library missing / Team ID mismatch on Electron Framework"
created: 2026-05-22T21:30:00Z
updated: 2026-05-23T00:30:00Z
resolution: "Resolved across 6 debug cycles. Four distinct root causes: (1) macOS code-signing Team ID mismatch from electron-builder keychain auto-discovery — fixed by a prebuild hook forcing ad-hoc signing (c0f4ef1, 89c0340); (2) NativeAppServiceProvider never opened a window — fixed by constructor-injecting WindowManager and opening 'main' in boot() (37f5dd0); (3) stale committed bootstrap/cache/*.php referencing dev-only Laravel\\Sentinel\\SentinelServiceProvider — removed from git and gitignored (2229728); (4) missing post-autoload-dump composer script (true underlying cause) — added so the NativePHP bundle's composer install --no-dev regenerates the package manifest (cd2f113, which also reverted interim exclusion 18af83d). User verified the rebuilt .dmg launches a native window rendering the diederik UI — PKG-04 / plan 15-01 Task 4 gate satisfied."
---

## Current Focus

### Cycle 6 (2026-05-23T10:00:00Z) — cycle-5 fix broke the bundle: missing `bootstrap/cache/` dir; no `post-autoload-dump` was the true root cause

reasoning_checkpoint:
  hypothesis: "Cycle 5's `cleanup_exclude_files` exclusion of the three `bootstrap/cache/*.php` files was a workaround that fixed the stale-Sentinel symptom but introduced a worse defect: the bundle's `bootstrap/cache/` SUBDIRECTORY is now entirely absent. `CopiesToBuildDirectory::copyToBuildDirectory()` only materialises a target directory when its RecursiveIteratorIterator encounters a non-excluded entry inside it. With cycle 5 excluding all three `.php` files, the only remaining entry was `.gitkeep` — which did not result in the directory being created in the bundle. Laravel's `PackageManifest::write()` (vendor/laravel/framework/.../PackageManifest.php:178) throws `\"The {dirname} directory must be present and writable\"` and does NOT mkdir — so the absent dir fatals boot, cascading to `Target class [view] does not exist`. The TRUE underlying root cause of the whole bootstrap/cache saga is that this project's `composer.json` lacks the standard Laravel `post-autoload-dump` script. NativePHP's unsecure build runs `composer install --no-dev` WITH scripts enabled (no `--no-scripts` flag — PrunesVendorDirectory.php:16). With the script absent, that in-bundle install never runs `package:discover` and never regenerates the package manifest against the `--no-dev` tree — which is why the stale dev-tree cache (with Sentinel) survived into the bundle in the first place."
  confirming_evidence:
    - "Packaged app log: `The .../build/app/bootstrap/cache directory must be present and writable.` → cascades to `Target class [view] does not exist` → blank window. (Orchestrator-confirmed.)"
    - "Bundle's `bootstrap/` exists with `app.php`+`providers.php` but `bootstrap/cache/` subdirectory is absent. (Orchestrator-confirmed.)"
    - "`vendor/laravel/framework/src/Illuminate/Foundation/PackageManifest.php:178` — `if (! is_writable($dirname = dirname($this->manifestPath))) throw new Exception(\"The {$dirname} directory must be present and writable.\")`. PackageManifest never mkdirs the cache dir."
    - "`vendor/nativephp/desktop/src/Builder/Concerns/PrunesVendorDirectory.php:16` runs `composer install --no-dev` — NO `--no-scripts` flag. Scripts (incl. post-autoload-dump) DO fire in-bundle."
    - "`BuildCommand::buildUnsecure()` order: copyToBuildDirectory() → cleanEnvFile() → installIcon() → pruneVendorDirectory(). Files copied FIRST, then `composer install --no-dev` runs — so a `post-autoload-dump` → `package:discover` fires AFTER the (stale) caches are in place and OVERWRITES them."
    - "Project `composer.json` `scripts` had test/analyse/format/check:paths/native:dev/post-update-cmd — NO `post-autoload-dump`."
  falsification_test: "If NativePHP ran `composer install --no-dev --no-scripts`, adding `post-autoload-dump` would not fire in-bundle and step 1 alone would be insufficient — but PrunesVendorDirectory.php:16 has no `--no-scripts`, so the script fires. If PackageManifest mkdir'd its cache dir, the absent-directory fatal could not occur — but line 178 only checks `is_writable`, never creates."
  fix_rationale: "Two coordinated changes. (1) Add the standard Laravel `post-autoload-dump` script (`Illuminate\\Foundation\\ComposerScripts::postAutoloadDump` + `@php artisan package:discover --ansi`) to `composer.json`. NativePHP's in-bundle `composer install --no-dev` then runs `package:discover`, regenerating `bootstrap/cache/packages.php`/`services.php` against the bundle's ACTUAL `--no-dev` vendor — no phantom `SentinelServiceProvider`. (2) REVERT cycle 5's `cleanup_exclude_files` exclusion. Letting the (stale) dev-tree caches copy into the bundle normally guarantees `bootstrap/cache/` physically EXISTS — and then the build-order (copy → composer install → post-autoload-dump → package:discover) OVERWRITES the stale manifest with the correct one. One mechanism solves BOTH the directory-presence problem AND the stale-Sentinel problem. Cycle 4's gitignore of `bootstrap/cache/*.php` STAYS — these are generated artifacts, never committed — but the working tree still has them on disk so they copy into the bundle."
  blind_spots: "Cannot run native:build headlessly — the user must rebuild + relaunch. Relies on `package:discover` running successfully inside the bundle's `composer install`; if that artisan call itself fails (e.g. a missing extension in nativephp/php-bin) the manifest would not regenerate — but php-bin ships a full Laravel-capable runtime, so this is unlikely. If a future build still fails, next suspect is config/route caching at first-launch `artisan optimize`, but Laravel skips uncacheable closure routes gracefully."

### Cycle 5 (2026-05-22T23:40:00Z) — cycle-4 fix insufficient: stale bootstrap/cache is COPIED into the bundle, never regenerated

reasoning_checkpoint:
  hypothesis: "Cycle 4 wrongly assumed (a) `git rm --cached` removes the files from disk — it does not, the physical files stay in the working tree — and (b) the desktop build regenerates package discovery against the --no-dev tree. Inspecting `nativephp/desktop 2.2.0` source: this project has no `BIFROST_*` env and no `build/__nativephp_app_bundle`, so `Builder::hasBundled()` is false and `native:build` runs the UNSECURE path `buildUnsecure()`. That path calls `copyToBuildDirectory()` which copies PHYSICAL working-tree files (filtered only by `cleanup_exclude_files` fnmatch globs), then `pruneVendorDirectory()` runs `composer install --no-dev` inside `build/app`. Crucially, this project's `composer.json` has NO `post-autoload-dump` script — so `composer install --no-dev` does NOT run `package:discover` and NEVER regenerates `bootstrap/cache/packages.php`. The stale physical `packages.php` (which the dev tree continuously regenerates WITH `Laravel\\Sentinel\\SentinelServiceProvider`, a require-dev transitive of laravel/horizon) is copied verbatim into the bundle and stays. At boot the --no-dev vendor lacks Sentinel → `ProviderRepository` fatals → every request 500s → blank window."
  confirming_evidence:
    - "`git ls-files bootstrap/cache/` → only `.gitkeep`; `ls -la bootstrap/cache/` → packages.php/services.php/modules.php physically present, mtime 23:13 today. `git rm --cached` (cycle 4) untracked them but left them on disk."
    - "No `BIFROST_*` keys in `.env`; no `build/__nativephp_app_bundle` file → `Builder::hasBundled()` false → `BuildCommand::buildUnsecure()` is the active path (vendor/nativephp/desktop/src/Drivers/Electron/Commands/BuildCommand.php:94-125)."
    - "`buildUnsecure()` order: preProcess() → copyToBuildDirectory() → ... → pruneVendorDirectory(). `CopiesToBuildDirectory::copyToBuildDirectory()` filters copied files via `fnmatch($pattern, $relativePath)` against `cleanup_exclude_files` (lines 31-60)."
    - "`composer.json` `scripts` block has test/analyse/format/native:dev/post-update-cmd — but NO `post-autoload-dump`. So `PrunesVendorDirectory`'s `composer install --no-dev` runs no `package:discover`; the stale `bootstrap/cache/packages.php` is never regenerated inside the bundle."
    - "`php -r 'fnmatch(\"bootstrap/cache/*.php\", \"bootstrap/cache/packages.php\")'` → true; `...\"bootstrap/cache/.gitkeep\"` → false — the exclude glob hits the three caches and spares `.gitkeep`."
  falsification_test: "If `composer install --no-dev` regenerated the cache (cycle-4 assumption), adding the exclude would be cosmetic and the bundle would already be Sentinel-free. It is not — proving nothing regenerates it. If the secure Bifrost path were active, `cleanup_exclude_files` would not apply (copyBundleToBuildDirectory ignores it) — but `hasBundled()` is false, so the unsecure path that DOES honour the glob is the one running."
  fix_rationale: "Add `bootstrap/cache/{packages,services,modules}.php` to `config/nativephp.php` `cleanup_exclude_files`. `copyToBuildDirectory()` then skips them at copy time via fnmatch — they NEVER enter the bundle regardless of how often the dev tree regenerates them. The bundle ships an empty `bootstrap/cache/` (just `.gitkeep`); Laravel regenerates package/service discovery lazily against the bundle's own `--no-dev` vendor on first boot, so no phantom `SentinelServiceProvider`. This is durable (a config glob, re-evaluated every build) — not a one-time deletion. The three stale physical files are also deleted now to clean immediate state; they will harmlessly reappear on the dev side and be excluded by the glob on the next build."
  blind_spots: "Cannot run native:build headlessly — user must rebuild + relaunch. If the user later adopts the secure Bifrost build (sets BIFROST_PROJECT, runs bifrost:download-bundle), `copyBundleToBuildDirectory()` ignores `cleanup_exclude_files` — the Bifrost remote builder works from the git tree, where the caches are already untracked (cycle 4), so it stays correct there too. If a future build still fails the next suspect is config/route caching, but Laravel skips uncacheable closure routes gracefully."

### Cycle 4 (2026-05-22T23:30:00Z) — packaged-only no-window: stale committed bootstrap/cache poisons boot

reasoning_checkpoint:
  hypothesis: "The packaged build crashes every HTTP request because `bootstrap/cache/packages.php` and `bootstrap/cache/services.php` were committed to git (commit 6243038) while the FULL dependency tree was installed. They hardcode `Laravel\\Sentinel\\SentinelServiceProvider` — a TRANSITIVE dependency of the require-dev-only `laravel/horizon`. NativePHP's build runs `composer install --no-dev`, so `horizon` and its transitive `sentinel` are absent from the bundled `vendor/`. The build copies the stale committed `packages.php` into the bundle; at boot `ProviderRepository` instantiates the listed providers, hits `SentinelServiceProvider`, throws a fatal `Class not found`, the boot aborts before the `view` binding registers, and every request to NativePHP's embedded PHP server returns HTTP 500. The window's loadURL therefore renders nothing. `native:serve` works because it runs from the project's FULL `vendor/` tree (sentinel present), so the identical stale cache is harmless in dev."
  confirming_evidence:
    - "Packaged app log ~/Library/Application Support/diederik/storage/logs/laravel-2026-05-22.log: `ERROR: Class \"Laravel\\Sentinel\\SentinelServiceProvider\" not found at .../ProviderRepository.php:205`, cascading into `BindingResolutionException: Target class [view] does not exist` on every request."
    - "`composer why laravel/sentinel` → `laravel/horizon v5.47.0 requires laravel/sentinel (^1.0)`. Horizon is in composer.json `require-dev`, NOT `require`."
    - "Bundled `vendor/laravel/` contains fortify, framework, passkeys, prompts, serializable-closure — but NO `sentinel` and NO `horizon` (stripped by `composer install --no-dev`)."
    - "`git show 6243038 -- bootstrap/cache/` shows packages.php + services.php were committed; both list Sentinel/Horizon providers. The bundled `.../build/app/bootstrap/cache/packages.php` still contains `Sentinel`."
    - "vendor/nativephp/desktop/src/Builder/Concerns/PrunesVendorDirectory.php runs `composer install --no-dev`; that composer run's post-autoload hook regenerates package discovery against the --no-dev tree — so a build with NO committed cache files produces a CORRECT cache with no phantom providers."
  falsification_test: "If the committed cache were NOT the cause, deleting it would not change the bundled `packages.php` contents — but the build regenerates package discovery during `composer install --no-dev`, so a clean (uncommitted) `bootstrap/cache/` yields a Sentinel-free manifest. Conversely, if it were a route/config cache problem, the packaged app would have a `config.php`/`routes-v7.php` in its bundle bootstrap/cache — it does not (NativePHP writes those to userData at first launch)."
  fix_rationale: "Laravel-generated `bootstrap/cache/*.php` are per-environment artifacts and must never be committed. Removing them from git + gitignoring `/bootstrap/cache/*.php` (keeping `.gitkeep`) means the bundle ships with an empty cache directory; NativePHP's `composer install --no-dev` and first-launch `artisan optimize` regenerate package/service/config/route caches against the bundle's ACTUAL vendor tree — no phantom `SentinelServiceProvider`. This is the standard Laravel convention (the framework's own `.gitignore` ignores these)."
  blind_spots: "Cannot run native:build headlessly — the user must rebuild the .dmg to confirm. The c0f4ef1/89c0340 code-signing fix and the 37f5dd0 window-open code are both correct and untouched; this cycle fixes a third, independent defect. If a future build still fails, the next suspect is route:cache choking on the closure-based `/` route in Modules/Core/Routes/web.php — but `artisan optimize` tolerates uncacheable closure routes by skipping the route cache, so this is unlikely."

### Cycle 3 (2026-05-22T22:30:00Z) — signing fixed, but no window opens

reasoning_checkpoint:
  hypothesis: "The code-signing crash is resolved (user confirmed the rebuilt .dmg shows `injected identity: null`, installs, and the process now stays alive). The remaining 'no window' symptom is a SEPARATE, real defect: NativeAppServiceProvider::boot() is empty. NativePHP v2 does NOT open any default window — the app author must explicitly call WindowManager::open(). With an empty boot() the Electron shell runs (dock/menu-bar icon present) but never creates a BrowserWindow, so nothing renders. The Cycle-1/2 Eliminated note that dismissed 'empty boot()' was correct only WHILE the dyld abort masked it; now that the abort is gone, the empty boot() is exposed as the active bug."
  confirming_evidence:
    - "User observation: app stays open, shows in dock/menu bar, quits normally — but displays no window. A running process with no window is exactly an Electron app that never called BrowserWindow."
    - "Modules/Desktop/Internal/NativeAppServiceProvider::boot() body was `//` — no Window open call of any kind."
    - "vendor/nativephp/desktop NativeAppBootedController does `app(config('nativephp.provider'))->boot()` — NativePHP's only window trigger is whatever the app's provider boot() calls. No framework-default window exists."
    - "15-01-PLAN.md Task 4 acceptance criterion explicitly requires 'Launching the app opens a native window that renders the diederik web UI' — a visible window IS in 15-01 scope."
    - "config/nativephp.php has no window/url block; the Window object defaults `url` to `url('/')` and `title` to `config('app.name')` (= 'diederik') in its constructor — so a bare open('main') already renders the app root."
  falsification_test: "If NativePHP v2 opened a default window itself, an empty boot() would still show a window — it does not (user sees none). If the window code were present-but-broken, native:serve would also fail to show a window."
  fix_rationale: "Add a single WindowManager::open('main') call in boot(). The window's constructor already defaults url -> url('/') and title -> config('app.name'), so a bare open() renders the diederik web UI with the correct title. This is the MINIMAL change that satisfies the 15-01 Task 4 gate; geometry persistence, app menu, and tray remain deferred to 15-02."
  blind_spots: "Cannot launch a packaged build headlessly here — the user must re-verify via native:build or native:serve. The WindowManager contract's open() has no declared return type (returns mixed), so chaining ->title() would trip Larastan level 10; relying on the constructor default title sidesteps that and is also genuinely sufficient since APP_NAME is already 'diederik'."

### Cycle 2 (2026-05-22T22:00:00Z) — "silent exit" is the SAME crash

reasoning_checkpoint:
  hypothesis: "The c0f4ef1 prebuild hook never applied. The hook patches $projectRoot/nativephp/electron/electron-builder.mjs, but NativePHP's ElectronServiceProvider::electronPath() falls back to the VENDOR copy (vendor/nativephp/desktop/resources/electron/) whenever nativephp/electron/package.json is absent. The project has no nativephp/electron/ directory, so the build ran against the unpatched vendor electron-builder.mjs (mac block has no `identity` key) and the hook silently exited 0 with 'no electron-builder.mjs found, skipping'. electron-builder then auto-discovered a keychain identity and produced a Team-ID-mismatched bundle. The 'silent exit with no window' the user reports is in fact the SAME dyld Team-ID abort — it aborts in dyld4::prepare before any window is created, so macOS shows no crash dialog."
  confirming_evidence:
    - "Crash reports diederik-2026-05-22-2151{42,43}.ips (launch time) — EXC_CRASH/SIGABRT, DYLD 'Library missing', 'Electron Framework ... not valid for use in process: ... different Team IDs'. codeSigningMonitor=1. Backtrace ends in dyld4::prepare → dyld4::start: crash is BEFORE the app runs."
    - "ElectronServiceProvider::electronPath() (line 21-27): returns base_path('nativephp/electron') only if that dir has package.json, ELSE returns the vendor default vendor/nativephp/desktop/resources/electron."
    - "No nativephp/electron/ directory exists in the project (`ls -d vendor/nativephp/electron` empty; `find . -name electron-builder.mjs` finds only the vendor copy)."
    - "vendor/nativephp/desktop/resources/electron/electron-builder.mjs mac block (lines 107-115) still has NO `identity` key — the build target was never patched."
    - "scripts/nativephp_force_adhoc_signing.php hard-codes \$configPath = \$projectRoot.'/nativephp/electron/electron-builder.mjs' — a path that does not exist — so it prints 'skipping' and exits 0, a silent no-op."
  falsification_test: "If the hook HAD patched the build target, the vendor electron-builder.mjs mac block would contain `identity: null`. It does not. Conversely, if electronPath() always used nativephp/electron/, the prior fix path would be correct — but the documented fallback logic and the missing directory contradict that."
  fix_rationale: "Rewrite the prebuild hook to resolve the SAME path NativePHP's build uses: prefer base_path('nativephp/electron/electron-builder.mjs') when nativephp/electron/package.json exists, otherwise patch the vendor fallback at vendor/nativephp/desktop/resources/electron/electron-builder.mjs. This guarantees the file the build actually reads gets `identity: null`, making ad-hoc signing deterministic regardless of keychain state. The Builder::preProcess() runs the hook before buildOrPublish() invokes electron-builder, so the patch lands in time."
  blind_spots: "The vendor fallback file is reinstalled by `composer install`/`update`, so the patch must remain idempotent and re-run every build (it does — it's a prebuild hook). If a future NativePHP version restructures electronPath() the hook's resolution logic must be revisited. Cannot run native:build headlessly here — user must re-verify."

### Cycle 1 (superseded — fix targeted the wrong file)

reasoning_checkpoint:
  hypothesis: "NativePHP's electron-builder.mjs has no `mac.identity` key. electron-builder's macOS signing then auto-discovers a keychain identity. When a discovered identity exists (Xcode-provisioned 'Apple Development' cert, expired Developer ID, etc.), electron-builder partially signs the bundle — the app shell ends up ad-hoc/empty-TeamID while the nested Electron Framework retains a non-empty Team ID, causing dyld to abort on Apple Silicon. With no identity in the keychain it does clean ad-hoc signing. The build is therefore non-deterministic."
  confirming_evidence:
    - "vendor/.../electron-builder.mjs `mac` block has entitlementsInherit + extendInfo but NO `identity` key — electron-builder default = auto-discover from keychain."
    - "Crash report: framework had a non-empty Team ID, main exe codeSigningTeamID empty → classic mixed/partial signing."
    - "Current keychain: `security find-identity` → 0 valid identities. Current dist/ bundle is cleanly ad-hoc (both main exe + framework TeamIdentifier=not set, `codesign --verify --deep --strict` passes)."
    - "Discrepancy between crash report and current clean bundle is explained only by transient keychain state at build time → confirms non-determinism."
  falsification_test: "If electron-builder ignored the keychain entirely, adding `identity: null` would change nothing and a build with a planted identity would still come out ad-hoc. (Documented electron-builder behavior contradicts this.)"
  fix_rationale: "Explicitly setting `mac.identity: null` forces electron-builder to ALWAYS perform consistent `--deep` ad-hoc signing regardless of keychain contents. This makes the build reproducible and guarantees no Team-ID mismatch. It does not require any paid Apple Developer ID."
  blind_spots: "Cannot reproduce the original crash without planting an identity in the keychain. Relying on documented electron-builder semantics + the matching symptom pattern. The absolute-@rpath note in the crash report is NOT present in the current build (rpath = @executable_path/../Frameworks) — that was a side effect of launching the in-place dev build, not a packaging defect; no fix needed."
test: Apply `mac.identity: null` to the NativePHP electron-builder config and confirm the build stays clean.
next_action: Patch electron-builder.mjs via NativePHP's customisation path so the change survives vendor reinstall.

## Symptoms

expected: `php artisan native:build` produces a .dmg whose installed app launches cleanly.
actual: app crashes on launch — DYLD "Library missing" for @rpath/Electron Framework.framework.
errors: |
  Termination Reason: Namespace DYLD, Code 1 Library missing
  Library not loaded: @rpath/Electron Framework.framework/Electron Framework
  code signature 'Electron Framework' not valid: mapping process and mapped file
  (non-platform) have different Team IDs
  codeSigningTeamID "" on main executable; @rpath resolved to absolute dev build dir path.
reproduction: php artisan native:build (mac arm64), install .dmg, launch app.
started: Phase 15 — first NativePHP build (nativephp/desktop 2.2.0, nativephp/php-bin 1.2.0).

## Eliminated

- hypothesis: "The c0f4ef1 fix resolved the crash; the new symptom (silent exit, no window) is a separate boot/window-lifecycle failure."
  evidence: "Crash reports from launch time (2151{42,43}.ips) show the IDENTICAL DYLD Team-ID abort. There is no separate boot failure — no Laravel log, no APP_KEY error, no DB error. The 'silent exit' is just the same SIGABRT happening pre-window so macOS shows no dialog."
  timestamp: 2026-05-22T22:05:00Z

- hypothesis: "Empty NativeAppServiceProvider::boot() (no Window::open()) is the root cause — app starts and exits with nothing to show."
  evidence: "The PHP/Laravel layer is never reached. dyld aborts the Electron process in dyld4::prepare, long before NativePHP boots Laravel or calls the provider. boot() being empty is real but not THIS bug — it would only matter once the bundle launches."
  timestamp: 2026-05-22T22:06:00Z

## Evidence

- timestamp: 2026-05-22T23:40:00Z
  checked: nativephp/desktop 2.2.0 Builder source — BuildCommand (buildBundle vs buildUnsecure), CopiesToBuildDirectory, CopiesBundleToBuildDirectory, PrunesVendorDirectory, HasPreAndPostProcessing; project .env BIFROST keys; composer.json scripts; physical state of bootstrap/cache/
  found: |
    The project runs the UNSECURE build path: no `BIFROST_*` env, no
    `build/__nativephp_app_bundle`, so `Builder::hasBundled()` is false and
    `BuildCommand::buildUnsecure()` runs. That path = preProcess() →
    copyToBuildDirectory() → cleanEnvFile() → pruneVendorDirectory().
    `copyToBuildDirectory()` copies physical working-tree files, skipping any
    whose relative path matches a `cleanup_exclude_files` fnmatch glob.
    `pruneVendorDirectory()` runs `composer install --no-dev` inside build/app
    — but composer.json has NO `post-autoload-dump` script, so no
    `package:discover` runs and `bootstrap/cache/packages.php` is NEVER
    regenerated in the bundle. Cycle 4's `git rm --cached` left the three
    cache files physically on disk (mtime 23:13), and the dev tree keeps
    regenerating packages.php WITH the Sentinel provider.
  implication: |
    Cycle 4's fix was insufficient on two counts: `git rm --cached` does not
    delete files from disk, and nothing in the build regenerates the cache.
    The durable fix is `cleanup_exclude_files` — the build's own copy filter
    — which excludes the three caches at copy time on every build regardless
    of dev-side regeneration. The stale files are also deleted now for a
    clean immediate state.

- timestamp: 2026-05-22T23:30:00Z
  checked: Packaged app Laravel log at ~/Library/Application Support/diederik/storage/logs/laravel-2026-05-22.log; bundled vendor/laravel/ directory; `composer why laravel/sentinel`; git show 6243038 -- bootstrap/cache/
  found: |
    The packaged app logs `ERROR: Class "Laravel\Sentinel\SentinelServiceProvider"
    not found` at ProviderRepository.php:205, cascading into
    `BindingResolutionException: Target class [view] does not exist` on every
    request. `laravel/sentinel` is a TRANSITIVE dependency of `laravel/horizon`,
    which is in composer.json `require-dev` only. The build runs
    `composer install --no-dev`, so the bundled `vendor/laravel/` has fortify,
    framework, passkeys, prompts, serializable-closure — but NOT sentinel/horizon.
    Commit 6243038 committed `bootstrap/cache/packages.php` + `services.php`,
    generated locally with the full dev tree; both hardcode the Sentinel/Horizon
    providers. The build copies these stale files into the bundle.
  implication: |
    ROOT CAUSE of the packaged-only no-window symptom. The bundle boots with a
    stale package-discovery cache referencing a provider class that does not
    exist in the --no-dev vendor tree. The fatal aborts Laravel boot before the
    `view` binding registers, so every request to NativePHP's embedded PHP
    server 500s and the window renders nothing. `native:serve` is unaffected
    because dev runs from the full `vendor/` tree where sentinel exists.
    `bootstrap/cache/*.php` must never be committed — they are per-environment
    artifacts the build regenerates against its own vendor tree.

- timestamp: 2026-05-22T22:30:00Z
  checked: Modules/Desktop/Internal/NativeAppServiceProvider::boot(); vendor/nativephp/desktop Http/Controllers/NativeAppBootedController; Windows/WindowManager + Windows/Window + Contracts/WindowManager
  found: boot() was empty (`//`). NativePHP boots the app then calls `app(config('nativephp.provider'))->boot()` — that provider call is the ONLY window trigger; there is no framework default window. The container-bound `Native\Desktop\Contracts\WindowManager` is injectable (bound in NativeServiceProvider:77 to the concrete `Windows\WindowManager`). `WindowManager::open('main')` returns a `PendingOpenWindow` whose constructor defaults `url` to `url('/')` and `title` to `config('app.name')`.
  implication: ROOT CAUSE of the no-window symptom — boot() never opened a window. Because the provider is resolved via `app()` (through the container), constructor injection of the `WindowManager` contract works — no facade needed, DI-only rule honoured.

- timestamp: 2026-05-22T22:32:00Z
  checked: Larastan level 10 on Modules/Desktop after adding `->title('diederik')` chained on open()
  found: `method.nonObject — Cannot call method title() on mixed` — the `WindowManager` contract's `open()` has no declared return type.
  implication: Dropped the redundant `->title()` chain. The Window constructor already defaults the title to `config('app.name')` = 'diederik' (APP_NAME=diederik), so a bare `open('main')` renders the app root with the correct title and stays Larastan-clean.

- timestamp: 2026-05-22T22:00:00Z
  checked: ~/Library/Logs/DiagnosticReports/diederik-2026-05-22-2151{42,43}.ips
  found: EXC_CRASH / SIGABRT, termination namespace DYLD, "Library missing", "Electron Framework ... code signature ... not valid for use in process: mapping process and mapped file (non-platform) have different Team IDs". codeSigningMonitor=1. Backtrace = __abort_with_payload → dyld4::halt → dyld4::prepare → dyld4::start.
  implication: The "silent exit" is the SAME Team-ID crash, aborting before any window. The c0f4ef1 fix did not take effect.

- timestamp: 2026-05-22T22:02:00Z
  checked: codesign -dvv on the installed /Applications/diederik.app main exe + Electron Framework
  found: Both currently report Signature=adhoc, TeamIdentifier=not set; `codesign --verify --deep --strict` passes. (This particular installed bundle was built with an empty keychain — but the 21:51 crash report is from a build/launch where the mismatch was present.)
  implication: Confirms the build is non-deterministic. The disk state of one lucky build is clean, but builds with a keychain identity present still crash. The fix must make EVERY build deterministic.

- timestamp: 2026-05-22T22:04:00Z
  checked: ElectronServiceProvider::electronPath() (vendor/nativephp/desktop/src/Drivers/Electron/ElectronServiceProvider.php:21-27); presence of nativephp/electron/ directory
  found: electronPath() returns base_path('nativephp/electron') ONLY if that dir contains package.json, else the vendor default vendor/nativephp/desktop/resources/electron. No nativephp/electron/ directory exists in the project.
  implication: native:build ran electron-builder against the VENDOR electron-builder.mjs, not the project-local path the prebuild hook targets.

- timestamp: 2026-05-22T22:05:00Z
  checked: scripts/nativephp_force_adhoc_signing.php $configPath; vendor/nativephp/desktop/resources/electron/electron-builder.mjs mac block
  found: The hook hard-codes $projectRoot.'/nativephp/electron/electron-builder.mjs' (nonexistent) → prints "no electron-builder.mjs found, skipping" → exit 0. The vendor electron-builder.mjs mac block (lines 107-115) still has no `identity` key.
  implication: ROOT CAUSE — the c0f4ef1 prebuild hook patches a file the build never reads, and silently no-ops. The build target stays unpatched, so electron-builder auto-discovers a keychain identity and produces the Team-ID-mismatched bundle.

- timestamp: 2026-05-22T21:35:00Z
  checked: nativephp/electron/dist/builder-effective-config.yaml + vendor/nativephp/desktop/resources/electron/electron-builder.mjs
  found: The `mac` block sets entitlementsInherit, artifactName, extendInfo — but NO `identity` key.
  implication: electron-builder uses default behavior = auto-discover a signing identity from the keychain.

- timestamp: 2026-05-22T21:36:00Z
  checked: codesign -dv on main exe and Electron Framework in current dist/mac-arm64 bundle
  found: Both report Signature=adhoc, TeamIdentifier=not set. `codesign --verify --deep --strict` → "valid on disk, satisfies its Designated Requirement". rpath = @executable_path/../Frameworks (relocatable).
  implication: The CURRENT bundle is clean. The crash report describes a STALE earlier build — confirming non-determinism.

- timestamp: 2026-05-22T21:37:00Z
  checked: security find-identity -v -p codesigning
  found: 0 valid identities found.
  implication: With an empty keychain electron-builder did clean ad-hoc signing. The crash occurred on a build where a keychain identity was present and caused partial/mismatched signing. Build outcome depends on transient keychain state.

## Resolution

root_cause: |
  Cycle 4 — packaged-only no-window root cause (THE active defect):
  `bootstrap/cache/packages.php` and `bootstrap/cache/services.php` were
  committed to git in commit 6243038, generated locally while the full
  (dev) dependency tree was installed. They hardcode
  `Laravel\Sentinel\SentinelServiceProvider` — a transitive dependency of
  `laravel/horizon`, which lives in composer.json `require-dev` only.
  NativePHP's build runs `composer install --no-dev`, stripping horizon and
  its transitive sentinel from the bundled `vendor/`. The build then copies
  the stale committed `packages.php` into the bundle. At boot,
  `ProviderRepository` instantiates the cached provider list, hits the
  absent `SentinelServiceProvider`, throws a fatal `Class not found`, and
  Laravel boot aborts before the `view` binding is registered — so every
  HTTP request to NativePHP's embedded PHP server returns a 500 and the
  window renders nothing. `php artisan native:serve` is unaffected: dev
  runs from the project's full `vendor/` tree where sentinel is present, so
  the identical stale cache is harmless.

  Cycle 3 — no-window root cause:
  `Modules/Desktop/Internal/NativeAppServiceProvider::boot()` was empty. NativePHP v2
  does not open any default window — the app's provider boot() must explicitly call
  `WindowManager::open()`. The dyld code-signing abort (below) masked this in cycles
  1-2 because the process died before boot() ran; once signing was fixed the empty
  boot() surfaced as a live process with no rendered window.

  Cycles 1-2 — code-signing crash (resolved):
  Two-layer root cause.
  (1) Underlying defect: NativePHP's bundled electron-builder config omits `mac.identity`, so electron-builder auto-discovers a keychain code-signing identity at build time. When an identity is present the bundle is partially signed — the app shell stays ad-hoc (empty Team ID) while the nested Electron Framework keeps a non-empty Team ID — and on Apple Silicon dyld aborts at launch with mismatched Team IDs. This abort happens in dyld4::prepare BEFORE any window, so macOS shows no crash dialog — it looks like a "silent exit".
  (2) Why the cycle-1 fix did not work: the cycle-1 prebuild hook patched `$projectRoot/nativephp/electron/electron-builder.mjs`. But `ElectronServiceProvider::electronPath()` only uses that project-local directory when it contains `package.json`; otherwise it falls back to the package default at `vendor/nativephp/desktop/resources/electron/`. The project has no `nativephp/electron/` directory, so the build read the VENDOR electron-builder.mjs — which the hook never touched. The hook found nothing at its hard-coded path, printed "skipping", and exited 0: a silent no-op. The build target stayed unpatched and kept crashing.
fix: |
  Cycle 4 — stop committing Laravel bootstrap caches:
  Removed `bootstrap/cache/packages.php`, `bootstrap/cache/services.php`, and
  `bootstrap/cache/modules.php` from git (`git rm --cached`) and added
  `/bootstrap/cache/*.php` to `.gitignore` (the `.gitkeep` is retained so the
  directory still exists for the build). The bundle now ships with an empty
  `bootstrap/cache/` directory; NativePHP's `composer install --no-dev`
  post-autoload hook and the first-launch `artisan optimize` regenerate the
  package, service, config, route, and event caches against the bundle's
  ACTUAL `--no-dev` vendor tree — so no phantom `SentinelServiceProvider` is
  ever referenced. This matches the standard Laravel convention (the
  framework's own `.gitignore` ignores `bootstrap/cache/*.php`). No PHP code
  changed; the c0f4ef1/89c0340 signing fix and the 37f5dd0 window-open code
  are correct and untouched.

  Cycle 3 — open the application window:
  Added a constructor-injected `Native\Desktop\Contracts\WindowManager` to
  `NativeAppServiceProvider` and made `boot()` call `$this->windows->open('main')`.
  Because NativePHP resolves the provider via `app(config('nativephp.provider'))`,
  the constructor is autowired — true constructor DI, no `Native\Desktop\Facades\Window`
  facade, so the project's DI-only rule and the `noNativePhpImportsOutsideDesktopModule`
  arch invariant both still hold. The opened window inherits its constructor defaults:
  `url` -> `url('/')` (renders the diederik web UI — login or dashboard per DB state)
  and `title` -> `config('app.name')` ('diederik'). No window/menu/tray chrome beyond
  the bare window — that is plan 15-02. Added `Modules/Desktop` to the `tests/Pest.php`
  module bootstrap loop so module Unit tests get a booted Laravel app, and implemented
  the previously-`->todo()` `it('configures the application window')` test using
  `Window::fake()` + `assertOpened('main')`.

  Cycles 1-2 — code-signing fix (resolved, confirmed by user):
  Rewrote scripts/nativephp_force_adhoc_signing.php to resolve the electron-builder.mjs the SAME way NativePHP's build does: prefer `nativephp/electron/electron-builder.mjs` when `nativephp/electron/package.json` exists, otherwise patch the vendor fallback `vendor/nativephp/desktop/resources/electron/electron-builder.mjs`. It injects `identity: null` as the first `mac` block key, forcing deterministic `--deep` ad-hoc signing regardless of keychain contents. The hook now exits 1 (fails loudly) if no electron-builder.mjs can be found at all, instead of silently no-opping. Still wired into config/nativephp.php `prebuild`, still idempotent, still runs in Builder::preProcess() before electron-builder is invoked. No paid Apple Developer ID required.
verification: |
  - Hook run against the real vendor electron-builder.mjs: injects `identity: null` as the first `mac` key (line 108). Second run reports "identity already configured" and is a no-op (idempotent).
  - `node --check` confirms the patched electron-builder.mjs is valid JavaScript.
  - Laravel Pint: passed. Larastan level 10 (--memory-limit=512M): "No errors".
  - Could not re-run native:build headlessly — the user must re-run `php artisan native:build mac arm64`, reinstall the .dmg, and confirm the app launches a window. End-to-end verification is the human checkpoint below.
verification (cycle 3): |
  - Larastan level 10 (--memory-limit=512M) on Modules/Desktop: "No errors".
  - Laravel Pint on the changed files: passed.
  - `php artisan test --filter="configures the application window"`: 1 passed (asserts
    boot() opens the 'main' window via Window::fake()).
  - `php artisan test --filter="noNativePhpImportsOutsideDesktopModule"`: passed —
    the NativePHP containment boundary still holds (the new import is inside
    Modules/Desktop/Internal).
  - Could not launch a packaged build headlessly — the user must re-verify: run
    `php artisan native:serve` (dev) OR `php artisan native:build mac arm64`, install,
    and launch /Applications/diederik.app — a window rendering the diederik web UI
    (login or dashboard) must now appear.
verification (cycle 4): |
  - Packaged app log inspected: confirmed `Class "Laravel\Sentinel\SentinelServiceProvider"
    not found` is the boot-time fatal that 500s every request.
  - `git rm --cached` removed the three cache files; `git check-ignore` confirms
    `/bootstrap/cache/*.php` now ignores all three; `bootstrap/cache/.gitkeep`
    remains tracked so the directory exists in a fresh checkout and in the bundle.
  - `php artisan package:discover` regenerates the local cache cleanly (it lists
    Sentinel locally — expected, the dev tree has horizon; the BUILD's --no-dev
    regeneration will not).
  - Could not run native:build headlessly — the user must rebuild the .dmg and
    relaunch to confirm a window now renders (human checkpoint below).
files_changed:
  - scripts/nativephp_force_adhoc_signing.php (cycle 2 — rewritten, resolves the real build target path)
  - Modules/Desktop/Internal/NativeAppServiceProvider.php (cycle 3 — constructor-injected WindowManager, boot() opens the main window)
  - Modules/Desktop/tests/Unit/NativeAppServiceProviderTest.php (cycle 3 — implemented the window-open test)
  - tests/Pest.php (cycle 3 — registered Modules/Desktop in the module test bootstrap loop)
  - bootstrap/cache/packages.php (cycle 4 — removed from git, now gitignored; cycle 5 — deleted from disk)
  - bootstrap/cache/services.php (cycle 4 — removed from git, now gitignored; cycle 5 — deleted from disk)
  - bootstrap/cache/modules.php (cycle 4 — removed from git, now gitignored; cycle 5 — deleted from disk)
  - .gitignore (cycle 4 — added /bootstrap/cache/*.php)
  - config/nativephp.php (cycle 5 — added bootstrap/cache/{packages,services,modules}.php to cleanup_exclude_files; cycle 6 — REVERTED that exclusion so the bundle's bootstrap/cache/ directory physically exists)
  - composer.json (cycle 6 — added standard Laravel post-autoload-dump script so the in-bundle composer install --no-dev regenerates the package manifest against the --no-dev vendor tree)

fix (cycle 6): |
  Cycle 5's `cleanup_exclude_files` exclusion fixed the stale-Sentinel
  symptom but broke the bundle a different way: with all three
  `bootstrap/cache/*.php` files excluded, the build's copy iterator never
  materialised the `bootstrap/cache/` subdirectory in the bundle (the lone
  `.gitkeep` did not create it). Laravel's `PackageManifest::write()`
  requires that directory to already exist and be writable — it does not
  mkdir — so the bundle fatalled with "the bootstrap/cache directory must
  be present and writable", cascading to "Target class [view] does not
  exist" and a blank window.

  The TRUE underlying root cause was a missing `post-autoload-dump` script
  in `composer.json`. NativePHP's unsecure build runs `composer install
  --no-dev` WITH scripts enabled (no `--no-scripts` flag). Without that
  script, the in-bundle install never ran `package:discover`, so the stale
  dev-tree manifest (with `Laravel\Sentinel\SentinelServiceProvider`) was
  never regenerated against the `--no-dev` vendor tree — the original
  cycle-4/5 saga.

  Cycle 6 fixes both with two coordinated changes:
  (1) Added the standard Laravel `post-autoload-dump` script
  (`Illuminate\Foundation\ComposerScripts::postAutoloadDump` +
  `@php artisan package:discover --ansi`) to `composer.json`.
  (2) Reverted cycle 5's `cleanup_exclude_files` exclusion. The (stale)
  caches now copy into the bundle normally, guaranteeing `bootstrap/cache/`
  exists; then the build order (copy files → `composer install --no-dev` →
  `post-autoload-dump` → `package:discover`) OVERWRITES the stale manifest
  with one built against the bundle's own `--no-dev` vendor — no phantom
  Sentinel provider. One mechanism solves both the directory-presence and
  the stale-cache problems. Cycle 4's gitignore of `bootstrap/cache/*.php`
  stays correct — they are generated artifacts, untracked but still on
  disk so they copy into the bundle.

verification (cycle 6): |
  - `vendor/nativephp/desktop/src/Builder/Concerns/PrunesVendorDirectory.php:16`
    confirmed to run `composer install --no-dev` with NO `--no-scripts` flag —
    so `post-autoload-dump` fires in-bundle.
  - `vendor/laravel/framework/src/Illuminate/Foundation/PackageManifest.php:178`
    confirmed to throw "directory must be present and writable" without mkdir.
  - `composer dump-autoload` re-run: `post-autoload-dump` fires and runs
    `package:discover` (full discovery output emitted) — script is wired correctly.
  - `composer validate` passes; Pint passes on the changed files.
  - `git check-ignore` confirms the three `bootstrap/cache/*.php` remain
    gitignored; all three are present on disk so they copy into the bundle.
  - Could not run native:build headlessly — the user must rebuild the .dmg
    and relaunch to confirm a window renders (human checkpoint below).

fix (cycle 5): |
  Cycle 4's fix was necessary (the caches should not be in git) but not
  sufficient: `git rm --cached` leaves the files on disk, and the dev tree
  continuously regenerates `bootstrap/cache/packages.php` WITH the Sentinel
  provider on every artisan call. NativePHP 2.2.0's UNSECURE build path
  (`buildUnsecure()`, the active path here — no Bifrost bundle) copies
  PHYSICAL working-tree files into `build/app`, and its `composer install
  --no-dev` step does NOT regenerate the cache (no `post-autoload-dump`
  script). So the stale Sentinel-referencing cache was bundled and fatalled
  boot. Cycle 5 adds `bootstrap/cache/packages.php`, `services.php`, and
  `modules.php` to `config/nativephp.php` `cleanup_exclude_files`. The
  build's own copy filter (`CopiesToBuildDirectory::copyToBuildDirectory()`)
  fnmatch-excludes those paths at copy time on EVERY build — they never
  enter the bundle no matter how often the dev side regenerates them. The
  bundle ships an empty `bootstrap/cache/` (`.gitkeep` only); Laravel
  regenerates package/service discovery lazily against the bundle's own
  `--no-dev` vendor on first boot. The three stale physical files were
  also deleted to clean the immediate working-tree state. Cycle 4's
  gitignore stays correct — these caches are environment-specific
  generated artifacts and must not be committed.
