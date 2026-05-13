---
phase: 02-asn-statement-coverage-camt-053-mt940
plan: 01
subsystem: ingestion
tags:
  - wave-0
  - fixtures
  - composer
  - test-infrastructure
  - phase-2-enablement
dependency_graph:
  requires:
    - 01-04-PLAN
  provides:
    - "`genkgo/camt: ^2.10` — CAMT.053 ISO 20022 parser available to every Wave 1+ adapter under `Genkgo\\Camt`; transitive `moneyphp/money` + `jschaedl/iban-validation` arrive with it (coexist with `brick/money`)"
    - "`tests/fixtures/asn-camt053-sample-1.{xml,md}` — 229-entry anonymised 3-month ASN CAMT.053.001.02 corpus + audit md, gold fixture for the CAMT adapter snapshot + namespace-detection tests"
    - "`tests/fixtures/asn-mt940-sample-1.{sta,md}` — synthesised 12-entry single-statement ASN MT940 + audit md covering RDDT / ICDT / RCDT / CCRD / OTHR / ACMT / RRCT families, drives the hand-rolled parser tests"
    - "`tests/fixtures/asn-cross-format/{february.csv,february.camt053.xml,README.md}` — same Feb-2026 period exported through CSV and CAMT.053 within minutes (72 entries each), entry-for-entry aligned by `(booking_date, amount, counterparty_name)`; drives `CrossFormatDedupTest::csv_then_camt053` + `camt053_then_csv`"
    - "`tests/Pest.php` phase-2 group convention documented (per-test `->group('phase-2')` chains; `vendor/bin/pest --group=phase-2 --bail` is the focused dev loop)"
  affects:
    - "`composer.lock` — adds `genkgo/camt 2.10.3`, `jschaedl/iban-validation v2.7.0`, `moneyphp/money v4.9.0`, `symfony/options-resolver v8.0.8`"
    - "Wave 1+ plans (02-02, 02-03, 02-04, 02-05) — all four can now consume `Genkgo\\Camt`, both fixture corpuses, and the focused Pest group filter"
tech_stack:
  added:
    - "genkgo/camt ^2.10 — CAMT.053/052/054 ISO 20022 parser (PHP 8.1+); supports sub-versions 001.02 / 001.03 / 001.08"
    - "moneyphp/money v4.9.0 (transitive from genkgo/camt) — coexists with project's own brick/money; adapter boundary must convert at the genkgo input boundary"
    - "jschaedl/iban-validation v2.7.0 (transitive) — mod-97 IBAN validator used internally by genkgo/camt"
    - "symfony/options-resolver v8.0.8 (transitive)"
  patterns:
    - "Shared anonymisation mapping across formats — one name+IBAN map built from the union of counterparties in every source file, applied consistently to all outputs. Lets cross-format dedup tests assert identical synthetic merchants in CSV + CAMT for the same Feb-2026 statement."
    - "Per-test group opt-in via `->group('phase-2')` chains rather than a global registration. The bootstrap (`tests/Pest.php`) carries documentation only; the group is implied by usage. A future test that forgets the chain is silently skipped by the focused dev loop — the comment explicitly flags that failure mode."
    - "Synthesised-from-CAMT MT940 fixture pattern — when a bank stops shipping a legacy format, derive the parser fixture from a curated subset of the format the bank still exports, render it back into the legacy dialect, and document the synthesis rationale in the fixture's audit md. Preserves the same anonymised data across all formats the project must support."
key_files:
  created:
    - tests/fixtures/asn-camt053-sample-1.xml
    - tests/fixtures/asn-camt053-sample-1.md
    - tests/fixtures/asn-mt940-sample-1.sta
    - tests/fixtures/asn-mt940-sample-1.md
    - tests/fixtures/asn-cross-format/february.csv
    - tests/fixtures/asn-cross-format/february.camt053.xml
    - tests/fixtures/asn-cross-format/README.md
  modified:
    - composer.json
    - composer.lock
    - tests/Pest.php
decisions:
  - "Pin `genkgo/camt` to `^2.10` (D-26 confirmed) — 2.10.3 supports CAMT.053.001.02 / 001.03 / 001.08 sub-versions; downstream adapter must detect on `xmlns` URI, not assume newest"
  - "Synthesise the ASN MT940 fixture from the anonymised CAMT corpus — ASN no longer ships an MT940 download channel, but D-25 mandates the hand-rolled parser and an `.sta` fixture is the only way to keep it test-driven. Synthesis preserves the shared anonymisation mapping across all four output files."
  - "Cross-format MT940 sample omitted with an explicit README flag — no real same-period MT940 export is reachable from ASN, so `CrossFormatDedupTest::mt940_after_csv` + `mt940_then_camt053` will be skipped or `@todo`-marked rather than fabricated"
  - "Phase-2 Pest group is documentation-only at the bootstrap level — per-test `->group('phase-2')` chains create the group implicitly. Avoids hidden global state and keeps test discovery local to the test file."
metrics:
  duration_minutes: 14
  completed_at: "2026-05-13T14:30:00Z"
  task_count: 3
  files_created: 7
  files_modified: 3
---

# Phase 02 Plan 01: Wave 0 Enablement (genkgo/camt + ASN fixture corpus + Pest group) Summary

Land the Wave 0 prerequisites for Phase 2: install the `genkgo/camt ^2.10` CAMT.053 parser, commit a shared-mapping-anonymised ASN fixture corpus (3-month CAMT, same-period CSV+CAMT pair, synthesised MT940 with audit trails for each), and document the per-test `->group('phase-2')` convention in the Pest bootstrap. Wave 1+ plans can now consume `Genkgo\Camt`, every Phase-2 adapter test has its gold fixture, and the focused dev loop `vendor/bin/pest --group=phase-2 --bail` is wired.

## Goals Achieved

- **`genkgo/camt 2.10.3` installed and locked.** Edited `composer.json`'s `require` block to add `"genkgo/camt": "^2.10"` adjacent to `"brick/money"`; ran `composer update genkgo/camt --no-interaction` to land the lock-file entry. Three transitive deps arrived: `moneyphp/money v4.9.0`, `jschaedl/iban-validation v2.7.0`, `symfony/options-resolver v8.0.8`. The `moneyphp/money` coexistence with `brick/money` is intentional and documented (the adapter boundary in Plan 02-03 converts at the genkgo input boundary).
- **3-month anonymised ASN CAMT.053 fixture committed.** `tests/fixtures/asn-camt053-sample-1.xml` (455 963 bytes, 11 284 lines, 229 entries) covers 2026-02-02 → 2026-04-30 spanning the RDDT / CCRD / ICDT / RCDT / RRCT / MDOP / ACMT / OTHR / IRCT bank-transaction families. The companion `asn-camt053-sample-1.md` records the empirical CAMT.053 sub-version (`001.02`, not the `001.08` Phase-2 research anticipated — see Open Follow-ups below), opening / closing balances, the deterministic counterparty mapping (34 unique IBANs → `NL00BANK00000000NN`, 39 unique names → the Phase-1 synthetic merchant pool), and the 294 free-text scrubs applied to `<Ustrd>` / `<AddtlNtryInf>`.
- **Same-period cross-format pair committed.** `tests/fixtures/asn-cross-format/february.csv` (18 516 bytes, 72 rows + header) and `february.camt053.xml` (140 895 bytes, 72 entries) cover the identical Feb-2026 statement window through two formats, both anonymised through the same shared mapping. The directory's `README.md` (5 810 bytes) documents the same-period guarantee, smoke-checks the first 10 transactions, and explicitly flags that no MT940 cross-format sample exists (with the consequence: `CrossFormatDedupTest::mt940_after_csv` + `mt940_then_camt053` will be skipped or `@todo`-marked).
- **Synthesised ASN MT940 fixture committed.** `tests/fixtures/asn-mt940-sample-1.sta` (2 719 bytes, 12 `:61:`/`:86:` pairs) is derived from a curated subset of the 3-month CAMT, rendered into ASN's published 34-char extended `:61:` customer-reference dialect with structured `?NN` `:86:` subfields and `EREF` / `SVWZ` GVC keywords inside the narrative. The companion `asn-mt940-sample-1.md` documents the synthesis rationale (ASN no longer offers MT940 downloads), the `:61:` / `:86:` field layouts the hand-rolled adapter will consume, the status mapping (`C` / `D` / `RC` / `RD`), and the GVC-keyword promotion rules (`EREF` → `sourceRef`, `SVWZ` → `description`).
- **Phase-2 Pest group documented.** Appended a comment block at the bottom of `tests/Pest.php` explaining that the `phase-2` group exists only through per-test `->group('phase-2')` chains and naming the focused dev loop `vendor/bin/pest --group=phase-2 --bail`. The comment is GSD-agnostic per `CLAUDE.md` (`feedback_codebase_gsd_agnostic`) — no `.planning/`, `PLAN.md`, `RESEARCH.md`, `CONTEXT.md`, or `D-NN` references.
- **Zero regression.** Full Pest suite — 239 passed / 1 skipped / 6 746 assertions / 9.21s. Larastan level 10 strict — `[OK] No errors`. Laravel Pint — `passed`. The empty-baseline `vendor/bin/pest --group=phase-2 --bail` invocation returns `No tests found`, the expected state at end of Wave 0.

## Implementation Details

### Task 2 — `genkgo/camt` install + Pest group

| Step | Action | Outcome |
|------|--------|---------|
| 1 | Edited `composer.json` to add `"genkgo/camt": "^2.10"` to the `require` block (adjacent to `brick/money`; `composer install` re-sorts alphabetically on disk because `sort-packages: true` is set in the project config) | manifest diff is reviewable; no Pint reformat noise |
| 2 | Ran `composer update genkgo/camt --no-interaction` (NOT `composer install` — the unmodified lock file would have rejected the new dep) | locked `genkgo/camt 2.10.3` + three transitives; `vendor/genkgo/camt/` populated |
| 3 | Verified `composer show genkgo/camt` reports `versions : * 2.10.3` | install confirmed |
| 4 | Appended the documentation-only `phase-2` comment block at the bottom of `tests/Pest.php` after the per-module `foreach` loop | bootstrap now documents the convention without introducing global state |
| 5 | Ran `vendor/bin/pest --group=phase-2 --bail` | `No tests found` (expected — no Phase-2 test has chained the group yet) |
| 6 | Ran the full `vendor/bin/pest` + `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` + `vendor/bin/pint --test` | all three green; Larastan baseline did NOT need regeneration |

The Larastan-baseline regeneration escape hatch documented in the plan was not needed — adding `genkgo/camt 2.10.3` produced zero new Larastan errors at level 10 strict against the existing codebase (Wave 1+ adapter code is where the genkgo type surface starts being exercised).

### Task 3 — Fixture corpus

The orchestrator pre-resolution payload (recorded as a deviation from the literal plan-text checkpoint, not a content deviation) handed over four ready-to-commit fixture data files and three audit md files:

| File | Bytes | Lines | Content summary |
|------|-------|-------|-----------------|
| `tests/fixtures/asn-camt053-sample-1.xml` | 455 963 | 11 284 | 229 `<Ntry>` entries across 2026-02-02 → 2026-04-30; opening balance `2 158,91 EUR`, closing balance `801,35 EUR`; namespace `camt.053.001.02`; own IBAN `NL00ASNB0123456789`; BIC `ASNBNL21` preserved (public); 9 bank-transaction families represented |
| `tests/fixtures/asn-camt053-sample-1.md` | 6 637 | 123 | Audit md documenting the schema sub-version, balance window, family distribution, the three-step CAMT-specific anonymisation, and the first 10 entries of the 39-entry name map. |
| `tests/fixtures/asn-mt940-sample-1.sta` | 2 719 | 30 | Synthesised single-statement MT940; 12 `:61:`/`:86:` pairs covering RDDT / ICDT / RCDT / CCRD / OTHR / ACMT / RRCT families; ASN-dialect 34-char extended `:61:` customer reference; structured `?NN`-subfield `:86:` blocks; `EREF` + `SVWZ` GVC keywords inside narrative |
| `tests/fixtures/asn-mt940-sample-1.md` | 7 221 | 144 | Audit md explaining the synthesis rationale, the dialect, the `:61:` / `:86:` field layouts, the status sign mapping, and the GVC-keyword promotion rules the hand-rolled adapter must implement |
| `tests/fixtures/asn-cross-format/february.csv` | 18 516 | 73 (header + 72) | ASN 20-column CSV export covering 2026-02-02 → 2026-02-27; `\r\n` line terminators (Excel-compatible, matches live ASN export) |
| `tests/fixtures/asn-cross-format/february.camt053.xml` | 140 895 | 3 503 | CAMT.053.001.02 over the same 72 transactions, balance window matching the real February statement; entry-for-entry aligned with the CSV |
| `tests/fixtures/asn-cross-format/README.md` | 5 810 | 95 | Cross-format directory README: documents the same-period guarantee, the alignment verification protocol, the first-10 smoke check, and the MT940 absence + downstream test-skip consequence |

All seven files passed the plan's automated verify gate:
- Both CAMT.053 XMLs load via `simplexml_load_file`
- MT940 `.sta` has at least one `:20:` (1), one `:61:` (12), one `:86:` (12) tag
- Every fixture contains the canonical own IBAN `NL00ASNB0123456789`
- Both CAMT XMLs declare an ISO 20022 CAMT.053 family namespace
- No audit md references `.planning/`, `PLAN.md`, `RESEARCH.md`, or `CONTEXT.md`

### Orchestrator pre-resolution of Task 1

The plan's Task 1 is typed `checkpoint:human-action` and gates the wave on the user handing over raw ASN exports. The orchestrator pre-resolved the checkpoint for this run: the user delivered the raw files out-of-band, the orchestrator ran a deterministic shared-mapping anonymiser, validated zero-PII via a denylist sweep, and staged the seven fixture files in the working tree before this executor was spawned. The executor's responsibility for Task 1 was therefore reduced to verifying the staged artefacts against the plan's `must_haves` contract — file existence, min-line counts, namespace presence, fixed own IBAN, no PII residual — which they pass. Recorded here as the canonical pre-resolution event so the SUMMARY reflects what actually happened on disk.

## Files Created (7)

| File | Lines | Bytes | Purpose |
|------|-------|-------|---------|
| `tests/fixtures/asn-camt053-sample-1.xml` | 11 284 | 455 963 | 3-month anonymised ASN CAMT.053.001.02 — gold fixture for the CAMT adapter snapshot test |
| `tests/fixtures/asn-camt053-sample-1.md` | 123 | 6 637 | Audit md for the 3-month CAMT corpus |
| `tests/fixtures/asn-mt940-sample-1.sta` | 30 | 2 719 | Synthesised single-statement ASN MT940 — drives hand-rolled parser tests |
| `tests/fixtures/asn-mt940-sample-1.md` | 144 | 7 221 | Audit md for the synthesised MT940 (includes synthesis rationale) |
| `tests/fixtures/asn-cross-format/february.csv` | 73 | 18 516 | Feb-2026 ASN 20-column CSV — cross-format dedup test left input |
| `tests/fixtures/asn-cross-format/february.camt053.xml` | 3 503 | 140 895 | Same Feb-2026 statement as CAMT.053.001.02 — cross-format dedup test right input |
| `tests/fixtures/asn-cross-format/README.md` | 95 | 5 810 | Cross-format directory README + MT940-absence flag |

## Files Modified (3)

| File | Change |
|------|--------|
| `composer.json` | Added `"genkgo/camt": "^2.10"` to the `require` block |
| `composer.lock` | Locked `genkgo/camt 2.10.3` + transitive `moneyphp/money v4.9.0`, `jschaedl/iban-validation v2.7.0`, `symfony/options-resolver v8.0.8` |
| `tests/Pest.php` | Appended the documentation-only `phase-2` group convention comment block at the bottom of the bootstrap |

## Tests

No new test files are added in this plan. The fixtures + group registration exist to support tests landing in Plans 02-02 / 02-03 / 02-04 / 02-05.

- **Full Pest suite:** 239 passed, 1 skipped, 6 746 assertions, 9.21s
- **Larastan level 10 strict:** `[OK] No errors`
- **Laravel Pint:** `passed`
- **Empty-baseline phase-2 group filter:** `vendor/bin/pest --group=phase-2 --bail` → `No tests found` (correct — Phase 2 has not authored tests yet)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking issue] Removed `02-RESEARCH.md` reference from `tests/fixtures/asn-mt940-sample-1.md`**

- **Found during:** Task 3 audit-md verification (pre-commit)
- **Issue:** The staged MT940 audit md referenced `02-RESEARCH.md §"MT940 hand-rolled state machine"` in its Caveats section. This violates `CLAUDE.md`'s `feedback_codebase_gsd_agnostic` rule and would have failed the plan's own Task 3 automated verify gate (`! grep -rE '\.planning|PLAN\.md|RESEARCH\.md|CONTEXT\.md'`).
- **Fix:** Rewrote the offending bullet to refer to "the conventional ASN MT940 family-to-id mapping" without the cross-doc citation. Functionally identical guidance to the original sentence; no information loss.
- **Files modified:** `tests/fixtures/asn-mt940-sample-1.md`
- **Verification:** Re-ran the grep — exit 1 (no matches), gate satisfied.
- **Commit:** Folded into the Task 3 fixture-corpus commit `8d4998c` (the edit landed before staging).

### Orchestrator pre-resolution of Task 1

Not a deviation from the plan's content — the user-action checkpoint is the literal Task 1 of the plan. Pre-resolution is the orchestrator's mode for sequential execution when the user has already delivered the artefact. Surfaced in the dedicated section above (Implementation Details / Orchestrator pre-resolution of Task 1) for traceability.

## Orchestrator Pre-Resolution Notes

The Task 1 checkpoint is `checkpoint:human-action` with the resume-signal expected to carry the absolute paths of the user-delivered raw ASN exports. In this run those raw files were validated, anonymised, and staged by the orchestrator before the executor was spawned. Concretely:

- **Source corpus:** 3-month real CAMT.053 + same-period Feb-2026 CAMT.053 + same-period Feb-2026 CSV. **No MT940** — ASN no longer ships an MT940 download channel on its modern online-banking surface.
- **Anonymisation script:** `/tmp/anonymize_phase2.py` (deliberately not committed). Built a single shared name+IBAN map from the union of all source counterparties and applied it consistently to four output files.
- **Empirical CAMT.053 sub-version:** ASN serves the older `001.02` variant, not the `001.08` the Phase-2 research doc anticipated. `genkgo/camt 2.10` supports `001.02` / `001.03` / `001.08`, so the Wave 2 adapter (Plan 02-03) must detect on the `xmlns` URI — already recorded in `tests/fixtures/asn-camt053-sample-1.md`.
- **MT940 fixture:** Synthesised from the anonymised CAMT corpus because no real `.sta` is reachable. The synthesis rationale is documented in `tests/fixtures/asn-mt940-sample-1.md`. The synthesised file is not a cross-format pair with the Feb-2026 CSV — `asn-cross-format/README.md` calls this out explicitly and names the specific `CrossFormatDedupTest` scenarios that will skip as a result.
- **PII sweep:** Zero hits across all seven staged files against the personal-name / address / employer / domain / city-specific-retailer denylist, and zero occurrences of the source own IBAN or any of the 34 real counterparty IBANs.

## Anonymisation Audit Trail

Three audit md files (`asn-camt053-sample-1.md`, `asn-mt940-sample-1.md`, `asn-cross-format/README.md`) carry the per-fixture audit. All three:

- Document the protocol applied (mirror `tests/fixtures/asn-sample-1.md`'s Phase-1 canonical, with Phase-2-specific extensions for free-text scrubbing and the synthesised MT940)
- Record element-level replacement counts (139 IBAN, 142 name, 294 free-text in the 3-month CAMT)
- Reproduce the shared counterparty mapping for the first 10 entries so the mapping is auditable without re-running the anonymiser
- Carry the empirical confirmation rather than the predicted research doc values (sub-version `001.02`, balance window `2 158,91 → 801,35 EUR`, 229 entries, 9 BkTxCd families)
- Contain zero references to `.planning/`, `PLAN.md`, `RESEARCH.md`, or `CONTEXT.md` (verified by the plan's own automated gate)

## Open Follow-ups for Wave 2

1. **CAMT.053 sub-version coverage gap.** The committed corpus is `001.02` only. If Wave 2's `AsnCamt053AdapterTest` must also exercise the `001.08` parser path (the namespace-detection variant), an additional 001.08 fixture is needed. Recommended approach for Plan 02-03 — fabricate a minimal-entry 001.08 fixture by hand-editing the `xmlns` of the real 001.02 file plus the small set of element renames between sub-versions, document the synthesis in a new audit md, and snapshot-test the namespace-detection branch specifically. Alternatively, Plan 02-03 may decide that `001.02`-only coverage is acceptable for the MVP and that 001.08 lands when a user supplies one.
2. **Missing MT940 cross-format pair.** The current corpus cannot exercise `CrossFormatDedupTest::mt940_after_csv` or `mt940_then_camt053`. Plan 02-05's CrossFormatDedupTest will need to either `->skip()` these scenarios with the README's reason string, mark them `@todo`, or wait for ASN to re-introduce MT940 downloads (unlikely). Recommend `->skip()` with the canned reason `'No same-period MT940 export available from ASN'`.
3. **moneyphp/money + brick/money coexistence.** The new `moneyphp/money v4.9.0` transitive lands alongside `brick/money 0.11`. Plan 02-03's `AsnCamt053Adapter` must convert `Money\Money` (genkgo's API) to `Brick\Money\Money` at the adapter boundary so the project's domain layer continues to see only `Brick\Money\Money`. A Larastan rule or an architecture test on `Modules/Ingestion/Public` that forbids `Money\Money` from leaking past the adapter boundary would close the loop — recommended for Plan 02-03's contract-test additions.
4. **`:61:` 34-char extended customer reference parser caveat.** The audit md documents that the synthesised MT940 uses ASN's 34-char extended `:61:` customer reference, not the SWIFT-standard 16. The hand-rolled adapter in Plan 02-04 must read the extended variant (and the audit md illustrates the layout). Worth a unit test that asserts the parser rejects a SWIFT-standard 16-char MT940 and emits a clear error, since the project ships exclusively against the ASN dialect.
5. **Fingerprint v3 (Plan 02-02) must run against this corpus.** The 229-entry 3-month CAMT exercises the full BkTxCd family distribution. Plan 02-02's `RederiveFingerprintsCommand` should include a feature test that imports `asn-camt053-sample-1.xml`, derives v3 fingerprints across all 229 entries, and asserts zero collisions — that is the corpus's first stress test of the v3 fingerprint shape.

## Threat Flags

None. This plan introduces no new network endpoints, auth paths, or trust-boundary changes beyond the planned `genkgo/camt` install (covered by the plan's existing threat register T-02-01-02).

## Self-Check: PASSED

All claimed files exist on disk and all commit hashes exist in git history:

- `tests/fixtures/asn-camt053-sample-1.xml` — FOUND
- `tests/fixtures/asn-camt053-sample-1.md` — FOUND
- `tests/fixtures/asn-mt940-sample-1.sta` — FOUND
- `tests/fixtures/asn-mt940-sample-1.md` — FOUND
- `tests/fixtures/asn-cross-format/february.csv` — FOUND
- `tests/fixtures/asn-cross-format/february.camt053.xml` — FOUND
- `tests/fixtures/asn-cross-format/README.md` — FOUND
- `composer.json` (with `genkgo/camt`) — FOUND
- `composer.lock` (with `genkgo/camt 2.10.3`) — FOUND
- `tests/Pest.php` (with `phase-2` block) — FOUND
- Commit `57d982d` (Task 2 — genkgo/camt + Pest group) — FOUND
- Commit `8d4998c` (Task 3 — fixture corpus) — FOUND
