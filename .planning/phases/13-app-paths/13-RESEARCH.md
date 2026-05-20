# Phase 13: AppPaths - Research

**Researched:** 2026-05-20
**Domain:** Laravel path abstraction / DI refactor / NativePHP-readiness / arch-test enforcement
**Confidence:** HIGH (codebase facts verified by grep + file read; Laravel internals CITED; NativePHP behavior MEDIUM — official docs confirm `storagePath()` rewrite but do NOT document `NATIVEPHP_STORAGE_PATH` as a real env var)

## Summary

Phase 13 is a contained refactor: introduce one `UserDataPathService` in `Modules/Core/Public/Services/`, route every production-code filesystem path through it, and lock the invariant with an arch test plus a CI grep gate. The phase ships no new behavior — it only changes *where* paths come from so that a future NativePHP build (Phase 15) resolves them under the OS appdata directory instead of the project root.

The single hard problem is the **config-file boot-ordering split**. `config/database.php`, `config/session.php`, and `config/modules.php` are plain PHP arrays evaluated by Laravel during `bootstrap/app.php` → `LoadConfiguration` — *before* any service provider's `register()` runs and therefore before the container can resolve `UserDataPathService`. Constructor DI is structurally impossible there. The recommended resolution (detailed below) is a **`public static` resolver on `UserDataPathService`** that config files call directly, with the instance methods delegating to the same static core so there is exactly one copy of the `NATIVEPHP_STORAGE_PATH` branching logic. The grep gate then *allows* `UserDataPathService::` references inside `config/` while still forbidding the raw `database_path()` / `storage_path()` / `base_path()` helpers and the bare string literals.

A critical correctness note for the planner: **NativePHP already rewrites `Application::storagePath()` and the `storage_path()` helper itself** when running inside Electron — confirmed by official NativePHP v2 docs. `NATIVEPHP_STORAGE_PATH` is **not** a documented NativePHP env var; it is a *project convention* this phase invents as its detection signal (CONTEXT.md D-01 treats it as authoritative, which is a valid project decision, but the planner must not describe it as "NativePHP's env var"). This has a concrete implication: in a real shipped build Phase 15 must arrange for `NATIVEPHP_STORAGE_PATH` to actually be set (e.g. in the bundled `.env`, or NativePHP's appdata path injected at boot), otherwise `UserDataPathService` silently falls back to project-rooted paths. Phase 13 only needs the *simulated* env to pass — but the planner should record this Phase 15 follow-up explicitly.

**Primary recommendation:** Build `UserDataPathService` with (1) a private static `storageRoot()` that reads `NATIVEPHP_STORAGE_PATH` once and falls back to a project-root constant, (2) `public static` accessors (`databaseFile()`, `storageBase()`, etc.) callable from config files, and (3) instance methods that delegate to the statics for DI consumers. Extend `BoundaryArchTest` with one new grep-style `it(...)` test (the existing `noLaravelGlobalHelpersInCoreConsoleCommands` test is the exact template). Express the CI grep gate as a `composer` script *now* (`composer check:paths`) so Phase 17's CI workflow can absorb it with a single line.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Path resolution (database / storage / backups / secrets) | Backend service (`Modules/Core/Public/Services/UserDataPathService`) | — | One injectable source of truth; named accessors per CONTEXT D-03 |
| Config-time path defaults | Backend (static resolver) | Config layer (`config/*.php`) | Config arrays evaluate pre-container; only a static method can serve them |
| Runtime-environment detection | Backend service | OS/Electron env (`NATIVEPHP_STORAGE_PATH`) | `getenv()`/`$_ENV` read; D-01 makes presence the authoritative signal |
| Invariant enforcement | Test tier (`BoundaryArchTest`) + CI tier (grep gate) | — | Arch test catches PHP call sites; grep gate also catches string literals |

## Standard Stack

### Core

This phase introduces **no new packages**. It is a pure refactor over the existing stack.

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | `^13.0` (installed) | `Application` container, path helpers being abstracted | Already the project framework |
| `pestphp/pest-plugin-arch` | `^4.0` (installed) | Arch-test plugin hosting `BoundaryArchTest` | Phase 12 used it for `noAuthFacadeOrHelper` |
| `nwidart/laravel-modules` | `^13.0` (installed) | Module structure; `config/modules.php` is one of the 3 config files in scope | Already the project module system |

### Supporting

None. `UserDataPathService` is a hand-written ~80-line class with no dependencies beyond PHP's `getenv()` / string functions.

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `UserDataPathService` with static + instance methods | `$app->useStoragePath(...)` in a service provider | `useStoragePath()` retargets Laravel's *entire* storage root globally [CITED: github.com/laravel/framework#20686]. It does NOT help `config/database.php`'s `database_path()` call (that runs before any provider) and does NOT give the named-accessor DI surface D-03 requires. Could be a *complement* in Phase 15, not a replacement. |
| Static resolver method (recommended) | `config/` files reading `getenv('NATIVEPHP_STORAGE_PATH')` directly | Duplicates the fallback logic in 3 files — exactly the "two coexisting patterns" D-04 forbids. Rejected. |
| Static resolver method | A bootstrap-time path override in `bootstrap/app.php` | `bootstrap/app.php` runs before config load; could `putenv` but cannot retarget `database_path()` without also calling `useBasePath`/`useStoragePath`. More moving parts than a static method. Keep as Phase 15 option only. |

**Installation:** None — no `composer require`.

## Package Legitimacy Audit

Not applicable — Phase 13 installs no external packages. (Verified: the deliverable is one new first-party class plus test/CI changes.)

## Architecture Patterns

### System Architecture Diagram

```
                          NATIVEPHP_STORAGE_PATH env var
                          (set = shipped build; absent = Herd dev)
                                      │
                                      ▼
        ┌─────────────────────────────────────────────────────┐
        │  UserDataPathService                                │
        │  ┌───────────────────────────────────────────────┐  │
        │  │ private static storageRoot(): string          │  │
        │  │   getenv('NATIVEPHP_STORAGE_PATH') ?: <proj>   │  │ ◄── single copy of
        │  │                                                │  │     the branching logic
        │  │ public static databaseFile(): string           │  │
        │  │ public static storageBase(): string            │  │
        │  │ public static backupsPath(): string            │  │
        │  │ public static secretsPath(): string            │  │
        │  │ public static frameworkPath(string $sub)        │  │
        │  │ public static modulesPath() / migrationsPath()  │  │
        │  └───────────────────────────────────────────────┘  │
        │  instance methods databasePath() etc.  ──delegate──┐ │
        └────────────────────────────────────────────────────┼┘
              ▲                          ▲                   │
              │ (constructor DI)         │ (STATIC call)      │
              │                          │                   │
   ┌──────────┴──────────┐    ┌──────────┴───────────┐        │
   │ ~8 production        │    │ config/database.php  │        │
   │ consumers:           │    │ config/session.php   │        │
   │  CoreServiceProvider │    │ config/modules.php   │        │
   │  Backup/Restore cmds │    │ (evaluated PRE-      │        │
   │  EmlBlobStore        │    │  container by        │        │
   │  EmitOAuthReauth…    │    │  LoadConfiguration)  │        │
   │  Auth migration ×3   │    └──────────────────────┘        │
   └─────────────────────┘                                    │
              │                                               │
              ▼                                               ▼
   ┌──────────────────────────────────────────────────────────┐
   │ ENFORCEMENT                                                │
   │  BoundaryArchTest::noStoragePathHardCoded…  (PHP grep)     │
   │  composer check:paths  (shell grep, CI-absorbable)         │
   │  Pest feature test: NATIVEPHP_STORAGE_PATH=<tmp> →         │
   │    migrate:fresh / db:backup land under <tmp>              │
   └──────────────────────────────────────────────────────────┘
```

### Recommended Project Structure

```
Modules/Core/Public/Services/
└── UserDataPathService.php      # NEW — the one place raw helpers are allowed
config/
├── database.php                 # MODIFIED — database_path() → UserDataPathService::databaseFile()
├── session.php                  # MODIFIED — storage_path('framework/sessions') → static accessor
└── modules.php                  # MODIFIED — base_path('Modules') etc. → static accessors
tests/Contracts/
└── BoundaryArchTest.php          # MODIFIED — + noStoragePathHardCodedOutsideUserDataPathService
tests/Feature/                   # or Modules/Core/tests/Feature/
└── UserDataPathResolutionTest.php  # NEW — simulated-NativePHP-env Pest test
composer.json                    # MODIFIED — + "check:paths" script
bin/check-paths.sh   (optional)  # NEW — standalone grep gate (composer script can inline it)
```

### Pattern 1: Static-core + instance-delegate service

**What:** `UserDataPathService` exposes both `public static` accessors (for config files) and instance methods (for DI consumers). The static methods hold the only copy of the resolution logic; instance methods are one-line delegators.

**When to use:** Whenever a value must be available both pre-container (config) and post-container (DI) without duplicating logic. This is the resolution to the D-02 open question.

**Example:**
```php
<?php
// Source: project pattern (mirrors Modules/Core/Public/Services/SystemClock.php shape).
// CITED for boot ordering: Laravel evaluates config arrays in
// Illuminate\Foundation\Bootstrap\LoadConfiguration, the FIRST bootstrapper,
// before RegisterProviders — see laravel/framework Application::$bootstrappers.

declare(strict_types=1);

namespace Modules\Core\Public\Services;

final class UserDataPathService
{
    /**
     * Project root used as the Herd-dev fallback. base_path() is the ONE
     * sanctioned raw helper call in the whole codebase — this class is the
     * arch-test allow-list of size one.
     */
    private static function projectRoot(): string
    {
        return base_path();
    }

    /**
     * Storage root. When NATIVEPHP_STORAGE_PATH is set it IS the root
     * (shipped build, D-01). Absent → project-rooted (Herd dev).
     */
    private static function storageRoot(): string
    {
        $native = getenv('NATIVEPHP_STORAGE_PATH');

        return is_string($native) && $native !== ''
            ? rtrim($native, '/\\')
            : self::projectRoot().DIRECTORY_SEPARATOR.'storage';
    }

    public static function databaseFile(): string
    {
        // Shipped build: <root>/database/database.sqlite
        // Herd dev:      <project>/database/database.sqlite (matches v1 today)
        $native = getenv('NATIVEPHP_STORAGE_PATH');
        $dbDir = is_string($native) && $native !== ''
            ? rtrim($native, '/\\').DIRECTORY_SEPARATOR.'database'
            : self::projectRoot().DIRECTORY_SEPARATOR.'database';

        return $dbDir.DIRECTORY_SEPARATOR.'database.sqlite';
    }

    public static function storageBase(): string
    {
        return self::storageRoot();
    }

    public static function appPath(string $relative = ''): string
    {
        $base = self::storageRoot().DIRECTORY_SEPARATOR.'app';

        return $relative === '' ? $base : $base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }

    public static function backupsPath(): string   { return self::appPath('backups'); }
    public static function secretsPath(): string    { return self::appPath('secrets'); }
    public static function frameworkPath(string $sub = ''): string
    {
        $base = self::storageRoot().DIRECTORY_SEPARATOR.'framework';

        return $sub === '' ? $base : $base.DIRECTORY_SEPARATOR.ltrim($sub, '/\\');
    }

    // --- Instance surface for DI consumers (D-03) -----------------------
    public function databasePath(): string { return self::databaseFile(); }
    public function storagePath(): string  { return self::storageBase(); }
    public function backups(): string      { return self::backupsPath(); }
    public function secrets(): string      { return self::secretsPath(); }
    public function inboxPath(string $rel): string { return self::appPath('inbox/'.$rel); }
}
```

> **Decision point for the planner — exact accessor surface is Claude's discretion (CONTEXT D-02/discretion).** The example above mixes granular named accessors (`backups()`, `secrets()`) with one generic relative resolver (`appPath()`). Recommend keeping the generic `appPath()` because `EmlBlobStore` builds *deep* dynamic paths (`app/inbox/{user}/{inbox}/{Y}/{M}/{slug}.eml`) that no fixed named accessor can express — a granular-only surface would force `EmlBlobStore` to re-concatenate `storage_path`-equivalent strings and re-introduce the literal `storage/app/` the grep gate forbids.

### Pattern 2: Config file calling the static resolver

**What:** Config arrays call `UserDataPathService::someMethod()` instead of `database_path()` / `storage_path()` / `base_path()`.

**When to use:** The three config files in scope.

**Example:**
```php
// config/database.php  (Source: existing file, line 33, modified)
use Modules\Core\Public\Services\UserDataPathService;

'database' => env('DB_DATABASE', UserDataPathService::databaseFile()),
```
```php
// config/session.php  (existing line 46, modified)
'files' => UserDataPathService::frameworkPath('sessions'),
```
```php
// config/modules.php  (existing lines 35/37/77/129, modified)
'modules'   => UserDataPathService::modulesPath(),       // <root>/Modules
'migration' => UserDataPathService::migrationsPath(),    // <root>/database/migrations
// scan paths + statuses-file likewise
```

**Gotcha — static call works because the class is autoloaded, not container-resolved.** A `public static` method needs only Composer's PSR-4 autoloader, which is registered in `bootstrap/app.php` *before* `LoadConfiguration` runs. No container, no service provider, no boot order problem. `Modules\Core\` is already PSR-4 mapped in `composer.json` (verified). This is the load-bearing reason a static resolver works where DI cannot.

**Gotcha — `config:cache`.** When config is cached, the *resolved string* is frozen into `bootstrap/cache/config.php` at cache time. If `NATIVEPHP_STORAGE_PATH` differs between cache time and run time the cached value is wrong. Laravel already warns: never call `env()` outside config files. The static resolver calls `getenv()` *inside* a config file, so it behaves exactly like `env()` — fine as long as the bundled build either ships uncached config or runs `config:clear`/`config:cache` *after* the env is set. Record this as a Phase 15 follow-up; for Phase 13's tests, ensure the feature test does NOT run under cached config.

### Pattern 3: Migration files use container resolution, not the helper

**What:** The Auth migration `2026_05_20_000002_rename_legacy_email_oauth_json.php` calls `storage_path()` ×3. Migrations run via `artisan migrate` — the container *is* fully booted, so the migration could `Container::getInstance()->make(UserDataPathService::class)`. It already does exactly this for `Filesystem` (line 56-60).

**When to use:** Migration files (anonymous classes — no constructor DI).

**Recommended approach:** Add a private `paths()` helper mirroring the existing `files()` helper:
```php
// Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php
private function paths(): UserDataPathService
{
    /** @var UserDataPathService $svc */
    $svc = Container::getInstance()->make(UserDataPathService::class);

    return $svc;
}
// up(): $legacy = $this->paths()->secrets().'/email-oauth.json';
```
This keeps the migration consistent with its own existing `files()` pattern and avoids the raw helper.

> **Scope decision the planner must make (CONTEXT D-02 open question).** `phpstan.neon` already *excludes* `Modules/*/Database/Migrations/*` and `database/migrations/*` from Larastan. The `noAuthFacadeOrHelper` arch test *also* explicitly skips `/Database/Migrations/`. **Recommendation:** the new path arch test should likewise **skip migration directories** for the *grep-for-helpers* rule (consistent precedent), BUT the planner should still migrate the Auth migration's 3 calls to the container pattern above as a *correctness* fix — because in a NativePHP build `storage_path()` resolves to appdata and the literal-string `app/secrets/...` constants would still be correct *relative* paths only if joined to the right root. Simplest safe outcome: migrate the 3 calls, exclude migrations from the gate. Document the exclusion in the test's comment block exactly as `noAuthFacadeOrHelper` documents its skip.

### Anti-Patterns to Avoid

- **Duplicating the `NATIVEPHP_STORAGE_PATH` fallback in config files.** Violates D-04 ("no two coexisting patterns"). All branching lives in `UserDataPathService` once.
- **Using `$app->useStoragePath()` as the *only* mechanism.** It retargets Laravel's storage root but leaves `database_path()` in `config/database.php` pointing at the project root, and gives no DI accessor surface. It is a Phase 15 *complement* at best.
- **A granular-accessors-only service surface.** `EmlBlobStore` needs a generic relative-path method or it will re-hand-roll `storage/app/` literals.
- **`env()` inside `UserDataPathService` instead of `getenv()`.** Laravel's `env()` helper depends on the `Env` repository being initialized; `getenv()` is unconditional and safe at any boot stage. Use `getenv()`.
- **Asserting Phase 13 makes the shipped build correct.** It does not — it makes paths *routable*. Phase 15 must actually set `NATIVEPHP_STORAGE_PATH` (or wire NativePHP's own `storagePath()` rewrite). Flag this.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Detecting "am I in a packaged build" | A bespoke `is_packaged()` heuristic sniffing `PHP_BINARY` or Electron globals | `getenv('NATIVEPHP_STORAGE_PATH')` presence (D-01) | Single authoritative signal; CONTEXT locked it |
| Walking the AST to forbid `storage_path()` | A custom PHPStan rule | A Pest `it(...)` grep test in `BoundaryArchTest` | The project already has 12+ grep-style arch tests; `noLaravelGlobalHelpersInCoreConsoleCommands` is a copy-paste template |
| Path joining | Manual `.` concatenation scattered everywhere | Centralize in `UserDataPathService` with `DIRECTORY_SEPARATOR` | Windows builds (Phase 15 ships `.msi`) need separator-correct joins |

**Key insight:** The whole phase is itself a "don't hand-roll" exercise — it removes ~14 scattered raw-helper call sites and replaces them with one tested service. The only thing genuinely hand-written is the ~80-line service; everything else is deletion + delegation.

## Common Pitfalls

### Pitfall 1: Pest arch tests cannot reliably catch string literals

**What goes wrong:** `pest-plugin-arch`'s fluent `arch()->expect()->not->toUse()` API detects *symbol usage* (classes, function calls) via PHP-Parser. It does **not** scan for arbitrary string literals like `'database.sqlite'` or `'storage/app/'`. A fluent arch rule cannot enforce CONTEXT's "or equivalent hard-coded string literal" clause.
**Why it happens:** Arch plugin operates on the parsed symbol graph, not raw text.
**How to avoid:** Use the project's established **grep-style `it(...)` test** pattern — `file_get_contents` + `preg_match` + comment-stripping — exactly as `noLaravelGlobalHelpersInCoreConsoleCommands` (BoundaryArchTest.php lines 967-1028) and `noAuthFacadeOrHelper` (lines 1030-1117) already do. That pattern catches *both* function calls and string literals because it is pure text matching. The fluent `arch()` API is the wrong tool here.
**Warning signs:** A plan task that says "add an `arch()->expect()->not->toUse('storage_path')` rule" — that will not catch literals and will not catch the helper called as a bare function (arch's `toUse` targets imported symbols, and these helpers are global functions, not imports).

### Pitfall 2: The grep pattern matches its own allowed file

**What goes wrong:** The new arch test greps `Modules/` for `storage_path(` etc.; `UserDataPathService.php` legitimately calls `base_path()`. Without an allow-list entry the test fails on the very file it is protecting.
**Why it happens:** The service is the *one* sanctioned call site.
**How to avoid:** Add `Modules/Core/Public/Services/UserDataPathService.php` to a precise allow-list array (mirror `noAuthFacadeOrHelper`'s `$allowList`). Keep it a per-file string match, never a directory glob.
**Warning signs:** Allow-listing the whole `Modules/Core/Public/Services/` directory — too broad; a future service could smuggle in a raw helper.

### Pitfall 3: `putenv` vs `$_ENV` vs `getenv` mismatch in the feature test

**What goes wrong:** The Pest test sets `NATIVEPHP_STORAGE_PATH=<tmp>` and expects `migrate:fresh` to write under `<tmp>`. If the test uses `$_ENV['NATIVEPHP_STORAGE_PATH'] = ...` but the service reads `getenv()`, or vice versa, the override silently does nothing.
**Why it happens:** PHP has three partially-disjoint env stores. `getenv()` reads the process environment; `$_ENV` is a superglobal populated at startup; Laravel's `env()` reads its own `Env` repository.
**How to avoid:** Pick `getenv()` in the service (recommended — works at all boot stages). In the test set it with **`putenv('NATIVEPHP_STORAGE_PATH='.$tmp)`** so `getenv()` sees it, and `putenv('NATIVEPHP_STORAGE_PATH')` (no `=`) in teardown to clear it. Be aware Laravel's `Env` may be configured with `putenv` disabled (`Env::disablePutenv()` in newer Laravel) — but that only affects `env()`, not direct `getenv()`/`putenv()`. Verify in the test that a direct `getenv()` reflects the `putenv()`.
**Warning signs:** A test that passes the env via `$this->app['config']->set(...)` — that sets config, not the env var, and bypasses the very branch under test.

### Pitfall 4: Config caching freezes the resolved path

**What goes wrong:** If `config:cache` ran before the test sets the env var, `config('database.connections.sqlite.database')` returns the *cached* project-root path and the test asserts against the wrong location.
**Why it happens:** Cached config is a flat PHP array of pre-resolved strings.
**How to avoid:** The feature test must run with **uncached config** (Laravel's test harness does this by default unless `bootstrap/cache/config.php` exists). Add an explicit `php artisan config:clear` guard, or assert `! $this->app->configurationIsCached()` at the top of the test. Document that the shipped build (Phase 15) must `config:cache` *after* the env is set, never before.
**Warning signs:** Test green locally, red in CI (or vice versa) depending on whether a stray `bootstrap/cache/config.php` exists.

### Pitfall 5: `migrate:fresh` under the simulated env needs the database *directory* to exist

**What goes wrong:** `NATIVEPHP_STORAGE_PATH=<tmp>` → `databaseFile()` returns `<tmp>/database/database.sqlite`, but `<tmp>/database/` does not exist; SQLite cannot create a file in a missing directory and `migrate:fresh` throws "database file does not exist" or "unable to open database file".
**Why it happens:** Laravel/SQLite do not `mkdir -p` the parent of the database file.
**How to avoid:** The feature test must `mkdir` `<tmp>/database` (and `<tmp>/storage/app/backups`, `<tmp>/storage/app/secrets`) before running the artisan commands — OR the planner decides `UserDataPathService` accessors should ensure-directory on first call (note `BackupDatabaseCommand::backupsDir()` already does `makeDirectory` for backups; mirror that). Recommend the test creates the dirs explicitly to keep the service pure.
**Warning signs:** The success-criterion-2 test failing with an "unable to open database file" SQLite error.

### Pitfall 6: NativePHP *also* rewrites `storage_path()` — double-resolution risk in Phase 15

**What goes wrong:** [CITED: nativephp.com/docs/desktop/2/digging-deeper/files] NativePHP rewrites `Application::storagePath()` and the `storage_path()` helper to the Electron appdata path *itself*. If Phase 15 *both* sets `NATIVEPHP_STORAGE_PATH` to the appdata path *and* `UserDataPathService` falls back through a path that NativePHP also rewrote, paths could be computed off the wrong base.
**Why it happens:** Two independent rewrite mechanisms (NativePHP's `storagePath()` override + this project's env-var convention) targeting overlapping concerns.
**How to avoid:** Phase 13 is fine — it is not the place to solve this. But the planner MUST record an explicit Phase 15 hand-off note: "Decide whether NativePHP's native `storagePath()` rewrite or `NATIVEPHP_STORAGE_PATH` is the source of truth in the bundle, and make `UserDataPathService` consistent with that choice." Phase 13 only proves the *simulated* env works.
**Warning signs:** Anyone treating `NATIVEPHP_STORAGE_PATH` as a documented NativePHP feature — it is not; it is this project's convention (verified: not in NativePHP v2 docs).

## Code Examples

### Extending BoundaryArchTest with the path invariant

```php
// Source: project pattern — direct adaptation of BoundaryArchTest.php
// lines 967-1028 (noLaravelGlobalHelpersInCoreConsoleCommands).
// tests/Contracts/BoundaryArchTest.php — APPEND this block.

it('does not allow raw path helpers or hard-coded storage literals outside UserDataPathService (noStoragePathHardCodedOutsideUserDataPathService)', function (): void {
    // PKG-01 / Phase 13 invariant: every filesystem path flows through
    // Modules\Core\Public\Services\UserDataPathService so a NativePHP
    // build can retarget the storage root. The service is the sole
    // sanctioned caller of base_path(); no other production file may
    // call database_path() / storage_path() / base_path() or embed the
    // literals 'database.sqlite' / 'storage/app/'. Test files keep the
    // raw helpers (CONTEXT D-02 — they run in a known Herd env, never
    // ship). Migration directories are skipped, consistent with
    // noAuthFacadeOrHelper, and migrated to container resolution
    // separately. The grep strips block + line comments first so PHPDoc
    // references stay legal.
    $allowList = [
        'Modules/Core/Public/Services/UserDataPathService.php',
    ];

    // Bare function-call shape only — `(?<![>:])` rules out
    // `$obj->storage_path()` / `Class::base_path()` method calls.
    $bannedHelpers = '/(?<![>:])\b(database_path|storage_path|base_path)\s*\(/';
    // Literal strings that hard-code the dev-mode layout.
    $bannedLiterals = "/['\"](database\\.sqlite|storage\\/app\\/)/";

    $hits = [];
    foreach (['Modules', 'app', 'config'] as $root) {           // D-02 scope
        $abs = base_path($root);
        if (! is_dir($abs)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
                continue;
            }
            $path = $file->getPathname();
            if (preg_match('#/tests/#', $path) === 1
                || preg_match('#/Database/Migrations/#', $path) === 1) {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $path);
            if (in_array($relative, $allowList, true)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
            if (preg_match($bannedHelpers, $stripped) === 1
                || preg_match($bannedLiterals, $stripped) === 1) {
                $hits[] = $relative;
            }
        }
    }

    expect($hits)->toBe(
        [],
        "Raw path helpers / storage literals are forbidden outside UserDataPathService. Offenders:\n  ".implode("\n  ", $hits),
    );
});
```

> **Two judgement calls for the planner.** (1) The `config/` files legitimately reference `UserDataPathService::` — that is *not* matched by `$bannedHelpers` (it has the `(?<![>:])` lookbehind so `::databaseFile(` is safe) and *not* by `$bannedLiterals`, so no allow-list entry is needed for `config/`. Good. (2) The literal regex `storage\/app\/` will also match PHPDoc/comment text — but the test strips comments first, so the many `storage/app/inbox/` *comment* references found across `EmlBlobStore`, `RestoreDatabaseCommand`, Blade views etc. are NOT flagged. **But** the Blade view `Modules/Core/Resources/views/livewire/settings-page.blade.php` shows `storage/app/inbox-drop/` inside `<code>` tags as *displayed text* — the comment-strip regex does not strip Blade `{{-- --}}` and the `<code>` content is real output, so it WOULD be flagged. Recommendation: restrict the file scan to `.php` non-Blade files (the example already only matches `/\.php$/`, and Blade files are `.blade.php` which also ends in `.php` — so explicitly exclude `.blade.php`, OR scope the literal rule to detect only `'...'`/`"..."` quoted PHP strings, which `<code>storage/app/...</code>` is not). The cleanest fix: add `if (str_ends_with($path, '.blade.php')) { continue; }` for the literal check, since user-facing docs strings in Blade are legitimate.

### CI grep gate as a composer script (recommended — Phase 17 absorbs it)

```jsonc
// composer.json "scripts" block — ADD "check:paths".
// No .github/workflows/ exists yet (verified). Phase 17 (CI-01) builds
// the real pipeline; expressing the gate as a composer script now means
// Phase 17 adds ONE line (`composer check:paths`) to ci.yml.
"scripts": {
    "test": "pest --parallel",
    "analyse": "phpstan analyse --memory-limit=1G",
    "format": "pint",
    "format:check": "pint --test",
    "check:paths": "bash bin/check-paths.sh"
}
```
```bash
#!/usr/bin/env bash
# bin/check-paths.sh — standalone grep gate. Exit 1 on any offender.
# Mirrors the arch test but is runnable without booting Pest, so CI can
# fail fast. The arch test remains the authoritative in-suite check;
# this is the fast pre-flight.
set -euo pipefail

ALLOW="Modules/Core/Public/Services/UserDataPathService.php"

# Raw helper calls (bare function form) in production code.
helpers=$(grep -RInE '(^|[^>:_a-zA-Z])(database_path|storage_path|base_path)[[:space:]]*\(' \
    --include='*.php' --exclude='*.blade.php' \
    Modules app config 2>/dev/null \
    | grep -v "/tests/" | grep -v "/Database/Migrations/" \
    | grep -v "$ALLOW" || true)

# Hard-coded storage-layout string literals.
literals=$(grep -RInE "['\"](database\.sqlite|storage/app/)" \
    --include='*.php' --exclude='*.blade.php' \
    Modules app config 2>/dev/null \
    | grep -v "/tests/" | grep -v "/Database/Migrations/" \
    | grep -v "$ALLOW" || true)

if [[ -n "$helpers" || -n "$literals" ]]; then
  echo "FAIL: raw path helpers / storage literals outside UserDataPathService:"
  [[ -n "$helpers" ]]  && echo "$helpers"
  [[ -n "$literals" ]] && echo "$literals"
  exit 1
fi
echo "OK: no raw path helpers or storage literals outside UserDataPathService."
```

> The arch test and the shell gate intentionally overlap. CONTEXT success-criterion 1 wants both an arch test (in-suite) *and* a CI grep gate. Keeping them as two artifacts that encode the same rule is acceptable redundancy; the arch test is authoritative (runs in `pest`), the shell script is a fast standalone pre-flight Phase 17 wires into `ci.yml`.

## Runtime State Inventory

> Phase 13 is a refactor (rename-of-mechanism). This inventory confirms no runtime state migration is needed.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — `UserDataPathService` changes *where new paths point*, not any stored value. SQLite rows, the `database.sqlite` file itself, `oauth_secrets` rows are untouched. In Herd dev the resolved paths are byte-identical to today's (`<project>/database/database.sqlite`, `<project>/storage/app/...`). | None — verified: fallback paths equal current paths |
| Live service config | None — no external service holds a diederik path string. | None — verified |
| OS-registered state | The `deploy/launchd/` plist templates (CLAUDE.md mentions them) hard-code `cd ~/code/diederik && php artisan ...`. These are launch *working directories*, not app-internal paths, and are out of Phase 13 scope (Phase 15 desktop shell owns process launching). | None for Phase 13 — note for Phase 15 |
| Secrets/env vars | `NATIVEPHP_STORAGE_PATH` is a NEW env var this phase consumes; it is absent in Herd dev (intended). `DIEDERIK_RUNTIME` stays orthogonal (D-01). No secret *value* changes. | None — the var's *absence* is the dev default |
| Build artifacts | `config:cache` output (`bootstrap/cache/config.php`) freezes resolved paths. If a cached config exists from before the refactor it holds stale `database_path()` output — harmless in dev (same value) but must be cleared in the shipped build after env is set. | `config:clear` after refactor; Phase 15 owns bundle config-cache timing |

**Nothing requiring data migration:** Verified — in Herd dev the new code produces the identical paths v1.0/Phase-12 used. This is why CONTEXT could drop the migration wizard (PKG-02): there is genuinely nothing to migrate.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Scattered `database_path()`/`storage_path()`/`base_path()` calls | Single `UserDataPathService` | This phase | Paths become NativePHP-retargetable |
| `core.backups_directory` string-binding + `->when()->needs('$backupsPath')` contextual injection (CoreServiceProvider lines 61-85) | Inject `UserDataPathService`; call `->backups()` | This phase (D-04) | Removes 3 contextual bindings + the named singleton; `BackupDatabaseCommand` / `RestoreDatabaseCommand` / `BackupFreshnessProbe` constructors change |

**Deprecated/outdated by this phase:**
- The `'core.backups_directory'` container binding and all three `$this->app->when(...)->needs('$backupsPath')->give(...)` blocks in `CoreServiceProvider::register()` — removed per D-04. The 3 consumers (`BackupDatabaseCommand`, `RestoreDatabaseCommand`, `BackupFreshnessProbe`) drop their `private readonly string $backupsPath` constructor param and gain `private readonly UserDataPathService $paths`.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `NATIVEPHP_STORAGE_PATH` is a project-invented convention, NOT a documented NativePHP env var | Summary, Pitfall 6 | LOW — CONTEXT D-01 defines it as the project's signal regardless; but if NativePHP *does* later define this name with different semantics, Phase 15 must reconcile. Verified absent from NativePHP v2 docs. |
| A2 | In Herd dev, `UserDataPathService` fallback paths are byte-identical to current `database_path()`/`storage_path()` output | Runtime State Inventory | MEDIUM — if the project root vs `storage/` join differs subtly (trailing slash, symlink resolution) a dev path could shift. Mitigation: the feature test should also assert the *no-env* (Herd) branch resolves to the same path `storage_path()` returns today. |
| A3 | Laravel 13 evaluates config arrays in `LoadConfiguration` before `RegisterProviders` (so static-resolver works, DI does not) | Pattern 1, Pattern 2 | LOW — this is stable Laravel architecture since Laravel 5; `Application::$bootstrappers` order is unchanged through Laravel 13. [CITED: Laravel framework `Illuminate\Foundation\Application`] |
| A4 | `pest-plugin-arch` fluent API cannot match string literals | Pitfall 1 | LOW — confirmed by the plugin's symbol-graph design; the project already works around this with grep-style `it()` tests for every literal-sensitive rule. |
| A5 | The grep-style `it()` test (not fluent `arch()`) is the right enforcement tool | Architecture, Code Examples | LOW — 12+ existing tests in `BoundaryArchTest.php` use exactly this pattern; it is the established project convention. |

## Open Questions

1. **Exact `UserDataPathService` accessor surface (granular vs generic).**
   - What we know: D-02 explicitly leaves this to planner/researcher. `EmlBlobStore` needs deep dynamic paths; `BackupDatabaseCommand` needs one fixed dir.
   - What's unclear: whether to expose ~6 named methods, one generic `appPath(string)`, or both.
   - Recommendation: **both** — named accessors for the fixed paths (`databaseFile`, `backupsPath`, `secretsPath`, `frameworkPath`) plus a generic `appPath(string $relative)` for `EmlBlobStore`'s `app/inbox/...` trees. Documented in Pattern 1.

2. **Should `config/` be in the grep scope or allow-listed?**
   - What we know: config files will reference `UserDataPathService::` which the helper regex does not match.
   - What's unclear: nothing — analysed.
   - Recommendation: **Keep `config/` in scope, no allow-list entry needed.** The `(?<![>:])` lookbehind makes `::databaseFile(` invisible to the helper regex, and `UserDataPathService::frameworkPath('sessions')` contains no banned literal. The gate naturally permits the correct pattern and still catches a regressing `storage_path()` in any future config file.

3. **PHP 8.5-vs-8.4 Larastan spike (carried from STATE.md blockers).**
   - What we know: STATE.md flags "Run a Larastan-L10-on-8.4 spike during Phase 13 start." Project dev pin is `^8.5`; `nativephp/php-bin` ships 8.1–8.4.
   - What's unclear: whether this spike belongs in Phase 13 or is purely a Phase 17 matrix concern.
   - Recommendation: **Out of Phase 13's PKG-01 scope** — it touches no path code. The planner should note it but not plan tasks for it here; it is a Phase 17 CI-matrix item. (Flagging because STATE.md attached it loosely to "Phase 13 start.")

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | Everything | ✓ | 8.5.0alpha1 | — |
| `pest` | Arch test + feature test | ✓ | Pest 4 (`pestphp/pest ^4.0`) | — |
| `pest-plugin-arch` | `BoundaryArchTest` | ✓ | `^4.0` | — |
| Larastan | L10 strict gate on the new service | ✓ | `^3.0` | — |
| `bash` + `grep` | `bin/check-paths.sh` CI gate | ✓ (macOS/Linux CI) | system | The arch test alone (in-suite) if a pure-PHP gate is preferred |
| `nativephp/desktop` | Real shipped-build path resolution | ✗ | not installed | Phase 13 needs only the *simulated* env var — no NativePHP install required. Phase 15 installs it. |

**Missing dependencies with no fallback:** None — Phase 13 is fully executable today.
**Missing dependencies with fallback:** `nativephp/desktop` absent — intentional; Phase 13 validates against a `putenv()`-simulated env, not a real bundle.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4 (`pestphp/pest ^4.0`) on PHPUnit 11 |
| Config file | `tests/Pest.php` (root); per-module suites bound there too |
| Quick run command | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` |
| Full suite command | `composer test` (`pest --parallel`) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PKG-01 | No raw `database_path()`/`storage_path()`/`base_path()` or `database.sqlite`/`storage/app/` literal outside `UserDataPathService` | arch (grep) | `vendor/bin/pest --filter=noStoragePathHardCodedOutsideUserDataPathService` | ❌ Wave 0 — new `it()` block in `BoundaryArchTest.php` |
| PKG-01 | CI grep gate fails the build on any offender | shell | `composer check:paths` | ❌ Wave 0 — new `bin/check-paths.sh` + composer script |
| PKG-01 | `migrate:fresh` under `NATIVEPHP_STORAGE_PATH=<tmp>` creates SQLite under `<tmp>/database/` | feature | `vendor/bin/pest --filter=UserDataPathResolution` | ❌ Wave 0 — new `UserDataPathResolutionTest.php` |
| PKG-01 | `db:backup` under the simulated env writes to `<tmp>/storage/app/backups/` | feature | same file | ❌ Wave 0 |
| PKG-01 | OAuth secrets path resolves under `<tmp>/storage/app/secrets/` under the simulated env | feature | same file | ❌ Wave 0 |
| PKG-01 | No-env (Herd) branch resolves to the *same* paths as today (regression guard, A2) | feature/unit | unit test on `UserDataPathService` | ❌ Wave 0 — recommended extra |
| PKG-01 | Existing `db:backup`/`db:restore` Pest tests still pass after the `$backupsPath`→service swap | feature (regression) | `vendor/bin/pest Modules/Core/tests` | ✅ existing — must stay green |

### Sampling Rate
- **Per task commit:** `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` + `composer check:paths` (both < 10s)
- **Per wave merge:** `composer test` + `composer analyse` (Larastan L10 must pass on the new service) + `composer format:check`
- **Phase gate:** Full suite green + `composer check:paths` green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Contracts/BoundaryArchTest.php` — append `noStoragePathHardCodedOutsideUserDataPathService` `it()` block (covers PKG-01 arch leg)
- [ ] `Modules/Core/tests/Feature/UserDataPathResolutionTest.php` (or `tests/Feature/`) — simulated-NativePHP-env test (covers PKG-01 success criterion 2)
- [ ] `Modules/Core/tests/Unit/UserDataPathServiceTest.php` — direct unit test of both env branches incl. the A2 Herd-parity regression assertion
- [ ] `bin/check-paths.sh` + `composer.json` `check:paths` script — CI grep gate
- [ ] No framework install needed — Pest 4 + arch plugin already present

## Security Domain

> `security_enforcement` config not located in `.planning/config.json` scope read; treat as enabled. This phase is low-security-surface (path abstraction) — most ASVS categories do not apply.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | Phase 13 touches no auth |
| V3 Session Management | indirect | `config/session.php` `files` path is being abstracted; session driver is `database` (D-26) so the `files` path is mostly inert — but resolving it correctly under appdata still matters. No control change. |
| V4 Access Control | no | — |
| V5 Input Validation | yes (narrow) | `UserDataPathService` accessors that take a `$relative` arg (e.g. `appPath(string)`) must not allow `../` traversal. `EmlBlobStore` already validates `provider_message_id` against `MESSAGE_ID_PATTERN` before path construction (verified, line 60/86) — the service should NOT loosen that. Recommendation: `appPath()` rejects `..` segments, or callers keep their existing validation and `appPath()` only joins trusted sub-paths. |
| V6 Cryptography | no | — |
| V12 Files & Resources | yes | The whole phase IS file-path handling. Control: centralization itself reduces path-traversal surface. Preserve existing chmod-0600/0700 behavior in `BackupDatabaseCommand`, `EmlBlobStore`, the Auth migration — the service returns *paths*, callers keep doing the permission hardening. |

### Known Threat Patterns for path-abstraction refactor

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Path traversal via `appPath('../../etc/passwd')` | Tampering | Service rejects `..` segments; callers validate dynamic id components (EmlBlobStore already does) |
| Resolved path escapes the appdata sandbox in shipped build | Tampering / EoP | `UserDataPathService` always joins onto `storageRoot()`; never accepts an absolute override except the `NATIVEPHP_STORAGE_PATH` root itself |
| Stale `config:cache` leaks dev path into shipped build | Information Disclosure (path leak) | Phase 15 must `config:clear` then `config:cache` after env is set; documented as a hand-off |
| Secrets directory (`storage/app/secrets/`) world-readable after re-rooting | Information Disclosure | Existing chmod-0600 logic in `OAuthSecretsRepository` / the Auth migration is preserved; the service changes the *path*, not the *mode* |

## Sources

### Primary (HIGH confidence)
- Codebase files read directly: `tests/Contracts/BoundaryArchTest.php`, `Modules/Core/Providers/CoreServiceProvider.php`, `Modules/Core/Internal/Console/BackupDatabaseCommand.php`, `Modules/Core/Internal/Console/RestoreDatabaseCommand.php`, `Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php`, `Modules/EmailScan/Public/Services/EmlBlobStore.php`, `Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php`, `config/database.php`, `config/session.php`, `config/modules.php`, `composer.json`, `phpstan.neon`, `bootstrap/app.php`, `bootstrap/providers.php`, `tests/Pest.php`, `Modules/Core/Public/Services/SystemClock.php` — verified 2026-05-20
- Full production grep for `database_path(`/`storage_path(`/`base_path(`/`database.sqlite`/`storage/app` — verified call-site inventory below
- `.planning/phases/13-app-paths/13-CONTEXT.md`, `.planning/REQUIREMENTS.md`, `.planning/STATE.md`, `.planning/ROADMAP.md`, `.planning/phases/12-multi-user-activation/12-CONTEXT.md`

### Secondary (MEDIUM confidence)
- [NativePHP desktop v2 — Files](https://nativephp.com/docs/desktop/2/digging-deeper/files) — confirms NativePHP rewrites `Application::storagePath()` + `storage_path()` to Electron `appData`; does NOT document `NATIVEPHP_STORAGE_PATH`
- [NativePHP desktop v2 — Databases](https://nativephp.com/docs/desktop/2/digging-deeper/databases) — SQLite lands in the appdata directory; exact override mechanism not documented
- [Laravel framework — useStoragePath discussion (#20686)](https://github.com/laravel/framework/issues/20686) — `useStoragePath()` behavior and `config:cache`/`env()` caveat

### Tertiary (LOW confidence)
- General web search on NativePHP storage env vars — corroborates that `NATIVEPHP_STORAGE_PATH` is not a documented variable (flagged as assumption A1)

## Verified Production Call-Site Inventory

> The CONTEXT.md "~8 files" estimate is close but the grep found **additional** call sites and string-literal occurrences. Full verified list (production code only — `/tests/` excluded):

**Raw path-helper *function calls* (must migrate or allow-list):**
| File | Line | Call | Resolves to |
|------|------|------|-------------|
| `Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php` | 47 | `storage_path('app/secrets/email-oauth.json.pre-phase-12.bak')` | rollback-artefact existence check |
| `Modules/EmailScan/Public/Services/EmlBlobStore.php` | 95 | `storage_path('app/inbox/...')` | per-message `.eml` blob path |
| `Modules/EmailScan/Public/Services/EmlBlobStore.php` | 228 | `storage_path('app/inbox')` | inbox root for chmod walk |
| `Modules/Auth/Database/Migrations/2026_05_20_000002_…php` | 37 | `storage_path('app/secrets/email-oauth.json')` | legacy file (migration) |
| `Modules/Auth/Database/Migrations/2026_05_20_000002_…php` | 38 | `storage_path('app/secrets/email-oauth.json.pre-phase-12.bak')` | backup target (migration) |
| `Modules/Auth/Database/Migrations/2026_05_20_000002_…php` | 46 | `storage_path('app/secrets/README.md')` | README target (migration) |
| `config/database.php` | 33 | `database_path('database.sqlite')` | **load-bearing** SQLite path |
| `config/session.php` | 46 | `storage_path('framework/sessions')` | session files dir |
| `config/modules.php` | 35 | `base_path('Modules')` | module root |
| `config/modules.php` | 37 | `base_path('database/migrations')` | migration scan path |
| `config/modules.php` | 77 | `base_path('vendor/*/*')` | module scan glob |
| `config/modules.php` | 129 | `base_path('modules_statuses.json')` | activator status file |
| `config/modules.php` | 36 | `public_path('modules')` | **NOT in CONTEXT scope** — `public_path` is a 4th helper; planner decides whether to abstract it (recommend: yes, for completeness, add `publicPath()` to the service or leave as a documented exception since `public/` is asset-serving not user-data) |

**Important corrections to the CONTEXT.md integration-point list:**
- `Modules/Core/Providers/CoreServiceProvider.php` — CONTEXT says it calls `base_path`. **It does NOT** — line 60 is a *comment*, and line 63 calls `$app->basePath('storage/app/backups')` which is the *DI-correct* `Application` method, not the global helper. The real Phase 13 work here is **removing the `core.backups_directory` binding + 3 `->when()->needs('$backupsPath')` blocks** (D-04), not fixing a raw helper.
- `Modules/Core/Internal/Console/RestoreDatabaseCommand.php` — CONTEXT lists it as calling a raw helper. **It does NOT** — line 97 uses `$this->app->basePath('storage/framework/down')` (DI-correct). Its real Phase 13 change is the `$backupsPath` string → `UserDataPathService` swap (D-04). The `storage/framework/down` path should still move to the service for NativePHP-correctness.
- `config/modules.php` has **5** banned-helper calls (lines 35, 37, 77, 129 = `base_path`; line 36 = `public_path`), not the implied few.
- `public_path('modules')` in `config/modules.php` line 36 is a helper the CONTEXT list omits — planner must decide its disposition.

**String-literal-only occurrences (comments / docblocks / Blade — mostly NOT violations after comment-strip):**
- `EmlBlobStore.php`, `RestoreDatabaseCommand.php`, `BackupDatabaseCommand.php`, `BackupFreshnessProbe.php`, `BackupRetentionPolicy.php`, `InstallCommand.php`, `RunImport.php`, `ScanInboxDropFolderJob.php`, `FileDropEmlBlobStore.php`, `SettingsPage.php` — all reference `storage/app/...` only in **comments/docblocks**, stripped by the grep, so NOT flagged.
- `Modules/Core/Resources/views/livewire/settings-page.blade.php` lines 157/175/177 — `storage/app/inbox-drop/` inside `<code>` tags as **user-facing displayed text**. This is the one genuine false-positive risk for the literal regex (see Code Examples judgement call (2)): **exclude `.blade.php` from the literal check.**
- `OAuthClientWizardModal.php` line 141 — `storage/app/secrets/` in a user-facing **error message string**. This IS a real PHP string literal and WOULD be flagged. Planner decision: either (a) treat user-facing copy as exempt and refine the regex to ignore strings inside obvious message contexts (hard), or (b) accept that this error string should not name a hard-coded path and reword it to not contain `storage/app/` (recommended — the message can say "check your secrets-directory permissions" without the literal path). This is a genuine Phase 13 task the CONTEXT.md list missed.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new packages; all tooling verified installed
- Architecture (static-resolver pattern): HIGH — Laravel boot order is stable and well-understood; the pattern is the only structurally-valid answer to the config/DI split
- Call-site inventory: HIGH — exhaustive grep performed; corrections to CONTEXT documented
- NativePHP behavior: MEDIUM — official docs confirm `storagePath()` rewrite; `NATIVEPHP_STORAGE_PATH` confirmed *absent* from docs (it is a project convention — A1)
- Pitfalls: HIGH — derived from reading the actual arch tests + Laravel/PHP env semantics

**Research date:** 2026-05-20
**Valid until:** 2026-06-20 (stable — Laravel 13 + Pest 4 internals are not fast-moving; the one volatile item is NativePHP v2 path semantics, re-verify at Phase 15 start)
