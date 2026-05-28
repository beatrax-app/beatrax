# Stack rationale

Why each load-bearing dependency was picked over its obvious alternative, and
the three v1.0 → desktop-bundle stack flips that constrain shipped builds.

This file supplements the [Architecture Decision Records](../adr/) — the ADRs
record what was chosen and what that commits us to; this file records the
specific alternatives evaluated and why each was rejected at the time of the
decision. When an alternative becomes attractive again (a maintainer landed
on a stagnant library, a new release closed a known gap), the corresponding
ADR is the place to revisit; this file is reference-only.

## Language and framework

### PHP 8.5 + Laravel 13

The desktop bundle currently ships PHP 8.4 because the bundled static-PHP
binary distribution (`nativephp/php-bin`) shipped 8.1–8.4 at packaging time;
the development pin stays at 8.5 to track upstream as the binary catches up.
Larastan level 10 strict passes on both, so the version split costs nothing
in practice.

Laravel 13 was released March 2026, two minor versions ahead of the
Livewire 4 + Volt + Flux starter kit baseline. The framework is the
constraint, not the picker.

### Livewire 4 + Volt + Flux UI

Picked over Inertia + React because:

- A finance dashboard is form-heavy and table-heavy. Livewire's component
  model is faster to ship than a React component tree behind an Inertia
  adapter.
- The "calm, content-first" Linear / Notion aesthetic the project targets
  is what Flux UI delivers out of the box. Reskinning Filament's admin-panel
  defaults to look like Linear costs more than building a few custom
  Livewire pages on the starter kit.
- One stack, one PHP-only mental model, no Vite component graph, no
  TypeScript build, no Inertia serialisation layer.

Filament v5 was the obvious "I want a dashboard fast" alternative. Rejected
because the project's calm-shell brief is incompatible with Filament's
sidebar-heavy table-first defaults at the rendered HTML level.

HTMX was rejected because there is no first-class Laravel integration and
no community of Laravel developers writing in that pattern. Livewire is
HTMX-for-Laravel with batteries.

## Data layer

### SQLite WAL mode

Picked over Postgres because:

- Single human, single machine, low write rate.
- Zero setup, single file, no daemon.
- Survives reboot, suspend, and laptop migration without ceremony.
- The desktop bundle requires an embedded database; SQLite is the only
  option that ships in-process without bundling a server runtime.

Postgres remains the upgrade path if a partner-sharing feature ever
needs concurrent writers across machines. The migration is a one-config
change plus a dump/load in Laravel.

WAL is non-negotiable for any process that wants a long-running scheduler
or queue worker reading while a web request writes. `synchronous=NORMAL`
keeps the write-amplification within laptop-friendly bounds without
sacrificing crash safety in practice.

Canonical decision: [ADR 0005 — SQLite WAL](../adr/0005-sqlite-wal.md).

### `brick/money` over `moneyphp/money`

Both packages land in the lock file because `genkgo/camt` depends on
`moneyphp/money`. The codebase uses `brick/money` directly because:

- Immutable value-object semantics throughout, no setters.
- Exact arithmetic via `brick/math` (no BCMath extension required).
- Explicit rounding modes, never implicit truncation.
- MoneyBag for multi-currency totals without an FX conversion seam.

The CAMT boundary converts `moneyphp/money` to `brick/money` at the adapter
edge so downstream code only ever sees one money type.

Canonical decision: [ADR 0009 — `brick/money`](../adr/0009-brick-money-multi-currency.md).

## Ingestion

### `genkgo/camt` as the primary CAMT.053 parser

Picked because it handles every CAMT.053 sub-version ASN exports (001.02,
001.03, 001.08), is actively maintained (multiple 2026 releases), has 1.2M+
installs, and requires only PHP 8.1+ — well within the pinned floor.

### Hand-rolled MT940 toolchain over `kingsquare/php-mt940`

The library was last released in November 2020 — stable but stagnant. The
codebase ships a hand-rolled lexer + per-tag parsers + counterparty cleaner
because:

- Single-purpose classes test independently with snapshot fixtures.
- No library compatibility surprises through later phases.
- The MT940 grammar is small enough that a bespoke parser is faster to
  iterate on than fighting a stagnant maintainer's pull-request queue.

The library remains a viable fallback if the bespoke toolchain ever
accumulates more bugs than the maintenance cost saves.

### `league/csv` for every CSV path

PHP's native `fgetcsv()` was rejected because encoding handling is fragile
(BOMs, Windows-1252 in some ICS exports), header mapping is manual, and
there is no streaming abstraction. `league/csv` provides memory-efficient
streaming reads, header mapping, and character-set conversion as
first-class operations.

## Authentication

### Laravel Fortify

The headless-actions surface of Fortify is exactly what the codebase needs
— Livewire components own every UI surface, and Fortify provides the
register / login / logout / password-reset actions with no Blade templates
to fight. Jetstream and Breeze were considered and rejected because both
ship UI assumptions (Jetstream → Inertia + React, Breeze → Tailwind-Blade
templates) that fight the Livewire 4 + Flux design language.

### Recovery codes, no SMTP password reset

The desktop bundle has no outbound SMTP path. The recovery-code mechanism
(printed at signup, stored as scrypt-hashed rows in the database) is the
only consumer-grade alternative that survives this constraint without
inventing a peer-to-peer reset flow. Owner-resets-partner provides an
escape hatch when both copies of a recovery code are lost.

Canonical decision: [ADR 0010 — Recovery codes](../adr/0010-recovery-codes-no-smtp.md).

## Background work

### `database` queue driver in the shipped bundle

Horizon + Redis was the v1.0 development posture. The desktop-bundle build
drops both because:

- NativePHP ships only a SQLite-backed queue worker as a child process.
  Redis is not bundled, cannot be assumed installed, and shipping Docker
  inside an installer is a non-starter.
- The `database` driver handles single-user-machine throughput well below
  the Redis crossover point.
- `Cache::lock()` against the database driver preserves
  `ShouldBeUniqueUntilProcessing` semantics for the chain-resolution job.

Horizon stays available for the Herd-served development runtime behind a
`DIEDERIK_RUNTIME=herd` feature flag — the same code paths run, the dev box
gets the dashboard, the shipped bundle stays slim.

Canonical decision: [ADR 0007 — Database queue driver](../adr/0007-database-queue-driver.md).

### `launchd` for the development scheduler / queue / IMAP-idle daemons

macOS-native job control. Survives reboots, restarts on crash, no
third-party supervisor required. The `beatrax:install --launchd` command
materialises plist templates with absolute paths resolved at install time.

The desktop build replaces `launchd` with NativePHP's `ChildProcess` +
`QueueWorker` facades — the queue worker lives exactly as long as the
window does, and the scheduler runs inside the same process.

## Testing and quality

### Pest 3 over PHPUnit

Functional-style tests read better at solo-dev scale, community momentum is
clearly Pest for greenfield Laravel projects in 2026, and the dataset
feature is a clean fit for table-driven ingestion tests ("given this PayPal
CSV row, expect this Transaction"). PHPUnit is the engine underneath, so
escape hatches exist when needed.

### Larastan level 10 strict + Laravel Pint

Level 10 is the maximum strictness setting in Larastan. Catches Eloquent
magic, mixed-type leakage at module boundaries, and the everywhere-and-no-where
nature of model-static calls. Critical for a money-handling app where a
single mistyped column flows through every subsequent query.

Pint runs the default Laravel preset — no config bikeshedding, formatter
disagreements are resolved by deferring to upstream defaults.

## Three v1.0 → desktop-bundle stack flips

The shipped desktop bundle differs from the development runtime in three
specific places. Each flip exists because the original v1.0 choice does
not survive inside an Electron-bundled environment.

### Flip 1 — Horizon + Redis → `database` driver + NativePHP `ChildProcess`

NativePHP ships only a SQLite-backed queue worker. The chain-resolver job
moves its `ShouldBeUniqueUntilProcessing` lock store from `redis` to
`database` via `config('cache.locks_store')`. The `BoundaryArchTest` carve-out
that allowed the original Redis-keyed lock stays legal.

### Flip 2 — `launchd` plists → NativePHP `ChildProcess` + `QueueWorker` facades

`launchd` is macOS-only. NativePHP runs `php artisan schedule:run` itself
when the app window is open and spawns one `queue:work` child per
`queue_workers` entry in `config/nativephp.php`. The `beatrax:install --launchd`
command stays but learns a `--desktop-mode` skip flag.

### Flip 3 — chmod 0600 single-file OAuth secrets → SQLite-encrypted `oauth_secrets` table

The v1.0 single-user model used a single chmod-0600 JSON file under
`storage/app/secrets/imap.json`. Multi-user activation moved per-user OAuth
secrets to an `oauth_secrets` table keyed by `user_id`, encrypted via
`APP_KEY`. The `OAuthSecretsRepository` is the only legal reader; an arch
invariant enforces it.

The OS-keychain option (`security` on macOS, `wincred` on Windows,
`secret-tool` on Linux) was rejected because shelling out to three
OS-specific CLIs from PHP under NativePHP is a portability tax the project
does not need.

## License posture

### Hippocratic License 3.0

Picked over MIT / Apache / GPL because:

- The project is local-only and personal-finance — the use cases the
  Hippocratic Standard restricts (mass surveillance, violations of
  international human rights conventions) would never legitimately consume
  this codebase, so the restriction costs nothing in real-world adoption.
- The README and ADRs are explicit that this is *source-available*, not
  OSI-approved open source. Contributors must opt in to that posture
  knowingly.

Canonical decision: [ADR 0003 — Hippocratic 3.0](../adr/0003-hippocratic-3-0-license.md).
