---
phase: 1
slug: foundation-asn-csv-vertical-slice
status: draft
nyquist_compliant: false
wave_0_complete: true
created: 2026-05-12
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Derived from `01-RESEARCH.md` §Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 3.x (built on PHPUnit 11) |
| **Config files** | `phpunit.xml` (Pest reads it) + `tests/Pest.php` (arch presets, datasets) |
| **Quick run command** | `vendor/bin/pest --filter=<TestName> --stop-on-failure` |
| **Full suite command** | `vendor/bin/pest --parallel` |
| **Estimated runtime** | ~5s per touched file (quick); ~30s full suite on a fresh project |
| **Coverage gate (informational)** | `vendor/bin/pest --coverage --min=70` (not CI-enforced in Phase 1) |
| **Companion gates** | Larastan level max (`vendor/bin/phpstan analyse`); Pint (`vendor/bin/pint --test`) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/pest <touched-module-or-file> --stop-on-failure` (≈5s)
- **After every plan wave:** Run `vendor/bin/pest --parallel` + `vendor/bin/phpstan analyse` + `vendor/bin/pint --test` (≈45s combined)
- **Before `/gsd-verify-work`:** Full suite green, Larastan level max green, Pint green
- **Max feedback latency:** 5 seconds per task (quick command)

No `--watch` / `--tdd` / `--coverage-html` flags during sampling — watch-mode is banned (blocks the harness).

---

## Per-Task Verification Map

> Verification is keyed by **Requirement ID**, not task ID, until PLAN.md exists. The planner MUST add a `Task ID` column entry when each task is authored.

| Req ID | Behaviour | Test Type | Automated Command | File Exists? | Status |
|--------|-----------|-----------|-------------------|-------------|--------|
| FND-01 | App refuses non-loopback bind | Feature | `pest tests/Feature/LoopbackOnlyTest.php -x` | ❌ W0 | ⬜ pending |
| FND-02 | Single-user Fortify + Livewire login works | Feature | `pest tests/Feature/Auth/LoginFlowTest.php -x` | ❌ W0 | ⬜ pending |
| FND-03 | Every domain table has nullable `user_id` | Arch | `pest tests/Contracts/UserIdColumnArchTest.php -x` | ✅ Plan 01 | ❌ red (Plan 03) |
| FND-04 | No `REAL`/`FLOAT` on money columns | Arch | `pest tests/Contracts/NoFloatMoneyArchTest.php -x` | ✅ Plan 01 | ❌ red (Plan 03) |
| FND-06 | SQLite WAL + `synchronous=NORMAL` on connection | Unit | `pest Modules/Core/tests/Unit/SqlitePragmasTest.php -x` | ❌ W0 | ⬜ pending |
| FND-07 | Money arithmetic uses brick/money exclusively | Unit | `pest Modules/Ledger/tests/Unit/MoneyValueObjectTest.php -x` | ❌ W0 | ⬜ pending |
| ING-01 | ASN CSV upload imports transactions | Feature | `pest Modules/Import/tests/Feature/AsnCsvImportTest.php -x` | ✅ Plan 05 | ✅ green (Plan 05) |
| ING-06 | Same-file re-upload → 0 new rows (idempotency contract) | Feature | `pest tests/Contracts/IdempotencyContractTest.php -x` | ✅ Plan 01 | ✅ green (Plan 05) |
| ING-07 | Source declared in UI (no auto-detect) | Feature | `pest Modules/Import/tests/Feature/UploadWizardTest.php::test_requires_source_declaration -x` | ✅ Plan 05 | ✅ green (Plan 05) |
| ING-08 | Raw source row link preserved | Unit | `pest Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php::test_preserves_raw_payload -x` | ✅ Plan 04 | ✅ green (Plan 04) |
| LED-01 | Accounts have type + currency | Unit | `pest Modules/Ledger/tests/Unit/AccountModelTest.php -x` | ❌ W0 | ⬜ pending |
| LED-02 | Transaction.type enum populated | Unit | `pest Modules/Ledger/tests/Unit/TransactionTypeTest.php -x` | ❌ W0 | ⬜ pending |
| MC-01 | Both original + settled amount columns present | Arch | `pest tests/Contracts/MoneyColumnsArchTest.php -x` | ✅ Plan 01 | ❌ red (Plan 03) |
| CAT-01 | Category tree assignable to transaction | Feature | `pest Modules/Categorization/tests/Feature/AssignCategoryTest.php -x` | ✅ Plan 07 | ✅ green (Plan 07) |
| CAT-03 | Override existing categorization | Feature | `pest Modules/Categorization/tests/Feature/AssignCategoryTest.php::test_overrides_existing -x` | ✅ Plan 07 | ✅ green (Plan 07) |
| CAT-05 | Triage page lists uncategorized rows | Feature | `pest Modules/Categorization/tests/Feature/TriagePageTest.php -x` | ❌ W0 | ⬜ pending |
| UI-01 | Dashboard shows current-period totals | Feature | `pest Modules/Ledger/tests/Feature/DashboardTest.php -x` | ✅ Plan 06 | ✅ green (Plan 06) |
| UI-04 | Recent-window default = 90 days | Feature | `pest Modules/Ledger/tests/Feature/TransactionListTest.php::test_defaults_to_recent_window -x` | ✅ Plan 06 | ✅ green (Plan 06) |
| UI-05 | Aesthetic compliance (calm Linear/Notion) | **manual** — UI-SPEC checker | n/a | n/a | manual checker pending (run /gsd-ui-checker after Plan 07) |
| PLT-01 | Localhost-only middleware in place | Feature | (same as FND-01) `pest tests/Feature/LoopbackOnlyTest.php -x` | ❌ W0 | ⬜ pending |
| PLT-02 | DB path validation in install command | Feature | `pest Modules/Core/tests/Feature/InstallCommandTest.php::test_refuses_icloud_path -x` | ❌ W0 | ⬜ pending |
| PLT-05 | `composer.json` lacks `ext-imap` | Arch | `pest tests/Contracts/NoExtImapTest.php -x` | ✅ Plan 01 | ✅ green (Plan 01) |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

This is a greenfield project — **all** test infrastructure is missing and must be created in Wave 0 before any feature task can run.

- [x] **Framework install:** `composer require pestphp/pest pestphp/pest-plugin-laravel pestphp/pest-plugin-arch --dev`
- [x] **Pest init:** `tests/Pest.php` + `tests/TestCase.php` authored manually (Pest 4 init not needed)
- [x] `tests/Pest.php` — global config (arch presets, base `TestCase` binding, DI helpers)
- [x] `tests/TestCase.php` — extends framework TestCase, applies `RefreshDatabase` trait by default; ships `seedFixtureUserAndAccount()` helper
- [x] `tests/Contracts/IdempotencyContractTest.php` — covers ING-06 (Pest dataset so future adapters in Phase 2+ inherit) — **RED until Plan 05**
- [x] `tests/Contracts/UserIdColumnArchTest.php` — covers FND-03 (column introspection via Schema builder) — **RED until Plan 03**
- [x] `tests/Contracts/NoFloatMoneyArchTest.php` — covers FND-04 — **RED until Plan 03**
- [x] `tests/Contracts/MoneyColumnsArchTest.php` — covers MC-01 — **RED until Plan 03**
- [x] `tests/Contracts/NoExtImapTest.php` — covers PLT-05 — **GREEN (Plan 01)**
- [x] `tests/Contracts/BoundaryArchTest.php` — enforces module cross-import rules (DI-only) — **GREEN (Plan 01)**
- [x] `tests/fixtures/asn-sample-1.csv` — anonymized real ASN export (committed in prior fixture-drop)
- [x] `tests/fixtures/asn-month-a.csv` + `tests/fixtures/asn-month-a-and-b.csv` — overlapping-period fixtures (committed in prior fixture-drop)
- [x] Per-module test skeletons under `Modules/Core/tests/`, `Modules/Ledger/tests/`, `Modules/Ingestion/tests/`, `Modules/Import/tests/`, `Modules/Categorization/tests/` (each with own `TestCase.php` + `Pest.php`)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Calm Linear/Notion aesthetic compliance | UI-05 | Visual/typographic judgement; no Dusk or Percy in v1 | After phase verify, open `/login`, `/`, `/imports/new`, `/uncategorized` in the browser; compare against `01-UI-SPEC.md` checklist (typography, color tokens, spacing, CTA labels). Re-run `/gsd-ui-checker` if regressions suspected. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or are explicitly listed under Wave 0
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all rows marked `❌ W0` above
- [ ] No watch-mode flags in any sampling command
- [ ] Feedback latency < 5s for per-task; < 45s for per-wave
- [ ] Larastan level max + Pint green required at every wave boundary
- [ ] `nyquist_compliant: true` set in frontmatter after planner attaches Task IDs

**Approval:** pending
