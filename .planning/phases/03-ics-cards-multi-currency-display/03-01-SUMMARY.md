---
phase: 03-ics-cards-multi-currency-display
plan: 01
subsystem: testing
tags: [pdf-to-text, poppler, pdftotext, ics-cards, anonymisation, pest, phase-3-group, scaffolds, fixture]

# Dependency graph
requires:
  - phase: 02-asn-second-source-and-statement-summaries
    provides: phase-2 group convention (mirrored as phase-3); statement_summaries shape (StatementSummaryData DTO already on the SourceAdapter contract); FingerprintComposer v3 (per-row dedup anchor); IBAN check-digit anonymisation convention (NL00BANK0000000000)
provides:
  - spatie/pdf-to-text 1.55.0 installed and resolvable at the Composer boundary
  - .gitignore carve-out for /local/ so raw ICS PDFs never enter the repo
  - README documents `brew install poppler` + `pdftotext -v` sanity check + poppler.freedesktop.org link
  - Anonymised ICS PDF text fixture (Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt — 102 lines, 15571 bytes, UTF-8)
  - Fixture record markdown documenting every empirical detail Wave 2 needs (anchor tokens, per-page noise, FX-row visual shape, source_ref availability, markup separability, statement summary tokens, Dutch date/amount formats, masked-card metadata schema)
  - Re-runnable in-repo anonymisation script (scripts/anonymize_ics_text.php — 8 regex-driven passes, no Composer deps)
  - Tiny synthetic 849-byte PDF (Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf) parseable via pdftotext to a text containing the literal `SYNTHETIC` — used by the Wave 2 idempotency-contract test to exercise the real pdftotext binary path
  - Reproducible tiny PDF generator script (scripts/generate_tiny_ics_pdf.php) so any future contributor can regenerate the fixture deterministically
  - phase-3 Pest group convention documented in tests/Pest.php
  - Nine failing test scaffolds (55 it() cases total) committed Red across Ingestion / Import / Core / Ledger so Waves 2..N have a complete behavioural target
  - Anonymisation-sweep guard test (tests/Feature/AnonymisedFixtureSweepTest.php — 5 cases, Green) that fails the build the moment any PII-shaped string re-enters a committed ICS fixture
affects: [03-02, 03-03, 03-04, 03-05, 03-06, 03-07]

# Tech tracking
tech-stack:
  added:
    - spatie/pdf-to-text 1.55.0 (Composer)
    - poppler / pdftotext 26.04.0 (system, via brew — documented in README)
  patterns:
    - "Empirical extract-then-redact-text protocol (raw PDF stays under local/ at chmod 600; only the anonymised .txt fixture enters git) — D-32a"
    - "Hand-crafted minimal PDF byte stream for the tiny synthetic fixture (~849 B) — beats cupsfilter's ~17 KB Cairo-pipeline overhead and stays under the 10 KB budget"
    - "Per-fixture companion markdown documenting empirical anchor tokens, FX-row shape, source_ref availability, statement-summary token list, Dutch date/amount formats, masked-card metadata schema — same shape as Phase 1/2 fixture records"
    - "phase-3 Pest group: no global registration; each it() chains ->group('phase-3') — focused dev loop is `vendor/bin/pest --group=phase-3 --bail`"
    - "Failing-scaffold convention: every Red scaffold uses `expect(true)->toBe(false, 'scaffold — implemented in plan 03-NN')`; never `->skip()` (Dimension 8 Nyquist gate)"
    - "Anonymisation-sweep guard: Green-by-design Pest test reading the committed fixture's bytes + a Symfony Process integration case exec()'ing pdftotext, guarded by ->group('integration') so poppler-less CI hosts can --exclude-group=integration"

key-files:
  created:
    - scripts/anonymize_ics_text.php (committed in Task 3 — 8-pass regex redactor, no Composer deps)
    - scripts/generate_tiny_ics_pdf.php (committed in Task 5 — hand-crafted PDF 1.4 byte-stream generator)
    - Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt
    - Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md
    - Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf
    - Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.md
    - Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php
    - Modules/Ingestion/tests/Unit/Adapters/Ics/PdfTextExtractorTest.php
    - Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php
    - Modules/Ingestion/tests/Unit/Adapters/Ics/IcsDateParserTest.php
    - Modules/Import/tests/Feature/IcsPdfImportTest.php
    - Modules/Core/tests/Feature/SettingsPageTest.php
    - Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php
    - Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php
    - Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php
    - tests/Feature/AnonymisedFixtureSweepTest.php
  modified:
    - composer.json (added spatie/pdf-to-text)
    - composer.lock
    - .gitignore (added /local/)
    - README.md (added Poppler prerequisite + pdftotext -v sanity check)
    - tests/Pest.php (added phase-3 group convention docblock)

key-decisions:
  - "Empirical Mijn ICS PDF uses revolving-credit summary nomenclature (Vorig openstaand saldo / Totaal ontvangen betalingen / Totaal nieuwe uitgaven / Nieuw openstaand saldo / Bestedingslimiet / Minimaal te betalen bedrag) — NOT the current-account tokens (Periode / Beginsaldo / Eindsaldo / Totaal nieuw saldo / Totaal betaald) anticipated by CONTEXT.md D-51. CONTEXT.md addendum required."
  - "Empirical Mijn ICS PDF has NO `Pagina X van Y` per-page footer (CONTEXT.md D-53 anticipated one). The page index lives inline at the end of the statement-summary header line as `1 van 2` / `2 van 2`."
  - "Empirical Mijn ICS PDF only renders the card last-four (e.g. `Uw Card met als laatste vier cijfers 1333`) — never the full PAN. The redaction script injects a synthetic `****-****-****-XXXX` placeholder on the watermark line so contract greps still hit; the underlying source has no full PAN to redact in the first place."
  - "FX-row shape (D-35) confirmed empirically as shape (b) — two-line block: native amount on the merchant row, `Wisselkoers <CURRENCY> <rate>` on the immediately-following row. Three real FX rows present in the fixture (Augment Code USD→EUR, Audible UK GBP→EUR, Vitrus USD→EUR); no synthetic FX row was needed."
  - "source_ref (D-34) is unavailable — the empirical statement carries no per-transaction authorisation code / slip number / transaction reference. Disposition: source_ref will be NULL for every ICS PDF row; the v3 fingerprint tuple is the only dedup anchor (same posture as Phase 2 MT940 entries with missing EREF)."
  - "ICS markup (D-40) is not itemised separately — the `Wisselkoers` line shows the effective (post-markup) rate only. D-40's 'rolled into settled' branch applies. Per-transaction extracted-text block stays in rawPayload so a future phase can re-derive markup without re-importing."
  - "Tiny synthetic PDF generator path: hand-crafted PDF 1.4 byte stream (~849 B) via scripts/generate_tiny_ics_pdf.php — cupsfilter (the plan's primary path) produced ~17 KB output even for a six-line input, busting the 10 KB acceptance gate. The hand-crafted fallback was explicitly authorised in the plan's Task 5 step 6."
  - "Scaffolds use the EMPIRICAL summary-token names (e.g. `Vorig openstaand saldo`) in failure-message comments — NOT the CONTEXT.md aspirational set — so plan 03-02 implements against reality."

patterns-established:
  - "Anonymisation script lives in repo (scripts/anonymize_ics_text.php) — the Phase 1 CSV anonymisation script was throwaway under /tmp; Phase 3 onwards commits the redaction tool alongside the redacted fixture so future runs are auditable."
  - "Fixture record markdown headings are the contract: each Wave-0 plan in subsequent phases should mirror the section list (Extraction command / Anonymisation protocol / Statement layout / Anchor tokens / Per-page noise / FX-row visual shape / source_ref availability / Markup separability / Statement summary tokens / Dutch date/amount formats / Masked-card metadata schema)."
  - "Anonymisation-sweep guard test pattern (project-root tests/Feature/) — load committed fixtures via file_get_contents + preg_match; Symfony Process for any pdftotext round-trip; tag integration cases ->group('integration') so CI hosts without the external binary can --exclude-group=integration."

requirements-completed: []  # 03-01 is a Wave 0 enablement plan — it lands fixtures + scaffolds, NOT a Green slice of any phase requirement. ING-04 / LED-03 / MC-02 / UI-06 stay open until plans 03-02..03-07.

# Metrics
duration: 18min
completed: 2026-05-15
---

# Phase 3 Plan 01: Wave 0 ICS PDF Enablement Summary

**Empirical anonymised Mijn ICS PDF fixture + nine Red scaffolds (55 it() cases) + Green anonymisation-sweep guard — Phase 3 Waves 2..7 now have a complete behavioural target to drive Green against real Dutch revolving-credit-statement shape.**

## Performance

- **Duration:** ~18 min (across 5 atomic task commits)
- **Started:** 2026-05-15T18:00:47+02:00 (Task 1 commit)
- **Completed:** 2026-05-15T18:18:54+02:00 (Task 7 commit)
- **Tasks:** 7 (5 auto + 2 human checkpoints — Task 2 PDF-handoff, Task 4 redaction verification)
- **Files created:** 16 (3 in Task 1, 3 in Task 3, 3 in Task 5, 10 in Task 6, 1 in Task 7 — minus overlaps)
- **Files modified:** 5 (composer.json / composer.lock / .gitignore / README.md / tests/Pest.php)

## Accomplishments

1. **spatie/pdf-to-text 1.55.0 installed** and documented (composer.json/.lock + README brew install poppler + pdftotext -v sanity check + poppler.freedesktop.org security-conscious link).
2. **`/local/` carve-out** added to .gitignore so the raw user-supplied PDF stays out of git forever.
3. **Empirical anonymised ICS PDF text fixture** (`Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt`) committed — 102 lines, 15571 bytes, UTF-8 — preserving three real FX rows (Augment Code USD→EUR, Audible UK GBP→EUR, Vitrus USD→EUR) and every empirical Dutch token Wave 2 will parse against.
4. **Re-runnable anonymisation script** (`scripts/anonymize_ics_text.php`) — 8 regex-driven passes (card-last-four watermark / spaced IBAN / 12+ digit runs / 11-digit klantnummer / compact IBAN / cardholder banner / email / NL phone), idempotent, no Composer deps.
5. **Fixture record markdown** (`Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md`) — exhaustive 13-section empirical record (anchor tokens / per-page noise / FX shape / source_ref / markup / statement summary tokens / Dutch formats / masked-card schema) plus a **Major Deviations from CONTEXT.md** section documenting D-51 / D-53 / D-37 contradictions.
6. **Tiny synthetic 849-byte PDF** (`Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf`) — hand-crafted minimal PDF 1.4 byte stream embedding the empirical summary-token set + placeholders + one EUR-native row whose merchant string contains the load-bearing literal `SYNTHETIC`. Companion markdown documents the cupsfilter-over-budget deviation and the chosen fallback path.
7. **Reproducible tiny PDF generator** (`scripts/generate_tiny_ics_pdf.php`) — idempotent (no entropy); any future contributor can regenerate the fixture byte-identically.
8. **phase-3 Pest group convention** documented in `tests/Pest.php` (mirrors the phase-2 docblock — no global registration; each it() chains `->group('phase-3')`).
9. **Nine Red scaffold files** (55 it() cases) committed across Ingestion (Unit/Adapters/Ics × 4) + Import (Feature × 1) + Core (Feature × 1) + Ledger (Feature × 3) — every case fails with the canonical `expect(true)->toBe(false, 'scaffold — implemented in plan 03-NN')` pattern; zero `->skip()` calls; Pint clean.
10. **Anonymisation-sweep guard test** (`tests/Feature/AnonymisedFixtureSweepTest.php`) — 5 it() cases (4 default + 1 integration); all Green on the executor's macOS Herd box with poppler 26.04.0 on PATH; Larastan level max strict clean; Pint clean; tagged `->group('integration')` on case 5 so CI hosts without poppler can `--exclude-group=integration`.

## pdftotext Invocation (locked tokens for Wave 2)

**Flag set (committed verbatim into the fixture record + READMEs):**
```sh
pdftotext -layout -enc UTF-8 -eol unix -nopgbrk <input>.pdf <output>.txt
```

**Poppler install on this host:**
- macOS Herd does NOT ship `pdftotext` out of the box (only `cupsfilter` ships with macOS).
- Installed via `brew install poppler` → `/opt/homebrew/bin/pdftotext` version **26.04.0** (Copyright 2005-2026 Poppler Developers, http://poppler.freedesktop.org/).
- Verification command: `pdftotext -v` (now documented in README's Install section right after `composer install`).

## Empirical Anchor Tokens (for Wave 2's IcsPdfExtractionMap)

Sourced from `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md`'s "Anchor tokens" + "Statement summary tokens (D-51)" sections.

**Transactions-table region — header pair (load-bearing):**
```
 Datum      Datum                   Omschrijving                                  Bedrag in                    Bedrag
 transactie boeking                                                               vreemde valuta               in euro's
```

The robust anchor is the literal **`transactie boeking`** on line N+1 (page 1 = line 18, page 2 = line 78). The substring appears exactly twice in the document and uniquely identifies the table-header region. The body opens on the next line and runs until the first blank line.

**FX-row recogniser:** the next line after a merchant row beginning with `Wisselkoers ` plus the same currency code as the native amount suffix (e.g. `Wisselkoers USD   1,14390`).

**Statement-summary anchor tokens (revolving-credit nomenclature — D-51 MAJOR DEVIATION):**

| Field on StatementSummaryData | Empirical Dutch token |
|-------------------------------|----------------------|
| Opening balance               | `Vorig openstaand saldo` |
| Payments received             | `Totaal ontvangen betalingen` |
| New charges                   | `Totaal nieuwe uitgaven` |
| Closing balance               | `Nieuw openstaand saldo` |
| Credit limit                  | `Bestedingslimiet` |
| Minimum payment due           | `Minimaal te betalen bedrag` |

**Periode is absent from the extracted text.** The adapter must either derive `period_start_at` / `period_end_at` from min/max booked dates across the parsed rows, or parse the body-paragraph line `Uw betalingen aan International Card Services BV zijn bijgewerkt tot 15 februari 2026` for the period-end date.

## Decision Dispositions

| Decision | Disposition | Notes |
|----------|-------------|-------|
| D-34 (source_ref availability) | **NULL — no per-transaction stable identifier** | The empirical statement has no authorisation code / slip number / transaction reference; v3 fingerprint tuple is the only dedup anchor (same posture as Phase 2 MT940 entries with missing EREF). |
| D-35 (FX-row visual shape) | **Shape (b) — two-line block** | Native amount on merchant row, `Wisselkoers <CURRENCY> <rate>` on the immediately-following row. Confirmed across all three real FX rows in the fixture. |
| D-40 (markup separability) | **Rolled into settled (D-40's b branch)** | Wisselkoers line shows the effective post-markup rate only; no per-transaction markup itemisation, no per-statement markup table. rawPayload preserves both lines so a future phase can recover the displayed rate without re-import. |
| D-51 (statement-summary anchor tokens) | **MAJOR DEVIATION — see "Major Deviations" below** | Mijn ICS consumer-portal uses revolving-credit nomenclature, not the current-account tokens CONTEXT.md anticipated. The empirical 6-token list replaces the predicted 5-token list. |
| D-52 (Dutch date / amount formats) | **Two date formats + comma-decimal + period-thousands** | Date: `dd MMM.` (transactiedatum/boekdatum, e.g. `23 jan.`) and `dd MMMM YYYY` (header, e.g. `15 februari 2026`). Amount: comma decimal, period thousands (`1.416,50`), `€ ` prefix for EUR, ISO code suffix for non-EUR. No leading-`-` for debits — column marker `Af`/`Bij` is the sign source. |
| D-53 (per-page noise patterns) | **`Pagina X van Y` footer does NOT exist** | The page index lives inline as `1 van 2` / `2 van 2` at the right end of the statement-summary header line. Other repeating noise: `Nu beschikbaar: Apple Pay!` banner, `Dit product valt onder het depositogarantiestelsel` disclaimer, body paragraphs anchored on `Het minimaal te betalen bedrag` / `Uw betalingen aan International Card Services BV`, `Bestedingslimiet ... Minimaal te betalen bedrag` two-column credit-limit block. |
| D-56 (masked-card metadata schema) | **Synthetic placeholder fallback** | The source PDF only renders the card last-four (`Uw Card met als laatste vier cijfers NNNN`), never the full PAN. The adapter writes `{issuer: "Mastercard", cardLast4: "<real-four-digits>", cardholderName: "STRIPPED"}` into `statement_summaries.extras`; the real last-four comes from the raw PDF at parse-time, never from the committed fixture (which carries the literal `XXXX` placeholder). |

## Major Deviations from CONTEXT.md

These were discovered during empirical extraction (Task 3) and surfaced
in `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md`'s
"Major Deviations from CONTEXT.md" section. The orchestrator should
issue a CONTEXT.md addendum revising D-51 / D-53 / D-37 against the
empirical reality, and plan 03-02 should adopt the empirical tokens.

### 1. D-51 Statement-summary anchor tokens — predicted vs empirical mismatch

CONTEXT.md (revised 2026-05-15) D-51 anticipated tokens `Periode`,
`Beginsaldo`, `Eindsaldo`, `Totaal nieuw saldo`, `Totaal betaald`.
**None of those five tokens appear in the empirical statement.** The
Mijn ICS consumer-portal emits revolving-credit summary nomenclature,
not current-account nomenclature. The empirical six tokens are
`Vorig openstaand saldo`, `Totaal ontvangen betalingen`,
`Totaal nieuwe uitgaven`, `Nieuw openstaand saldo`,
`Bestedingslimiet`, `Minimaal te betalen bedrag`.

**Recommended downstream action:** plan 03-02 should adopt the six
empirical tokens as the `IcsPdfExtractionMap::SUMMARY_TOKENS`
constants. There is **no explicit `Periode` field** — derive
`period_start_at` / `period_end_at` from min/max transaction dates,
or parse the body-paragraph line for the period-end date.

### 2. D-53 `Pagina X van Y` footer absent

CONTEXT.md D-53 anticipated a `^Pagina \d+ van \d+$` per-page footer
regex. **That pattern does not appear in this statement.** The page
index instead appears as `1 van 2` / `2 van 2` at the right end of
the statement-summary header line (lines 11 / 71). The parser's
per-page noise pass should anchor on the wider header pattern
rather than the standalone `Pagina ...` line.

### 3. D-37 / Wave 0 verification grep — card number never rendered in PDF body

CONTEXT.md D-37 (and the Wave 0 plan's verification grep) assumed the
PDF body would render the full PAN as a watermark. **In practice the
empirical statement only renders the card last-four** on a single
banner line (`Uw Card met als laatste vier cijfers 1333`). The
redaction script injects a synthetic `****-****-****-XXXX`
placeholder on the same line so the canonical placeholder is present
in the fixture (for grep-based contract tests), but the underlying
source PDF has no full PAN to redact in the first place.

**Downstream impact on plan 03-02:** the adapter's PII guard (the
test asserting card-number text never lands in `transactions.raw_payload`)
remains load-bearing — Wave 2 will still strip any `****-****-****-XXXX`
literal and any 12+ digit run from the per-transaction text block
before persisting rawPayload.

## FX-Row Availability — no synthesis required

The committed fixture contains **three real FX rows** (none synthesised):

| Line | Date     | Merchant       | Native | Settled  | Wisselkoers |
|------|----------|----------------|--------|----------|-------------|
| 31   | 23 jan.  | AUGMENT CODE   | 50,00 USD | 43,71 EUR | 1,14390 |
| 34   | 26 jan.  | Audible UK     | 8,99 GBP  | 10,59 EUR | 1,17798 |
| 36   | 27 jan.  | VITRUS         | 6,00 USD  | 5,17 EUR  | 1,16054 |

The user's resume signal was `approved` (not `approved synthesise-fx`),
so no synthetic FX row was added to `ics-sample-1.txt`. The
`scripts/anonymize_ics_text.php` script's synthesis branch was
therefore not exercised in this plan.

## Tiny synthetic PDF generation path

**Chosen path: hand-crafted PDF 1.4 byte stream (the plan's documented fallback).**

Primary path attempted: `cupsfilter -i text/plain -m application/pdf`
on a 9-line text input. Output was **16–18 KB** even for the shortest
input the synthetic content could be trimmed to — Cairo / CoreGraphics
pipeline embeds a subsetted Type1 font + page-box metadata that alone
exceeds the 10 KB acceptance gate. Trimming the input below six lines
would have lost a load-bearing summary token.

Fallback path adopted (explicitly permitted by Task 5 step 6): a
hand-crafted PDF 1.4 byte stream via `scripts/generate_tiny_ics_pdf.php`:

```sh
php scripts/generate_tiny_ics_pdf.php
```

Output: **849 bytes**, well under the 10 KB budget. The generator is
idempotent (no entropy) — re-running overwrites the committed fixture
byte-identically. The generated file embeds:

```
KAARTHOUDER ****-****-****-XXXX
Vorig openstaand saldo EUR 0,00
Totaal ontvangen betalingen EUR 0,00
Totaal nieuwe uitgaven EUR 1,00
Nieuw openstaand saldo EUR 1,00
12-04-2026 SYNTHETIC ICS TINY EUR 1,00
```

These are the **empirical** summary tokens (revolving-credit
nomenclature), not the CONTEXT.md aspirational set — so the
Wave 2 wire-level contract test using this tiny PDF will stay
internally consistent with the redacted full-fixture tests using
`ics-sample-1.txt`.

`pdftotext -layout` on the tiny PDF round-trips the literal `SYNTHETIC`
and contains zero 12+ digit runs (verified locally, and guarded on
every future build by `tests/Feature/AnonymisedFixtureSweepTest.php`
case 5).

## Failing-scaffold inventory

Total **55 it() invocations** across nine files (planner target ~55;
acceptance gate ≥ 30 — comfortably over). Per-file breakdown:

| File | Cases | Driven Green by |
|------|------:|-----------------|
| Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php           | 10 | 03-02 |
| Modules/Ingestion/tests/Unit/Adapters/Ics/PdfTextExtractorTest.php        |  4 | 03-02 |
| Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php         |  5 | 03-02 |
| Modules/Ingestion/tests/Unit/Adapters/Ics/IcsDateParserTest.php           |  5 | 03-02 |
| Modules/Import/tests/Feature/IcsPdfImportTest.php                         |  9 | 03-02 (7 cases) + 03-03 (2 cases) |
| Modules/Core/tests/Feature/SettingsPageTest.php                           |  6 | 03-04 |
| Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php       |  7 | 03-05 |
| Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php                |  5 | 03-06 |
| Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php              |  4 | 03-07 |
| **Total**                                                                 | **55** | |

No deviations from the planner's per-file case list. Every case
chains `->group('phase-3')`. Zero `->skip()` calls. `vendor/bin/pest --group=phase-3`
exits non-zero (Red — 55 failures, 55 assertions) on this commit, as
designed. Pint clean on all nine files plus `tests/Pest.php`.

## Anonymisation-sweep test status

`tests/Feature/AnonymisedFixtureSweepTest.php` — five cases, all Green
on the executor's macOS Herd machine:

1. `it('the redacted ICS text fixture contains zero 12-digit-or-longer runs')`
2. `it('the redacted ICS text fixture contains zero IBAN-shaped tokens other than the deterministic placeholder')`
3. `it('the redacted ICS text fixture contains the KAARTHOUDER placeholder')`
4. `it('the redacted ICS text fixture contains a card-number placeholder')`
5. `it('the tiny synthetic ICS PDF, after pdftotext extraction, contains zero PII-shaped strings')` — tagged `->group('integration')` (shells out to pdftotext via Symfony Process). **Green on this host (poppler 26.04.0 on PATH).** CI hosts without poppler can `--exclude-group=integration`.

Larastan posture: the project-root `tests/*` directory is excluded from
the default `phpstan.neon` analyse paths (the project's static-analysis
convention is to scan source under `Modules/`, `app/`, `bootstrap/`).
The sweep test was nonetheless analysed via a one-shot configuration
at level=max with all strict-rules extensions enabled — **zero
errors**. The intentional `file_get_contents` `string|false` narrowing
is handled via an explicit `if ($contents === false)` guard rather
than a `@phpstan-ignore` comment or assert(), keeping the project's
"no suppressions, no widened types" posture intact.

## Task Commits

Each task was committed atomically:

1. **Task 1: Install spatie/pdf-to-text + document brew install poppler + carve out local/** — `bf4a5c2` (chore)
2. **Task 2: Receive raw ICS PDF from user (human-action checkpoint)** — no commit (handoff only)
3. **Task 3: Extract-then-redact-text protocol — fixture + anonymisation script + record markdown** — `ccdc524` (test)
4. **Task 4: Human verification — committed fixture preserves data shape and contains zero PII** — no commit; user replied `approved`
5. **Task 5: Tiny synthetic ICS PDF + generator script + companion markdown** — `518a334` (test)
6. **Task 6: Phase-3 Pest convention + nine failing scaffolds (55 it() cases)** — `415a175` (test)
7. **Task 7: Anonymisation-sweep guard test** — `a3d966d` (test)

Plan-metadata commit (this SUMMARY.md + STATE.md + ROADMAP.md): see final commit.

## Files Created/Modified

**Created (15):**

- `scripts/anonymize_ics_text.php` — 8-pass regex redactor; re-runnable on any future extraction
- `scripts/generate_tiny_ics_pdf.php` — idempotent generator for the 849 B tiny synthetic PDF
- `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt` — empirical anonymised ICS PDF text fixture (102 lines)
- `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md` — exhaustive empirical fixture record + Major Deviations
- `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` — tiny synthetic PDF (849 B; embeds empirical tokens + `SYNTHETIC` anchor)
- `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.md` — generator documentation + cupsfilter-deviation note
- `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php` — 10 Red scaffolds (driven Green by 03-02)
- `Modules/Ingestion/tests/Unit/Adapters/Ics/PdfTextExtractorTest.php` — 4 Red scaffolds (03-02)
- `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php` — 5 Red scaffolds (03-02)
- `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsDateParserTest.php` — 5 Red scaffolds (03-02)
- `Modules/Import/tests/Feature/IcsPdfImportTest.php` — 9 Red scaffolds (03-02 + 03-03)
- `Modules/Core/tests/Feature/SettingsPageTest.php` — 6 Red scaffolds (03-04)
- `Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php` — 7 Red scaffolds (03-05)
- `Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php` — 5 Red scaffolds (03-06)
- `Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php` — 4 Red scaffolds (03-07)
- `tests/Feature/AnonymisedFixtureSweepTest.php` — Green-by-design repo-wide PII guard (5 cases)

**Modified (5):**

- `composer.json` / `composer.lock` — added `spatie/pdf-to-text: ^1.0` (resolved to 1.55.0)
- `.gitignore` — added `/local/` carve-out for raw PDF intake
- `README.md` — added `brew install poppler` prerequisite + `pdftotext -v` sanity check + poppler.freedesktop.org link
- `tests/Pest.php` — added phase-3 group convention docblock

## Decisions Made

See **key-decisions** in the frontmatter for the full list. Highlights:

- **Anonymisation is in-repo, not on the user's machine** (D-32a) — the user supplies the raw PDF only; the redaction script lives at `scripts/anonymize_ics_text.php` and is auditable line-by-line. Re-runs are safe (idempotent — every replacement is regex-driven on input shape, not state).
- **Tiny synthetic PDF generated via hand-crafted byte stream**, not `cupsfilter` — the plan's primary path produced ~17 KB output even for the trimmest input. The hand-crafted 849-byte fallback (explicitly permitted by Task 5 step 6) is reproducible via the committed `scripts/generate_tiny_ics_pdf.php`.
- **Scaffold failure-message comments reference plan numbers (e.g. `'scaffold — implemented in plan 03-02'`), not decision IDs** — CLAUDE.md's GSD-agnostic posture forbids `.planning/` and `D-XX` references in committed PHP source. Plan IDs are acceptable identifiers; decision IDs are not.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] cupsfilter exceeded the 10 KB tiny-PDF budget**

- **Found during:** Task 5 (tiny synthetic PDF generation)
- **Issue:** `cupsfilter -i text/plain -m application/pdf` on the smallest viable text input produced 16–18 KB output (Cairo / CoreGraphics pipeline embeds a subsetted Type1 font + a `/MediaBox /CropBox /BleedBox /TrimBox /ArtBox` header). The acceptance gate is ≤ 10 KB.
- **Fix:** Adopted the plan's documented fallback path (Task 5 step 6) — hand-crafted a minimal PDF 1.4 byte stream (one Catalog / one Pages / one Page / one Type1 Helvetica font / one Tj-based content stream) via `scripts/generate_tiny_ics_pdf.php`. Output: 849 bytes (~5% of the budget).
- **Files modified:** `scripts/generate_tiny_ics_pdf.php` (new), `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` (regenerated), `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.md` (deviation note added)
- **Verification:** `head -c 5 tiny.pdf` = `%PDF-`; `wc -c tiny.pdf` = 849; `pdftotext -layout` round-trips the literal `SYNTHETIC` and yields zero 12+ digit runs.
- **Committed in:** `518a334` (Task 5 commit)

**2. [Rule 1 - Bug] Larastan strict-rules flagged `file_get_contents` return-type narrowing in AnonymisedFixtureSweepTest**

- **Found during:** Task 7 (sweep test verification — `vendor/bin/phpstan analyse` via one-shot config at level=max with strict rules)
- **Issue:** Initial implementation used `$contents = file_get_contents(...); expect($contents)->toBeString();` and then `preg_match_all($pattern, $contents, ...)`. Pest's runtime `expect(...)->toBeString()` does not narrow PHPStan's static type — the analyser still saw `string|false` flowing into `preg_match_all`'s `string` parameter (4 errors across 4 case bodies).
- **Fix:** Replaced the `expect(...)->toBeString()` narrowing with an explicit `if ($contents === false) { throw new RuntimeException("Could not read ICS text fixture at {$fixtureTxt}"); }` — narrows statically and surfaces a clean error if the fixture path ever rots. No `@phpstan-ignore`, no `assert()`, no widened types (CLAUDE.md "fix all severities" posture preserved).
- **Files modified:** `tests/Feature/AnonymisedFixtureSweepTest.php`
- **Verification:** PHPStan level max strict via one-shot config → 0 errors; Pest 5 cases all Green; Pint clean.
- **Committed in:** `a3d966d` (Task 7 commit)

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 bug fix). Plus three CONTEXT.md major-deviations surfaced during Task 3 (D-51 anchor-token mismatch, D-53 missing page-footer, D-37 no full PAN rendered) — these are empirical-reality discoveries, not auto-fixes; the orchestrator should issue a CONTEXT.md addendum or revise plan 03-02 against them.

**Impact on plan:** All auto-fixes were necessary for correctness — neither expanded scope. The CONTEXT.md major deviations are EMPIRICAL DISCOVERIES, not scope creep: they fix the planning assumptions to match reality and tighten plan 03-02's behavioural target.

## Issues Encountered

- `pdftotext` is not on macOS by default — required `brew install poppler` before Task 3 could run. The orchestrator handled the install; README now documents this prerequisite + `pdftotext -v` sanity check so future contributors don't trip over it.
- Larastan's project config excludes `tests/*` from the default analyse paths — the plan's verify line for Task 7 (`vendor/bin/phpstan analyse tests/Feature/AnonymisedFixtureSweepTest.php`) returned "No files found to analyse" against the project's `phpstan.neon`. Worked around by writing a one-shot config that explicitly paths the file; full level=max + strict-rules analysis passed cleanly (zero errors).
- Scaffold-vs-phpstan known limitation: the nine Red scaffold files reference symbols that don't exist yet (e.g. `IcsPdfAdapter`, `PdfTextExtractor`, `IcsAmountParser`). Larastan would flag those if asked; per `task_6_notes` the plan does NOT require Larastan-clean on the scaffolds (only on the Task 7 sweep test). The scaffolds are deliberately authored to fail at runtime (`expect(true)->toBe(false, ...)`) BEFORE any symbol resolution happens — Pest discovery doesn't load the referenced classes, so the Red exit code is clean.

## User Setup Required

None — Poppler installation is documented in the README's Prerequisites
section but the orchestrator already installed it for this plan.

## Next Phase Readiness

**Plan 03-02 (Wave 2 wire-level slice) is ready to start** with a complete behavioural target:

- The redacted `ics-sample-1.txt` fixture provides empirical anchor tokens, FX-row shape, statement-summary nomenclature, and Dutch date/amount formats.
- The fixture-record markdown documents per-page noise regexes, source_ref disposition (NULL), markup disposition (rolled into settled), and the masked-card metadata schema.
- 38 Red scaffolds in the Ingestion + Import modules (10 + 4 + 5 + 5 + 9 + 2 doc-only IcsPdfImportTest cases driven by 03-03) await Green implementation.
- The tiny synthetic PDF unblocks the `IdempotencyContractTest` dataset extension at 03-02 (the SHA-256 dedup + row-level fingerprint round-trip can exercise the real pdftotext binary path without depending on the user's local raw export).
- **One known fixture quirk for plan 03-02:** the page-2 transactions-table region in `ics-sample-1.txt` has very wide leading whitespace (the redacted output retains pdftotext's `-layout` column padding from the issuer banner on page 1). Parsing must trim leading whitespace per line, NOT key on column offsets. The fixture-record markdown documents this.

**CONTEXT.md addendum recommended** before plan 03-02 begins implementation:

- Revise D-51 anchor-token list to the empirical six (`Vorig openstaand saldo`, `Totaal ontvangen betalingen`, `Totaal nieuwe uitgaven`, `Nieuw openstaand saldo`, `Bestedingslimiet`, `Minimaal te betalen bedrag`) and remove `Periode` / `Beginsaldo` / `Eindsaldo` / `Totaal nieuw saldo` / `Totaal betaald` from the constant set.
- Revise D-53 to remove the `^Pagina \d+ van \d+$` regex and replace with the inline `\d+ van \d+` form on the statement-summary header line.
- Note under D-37 that the source PDF never renders the full PAN — only the card last-four. The PII guard in plan 03-02 still strips `****-****-****-XXXX` literals AND 12+ digit runs (defense in depth — `scripts/anonymize_ics_text.php` could theoretically encounter a future statement format that does render the full PAN).

## Self-Check: PASSED

Verified all committed artefacts exist on disk and all commit hashes
resolve in the git tree:

- bf4a5c2 (Task 1): FOUND
- ccdc524 (Task 3): FOUND
- 518a334 (Task 5): FOUND
- 415a175 (Task 6): FOUND
- a3d966d (Task 7): FOUND
- `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt`: FOUND
- `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md`: FOUND
- `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf`: FOUND (849 B)
- `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.md`: FOUND
- `scripts/anonymize_ics_text.php`: FOUND (executable)
- `scripts/generate_tiny_ics_pdf.php`: FOUND (executable)
- Nine scaffold files: ALL FOUND (55 it() cases total)
- `tests/Feature/AnonymisedFixtureSweepTest.php`: FOUND (5 cases, Green)
- `tests/Pest.php`: phase-3 docblock present
- `.gitignore`: `/local/` present
- `README.md`: `brew install poppler` + `pdftotext -v` + `poppler.freedesktop.org` all present
- `composer.json`: `spatie/pdf-to-text` declared; `composer show spatie/pdf-to-text` returns 1.55.0

---
*Phase: 03-ics-cards-multi-currency-display*
*Plan: 01*
*Completed: 2026-05-15*
