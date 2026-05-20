# Phase 13: AppPaths - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-20
**Phase:** 13-AppPaths
**Areas discussed:** Wizard entry point (discarded), Runtime detection signal, Arch test scope, Consumption pattern

---

## Phase scope change (mid-discussion)

The discussion opened against the roadmapped phase "AppPaths + First-Run
Migration Wizard" (PKG-01 + PKG-02). After one area (Wizard entry point) was
discussed, the user halted it: diederik has no real deployment and no v1.0
user data, so there is no migration to perform and backwards-breaking changes
are free. PKG-02 (the first-run migration wizard) was **dropped entirely**.

Actioned: `ROADMAP.md` (Phase 13 renamed to "AppPaths", goal rewritten,
success criteria 3–5 removed, slug → `13-app-paths`), `REQUIREMENTS.md`
(PKG-02 deleted, counts 48→47, traceability updated), `STATE.md` (Phase 13
migration-UAT blocker + first-launch-migration-UX gap removed, counts
corrected). The "Wizard entry point" answers were discarded.

---

## Wizard entry point (discarded)

Discussed before the scope change, then voided. Answers (now moot): blocking
Livewire route; always-written `.first-run-complete` sentinel; goodbye-screen
Quit; `diederik:reset-first-run` dev command. Retained here only as audit
trail — none of this is built.

---

## Runtime detection signal

| Option | Description | Selected |
|--------|-------------|----------|
| NATIVEPHP_STORAGE_PATH presence | Env var set = shipped (var IS the root); absent = project paths. DIEDERIK_RUNTIME orthogonal. | ✓ |
| DIEDERIK_RUNTIME authoritative | One flag drives path resolution + dev-feature gating. | |
| Both, with NATIVEPHP primary | NATIVEPHP drives paths; DIEDERIK_RUNTIME cross-checked, mismatch throws at boot. | |

**User's choice:** NATIVEPHP_STORAGE_PATH presence.
**Notes:** Path resolution keyed solely on the env var presence; `DIEDERIK_RUNTIME` kept orthogonal for dev-feature gating only.

---

## Arch test scope

| Option | Description | Selected |
|--------|-------------|----------|
| Production code only | Arch test + grep gate cover Modules non-test + app/ + config/; ~40+ test files exempt. | ✓ |
| Production + tests | Every file including tests/ goes through the service (~50-file refactor). | |
| Production banned, tests use a helper | Tests exempt from the ban but get a shared temp-path test trait. | |

**User's choice:** Production code only.
**Notes:** Test files keep raw helpers — they run in a known Herd env and are never shipped. An open question was flagged: `config/*.php` cannot use constructor DI, so config-level path defaults need a separate resolution strategy (left to the planner).

---

## Consumption pattern

| Option | Description | Selected |
|--------|-------------|----------|
| Inject the service directly | Consumers inject UserDataPathService, call named accessors; existing string-injected consumers migrated; old config bindings removed. | ✓ |
| Keep string injection, service feeds config | Consumers keep injecting strings; providers source them from the service. | |
| Hybrid | New code injects the service; existing consumers re-pointed; two patterns coexist. | |

**User's choice:** Inject the service directly.
**Notes:** `BackupDatabaseCommand` migrates off the `core.backups_directory` config-string pattern; old config keys/bindings removed so only one pattern exists.

## Claude's Discretion

- Exact method surface of `UserDataPathService`.
- How `config/*.php` resolves its path defaults under `NATIVEPHP_STORAGE_PATH`.
- Internal structure of the arch test / CI grep gate.

## Deferred Ideas

None. The first-run migration wizard was removed, not deferred.
