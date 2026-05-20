---
phase: 13
slug: app-paths
status: verified
threats_open: 0
asvs_level: 1
created: 2026-05-20
---

# Phase 13 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| OS env → app | `NATIVEPHP_STORAGE_PATH` is read from the process environment and becomes a filesystem-path base | Storage root path |
| caller → `UserDataPathService::appPath()` | A `$relative` argument crosses into path construction; untrusted segments could escape the storage sandbox | Sub-path segments |
| caller → `EmlBlobStore::appRelative()` | Per-message `.eml` blob paths built from `provider_message_id` | Message-id slug |
| migration → filesystem | The Auth migration moves a secrets file | Secrets file path |
| app → user (error copy) | `OAuthClientWizardModal` surfaces a filesystem path in UI copy | Path string |
| simulated env → test process | Feature test injects `NATIVEPHP_STORAGE_PATH` via `putenv()` into a temp dir | Env var, temp path |
| CI gate → build | `composer check:paths` is the regression boundary against raw path helpers | Source literals |

---

## Threat Register

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-13-01 | Tampering / EoP | `appPath(string $relative)` | mitigate | `appPath()` splits on `[/\\]` and throws `\InvalidArgumentException` on any `..` segment; `backupsPath()`/`secretsPath()` route through it (`UserDataPathService.php:79-98`). Unit test `UserDataPathServiceTest.php:80-83`. | closed |
| T-13-02 | Tampering | `NATIVEPHP_STORAGE_PATH` env value | accept | See Accepted Risks AR-13-01. | closed |
| T-13-03 | Information Disclosure | resolved path strings (env-set vs env-unset) | accept | See Accepted Risks AR-13-02. | closed |
| T-13-04 | Tampering / EoP | `EmlBlobStore` blob-path construction | mitigate | `MESSAGE_ID_PATTERN` preserved unchanged; `pathFor()` rejects bad ids (`EmlBlobStore.php:91-96`) before joining via `appRelative()` (line 100) — two defence layers. | closed |
| T-13-05 | Information Disclosure | `OAuthClientWizardModal` error string | mitigate | Error string reworded — no `storage/app/secrets/` literal (`OAuthClientWizardModal.php:141`); `bin/check-paths.sh` exits 0. | closed |
| T-13-06 | Information Disclosure | OAuth secrets file path after re-rooting (`secretsPath()`) | accept | See Accepted Risks AR-13-03. | closed |
| T-13-07 | Tampering | `NATIVEPHP_STORAGE_PATH` env leaking between tests | mitigate | `putenv('NATIVEPHP_STORAGE_PATH')` clears the var (no `=`) (`UserDataPathResolutionTest.php:51`); per-test temp root via `bin2hex(random_bytes(8))` (line 33). | closed |
| T-13-08 | Tampering / EoP | Arch-test allow-list scope creep | mitigate | `BoundaryArchTest.php:1140-1142` allow-list holds exactly one file entry, never a directory glob; `bin/check-paths.sh:16` single `ALLOW=`. | closed |
| T-13-09 | Information Disclosure | Stale `config:cache` freezing a dev path into a test result | mitigate | `configurationIsCached()` false-assertion guards (`UserDataPathResolutionTest.php:30,77,107,143,159`). | closed |
| T-13-SC | Tampering | npm/pip/cargo installs | mitigate | Phase 13 installs no packages; `tech-stack.added: []` in all 3 summaries — only a `composer.json` script entry added. Not applicable. | closed |

*Status: open · closed*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-13-01 | T-13-02 | `NATIVEPHP_STORAGE_PATH` originates from trusted bundle config, not user input. In Herd dev the var is absent (project-root fallback); a shipped build (Phase 15) sets it from NativePHP's appdata path. Local-only single-user app — low-value target. Documented as a Phase 15 hand-off. | Wessel Verheij | 2026-05-20 |
| AR-13-02 | T-13-03 | Resolved path strings contain no secrets. The secrets *files* keep their existing chmod-0600/0700 hardening, owned by callers (not `UserDataPathService`). | Wessel Verheij | 2026-05-20 |
| AR-13-03 | T-13-06 | This phase changes the OAuth secrets *path*, not the *mode*. chmod-0600 hardening verified present in code (migration `2026_05_20_000002:48`, `BackupDatabaseCommand.php:158`, `RestoreDatabaseCommand.php:148`, `EmlBlobStore.php:65-68,160,186`). `OAuthSecretsRepository` additionally stores OAuth credentials AES-256-encrypted in the DB. | Wessel Verheij | 2026-05-20 |

*Accepted risks do not resurface in future audit runs.*

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-05-20 | 10 | 10 | 0 | gsd-security-auditor |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-05-20
