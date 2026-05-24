---
phase: 16-developer-mode-ui
plan: 03
subsystem: dev-mode-skeleton
tags: [livewire, middleware, di-only, blade-layout, public-contracts, activitylog, sql-formatter, fuse-js, dev-shell, ensure-developer-mode, arch-invariant, settings-toggle]

# Dependency graph
requires:
  - phase: 12-multi-user-activation
    provides: "users.is_developer column (Phase 12 D-04 first-signup auto-promote). EnsureDeveloperMode + Settings toggle read/write the existing column; no new migration."
  - phase: 16-developer-mode-ui
    plan: 01
    provides: "Sectioned sidebar + --side-w token + .side-item / .side-foot primitives the dev-shell layout reuses. The 220px dev-shell width comes from --side-w-dev (new sibling token) so the main app's --side-w stays at 248px."
  - phase: 16-developer-mode-ui
    plan: 02
    provides: "Repo-wide beatrax brand + BoundaryArchTest invariants (impersonationSurfaceRemoved, noDiederikLiteralAfterRename). EnsureDeveloperMode middleware is the new gate; the existing RequireDeveloperMiddleware from Auth (16-02 left in place) stays for its current consumers."
provides:
  - "Modules/DevMode/ bounded module — registered in bootstrap/providers.php; binds four Public contracts (DevCommandRegistry, NavigationRegistry, AppActionRegistry, AuditWriter) to Null* concretes; aliases ensureDeveloperMode middleware."
  - "EnsureDeveloperMode middleware — 404-not-403 gate on /dev/* (T-16-01 mitigation); constructor-DI on CurrentUser; no facade calls."
  - "/dev overview route + DevOverviewPage Livewire component — mounted behind ['web', 'auth', 'ensureDeveloperMode'] + #[Layout('dev::layouts.dev-shell')]; placeholder content (more in Wave 5)."
  - "dev-shell.blade.php — 220px sidebar hard-swap variant of the main app shell; 9 nav items (8 nav-disabled this plan); embedded core.system-alerts-banner; Back-to-app foot link; @inject-routed CurrentUser (no auth() helper)."
  - "Four Public contract interfaces + supporting DTOs (CommandSpec, ArgSpec, NavigationEntry, AppAction) — cross-module surface every later DevMode plan consumes."
  - "Settings page Developer toggle — instant-apply via setDevMode(); writes users.is_developer via Eloquent; DB-persisted (survives logout/login); the first concrete use of the .switch CSS primitive."
  - "Wave 0 dependencies installed: spatie/laravel-activitylog ^5.0 (supersedes CONTEXT D-23's ^4.12 since v4 cannot resolve under Laravel 13), doctrine/sql-formatter ^1.5, fuse.js ^7.0."
  - "config/logging.php published — default 'daily' (D-27); empty 'tap: []' slot on every channel (16-04b fills with baseline RedactSecretsProcessor; 16-05 upgrades to full OAuth scrub-set); paths routed through UserDataPathService::logsFile() (new accessor)."
  - "config/activitylog.php published — table_name='dev_mode_audit' + default_log_name='dev_mode' (D-23)."
  - "config/database.php gains 'readonly_select' connection (D-45) cloned from sqlite via a closure-scoped local."
  - "dev_mode_audit table migrated (Pattern G anonymous-class migration in Modules/DevMode/Database/Migrations/)."
  - "BoundaryArchTest invariant everyDevModeRouteAppliesEnsureDeveloperModeMiddleware — walks runtime route table; locks the EnsureDeveloperMode coverage for every future /dev/* route at PR time."
  - "UserDataPathService gains logsFile() accessor — config/logging.php routes through it so the noStoragePathHardCodedOutsideUserDataPathService invariant stays green; the NativePHP bundle's NATIVEPHP_STORAGE_PATH retarget covers logs too."
  - "resources/css/app.css gains --side-w-dev (220px) + @layer components primitives (.dev-side, .dev-side-head, .dev-on-chip, .dev-back-link, .nav-disabled, .card, .switch)."
affects:
  - 16-04-artisan-runner-process-pipeline
  - 16-04b-audit-pipeline-triple-gate-sidebar-enable
  - 16-05-log-tailer-redaction
  - 16-06-queue-inspector-horizon-iframe
  - 16-07-doctor-sql-system
  - 16-08-command-palette

# Tech tracking
tech-stack:
  added:
    - "spatie/laravel-activitylog ^5.0 (CONTEXT D-23 supersession — v4 cannot resolve under Laravel 13)"
    - "doctrine/sql-formatter ^1.5 (16-07 SelectOnlyValidator wrapper consumer)"
    - "fuse.js ^7.0 (16-08 command-palette client-side fuzzy-search consumer)"
  patterns:
    - "Pattern C (PATTERNS.md): Public-interface / Internal-Null-concrete swap — every interface bound to a Null* default in DevModeServiceProvider::register(); later plans REPLACE the binding from their own ServiceProvider without touching this provider. The Null shape exists so consumer code can resolve the contract from day one without app()->bound(...) guards."
    - "Pattern D (PATTERNS.md): Module ServiceProvider boot wiring — register() binds singletons; boot(Router, LivewireManager) aliases middleware + loadMigrationsFrom + loadRoutesFrom + loadViewsFrom + Livewire component registration."
    - "Pattern G (PATTERNS.md): anonymous-class migration with injected DatabaseManager — mirrors create_system_alerts_table.php shape so the migration is reviewable as one of a pair."
    - "@inject of CurrentUser + Container contracts inside a Blade layout — the new pattern for keeping the noAuthFacadeOrHelper arch invariant green on Module/* Blade views that need the current user or an optional binding (Wave 1's app.blade.php sits outside Modules/* so it can call auth() / app() directly; the dev-shell layout lives inside Modules/DevMode so it routes through @inject instead)."
    - "Closure-scoped local for shared connection shape in config/database.php — readonly_select is array_merge($sqlite, [...]) where $sqlite is a local variable, not a config() lookup. Avoids the bootstrap-time chicken-and-egg of calling config() during config-load."
    - "Pattern F (PATTERNS.md) extended — arch invariant that walks the RUNTIME route table (Route::getRoutes()->getRoutes()) instead of the source filesystem. The middleware-alias coverage check needs the resolved middleware stack (gatherMiddleware()) so the runtime table is the only authoritative source."

key-files:
  created:
    - "Modules/DevMode/composer.json"
    - "Modules/DevMode/Providers/DevModeServiceProvider.php"
    - "Modules/DevMode/Internal/Http/Middleware/EnsureDeveloperMode.php"
    - "Modules/DevMode/Internal/Http/Livewire/DevOverviewPage.php"
    - "Modules/DevMode/Internal/Audit/NullAuditWriter.php"
    - "Modules/DevMode/Internal/Registries/NullDevCommandRegistry.php"
    - "Modules/DevMode/Internal/Registries/NullNavigationRegistry.php"
    - "Modules/DevMode/Internal/Registries/NullAppActionRegistry.php"
    - "Modules/DevMode/Public/Contracts/DevCommandRegistry.php"
    - "Modules/DevMode/Public/Contracts/NavigationRegistry.php"
    - "Modules/DevMode/Public/Contracts/AppActionRegistry.php"
    - "Modules/DevMode/Public/Contracts/AuditWriter.php"
    - "Modules/DevMode/Public/Dto/CommandSpec.php"
    - "Modules/DevMode/Public/Dto/ArgSpec.php"
    - "Modules/DevMode/Public/Dto/NavigationEntry.php"
    - "Modules/DevMode/Public/Dto/AppAction.php"
    - "Modules/DevMode/Database/Migrations/2026_05_24_000001_create_dev_mode_audit_table.php"
    - "Modules/DevMode/Routes/web.php"
    - "Modules/DevMode/Resources/views/layouts/dev-shell.blade.php"
    - "Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php"
    - "Modules/DevMode/tests/Pest.php"
    - "Modules/DevMode/tests/TestCase.php"
    - "Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php"
    - "Modules/DevMode/tests/Feature/DevOverviewPageTest.php"
    - "Modules/Core/tests/Feature/SettingsPageDevModeToggleTest.php"
    - "config/logging.php"
    - "config/activitylog.php"
  modified:
    - "bootstrap/providers.php — DevModeServiceProvider::class registered unconditionally (Horizon iframe is the only conditional surface; it lands in 16-06)"
    - "composer.json + composer.lock — spatie/laravel-activitylog ^5.0 + doctrine/sql-formatter ^1.5 added to require; Modules\\DevMode\\Tests\\ PSR-4 mapping added to autoload-dev"
    - "package.json + package-lock.json — fuse.js ^7.0 added to dependencies"
    - "config/database.php — readonly_select connection added via closure-scoped local; D-45 consumer (16-07) sets PRAGMA query_only=1 per-PDO"
    - "Modules/Core/Public/Services/UserDataPathService.php — logsFile() accessor added so config/logging.php can route paths through the user-data storage root (NATIVEPHP_STORAGE_PATH retarget covers logs)"
    - "Modules/Core/Internal/Http/Livewire/SettingsPage.php — #[Validate('boolean')] public bool $isDeveloper property + mount() seeds it from User->is_developer + setDevMode(bool, CurrentUser) writes via Eloquent fill()->save()"
    - "Modules/Core/Resources/views/livewire/settings-page.blade.php — Developer section (placed before Appearance) with .switch primitive bound to setDevMode; copy: 'Show the Dev Console at /dev. Resets the Advanced toggle on every login.'"
    - "resources/css/app.css — --side-w-dev: 220px + @layer components primitives (.dev-side, .dev-side-head, .dev-on-chip, .dev-back-link, .nav-disabled, .card, .switch)"
    - "tests/Contracts/BoundaryArchTest.php — everyDevModeRouteAppliesEnsureDeveloperModeMiddleware invariant added; use Illuminate\\Routing\\Route; statement added by Pint"
    - "tests/Pest.php — Modules/DevMode/tests/ binding added so module-local tests inherit RefreshDatabase + the DevMode TestCase"
    - "phpunit.xml — Modules/DevMode/tests/Unit + Feature paths added to the named testsuites"

key-decisions:
  - "spatie/laravel-activitylog v5.0 not v4.12 (CONTEXT D-23 supersession). RESEARCH Pitfall 3 confirms v4 (^4.12) cannot resolve under Laravel 13 + PHP 8.5 — v4 requires Laravel 9/10/11 only. v5.0.0 (2026-03-25) supports Laravel 12/13 + PHP 8.4+ and the call-site API (activity()->log(), config('activitylog.table_name') override) is unchanged between v4 and v5. Documented inline in 16-03-PLAN.md's CONTEXT DECISION RECONCILIATION block."
  - "Null* concrete pattern for the four Public contracts (Pattern C extended). DevCommandRegistry, NavigationRegistry, AppActionRegistry, AuditWriter all bind to Null* concretes in DevModeServiceProvider::register() so consumer code can resolve the contract from day one. Later plans REPLACE the binding: DevCommandRegistryImpl + SpatieAuditWriter land in 16-04; NavigationRegistryImpl + AppActionRegistryImpl land in 16-08. No consumer needs an app()->bound() null-check."
  - "dev-shell layout uses @inject (not auth() / app()) to honour the noAuthFacadeOrHelper arch invariant. The Modules/* Blade tree is in scope for the invariant; the @inject directive resolves a contract through the container (constructor-DI equivalent) so the layout reads the current user via CurrentUser::isAuthenticated() / user() and the OsThemeSignal optional binding via the injected Container. Wave 1's resources/views/layouts/app.blade.php sits OUTSIDE Modules/* so it can keep using auth() — only the dev-shell needed the new pattern."
  - "UserDataPathService::logsFile() new accessor — the project's hard rule routes EVERY filesystem path through this service so the NATIVEPHP bundle's NATIVEPHP_STORAGE_PATH retarget covers everything. Adding the accessor here (Rule 2 of deviation rules — missing critical functionality the new config file needed) means config/logging.php satisfies the noStoragePathHardCodedOutsideUserDataPathService invariant cleanly, and the 16-05 log tailer can read the same accessor."
  - "config/database.php closure-scoped local for the shared sqlite shape — readonly_select reuses the same database file and PRAGMA defaults as sqlite. Wrapping the connections array in a self-executing closure (static fn (): array => ...) keeps the sqlite shape in a local variable, sidesteps the bootstrap-time chicken-and-egg of calling config('database.connections.sqlite') from inside config/database.php itself."
  - "Live route-table walk for the everyDevModeRouteAppliesEnsureDeveloperModeMiddleware arch invariant (not a source-file grep). The middleware-alias coverage check needs the RESOLVED middleware stack (gatherMiddleware()) so source-file grep is unsuitable — group-applied middleware lives in the route group declaration, not on each route. Walking the runtime table is the only authoritative path. The URI filter is precise ('dev' or starts-with 'dev/') so future routes like '/developer-tools/...' do not get false-positive-matched."
  - "Dev sidebar nav-disabled affordance via Route::has(...) per item — each nav entry checks if its target route name is registered and applies nav-disabled when not. As downstream plans register routes (16-04b adds dev.artisan + dev.audit; 16-05 adds dev.logs; 16-06 adds dev.queue + dev.horizon; 16-07 adds dev.doctor + dev.sql + dev.system) the disabled class drops automatically — no edit to the dev-shell layout. NOTE per output spec: per B-5 fix the original 16-04 was split into 16-04 (process pipeline, SAFE only) + 16-04b (audit + UI + triple-gate + sidebar enable); the Artisan + Audit sidebar enables therefore land in 16-04b not 16-04."
  - "config/logging.php 'tap: []' slot on every channel that has a driver. The empty slot is intentionally a placeholder; 16-04b installs the baseline RedactSecretsProcessor FQCN (W-1 fix — closes the redaction-gap window in the same Wave 4 as the runner) and 16-05 upgrades the same processor in place with the full OAuth scrub-set. Wiring the slot here means downstream plans only fill the FQCN — they never re-edit the file's structure."

patterns-established:
  - "Public-interface + Null-default-concrete + later-plan-swap. The pattern lets a module ship the contract surface in Wave 0 of its phase without forcing every consumer to provide a real implementation, and it eliminates the app()->bound() null-check pattern at every call site. Future modules with similar 'contract first, concrete later' shapes should mirror it."
  - "Closure-scoped local in config/*.php files for derived connection / channel shapes. When one config entry is a derivation of another (e.g. readonly_select = sqlite with overrides), wrapping the array in a self-executing static closure keeps the base shape addressable as a local variable without a circular config() lookup."
  - "@inject of CurrentUser inside a Module Blade layout to satisfy noAuthFacadeOrHelper. The pattern preserves the DI-only rule for layouts that need the current user; the alternative (passing the user from every Livewire render() into the layout's slot) is more friction without a real safety upside."
  - "Live runtime route-table walking for middleware-alias-coverage arch invariants. The standard pest-plugin-arch shape walks the filesystem; for middleware coverage the resolved stack from gatherMiddleware() is the only authoritative source, so a Pest `it(...)` test (not an `arch(...)` rule) that iterates Route::getRoutes() is the right tool."

requirements-completed: [DEVUI-01]

# Metrics
duration: 70min
completed: 2026-05-24
---

# Phase 16 Plan 03: DevMode Module Skeleton + EnsureDeveloperMode + Dev Shell + Settings Toggle Summary

**Lands the entire Wave-3 DevMode skeleton: Modules/DevMode/ bounded module with the EnsureDeveloperMode 404-gate middleware, /dev route + DevOverviewPage rendered through the 220px dev-shell layout, four Public contract interfaces bound to Null* defaults, the Settings page Developer toggle writing users.is_developer, and the Wave 0 infrastructure (spatie/laravel-activitylog ^5.0 + doctrine/sql-formatter ^1.5 + fuse.js ^7.0 + config/logging.php with empty tap slots + config/activitylog.php with the dev_mode_audit table override + config/database.php with the readonly_select connection) every downstream Phase 16 plan depends on.**

## Performance

- **Duration:** ~70 min (env bootstrap + research + 3 tasks + verification)
- **Tasks:** 3 (all autonomous, all TDD)
- **Commits:** 3 atomic commits
- **Files created:** 27
- **Files modified:** 11
- **Files deleted:** 1 (the spatie-published `2026_05_24_135310_create_activity_log_table.php` after I rehoused it inside the DevMode module's Database/Migrations tree)
- **Test growth:** 2196 → 2210 passed (+14 = 4 EnsureDeveloperMode + 5 DevOverviewPage + 1 arch invariant + 4 SettingsPageDevModeToggle)
- **Net assertions:** 24832 → 24873 (+41)

## Accomplishments

### Task 1 — DevMode module skeleton + middleware + 4 Public contracts + Wave 0 deps (commit `5dc457c`)

- `Modules/DevMode/` bounded module created (composer.json `beatrax/devmode`, PSR-4 `Modules\\DevMode\\` → `Modules/DevMode/`, registered in `bootstrap/providers.php` unconditionally).
- `EnsureDeveloperMode` middleware (`Modules/DevMode/Internal/Http/Middleware/EnsureDeveloperMode.php`) — line-for-line clone of `RequireDeveloperMiddleware` shape; throws `NotFoundHttpException` for any non-developer request (T-16-01 information-disclosure mitigation); aliased as `ensureDeveloperMode` in `DevModeServiceProvider::boot()`.
- Four Public contracts + supporting DTOs:
  - `DevCommandRegistry` (safe / destructive / find) + `CommandSpec` + `ArgSpec`
  - `NavigationRegistry` (all) + `NavigationEntry`
  - `AppActionRegistry` (all) + `AppAction`
  - `AuditWriter` (recordCommandRun / recordDestructiveQueueAction / recordSelectQuery)
- Null* concretes bound in `DevModeServiceProvider::register()`:
  - `NullDevCommandRegistry` (empty SAFE + DESTRUCTIVE; `find()` throws `InvalidArgumentException`)
  - `NullNavigationRegistry` + `NullAppActionRegistry` (each returns `[]`)
  - `NullAuditWriter` (every method is a no-op)
- Wave 0 dependency installs:
  - `composer require spatie/laravel-activitylog:^5.0 doctrine/sql-formatter:^1.5` (v5.0.0 + v1.5.4 resolved). The v5 vs v4.12 supersession is documented in PLAN.md's CONTEXT DECISION RECONCILIATION block.
  - `npm install fuse.js@^7.0` (v7.3.0 resolved)
  - `php artisan vendor:publish --tag=activitylog-config` → edited to `table_name='dev_mode_audit'` + `default_log_name='dev_mode'`
  - `php artisan vendor:publish --tag=activitylog-migrations` → published file MOVED to `Modules/DevMode/Database/Migrations/2026_05_24_000001_create_dev_mode_audit_table.php` with `Schema::create('dev_mode_audit', ...)`; the table is configured per the `table_name` override above so spatie reads/writes it.
  - `config/logging.php` published (copied from `vendor/laravel/framework/config/logging.php`) — default = `'daily'` (D-27); every driver-bearing channel gets `'tap' => []` (empty placeholder for the 16-04b RedactSecretsProcessor FQCN per W-1 fix; 16-05 upgrades to full OAuth scrub-set).
  - `config/database.php` edited to add `readonly_select` connection cloned from `sqlite` via a closure-scoped local (so the sqlite shape stays addressable without a circular `config()` call).
- 4 Pest feature tests in `EnsureDeveloperModeTest`:
  1. Unauthenticated → 404 (after seeding a user so EnsureDatabaseReady is a pass-through)
  2. Authenticated non-developer → 404
  3. Authenticated developer → 200 with body `PROBE`
  4. All four Public contracts resolve via `app(...)` and return empty / no-op
- The `/dev/__probe` route is registered inside the test's `beforeEach` (`Route::middleware(['web', 'ensureDeveloperMode'])->get(...)`) so the middleware is exercised in isolation from the Task 2 layout-rendering paths.

### Task 2 — `/dev` route + dev-shell layout + DevOverviewPage + arch invariant (commit `1c8c7c8`)

- `/dev` route group registered in `Modules/DevMode/Routes/web.php` behind `['web', 'auth', 'ensureDeveloperMode']` middleware; only `dev.overview` registered this plan (Wave 4/5/6/7 plans append the rest).
- `DevOverviewPage` Livewire component — `#[Layout('dev::layouts.dev-shell')]` attribute mounts it through the 220px dev-shell layout; method-DI on `render(ViewFactory)` per Pattern B; placeholder content (heading "Overview" + "More Dev Console panels arriving in Wave 5" card).
- `dev-shell.blade.php` layout:
  - 220px sidebar via `--side-w-dev` CSS custom property (inline + declared in `@theme`).
  - "Dev Console" heading + amber `ON` chip (per UI-SPEC § Dev Console sidebar).
  - 9 nav items (Overview / Artisan / Audit / Logs / Queue / Doctor / SQL / Horizon / System); each entry checks `Route::has(...)` and applies the `nav-disabled` class when the route is not yet registered (only Overview is enabled this plan).
  - "Back to app" foot link to `/`.
  - Embedded `@livewire('core.system-alerts-banner')` at top of `<main>` per D-04.
  - Reads `CurrentUser` + `Container` via `@inject` (not `auth()` / `app()`) so the layout satisfies the `noAuthFacadeOrHelper` arch invariant. The theme resolution (light / dark / system + OsThemeSignal optional read + pre-paint script for `system`-themed renders) mirrors `app.blade.php`'s intent without duplicating the facade calls.
- `resources/css/app.css` gains:
  - `--side-w-dev: 220px` token (in `@theme`)
  - `@layer components` primitives: `.dev-side` (220px hard-swap of `.side`), `.dev-side-head`, `.dev-on-chip` (amber pill), `.dev-back-link`, `.nav-disabled` (opacity 0.5 + pointer-events: none), `.card` (calm surface card), `.switch` + `.switch--on` + `.switch__thumb` (sketch-findings emerald-when-on toggle, 120ms thumb slide).
- New arch invariant `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` in `tests/Contracts/BoundaryArchTest.php` — walks the live route table; filters URIs to `dev` or starts-with `dev/` (precise containment, no `developer/*` false positives); asserts every match's `gatherMiddleware()` contains `'ensureDeveloperMode'`. Locks coverage at PR time.
- 5 Pest feature tests in `DevOverviewPageTest`: 200 for developer; 404 for non-developer; all 9 nav items present with exactly 8 `nav-disabled` entries; 220px width declared; "Back to app" foot link present.

### Task 3 — Settings Developer toggle wiring (commit `7929266`)

- `SettingsPage` Livewire component gains:
  - `#[Validate('boolean')] public bool $isDeveloper` property.
  - `mount(CurrentUser)` seeds `$isDeveloper` from `$user->is_developer === true`.
  - `setDevMode(bool $value, CurrentUser $currentUser)` writes the boolean to the User Eloquent model via `fill(['is_developer' => $value])->save()`. Scopes the write to `CurrentUser` so cross-user writes are structurally impossible. No facade calls.
- `settings-page.blade.php` gains a Developer section (placed before Appearance, mirroring the existing `<section class="space-y-2">` shell). The `.switch` primitive (declared in Task 2 in `app.css`) is bound to `wire:click="setDevMode({{ $isDeveloper ? 'false' : 'true' }})"` with the proper `aria-pressed` + `aria-label` accessibility attributes. Copy is the locked plan string: "Show the Dev Console at /dev. Resets the Advanced toggle on every login."
- 4 Pest feature tests in `SettingsPageDevModeToggleTest`:
  1. Non-developer mounts page → toggle off.
  2. `setDevMode(true)` writes `is_developer=true` via Eloquent + property flips.
  3. Flip persists DB-side across logout/login (re-fetched User shows `is_developer=true`).
  4. After toggling on, `/dev` returns 200 instead of 404 (EnsureDeveloperMode accepts the user).

## CONTEXT D-23 supersession

CONTEXT D-23 prescribed `spatie/laravel-activitylog ^4.12`. Pinning to `^4.12` fails composer resolution: v4 requires Laravel 9/10/11 only; this project runs Laravel 13. PLAN.md's CONTEXT DECISION RECONCILIATION block documents the upgrade to `^5.0` (v5.0.0 released 2026-03-25; supports Laravel 12/13 + PHP 8.4+; the call-site API and the `config('activitylog.table_name')` override are unchanged between v4 and v5). Installed version is exactly v5.0.0 (no patches beyond .0 released at lock time).

Future-facing: future contributors reading the CONTEXT block should treat the version pin as superseded by this plan's reconciliation. The plan's `<objective>` block calls it out explicitly so the next planner / executor does not waste a cycle attempting `^4.12`.

## Null* contract concrete swap-pattern

Every Public contract is bound to a Null* default in `DevModeServiceProvider::register()`:

| Contract | Null* default | Replaced by | In plan |
|----------|---------------|-------------|---------|
| DevCommandRegistry | NullDevCommandRegistry (empty lists; `find()` throws) | DevCommandRegistryImpl (CONTEXT D-12 + D-13 allow-lists) | 16-04 |
| AuditWriter | NullAuditWriter (every method no-op) | SpatieAuditWriter (writes via spatie/laravel-activitylog to dev_mode_audit) | 16-04 |
| NavigationRegistry | NullNavigationRegistry (empty list) | NavigationRegistryImpl (enumerates Route::has authenticated views) | 16-08 |
| AppActionRegistry | NullAppActionRegistry (empty list) | AppActionRegistryImpl (Phase 15 app-menu entries) | 16-08 |

Later plans replace the binding from their OWN ServiceProvider's `register()` — they do NOT edit `DevModeServiceProvider`. The pattern lets consumer Livewire pages constructor- or method-inject the contracts from day one without `app()->bound(...)` null-checks. Future modules with similar "contract first, concrete later" shapes should mirror this swap pattern.

## Logging `tap: []` slot future fill

`config/logging.php` ships with `'tap' => []` on every driver-bearing channel:
- **16-04b** (Wave 4 audit + UI + sidebar enable, post B-5 split) installs the baseline `RedactSecretsProcessor::class` FQCN into the tap slots. This is the **W-1 fix** — closing the redaction-gap window in the SAME wave as the runner so logs are scrubbed before any new log-producing surface lands.
- **16-05** (Wave 5 log tailer + redaction) upgrades the same processor in place with the full OAuth scrub-set (per CONTEXT D-29 + the full RESEARCH § OAuth-Secret Audit table).

Wiring the slot in this plan means the downstream plans only fill the FQCN string — they never re-edit the file's structure. The W-1 timing is tight (this plan ships → 16-04b ships in Wave 4) and no new log-producing surface lands in the gap.

## Dev sidebar `nav-disabled` per-item enablement schedule

Of the 9 nav items, 8 ship with `nav-disabled` in this plan (only Overview is enabled). Each disabled link gates on `Route::has('dev.{slug}')`; the class drops automatically when the route is registered. Per CLAUDE.md output spec B-5 note:

| Nav item | Slug / route name | Enabled by |
|----------|-------------------|------------|
| Overview | dev.overview | This plan (16-03) |
| Artisan | dev.artisan | 16-04b (audit + UI + triple-gate + sidebar enable; B-5 split of original 16-04) |
| Audit | dev.audit | 16-04b |
| Logs | dev.logs | 16-05 |
| Queue | dev.queue | 16-06 |
| Doctor | dev.doctor | 16-07 |
| SQL | dev.sql | 16-07 |
| Horizon | dev.horizon | 16-06 (conditional on `config('app.dev_mode') === true` AND `class_exists(\Laravel\Horizon\HorizonServiceProvider::class)` per D-38) |
| System | dev.system | 16-07 |

The `nav-disabled` class is purely visual (opacity-0.5 + cursor-not-allowed + pointer-events: none + aria-disabled="true" + tabindex="-1"). No future edit to the dev-shell layout is needed when downstream plans register their routes — the `Route::has(...)` check resolves the change automatically.

## Verification

All quality gates green at plan close:

- `vendor/bin/pest --filter='EnsureDeveloperModeTest|DevOverviewPage|SettingsPageDevModeToggleTest|everyDevModeRouteAppliesEnsureDeveloperModeMiddleware'`: **14 passed** (43 assertions across 4 + 5 + 4 + 1 tests).
- `vendor/bin/pest` (full sequential): **2210 passed**, 19 todos, 6 skipped, 0 failed (24873 assertions). 14 new tests vs the Wave 2 baseline of 2196.
- `vendor/bin/phpstan analyse --memory-limit=2G`: **No errors** (Larastan L10 strict, 569 files analysed).
- `vendor/bin/pint --test`: **passed**.
- `composer show spatie/laravel-activitylog`: `versions : * 5.0.0`
- `composer show doctrine/sql-formatter`: `versions : * 1.5.4`
- `npm ls fuse.js`: `fuse.js@7.3.0`
- `php artisan route:list | grep dev`: `GET|HEAD dev dev.overview › Modules\DevMode\Internal\Http\Livewire\DevOverviewPage` — registered with the alias.
- `php artisan migrate:status | grep dev_mode_audit`: `2026_05_24_000001_create_dev_mode_audit_table  [2] Ran` — table created.

## Task Commits

| Task | Commit | Title |
|------|--------|-------|
| 1 (TDD) | `5dc457c` | feat(16-03): DevMode module skeleton + EnsureDeveloperMode 404 gate + Wave 0 deps |
| 2 (TDD) | `1c8c7c8` | feat(16-03): /dev route + dev-shell layout + DevOverviewPage + arch invariant |
| 3 (TDD) | `7929266` | feat(16-03): Settings Developer toggle wiring (DEVUI-01) |

Each task is a single commit (the RED + GREEN halves landed together because the plan's `<done>` criteria explicitly required the GREEN-phase tests to pass for the task to be considered complete; the RED phase was verified by running the tests before implementation and confirming the expected failures).

## Decisions Made

- **spatie/laravel-activitylog v5 over v4.12 (CONTEXT D-23 supersession).** v4 cannot resolve under Laravel 13. v5 ships the same call-site API + the same `config('activitylog.table_name')` override, so the supersession is internal — every consumer planned for 16-04 (SpatieAuditWriter) keeps working as documented.
- **Null* contract concrete pattern.** The four Public contracts ship with Null* defaults in this plan; later plans REPLACE the binding from their own ServiceProvider. Eliminates the `app()->bound(...)` null-check pattern at every call site and lets consumer Livewire pages inject the contracts via Pattern B (method-DI on render()) from day one.
- **dev-shell layout reads CurrentUser via `@inject` (not `auth()`).** The Modules/* Blade tree is in scope for the `noAuthFacadeOrHelper` arch invariant; the @inject directive routes through the container (constructor-DI equivalent). Wave 1's `resources/views/layouts/app.blade.php` lives OUTSIDE Modules/* so it can keep using `auth()` — only the dev-shell needed the new pattern.
- **UserDataPathService::logsFile() new accessor (Rule 2 deviation — see below).** The project's hard rule routes EVERY filesystem path through UserDataPathService so the NATIVEPHP bundle's NATIVEPHP_STORAGE_PATH retarget covers everything. Adding the accessor here means `config/logging.php` satisfies the `noStoragePathHardCodedOutsideUserDataPathService` invariant cleanly, and the 16-05 log tailer can read the same accessor.
- **config/database.php closure-scoped local.** `readonly_select = array_merge($sqlite, [...])` where `$sqlite` is a local variable inside a self-executing `static fn (): array` closure — sidesteps the bootstrap-time chicken-and-egg of calling `config()` from inside `config/database.php` itself.
- **Live route-table walk for the arch invariant.** The middleware-alias coverage check needs the RESOLVED middleware stack (`gatherMiddleware()`), so source-file grep is unsuitable — group-applied middleware lives in the route group declaration, not on each route line. Walking `Route::getRoutes()` is the only authoritative source.
- **Dev sidebar nav-disabled affordance via `Route::has(...)` per item.** Each nav entry queries the route registry at render time; the `nav-disabled` class drops automatically as downstream plans register their routes. No future edit to the dev-shell layout is needed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Bootstrap the test environment that the worktree lacked**
- **Found during:** Task 1 setup (pre-RED-phase suite check)
- **Issue:** The worktree had no `.env`, no `vendor/`, no `database/database.sqlite`, and no `public/build/manifest.json`. Same per-worktree environment hygiene issue that Wave 1 and Wave 2 surfaced.
- **Fix:** `cp .env.example .env && composer install && php artisan key:generate && touch database/database.sqlite && php artisan migrate --force && npm install && npm run build`. None of these artifacts is committed (`.env`, `vendor/`, `database/database.sqlite`, `public/build/`, `node_modules/` are all gitignored), but they are required for any test run in a fresh worktree.
- **Verification:** Pre-Task-1 baseline reached 2196 passed (the Wave 2 baseline) sequentially, matching Wave 2's SUMMARY.
- **Committed in:** N/A — environment-bootstrap actions, not tracked changes.

**2. [Rule 2 — Missing critical functionality] Add `UserDataPathService::logsFile()` so `config/logging.php` honours the noStoragePathHardCodedOutsideUserDataPathService invariant**
- **Found during:** Task 1 (post-publish suite check after copying the framework `config/logging.php`)
- **Issue:** The framework's default `config/logging.php` uses raw `storage_path('logs/laravel.log')` calls. The project's `noStoragePathHardCodedOutsideUserDataPathService` arch invariant scopes Modules + app + config and bans bare `storage_path()` / `database_path()` / `base_path()` calls (the NATIVEPHP bundle's retarget covers only the UserDataPathService surface). Without an accessor for the log file, the test immediately turned red.
- **Fix:** Added `UserDataPathService::logsFile()` static accessor (mirrors the existing `databaseFile()` shape — reads NATIVEPHP_STORAGE_PATH or falls back to project-rooted `storage/`). Updated `config/logging.php` to import `UserDataPathService` and use `UserDataPathService::logsFile()` on every channel that needs a log path. The accessor lives in the existing `Modules/Core/Public/Services/UserDataPathService.php` allow-listed file so the invariant passes.
- **Files modified:** `Modules/Core/Public/Services/UserDataPathService.php` (new method), `config/logging.php` (use statement + 3 replacements)
- **Verification:** `vendor/bin/pest --filter="noStoragePathHardCodedOutsideUserDataPathService"` → PASS.
- **Committed in:** `5dc457c` (Task 1 commit)

**3. [Rule 1 — Bug] Initial dev-shell layout used `auth()` / `app()` helpers, failing noAuthFacadeOrHelper**
- **Found during:** Task 2 (full Pest run after writing the layout)
- **Issue:** My first cut of `dev-shell.blade.php` mirrored `app.blade.php`'s theme-resolution block verbatim, including its `auth()->check()` / `auth()->user()` / `app()->bound(...)` / `app(...)` calls. `app.blade.php` lives in `resources/views/` which is OUTSIDE the `noAuthFacadeOrHelper` scan; the new dev-shell lives in `Modules/DevMode/Resources/views/` which is INSIDE the scan. The invariant immediately turned red on `Modules/DevMode/Resources/views/layouts/dev-shell.blade.php`.
- **Fix:** Rewrote the head block to use `@inject('currentUser', \Modules\Core\Public\Contracts\CurrentUser::class)` + `@inject('container', \Illuminate\Contracts\Container\Container::class)` and resolved everything through the injected contracts. The theme-resolution behaviour is identical; only the DI path changed.
- **Files modified:** `Modules/DevMode/Resources/views/layouts/dev-shell.blade.php`
- **Verification:** `vendor/bin/pest --filter="noAuthFacadeOrHelper"` → PASS.
- **Committed in:** `1c8c7c8` (Task 2 commit)

**4. [Rule 1 — Bug] DevOverviewPageTest used Pest's `toContain(needle, message)` form which is variadic-needles, not needle+message**
- **Found during:** Task 2 (initial test run after the layout fix above)
- **Issue:** My test wrote `expect($html)->toContain($label, "Dev sidebar missing nav item: {$label}")` — but Pest's `toContain(...$needles)` signature treats both arguments as separate needles. The test treated `"Dev sidebar missing nav item: Overview"` as a second literal expected substring (which doesn't appear in the rendered HTML) and reported a misleading failure.
- **Fix:** Switched to `expect(str_contains($html, $label))->toBeTrue("Dev sidebar missing nav item: {$label}")` — the message is the `toBeTrue()` failure message, which Pest does pass through.
- **Files modified:** `Modules/DevMode/tests/Feature/DevOverviewPageTest.php`
- **Verification:** All 5 DevOverviewPage tests pass.
- **Committed in:** `1c8c7c8` (Task 2 commit)

**5. [Rule 3 — Blocking] Seed a developer user in the EnsureDeveloperMode unauth test before issuing the request**
- **Found during:** Task 1 (first RED-phase run after writing the test)
- **Issue:** The plan's test expected an unauthenticated GET to `/dev/__probe` to return 404, but the actual response was 302. Root cause: the Phase 15 `EnsureDatabaseReady` middleware sits in the `web` group and redirects to `/welcome` when the `users` table is empty — and `RefreshDatabase` resets to an empty users table before each test. The redirect fired before `EnsureDeveloperMode` could run.
- **Fix:** Seeded a developer user inside the test body before the unauth request so the `EnsureDatabaseReady` first-launch gate becomes a pass-through, isolating the unauth-404 contract to its actual subject.
- **Files modified:** `Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php`
- **Verification:** All 4 EnsureDeveloperMode tests pass.
- **Committed in:** `5dc457c` (Task 1 commit)

**6. [Rule 1 — Bug] Pint fully-qualified-name fixup of the new arch invariant**
- **Found during:** Task 2 (Pint pre-commit check)
- **Issue:** My new `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` invariant used `\Illuminate\Routing\Route` FQN inline in a closure type-hint. Pint's `fully_qualified_strict_types` fixer (which the project's preset enables) automatically hoisted the FQN into a top-of-file `use Illuminate\Routing\Route;` and dropped the leading `\` everywhere. It also added a trailing newline.
- **Fix:** Ran `vendor/bin/pint tests/Contracts/BoundaryArchTest.php` to apply the fixer; verified the arch invariant still resolves the right symbol; re-ran the invariant to confirm green.
- **Files modified:** `tests/Contracts/BoundaryArchTest.php`
- **Verification:** Pint `--test` → passed; the invariant resolves `Illuminate\Routing\Route` correctly.
- **Committed in:** `1c8c7c8` (Task 2 commit)

---

**Total deviations:** 6 auto-fixed (3 Rule 1 — bug; 1 Rule 2 — missing critical; 2 Rule 3 — blocking).
**Impact on plan:** All 6 are necessary follow-throughs of the plan's intent. None changed scope. The two Rule 1 bugs (Pest needle-vs-message API + Pint FQN hoist) and the two Rule 3 blockers (env bootstrap + first-launch-gate test seeding) are mechanical fixes. The Rule 2 logsFile() accessor + the layout `@inject` rewrite are the project conventions doing their job — both are wired exactly as the noStoragePathHardCodedOutsideUserDataPathService / noAuthFacadeOrHelper invariants intend.

## Hand-off Notes

- The dev-shell layout's nav entries are gated on `Route::has('dev.{slug}')`. As each downstream plan adds its route, the matching nav item enables automatically. Downstream plans **MUST** name their routes per the table in this SUMMARY (`dev.artisan`, `dev.audit`, `dev.logs`, `dev.queue`, `dev.horizon`, `dev.doctor`, `dev.sql`, `dev.system`) or the sidebar will continue to render the entry as `nav-disabled`.
- The `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` arch invariant locks the middleware coverage for every future `/dev/*` route. Downstream plans **MUST** add their routes inside the existing `Route::middleware(['web', 'auth', 'ensureDeveloperMode'])->prefix('/dev')->group(...)` block in `Modules/DevMode/Routes/web.php`, or the invariant fails.
- The four Public contract bindings are Null* defaults. Downstream plans REPLACE the binding from their own `ServiceProvider::register()` — do NOT edit `DevModeServiceProvider`. Pattern:
  ```php
  // In some downstream plan's ServiceProvider::register():
  $this->app->singleton(\Modules\DevMode\Public\Contracts\AuditWriter::class, \Modules\DevMode\Internal\Audit\SpatieAuditWriter::class);
  ```
- The `config/logging.php` empty `tap: []` slots are placeholders for the 16-04b baseline `RedactSecretsProcessor` (W-1 fix) and the 16-05 full-OAuth-scrub-set upgrade. Downstream plans fill the FQCN only — they do not re-edit the file's structure.
- The `dev_mode_audit` table is created and empty. 16-04 binds `SpatieAuditWriter` (which writes through `activity()->log()`) and 16-04 / 16-04b populate the first rows.

## Known Stubs

- **DevOverviewPage placeholder card.** The "More Dev Console panels arriving in Wave 5" card is a placeholder. The 16-04 / 16-04b / 16-05 / 16-06 / 16-07 plans replace the card body with the real tile grid + theme-locked `.console-pane` per UI-SPEC § /dev overview surfaces. The dev-shell layout itself stays.
- **8/9 dev-sidebar nav items render with `nav-disabled`.** Each entry's target route lands in a downstream plan per the schedule table above. The `Route::has(...)` check drops the disabled affordance automatically; no future layout edit is needed.
- **Null* contract concretes return empty / no-op.** `DevCommandRegistry::safe()` and `destructive()` return `[]`; `find()` throws. `NavigationRegistry::all()` and `AppActionRegistry::all()` return `[]`. `AuditWriter` methods are no-ops. The 16-04 (audit + command list) and 16-08 (navigation + actions) plans replace each binding.

None of these stubs prevents this plan's goal (Wave-3 skeleton with the gate + the dev-shell + the contract surface + the Wave 0 deps all in place) from being achieved.

## Self-Check: PASSED

Files asserted present:

- `Modules/DevMode/composer.json` — FOUND
- `Modules/DevMode/Providers/DevModeServiceProvider.php` — FOUND
- `Modules/DevMode/Internal/Http/Middleware/EnsureDeveloperMode.php` — FOUND
- `Modules/DevMode/Internal/Http/Livewire/DevOverviewPage.php` — FOUND
- `Modules/DevMode/Internal/Audit/NullAuditWriter.php` — FOUND
- `Modules/DevMode/Internal/Registries/NullDevCommandRegistry.php` — FOUND
- `Modules/DevMode/Internal/Registries/NullNavigationRegistry.php` — FOUND
- `Modules/DevMode/Internal/Registries/NullAppActionRegistry.php` — FOUND
- `Modules/DevMode/Public/Contracts/DevCommandRegistry.php` — FOUND
- `Modules/DevMode/Public/Contracts/NavigationRegistry.php` — FOUND
- `Modules/DevMode/Public/Contracts/AppActionRegistry.php` — FOUND
- `Modules/DevMode/Public/Contracts/AuditWriter.php` — FOUND
- `Modules/DevMode/Public/Dto/CommandSpec.php` — FOUND
- `Modules/DevMode/Public/Dto/ArgSpec.php` — FOUND
- `Modules/DevMode/Public/Dto/NavigationEntry.php` — FOUND
- `Modules/DevMode/Public/Dto/AppAction.php` — FOUND
- `Modules/DevMode/Database/Migrations/2026_05_24_000001_create_dev_mode_audit_table.php` — FOUND
- `Modules/DevMode/Routes/web.php` — FOUND
- `Modules/DevMode/Resources/views/layouts/dev-shell.blade.php` — FOUND
- `Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php` — FOUND
- `Modules/DevMode/tests/Pest.php` — FOUND
- `Modules/DevMode/tests/TestCase.php` — FOUND
- `Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php` — FOUND
- `Modules/DevMode/tests/Feature/DevOverviewPageTest.php` — FOUND
- `Modules/Core/tests/Feature/SettingsPageDevModeToggleTest.php` — FOUND
- `config/logging.php` — FOUND
- `config/activitylog.php` — FOUND
- `bootstrap/providers.php` (modified — DevModeServiceProvider registered) — FOUND
- `composer.json` (modified — spatie/laravel-activitylog ^5.0 + doctrine/sql-formatter ^1.5 + autoload-dev Modules\\DevMode\\Tests\\) — FOUND
- `package.json` (modified — fuse.js ^7.0) — FOUND
- `config/database.php` (modified — readonly_select connection) — FOUND
- `Modules/Core/Public/Services/UserDataPathService.php` (modified — logsFile() accessor) — FOUND
- `Modules/Core/Internal/Http/Livewire/SettingsPage.php` (modified — isDeveloper + setDevMode) — FOUND
- `Modules/Core/Resources/views/livewire/settings-page.blade.php` (modified — Developer section + .switch) — FOUND
- `resources/css/app.css` (modified — --side-w-dev + dev-shell + .switch primitives) — FOUND
- `tests/Contracts/BoundaryArchTest.php` (modified — everyDevModeRouteAppliesEnsureDeveloperModeMiddleware) — FOUND
- `tests/Pest.php` (modified — Modules/DevMode binding) — FOUND
- `phpunit.xml` (modified — Modules/DevMode/tests testsuite entries) — FOUND

Commits asserted present:

- `5dc457c` (Task 1 — module skeleton + middleware + contracts + Wave 0 deps) — FOUND
- `1c8c7c8` (Task 2 — /dev route + dev-shell + DevOverviewPage + arch invariant) — FOUND
- `7929266` (Task 3 — Settings Developer toggle wiring) — FOUND

## Next Phase Readiness

- **16-04 (artisan-runner process pipeline):** Bind `DevCommandRegistryImpl` + `SpatieAuditWriter` from `Modules/DevMode/Providers/DevModeServiceProvider.php` (replace the Null* singletons). Both consumers can rely on the `dev_mode_audit` table being migrated + the `config('activitylog.table_name')` override being in place.
- **16-04b (audit + UI + triple-gate + sidebar enable):** Add `dev.artisan` + `dev.audit` routes inside the existing route group; install the baseline `RedactSecretsProcessor::class` FQCN into the `config/logging.php` tap slots (W-1 fix). The dev-sidebar Artisan + Audit nav items auto-enable via `Route::has(...)`.
- **16-05 (log tailer + redaction):** Add `dev.logs` route; upgrade the `RedactSecretsProcessor` in place with the full OAuth scrub-set. `UserDataPathService::logsFile()` is the canonical accessor for the log file path.
- **16-06 (queue inspector + Horizon iframe):** Add `dev.queue` + `dev.horizon` routes; the Horizon entry stays `nav-disabled` until the conditional gate (`config('app.dev_mode') === true` AND `class_exists(\Laravel\Horizon\HorizonServiceProvider::class)`) resolves true.
- **16-07 (doctor + SQL + system):** Add `dev.doctor` + `dev.sql` + `dev.system` routes; wire `Modules/DevMode/Internal/Sql/SelectOnlyValidator.php` around `Doctrine\SqlFormatter\Tokenizer` (the `@internal` wrap mitigation); open the `readonly_select` PDO with `PRAGMA query_only=1` per RESEARCH Pattern 5.
- **16-08 (command palette):** Bind `NavigationRegistryImpl` + `AppActionRegistryImpl` from a new ServiceProvider; import fuse.js client-side inside the palette modal's `<x-data>` block.

---
*Phase: 16-developer-mode-ui*
*Completed: 2026-05-24*
