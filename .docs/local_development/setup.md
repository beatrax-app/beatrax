# Setup

How to get a working development copy of Beatrax. The expected end state is the
dashboard rendering locally, with the test suite green and the desktop bundle
buildable on demand.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) — the PHP 8.5 toolchain is defined in
  `docker-compose.yml` and `docker/php8.5/Dockerfile`. Everything PHP-related runs via
  `docker compose run --rm php …`, so PHP and Composer do not need to be on the host.
- Node 22 or later, with `npm` — the version in `.nvmrc`, which is also what CI runs.
  The Docker image does not bundle Node.
- `git`, with commit signing configured if you intend to push.

The project is pinned to PHP 8.5 for development; the Docker image targets exactly that
version, so the toolchain is reproducible across machines.

## First-time clone

```sh
git clone git@github.com:beatrax-app/beatrax.git
cd beatrax

# Build the dev PHP 8.5 image
docker compose build

# Install PHP dependencies (development mode includes Larastan, Pint, Pest)
docker compose run --rm php composer install

# Install frontend dependencies (Tailwind v4, Vite, Alpine, ApexCharts)
npm ci

# Create the per-environment .env from the example template
cp .env.example .env
docker compose run --rm php php artisan key:generate
```

The repo is bind-mounted into the container, so edits on the host are picked up
immediately and `vendor/` written inside the container lands back on the host.

## Initialise the database

The project ships SQLite as the only supported database, in a single file at
`database/database.sqlite`.

> **Every data command through Docker needs `-e DB_CONNECTION=sqlite`.**
>
> `docker-compose.yml` sets `DB_CONNECTION: sqlite_testing` on the container, because
> the test suite needs a real environment variable to beat `phpunit.xml`'s `<env>`. That
> connection is `:memory:`. Without the override, `migrate` and `beatrax:install`
> **appear to succeed** — they print the full migration list, they report the user they
> created — and then the container exits and every byte of it is gone. What you are left
> with is a zero-byte `database/database.sqlite` and the impression that you are set up.
>
> The first honest error arrives later, from `demo:seed`: *no such table: users*.

```sh
# Create the empty SQLite file (Laravel's migrate command does not create it for you)
touch database/database.sqlite

# Apply every migration
docker compose run --rm -e DB_CONNECTION=sqlite php php artisan migrate

# Migrate, seed the currency table, create the owner account, dispatch UserInstalled
docker compose run --rm -e DB_CONNECTION=sqlite php php artisan beatrax:install \
    --username=dev --password='choose-something-better'
```

`--username` and `--password` are only needed when running non-interactively, which is
what `docker compose run` does by default; omit them and the command refuses with
*"username is required"* rather than prompting. `--period-start-day` defaults to 1.

Confirm it actually landed, rather than trusting the output:

```sh
sqlite3 database/database.sqlite "select count(*) from sqlite_master where type='table';"
# 98 or thereabouts. Zero means the override was missing.
```

## A demo dataset to look at

An empty install is hard to judge. `demo:seed` fills it with a representative ledger —
two personas with their own accounts and transactions, plus chains, recurring series,
forecasts, drift and anomaly alerts, notifications, and a rebuilt search index. It
prints a running count of every kind of row it seeds, so read the output rather than
this page for the sizes:

```sh
docker compose run --rm -e DB_CONNECTION=sqlite php php artisan demo:seed --reset
```

It finishes by printing the login it created (`demo-1`). `--reset` clears
any previous demo rows first, so it is safe to re-run.

`beatrax:install` is idempotent. It refuses outright if the database path resolves
inside a cloud-sync folder, runs the migrations, seeds the currency reference table, and
ensures the owner user exists — creating one with a prompted password if not, and
leaving an existing password alone if so. Re-running it re-dispatches `UserInstalled`,
which is how the seed listeners heal missing reference data.

It sets no PRAGMAs. WAL and `synchronous=NORMAL` are declared in `config/database.php`
and applied by `SqliteOptimizationsProvider` on Laravel's `ConnectionEstablished` event,
which fires during provider boot — before any command runs. See [creating the SQLite
file before the container boots](../architecture/sqlite-file-precreation.md).

`--launchd` is a separate mode rather than an extra step: it runs no migrations, seeds
nothing and creates no account. On macOS it writes and bootstraps
`~/Library/LaunchAgents/com.beatrax.horizon.plist`, `…scheduler.plist` and
`…redis.plist`, with `--without-redis` dropping the third; on any other OS it errors out
and stops.

## Build the frontend

For day-to-day development:

```sh
npm run dev
```

Vite watches every Blade view and JS / CSS entry; Tailwind v4's oxide engine recompiles
the stylesheet in roughly 100 ms.

For a production-shaped build (faster page loads, no source maps):

```sh
npm run build
```

## Run the test suite

```sh
# Full suite
docker compose run --rm php php artisan test --parallel

# Just architectural invariants
docker compose run --rm php php artisan test --testsuite=Contracts

# Single file
docker compose run --rm php php artisan test tests/Contracts/CommentPolicyArchTest.php

# Pint formatting check
docker compose run --rm php vendor/bin/pint --test

# Larastan level 10 strict
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=1G
```

The PR gate runs those same checks on every push, plus `composer analyse:deps`, and all
of it on PHP 8.5 — there is no second version axis to be caught out by. What does differ
is shape: CI shards the Pest run across three runners and each shard fans out with
`--parallel` over that runner's cores, where the command above runs the whole suite in
one place. Green locally usually means green in CI. The release pipeline runs the same
gate before it builds anything; see
[`70-operations/releasing.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md).

## Build the desktop bundle locally

The shipped app is packaged via NativePHP (Electron under the hood). To produce a local
`.dmg`:

```sh
# One-time: install Electron build tooling
npm ci

# Build the macOS bundle. The `prebuild` hooks in config/nativephp.php run
# first, staging the brand assets and patching the Electron scaffold.
php artisan native:build mac
```

The resulting `.dmg` lands under `build/`. A LOCAL build is ad-hoc-signed — the
Developer ID credentials live in CI, not on a developer machine — so launching it
requires a one-time right-click → Open → "Open Anyway".

That friction is local only. Released macOS builds are Developer ID signed and
notarised by the release workflow, so end users do not see it. If you need a signed
bundle locally, set `NATIVEPHP_MAC_IDENTITY` to the Developer ID identity in your
keychain before building; see
[`../runbooks/repo-security-setup.md`](../runbooks/repo-security-setup.md#release-signing).

For Windows and Linux targets, run `php artisan native:build win` or `native:build
linux` on the corresponding OS. Cross-platform builds from macOS are not supported by
the underlying tooling.

## The mobile shell

`mobile-app/` is a **second Composer root**, not a subdirectory of the first. It has its
own `vendor/`, and it depends on `nativephp/mobile` where the repo root depends on
`nativephp/desktop` — the two host packages hard-conflict, which is the whole reason
for the split. The domain code is shared into it by symlink — `app/`, `Modules/`,
`resources/`, `routes/`, `public/` and `tests/` are each a link back to the repo root,
so a change to a module is picked up by both roots with no copying. `database/` is
**not** among them: `mobile-app/database/` is a real directory that links only
`migrations/` and `schema/`, so the two roots share the schema and keep their own
database file. Why each root needs its own file, and why each `bootstrap/app.php`
creates it before the container boots, is in [creating the SQLite file before the
container boots](../architecture/sqlite-file-precreation.md#the-mobile-mirror).

Working on it needs its own install:

```sh
cd mobile-app
composer install
cp ../.env.example .env
php artisan key:generate
```

### The plugin credentials

The NativePHP **mobile** plugins are a paid distribution behind HTTP basic auth, so
`composer install` in `mobile-app/` fails with a 401 until Composer knows the licence:

```sh
composer config --global http-basic.plugins.nativephp.com <licence-email> <licence-key>
```

`--global` keeps it in `~/.composer/auth.json` and out of the repository. A
project-local `auth.json` is gitignored precisely so it cannot be committed, but the
global file is the safer habit. CI supplies the same pair through the
`NATIVEPHP_LICENSE_EMAIL` and `NATIVEPHP_LICENSE_KEY` repository secrets.

### Running its tests

The mobile shell has its own Pest testsuite, scoped to the module that owns it:

```sh
cd mobile-app
vendor/bin/pest --testsuite=Mobile --exclude-group=repo-root-only
```

`repo-root-only` marks assertions that resolve paths relative to the repository root —
run from here they would look for `mobile-app/mobile-app/…`. The repo-root suite runs
them; this one skips them.

## A seeded environment to click through

`demo:seed` stands up a realistic dataset — two personas with their own accounts
and transactions, plus chains, recurring series, forecasts, drift alerts,
receipts, goals, pots, cash-book entries, saved reports, anomalies and
notifications — so every surface has something in it. The command reports the
count for each as it goes; that is the number to trust, not one written here.
Two of those accounts are denominated in yen,
which has no minor unit; [what the demo zero-decimal account has to
show](../features/ledger/what-the-demo-zero-decimal-account-has-to-show.md) says
which surfaces that is there to prove.

**Which connection you seed depends on how you are going to run the app**, and
getting it wrong is quiet rather than loud: the seeder reports success, the
file fills up, and the app you launch shows the first-run welcome screen
because it is reading a different database entirely.

```sh
composer dev:seed            # browser / `dev:serve` — the `sqlite` connection
composer dev:seed-desktop    # the NativePHP shell — the `nativephp` connection
composer dev:serve           # artisan serve on 127.0.0.1:8000 + vite in watch mode
```

NativePHP sets `NATIVEPHP_RUNNING=true` when it launches the desktop shell. Grepping the
first-party tree for it finds only the `dev:seed-desktop` script that sets it; the reader
is in `vendor/nativephp/desktop`, where the vendored `NativeServiceProvider` rewrites
`config('database.default')` from `sqlite` to a `nativephp` connection it defines at
runtime. That connection is not in `config/database.php` and never will be, which is why
looking there is a dead end. On a debug build, which a local checkout is, it points at
`database/nativephp.sqlite`. `dev:seed-desktop` sets the same variable through
`@putenv` so the seeder lands where the shell will look.

`NATIVEPHP_STORAGE_PATH` is a different switch and only the packaged shell sets it. It
moves the storage tree, and with it the `sqlite` connection's own file, because
`UserDataPathService::databaseFile()` reads it — but it never changes which connection
is default.

If the desktop app shows the welcome screen on a database you know you seeded,
ask it which connection it is on rather than which file exists:

```sh
NATIVEPHP_RUNNING=true php artisan tinker \
    --execute="echo DB::connection()->getDatabaseName();"
```

On a debug build, asking creates the answer: if `database/nativephp.sqlite` is not
there, the provider touches it and runs `native:migrate` before the echo prints.

Both seeds pass `--reset`, whose teardown is scoped to demo rows only — it
never touches a real user you created by hand, so either is safe to re-run.

Sign in as `demo-1` with the password `demo-only`.

`dev:serve` binds loopback rather than the `.test` host on purpose:
`LoopbackOnly` rejects any request whose `SERVER_ADDR` is not a loopback
address, and serving on 127.0.0.1 is also the closest match to how the mobile
shell reaches the app on-device.

### Running the desktop shell

```sh
php artisan native:run --no-interaction
```

If it dies in `patchPlist()` on a missing `Electron.app/Contents/Info.plist`,
the Electron binary was downloaded but never unpacked — its npm postinstall
exits 0 having extracted only `LICENSES.chromium.html`. Extract the cached zip
by hand into the copy the dev runner actually uses, which is the one under
`vendor/`, not `nativephp/electron/`:

```sh
D=vendor/nativephp/desktop/resources/electron/node_modules/electron
V=$(node -e "console.log(require('./$D/package.json').version)")
rm -rf $D/dist && mkdir -p $D/dist
unzip -q ~/Library/Caches/electron/*/electron-v$V-darwin-arm64.zip -d $D/dist
printf 'Electron.app/Contents/MacOS/Electron' > $D/path.txt
```

Then launch with `ELECTRON_SKIP_BINARY_DOWNLOAD=1`, because `native:run` runs
`npm install` first and that postinstall wipes `dist` again.
