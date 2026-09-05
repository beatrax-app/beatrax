# Database

Beatrax uses SQLite as its only database. The choice is load-bearing: a single file on
disk per machine, no separate service to run, no network port to bind, no client/server
protocol. The trade-off is single-writer concurrency, which WAL mode mitigates to the
point that the local dashboard, the queue worker, the scheduler and the sync daemon can
all run against the same file without contention — four processes on one file is the
desktop's normal shape.

## File location

Two contexts to know about, with two different paths.

### Local dev (development)

The development DB lives at `database/database.sqlite`, relative to the project root.
It is not committed — `.gitignore` excludes both the file itself and its `*-wal` and
`*-shm` sidecars.

### Inside the shipped bundle

The user-data SQLite file lives under the per-OS user-data directory that
`UserDataPathService` resolves at runtime:

| OS | Path |
|---|---|
| macOS | `~/Library/Application Support/beatrax/database.sqlite` |
| Windows | `%APPDATA%\beatrax\database.sqlite` |
| Linux | `~/.config/beatrax/database.sqlite` |

The bundle never writes inside its own install directory. That separation means a
re-install or auto-update never touches user data.

## WAL mode

Both contexts run SQLite in WAL (Write-Ahead Log) mode with `synchronous=NORMAL`. WAL
allows readers to proceed in parallel with a single writer, which is exactly the
Beatrax shape: the web request and the schedule/queue workers all read constantly and
write occasionally.

Nothing sets the PRAGMAs once at install time. `config/database.php` declares them on
the `sqlite` connection, and `SqliteOptimizationsProvider` applies them —
`journal_mode`, `synchronous`, `busy_timeout`, `foreign_keys` and `temp_store` — by
listening for Laravel's `ConnectionEstablished` event. That fires the first time
anything opens the connection, which on both Composer roots happens during provider
boot: before `artisan migrate`, before `FirstLaunchBootstrap`, before a route is
matched. A manually-copied database that arrived in journal mode is therefore upgraded
by the first connection anything makes to it, not by a later command. See [creating the
SQLite file before the container
boots](../architecture/sqlite-file-precreation.md#why-leaving-it-to-the-migrator-does-not-work)
for why the ordering cannot be rearranged.

To inspect the current mode:

```sh
sqlite3 database/database.sqlite "PRAGMA journal_mode; PRAGMA synchronous;"
# Expected output:
#   wal
#   1   (NORMAL)
```

`beatrax:doctor` (run under Dev Mode or directly via artisan) probes the same PRAGMAs
and flags any drift.

## Recommended GUIs

For day-to-day inspection of the local DB:

- **TablePlus** — polished commercial option, free for single-connection use.
- **DBNGIN** — free, ships the same `sqlite3` CLI.
- **`sqlite3` CLI** — bundled with macOS; sufficient for ad-hoc queries.

Avoid the SQLite Browser (`sqlitebrowser.org`) for the user-data DB — it has a habit of
holding write locks longer than expected, which surfaces as "database is locked" errors
inside the running app.

## Schema introspection

The schema is owned by the migration files — each module's own `Database/Migrations/`
directory, plus `database/migrations/` at the root for the framework's queue, cache and
job-batch tables. There is also a squashed dump at `database/schema/sqlite-schema.sql`:
`artisan migrate` loads it first and then runs only the migrations it does not already
account for. A phone does not, because the loader shells out to a `sqlite3` binary the
device does not carry, so `MobileFirstLaunchBootstrap` replays every migration from the
first one instead — see [the migrations only a phone ever
runs](../features/mobile/architecture.md#the-migrations-only-a-phone-ever-runs).

Laravel's `migrate:status` reports the applied state:

```sh
php artisan migrate:status
```

`php artisan db:show` and `db:table {table}` (Laravel 11+) print the live shape of any
table without leaving the terminal.
