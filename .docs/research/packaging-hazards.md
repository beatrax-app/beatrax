# Packaging hazards

The desktop-bundle-specific pitfalls that the rest of the codebase guards
against. Each entry is a class of failure the codebase actively prevents
through an arch invariant, a runtime guard, an installer step, or a CI gate.

The pitfalls in [known-hazards.md](known-hazards.md) apply to every runtime;
the pitfalls here apply only inside the NativePHP-bundled desktop build.

## File paths

### Hard-coded `database/database.sqlite` breaks the moment NativePHP boots

Inside an Electron bundle the project root is `Resources/app/` (macOS) or
`resources\app\` (Windows), shipped as read-only. NativePHP transparently
relocates the SQLite file to `Application::storagePath()`, but anything
that doesn't read the active connection's `database` PDO attribute and
instead reads `base_path('database/database.sqlite')` or the literal
`DB_DATABASE` env value keeps pointing at the read-only bundled location.
The first write attempt throws `SQLSTATE[HY000]: General error: 8 attempt
to write a readonly database`.

The DI-only rule makes this *worse*, not better: a service that takes a
`DatabaseManager` and asks it for the current connection path is correct;
a service that takes a `Repository $config` and reads
`database.connections.sqlite.database` is wrong but passes Larastan.

**Mitigations:**

- The `Modules\Core\Public\Services\AppPaths` injectable is the single
  source of truth for every filesystem location: `databasePath()`,
  `backupsPath()`, `oauthSecretsPath()`, `logsPath()`, `inboxPath()`,
  `attachmentsPath()`. Herd mode returns the development paths; NativePHP
  mode returns paths under `Application::storagePath()`.
- Arch invariant: `base_path()`, `database_path()`, `storage_path()`, and
  string literals containing `database.sqlite` / `storage/app/` are
  forbidden outside `Modules\Core\Public\Services`.
- CI grep gate: `git grep -nE '(base_path|database_path|storage_path)\(' Modules/ app/` returns empty.
- A Pest test boots the app under a simulated NativePHP env (env var
  `NATIVEPHP_STORAGE_PATH` set to a temp dir), runs `php artisan db:backup`,
  and asserts the backup landed in the temp dir, not in the project root.

## First-run migration

### First-run data migration silently corrupts the developer's real v1.0 data

The developer has years of v1.0 transactions in `database/database.sqlite`
at the project root. The desktop build runs for the first time and either:

- migrates a fresh empty schema into `Application::storagePath()`, so the
  desktop app starts with zero transactions and the user panics and runs
  `db:restore` while the v1.0 daemons are still writing to the live file,
  producing a torn copy;
- helpfully copies the v1.0 file into the new location, but the v1.0 file
  is in WAL mode with uncommitted pages, and the copy is a plain `cp`
  producing a subtly corrupt SQLite on the partner's machine;
- runs idempotently every launch and on the second launch overwrites the
  desktop's already-edited database with the now-stale v1.0 copy.

**Mitigations:**

- The first-run migration is an explicit wizard with three exclusive
  outcomes — Start fresh / Import from v1.0 / Quit. There is no implicit
  copy.
- `VACUUM INTO` against a read-only attached source produces a
  WAL-consistent single-file copy regardless of the source's commit state.
- A sentinel file at `Application::storagePath() . '/.migration_complete'`
  prevents the wizard from re-running on subsequent launches.
- OAuth secrets are never auto-copied. A re-auth prompt during the import
  step forces the user (or each user, in multi-user mode) to reconnect
  each inbox.
- A pre-migration rollback snapshot of the empty desktop database lands
  alongside the chosen source so a botched import can revert.
- The `beatrax:install --launchd` plists from the v1.0 install must be
  uninstalled before the import runs — the migration wizard verifies and
  blocks otherwise.

## Background work in the bundle

### Horizon / Redis absent in the shipped build silently kills chain resolution

If the chain-resolver job dispatches to a non-existent Redis instance, it
fails in the background, forecasts ignore fuzzy resolution, and the partner
never sees the Netflix → ICS → ASN chain that was the project's headline
differentiator. The user sees no error — just wrong numbers.

**Mitigations:**

- Horizon is dropped from the shipped build entirely. The bundle uses
  NativePHP's `queue_workers` config + the SQLite `database` queue driver.
- `ShouldBeUniqueUntilProcessing`'s lock moves to `Cache::lock()` against
  the database driver — officially supported in Laravel 11+ and verified
  end-to-end under concurrent load.
- The dev console's job inspector reads `jobs` + `failed_jobs` directly
  via a ~200-line Livewire component, replacing Horizon's dashboard in the
  bundle.
- An arch invariant forbids any `Horizon\*` import outside the
  `Modules/DevMode/Internal/HorizonProbe` surface (which only runs under
  `DIEDERIK_RUNTIME=herd`).

## Code signing and runtime hardening

### Apple Hardened Runtime entitlements clash with bundled PHP

Notarytool rejects an Electron bundle that ships a runtime executing
unsigned JIT-compiled code. PHP's bundled OPcache and any extension that
relies on JIT-style code generation will trip this gate. The bundle either
fails to notarise or notarises but crashes on first launch when Gatekeeper
verifies the runtime.

**Mitigations:**

- The bundled PHP build disables JIT entirely (`opcache.jit=0`).
- The macOS entitlements include
  `com.apple.security.cs.allow-unsigned-executable-memory` for PHP's
  legitimate code-generation paths that don't qualify as JIT but do
  allocate executable memory.
- Notarisation runs in CI as part of the release workflow. A bundle that
  fails to notarise blocks the release; nothing publishes until the
  binary's entitlement set is correct.

Canonical procedure: [Cutting a release](../runbooks/release-cut.md).

### Code-signing secrets exposed to forked PRs

A forked PR runs the same workflow file as a trusted PR. If signing secrets
are available to `pull_request` triggers, a fork can exfiltrate them.

**Mitigations:**

- The release workflow uses `pull_request_target` for the signing job,
  scoped to the `release` GitHub environment. Forked PR runs cannot
  access the signing secrets even when they edit the workflow file.
- All signing secrets live in environment-scoped repository secrets, never
  in repo-wide secrets that would propagate to every workflow.
- CODEOWNERS gates `.github/workflows/release.yml` so a forked PR's
  proposed workflow change requires explicit owner approval before any
  privileged run.

Canonical procedure: [Repo security setup](../runbooks/repo-security-setup.md).

### Unsigned auto-update bypasses code signing

If `electron-updater` accepts an unsigned update payload, an attacker who
compromises the GitHub Releases bucket can ship a malicious replacement to
every installed copy.

**Mitigations:**

- The release workflow signs the `latest.yml` manifest with an Ed25519
  publisher key. The installed client verifies the signature against a
  pinned public key before accepting any update.
- macOS auto-update additionally requires a Developer ID + notarisation
  receipt — `electron-updater` refuses to install otherwise.
- A user-facing release-verification recipe documents the SHA-256 +
  Ed25519 verification path for operators who want to validate manually.

Canonical recipe: [Verify a release](../runbooks/verify-release.md).

## Configuration and secrets

### `.env` values leak into the bundle

A naive `electron-builder` config bundles the source tree verbatim, including
the developer's `.env` with the development `APP_KEY`, OAuth client secrets,
and IMAP credentials. Every installed copy then shares the same `APP_KEY`
and any per-user encrypted column is trivially decryptable across machines.

**Mitigations:**

- The bundle ships `.env.bundled` instead of `.env`. `.env.bundled` carries
  build-time defaults (no secrets) plus a sentinel `APP_KEY=` empty value.
- The first-launch bootstrap regenerates `APP_KEY` per install and writes
  the active `.env` to `Application::storagePath()`, not into the read-only
  bundle root.
- GitHub's repo-level secret scanning + push protection guard the source
  tree against accidentally-committed provider tokens; the release
  workflow's bundle step explicitly excludes `.env*` from the packaged
  source so even build-time defaults never reach the published archive.

## Public-release boundary

### Hippocratic 3.0 mislabelled as OSI-approved

The README, package metadata, and any marketing copy that calls Hippocratic
3.0 "open source" misleads — Hippocratic 3.0 is *source-available*, with
ethical-use restrictions that OSI's Open Source Definition does not permit.

**Mitigations:**

- README and NOTICE explicitly say "source-available, not OSI-approved
  open source" and link to ADR 0003 for the reasoning.
- SPDX identifier is `Hippocratic-3.0` (officially registered) so package
  managers display the license correctly.
- The NOTICE file explains the trade-off in plain language for contributors
  evaluating whether to participate.

Canonical decision: [ADR 0003 — Hippocratic 3.0](../adr/0003-hippocratic-3-0-license.md).
