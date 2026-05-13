---
phase: 2
slug: asn-statement-coverage-camt-053-mt940
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-13
---

# Phase 2 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 3.x (PHPUnit 11 engine) + `spatie/pest-plugin-snapshots` |
| **Config file** | `phpunit.xml` (project root) + `Modules/*/tests/Pest.php` (per-module bootstrap) |
| **Quick run command** | `vendor/bin/pest --group=phase-2 --bail` |
| **Full suite command** | `vendor/bin/pest && vendor/bin/phpstan analyse --level=10 && vendor/bin/pint --test` |
| **Estimated runtime** | ~30 seconds (quick), ~120 seconds (full suite) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/pest --group=phase-2 --bail`
- **After every plan wave:** Run full suite (Pest + Larastan + Pint)
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

> Placeholder — populated as `gsd-planner` emits PLAN.md task IDs in step 8. Each row maps a task to its Pest test file and `@group phase-2` marker.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 02-XX-XX | XX | X | ING-02 | — | CAMT.053 entry yields one `SourceTransactionDto` per `TxDtls` with `EndToEndId` captured | unit | `vendor/bin/pest --filter=AsnCamt053AdapterTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-02 | — | CAMT namespace dispatch handles V02/V03/V04/V08 without branching | unit | `vendor/bin/pest --filter=CamtNamespaceDispatchTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-03 | — | MT940 `:61:` line with 34-char customer_reference parses without error | unit | `vendor/bin/pest --filter=AsnMt940TagParserTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-03 | — | MT940 `:86:` `?NN` subfields decode EREF/MREF/SVWZ/CRED/IBAN/BIC correctly | unit | `vendor/bin/pest --filter=Mt940NarrativeDecoderTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-06 | — | v3 fingerprint excludes `source_ref`; same real-world entry CSV vs CAMT hash identically | unit | `vendor/bin/pest --filter=FingerprintComposerV3Test` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-06 | — | v3 migration aborts cleanly when pre-check finds a collision; v2 rows remain untouched | feature | `vendor/bin/pest --filter=RederiveFingerprintsCommandTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-02, ING-06 | — | CAMT.053 import after CSV import of the same period yields zero new rows; existing rows are ENRICHED with `EndToEndId` | feature | `vendor/bin/pest --filter=CrossFormatDedupTest::camt_after_csv` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-03, ING-06 | — | MT940 import after CSV import of the same period yields zero new rows | feature | `vendor/bin/pest --filter=CrossFormatDedupTest::mt940_after_csv` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-02, ING-03 | — | `HeaderSniffer` rejects an XML payload declared as `asn-mt940` and vice versa with a user-readable message | unit | `vendor/bin/pest --filter=HeaderSnifferSignaturesTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-02 | — | `enriched_from` JSON column appends a new provenance entry per import; null on rows that have never been enriched | unit | `vendor/bin/pest --filter=EnrichedFromCastTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-02, ING-03 | — | `statement_summaries` row created for CAMT/MT940 imports; absent for CSV imports | feature | `vendor/bin/pest --filter=StatementSummaryTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-02 | — | CAMT `Ntry` with N `TxDtls` blocks produces N `SourceTransactionDto` rows | unit | `vendor/bin/pest --filter=CamtBatchEntryTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-02, ING-03 | — | Snapshot test for canonical anonymised fixtures produces stable DTO stream | unit | `vendor/bin/pest --filter=AdapterSnapshotTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-02, ING-03 | — | `UploadWizard` dropdown extends to `asn-csv`, `asn-camt053`, `asn-mt940`; validator rejects others | feature | `vendor/bin/pest --filter=UploadWizardLivewireTest` | ❌ W0 | ⬜ pending |
| 02-XX-XX | XX | X | ING-02 | — | Preview wizard renders ENRICHED row with `source_ref: ∅ → ENDTOEND-XYZ` diff indicator | feature | `vendor/bin/pest --filter=PreviewWizardEnrichedStateTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] Anonymised ASN CAMT.053 XML fixture (at least one of `001.02` / `001.03` / `001.08`) committed under `Modules/Ingestion/tests/Fixtures/Asn/Camt/`
- [ ] Anonymised ASN MT940 fixture covering older statement period committed under `Modules/Ingestion/tests/Fixtures/Asn/Mt940/`
- [ ] Anonymised same-period CSV + CAMT.053 fixture pair for cross-format dedup tests (must contain the same real-world transactions in both formats)
- [ ] Anonymised same-period CSV + MT940 fixture pair for cross-format dedup tests
- [ ] `composer require genkgo/camt:^2.10` installed; Larastan baseline regenerated to absorb library type drift
- [ ] `@group phase-2` Pest group registered in `tests/Pest.php` so quick-run command filters cleanly
- [ ] `spatie/pest-plugin-snapshots` confirmed present in `composer.json` `require-dev` (Phase 1 already installed it; verify, don't reinstall)
- [ ] `Modules/Ingestion/tests/Snapshots/` directory created for `AdapterSnapshotTest` outputs

*Critical:* The user must supply anonymised ASN CAMT.053 and MT940 export samples before Wave 0 ingestion plans can ship. Until then, the adapter tests are based on the genkgo/camt library's own fixtures + the published SWIFT MT940 spec — empirical ASN-specific narrative quirks remain unverified.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real ASN CAMT.053 export ingests end-to-end through the wizard with `EndToEndId` populated on every row that has one | ING-02 | Requires real ASN export the user must download from their bank | Log in → upload via wizard → confirm → check `transactions.source_ref` for the run via Tinker |
| Real ASN MT940 export ingests end-to-end with sensible counterparties after `:86:` decode | ING-03 | Requires real ASN MT940 sample; only the user has authority to download | Same flow as above, source format = `asn-mt940` |
| ENRICHED diff indicator looks visually distinct from NEW / DUPLICATE in the preview wizard | ING-02 | Subjective visual quality — Flux UI rendering | Re-import same period in CAMT after CSV; confirm green/yellow/grey state colors match the calm-aesthetic intent |
| Statement-coverage panel shows opening/closing balance and entry count matching the source file | ING-02 | Trust check; the user must visually confirm the numbers ASN printed equal what we ingested | After CAMT import, open the run's detail view; cross-check against the original XML |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (fixtures, composer install, group registration)
- [ ] No watch-mode flags in Pest commands
- [ ] Feedback latency < 30s on quick run
- [ ] `nyquist_compliant: true` set in frontmatter after gsd-planner + gsd-plan-checker complete

**Approval:** pending
