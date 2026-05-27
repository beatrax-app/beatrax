# Setup

How to get a working development copy of beatrax on a macOS machine. The expected end
state is the dashboard rendering at `https://beatrax.test/` under Herd, with the test
suite green and the desktop bundle buildable on demand.

## Prerequisites

- macOS (Apple Silicon or Intel both work).
- [Laravel Herd](https://herd.laravel.com) — the free tier is sufficient. Herd provides
  PHP, nginx, dnsmasq, and the `*.test` HTTPS routing.
- Node 20 LTS or later, with `npm`. Herd does not bundle Node.
- `git`, with commit signing configured if you intend to push.

The project is pinned to PHP 8.5 for development. Herd ships multiple PHP versions side
by side; pick 8.5 as the project's PHP via Herd's UI or the per-directory `.herd`
shortcut.

## First-time clone

```sh
cd ~/Herd
git clone git@github.com:nightworksio/beatrax.git
cd beatrax

# Install PHP dependencies (development mode includes Larastan, Pint, Pest)
composer install

# Install frontend dependencies (Tailwind v4, Vite, Alpine, ApexCharts)
npm ci

# Create the per-environment .env from the example template
cp .env.example .env
php artisan key:generate
```

Herd auto-routes `~/Herd/beatrax` to `https://beatrax.test/` without further
configuration — the dnsmasq + nginx layer detects the directory on disk.

## Initialise the database

The project ships SQLite as the only supported database. Migrations run against a single
file at `database/database.sqlite`:

```sh
# Create the empty SQLite file (Laravel's migrate command does not create it for you)
touch database/database.sqlite

# Apply every migration
php artisan migrate

# Optional: enable WAL mode for the local DB — see database.md
php artisan beatrax:install
```

`beatrax:install` is idempotent. It enables WAL mode + `synchronous=NORMAL` on the
SQLite file, ensures the owner user exists (creating one with a prompted password if
not), and registers the launchd plist files for the scheduler, queue worker, and
IMAP-idle worker if you opt in.

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
php artisan test --parallel

# Just architectural invariants
php artisan test --testsuite=Arch

# Single file
php artisan test tests/Contracts/GsdLeakageTest.php

# Pint formatting check
vendor/bin/pint --test

# Larastan level 10 strict
vendor/bin/phpstan analyse --memory-limit=1G
```

The PR gate runs the same three commands on every push. Green locally usually means
green in CI; the two known divergences (PHP 8.4 vs 8.5 axis, and CI's stricter
`fail-fast` posture for release builds) are documented in
[`../cicd/release-workflow.md`](../cicd/release-workflow.md).

## Build the desktop bundle locally

The shipped app is packaged via NativePHP (Electron under the hood). To produce a local
`.dmg`:

```sh
# One-time: install Electron build tooling
npm ci

# Stage the brand assets the prebuild hook expects
php artisan native:prebuild

# Build the macOS bundle
php artisan native:build mac
```

The resulting `.dmg` lands under `build/`. The bundle is ad-hoc-signed (no paid Apple
Developer ID is used for local builds), so launching it requires a one-time
right-click → Open → "Open Anyway" — the same friction end users will see on the
first install of a published release.

For Windows and Linux targets, run `php artisan native:build win` or `native:build
linux` on the corresponding OS. Cross-platform builds from macOS are not supported by
the underlying tooling.
