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

## Backups

Plain `cp database.sqlite` is unsafe in WAL mode — the `.sqlite-wal` and
`.sqlite-shm` sidecar files contain uncommitted pages. A `php artisan db:backup`
command using `VACUUM INTO` ships in a later phase (FND-05); until then, stop
the app before any manual backup.

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
