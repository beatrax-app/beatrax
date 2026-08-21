# Creating the SQLite file before the container boots

Every beatrax root — the desktop root at `bootstrap/app.php` and the mobile
root at `mobile-app/bootstrap/app.php` — opens with a `->booting()` hook that
does nothing but make sure an **empty** SQLite file exists on disk. It looks
like defensive clutter in the middle of an otherwise declarative bootstrap
file. It is not: without it the desktop packaging step aborts and a genuine
fresh install throws on its first request.

## The problem

Laravel's SQLite connector does **not** create a missing database file. It
throws:

```
Database file … does not exist.
```

So something has to put the file there first. The obvious candidate — the
migrator — is too late, and that is the part worth understanding.

## Why leaving it to the migrator does not work

`SqliteOptimizationsProvider` applies the substrate pragmas
(`journal_mode = WAL`, `synchronous = NORMAL`, `busy_timeout`, foreign keys)
by listening for Laravel's `ConnectionEstablished` event. That event fires the
first time *anything* opens the connection, and on both roots that happens
during **provider boot** — before `artisan migrate` runs, before
`FirstLaunchBootstrap` runs, before a route is matched.

So the ordering is fixed and cannot be rearranged:

1. `->booting()` — the file must exist by the end of this hook.
2. Provider boot — `SqliteOptimizationsProvider` opens the connection and sets
   the pragmas. Throws here if the file is missing.
3. `->booted()` / `FirstLaunchBootstrap` — the migrator creates the schema
   inside the file that now exists.

The hook therefore guarantees an **empty file the migrator can populate**, and
nothing more. It must never seed and never migrate: at step 1 the container is
only half-built.

## What the hook does

```php
$dbFile = UserDataPathService::databaseFile();
$dbDir  = dirname($dbFile);
if (! is_dir($dbDir))      { @mkdir($dbDir, 0775, true); }
if (! file_exists($dbFile)) { @touch($dbFile); @chmod($dbFile, 0600); }
```

The path comes from
[`UserDataPathService::databaseFile()`](../features/core/architecture.md), which
is the only sanctioned reader of `database_path()` and which resolves
differently per platform: `NATIVEPHP_STORAGE_PATH` on the packaged desktop
build, the sibling `persisted_data` store on mobile, and the repo tree in local
development.

The `chmod(0600)` is applied on the desktop root the moment the file is created
— **before the migrator writes anything into it**. The database holds every
transaction, balance and account number in plaintext; the process umask would
otherwise commonly leave it at `0644`, world-readable. The permission is set
once, at creation, because after that first write there is no moment where the
file is guaranteed to be empty.

The hook fires on **every** boot. That is intentional and harmless: in local
development the directory and file already exist, so both branches no-op.

## The two callers that depend on it

**Desktop packaging.** `php artisan native:build` copies the working tree, then
runs `composer install`, whose `post-autoload-dump` script runs
`package:discover` — which boots the whole application inside the copied build
tree. `database/*.sqlite` is stripped from that copy by
`cleanup_exclude_files` (see
[desktop build file exclusions](../features/desktop/build-file-exclusions.md)),
so the file genuinely is not there. Without this hook the build aborts at
discovery, long before electron-builder ever gets to sign anything.

**A genuine fresh install.** On a newly installed desktop bundle
`databaseFile()` resolves to the writable `NATIVEPHP_STORAGE_PATH` root, where
no file exists yet. The hook creates it; `FirstLaunchBootstrap` then migrates
into it (see [desktop architecture](../features/desktop/architecture.md)).

## The mobile mirror

`mobile-app/bootstrap/app.php` carries the same hook for the same reason, with
one difference in emphasis: on mobile the missing piece is usually the
**directory**, not the file, because `databaseFile()` targets a sibling
`persisted_data` store that the native layer provisions outside the Laravel
tree. The failure without it is a one-time, self-healing but noisy cold-boot
error rather than a hard build abort. Mobile does not chmod the file — the app
sandbox already isolates it.

See [mobile architecture](../features/mobile/architecture.md) for how
`isMobileRuntime()` decides which path `databaseFile()` returns.
