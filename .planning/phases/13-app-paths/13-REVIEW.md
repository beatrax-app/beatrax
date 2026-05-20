---
phase: 13-app-paths
reviewed: 2026-05-20T00:00:00Z
depth: standard
files_reviewed: 16
files_reviewed_list:
  - Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php
  - Modules/Core/Internal/Console/BackupDatabaseCommand.php
  - Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php
  - Modules/Core/Internal/Console/RestoreDatabaseCommand.php
  - Modules/Core/Providers/CoreServiceProvider.php
  - Modules/Core/Public/Services/UserDataPathService.php
  - Modules/Core/tests/Feature/UserDataPathResolutionTest.php
  - Modules/Core/tests/Unit/UserDataPathServiceTest.php
  - Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php
  - Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php
  - Modules/EmailScan/Public/Services/EmlBlobStore.php
  - bin/check-paths.sh
  - config/database.php
  - config/modules.php
  - config/session.php
  - tests/Contracts/BoundaryArchTest.php
findings:
  critical: 1
  warning: 5
  info: 4
  total: 10
status: issues_found
---

# Phase 13: Code Review Report

**Reviewed:** 2026-05-20T00:00:00Z
**Depth:** standard
**Files Reviewed:** 16
**Status:** issues_found

## Summary

Phase 13 introduces `UserDataPathService` as the single source of truth for
filesystem paths so a packaged NativePHP build can retarget the storage root
via `NATIVEPHP_STORAGE_PATH`, and migrates every call site (backup/restore
commands, freshness probe, EML blob store, OAuth listener, the legacy-secrets
migration, and three config files) onto it. The service design is sound and the
arch-test / `check-paths.sh` gate enforce the invariant well.

The phase has one structural correctness gap that defeats the very goal of the
phase: `UserDataPathService` retargets *its own* accessors but the Laravel
framework's `storagePath()` is never reconfigured to agree. Any framework code
path that resolves paths through `storage_path()` (the maintenance-mode `down`
marker, `php artisan down/up`, the session/cache/view directories) will land
under the project tree while the phase's own accessors point at the NativePHP
root. In Herd development the two coincide, so tests pass green; in the packaged
build they silently diverge. The `RestoreDatabaseCommand` maintenance-mode rail
is the concrete victim.

Remaining findings are robustness and consistency issues in the migrated
commands and the path service.

## Critical Issues

### CR-01: `down` maintenance marker resolves to two different paths under a packaged build

**File:** `Modules/Core/Internal/Console/RestoreDatabaseCommand.php:94`, `Modules/Core/Public/Services/UserDataPathService.php:100-107`

**Issue:** `RestoreDatabaseCommand` detects/relies on maintenance mode by
checking `$this->paths->framework('down')`, which resolves to
`{storageRoot}/framework/down` — and `storageRoot()` honours
`NATIVEPHP_STORAGE_PATH`. But Laravel's `FileBasedMaintenanceMode::path()`
(vendor `FileBasedMaintenanceMode.php:62`) returns `storage_path('framework/down')`,
and the framework's `storagePath()` is **never** retargeted in this codebase —
no `useStoragePath(...)` call exists in `bootstrap/app.php` or any provider.

Consequences in a packaged NativePHP build (`NATIVEPHP_STORAGE_PATH` set):
- `php artisan down` (called by the command itself at line 104, and by an
  operator) writes the marker to the project-rooted `storage/framework/down`.
- `RestoreDatabaseCommand::handle()` at line 95 reads
  `$this->files->exists($downMarkerPath)` against the *NativePHP-rooted*
  `framework/down` — which will never exist.
- `$alreadyDown` is therefore always `false`. An operator who correctly ran
  `php artisan down` is told "App must be in maintenance mode" (line 98) and
  the restore is refused.
- With `--force-maintenance`, the command calls `down` (real marker written
  project-rooted), proceeds, and in the `finally` block calls `up` — but the
  whole point of the rail (verifying the app is actually quiet, and that
  Horizon workers are paused) is built on a marker path the command cannot
  observe. The safety property the docblock claims (lines 21-26) is not
  delivered off-Herd.

The session, cache, and view directories have the same class of divergence:
`config/session.php:48` points `files` at `UserDataPathService::frameworkPath('sessions')`
(NativePHP-rooted) while the framework's view compiler / cache still use
`storage_path()` internally. Sessions will land in one tree and compiled views
in another.

**Fix:** Retarget the framework storage path at boot so `storage_path()` and
`UserDataPathService::storageRoot()` always agree. In `bootstrap/app.php` (or a
very-early provider) before the container resolves any path:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->create()
    ->useStoragePath(UserDataPathService::storageBase());
```

`useStoragePath()` is idempotent and makes `storage_path()` /
`FileBasedMaintenanceMode::path()` resolve under the same root. After this, the
`framework('down')` accessor and Laravel's marker are byte-identical in every
environment. Add a regression test asserting
`app()->storagePath() === UserDataPathService::storageBase()` under both env
states. (Note: `database_path()` would similarly need `useDatabasePath()` if any
framework code depends on it — `config/database.php` already routes the sqlite
path through the service, so a check that nothing else calls `database_path()`
in framework-driven flows is warranted.)

## Warnings

### WR-01: `appPath()` rejects `..` but `frameworkPath()`, `publicPath()`, `projectPath()` do not

**File:** `Modules/Core/Public/Services/UserDataPathService.php:100-153`

**Issue:** `appPath()` carefully splits the relative argument on separators and
throws on a `..` traversal segment (lines 79-86). The sibling joiners
`frameworkPath($sub)`, `publicPath($relative)`, and `projectPath($relative)`
apply only `ltrim($x, '/\\')` and concatenate — a caller passing
`'../../etc'` (or a value derived from less-trusted input) escapes the intended
base directory silently. The class docblock and the unit test
(`UserDataPathServiceTest.php:80-83`) present traversal rejection as a property
of the service, but it holds for exactly one of four joiners.

**Fix:** Extract the segment-scan guard from `appPath()` into a private
`assertNoTraversal(string $relative): void` helper and call it from all four
joiners, or document explicitly on each non-validating accessor that the
argument MUST be a compile-time constant. Given `EmlBlobStore` is the only
caller and uses `appRelative()`/`appPath()`, applying the guard uniformly costs
nothing and removes the asymmetry.

### WR-02: `databaseFile()` re-implements the env branch instead of reusing `storageRoot()`

**File:** `Modules/Core/Public/Services/UserDataPathService.php:50-58`

**Issue:** `storageRoot()` (lines 41-48) reads `getenv('NATIVEPHP_STORAGE_PATH')`,
trims trailing separators, and falls back to the project root. `databaseFile()`
duplicates that exact env read and trim logic inline rather than calling
`storageRoot()`. The two copies can drift: if a future change normalises the env
value differently in one place (e.g. resolves symlinks, lowercases on Windows),
the database file and the storage tree silently disagree. This is live code
duplication of a security-relevant path computation.

**Fix:** Derive the database directory from the shared root:

```php
public static function databaseFile(): string
{
    $native = getenv('NATIVEPHP_STORAGE_PATH');
    $root = is_string($native) && $native !== ''
        ? rtrim($native, '/\\')
        : self::projectRoot();

    return $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';
}
```

Note the database dir is the storage root's *parent-level* `database/`, not
`storageRoot()/database`, so a small dedicated helper (`nativeRootOrProject()`)
shared by both `storageRoot()` and `databaseFile()` is the cleaner extraction.

### WR-03: `config/database.php` evaluates `UserDataPathService::databaseFile()` as the `env()` default — defeated by config caching

**File:** `config/database.php:35`

**Issue:** `'database' => env('DB_DATABASE', UserDataPathService::databaseFile())`.
The default argument to `env()` is evaluated *eagerly* every time this file is
parsed. When config is cached (`php artisan config:cache`), the file is parsed
once at cache-build time and the resolved string is frozen into
`bootstrap/cache/config.php`. If the cache is built in one environment (Herd,
no env var) and the app later runs as a packaged build (env var set), the frozen
project-rooted path wins — the NativePHP retarget is silently ignored.

The phase's own tests assert `configurationIsCached()` is `false`
(`UserDataPathResolutionTest.php:30`, `:77`) precisely because cached config
masks the env branch — that is an acknowledged hazard, but it is only guarded in
the test harness, not in production. A packaged build that ships a prebuilt
config cache (a common optimisation) reintroduces the bug.

**Fix:** Either (a) document and enforce that the packaged build must never ship
a config cache (and add an `InstallCommand` / boot-time assertion that
`config:cache` was not run with a NativePHP root mismatch), or (b) make the
sqlite `database` value resolve lazily — e.g. bind the connection config in a
service provider's `register()` after reading the env at runtime, so it survives
config caching. Option (b) is the robust fix; the same caching concern applies
to `config/session.php:48` and `config/modules.php`.

### WR-04: `BackupDatabaseCommand` smart-skip can be defeated by a partially-written sidecar

**File:** `Modules/Core/Internal/Console/BackupDatabaseCommand.php:309-339`

**Issue:** `isSkippable()` picks the newest `*.meta.json` and skips the backup
when its `data_version` matches the live DB. `writeSidecar()` writes the sidecar
atomically (tmp + rename), so a *crashed* `db:backup` cannot leave a torn
sidecar — good. But the JSON read path (`file_get_contents` at line 330) does
not verify the matching `.sqlite` backup file still exists. If an operator (or
the retention pruner of a *different* tool, or a manual `rm`) deletes a
`diederik-*.sqlite` file but leaves its `.meta.json` sidecar, `isSkippable()`
returns `true` and `db:backup` skips — so the system now has *zero* on-disk
backups for that data_version while reporting "Skipped — no commits since last
backup". `pruneRetention()` (lines 401-428) deletes the sidecar alongside the
daily it prunes, so the normal path stays consistent, but the skip predicate
trusts the sidecar as a proxy for a file it never stats.

**Fix:** In `isSkippable()`, after selecting `$newest`, derive the backup
filename by stripping `.meta.json` and require `is_file()` on the backup itself
before honouring the data_version match:

```php
$backupFile = substr($newest, 0, -strlen('.meta.json'));
if (! is_file($backupFile)) {
    return false; // sidecar orphaned — not safe to skip
}
```

### WR-05: `RestoreDatabaseCommand` leaves the live DB connection purged on early-return failure paths

**File:** `Modules/Core/Internal/Console/RestoreDatabaseCommand.php:159-196`

**Issue:** Line 159 calls `$this->db->purge('sqlite')` to release the live PDO
handle before the file copy. If `Filesystem::copy` returns `false` (line 164) or
the post-swap integrity check fails (line 184), the command returns `FAILURE`
with the `sqlite` connection purged and the live database file in an unknown
state (a failed copy may have truncated or partially overwritten it). The
`finally` block only toggles maintenance mode; it does not restore a usable
connection or surface guidance to re-purge. Any subsequent in-process artisan
call that reuses the same kernel (the test harness, or a scripted multi-command
run) gets a fresh connection against a possibly-corrupt file with no WAL pragmas
re-applied until `ConnectionEstablished` fires. The recorded alert tells the
operator about the pre-restore snapshot, which is the right recovery artefact,
but the command's own process is left holding a half-broken connection.

**Fix:** This is acceptable for the documented "operator inspects manually"
posture, but make it explicit: in the copy-failure and post-swap-failure
branches, log that the live DB may be inconsistent and that the operator must
restore from the pre-restore snapshot before bringing the app up. Consider
attempting an automatic copy-back of the pre-restore snapshot on copy failure
(the snapshot is known-good and was just written) so a transient copy error
self-heals instead of requiring manual intervention.

## Info

### IN-01: Migration uses `octal 0600` literal instead of `0o600` used elsewhere

**File:** `Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php:48`

**Issue:** `$files->chmod($backup, 0600)` uses the legacy `0600` octal form
while every other file in this phase (`BackupDatabaseCommand` `0o600`,
`EmlBlobStore` `0700`/`0600` constants, `backupsDir()` `0o755`) is inconsistent
about it. `0600` and `0o600` are numerically identical, but mixed styles in a
single phase invite a future reader to misread `0755` as decimal.

**Fix:** Standardise on the PHP 8.1 `0o` prefix project-wide (or pick one and
note it in conventions). Cosmetic.

### IN-02: Migration's `chmod` return value is unchecked

**File:** `Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php:48`

**Issue:** `$files->chmod($backup, 0600)` ignores its return value. If the chmod
fails (filesystem that rejects mode changes, permission boundary), the legacy
secrets file — which contained OAuth client secrets and refresh tokens — is left
renamed but at whatever mode `move()` produced, potentially group/world
readable. `BackupDatabaseCommand::writeSidecar()` (line 373) treats a failed
chmod on a secret file as fatal; this migration treats it as best-effort. The
`.bak` file is "never read by the app" but it still contains live secrets until
the operator deletes it.

**Fix:** Check the return value and at minimum surface a warning, or delete the
`.bak` artefact if it cannot be locked down to 0600 — consistent with
`BackupDatabaseCommand`'s "chmod failure makes the file unsafe to retain" stance.

### IN-03: `recordCorruptAlert` message uses `$this->files->exists($suspectPath)` re-check that can race

**File:** `Modules/Core/Internal/Console/BackupDatabaseCommand.php:449-451`

**Issue:** The alert message branches on `$suspectPath !== null && $this->files->exists($suspectPath)`.
In the post-VACUUM integrity-check branch the caller has *just* moved the file to
`.suspect` (line 179) and passes a non-null path, so the `exists()` re-check is
redundant there; in every other branch `$suspectPath` is already `null`. The
re-check guards a case that cannot occur (non-null path with missing file) and
adds a filesystem stat to an error path. Harmless but dead-ish defensive code.

**Fix:** Drop the `exists()` re-check and branch on `$suspectPath !== null`
alone, or document why a non-null-but-missing path is considered reachable.

### IN-04: `bin/check-paths.sh` literal regex can false-negative on concatenated literals

**File:** `bin/check-paths.sh:26`

**Issue:** The literal scan greps for `['\"](database\.sqlite|storage/app/)`.
A hard-coded path written as `'storage'.'/app/'` or `"storage/" . "app"` (string
concatenation) slips past the single-literal pattern, as does `'database' . '.sqlite'`.
The authoritative arch test (`BoundaryArchTest.php:1148`) shares the same
limitation. This is a fast pre-flight gate, not a proof, so the gap is
acceptable — but worth noting that the gate cannot catch an adversarially or
accidentally split literal.

**Fix:** None required for v1; optionally note in the script header that the
literal scan assumes un-concatenated string literals and the arch test plus code
review are the backstop.

---

_Reviewed: 2026-05-20T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
