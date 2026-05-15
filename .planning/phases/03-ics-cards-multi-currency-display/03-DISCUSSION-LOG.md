# Phase 3: ICS Cards + Multi-Currency Display - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-13
**Phase:** 3-ICS Cards + Multi-Currency Display
**Areas discussed:** ICS export format coverage, ICS account modeling, FX rate + ICS markup handling, Dual-currency display UX

---

## ICS Export Format Coverage

### Q1 — Which ICS export format(s)?

| Option | Description | Selected |
|--------|-------------|----------|
| CSV only | Lighter dependency footprint; mirrors Phase 1 ASN CSV adapter. Skip phpoffice/phpspreadsheet entirely. | ✓ |
| Excel (.xlsx) only | Use phpoffice/phpspreadsheet. Only if portal exports only Excel. | |
| Both CSV and Excel | Two adapters + ENRICHED cross-format dedup (Phase 2 pattern). | |
| I'll check what my portal offers | Defer the decision. | |

**User's choice:** CSV only (Recommended).
**Notes:** Locks the adapter pattern to mirror `AsnCsvAdapter` and skips the phpoffice dependency.

### Q2 — Real ICS fixture availability?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — provide before planning | Anonymised by the user. Best path; matches Phase 1's empirical resolution. | |
| Yes, during execution | Researcher works from public samples first; fixture arrives mid-execution. | |
| No — reverse-engineer from public samples | Highest risk. | |

**User's choice (free text):** "1, but I'll provide a real one and you should anonomize it"
**Notes:** Option 1 — user provides raw CSV, anonymisation is OUR job (Wave 0). Mirrors Phase 2's CAMT IBAN check-digit re-anonymisation precedent.

### Q3 — Wizard slot strategy?

| Option | Description | Selected |
|--------|-------------|----------|
| Fourth dropdown option | Extend existing flat ['asn-csv', 'asn-camt053', 'asn-mt940'] list. | |
| Grouped by issuer/format | Two-step picker: issuer first, format second. | ✓ |
| You decide | Defer to UI sketch. | |

**User's choice:** Grouped issuer→format picker.
**Notes:** Refactor of Phase 1's flat dropdown. Scales to PayPal + Google Play groups in later phases.

### Q4 — `source_ref` from ICS?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — txn ID/auth code column | Strong dedup anchor; Phase 5 chain joins. | |
| No — date + merchant + amount only | source_ref = NULL; fingerprint v3 is the only anchor. | |
| Unsure — verify against real fixture | Defer to Wave 0. | ✓ |

**User's choice:** Unsure — defer to Wave 0 fixture.

### Q5 — FX row shape in CSV?

| Option | Description | Selected |
|--------|-------------|----------|
| One row with both original + settled columns | Adapter yields one DTO. | |
| Two rows: charge + FX conversion line | Adapter rolls up into one canonical row. | |
| Unsure — verify against real fixture | Defer to Wave 0. | ✓ |

**User's choice:** Unsure — defer to Wave 0 fixture.

---

## ICS Account Modeling

### Q1 — Account representation?

| Option | Description | Selected |
|--------|-------------|----------|
| One Account per ICS contract / credit line | Card_number on the transaction. Clean for Phase 5 bulk-iDEAL decomposition. | |
| One Account per physical card | Closer to user mental model of separate cards. | |
| One pooled 'ICS Cards' Account | Lose card-level visibility. | |
| Not sure yet — only have one card right now | Defer the multi-card design. | ✓ |

**User's choice:** Defer multi-card design — single Account in Phase 3 ; schema leaves room for later without migration pain.

### Q2 — Where does card number land?

| Option | Description | Selected |
|--------|-------------|----------|
| Nullable `card_last4` column on transactions | Privacy-safe; future-proof; queryable. | |
| Store full card number in `rawPayload` only | More flexible, less queryable. | |
| Skip entirely for now — you decide later | Lose card_number unless statements are re-importable. | ✓ |

**User's choice:** Skip entirely for now.
**Notes:** Captured as a deferred-ideas landmine: multi-card support requires fingerprint tuple expansion (v3 → v4) and card identity in the schema BEFORE card 2 is imported, else two cards' same-merchant/date/amount rows would falsely de-duplicate.

### Q3 — Account creation flow in wizard?

| Option | Description | Selected |
|--------|-------------|----------|
| Wizard prompts to name on first upload | Mirrors Phase 1's IBAN-naming flow. | |
| User pre-creates in Settings before first upload | More friction. | |
| You decide | Defer to Claude. | ✓ |

**User's choice:** You decide (captured under Claude's Discretion as D-38 — wizard prompt approach selected).

---

## FX Rate + ICS Markup Handling

### Q1 — What populates `fx_rate_used`?

| Option | Description | Selected |
|--------|-------------|----------|
| Derive from settled / original when both present | Effective post-markup rate. Honest. Phase 9 drift can spot rising effective rates. | ✓ |
| Only populate when statement provides explicitly | NULL on rows without explicit rate column. | |
| Store both market rate AND markup separately | Adds new schema columns; scope creep. | |
| You decide | Defer to Claude. | |

**User's choice:** Derive from settled / original (Recommended).

### Q2 — Explicit FX markup / fee rows?

| Option | Description | Selected |
|--------|-------------|----------|
| Roll into the same canonical row — markup invisible | Settled already includes markup. Recoverable from rawPayload. | ✓ |
| Yield a separate `type='fee'` canonical row | Two rows per FX charge; more accurate categorization. Costs wizard preview scope. | |
| Defer until Wave 0 fixture | If no explicit column, the question is moot. | |

**User's choice:** Roll into same canonical row (Recommended for Phase 3).

### Q3 — EUR-native rows' settled columns?

| Option | Description | Selected |
|--------|-------------|----------|
| settled = native (mirrored), fx_rate_used = NULL | Stays consistent with Phase 1's NOT NULL contract. | ✓ |
| settled = NULL, fx_rate_used = NULL | Breaks NOT NULL contract; would require schema change. | |

**User's choice:** Mirrored (Recommended).

### Q4 — SourceTransactionDto change strategy?

| Option | Description | Selected |
|--------|-------------|----------|
| Add nullable settledAmountMinor/settledCurrency/fxRateUsed fields | First-class fields. Cleanest typing for Larastan level 10. | ✓ |
| Pass settled+rate through `rawPayload` only | No DTO shape change; looser typing. | |
| You decide | Defer to Claude. | |

**User's choice:** Add nullable fields to the DTO (Recommended).

---

## Dual-Currency Display UX

### Q1 — Where does the EUR/dual toggle live?

| Option | Description | Selected |
|--------|-------------|----------|
| Per-page toggle on /transactions | Flux switch; URL-query persisted. Default EUR-only. | |
| Global user preference (Settings) | Cleaner if user always wants one mode. Costs a Settings UI. | |
| Both — Settings default + per-page override | Most flexible. | ✓ |
| You decide | Defer to Claude. | |

**User's choice:** Both — Settings default + per-page override.
**Notes:** Forces Phase 3 to ship a minimal Settings UI (which Phase 1 had deferred via D-19).

### Q2 — Dashboard tiles respect the toggle?

| Option | Description | Selected |
|--------|-------------|----------|
| No — tiles always render EUR-settled | Mathematically clean. Toggle stays a /transactions concern. | |
| Yes — tiles toggle EUR-only vs original-currency view | More information; possibly more clutter. | ✓ |
| You decide | Defer to Claude. | |

**User's choice:** Yes — toggle also affects the dashboard tiles.

### Q3 — Dashboard tile shape in original-currency mode?

| Option | Description | Selected |
|--------|-------------|----------|
| One tile-row per currency (e.g., EUR row + USD row) | Mathematically honest; EUR-only months collapse cleanly. | ✓ |
| Primary EUR tile + secondary line `incl. $X USD` | Always lead with EUR. Stays single-row per tile. | |
| You decide | Defer to Claude. | |

**User's choice:** One tile-row per currency (Recommended).

### Q4 — Where does the global default toggle live in Settings?

| Option | Description | Selected |
|--------|-------------|----------|
| Ship a minimal Settings page in Phase 3 | Discharges Phase 1's D-19 deferred Settings UI. | ✓ |
| Add a CLI config flag only | No UI; mismatches calm-UI ethos. | |
| You decide | Defer to Claude. | |

**User's choice:** Ship minimal /settings page (Recommended).

### Q5 — Transaction row render when native ≠ settled?

| Option | Description | Selected |
|--------|-------------|----------|
| Inline arrow: '$12.99 USD → €12.07' | One line per row. Reads at a glance. Matches UI-06 example. | |
| Two-line stack: '$12.99 USD' over '€12.07 EUR' muted | More vertical space; clearer hierarchy. | ✓ |
| Tooltip on hover | Cleanest at-rest; hidden info. | |
| You decide | Defer to Claude. | |

**User's choice:** Two-line stack.

### Q6 — Per-transaction FX rate surface (Success Criterion 2)?

| Option | Description | Selected |
|--------|-------------|----------|
| Transaction detail page only | Doesn't clutter the list. | |
| Inline on the list row after the amount | Visible on every foreign row. | |
| Tooltip on the amount | Hidden until hover. | |
| You decide | Defer to Claude. | ✓ |

**User's choice:** You decide (captured under Claude's Discretion as D-48 — detail-page-only selected).

---

## Claude's Discretion

- **D-38:** Wizard's "name your ICS Account" step on first ICS upload (trigger: no Account of type `ics_card` exists yet).
- **D-48:** FX rate surfaces on the transaction detail page only — not inline, not tooltip.
- Currency formatting (Dutch-locale `brick/money` for EUR; ISO-symbol prefix for non-EUR).
- Settings page styling (Flux primitives, calm Linear/Notion aesthetic).
- Wizard wording details for the issuer→format two-step.
- Exact FX-rate string rendering on the detail page (rate orientation, precision).

## Deferred Ideas

- ICS Excel (.xlsx) ingestion — revisit only if portal drops CSV.
- Per-card visibility within one ICS contract (requires fingerprint v4 + schema work BEFORE card 2 is imported).
- Splitting ICS FX markup as a separate fee row (Phase 9 may revisit).
- Market-rate-vs-effective-rate split (`fx_market_rate` + `fx_markup_basis_points` columns) — rejected for v1.
- Chain-resolution use of ICS source_ref — Phase 5 researcher to check Wave 0 findings.
- OFX / QIF export of multi-currency rows — out of v1 scope.
- Per-currency budgets / spending limits — out of scope.
- Settings UI for `period_start_day` — folded INTO Phase 3 (D-45) as co-discharge of Phase 1 D-19.

---

## Re-discussion 2026-05-15 — ICS source format pivot (CSV → PDF)

**Trigger:** User discovered Mijn ICS consumer portal exports PDF statements only — no CSV or Excel option exists. The previously locked D-31 ("CSV only, no phpoffice/phpspreadsheet") had to be reversed. Scope (per user command): reverse D-31, decide PDF parsing library, re-frame D-34/D-35/D-40 as PDF-extraction questions. Keep plans 03-03 through 03-07 intact — only plans 03-01 and 03-02 need rebuilding.

**Areas discussed:** PDF parsing library, fixture anonymisation protocol, rawPayload retention, multi-PDF backfill UX, statement_summaries card-metadata archiving. Plus implementation-flag confirmations on multi-statement PDFs, statement_summaries population, Dutch-locale parsing, page header/footer noise stripping, wizard validator/HeaderSniffer extension, and extraction-step idempotency. Password-protected PDFs excluded by user.

### Q1 — PDF parsing library (locks D-31a)

| Option | Description | Selected |
|--------|-------------|----------|
| `spatie/pdf-to-text` (poppler `pdftotext` binary) | Spatially-preserved extraction via `-layout`; requires `brew install poppler`. Most accurate column recovery. | ✓ |
| `smalot/pdfparser` (pure PHP) | Zero system deps; tabular extraction from per-glyph coordinates is more fragile. | |
| Both — smalot default, pdftotext fallback | Belt-and-braces; two code paths to maintain. | |
| Decide after Wave 0 — try smalot first, switch only if columns don't recover | Defers decision; adds extraction-spike step to Wave 0. | |

**User's choice:** spatie/pdf-to-text (Recommended).
**Notes:** Locks D-31a. `PdfTextExtractor` service isolates the `exec()` boundary. macOS prerequisite: `brew install poppler` (documented in README). Phase 3 introduces this codebase's first text-extraction-driven ingestion path.

### Q2 — PDF fixture anonymisation protocol (locks updated D-32)

| Option | Description | Selected |
|--------|-------------|----------|
| Extract-then-redact-text | Wave 0 runs `pdftotext -layout`, anonymises the *.txt, commits the redacted *.txt. Raw PDF gitignored. | ✓ |
| Redact-PDF-in-place + commit redacted PDF | Use qpdf/pdftk/Preview to overlay redactions; commit redacted PDF; adapter runs end-to-end against real PDF. | |
| Both — committed redacted PDF AND extracted-text snapshot | Belt-and-braces; doubles fixture maintenance. | |

**User's choice:** Extract-then-redact-text (Recommended).
**Notes:** Sidesteps PDF binary redaction entirely (CID fonts, watermarks, multi-page headers). Fixture lives at `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt`. Raw PDFs stay under gitignored `local/ics/`. Parser is unit-testable against committed text; the `exec()` integration smoke test is `@group integration` (skippable on CI hosts without poppler).

### Q3 — `rawPayload` content for ICS PDF rows (locks D-49)

| Option | Description | Selected |
|--------|-------------|----------|
| Per-transaction extracted-text block | Contiguous text lines for one logical transaction (~200 bytes/row). Honors D-40's markup-recoverability promise. | ✓ |
| Full extracted-statement text per row | Trivially simple; ~20 KB/row waste; breaks per-row semantics. | |
| Just parsed structured fields | Smallest storage; loses D-40 recoverability. | |

**User's choice:** Per-transaction extracted-text block (Recommended).
**Notes:** New D-49 added. Storage shape: `{ "format": "ics-pdf", "extractedText": "<contiguous text block>" }` — discriminator field matches Phase 1/2 adapter payload conventions.

### Q4 — PDF backfill UX (locks D-54 single-file scope)

| Option | Description | Selected |
|--------|-------------|----------|
| Single-PDF per upload | Matches existing wizard contract; backfill = N sessions. Smallest blast radius. | ✓ |
| Multi-select / zip-upload in Phase 3 | Adds drag-multiple-files; expands plan 03-03 surface. | |
| Defer to Wave 0 — decide based on backlog size | Defers until backlog actually exists. | |

**User's choice:** Single-PDF per upload (Recommended).
**Notes:** Captured "multi-PDF backfill" in Deferred Ideas for a later phase if backfill friction becomes a real chore. Fingerprint v3 dedup makes order and repetition harmless across N independent single-file imports.

### Q5 — `statement_summaries.extras` card-metadata archiving (locks D-56)

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — capture in `extras` JSON only (issuer, cardLast4, cardholderName=STRIPPED) | Archive-only; phase-3 doesn't read; multi-card phase inherits history. | ✓ |
| No — D-37's no-card rule applies strictly to statement_summaries too | Strict D-37 reading; multi-card phase needs re-import. | |

**User's choice:** Yes — capture in `extras` JSON only (Recommended).
**Notes:** Locks D-56. Phase 3 never reads the field — identical posture to Phase 2's `extras.multiStatement` flag.

### Bulk confirmations (implementation flags, recommendation accepted as-is)

| Topic | Recommendation | Outcome |
|-------|----------------|---------|
| Multi-statement PDFs | One PDF = one statement (Mijn ICS exports one month per download) | Accepted → **D-50** |
| `statement_summaries` population from PDF header | Yes, populate (Periode, Beginsaldo, Eindsaldo, Totaal). Stateful-adapter pattern from plan 02-03. | Accepted → **D-51** |
| Dutch-locale parsing | New `IcsAmountParser` + `IcsDateParser` next to Phase 2's ASN helpers; explicit nl_NL formats, no `setLocale()` | Accepted → **D-52** |
| Page header/footer noise stripping | First-pass regex strip before transaction iteration; patterns captured by Wave 0 in `IcsPdfExtractionMap` | Accepted → **D-53** |
| Wizard validator + HeaderSniffer extension | Add `pdf` to mimes list, `%PDF-` magic-byte sniff, `ics-pdf` leaf key | Accepted → **D-54** |
| Idempotency layering | No PDF-hashing layer; existing `import_runs.sha256` (file-level) + fingerprint v3 (row-level) sufficient | Accepted → **D-55** |

### Reframed decisions (D-34 / D-35 / D-40)

- **D-34 (REFRAMED):** Question shifts from "CSV column for per-transaction reference" to "extracted text token for per-transaction identifier (auth code, slip number, transaction reference)". Deferred to Wave 0 PDF-extraction inspection.
- **D-35 (REFRAMED):** Question shifts from "one CSV row vs two" to "single text line / two-line block / footnote-style FX rendering". Deferred to Wave 0; parser handles whichever shape Wave 0 reports.
- **D-40 (REFRAMED):** Question shifts from "separate markup row in CSV" to "markup as separate text line / footnote / implicit in displayed rate". Spirit unchanged (markup invisible at canonical layer; recoverable from rawPayload).

### Excluded from this re-discussion (by user)

- **Password-protected PDFs.** Mijn ICS doesn't ship password-protected statements today; captured as a Deferred Idea for if that ever changes.

### Plans impact

- **03-01 (Wave 0 enablement)** — REBUILD. Anonymisation protocol changes (extract-then-redact-text); fixture deliverable changes (*.txt instead of *.csv); fixture record reports on extraction map (anchor tokens, per-page noise, FX-line shape, Dutch tokens, statement-summary tokens) instead of column indices.
- **03-02 (Adapter wiring)** — REBUILD. `IcsPdfAdapter` + `PdfTextExtractor` + `IcsPdfExtractionMap` + `IcsAmountParser` + `IcsDateParser` replace `IcsCsvAdapter` + `IcsCsvHeaderProfile` + `IcsCsvColumnMap`. Composer adds `spatie/pdf-to-text`. NormalizeStage + SourceTransactionDto + HeaderSniffer changes remain in scope (PDF-flavored).
- **03-03 (Wizard refactor)** — INTACT, one-token rename: leaf key `ics-csv` → `ics-pdf`.
- **03-04 / 03-05 / 03-06 / 03-07** — INTACT (Settings page, transactions toggle, dashboard tiles, detail FX-row are all format-agnostic; operate on canonical data).
