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
