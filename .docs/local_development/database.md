# Database

Beatrax uses SQLite as its only database. The choice is load-bearing: a single file on
disk per machine, no separate service to run, no network port to bind, no client/server
protocol. The trade-off is single-writer concurrency, which WAL mode mitigates to the
point that the local dashboard, the queue worker, and the IMAP-idle worker can all run
against the same file without contention.

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

The `beatrax:install` artisan command sets the PRAGMAs once at install time. The
`FirstLaunchBootstrap` re-asserts them on every bundle boot, so a manually-copied
database that arrived in journal mode silently upgrades on next launch.

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

The schema is owned by the migration files under each module's `Database/Migrations/`
directory. There is no central schema dump file; Laravel's `migrate:status` reports the
applied state:

```sh
php artisan migrate:status
```

`php artisan db:show` and `db:table {table}` (Laravel 11+) print the live shape of any
table without leaving the terminal.
