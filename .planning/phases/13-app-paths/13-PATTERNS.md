# Phase 13: AppPaths - Pattern Map

**Mapped:** 2026-05-20
**Files analyzed:** 13 (1 new service, 2 new tests, 1 new CI gate, 1 modified arch test, ~8 modified production call sites)
**Analogs found:** 13 / 13

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Core/Public/Services/UserDataPathService.php` | service | transform (path resolution) | `Modules/Core/Public/Services/SystemClock.php` + `CurrentUserService.php` | role-match (no env-reading service exists yet) |
| `tests/Contracts/BoundaryArchTest.php` (modified) | test (arch) | transform (grep) | `noLaravelGlobalHelpersInCoreConsoleCommands` `it()` block in same file (lines 967-1028) | exact |
| `Modules/Core/tests/Feature/UserDataPathResolutionTest.php` (new) | test (feature) | event-driven (boots artisan) | `Modules/Core/tests/Feature/BackupDatabaseCommandTest.php` | exact |
| `Modules/Core/tests/Unit/UserDataPathServiceTest.php` (new, recommended) | test (unit) | transform | `Modules/Core/tests/Unit/CurrentUserServiceTest.php` | role-match |
| `bin/check-paths.sh` + `composer.json` `check:paths` (new) | config (CI gate) | batch (shell grep) | RESEARCH.md § "CI grep gate" (copy-paste-ready) | exact |
| `Modules/Core/Internal/Console/BackupDatabaseCommand.php` (modified) | service (console command) | file-I/O | itself — `$backupsPath` string → `UserDataPathService` swap | exact (D-04) |
| `Modules/Core/Internal/Console/RestoreDatabaseCommand.php` (modified) | service (console command) | file-I/O | itself — `$backupsPath` string → `UserDataPathService` swap | exact (D-04) |
| `Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php` (modified) | service (probe) | file-I/O | itself — `$backupsPath` string → `UserDataPathService` swap | exact (D-04) |
| `Modules/Core/Providers/CoreServiceProvider.php` (modified) | provider | event-driven (DI wiring) | itself — remove `core.backups_directory` binding + 3 contextual bindings | exact (D-04) |
| `Modules/EmailScan/Public/Services/EmlBlobStore.php` (modified) | service | file-I/O | itself — `storage_path()` ×2 → injected `UserDataPathService::appPath()` | role-match |
| `Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php` (modified) | listener | event-driven | itself — `storage_path()` ×1 → injected `UserDataPathService` | role-match |
| `Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php` (modified) | migration | file-I/O | itself — its own existing `files()` container-resolution helper | exact |
| `config/database.php`, `config/session.php`, `config/modules.php` (modified) | config | transform | RESEARCH.md Pattern 2 — static-resolver call | exact |

## Pattern Assignments

### `Modules/Core/Public/Services/UserDataPathService.php` (service, transform — NEW)

**Analog:** `Modules/Core/Public/Services/SystemClock.php` (class shape) + `CurrentUserService.php` (constructor-DI style)

**Class-shape pattern** — copy from `SystemClock.php` (whole file, 20 lines):
- `declare(strict_types=1);` header
- `namespace Modules\Core\Public\Services;`
- `final class` keyword (every service in this directory is `final`)
- Doc-comment naming the convention being followed (SystemClock's comment explicitly says "the class static method, not the `now()` helper" — UserDataPathService's comment should equivalently say "the one sanctioned caller of `base_path()`")

**Constructor-DI style** — copy from `CurrentUserService.php` lines 18-20:
```php
public function __construct(
    private readonly AuthFactory $auth,
) {}
```
`UserDataPathService` itself takes **no constructor dependencies** (it reads `getenv()` only) — but DI consumers inject it the same `private readonly` way.

**Implementation (static-core + instance-delegate)** — full reference body is in RESEARCH.md § "Pattern 1" lines 127-202. Key invariants from that excerpt:
- `private static function projectRoot(): string { return base_path(); }` — the ONE sanctioned `base_path()` call in the whole codebase
- `getenv('NATIVEPHP_STORAGE_PATH')` (NOT `env()` — Anti-Patterns, RESEARCH line 262)
- `public static` accessors callable pre-container from `config/*.php`; instance methods one-line delegate to the statics
- Mixed surface: named accessors (`databaseFile()`, `backupsPath()`, `secretsPath()`, `frameworkPath()`) **plus** generic `appPath(string $relative)` for `EmlBlobStore`'s deep dynamic trees (RESEARCH § Open Question 1)
- `DIRECTORY_SEPARATOR` joins throughout (Windows `.msi` build — "Don't Hand-Roll" table)
- `appPath()` must reject `..` segments (Security Domain V5)

**Larastan L10 strict:** the new file is NOT excluded by `phpstan.neon` — it must pass level-10 strict. All methods need explicit return types (`: string`) and the file already shown in RESEARCH satisfies this.

---

### `tests/Contracts/BoundaryArchTest.php` (test/arch, MODIFIED — append one `it()` block)

**Analog:** `noLaravelGlobalHelpersInCoreConsoleCommands` — SAME FILE, lines 967-1028. This is the exact template; the new block is appended after `noAuthFacadeOrHelper` (ends line 1117).

**Test-naming convention** (line 967): `it('does not allow ... (camelCaseInvariantName)', function (): void { ... })` — the parenthesised name at the end is grep-able by `--filter`. New name: `noStoragePathHardCodedOutsideUserDataPathService`.

**Comment-block convention** — every grep test opens with a multi-paragraph rationale comment (see lines 968-977 and 1030-1049). The new block's comment must document: the PKG-01 invariant, the `Modules`/`app`/`config` scope (D-02), why test files are exempt, and why migration dirs are skipped — mirror `noAuthFacadeOrHelper`'s skip documentation.

**Helper-call regex** — copy the negative-lookbehind from line 998:
```php
$pattern = '/(?<![>:])\\b('.implode('|', array_map('preg_quote', $bannedFunctions)).')\\s*\\(/';
```
The `(?<![>:])` lookbehind is load-bearing — it lets `UserDataPathService::databaseFile(` pass while catching bare `storage_path(`.

**Allow-list pattern** — copy from `noAuthFacadeOrHelper` lines 1050-1062 (`$allowList` array of precise per-file relative paths) and lines 1099-1102 (the `in_array($relative, $allowList, true)` skip). New allow-list = exactly one entry: `Modules/Core/Public/Services/UserDataPathService.php`.

**Directory walk + comment-strip** — copy lines 1086-1104:
```php
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
);
...
if (preg_match('#/tests/#', $path) === 1 || preg_match('#/Database/Migrations/#', $path) === 1) {
    continue;
}
$relative = str_replace(base_path().'/', '', $path);
...
$stripped = preg_replace('#/\*.*?\*/|//[^\n]*|\{\{--.*?--\}\}#s', '', $contents) ?? $contents;
```
**Differences for Phase 13** (from RESEARCH § Code Examples + judgement calls):
- Loop over **three** roots `['Modules', 'app', 'config']`, not one (D-02 scope).
- Add a `$bannedLiterals` regex for `database.sqlite` / `storage/app/` (RESEARCH line 348).
- Skip `.blade.php` files for the literal check (RESEARCH judgement call 2 — `<code>` tags in `settings-page.blade.php` are false positives).

**Final assertion** — copy line 1113 shape: `expect($hits)->toBe([], "...Offenders:\n  ".implode("\n  ", $hits))`.

The complete copy-paste-ready `it()` block is in RESEARCH.md § "Extending BoundaryArchTest with the path invariant" lines 328-386.

---

### `Modules/Core/tests/Feature/UserDataPathResolutionTest.php` (test/feature, NEW)

**Analog:** `Modules/Core/tests/Feature/BackupDatabaseCommandTest.php` lines 1-73

**File-level pattern:**
- `declare(strict_types=1);` then top-level `use` imports (no class — Pest functional style, lines 1-8)
- Multi-line `/* ... */` block comment describing what the file drives end-to-end and its coverage bullets (lines 10-23)
- `beforeEach(function (): void { ... })` / `afterEach(function (): void { ... })` for setup/teardown (lines 25-73)
- `it('...', function (): void { ... })` test bodies

**Per-test temp-dir pattern** — copy from line 52:
```php
$this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-test-'.bin2hex(random_bytes(8));
```
This is the exact idiom for collision-free per-test directories.

**Teardown pattern** — copy lines 56-73 (`glob` + `@unlink` + `@rmdir` cascade).

**Phase-13-specific additions** (from RESEARCH Pitfalls 3, 4, 5):
- Set the env via `putenv('NATIVEPHP_STORAGE_PATH='.$tmp)` in `beforeEach`; clear with `putenv('NATIVEPHP_STORAGE_PATH')` (no `=`) in `afterEach` — `getenv()` must see it (Pitfall 3).
- `mkdir` `<tmp>/database`, `<tmp>/storage/app/backups`, `<tmp>/storage/app/secrets` BEFORE running artisan — SQLite will not create the parent dir (Pitfall 5).
- Assert `! $this->app->configurationIsCached()` at the top, or run `config:clear` — cached config freezes resolved paths (Pitfall 4).

**Real-on-disk SQLite pattern** — `BackupDatabaseCommandTest` rebinds the `sqlite` connection through `Repository::set('database.connections.sqlite.database', ...)` (lines 39-45). The feature test asserts `migrate:fresh`/`db:backup` land under `<tmp>` instead.

---

### `Modules/Core/tests/Unit/UserDataPathServiceTest.php` (test/unit, NEW — recommended)

**Analog:** `Modules/Core/tests/Unit/CurrentUserServiceTest.php`

Direct unit test of both `getenv()` branches. Critical assertion (RESEARCH A2 regression guard): with no env var set, `UserDataPathService` resolves to the **same** path `storage_path()` / `database_path()` return today — proves the Herd-dev fallback is byte-identical.

---

### `bin/check-paths.sh` + `composer.json` `check:paths` (config/CI gate, NEW)

**Analog:** RESEARCH.md § "CI grep gate as a composer script" lines 393-437 — copy-paste-ready.

**`composer.json` `scripts` block** — append one key alongside existing `test`/`analyse`/`format`/`format:check`:
```jsonc
"check:paths": "bash bin/check-paths.sh"
```
**`bin/check-paths.sh`** — copy the full script from RESEARCH lines 406-437. Key points: `set -euo pipefail`, `--exclude='*.blade.php'`, `grep -v "/tests/"`, `grep -v "/Database/Migrations/"`, single `ALLOW` file, exit 1 on any hit. No `.github/workflows/` exists yet — Phase 17 absorbs `composer check:paths` with one line.

---

### `Modules/Core/Internal/Console/BackupDatabaseCommand.php` (service/console command, MODIFIED — D-04)

**Analog:** itself — the `$backupsPath` → `UserDataPathService` swap.

**Current constructor** (lines 62-71): takes `private readonly string $backupsPath` as the 6th param, bound via `core.backups_directory` contextual binding.

**Change:** drop the `string $backupsPath` param; add `private readonly UserDataPathService $paths`. The existing `backupsDir()` method (lines 239-246) keeps its `makeDirectory(..., 0o755, ...)` logic but reads `$this->paths->backups()` instead of `$this->backupsPath`. Update the class doc-comment (lines 30-33) which currently names the retired `core.backups_directory` binding.

**Constructor-DI style to preserve** — the command already follows it perfectly (lines 62-69: all `private readonly`, typed, no facades). Just swap one param's type.

---

### `Modules/Core/Internal/Console/RestoreDatabaseCommand.php` (service/console command, MODIFIED — D-04)

**Analog:** itself + `BackupDatabaseCommand`.

**Change:** drop `private readonly string $backupsPath` (line 78); add `private readonly UserDataPathService $paths`. Also migrate the `$this->app->basePath('storage/framework/down')` call (line ~97) — RESEARCH notes this is DI-correct today but the `storage/framework/down` path should still route through `UserDataPathService::frameworkPath('down')` for NativePHP-correctness.

---

### `Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php` (service/probe, MODIFIED — D-04)

**Analog:** itself + `BackupDatabaseCommand`.

**Change:** drop the `private readonly string $backupsPath` constructor param; inject `UserDataPathService` and call `->backups()`. Same one-line swap as the two commands above.

---

### `Modules/Core/Providers/CoreServiceProvider.php` (provider, MODIFIED — D-04)

**Analog:** itself — removal of retired bindings.

**Remove** (RESEARCH § State of the Art):
- The `$this->app->singleton('core.backups_directory', ...)` block (lines 61-64).
- All three `$this->app->when(...)->needs('$backupsPath')->give(...)` blocks (lines 66-85) for `BackupDatabaseCommand`, `RestoreDatabaseCommand`, `BackupFreshnessProbe`.

**Keep the existing binding style** for `UserDataPathService` itself — mirror line 49/51 (`$this->app->singleton(...)`). Since `UserDataPathService` is dependency-free and stateless, a `singleton` binding (like `SystemClock`/`SystemAlertQuery`) is appropriate; the three consumers then resolve it through plain constructor DI (no contextual binding needed).

---

### `Modules/EmailScan/Public/Services/EmlBlobStore.php` (service, MODIFIED)

**Analog:** itself.

**Current:** `storage_path('app/inbox/...')` at line ~95 (deep dynamic path) and `storage_path('app/inbox')` at line ~228 (chmod-walk root).

**Change:** inject `private readonly UserDataPathService $paths` (constructor — class currently has no `__construct`, add one following `CurrentUserService` style). Replace line ~95 with `$this->paths->appPath(sprintf('inbox/%d/%d/...', ...))` and line ~228 with `$this->paths->appPath('inbox')`. This is exactly why the service needs a generic `appPath(string)` accessor — no fixed named accessor expresses the per-message tree (RESEARCH Open Question 1).

**Preserve** the `MESSAGE_ID_PATTERN` validation (lines 60, 86-91) — `appPath()` joins only the already-validated slug; do NOT loosen this (Security Domain V5).

---

### `Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php` (listener, MODIFIED)

**Analog:** itself.

**Current:** `storage_path(self::BACKUP_RELATIVE)` at line 47, where `BACKUP_RELATIVE = 'app/secrets/email-oauth.json.pre-phase-12.bak'`.

**Change:** add `private readonly UserDataPathService $paths` to the existing constructor (lines 34-38 — already constructor-DI'd with `Filesystem`, `CurrentUser`, `DatabaseManager`, so this is a clean 4th param). Replace `storage_path(self::BACKUP_RELATIVE)` with `$this->paths->secrets().DIRECTORY_SEPARATOR.'email-oauth.json.pre-phase-12.bak'` (or an `appPath('secrets/...')` call). The `BACKUP_RELATIVE` constant's `app/secrets/` prefix would otherwise trip the literal regex.

---

### `Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php` (migration, MODIFIED)

**Analog:** its OWN existing `files()` helper (lines 55-60).

**Pattern to copy** — the migration already resolves `Filesystem` via the container because anonymous-class migrations cannot use constructor DI:
```php
private function files(): Filesystem
{
    /** @var Filesystem $files */
    $files = Container::getInstance()->make(Filesystem::class);

    return $files;
}
```
**Change:** add a parallel `paths(): UserDataPathService` helper using the identical `Container::getInstance()->make(...)` shape (RESEARCH Pattern 3, lines 244-250). Replace the three `storage_path('app/secrets/...')` calls in `up()` (lines 37, 38, 46) with `$this->paths()->secrets().'/...'`.

**Note:** the arch test SKIPS `/Database/Migrations/` (consistent with `noAuthFacadeOrHelper` and `phpstan.neon`), so this migration is not gate-enforced — but it is migrated anyway as a NativePHP correctness fix (RESEARCH Pattern 3 scope decision).

---

### `config/database.php`, `config/session.php`, `config/modules.php` (config, MODIFIED)

**Analog:** RESEARCH.md § "Pattern 2" lines 213-231.

**Pattern:** config arrays evaluate pre-container (in `LoadConfiguration`, the first bootstrapper) so DI is impossible — call the `public static` accessor directly. PSR-4 autoload is registered before `LoadConfiguration`, so a static call needs no container.

```php
use Modules\Core\Public\Services\UserDataPathService;
// config/database.php line 33:
'database' => env('DB_DATABASE', UserDataPathService::databaseFile()),
// config/session.php line 46:
'files' => UserDataPathService::frameworkPath('sessions'),
// config/modules.php lines 35/37/77/129:
'modules' => UserDataPathService::modulesPath(),
'migration' => UserDataPathService::migrationsPath(),
```

**Open item the planner must resolve** (RESEARCH Verified Inventory): `config/modules.php` line 36 calls `public_path('modules')` — a 4th helper CONTEXT.md omits. Planner decides: add `publicPath()` to the service, or document `public_path` as an exception (asset-serving, not user-data). The grep gate `$bannedHelpers` regex as written in RESEARCH does NOT include `public_path` — confirm consistency with whatever the planner decides.

## Shared Patterns

### Service-class shape (Core public services)
**Source:** `Modules/Core/Public/Services/SystemClock.php` (whole file)
**Apply to:** `UserDataPathService`
```php
declare(strict_types=1);

namespace Modules\Core\Public\Services;

/**
 * [Doc comment naming the convention being followed and the one
 *  sanctioned exception — e.g. "the sole caller of base_path()".]
 */
final class UserDataPathService { ... }
```
Every file in `Modules/Core/Public/Services/` is `final`, has `declare(strict_types=1)`, and carries a rationale doc-comment.

### Constructor-DI (no facades, no helpers)
**Source:** `Modules/Core/Public/Services/CurrentUserService.php` lines 18-20; `BackupDatabaseCommand.php` lines 62-69
**Apply to:** every modified consumer (`BackupDatabaseCommand`, `RestoreDatabaseCommand`, `BackupFreshnessProbe`, `EmlBlobStore`, `EmitOAuthReauthRequiredAlert`)
```php
public function __construct(
    private readonly UserDataPathService $paths,
    // ...other deps...
) {}
```
All params `private readonly`, fully typed. MEMORY.md `feedback_laravel_di_only.md`: constructor DI only, no facades, no global helpers. Eloquent models direct is OK.

### Grep-style arch invariant (`it()` block)
**Source:** `tests/Contracts/BoundaryArchTest.php` lines 967-1028 (`noLaravelGlobalHelpersInCoreConsoleCommands`) and 1030-1117 (`noAuthFacadeOrHelper`)
**Apply to:** the new `noStoragePathHardCodedOutsideUserDataPathService` block
Recurring elements: rationale comment block; `(?<![>:])` negative-lookbehind regex; precise per-file `$allowList` array; `RecursiveIteratorIterator` walk; `/tests/` + `/Database/Migrations/` skip; comment-strip `preg_replace`; `expect($hits)->toBe([], "...Offenders...")`.

### Pest functional-test file layout
**Source:** `Modules/Core/tests/Feature/BackupDatabaseCommandTest.php` lines 1-73
**Apply to:** `UserDataPathResolutionTest.php`
No test class — top-level `use` imports, block-comment coverage summary, `beforeEach`/`afterEach` closures, `it()` bodies. Per-test temp dir via `sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-test-'.bin2hex(random_bytes(8))`; cascade `@unlink`/`@rmdir` teardown.

### Container-resolution inside migrations
**Source:** `Modules/Auth/Database/Migrations/2026_05_20_000002_...php` lines 55-60 (`files()` helper)
**Apply to:** the same migration's new `paths()` helper
Anonymous-class migrations cannot use constructor DI — resolve via `Container::getInstance()->make(...)` in a private typed helper method.

## No Analog Found

No production file is left without a pattern source. The closest thing to a gap:

| File | Role | Data Flow | Note |
|------|------|-----------|------|
| `Modules/Core/Public/Services/UserDataPathService.php` | service | transform | No existing service reads `getenv()` or exposes `public static` accessors. Class *shape* copies `SystemClock`; the static-resolver *body* has no codebase analog — it follows RESEARCH.md § Pattern 1, which is itself a documented Laravel boot-ordering solution, not an external invention. |

## Metadata

**Analog search scope:** `Modules/Core/Public/Services/`, `Modules/Core/Internal/Console/`, `Modules/Core/Providers/`, `Modules/Core/tests/`, `Modules/EmailScan/`, `Modules/Auth/Database/Migrations/`, `tests/Contracts/`, `config/`
**Files scanned:** ~12 read directly + grep inventory inherited from RESEARCH.md (verified call-site list, exhaustive)
**Pattern extraction date:** 2026-05-20
