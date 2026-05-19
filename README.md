# diederik

A local-only personal finance dashboard that pulls together transactions from ASN
Bank, ICS Cards, PayPal, and Google Play into a single calm "this month at a
glance" view.

The app resolves the routing chains between these accounts (PayPal → ASN or ICS,
ICS → ASN via bulk iDEAL settlement) so that fixed monthly payments, real
underlying funding sources, and upcoming cash flow are visible in one place
instead of buried across statements.

## Prerequisites

- PHP 8.5
- Composer 2.x
- SQLite 3.45+
- Node.js 20+ (for the Vite asset pipeline)
- macOS recommended; Laravel Herd ships a compatible PHP binary out of the box
- Poppler (`pdftotext` binary) for ICS PDF statement parsing — `brew install poppler` on macOS; verify with `pdftotext -v`. See https://poppler.freedesktop.org/ for source builds on other platforms.
- Docker Desktop (https://www.docker.com/products/docker-desktop/) — used only to run the Redis container that backs the queue.

## Setup

### Redis (loopback Docker container)

The queue driver is Redis and the chain-resolver job uses `ShouldBeUniqueUntilProcessing` semantics that require it. Redis runs as a single named Docker container, bound to `127.0.0.1` only — never `0.0.0.0`. The loopback bind is mandatory; the default `-p 6379:6379` binds every interface and would expose the cache to the LAN.

```sh
docker volume create diederik-redis-data
docker run --name diederik-redis \
  -p 127.0.0.1:6379:6379 \
  -v diederik-redis-data:/data \
  -d redis:7-alpine \
  redis-server --save 60 1
```

Persistence is via the named volume `diederik-redis-data` — no source-tree bind mount, so the Sail-on-Mac bind-mount performance trap does not apply. Verify the bind:

```sh
docker ps --filter "name=diederik-redis" --format "{{.Names}} {{.Ports}}"
# Expected: diederik-redis 127.0.0.1:6379->6379/tcp
```

### PHP / app install

```sh
composer install
pdftotext -v          # confirms poppler is on PATH (required for ICS PDF imports — see Prerequisites)
php artisan migrate
```

### OAuth redirect URI (email ingestion)

When connecting Gmail or Microsoft 365 inboxes, the OAuth dance uses the RFC 8252 loopback IP scheme `http://127.0.0.1:PORT/oauth/callback/{provider}` — never `https://diederik.test`, because Google and Microsoft both reject `*.test` subdomains as redirect URIs. The port is read from `config('app.url')` with a fallback to `8000`. Paste the URI verbatim into the Google Cloud Console / Azure Portal redirect-URI field; the in-app OAuth-client wizard surfaces a copy-to-clipboard button with the exact string.

The email ingestion path depends on these Composer packages, already pinned in `composer.json`:

| Package | Purpose |
|---------|---------|
| `google/apiclient` | Gmail API calls |
| `league/oauth2-client` + `league/oauth2-google` | OAuth dance + token exchange |
| `zbateson/mail-mime-parser` | RFC 822 header parsing on fetched `.eml` blobs |

## Running the app

The app needs two terminals during development:

```sh
# Terminal 1 — HTTP server
php artisan serve --host=127.0.0.1 --port=8080
# or open https://diederik.test if you have Laravel Herd linked

# Terminal 2 — queue worker / Horizon supervisor
php artisan horizon
```

Visit `/horizon` (while authenticated) for queue throughput, runtime, and
failed-job inspection. The auth gate is enforced in
`App\Providers\HorizonServiceProvider`.

The HTTP server, database, and Redis container are all bound to loopback only.
Production cloud deployment is out of scope.

## Background workers (Phase 6+)

### Background workers via macOS launchd

After running `php artisan diederik:install --launchd`, three LaunchAgents are registered:

- `com.diederik.horizon` — runs `php artisan horizon` (queue worker supervisor).
- `com.diederik.scheduler` — runs `php artisan schedule:work` (hourly Gmail/Graph incremental scans + daily discovery).
- `com.diederik.redis` — optional; only installed when you're not running Docker Desktop on login. Otherwise pass `--without-redis`.

The plists run under your user account (no root). All three include `KeepAlive` so they restart on crash. The plists live in version control under `deploy/launchd/` with `{{ABS_PHP_BINARY}}` + `{{ABS_PROJECT_ROOT}}` placeholders; the install command substitutes both before writing the rendered plist to `~/Library/LaunchAgents/` and running `launchctl bootstrap gui/$(id -u)`.

**First-run permission grant**: macOS may prompt for Terminal accessibility / Full Disk Access on the first run; grant in System Settings → Privacy & Security → Accessibility / Full Disk Access.

**Log locations**: `storage/logs/launchd-horizon.log`, `storage/logs/launchd-scheduler.log`, `storage/logs/launchd-redis.log` (plus matching `.err.log` files for stderr).

**Re-run after upgrade**: If Laravel Herd is upgraded (which changes the PHP binary path), re-run `php artisan diederik:install --launchd` so the plists pick up the new binary.

**Verifying the install**:

```sh
launchctl list | grep com.diederik
```

Expected output (with `--without-redis` passed):

```
com.diederik.scheduler
com.diederik.horizon
```

Without `--without-redis`, `com.diederik.redis` appears as a third line.

**Stopping the workers**:

```sh
launchctl bootout gui/$(id -u) ~/Library/LaunchAgents/com.diederik.horizon.plist
launchctl bootout gui/$(id -u) ~/Library/LaunchAgents/com.diederik.scheduler.plist
launchctl bootout gui/$(id -u) ~/Library/LaunchAgents/com.diederik.redis.plist  # if installed
```

## Backups

The supported backup path is `php artisan db:backup`. It runs SQLite
`VACUUM INTO` against the live database, writes the result to
`storage/app/backups/diederik-YYYY-MM-DD-HHMMSS.sqlite` at mode `0600`,
verifies the output with a fresh-PDO `PRAGMA integrity_check`, drops a
matching `.meta.json` sidecar capturing the source `PRAGMA data_version`,
and prunes the directory to the retention window described below. The
`VACUUM INTO` mechanic is safe against a running app — WAL writes from
the web request continue while the backup is taken.

### Daily schedule

`db:backup` runs every day at `03:00` under the schedule entry
`db.backup-daily`. The entry is wrapped with `withoutOverlapping(60)`,
so a crashed run leaves a lock row in the `cache` table that releases
itself 60 minutes later — the lock TTL is short on purpose, much
shorter than the 24h default, because a backup that has been running
longer than an hour is anomalous. If a lock outlives a crashed run,
see "Stuck withoutOverlapping lock" below for the manual release.

### Manual run

Two modes:

```sh
# Smart-skip mode (default). Compares the live PRAGMA data_version
# against the most recent sidecar; exits 0 with "Skipped — no commits
# since last backup" when nothing has been written since.
php artisan db:backup

# Always-run mode. Bypasses the data_version gate. Useful before a
# risky manual operation or when validating that the backup pipeline
# itself still produces a clean file.
php artisan db:backup --force
```

### Retention

After every successful run the command prunes
`storage/app/backups/` so the directory keeps:

- 7 daily `diederik-*.sqlite` files (the 7 most-recent dated files);
- the 4 most-recent **Sunday-dated** `diederik-*.sqlite` snapshots
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
sqlite3 storage/app/backups/diederik-2026-05-20-030000.sqlite "PRAGMA integrity_check;"
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

If `db:backup` itself ever fails to run (Herd PHP swap mid-flight,
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
docker exec diederik-redis redis-cli KEYS '*unique-lock:resolve-chain-links:*'
```

Clear a specific lock:

```sh
docker exec diederik-redis redis-cli DEL '<key>'
```

Clear every chain-resolver lock (use with care — only when no jobs are mid-run;
running this while a worker holds a lock will let a second dispatch enter the
critical section):

```sh
docker exec diederik-redis redis-cli --scan \
  --pattern 'unique-lock:resolve-chain-links:*' \
  | xargs -I {} docker exec diederik-redis redis-cli DEL {}
```

A future phase may wrap the equivalent through an artisan command
(`chains:clear-stuck-locks --user=<id>`) backed by an injected
`Cache::driver('redis')` repository. For now, the `redis-cli` recipes above are
the supported recovery path.

### Restoring from a backup

The `db:restore` command is destructive — it overwrites the live DB
file. Three safety rails are mandatory:

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
php artisan db:restore --confirm storage/app/backups/diederik-2026-05-20-030000.sqlite

# 3) Confirm the restored DB's PRAGMAs match config.
php artisan diederik:doctor

# 4) Bring the app back up. Skip if you used --force-maintenance —
#    the command brings the app up itself on success.
php artisan up
```

Script-friendly variant (single invocation, brings the app down and
back up automatically):

```sh
php artisan db:restore --confirm --force-maintenance \
  storage/app/backups/diederik-2026-05-20-030000.sqlite
```

If the post-swap integrity check fails, the command leaves the app in
maintenance mode and prints the path to the `pre-restore-*.sqlite`
snapshot. To undo: run `db:restore --confirm` against that snapshot,
then `php artisan up`.

### Corrupt-backup alert

A row of `kind=backup_corrupt` and `severity=critical` lands in the
`system_alerts` table whenever a backup run fails its post-write
integrity check (or whenever `VACUUM INTO` itself refuses a malformed
source). The persistent banner on every authenticated page surfaces
the row in rose until the operator acknowledges it.

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
sqlite3 storage/app/backups/diederik-2026-05-20-030000.sqlite.suspect "PRAGMA integrity_check;"

# Either output reveals genuine corruption (the file is genuinely
# unsafe; investigate the source DB) or a transient write-time
# failure (re-run db:backup --force).
php artisan db:backup --force

# Once you have a clean replacement backup, delete the .suspect file
# manually. The retention sweep never touches it.
rm storage/app/backups/diederik-2026-05-20-030000.sqlite.suspect
```

Dismiss the banner by clicking "Mark as resolved" on the rose banner
row. The row is stamped with `acknowledged_at` but kept in
`system_alerts` for audit; the banner stops surfacing it on subsequent
page loads.

### Failed-jobs maintenance

The `failed_jobs` table grows over time as Horizon retries exhaust.
The `diederik:failed-jobs prune` command trims it:

```sh
# Preview — prints up to 50 candidate rows and a footer count,
# writes nothing.
php artisan diederik:failed-jobs prune --older-than=30d --dry-run

# Apply — deletes every failed_jobs row older than the duration.
php artisan diederik:failed-jobs prune --older-than=30d
```

The `--older-than=` token accepts `d` (days), `h` (hours), and `w`
(weeks). `m` is rejected on purpose: across SI-style short durations
it is ambiguous between minutes and months. Default is `30d`.

For an overall queue-health view, `php artisan diederik:doctor`
includes the WAL + synchronous + backup-freshness probes alongside the
existing version checks.

### Stuck withoutOverlapping lock

If a `db:backup` process is SIGKILL'd, Laravel's
`withoutOverlapping(60)` lock row stays in the `cache` table and
blocks subsequent scheduled runs for up to 60 minutes. The TTL is
deliberately short so the system self-heals, but pre-TTL recovery is
useful (e.g. after a Herd PHP swap mid-run).

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

## CI gates

The three checks must pass on every change:

```sh
vendor/bin/pest                # tests
vendor/bin/phpstan analyse     # static analysis at level max
vendor/bin/pint --test         # code style
```

## Module layout

The codebase lives under `Modules/` with five bounded contexts: `Core`,
`Ledger`, `Ingestion`, `Import`, `Categorization`. Each module exposes a
`Public/` namespace for cross-module callers; everything under `Internal/`
is private. A custom PHPStan rule (`App\PhpStan\Rules\BoundaryRule`) enforces
the boundary in CI.

Constructor dependency injection is the only allowed style. Global helpers
(`auth()`, `config()`, `now()`, …) and facades (`Auth::`, `DB::`, …) are
banned in non-test, non-Routes, non-Migrations code.
