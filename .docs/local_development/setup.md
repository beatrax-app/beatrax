# Setup

How to get a working development copy of beatrax. The expected end state is the
dashboard rendering locally, with the test suite green and the desktop bundle
buildable on demand.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) — the PHP 8.5 toolchain is defined in
  `docker-compose.yml` and `docker/php8.5/Dockerfile`. Everything PHP-related runs via
  `docker compose run --rm php …`, so PHP and Composer do not need to be on the host.
- Node 20 LTS or later, with `npm`. The Docker image does not bundle Node.
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

The project ships SQLite as the only supported database. Migrations run against a single
file at `database/database.sqlite`:

```sh
# Create the empty SQLite file (Laravel's migrate command does not create it for you)
touch database/database.sqlite

# Apply every migration
docker compose run --rm php php artisan migrate

# Optional: enable WAL mode for the local DB — see database.md
docker compose run --rm php php artisan beatrax:install
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
docker compose run --rm php php artisan test --parallel

# Just architectural invariants
docker compose run --rm php php artisan test --testsuite=Arch

# Single file
docker compose run --rm php php artisan test tests/Contracts/GsdLeakageTest.php

# Pint formatting check
docker compose run --rm php vendor/bin/pint --test

# Larastan level 10 strict
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=1G
```

The PR gate runs the same three commands on every push. Green locally usually means
green in CI; the two known divergences (PHP 8.4 vs 8.5 axis, and CI's stricter
`fail-fast` posture for release builds) are documented in
[`../cicd/release-workflow.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md).

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
