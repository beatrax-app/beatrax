# Operator recovery

Operational runbook for self-hosted Beatrax deployments — backups, restore,
corrupt-backup remediation, failed-jobs maintenance, and stuck-lock recovery.

This document is the authoritative source for operational procedures. The
public-facing README points contributors and end-users here for anything
beyond "install + use the app".

## Backups

The supported backup path is `php artisan db:backup`. It runs SQLite
`VACUUM INTO` against the live database, writes the result to
`storage/app/backups/beatrax-YYYY-MM-DD-HHMMSS.sqlite` at mode `0600`,
verifies the output with a fresh-PDO `PRAGMA integrity_check`, drops a
matching `.meta.json` sidecar carrying the finished copy's SHA-256
(`content_sha256`), its start and completion timestamps and its integrity
verdict, and prunes the directory to the retention window described below. The
`VACUUM INTO` mechanic is safe against a running app — WAL writes from
the web request continue while the backup is taken.

### Daily schedule

`db:backup` runs once a day under the schedule entry `db.backup-daily`.
It used to name `03:00`; the phone's background runner takes a repeat
interval and no wall clock, so that hour meant the one device which may
hold the only copy of the data never backed it up at all. The entry is wrapped with `withoutOverlapping(60)`,
so a crashed run leaves a lock row in the `cache` table that releases
itself 60 minutes later — the lock TTL is short on purpose, much
shorter than the 24h default, because a backup that has been running
longer than an hour is anomalous. If a lock outlives a crashed run,
see "Stuck withoutOverlapping lock" below for the manual release.

### Manual run

Two modes:

```sh
# Smart-skip mode (default). Hashes the copy it just produced and
# compares it against the newest sidecar's content_sha256; exits 0 with
# "Skipped — the database is unchanged since the last backup" when they
# match. Decided on the finished copy, not the live file: VACUUM rebuilds
# pages in a canonical order, so equal data gives an equal digest, while
# the live file and its write-ahead log churn on every checkpoint.
php artisan db:backup

# Always-run mode. Bypasses the content-digest gate, so a quiet day still
# writes a fresh sidecar and no false backup_overdue banner appears. This
# is the mode the daily schedule runs in.
php artisan db:backup --force
```

### Retention

After every successful run the command prunes
`storage/app/backups/` so the directory keeps:

- 7 daily `beatrax-*.sqlite` files (the 7 most-recent dated files);
- the 4 most-recent **Sunday-dated** `beatrax-*.sqlite` snapshots
  (the project's week anchor; the same Sunday rule the dashboard's
  "this period" tile uses);
- every `.suspect` file (produced when a backup fails integrity
  check; never pruned automatically — the operator inspects and
  deletes manually);
- every `pre-restore-*.sqlite` snapshot (written by `db:restore`
  before swapping; never pruned automatically — the operator deletes
  once confident in the restore).

Steady-state disk usage on a sub-100MB DB is bounded at roughly
`(7 + 4)` daily-sized files plus any `.suspect` or `pre-restore-*`
artifacts the operator chose to keep.

### Verifying a backup

The integrity check `db:backup` runs is the same one available
ad-hoc through the SQLite CLI. To re-verify a file after the fact:

```sh
sqlite3 storage/app/backups/beatrax-2026-05-20-030000.sqlite "PRAGMA integrity_check;"
# Expected output: ok
```

Any other output means the file is unsafe to restore — keep it as
evidence (rename to `.suspect` if it is not already), open the matching
`.meta.json` sidecar to see when the file was written, and run a fresh
`db:backup --force` to produce a new known-good snapshot.

### DO NOT cp database.sqlite

A plain `cp` of the live `database.sqlite` file to a backup path is
unsafe. SQLite runs in WAL mode here, and the live file is only half
the database state — the `.sqlite-wal` and `.sqlite-shm` sidecar files
hold pages that have been written but not yet checkpointed back into
the main file. A plain file copy captures the main file at one moment
and the sidecars at another, producing a snapshot the database engine
considers inconsistent. `VACUUM INTO` (the mechanism behind
`db:backup`) is the supported way to produce a consistent copy while
the app is running.

If `db:backup` itself ever fails to run (a PHP version swap mid-flight,
missing storage permissions, disk full), stop the app before any
manual copy: `php artisan down`, copy the `.sqlite`, `.sqlite-wal`,
and `.sqlite-shm` files together as a unit, then `php artisan up`.

## Operator recovery

### Stuck Redis unique-lock keys

When a queue worker crashes mid-job, the per-user unique-lock key
`unique-lock:resolve-chain-links:{userId}` may remain in Redis and block the
next chain-resolver job dispatch for that user. The lock has a 600-second TTL
so it self-clears, but pre-TTL recovery is sometimes desirable (e.g. after a
worker SIGKILL during development).

List stuck locks:

```sh
docker exec beatrax-redis redis-cli KEYS '*unique-lock:resolve-chain-links:*'
```

Clear a specific lock:

```sh
docker exec beatrax-redis redis-cli DEL '<key>'
```

Clear every chain-resolver lock (use with care — only when no jobs are mid-run;
running this while a worker holds a lock will let a second dispatch enter the
critical section):

```sh
docker exec beatrax-redis redis-cli --scan \
  --pattern 'unique-lock:resolve-chain-links:*' \
  | xargs -I {} docker exec beatrax-redis redis-cli DEL {}
```

A future artisan command (`chains:clear-stuck-locks --user=<id>`) backed by an
injected `Cache::driver('redis')` repository may wrap this; for now the
`redis-cli` recipes above are the supported recovery path.

### Restoring from a backup

The `db:restore` command is destructive — it replaces the contents of the
live DB. Three safety rails are mandatory:

1. The app must be in maintenance mode (`php artisan down`) so no web
   request can write to the live DB during the swap.
2. The source `.sqlite` file must pass `PRAGMA integrity_check`
   BEFORE the swap.
3. The operator must confirm — either by passing `--confirm` (for
   scripted use) or by answering `y` to the TTY prompt.

The recipe:

```sh
# 1) Bring the app down. Skip if you are using --force-maintenance.
php artisan down

# 2) Restore. The command takes a pre-restore snapshot of the CURRENT
#    live DB BEFORE the swap, writing it to
#    storage/app/backups/pre-restore-YYYY-MM-DD-HHMMSS.sqlite at 0600.
#    If anything goes wrong, that snapshot is your undo button.
php artisan db:restore --confirm storage/app/backups/beatrax-2026-05-20-030000.sqlite

# 3) Confirm the restored DB's PRAGMAs match config.
php artisan beatrax:doctor

# 4) Bring the app back up. Skip if you used --force-maintenance —
#    the command brings the app up itself on success.
php artisan up
```

Script-friendly variant (single invocation, brings the app down and
back up automatically):

```sh
php artisan db:restore --confirm --force-maintenance \
  storage/app/backups/beatrax-2026-05-20-030000.sqlite
```

If the post-swap integrity check fails, the command leaves the app in
maintenance mode and prints the path to the `pre-restore-*.sqlite`
snapshot. To undo: run `db:restore --confirm` against that snapshot,
then `php artisan up`.

#### Why the swap is not a file copy

`db:restore` writes the source's pages **into** the live database through
SQLite's own backup API, after dropping every open connection that names
that file — it does not copy the source over it. `php artisan down`
refuses HTTP requests; it does not close the desktop server's own handle,
and it does not stop the sync daemon or the queue worker at all. With any
one of those still holding the file, its `-wal` sidecar survives the swap,
and the next reader recovers that write-ahead log straight back over the
restored pages. Measured: 501 rows of the OLD database, exit code 0,
"Restore complete", and a post-swap `PRAGMA integrity_check` of `ok` on
the pages it had just replayed. Not one byte of the backup survived.

### Corrupt-backup alert

A row of `kind=backup_corrupt` and `severity=critical` lands in the
`system_alerts` table whenever a backup or restore run fails. The
persistent banner on every authenticated page surfaces the row in rose
until the operator acknowledges it.

One kind covers four different failures, so `metadata.cause` says which —
`Modules\Core\Internal\Enums\BackupFailureCause`, and the banner picks
its sentence from it:

| `cause` | what happened |
|---|---|
| `source_unreadable` | The live database could not be opened, or `VACUUM INTO` refused it. Nothing usable was produced. |
| `copy_suspect` | A copy was produced and kept as `.suspect` because it did not verify. |
| `write_failed` | The database is sound. Writing the copy, narrowing its mode, promoting it, or writing its sidecar failed — check free space and permissions. |
| `restore_failed` | A restore did not complete. `metadata.pre_restore_snapshot` is the undo. |

Before the cause was recorded, the banner chose between two sentences on
whether a `.suspect` file existed, and three of those four producers
landed on *"aborted before any file was produced — source DB failed
integrity check."* One of them was the sidecar-write failure, which
reaches that branch with a **verified backup sitting on disk**. A row
written by an older build carries no `cause` and still reads the way it
always did.

What it means:

- A `.suspect` file lives in `storage/app/backups/` — the failed
  backup is preserved on disk for inspection, never pruned by the
  retention sweep.
- The matching `system_alerts.metadata.suspect_path` JSON field
  records the exact path. The banner copy also names the file.

To inspect and resolve:

```sh
# Find the suspect file and run the integrity check yourself.
ls -la storage/app/backups/*.suspect
sqlite3 storage/app/backups/beatrax-2026-05-20-030000.sqlite.suspect "PRAGMA integrity_check;"

# Either output reveals genuine corruption (the file is genuinely
# unsafe; investigate the source DB) or a transient write-time
# failure (re-run db:backup --force).
php artisan db:backup --force

# Once you have a clean replacement backup, delete the .suspect file
# manually. The retention sweep never touches it.
rm storage/app/backups/beatrax-2026-05-20-030000.sqlite.suspect
```

Dismiss the banner by clicking "Mark as resolved" on the rose banner
row. The row is stamped with `acknowledged_at` but kept in
`system_alerts` for audit; the banner stops surfacing it on subsequent
page loads.

### Failed-jobs maintenance

The `failed_jobs` table grows over time as Horizon retries exhaust.
The `beatrax:failed-jobs prune` command trims it:

```sh
# Preview — prints up to 50 candidate rows and a footer count,
# writes nothing.
php artisan beatrax:failed-jobs prune --older-than=30d --dry-run

# Apply — deletes every failed_jobs row older than the duration.
php artisan beatrax:failed-jobs prune --older-than=30d
```

The `--older-than=` token accepts `d` (days), `h` (hours), and `w`
(weeks). `m` is rejected on purpose: across SI-style short durations
it is ambiguous between minutes and months. Default is `30d`.

For an overall queue-health view, `php artisan beatrax:doctor`
includes the WAL + synchronous + backup-freshness probes alongside the
existing version checks.

### Stuck withoutOverlapping lock

If a `db:backup` process is SIGKILL'd, Laravel's
`withoutOverlapping(60)` lock row stays in the `cache` table and
blocks subsequent scheduled runs for up to 60 minutes. The TTL is
deliberately short so the system self-heals, but pre-TTL recovery is
useful (e.g. after a PHP version swap mid-run).

Find the lock entry and clear it:

```sh
# List schedule-mutex rows currently held in the cache table.
sqlite3 database/database.sqlite "SELECT key FROM cache WHERE key LIKE 'framework/schedule-%';"

# Drop the specific lock once you have the key — substitute the
# row's key here.
php artisan cache:forget "framework/schedule-<hash-from-the-row-above>"
```

Either approach unblocks the next scheduled run. Waiting the 60
minutes out is also fine.
