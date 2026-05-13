---
phase: 1
slug: foundation-asn-csv-vertical-slice
status: verified
threats_open: 0
threats_total: 58
threats_closed: 58
asvs_level: 1
register_authored_at_plan_time: true
created: 2026-05-13
audited: 2026-05-13
---

# Phase 1 — Security

Per-phase security contract: 58 STRIDE threats authored at plan time across 7 plans, verified against the executed implementation. Local-only, single-user app handling financial data on the user's own machine. ASVS L1.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Browser → Laravel (HTTP) | Loopback-only listener (`LoopbackOnly` middleware checks `SERVER_ADDR`); session-cookied auth via Fortify + hand-written Livewire login | Credentials, session cookie, CSRF tokens, financial UI state |
| Filesystem → PHP runtime | `composer.json`, `phpstan.neon`, `phpunit.xml`, lock files; fixture files live on disk; PHP / Composer / PHPStan read them | Configuration, dependency manifest |
| User upload → Import pipeline | `UploadWizard` accepts CSV files (≤10 MB, MIME-checked, name-sanitised) and stores at a sha256-named path under `storage/app/private/imports/{user_id}/` | Bank transaction data (counterparty IBANs, amounts, descriptions) |
| Import preview cache → confirm step | `PreviewCache` round-trips a `CanonicalTransaction[]` between two HTTP requests via JSON (no `unserialize`) | Parsed transaction rows + unknown-IBAN naming staging |
| SQLite DB → application | Single file on disk (`database/database.sqlite`); WAL mode; `synchronous=NORMAL`; `busy_timeout=5000`; install refuses cloud-sync paths | All persisted financial data |
| Application → user filesystem (raw payloads) | Adapter stamps every row with `rawPayload` + `sourceRowIndex` so reproduction without re-uploading is possible | Original CSV row content (audit trail) |
| Composer → packagist.org | Composer install fetches packages over TLS; `composer.lock` pins exact versions | Third-party PHP code |

---

## Threat Register

### Plan 01-01 — Project scaffold + DI-enforcement gate (8 threats)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-01-01 | T | `phpstan.neon` | mitigate | `phpstan.neon:40` registers `App\PhpStan\Rules\BoundaryRule`; `:8` pins `level: max`; `tests/Unit/PhpStanBoundaryRuleTest.php` execs PHPStan and fails if rule is silently disabled | closed |
| T-01-02 | T | `composer.json` (no `ext-imap`) | mitigate | `tests/Contracts/NoExtImapTest.php` asserts `ext-imap` is absent from `composer.json`; verified | closed |
| T-01-03 | E | Composer transitive deps | accept | High-install packages only (brick/money 39M, league/csv 173M, etc.); `composer.lock` committed; rationale unchanged | closed |
| T-01-04 | I | `.gitignore` for SQLite + imports | mitigate | `.gitignore:38-44` excludes `/database/*.sqlite*` + `/storage/imports` | closed |
| T-01-05 | T | Custom `BoundaryRule.php` AST walker | mitigate | `tests/Unit/PhpStanBoundaryRuleTest.php` with paired good+bad fixtures fails immediately on subtle rule regression | closed |
| T-01-06 | D | PHPStan runtime on `level: max` | accept | Operational only; result cache mitigates re-runs; not a security concern | closed |
| T-01-07 | S | `composer install` from untrusted network | accept | Composer TLS + SHA verification is the user's responsibility | closed |
| T-01-08 | I | Plan 01 has no routes / no auth / no DB | accept | Non-runnable surface until Plan 02 lands auth | closed |

### Plan 01-02 — Auth + LoopbackOnly + install (13 threats)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-02-01 | S | Password hashing | mitigate | `Modules/Core/Internal/Providers/FortifyServiceProvider.php:37-53` injects `Hasher`; `Modules/Core/Models/User.php:41` casts `'password' => 'hashed'` | closed |
| T-02-02 | T | Session cookie | mitigate | `config/session.php:114` `http_only=true`; `:122` `same_site=strict`; `:25` 30-day lifetime | closed |
| T-02-03 | R | Auth audit log | accept | Deferred to Phase 11; Fortify rate-limiter present meanwhile | closed |
| T-02-04 | I | Login error messages | mitigate | Generic `Email or password is incorrect.` in `Modules/Core/Resources/views/auth/login-form.blade.php:42` | closed |
| T-02-05 | I | Cache-Control on authed responses | mitigate | `Modules/Core/Internal/Http/Middleware/NoStoreFinancialData.php:24` sets `Cache-Control: no-store, no-cache, must-revalidate, private`; `bootstrap/app.php:22` registers globally; `tests/Feature/Auth/LoginFlowTest.php:48-51` asserts header | closed |
| T-02-06 | D | Login brute-force | mitigate | `FortifyServiceProvider.php:57-63` configures `login` rate limiter (5/min keyed by email+IP) | closed |
| T-02-07 | E | Non-loopback access | mitigate | `Modules/Core/Internal/Http/Middleware/LoopbackOnly.php:38-42` checks `SERVER_ADDR` (not Host) against IPv4 loopback range, `::1`, and IPv4-mapped-IPv6 loopback; `bootstrap/app.php:18` prepends it; `tests/Feature/LoopbackOnlyTest.php` covers the matrix | closed |
| T-02-08 | E | Reverse-proxy header trust | mitigate | No `trustProxies` calls anywhere in `bootstrap/` or `app/` (grep clean) | closed |
| T-02-09 | I | Cloud-sync DB exfiltration | mitigate | `Modules/Core/Internal/Console/InstallCommand.php:44-91` refuses iCloud / OneDrive / Dropbox / Box / pCloud / MEGA / `Library/CloudStorage` paths | closed |
| T-02-10 | T | Time-Machine WAL hazard | accept | Operational concern; deferred to Phase 11 `db:backup` via `VACUUM INTO` | closed |
| T-02-11 | S | Cross-user data leakage | mitigate | `Modules/Core/Public/Scopes/UserScope.php:28-35` global scope adds `WHERE user_id = ?` for every authenticated query | closed |
| T-02-12 | T | Password verification | mitigate | `User.php:41` `'password' => 'hashed'` cast; `FortifyServiceProvider.php:48` `$hasher->check()` | closed |
| T-02-13 | I | `.env` leaks | mitigate | `.gitignore:21-23` excludes `.env`, `.env.backup`, `.env.production` | closed |

### Plan 01-03 — Ledger schema + Money VO + Fingerprint (8 threats)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-03-01 | T | Category mutation cross-user | mitigate | `Modules/Ledger/Public/Actions/UpdateTransactionCategory.php:49` filters `where('user_id', $user->id)`; BoundaryArchTest blocks cross-module internals | closed |
| T-03-02 | T | Fingerprint normalisation drift | mitigate | `Modules/Ledger/Public/Services/FingerprintComposer.php:53` uses `$tx->sourceRef ?? ''`; migration `2026_05_12_010005_create_transactions_table.php:36` makes `counterparty_normalized` NOT NULL; `:71-78` composite UNIQUE + SHA-256 UNIQUE | closed |
| T-03-03 | I | Cross-user query leakage | mitigate | `UserScope.php:31` global scope; `tests/Contracts/UserIdColumnArchTest.php` asserts every domain table has `user_id` | closed |
| T-03-04 | T | Float-money corruption | mitigate | `Modules/Ledger/Public/ValueObjects/Money.php:27-30` only `ofMinor(int, string)` constructor; `tests/Contracts/NoFloatMoneyArchTest.php:12` rejects float/double/real columns and asserts `bigInteger('amount_minor')` | closed |
| T-03-05 | D | Large-import memory | accept | Generator-streamed adapter contract; rationale holds | closed |
| T-03-06 | I | Partial-write torn rows | mitigate | `Modules/Ledger/Public/Actions/RecordTransactions.php:44` wraps `$this->db->connection()->transaction(...)` | closed |
| T-03-07 | E | Unscoped insert | mitigate | `RecordTransactions.php:48` throws if `userId` is null; migration `010004:27` UNIQUE on `(user_id, sha256)` | closed |
| T-03-08 | T | Idempotency bypass | mitigate | `tests/Contracts/IdempotencyContractTest.php` dataset gate; `FingerprintStage` in pipeline is unconditional | closed |

### Plan 01-04 — ASN CSV adapter (8 threats)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-04-01 | I | CSV export | accept | No CSV export path in Phase 1; rationale holds | closed |
| T-04-02 | E | Filesystem path injection | mitigate | `AsnCsvAdapter.php:47` receives `$localPath` from upstream, never request input; `UploadWizard::sanitiseFilename` strips non-`[A-Za-z0-9_-]` | closed |
| T-04-03 | D | Unbounded memory on large file | mitigate | `AsnCsvAdapter.php:47` returns `Generator` (`yield` at :90); `UploadWizard.php:41` `max:10240` rule | closed |
| T-04-04 | T | Layout drift unnoticed | mitigate | `HeaderSniffer.php:60-93` validates column count + signature; fixture snapshot test | closed |
| T-04-05 | T | Encoding/BOM corruption | mitigate | `AsnCsvAdapter.php:57` unconditionally `CharsetConverter::addTo($reader, AsnCsvHeaderProfile::SOURCE_ENCODING, 'UTF-8')` | closed |
| T-04-06 | T | Adapter bypass | mitigate | `IdempotencyContractTest.php:7-35` Pest dataset; BoundaryArchTest enforces module boundaries | closed |
| T-04-07 | R | Provenance loss | mitigate | `AsnCsvAdapter.php:101-102` stamps `rawPayload` + `sourceRowIndex`; `import_runs.raw_file_path` column | closed |
| T-04-08 | I | Sensitive data in exception messages | accept | Adapter raises `'Row %d: %s'` (`AsnCsvAdapter.php:72`) — row index only, no IBAN/amount leakage | closed |

### Plan 01-05 — Import pipeline + upload wizard (11 threats)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-05-01 | E | Filename path traversal | mitigate | `UploadWizard.php:96-102` `preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem)` | closed |
| T-05-02 | D | Resource exhaustion | mitigate | `UploadWizard.php:41` `'max:10240'` Livewire rule; Generator adapter | closed |
| T-05-03 | T | Cross-user import access | mitigate | `RunImport`, `ConfirmImport`, `DiscardImport` all `->where('user_id', $user->id)->firstOrFail()`; `PreviewWizard:72-74`, `ImportResults:33-35` likewise | closed |
| T-05-04 | I | Preview-cache leakage | mitigate | `ConfirmImport.php:40` verifies user before reading cache; PreviewCache key `import.{id}.{preview\|canonical}` scoped via importRunId | closed |
| T-05-05 | S | Status-state forgery on confirm | mitigate | `ConfirmImport.php:40` `where('user_id', $user->id)->firstOrFail()`; status transitions gated | closed |
| T-05-06 | T | Idempotency replay | mitigate | Migration `010004:27` UNIQUE `(user_id, sha256)`; `RunImport.php:63` user-scoped; IdempotencyContractTest covers replay | closed |
| T-05-07 | I | Stack-trace leakage in preview | mitigate | `ImportPipeline.php:84-100, 119-134` converts Throwables to `PreviewRowDto::error` — no traces leak | closed |
| T-05-08 | T | CSV export tampering | accept | No CSV export — rationale holds | closed |
| T-05-09 | E | Unsupported-format escape | mitigate | `SourceAdapterRegistry.php:27` throws `UnsupportedFormatException`; `UploadWizard.php:42` `'in:asn-csv'` rule | closed |
| T-05-10 | T | Account-name spoofing | mitigate | `AccountNamer.php:105` stamps `'user_id' => $user->id` on every account row | closed |
| T-05-11 | E | Deserialization-of-untrusted-data | mitigate | `PreviewCache.php:44-54, 84-89` uses `json_encode` + `JSON_THROW_ON_ERROR` + `CanonicalTransaction::from()`; `unserialize` literal absent from file | closed |

### Plan 01-06 — Dashboard + transactions list (5 threats)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-06-01 | I | Cross-user dashboard aggregation | mitigate | `ThisPeriodAtAGlanceQuery.php:51, 57, 75, 92` explicit `->where('user_id', $user->id)` at every aggregation site | closed |
| T-06-02 | T | Period parameter tampering | mitigate | `Dashboard.php:53-92` derives Period via `PeriodQuery` action methods only — never trusts query-string period | closed |
| T-06-03 | D | Pagination DoS | accept | Cursor pagination; rationale holds | closed |
| T-06-04 | I | Browser back/forward shows stale numbers | mitigate | NoStoreFinancialData middleware registered globally in `bootstrap/app.php:22` | closed |
| T-06-05 | T | XSS via unescaped output | mitigate | No `{!! !!}` anywhere under `Modules/*/Resources/views` (grep clean); Blade `{{ }}` auto-escape on every site | closed |

### Plan 01-07 — Categorization + triage (5 threats)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-07-01 | E | Privilege escalation via category assign | mitigate | `AssignCategory.php:29-37` fires `TransactionCategorized` only when `$affected > 0`; routes through `UpdateTransactionCategory.php:49` `where('user_id', $user->id)` | closed |
| T-07-02 | T | Global-category tampering | accept | Phase 1 ships global defaults with `user_id=NULL`; flagged in SUMMARY for Phase 7 per-user override surface | closed |
| T-07-03 | I | Triage-inbox cross-user leakage | mitigate | `UncategorizedTriageQuery.php:36` `->where('user_id', $user->id)`; UserScope global scope on Transaction as defense in depth | closed |
| T-07-04 | T | XSS via category name | mitigate | Categories are seeder-controlled (`DefaultCategoryTreeSeeder`); Blade `{{ }}` auto-escape; no `{!! !!}` in templates | closed |
| T-07-05 | T | Duplicate seed runs | mitigate | `DefaultCategoryTreeSeeder.php:72-93` uses `updateOrCreate(['slug' => …, 'user_id' => null], …)` | closed |

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-01 | T-01-03 | Composer transitive supply-chain risk is project-wide, not Phase-1-novel. All Phase 1 deps are high-install packages (brick/money 39M, league/csv 173M, spatie/laravel-data, Pest, Larastan, Livewire). `composer.lock` committed. | Phase 1 plan-time | 2026-05-12 |
| AR-02 | T-01-06 | PHPStan first-run cost is operational only, not security. Result cache amortises subsequent runs. | Phase 1 plan-time | 2026-05-12 |
| AR-03 | T-01-07 | `composer install` over untrusted networks is user's responsibility; Composer ships TLS + SHA verification. | Phase 1 plan-time | 2026-05-12 |
| AR-04 | T-01-08 | Plan 01 surface ships no routes / no auth / no DB writes — no data exposure possible. | Phase 1 plan-time | 2026-05-12 |
| AR-05 | T-02-03 | Authentication audit logging deferred to Phase 11 operational hardening. Fortify's built-in `login` rate-limiter (5/min) covers Phase 1's exposure. | Phase 1 plan-time | 2026-05-12 |
| AR-06 | T-02-10 | Time-Machine WAL hazard accepted for Phase 1 (single-user local app). Phase 11 will ship `db:backup` via `VACUUM INTO`. | Phase 1 plan-time | 2026-05-12 |
| AR-07 | T-03-05 | Large-import memory bound enforced by Generator-streamed adapter contract and 10 MB upload cap (T-05-02). Acceptable for Phase 1 dataset sizes. | Phase 1 plan-time | 2026-05-12 |
| AR-08 | T-04-01 | No CSV export in Phase 1; ingestion is one-directional. | Phase 1 plan-time | 2026-05-12 |
| AR-09 | T-04-08 | Adapter exception messages include row index only (`'Row %d: %s'`), not IBAN or amount values. Verified at `AsnCsvAdapter.php:72`. | 2026-05-13 audit | 2026-05-13 |
| AR-10 | T-05-08 | No CSV export — duplicate of AR-08 for the import-pipeline boundary. | Phase 1 plan-time | 2026-05-12 |
| AR-11 | T-06-03 | Pagination DoS bounded by cursor pagination (no offset abuse) and single-user local listener. | Phase 1 plan-time | 2026-05-12 |
| AR-12 | T-07-02 | Phase 1 ships global default categories with `user_id=NULL`. Per-user category overrides land in Phase 7 when the multi-user-ready surface lights up. | Phase 1 plan-time | 2026-05-12 |

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-05-13 | 58 | 58 | 0 | gsd-security-auditor (sonnet) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log (AR-01..AR-12)
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-05-13
