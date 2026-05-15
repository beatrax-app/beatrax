# Phase 4: PayPal Ingestion + Transfer Detection — Discussion Log

**Date:** 2026-05-15
**Phase:** 4 — PayPal Ingestion + Transfer Detection

This is a human-reference record of the discussion that produced `04-CONTEXT.md`. It is NOT consumed by downstream agents (researcher, planner, executor). Use it for audits, retrospectives, and "why did we lock that decision?" lookups.

---

## Area 1: PayPal CSV format + event taxonomy

### Q1.1 — Which PayPal CSV export does the user use, and is a real export available for Wave 0?

**Options presented:**
- Activity Download (default, CSV) — have sample
- Activity Download — need to export
- Reports / Statement of Account
- Unsure / want to inspect both

**User selection:** Activity Download (default, CSV) — have sample

**Resulting decision:** D-57 (Activity Download is canonical) + D-58 (Wave 0 anonymises the user-provided real export into a committable fixture under `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv`; raw stays under `local/paypal/` gitignored).

---

### Q1.2 — Which UI language was the user's PayPal account set to when they exported?

**Options presented:**
- Dutch (nl_NL)
- English (en)
- Unsure / both at different times

**User selection:** Unsure / both at different times

**Resulting decision:** D-59 (Wave 0 fingerprints language from the header row and locks a `PaypalCsvLanguageProfile`; unrecognised languages raise `UnsupportedPaypalCsvLanguageException` rather than silently mis-parsing; Phase 4 implements whichever language Wave 0 finds in the committed fixture).

---

### Q1.3 — How should "Transfer to bank" / "General Withdrawal" rows be handled?

**Options presented:**
- Recommended: ingest as `transfer_out` (link in Phase 4)
- Ingest as `transfer_out`, but pair only in Phase 5
- Ingest as `expense`, no special handling

**User selection:** Recommended (ingest as `transfer_out`, pair in Phase 4)

**Resulting decision:** Locks the `Modules/Transfers/Internal/Listeners/PairTransferCandidates` Phase 4 scope (D-72, D-73, D-75) — the PayPal→ASN sweep axis is paired in this phase, not deferred to Phase 5.

---

### Q1.4 — How should Hold / Authorization / Reserve / Reversal rows be handled?

**Options presented:**
- Recommended: skip entirely
- Skip canonical, but archive in import_run
- Capture as zero-amount audit rows

**User selection:** Recommended (skip entirely)

**Resulting decision:** D-62 (filter at the adapter boundary; persist count to `import_runs.extras.skippedHoldCount` so the wizard summary can surface "X hold rows skipped" without polluting the ledger).

---

## Area 2: Reporting API scope (ING-09)

### Q2.1 — What's the user's PayPal account type?

**Options presented:**
- Personal
- Business
- Unsure

**User selection:** Personal

**Resulting decision:** Constrains ING-09 to a deferred posture — PayPal Transaction Search is business-gated.

---

### Q2.2 — Given a personal account, how should ING-09 be handled?

**Options presented:**
- Recommended: defer to backlog, CSV-only this phase
- Light spike: confirm feasibility, then defer
- Keep ING-09 in Phase 4 anyway

**User selection:** Recommended (defer to backlog, CSV-only this phase)

**Resulting decision:** D-79 (ING-09 moves to REQUIREMENTS.md "Deferred / future-revisit" with trigger "when user upgrades to Business"; ROADMAP.md Phase 4 goal + SC #2 edited during plan-phase to remove the API path).

---

## Area 3: Transfer-pair detection + income detector (LED-04 + LED-05)

### Q3.1 — How should the transfer-pair detector identify ASN ↔ ICS / PayPal ↔ ASN moves?

**Options presented:**
- Recommended: deterministic IBAN match, tolerant fallback to review-queue
- Tolerant-only (amount + date window)
- Manual-only (no auto-pair)

**User selection:** Recommended (deterministic IBAN/account-key match + tolerant fallback)

**Resulting decision:** D-73 (Layer-1 deterministic IBAN match auto-links pairs; Layer-2 tolerant-window match is review-only and defers to Phase 5's review-queue surface — Phase 4 does NOT ship the review queue).

---

### Q3.2 — How should half-pair state behave (one side imported, partner not yet)?

**Options presented:**
- Recommended: typed `transfer_out` immediately, `pair_transaction_id=NULL` until partner lands
- Type stays `expense` until partner exists
- Block import if partner not present

**User selection:** Recommended

**Resulting decision:** D-74 (half-pair is observable: row classified `transfer_out` / `transfer_in` immediately so it never inflates expense/income totals; `pair_transaction_id` written atomically by the post-load listener when the partner lands). Implies D-76 (typing decouples from pairing — pre-pairing classification by source-format event-type map + cross-account-IBAN predicate).

---

### Q3.3 — What's the v1 income detector heuristic (LED-05)?

**Options presented:**
- Recommended: positive inflow NOT linked to `pair_transaction_id` = income; manual override on detail page
- Heuristic counterparty bucketing (salary vs misc)
- Detect later — keep raw `income` for everything inflow

**User selection:** Recommended

**Resulting decision:** D-77 (simple subtractive rule: positive AND NOT transfer/refund/fee → income) + D-78 (manual override action on transaction detail page; reclassifying one side of a pair breaks both sides atomically).

---

### Q3.4 — Where does pair-detection run — pipeline stage or event listener?

**Options presented:**
- Recommended: post-load event handler (`TransactionImported` listener)
- Inline stage between Fingerprint and Load
- Separate async job (queue:database)

**User selection:** Recommended

**Resulting decision:** D-75 (`PairTransferCandidates` listener in new `Modules/Transfers/Internal/Listeners/`; subscribes to `TransactionImported`; runs deterministic Layer-1 match inside the listener's outer DB transaction; queue infra stays Phase 6 territory).

---

## Area 4: PayPal Account modeling

### Q4.1 — How should Phase 4 model the inherently-multi-currency PayPal account?

**Options presented:**
- Recommended: ONE Account row, `default_currency='EUR'`, multi-currency rows hang under it
- One Account row per balance currency
- Defer the multi-currency decision

**User selection:** Recommended

**Resulting decision:** D-66 (single `kind='paypal'` Account, synthetic IBAN `'PAYPAL'` mirroring Phase 3's `'ICS-CARD'` precedent; dual-amount columns already in schema preserve every non-EUR row; per-currency dashboard tile rows from Phase 3 D-46 render PayPal cleanly) + D-67 (wizard naming step on first PayPal upload, generalising Phase 3 D-38) + D-68 (no PayPal balance-by-currency surface in Phase 4).

---

### Q4.2 — How should the wizard's PayPal arm work?

**Options presented:**
- Recommended: third issuer group `PayPal` → single format `CSV (Activity Download)`
- PayPal arm exposes both `paypal-csv` and `paypal-api` (greyed out)

**User selection:** Recommended

**Resulting decision:** D-69 (single leaf `paypal-csv`; validator extends `'in:...,paypal-csv'`; `HeaderSniffer::sniffPaypalCsv()` reuses the language-profile registry from D-59; no greyed-out future option — premature noise) + D-70 (`SourceAdapterRegistry::'paypal-csv' => PaypalCsvAdapter::class`).

---

## Deferred Ideas (captured for future phases)

These came up during analysis but are explicitly out-of-scope for Phase 4 — see `<deferred>` in `04-CONTEXT.md` for the full list. Highlights:

- **ING-09 / Reporting API** — Personal account blocks the API path; revisit on Business upgrade.
- **Counterparty-heuristic salary detection** — Phase 8 (recurring detection) territory.
- **Multi-PayPal-account support** — Single PayPal in v1; mirrors Phase 3's single-ICS-card pattern.
- **Review queue for tolerant-window pair candidates** — Phase 5 ships this surface.
- **PayPal `.eml` receipt ingestion** — Phase 7.
- **Manual "link these two rows" UI** — Most likely Phase 5 carry-over.
- **`Modules/Transfers/Public/` surface** — Empty until Phase 5's chain resolver needs it.

---

## Claude's Discretion Notes

These are spots where the planner has freedom to pick the cleanest shape during plan-phase:

- **Wizard naming-prompt wording** — Mirror Phase 3 D-38; UI-SPEC pass locks during planning.
- **Wave 0 fixture count** — Default to one (user has one sample); extend if a second-language sample arrives.
- **`pair_transaction_id` migration filename/ordering** — Default slot `2026_05_15_010002_*`, flexible.
- **Layer-2 tolerance-threshold values** — Match `research/PITFALLS.md` Pitfall 4 (±€5 OR ±2% across ±10-day window).
- **Override action location** — Detail page only by default (preserves calm aesthetic).
- **Pair-break UX** — Single-click + inline toast by default.
- **Reconciliation gate** — Soft warning on `sum(net) ≠ closing - opening` mismatch, not blocker (matches Phase 2's multi-statement MT940 flag posture).

---

*Discussion log written: 2026-05-15*
