# Phase 3 — PDF Pivot Audit (Downstream CSV Leftovers)

**Audit date:** 2026-05-15
**Audit scope:** All artifacts in `.planning/phases/03-ics-cards-multi-currency-display/` *except* `03-01-PLAN.md`, `03-02-PLAN.md`, `03-CONTEXT.md`, `03-DISCUSSION-LOG.md` (the post-pivot artifacts — already aligned with D-31a / D-32a / D-49–D-56).
**Producing context:** `/gsd-plan-phase 3 --replan-plan 01,02` (selective replan + audit). Plans 03-01 and 03-02 were rewritten earlier this session against the revised CONTEXT.md. This document is the cross-reference of every stale ICS-CSV-era reference that survives elsewhere in the phase directory and therefore must be patched before `/gsd-execute-phase 3`.

> **Important:** This file is non-blocking. The orchestrator did not apply any of these patches automatically — they require human review (the new error copy in 03-UI-SPEC.md is user-facing; the rename of `IcsCsvImportTest.php` → `IcsPdfImportTest.php` in 03-03 changes which test file is treated as the contract). Treat this as the patch list for a second pass.

---

## Summary

| File                              | CSV-era refs | Action                                                  |
| --------------------------------- | ------------ | ------------------------------------------------------- |
| `03-RESEARCH.md`                  | 53           | **Supplement, do not rewrite.** See §5.                 |
| `03-PATTERNS.md`                  | 53           | **Supplement, do not rewrite.** See §5.                 |
| `03-VALIDATION.md`                | 10           | **Targeted rewrite required.** See §4.                  |
| `03-UI-SPEC.md`                   | 2 (+wider copy) | **Targeted rewrite required.** See §3.               |
| `03-03-PLAN.md`                   | ~30          | **Targeted rewrite required.** See §2.                  |
| `03-04-PLAN.md`                   | 0            | ✓ Clean (Settings page — format-agnostic).              |
| `03-05-PLAN.md`                   | 0            | ✓ Clean (transactions-list currency toggle).            |
| `03-06-PLAN.md`                   | 0            | ✓ Clean (dashboard GROUP-BY-currency).                  |
| `03-07-PLAN.md`                   | 0            | ✓ Clean (transaction-detail FX-rate surface).           |

**Net work:** one downstream plan (03-03), two contract artifacts (UI-SPEC, VALIDATION). The two research-style artifacts (RESEARCH.md, PATTERNS.md) are not on the critical path — they're inputs to planning, and planning for plans 01+02 has already happened against the revised CONTEXT.md.

---

## 1. Canonical token-rename map

The terms below are the load-bearing renames. Every patch in §2–§5 can be derived from this table.

| Stale token                                                     | Replacement                                                  | Rationale                                                                                          |
| --------------------------------------------------------------- | ------------------------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| `IcsCsvAdapter`                                                 | `IcsPdfAdapter`                                              | New adapter (CONTEXT.md D-31a). 03-02 builds this.                                                 |
| `IcsCsvHeaderProfile`                                           | `IcsPdfHeaderProfile`                                        | New constants class. 03-02 builds this.                                                            |
| `IcsCsvColumnMap`                                               | `IcsPdfExtractionMap`                                        | Constants shift from "column indices" to "anchor tokens + regex patterns" (D-53).                  |
| `sniffIcsCsv`                                                   | `sniffIcsPdf`                                                | `%PDF-` magic-byte sniff (D-54). 03-02 builds this.                                                |
| `IcsCsvAdapterTest`                                             | `IcsPdfAdapterTest`                                          | New adapter's unit-test class. 03-01 scaffolds; 03-02 drives Green.                                |
| `IcsCsvImportTest`                                              | `IcsPdfImportTest`                                           | New end-to-end feature test. 03-01 scaffolds; 03-02 drives Green (minus the 2 wizard scaffolds 03-03 owns). |
| `'ics-csv'` (format leaf key)                                   | `'ics-pdf'`                                                  | `SourceAdapterRegistry` key + wizard format value (D-33 updated leaf, D-54).                       |
| `ics-sample-1.csv`                                              | `ics-sample-1.txt` (+ `ics-sample-tiny.pdf` for contract test) | Committable redacted fixture is `*.txt` (D-32a); a tiny synthetic `.pdf` is needed for the wire-level idempotency-contract test (03-02 Task 5). |
| `league/csv` (in ICS-format context)                            | `spatie/pdf-to-text` (D-31a)                                 | League/CSV stays in use for the ASN CSV adapter — only the ICS-specific mentions change.           |
| `csv,txt,xml,sta,mt940,940` (UploadWizard `mimes:` rule)        | `csv,txt,xml,sta,mt940,940,pdf`                              | Append `pdf` (D-54). Existing ASN formats are unchanged.                                           |
| `'in:asn-csv,asn-camt053,asn-mt940,ics-csv'` (validator allow-list) | `'in:asn-csv,asn-camt053,asn-mt940,ics-pdf'`             | One-token swap (D-33 leaf-key update).                                                             |
| User-facing copy: "Drop in the CSV you downloaded from the ICS portal." | "Drop in the PDF you downloaded from the Mijn ICS portal." | UI-SPEC error copy (line 196).                                                                     |
| User-facing copy: "This CSV doesn't match the expected ICS column layout..." | "That file doesn't appear to be an ICS PDF statement..." | UI-SPEC error copy (line 197). The "column layout" framing is wrong for PDF — there is no header row. |
| "Format options (when ICS) | `CSV`"                             | "Format options (when ICS) | `PDF`"                          | UI-SPEC line 194 — the only Format-dropdown option for ICS.                                        |

---

## 2. `03-03-PLAN.md` patch list — cascading wizard picker + ICS-Account naming

**Scope of 03-03:** UploadWizard's flat dropdown → two-step issuer→format cascade (D-33), plus the generalisation of the IBAN-naming step into an ICS-Account naming step (D-36/D-38). Every reference to "CSV" inside an ICS-format slot must be renamed.

**`files_modified` (line 13):**
- `Modules/Import/tests/Feature/IcsCsvImportTest.php` → `Modules/Import/tests/Feature/IcsPdfImportTest.php`

**`<files_to_read>` block (line 76):**
- `@Modules/Import/tests/Feature/IcsCsvImportTest.php` → `@Modules/Import/tests/Feature/IcsPdfImportTest.php`

**Wizard option labels and validator (Task body):**
- Line 88: "Format options when ICS: 'CSV' (ics-csv)" → "Format options when ICS: 'PDF' (ics-pdf)"
- Line 101: `"in:asn-csv,asn-camt053,asn-mt940,ics-csv"` → `"in:asn-csv,asn-camt053,asn-mt940,ics-pdf"`
- Lines 127–128: every `'ics-csv'` literal → `'ics-pdf'`
- Line 130: validator allow-list → same token swap
- Line 134: "Append `'ics-csv'` to `SUPPORTED_FORMATS`" → "Append `'ics-pdf'` to `SUPPORTED_FORMATS`"
- Line 147: `['value' => 'ics-csv', 'label' => 'CSV']` → `['value' => 'ics-pdf', 'label' => 'PDF']`
- Line 164: "sourceFormat would be 'ics-csv' which is not in the visible ASN options" → "sourceFormat would be 'ics-pdf' which is not in the visible ASN options"
- Line 166: validator allow-list mention → same token swap
- Line 173 (the `<automated>` verify block): `grep -q "in:asn-csv,asn-camt053,asn-mt940,ics-csv"` → `grep -q "in:asn-csv,asn-camt053,asn-mt940,ics-pdf"`
- Line 180 (acceptance criterion): same
- Lines 266–267 (test E description): all `'ics-csv'` → `'ics-pdf'`
- Lines 285, 299, 303, 307 (test E code): all `'ics-csv'` literals → `'ics-pdf'`
- Line 304: the UploadedFile fake — `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv` → `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` (the wire-level fake should be a real PDF for the `%PDF-` magic-byte check to pass)

**`Mimes:` validator extension (NEW work — not in current 03-03):**
- D-54 requires the `mimes:` rule to gain `pdf`. The current 03-03 does NOT mention this; the original plan assumed `ics-csv` would fit the existing `csv,txt,xml,sta,mt940,940` allow-list. This is a real omission and should be folded into Task 2 (or a new sub-task): `'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xml,sta,mt940,940,pdf']`.

**ICS-Account naming branch (Task 4) — file rename + scaffold target:**
- Lines 339–432: every reference to `IcsCsvImportTest.php` → `IcsPdfImportTest.php`. The two RED scaffolds 03-03 drives Green now live in `IcsPdfImportTest.php` (per the new 03-02). The acceptance gate `grep -c 'toBe(false' Modules/Import/tests/Feature/IcsPdfImportTest.php` returns `0` after this task.
- Line 373 / 380: `sourceFormat === 'ics-csv'` → `sourceFormat === 'ics-pdf'`
- Line 400: "when the trigger fired for `ics-csv`, it inserts an `accounts` row" → same with `ics-pdf`
- Line 427: "the two RED scaffolds in `Modules/Import/tests/Feature/IcsCsvImportTest.php`" → "...`IcsPdfImportTest.php`"

**Estimated patch size for 03-03:** ~30 line edits, one new sub-task (mimes:pdf), no structural change. The plan's wave + dependency contract is intact.

---

## 3. `03-UI-SPEC.md` patch list — user-facing copy + format-leaf-key

**Two-step picker option set (line 18):**
- Current: "ASN → CSV / CAMT.053 / MT940; ICS → CSV."
- After: "ASN → CSV / CAMT.053 / MT940; ICS → PDF."

**Out-of-scope list (line 28):**
- Current: "ICS Excel ingestion (D-31 — CSV only)."
- After: "ICS CSV / Excel ingestion (D-31 reversed 2026-05-15 — Mijn ICS consumer portal is PDF-only; revisit if ICS ever ships CSV/Excel)."

**Format-options table (line 194):**
- Current: "Format options (when ICS) | `CSV`"
- After: "Format options (when ICS) | `PDF`"

**Pre-parse error copy (lines 196–197):**
- Current:
  > Pre-parse MIME error (ICS path) | `That file doesn't look like an ICS export. Drop in the CSV you downloaded from the ICS portal.`
  > Pre-parse header error (ICS path) | `This CSV doesn't match the expected ICS column layout. If ICS changed their export format, file an issue.`
- After:
  > Pre-parse MIME error (ICS path) | `That file doesn't look like a PDF. Drop in the ICS PDF export you downloaded from the Mijn ICS portal.`
  > Pre-parse header error (ICS path) | `This file does not start with %PDF-. If you exported a different file format from ICS by mistake, re-download the monthly statement PDF.`

(The replacement strings are already locked verbatim in the new 03-02 plan's `sniffIcsPdf()` implementation — see [03-02-PLAN.md:794–801]. Keep the UI-SPEC strings in sync with the source.)

**Leaf-key + validator (line 199):**
- Current: "The leaf `sourceFormat` value remains the wire format: `asn-csv`, `asn-camt053`, `asn-mt940`, `ics-csv`. Validator: `'in:asn-csv,asn-camt053,asn-mt940,ics-csv'`."
- After: "The leaf `sourceFormat` value remains the wire format: `asn-csv`, `asn-camt053`, `asn-mt940`, `ics-pdf`. Validator: `'in:asn-csv,asn-camt053,asn-mt940,ics-pdf'`."

**Page subheading (line 189) — already correct:**
- "Drop in an ASN or ICS export." is format-agnostic and reads naturally for both CSV and PDF. No change.

---

## 4. `03-VALIDATION.md` patch list — gate commands

The Nyquist-style coverage table (lines 43–47, 51) needs the gate commands rewired to the new file names. The dimension-coverage framing itself is unchanged.

**Line 43 — gate `03-01-T2`:**
- Current: `test -f Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv && [ "$(grep -Ec '[0-9]{12,}' Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv)" = "0" ] && grep -q 'phase-3' tests/Pest.php`
- After: `test -f Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt && [ "$(grep -Ec '[0-9]{12,}' Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt)" = "0" ] && test -f Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf && grep -q 'phase-3' tests/Pest.php`

**Line 44 — gate `03-01-T3`:**
- Current: lists `IcsCsvAdapterTest.php`, `IcsCsvImportTest.php`.
- After: lists `IcsPdfAdapterTest.php`, `PdfTextExtractorTest.php`, `IcsAmountParserTest.php`, `IcsDateParserTest.php`, `IcsPdfImportTest.php`, `SettingsPageTest.php`, `TransactionsListCurrencyToggleTest.php`, `DashboardCurrencyModeTest.php`, `TransactionDetailFxRateTest.php` (the 9-file scaffold set produced by the new 03-01). Total `it(` count budget stays `>= 30`.

**Lines 46–47 — gates `03-02-T2`, `03-02-T3`:**
- All `IcsCsvAdapterTest` → `IcsPdfAdapterTest`.
- All `IcsCsvImportTest` → `IcsPdfImportTest`.
- The `--filter='imports every parsed row\|returns zero new rows when re-importing\|persists settled_amount_minor and settled_currency\|persists native + settled'` clause: keep — these test names are preserved in the new 03-02 plan with the same intent.

**Line 51 — gate `03-03-T4`:**
- Current: `Modules/Import/tests/Feature/IcsCsvImportTest.php`
- After: `Modules/Import/tests/Feature/IcsPdfImportTest.php` (twice — once in the `pest` invocation, once in the `grep -c 'toBe(false'` clause).

**Line 70 — "Fixtures" checklist:**
- Current: `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv` — anonymised ICS CSV fixture (raw user file → redacted card numbers / cardholder names; preserve dates / amounts / currencies / merchants verbatim per D-32)
- After (two entries):
  - `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt` — redacted pdftotext output (extract-then-redact-text protocol per D-32a; preserves dates / amounts / currencies / merchants verbatim; 12+ digit runs and IBAN-shaped strings absent)
  - `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` — tiny synthetic anonymised PDF for the wire-level `IdempotencyContractTest 'ics-pdf'` row (≤ 10 KB, contains the literal `SYNTHETIC` anchor)

**Line 74 — test file checklist:**
- Current: `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php` — failing scaffolds for ING-04 + LED-03 (driven GREEN in plan 03-02)
- After: same path with `IcsCsv` → `IcsPdf`, AND add the three new scaffold files (`PdfTextExtractorTest.php`, `IcsAmountParserTest.php`, `IcsDateParserTest.php`) from the revised 03-01 plan.

**Line 75:**
- Current: `Modules/Import/tests/Feature/IcsCsvImportTest.php` — failing scaffolds for ING-04 end-to-end (four driven GREEN in 03-02; two in 03-03)
- After: same path with `IcsCsv` → `IcsPdf`. Driven-Green counts are unchanged (seven of nine driven by 03-02; the two PreviewWizard naming scaffolds are 03-03's).

**Line 80:**
- Current: `tests/Contracts/IdempotencyContractTest.php` — dataset row for `ics-csv` (added in plan 03-02 Task 3)
- After: dataset row for `ics-pdf` (added in plan 03-02 Task 5).

---

## 5. `03-RESEARCH.md` + `03-PATTERNS.md` — supplement rather than rewrite

**Rationale for not rewriting these in place:** RESEARCH.md and PATTERNS.md are *inputs to planning*, not contracts. The planning for plans 03-01 and 03-02 has already happened against the revised CONTEXT.md (the post-pivot source of truth) — those plans are now the contracts for execute-phase, and the CSV-era RESEARCH/PATTERNS content was deliberately bypassed during the replan (per the `critical_warning` in the planner prompt and verified by the plan-checker).

**The simpler, less-error-prone shape:** append a PDF-extension to each file rather than do a 53-edit token rename through ~80 KB of CSV-flavoured content.

**Proposed addendum stubs:**

```markdown
# (Append to bottom of 03-RESEARCH.md)
---

## Addendum 2026-05-15 — PDF Pivot

The body above (dated 2026-05-13) describes a CSV-based ICS ingestion path that was reversed on 2026-05-15. See 03-CONTEXT.md (`D-31 (REVERSED)`, `D-31a (NEW)`, D-32a, D-49–D-56) for the canonical PDF-based plan and 03-01-PLAN.md / 03-02-PLAN.md for the executable contracts. The Asn-side reference patterns this document cites (AsnCamt053Adapter stateful-adapter pattern, AsnAmountParser/AsnDateParser shape, league/csv usage for ASN's CSV adapter) remain valid — only the ICS-side recommendations are obsolete.

Quick post-pivot stack delta:
- `spatie/pdf-to-text` (NEW direct dependency, thin wrapper around poppler `pdftotext`).
- `poppler-utils` (NEW system dependency on macOS Herd via `brew install poppler`).
- `league/csv` — no longer used for ICS; remains in use for AsnCsvAdapter.
- `phpoffice/phpspreadsheet` — never introduced (already deferred in the original RESEARCH).
- `brick/math BigDecimal` — load-bearing for `fx_rate_used` derivation per D-39; covered in 03-02.
```

```markdown
# (Append to bottom of 03-PATTERNS.md)
---

## Addendum 2026-05-15 — PDF Pivot

The body above (dated 2026-05-13) maps every ICS file to an Asn-CSV analog. After the PDF pivot of 2026-05-15, the ICS rows map differently:

| Stale ICS row                                                  | Post-pivot replacement                                          | Closest analog                              |
| -------------------------------------------------------------- | --------------------------------------------------------------- | ------------------------------------------- |
| `Modules/Ingestion/Internal/Adapters/Ics/IcsCsvAdapter.php`    | `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php`     | `AsnCamt053Adapter.php` (stateful-adapter)  |
| `Modules/Ingestion/Internal/Adapters/Ics/IcsCsvHeaderProfile.php` | `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfHeaderProfile.php` | `AsnCamt053HeaderProfile.php`           |
| `Modules/Ingestion/Internal/Adapters/Ics/IcsCsvColumnMap.php`  | `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfExtractionMap.php` | (new — anchor tokens + regex patterns)    |
| (none)                                                         | `Modules/Ingestion/Internal/Adapters/Ics/PdfTextExtractor.php`  | (new — wraps spatie/pdf-to-text exec)       |
| (none)                                                         | `Modules/Ingestion/Internal/Adapters/Ics/IcsAmountParser.php`   | `AsnAmountParser.php`                       |
| (none)                                                         | `Modules/Ingestion/Internal/Adapters/Ics/IcsDateParser.php`     | `AsnDateParser.php`                         |
| `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php` | `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php` | `AsnCamt053AdapterTest.php`              |
| `Modules/Import/tests/Feature/IcsCsvImportTest.php`            | `Modules/Import/tests/Feature/IcsPdfImportTest.php`             | `AsnCamt053ImportTest.php`                  |
| Wizard adds `'ics-csv'` to `SUPPORTED_FORMATS`                 | Wizard adds `'ics-pdf'` to `SUPPORTED_FORMATS` + `pdf` to `mimes:` allow-list | `AsnCamt053`-equivalent wizard option  |
| `sniffIcsCsv()` arm in `HeaderSniffer`                         | `sniffIcsPdf()` arm — checks first 5 bytes against `%PDF-`      | `sniffAsnCamt053()` (XML-style sniff)       |
| `tests/Contracts/IdempotencyContractTest.php` adds `'ics-csv'` dataset row | Adds `'ics-pdf'` dataset row pointing at `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` | existing dataset shape  |

All Asn-side rows in the table above this addendum remain authoritative. The new PDF adapter mirrors the stateful-adapter shape pioneered by Phase 2's CAMT/MT940 adapters (because Phase 3 also populates `statement_summaries`), not Phase 1's pure-streaming AsnCsvAdapter shape.
```

This is ~50 lines of new content vs ~100+ scattered token edits. The user can drop the addenda in with two `Write` calls.

---

## 6. Recommended fix order

If the user wants to run `/gsd-execute-phase 3` cleanly:

1. **§2 (03-03-PLAN.md)** — Highest priority. It's the contract for plan 03-03's wave; execute-phase will load this verbatim. Estimated 5 minutes by hand or one targeted planner-revision call.
2. **§3 (03-UI-SPEC.md)** — User-facing copy. The new error strings are already locked in 03-02's `sniffIcsPdf()` source code; the UI-SPEC should mirror them. Estimated 2 minutes by hand.
3. **§4 (03-VALIDATION.md)** — Gate commands. The dimension framing is unchanged; only the file names rotate. Estimated 3 minutes by hand or 1 planner-revision call.
4. **§5 (RESEARCH.md, PATTERNS.md)** — Lowest priority. Append-only addenda — these files aren't loaded by execute-phase; they were loaded by plan-phase (already complete).

**Total estimated effort:** ~10–15 minutes by hand, or one targeted planner-revision call covering §2–§4.

---

## 7. What's already clean (no action)

- `03-01-PLAN.md` — rewritten this session, post-pivot.
- `03-02-PLAN.md` — rewritten this session, post-pivot.
- `03-CONTEXT.md` — revised 2026-05-15, the source of truth.
- `03-DISCUSSION-LOG.md` — captures the rationale behind the May-15 pivot.
- `03-04-PLAN.md` through `03-07-PLAN.md` — format-agnostic (Settings page, transactions-list toggle, dashboard mode, transaction-detail FX rate). Zero CSV-era references.

---

## 8. Mechanical verification

Sanity-check command — run after applying the patches in §2–§4. After §1–§4 are done, the only matches should come from `RESEARCH.md` / `PATTERNS.md` *above* their new addenda (deliberately retained historical context — see §5):

```bash
grep -rlE "IcsCsv|ics-csv|ics-sample-1\.csv|IcsCsvAdapterTest|IcsCsvImportTest|sniffIcsCsv" \
  .planning/phases/03-ics-cards-multi-currency-display/
```

After §5 is also applied, the `RESEARCH.md` and `PATTERNS.md` content above each new addendum still matches — that's expected (historical context preserved; the addendum is the canonical post-pivot guidance). Treat any other file appearing in the above grep output as a real residual CSV leftover.

If you need to compare the new 03-01 / 03-02 plans against their pre-pivot CSV-era versions, use git history rather than reaching for a sibling file:

```bash
git show HEAD~1:.planning/phases/03-ics-cards-multi-currency-display/03-01-PLAN.md
git diff HEAD~1 -- .planning/phases/03-ics-cards-multi-currency-display/03-02-PLAN.md
```

*— end of audit —*
