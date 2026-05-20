# Phase 13: AppPaths - Context

**Gathered:** 2026-05-20
**Status:** Ready for planning

<domain>
## Phase Boundary

Route every filesystem path the app reads or writes through a single
injectable `UserDataPathService` (in `Modules/Core/Public/Services/`). The
service resolves paths under NativePHP's storage root in shipped builds and
under the existing project-rooted paths in Herd dev mode. An arch test plus a
CI grep gate guarantee no raw `database_path()` / `storage_path()` /
`base_path()` call — or equivalent hard-coded string literal — survives
outside that service.

Phase 13 ships:

- `Modules\Core\Public\Services\UserDataPathService` — the one place raw path
  helpers are allowed; exposes named accessors (e.g. `databasePath()`,
  `storagePath()`, `backupsPath()`, `secretsPath()` — exact surface is
  planner/researcher discretion).
- A new `BoundaryArchTest::noStoragePathHardCodedOutsideUserDataPathService`
  invariant, extending the existing `BoundaryArchTest` (the same test class
  Phase 12 added `noAuthFacadeOrHelper` to).
- A CI grep gate forbidding the raw helpers and the string literals
  `database.sqlite` / `storage/app/` outside the service.
- Migration of the ~8 non-test production files currently calling raw path
  helpers to inject `UserDataPathService` instead.
- A Pest feature test proving path resolution under a simulated NativePHP env
  (`NATIVEPHP_STORAGE_PATH=<tmp>`).

Phase 13 does NOT ship:

- **The first-run migration wizard.** PKG-02 was dropped entirely on
  2026-05-20 — diederik has no real deployment and no v1.0 user data to
  preserve, so there is no migration to perform and backwards-breaking
  changes are free. Phase 13 is now AppPaths-only (PKG-01). If the packaged
  desktop build ever needs first-launch DB initialization, that is a plain
  `migrate` inside Phase 15's desktop shell — not a wizard.
- NativePHP integration itself (Phase 15) — Phase 13 only ensures paths
  *would* resolve correctly once `Application::storagePath()` is available;
  it is validated here against a simulated env var.
- Queue/cache path concerns (Phase 14).

</domain>

<decisions>
## Implementation Decisions

### Runtime Detection

- **D-01: `NATIVEPHP_STORAGE_PATH` presence is the authoritative signal for
  path resolution.** If the `NATIVEPHP_STORAGE_PATH` env var is set, it IS the
  storage root (shipped build); if absent, `UserDataPathService` falls back to
  the existing project-rooted paths (Herd dev). The signal and the value are
  the same thing — there is no separate boolean flag to keep in sync.
  `DIEDERIK_RUNTIME` (`=herd`) stays **orthogonal** — it is used only for
  dev-feature gating (Horizon etc. in Phases 14/16) and must NOT drive path
  resolution.

### Arch Test Scope

- **D-02: The arch test + CI grep gate cover production code only.** Scope is
  `Modules/*` (non-test) + `app/` + `config/`. The ~40+ test files that call
  `storage_path()` / `base_path()` are exempt and keep using the raw helpers
  — tests run in a known Herd env, are never shipped, and never boot under
  Electron. This keeps the refactor small and low-risk.
  - **Open question for research:** `config/database.php`,
    `config/session.php`, and `config/modules.php` call raw path helpers but
    cannot use constructor DI (config arrays evaluate before the container is
    fully up). The planner must decide how config-level path defaults resolve
    through the same `NATIVEPHP_STORAGE_PATH` logic — likely a static
    resolver on the service, or `config/` reading the env var directly with
    the service wrapping identical logic. The grep gate's treatment of
    `config/` depends on this resolution.

### Path Consumption Pattern

- **D-03: Consumers inject `UserDataPathService` directly.** Constructor DI of
  the service object; consumers call its named accessors. The service is the
  single, discoverable source of truth. (Consistent with the project's
  DI-only rule — constructor DI, no facades or global helpers.)
- **D-04: Existing string-injected consumers are migrated, not preserved.**
  `BackupDatabaseCommand` currently takes a plain `$backupsPath` string bound
  from a `core.backups_directory` config value; it is refactored to inject
  `UserDataPathService`. The old `core.backups_directory`-style config keys
  and their service-provider bindings are removed — no two coexisting
  patterns.

### Claude's Discretion

- Exact method surface of `UserDataPathService` (granular named accessors vs.
  a generic relative-path resolver) — planner/researcher decision.
- How `config/*.php` resolves its path defaults (see D-02 open question).
- Internal structure of the arch test / grep gate implementation.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements
- `.planning/ROADMAP.md` § "Phase 13: AppPaths" — goal + 2 success criteria
  (arch test green; simulated-NativePHP-env Pest test).
- `.planning/REQUIREMENTS.md` — PKG-01 (the only requirement in scope).

### Project conventions
- `CLAUDE.md` — DI-only rule (constructor DI; no facades / global helpers;
  Eloquent models direct OK); modular-boundary rule (cross-module access via
  public service classes only).
- `.planning/phases/12-multi-user-activation/12-CONTEXT.md` — establishes the
  `BoundaryArchTest` pattern that the new path invariant extends.

No external ADRs or specs — requirements fully captured in the decisions above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `Modules/Core/Public/Services/` — established home for cross-cutting
  services (`CurrentUserService`, `SystemClock`, `SystemAlertQuery`).
  `UserDataPathService` belongs here.
- `BoundaryArchTest` — existing arch-test class (Phase 12 added
  `noAuthFacadeOrHelper`); the new `noStoragePathHardCodedOutsideUserDataPathService`
  invariant extends it rather than creating a new test file.
- `BackupDatabaseCommand` — already follows constructor DI of a path string
  (`$backupsPath` from `core.backups_directory`). It is the reference for the
  *old* pattern being superseded by D-03/D-04.

### Established Patterns
- DI-only: constructor injection everywhere; no `Auth::`/`auth()`/facade or
  global-helper calls in module code. AppPaths must follow this — the service
  is injected, never resolved via a helper.
- The existing config-binding pattern (service provider reads a config value,
  binds it as an injectable string) is being *retired* for paths by D-04.

### Integration Points
- Non-test production files calling raw path helpers today (~8, to migrate):
  `Modules/Core/Providers/CoreServiceProvider.php` (`base_path`),
  `Modules/Core/Internal/Console/RestoreDatabaseCommand.php`,
  `Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php`,
  `Modules/EmailScan/Public/Services/EmlBlobStore.php` (`storage_path` ×2),
  `Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php` (×3),
  `config/database.php`, `config/session.php`, `config/modules.php`.
- `config/database.php` sqlite connection: `database` defaults to
  `env('DB_DATABASE', database_path('database.sqlite'))` — the load-bearing
  path that must resolve correctly under `NATIVEPHP_STORAGE_PATH` (see D-02
  open question).

</code_context>

<specifics>
## Specific Ideas

No specific UI/reference requirements — this is a backend path-abstraction
refactor. The user's guiding constraint: diederik is not deployed and has no
real users, so Phase 13 may make backwards-breaking changes freely (this is
what justified dropping the migration wizard).

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope. The first-run migration wizard
(former PKG-02) was not deferred but **removed**: with no deployment and no
v1.0 user data, no migration is needed.

</deferred>

---

*Phase: 13-AppPaths*
*Context gathered: 2026-05-20*
