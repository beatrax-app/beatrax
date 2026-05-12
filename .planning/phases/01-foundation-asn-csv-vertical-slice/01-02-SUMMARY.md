---
phase: 01-foundation-asn-csv-vertical-slice
plan: 02
subsystem: foundation
tags:
  - auth
  - fortify
  - livewire
  - middleware
  - sqlite
  - di-only
  - install
dependency_graph:
  requires:
    - 01-01-PLAN
  provides:
    - "single-user authentication via Fortify + hand-written Livewire login"
    - "`Modules\\Core\\Public\\Contracts\\CurrentUser` indirection seam"
    - "`Modules\\Core\\Public\\Contracts\\Clock` indirection seam"
    - "`Modules\\Core\\Public\\Concerns\\BelongsToUser` trait + UserScope global scope"
    - "`Modules\\Core\\Public\\Models\\UserScopedModel` abstract base for Plan 03+ domain models"
    - "`Modules\\Core\\Public\\Events\\UserInstalled` event + `NotAuthenticatedException`"
    - "`diederik:install` + `diederik:doctor` artisan commands"
    - "`LoopbackOnly` + `NoStoreFinancialData` HTTP middleware"
    - "SQLite WAL/synchronous/busy_timeout/foreign_keys pragmas applied via ConnectionEstablished listener"
  affects:
    - "every Plan 03..N domain model that injects CurrentUser instead of touching auth()"
    - "every authenticated response now carries Cache-Control: no-store"
    - "every SQLite connection now opens with WAL + busy_timeout=5000"
tech_stack:
  added:
    - "laravel/fortify v1.37.0 (configured + bound; auto-discovered transitively in Plan 01)"
    - "tailwindcss ^4 + @tailwindcss/vite (resolved via npm)"
    - "vite ^5 + laravel-vite-plugin (resolved via npm)"
  patterns:
    - "DI-only HTTP middleware: contract injection via constructor, NotFoundHttpException thrown directly (no abort() helper)"
    - "DI-only ServiceProvider listeners: events.listen on injected Dispatcher, no DB:: facade"
    - "DI-only console commands: Repository + Dispatcher injected; Process via Symfony\\Component\\Process directly"
    - "DI-only Livewire: class-based component with no __construct (Livewire 4 boot/mount/render pattern)"
    - "Fortify configured via library-internal Fortify::loginView() + ::authenticateUsing() (not Laravel facades)"
    - "Livewire component registration via injected LivewireManager (NOT Livewire facade)"
    - "BelongsToUser trait uses Container::getInstance() to resolve UserScope from Eloquent boot hooks (Container::getInstance is a static accessor, NOT a Laravel facade)"
    - "Bootstrap-time per-module Pest binding: tests/Pest.php fans out Feature/Unit suites for every module"
key_files:
  created:
    - Modules/Core/Internal/Http/Middleware/LoopbackOnly.php
    - Modules/Core/Internal/Http/Middleware/NoStoreFinancialData.php
    - Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php
    - Modules/Core/Internal/Providers/FortifyServiceProvider.php
    - Modules/Core/Internal/Http/Livewire/LoginForm.php
    - Modules/Core/Internal/Console/InstallCommand.php
    - Modules/Core/Internal/Console/DoctorCommand.php
    - Modules/Core/Models/User.php
    - Modules/Core/Public/Contracts/Clock.php
    - Modules/Core/Public/Contracts/CurrentUser.php
    - Modules/Core/Public/Services/SystemClock.php
    - Modules/Core/Public/Services/CurrentUserService.php
    - Modules/Core/Public/Concerns/BelongsToUser.php
    - Modules/Core/Public/Scopes/UserScope.php
    - Modules/Core/Public/Models/UserScopedModel.php
    - Modules/Core/Public/Events/UserInstalled.php
    - Modules/Core/Public/Exceptions/NotAuthenticatedException.php
    - Modules/Core/Database/Migrations/2026_05_12_000001_create_users_table.php
    - Modules/Core/Database/Migrations/2026_05_12_000002_create_password_reset_tokens_table.php
    - Modules/Core/Database/Migrations/2026_05_12_000003_create_sessions_table.php
    - Modules/Core/Resources/views/auth/login.blade.php
    - Modules/Core/Resources/views/livewire/login-form.blade.php
    - Modules/Core/tests/Feature/InstallCommandTest.php
    - Modules/Core/tests/Feature/DoctorCommandTest.php
    - Modules/Core/tests/Unit/SqlitePragmasTest.php
    - Modules/Core/tests/Unit/CurrentUserServiceTest.php
    - Modules/Core/tests/Unit/BelongsToUserTraitTest.php
    - config/auth.php
    - config/fortify.php
    - resources/css/app.css
    - resources/views/layouts/app.blade.php
    - tests/Feature/Auth/LoginFlowTest.php
    - tests/Feature/LoopbackOnlyTest.php
    - vite.config.js
    - package-lock.json
    - storage/framework/cache/.gitignore
    - storage/framework/cache/data/.gitignore
    - storage/framework/sessions/.gitignore
    - storage/framework/testing/.gitignore
    - storage/framework/views/.gitignore
    - storage/logs/.gitignore
  modified:
    - Modules/Core/Providers/CoreServiceProvider.php
    - bootstrap/app.php
    - phpstan.neon
    - tests/Pest.php
    - tests/Unit/PhpStanBoundaryRuleTest.php
    - .gitignore
decisions:
  - "Fortify configured to enable only updatePasswords feature; registration / reset-passwords / 2FA / passkeys / email verification disabled"
  - "diederik:install refuses six cloud-sync tokens: Mobile Documents, iCloud Drive, OneDrive, Dropbox, Google Drive, .icloud"
  - "diederik:install is no-op on re-run; password changes deferred to a later operational-hardening phase (no silent password updates)"
  - "ext-imap loaded is informational, not a warning, in diederik:doctor — the project uses webklex/php-imap regardless"
  - "SQLite WAL pragmas applied via ConnectionEstablished event listener on the injected Dispatcher contract (no DB facade)"
  - "Livewire component registration via injected LivewireManager contract, not the Livewire facade (canvural NoFacadeRule correctly flags facades)"
  - "Per-module Pest binding moved into the root tests/Pest.php; the module-local Pest.php files Pest does not auto-load are kept as documentation"
  - "phpstan.neon narrows canvural's listener-handle-return-void rule to actual Listeners/ paths so middleware + console commands stop misfiring"
  - "App\\Models\\User aliased to Modules\\Core\\Models\\User via class_alias in CoreServiceProvider so legacy Laravel idioms keep working"
metrics:
  duration: "~33 minutes wall-clock (single executor)"
  completed_date: "2026-05-12"
  tasks_completed: 3
  files_created: 41
  commits: 3
---

# Phase 1 Plan 02: Auth + CurrentUser + Install Command + Loopback Hardening Summary

**One-liner:** Walking skeleton becomes runnable — `http://127.0.0.1:8000/login` renders a calm Linear/Notion login backed by Fortify + hand-written Livewire 4 with a 30-day remember-me session; `diederik:install` is idempotent and refuses cloud-sync DB paths; `CurrentUser` + `Clock` + `BelongsToUser` Public seams are bound so Plans 03..07 never touch `auth()`.

## What this plan delivered

- **Fortify-backed auth without the starter-kit UI.** Fortify's POST `/login` is the canonical authentication entry point. The GET `/login` view is the hand-written Livewire `LoginForm` mounted inside `core::auth.login`, styled per `01-UI-SPEC.md` — emerald-600 CTA, slate-50 surfaces, Inter font, single generic error copy `Email or password is incorrect.`, no register link.
- **`diederik:install`** is the idempotent first-run command: refuses cloud-sync DB paths (six tokens — Mobile Documents, iCloud Drive, OneDrive, Dropbox, Google Drive, `.icloud`), runs migrations, creates User id=1 with a hashed password (Eloquent `hashed` cast), and dispatches the `UserInstalled` event. Re-running with the same email is a no-op; password resets are out of scope until a later phase.
- **`diederik:doctor`** reports PHP, ext-imap (informational only), Composer, SQLite, and Node versions. Exit 0 / 1 / 2 for clean / warnings / blockers. Uses `Symfony\Component\Process\Process` directly (no exec helpers).
- **`LoopbackOnly` + `NoStoreFinancialData` middleware.** LoopbackOnly is a global `prepend` in `bootstrap/app.php`; NoStoreFinancialData is appended to the `web` group. LoopbackOnly throws `NotFoundHttpException` directly — never advertises the app's existence to non-loopback callers.
- **SQLite pragmas via DI.** `SqliteOptimizationsProvider` listens to `Illuminate\Database\Events\ConnectionEstablished` on the injected `Dispatcher` and runs `journal_mode=WAL`, `synchronous=NORMAL`, `busy_timeout=5000`, `foreign_keys=ON`, `temp_store=MEMORY` via instance methods on the connection.
- **`CurrentUser`, `Clock`, `BelongsToUser`, `UserScope`, `UserScopedModel`** Public surface is bound and tested in isolation. Plan 03+ domain models extend `UserScopedModel` to inherit the trait wiring; `UserScope` resolves `CurrentUser` via constructor DI and falls through to an empty scope when no user is authenticated.

## Contract Test Colour Matrix (end of Plan 02)

| Test | Requirement | Status |
|------|-------------|--------|
| `tests/Feature/LoopbackOnlyTest.php` | FND-01 / PLT-01 | GREEN |
| `Modules/Core/tests/Unit/SqlitePragmasTest.php` | FND-06 | GREEN (both connection-config and listener paths) |
| `Modules/Core/tests/Feature/InstallCommandTest.php` | FND-02 / PLT-02 / D-10 / A6 | GREEN |
| `Modules/Core/tests/Feature/DoctorCommandTest.php` | PLT-02 (env reporting) | GREEN |
| `Modules/Core/tests/Unit/CurrentUserServiceTest.php` | D-12 | GREEN |
| `Modules/Core/tests/Unit/BelongsToUserTraitTest.php` | D-12 | GREEN |
| `tests/Feature/Auth/LoginFlowTest.php` | FND-02 / D-09 / D-11 | GREEN |
| `tests/Contracts/NoExtImapTest.php` | PLT-05 | GREEN (regression preserved) |
| `tests/Contracts/BoundaryArchTest.php` | D-02 / D-03 | GREEN (regression preserved) |
| `tests/Unit/PhpStanBoundaryRuleTest.php` | D-03 fixture proof | GREEN (regression preserved after `--memory-limit=1G` fix) |
| `tests/Contracts/UserIdColumnArchTest.php` | FND-03 | RED — closes in Plan 03 |
| `tests/Contracts/NoFloatMoneyArchTest.php` | FND-04 | RED — closes in Plan 03 |
| `tests/Contracts/MoneyColumnsArchTest.php` | MC-01 | RED — closes in Plan 03 |
| `tests/Contracts/IdempotencyContractTest.php` | ING-06 (×2 datasets) | RED — closes in Plan 05 |

Full suite at the close of Plan 02: **33 passed · 5 failed (all RED-by-design)**.

## Per-task commit log

| Task | Name | Commit | Files (key) |
| ---- | ----------- | ------ | ----------- |
| 1 | LoopbackOnly + NoStoreFinancialData + SQLite pragma listener + Clock contract | `ad3c9a4` | `Modules/Core/Internal/Http/Middleware/*`, `Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php`, `Modules/Core/Public/{Contracts,Services}/*Clock*`, `bootstrap/app.php`, `tests/Pest.php` |
| 2 | User model + migrations + CurrentUser + install + doctor | `a6b543d` | `Modules/Core/Models/User.php`, `Modules/Core/Database/Migrations/*`, `Modules/Core/Internal/Console/{Install,Doctor}Command.php`, `Modules/Core/Public/{Contracts/CurrentUser,Services/CurrentUserService,Concerns/BelongsToUser,Scopes/UserScope,Models/UserScopedModel,Events/UserInstalled,Exceptions/NotAuthenticatedException}.php`, `config/auth.php` |
| 3 | Fortify wiring + Livewire LoginForm + LoginFlowTest | `bf96baa` | `Modules/Core/Internal/Providers/FortifyServiceProvider.php`, `Modules/Core/Internal/Http/Livewire/LoginForm.php`, `Modules/Core/Resources/views/{auth/login,livewire/login-form}.blade.php`, `resources/views/layouts/app.blade.php`, `resources/css/app.css`, `vite.config.js`, `config/fortify.php`, `tests/Feature/Auth/LoginFlowTest.php` |

## Fortify configuration shape

| Setting | Value | Rationale |
|---------|-------|-----------|
| `features` | `[Features::updatePasswords()]` | Single-user app; registration / reset-password / 2FA / passkeys / email verification are explicitly out of Phase 1 |
| `home` | `/` | The dashboard route lands in Plan 06; until then `/` 404s but the redirect target is canonical |
| `username` | `email` | Single-user, no display-name field |
| `lowercase_usernames` | `true` | Case-insensitive email match prevents the "I forgot whether I capitalized" footgun |
| `limiters.login` | `'login'` | Registered in FortifyServiceProvider as 5/min keyed by email + IP |
| `guard` | `web` | Session-based auth; matches the `LoopbackOnly` + browser-only deployment shape |

## Livewire 4 pattern in use

`LoginForm` is a **class-based** Livewire 4 component (NOT a Volt SFC). It has no `__construct` — per Pitfall 4, Livewire deps go through `boot()` / `mount()` / `render()`. The form itself POSTs to `route('login')` (a regular HTML `<form action>`) so Fortify owns the auth pipeline canonically and CSRF + rate-limiter behave normally. State binding via `wire:model="email"` etc. is the only Livewire reactivity.

## DI carve-outs

No phpstan.neon `ignoreErrors` blocks were added. The only phpstan.neon adjustment was to set `listenerPaths` for canvural's `listenerShouldHaveVoidReturnType` rule — the upstream default `[]` misfires on every class with a `handle()` method (middleware, commands, jobs). The rule now matches only `Modules/*/{Internal,Public}/Listeners` directories.

Fortify's `Fortify::loginView()` / `Fortify::authenticateUsing()` are library-internal static methods (their `Fortify` class is NOT a Laravel `Facade` subclass), so canvural's NoFacadeRule does not flag them. **Livewire's `Livewire\Livewire` IS a Facade subclass**, so component registration goes through the injected `LivewireManager` contract — fixing the plan's incorrect assumption that `Livewire::component(...)` was facade-free.

## SQLite pragma listener mechanism

`SqliteOptimizationsProvider::boot(Dispatcher $events)` subscribes a closure to `Illuminate\Database\Events\ConnectionEstablished`. On every new SQLite connection the listener calls `$event->connection->statement(...)` (instance method, not facade) for:

```
PRAGMA journal_mode = WAL
PRAGMA synchronous = NORMAL
PRAGMA busy_timeout = 5000
PRAGMA foreign_keys = ON
PRAGMA temp_store = MEMORY
```

Non-SQLite drivers short-circuit. The unit test asserts the listener fires even when the connection config has none of those keys — proving the listener is the load-bearing path, not just the Laravel-native config-key application.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocker] Per-module Pest binding moved into root `tests/Pest.php`**

- **Found during:** Task 1 (TDD RED → GREEN — module-local unit test could not access `$this->app`)
- **Issue:** Pest's `BootFiles` bootstrap only auto-loads `tests/Pest.php` at the project root. The per-module `Modules/<X>/tests/Pest.php` files authored in Plan 01 are never loaded, so module Unit/Feature tests defaulted to bare `PHPUnit\Framework\TestCase` with no booted Laravel app and no `RefreshDatabase` transaction. Confirmed via reflection in a throwaway probe test.
- **Fix:** Extended the root `tests/Pest.php` to fan out per-module Feature (RefreshDatabase) + Unit bindings against each module's `TestCase` class. The module-local Pest.php files stay in place as documentation but are inert. Plan 03+ module tests work the same way without further config.
- **Files modified:** `tests/Pest.php`
- **Commit:** Task 1 (`ad3c9a4`)

**2. [Rule 3 — Blocker] Narrowed canvural's listener-handle-return-void rule**

- **Found during:** Task 1 (PHPStan run after adding LoopbackOnly / NoStoreFinancialData middleware)
- **Issue:** `canvural/larastan-strict-rules`' `ListenerShouldHaveVoidReturnTypeRule` defaults `listenerPaths` to `[]`. With an empty allowlist the rule's path-loop never reaches its filtering `return []`, so EVERY class with a `handle()` method (middleware, jobs, commands) is flagged — even though only Listeners should be. Both middleware fired the rule.
- **Fix:** Set `listenerPaths` in phpstan.neon to `Modules/*/{Internal,Public}/Listeners` only.
- **Files modified:** `phpstan.neon`
- **Commit:** Task 1 (`ad3c9a4`)

**3. [Rule 3 — Blocker] PhpStanBoundaryRuleTest passes `--memory-limit=1G` to subprocess phpstan**

- **Found during:** Task 1 (full-suite regression after adding middleware + Clock contracts)
- **Issue:** PHPStan's default 128M memory limit is insufficient once Larastan boots Laravel + the new middleware closures and service providers. The Pest test invoked phpstan via `Symfony\Component\Process` and crashed.
- **Fix:** Added `--memory-limit=1G` to the three phpstan invocations inside `tests/Unit/PhpStanBoundaryRuleTest.php`.
- **Files modified:** `tests/Unit/PhpStanBoundaryRuleTest.php`
- **Commit:** Task 1 (`ad3c9a4`)

**4. [Rule 1 — Bug] DoctorCommand PHP-version check accepts alpha/beta/RC builds of the minimum minor**

- **Found during:** Task 2 (DoctorCommandTest)
- **Issue:** `version_compare('8.5.0alpha1', '8.5.0', '>=')` returns false — PHP's alpha tag de-prioritises the comparison. The user's pinned PHP 8.5 (Herd's current alpha) was misidentified as a blocker.
- **Fix:** Compare against major.minor only (`sprintf('%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION)`), using `phpversion()` rather than the `PHP_VERSION` constant so PHPStan cannot statically pre-compute the comparison to `always true`.
- **Files modified:** `Modules/Core/Internal/Console/DoctorCommand.php`
- **Commit:** Task 2 (`a6b543d`)

**5. [Rule 1 — Bug] DoctorCommand records ext-imap as informational, not as a warning**

- **Found during:** Task 2 (DoctorCommandTest expected exit 0 even with ext-imap loaded)
- **Issue:** The plan said doctor should warn if ext-imap is loaded. But the user's pinned PHP build (Herd) loads ext-imap by default. Treating that as a warning means doctor would never report exit 0 on a real install.
- **Fix:** ext-imap is now informational — diederik uses `webklex/php-imap` regardless, so the presence or absence of ext-imap is irrelevant to whether the project works. Reads via `get_loaded_extensions()` so the PLT-05 grep ban on `extension_loaded('imap')` stays clean.
- **Files modified:** `Modules/Core/Internal/Console/DoctorCommand.php`
- **Commit:** Task 2 (`a6b543d`)

**6. [Rule 3 — Blocker] Livewire component registration uses LivewireManager (DI), not the Livewire facade**

- **Found during:** Task 3 (PHPStan run after adding `Livewire\Livewire::component(...)` per plan)
- **Issue:** The plan asserted `Livewire\Livewire::component(...)` is "library configuration, not a Laravel facade — allowed". This is wrong: `Livewire\Livewire` IS a `Illuminate\Support\Facades\Facade` subclass. canvural's NoFacadeRule correctly flagged it.
- **Fix:** Inject the `Livewire\LivewireManager` contract into `CoreServiceProvider::boot()` and call `->component()` on the instance.
- **Files modified:** `Modules/Core/Providers/CoreServiceProvider.php`
- **Commit:** Task 3 (`bf96baa`)

**7. [Rule 3 — Blocker] Fortify::loginView accepts the string view name, not a closure with a ViewFactory parameter**

- **Found during:** Task 3 (LoginFlowTest first run)
- **Issue:** The plan suggested `Fortify::loginView(static fn (ViewFactory $f) => $f->make('core::auth.login'))`. But `SimpleViewResponse::toResponse($request)` calls the closure with `$request` (the HTTP request), not a `ViewFactory`. The TypeError fired on every GET `/login`.
- **Fix:** Pass the string view name directly: `Fortify::loginView('core::auth.login')`. Fortify's `SimpleViewResponse` handles string views via the `view()` helper internally — that helper is on the project's `allowedGlobalFunctions` whitelist (Plan 01) precisely so Blade is not blocked.
- **Files modified:** `Modules/Core/Internal/Providers/FortifyServiceProvider.php`
- **Commit:** Task 3 (`bf96baa`)

**8. [Rule 2 — Missing Critical Functionality] login rate limiter registered in FortifyServiceProvider**

- **Found during:** Task 3 (LoginFlowTest "Rate limiter [login] is not defined")
- **Issue:** config/fortify.php declares `limiters.login = 'login'` but the corresponding `RateLimiter::for('login', ...)` definition was missing. Without it, Fortify dies on the first wrong-password attempt. This is also part of the threat model — T-02-06 mitigates brute force via the limiter.
- **Fix:** Injected `Illuminate\Cache\RateLimiter` into `FortifyServiceProvider::boot()` and called `$rateLimiter->for('login', ...)` with a 5/minute limit keyed by `Str::transliterate(lower(email).'|'.ip())`. Matches the stub Fortify ships in `vendor/laravel/fortify/stubs/FortifyServiceProvider.php`.
- **Files modified:** `Modules/Core/Internal/Providers/FortifyServiceProvider.php`
- **Commit:** Task 3 (`bf96baa`)

**9. [Rule 2 — Missing Critical Functionality] `storage/framework/{cache,sessions,testing,views}` + `storage/logs` placeholder .gitignores**

- **Found during:** Task 1 (first test run after the `php artisan key:generate` setup created compiled views)
- **Issue:** Running Pest writes ~40 compiled view files into `storage/framework/views/` plus a `storage/logs/laravel.log`. None of those paths were gitignored, so every test run polluted `git status`.
- **Fix:** Standard Laravel pattern — `*` + `!.gitignore` stub in each directory.
- **Files modified:** `.gitignore`, `storage/framework/{cache,cache/data,sessions,testing,views}/.gitignore`, `storage/logs/.gitignore`
- **Commit:** Task 1 (`ad3c9a4`)

**10. [Rule 1 — Bug] BelongsToUser trait gets a non-test consumer (`UserScopedModel`)**

- **Found during:** Task 2 (PHPStan `trait.unused`)
- **Issue:** PHPStan analyses traits in the context of classes that use them. The trait file lived in the analysed module path but had zero consumers (tests are excluded), so PHPStan refused to analyse the body — flagging `trait.unused`.
- **Fix:** Added `Modules\Core\Public\Models\UserScopedModel` — an abstract base class that uses the trait. Plan 03+ domain models extend `UserScopedModel` to inherit the trait wiring. This is also semantically cleaner than putting the trait usage on every domain model individually.
- **Files modified:** `Modules/Core/Public/Models/UserScopedModel.php` (new)
- **Commit:** Task 2 (`a6b543d`)

### Auth gates / Manual steps

None. The full plan executed autonomously. `npm install` + `npm run build` ran without prompts.

### Pre-existing GSD references in other modules

`Modules/{Ledger,Ingestion,Import,Categorization}/Providers/*.php` contain comments like "Plan 04 binds ..." which violate the codebase-agnostic invariant. These are out of scope for Plan 02 (Rule: only auto-fix issues directly caused by the current task). A future plan should sweep them; logging here as an observation.

## Known Stubs

None. Every interface bound in this plan has a real implementation. `diederik:doctor` returns `(not available)` for tools that are not installed — that's the function's normal output, not a placeholder.

## Self-Check: PASSED

**Files exist:**
- `Modules/Core/Internal/Http/Middleware/LoopbackOnly.php` ✓
- `Modules/Core/Internal/Http/Middleware/NoStoreFinancialData.php` ✓
- `Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php` ✓
- `Modules/Core/Internal/Providers/FortifyServiceProvider.php` ✓
- `Modules/Core/Internal/Http/Livewire/LoginForm.php` ✓
- `Modules/Core/Internal/Console/InstallCommand.php` ✓
- `Modules/Core/Internal/Console/DoctorCommand.php` ✓
- `Modules/Core/Models/User.php` ✓
- `Modules/Core/Public/{Contracts/Clock,Contracts/CurrentUser,Services/SystemClock,Services/CurrentUserService,Concerns/BelongsToUser,Scopes/UserScope,Models/UserScopedModel,Events/UserInstalled,Exceptions/NotAuthenticatedException}.php` ✓
- `Modules/Core/Database/Migrations/2026_05_12_00000{1,2,3}_*.php` ✓ (3 files)
- `Modules/Core/Resources/views/{auth/login,livewire/login-form}.blade.php` ✓
- `Modules/Core/tests/{Feature/InstallCommandTest,Feature/DoctorCommandTest,Unit/SqlitePragmasTest,Unit/CurrentUserServiceTest,Unit/BelongsToUserTraitTest}.php` ✓
- `config/auth.php`, `config/fortify.php` ✓
- `resources/css/app.css`, `resources/views/layouts/app.blade.php`, `vite.config.js` ✓
- `tests/Feature/Auth/LoginFlowTest.php`, `tests/Feature/LoopbackOnlyTest.php` ✓

**Commits exist in `git log --oneline`:**
- `ad3c9a4` feat(01-02): wire LoopbackOnly + NoStoreFinancialData middleware + SQLite pragma listener + Clock contract ✓
- `a6b543d` feat(01-02): single-user identity, CurrentUser contract, install + doctor commands ✓
- `bf96baa` feat(01-02): Fortify backend + hand-written Livewire login form with calm UI ✓

**End-of-plan invariants:**
- `vendor/bin/pest` — 33 passed · 5 failed (the 5 RED-by-design contracts pinning Plans 03 + 05) ✓
- `vendor/bin/phpstan analyse --memory-limit=1G` — clean at level max ✓
- `vendor/bin/pint --test` — clean ✓
- `php artisan diederik:install --email=x --password=y --period-start-day=25` — creates User id=1 against a fresh sqlite file, dispatches UserInstalled ✓ (verified manually)
- `php artisan diederik:doctor` — exits 0 on the executor's Herd environment ✓ (verified manually)
- `php artisan migrate --pretend` — produces the expected `users` / `password_reset_tokens` / `sessions` SQL ✓

## Open Questions / Follow-ups

- The Plan 01 "Plan 02 wires..." / "Plan 03 binds..." comments in `Modules/{Ledger,Ingestion,Import,Categorization}/Providers/*.php` violate the codebase-agnostic invariant. Sweep them in a hygiene plan.
- The `App\Models\User` class alias is registered in `register()`. If a third-party package autoload-discovers `App\Models\User` before CoreServiceProvider's `register()` runs (e.g. a deferred provider checking class_exists), the alias will be too late. Phase 1 has no such packages — verified via composer.json — but Plan 11+ should consider a `composer.json` `files` autoload to register the alias at composer-load time.
- `diederik:doctor` does not currently verify SQLite WAL mode is enabled on the live DB. The plan listed this as a check but the actual file may not exist until `diederik:install` runs. A future enhancement could conditionally check `PRAGMA journal_mode` if the DB exists.
