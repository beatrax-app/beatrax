# Security Audit — Phase 13: app-paths

**Audited:** 2026-05-20
**ASVS Level:** L1
**Block-on:** high
**Audit type:** State B — first audit (no prior SECURITY.md)
**Result:** SECURED — 10/10 threats closed

The phase introduced `UserDataPathService`, a single class through which every
filesystem path resolves, so a future NativePHP desktop build can re-root
storage via `NATIVEPHP_STORAGE_PATH`. Each declared threat mitigation was
verified by reading the cited implementation; documentation alone was not
accepted as evidence.

## Threat Verification

| Threat ID | Category | Disposition | Status | Evidence |
|-----------|----------|-------------|--------|----------|
| T-13-01 | Tampering / EoP | mitigate | CLOSED | `UserDataPathService.php:71-88` — `appPath()` splits the relative argument on `[/\\]`, and `in_array('..', $segments, true)` throws `InvalidArgumentException`. `backupsPath()`/`secretsPath()` (lines 90-98) route through `appPath()`, so every storage-app accessor inherits the guard. Unit test `UserDataPathServiceTest.php:80-83` asserts the throw on `../../etc/passwd`. |
| T-13-02 | Tampering | accept | CLOSED | Accepted risk — see Accepted Risks log below. `NATIVEPHP_STORAGE_PATH` is read at `UserDataPathService.php:43,52` via `getenv()`; in Herd dev it is absent (project-root fallback at lines 47, 55). Phase 15 hand-off documented in `13-03-SUMMARY.md:171-189`. |
| T-13-03 | Information Disclosure | accept | CLOSED | Accepted risk — see Accepted Risks log below. Resolved path strings contain no secrets. Secrets-file hardening verified preserved: see T-13-06 evidence. |
| T-13-04 | Tampering / EoP | mitigate | CLOSED | `EmlBlobStore.php:62` — `MESSAGE_ID_PATTERN` (`/^[A-Za-z0-9._%=+\-]{1,512}$/`) is preserved unchanged; `pathFor()` lines 91-96 reject any non-matching `providerMessageId` with `InvalidArgumentException` BEFORE path construction. The validated/hashed slug is then joined via `$this->paths->appRelative(...)` (line 100), which delegates to `appPath()` and inherits the `..` guard (T-13-01). Two layers of defence intact. |
| T-13-05 | Information Disclosure | mitigate | CLOSED | `OAuthClientWizardModal.php:141` — error string reworded to "Could not save your OAuth client to disk — check your secrets-directory permissions and try again." No `storage/app/secrets/` literal remains. Confirmed: grep gate `bin/check-paths.sh` exits 0 (no literal anywhere outside the allow-listed service). |
| T-13-06 | Information Disclosure | accept | CLOSED | Accepted risk — see Accepted Risks log below. chmod hardening verified PRESENT, not merely claimed: Auth migration `2026_05_20_000002_rename_legacy_email_oauth_json.php:48` calls `$files->chmod($backup, 0600)`. `BackupDatabaseCommand.php:158` and `RestoreDatabaseCommand.php:148` apply `chmod(0o600)` to backup/snapshot artifacts. `EmlBlobStore.php:65-68,160,186` enforce `0700` dir / `0600` file modes with a born-narrow umask. The phase changed path resolution only, not file modes. |
| T-13-07 | Tampering | mitigate | CLOSED | `UserDataPathResolutionTest.php:51` — `afterEach` calls `putenv('NATIVEPHP_STORAGE_PATH')` with no `=`, clearing the var. Per-test temp root at line 33 uses `bin2hex(random_bytes(8))`, preventing cross-test collision. `UserDataPathServiceTest.php:28-33` applies the same `beforeEach`/`afterEach` clear discipline. |
| T-13-08 | Tampering / EoP | mitigate | CLOSED | `BoundaryArchTest.php:1140-1142` — the `$allowList` array holds exactly one file entry: `Modules/Core/Public/Services/UserDataPathService.php`. No directory glob, no second entry. `bin/check-paths.sh:16` mirrors this with a single `ALLOW=` file path. |
| T-13-09 | Information Disclosure | mitigate | CLOSED | `UserDataPathResolutionTest.php:30` (`beforeEach`) and lines 77, 107, 143, 159 — every test re-asserts `expect($this->app->configurationIsCached())->toBeFalse()` before relying on resolved config, so a stale `bootstrap/cache/config.php` cannot freeze a dev path into the result. |
| T-13-SC | Tampering | mitigate (N/A) | CLOSED | Phase 13 installs no npm/pip/cargo packages. `composer.json` change in Plan 01 added only a `check:paths` script entry, no new dependency. All three SUMMARY files report `tech-stack.added: []`. No install task exists, so no slopcheck checkpoint is required. |

## Accepted Risks Log

The following threats were dispositioned `accept` at plan time. Each is recorded
here as required for the disposition to count as closed.

### T-13-02 — `NATIVEPHP_STORAGE_PATH` env value is a filesystem-path base
The environment variable, when set, becomes the storage root for all user
data. It originates from trusted bundle configuration, not from user input.
This is a local-only, single-user, offline finance app — a low-value target
with no remote attack surface. In Herd development the variable is absent and
the service falls back to project-rooted paths. Accepted as a Phase 15
hand-off: Phase 15 (Desktop Shell) must actually set the variable from the
NativePHP appdata path; until then the fallback is the only behaviour.

### T-13-03 — Resolved path strings differ between env-set and env-unset branches
Path strings produced by `UserDataPathService` accessors contain directory
locations only — no credentials, tokens, or secret material. The secrets
*files* themselves keep their existing filesystem-permission hardening
(see T-13-06). Disclosure of a directory path on a single-user local machine
carries negligible risk. Accepted.

### T-13-06 — OAuth secrets file path changes after storage re-rooting
Re-rooting moves the `secrets/` directory location but does not change the
file-permission posture. Verified preserved in code: the Auth migration
chmods the rollback `.bak` to `0600`; backup/restore commands chmod artifacts
to `0600`; `EmlBlobStore` enforces `0700`/`0600`. OAuth client credentials and
refresh tokens themselves now live AES-256-encrypted in the `oauth_secrets`
DB table (`OAuthSecretsRepository`), not a flat file — encryption at rest is
the primary control and is unaffected by path resolution. Accepted.

## Unregistered Flags

None. All three SUMMARY files (`13-01`, `13-02`, `13-03`) contain a
`## Threat Surface` section; each explicitly states "No new security surface
beyond the plan's `<threat_model>`" and maps observed mitigations to existing
threat IDs. No new attack surface appeared during implementation that lacks a
threat mapping.

## Verification Notes

- Implementation files were read-only throughout this audit; none were modified.
- `bin/check-paths.sh` was executed and exits 0 — confirms no raw path helper
  or storage literal survives outside the allow-listed service.
- The arch invariant `noStoragePathHardCodedOutsideUserDataPathService`
  (`BoundaryArchTest.php:1119-1187`) encodes the same rule in-suite; allow-list
  scope verified to be exactly one file (T-13-08).
- Path-traversal defence (T-13-01 / T-13-04) verified as two independent
  layers: per-id regex validation in `EmlBlobStore` AND the `..`-segment
  rejection in `UserDataPathService::appPath()`.
