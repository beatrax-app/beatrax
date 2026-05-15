---
status: partial
phase: 03-ics-cards-multi-currency-display
source: [03-VERIFICATION.md]
started: 2026-05-15T22:35:00Z
updated: 2026-05-15T22:35:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. End-to-end real PDF import via the browser
expected: Logged-in user visits /imports/new, picks Source=ICS, Format=PDF, uploads a Mijn ICS export with at least one FX charge; preview renders rows; first-time upload prompts for ICS card account name (verbatim copy "Name your ICS card account."); after Save name, rows preview shows; Confirm import lands rows; subsequent ICS upload skips the naming step.
result: [pending]

### 2. Dashboard at / in 'original' currency mode (per-currency tile rows)
expected: After Settings → Default view = "Original currency", dashboard / renders one tile-row per currency present in the period (EUR + USD + GBP alphabetical), each captioned with the ISO code, with In/Out/Net values formatted as €68,86 (nl_NL) for EUR and $74.43 (en_US) for USD/GBP. Zero-activity currencies are omitted. Switching the preference back to "EUR only" collapses to the Phase 1 single-row layout.
result: [pending]

### 3. Transactions list at /transactions in 'original' currency mode with FX rows
expected: /transactions renders the Flux segmented control "EUR only / Original currency". When the toggle is "Original currency" (or user default is "original"), foreign-currency rows render as two stacked lines: native primary (e.g. "$50.00") in slate-900, settled-EUR secondary (e.g. "€ 43,71") in mt-1 slate-500 text-xs. EUR-native rows render as a single line. Toggle clicks update the URL ?currency=eur / ?currency=original; clean URL means the user preference is in effect. Page refresh preserves the toggle state.
result: [pending]

### 4. Transaction detail at /transactions/{id} for a USD transaction — Effective-rate row
expected: Visiting /transactions/{id} for one of the imported FX transactions (e.g. AUGMENT CODE 50 USD → 43,71 EUR) renders the calm two-column metadata block AND below it an "Effective rate" <dl> row showing "€0.874 / USD" (rate scaled to 3 decimals via BigDecimal) and a slate-500 12px helper line "Includes any ICS markup." For an EUR-native row, the Effective rate <dl> is absent.
result: [pending]

### 5. Settings page round-trip (two preferences) UX
expected: Visiting /settings renders the calm form with two fields: Default view on the transactions list (EUR only / Original currency) and Period starts on day (1..28). Submit shows inline "Saved." in emerald-700 that auto-dismisses after ~4s via wire:transition. Validation errors render verbatim "Choose a day from 1 to 28." and "Pick one of the available options." in rose-600 below each field.
result: [pending]

## Summary

total: 5
passed: 0
issues: 0
pending: 5
skipped: 0
blocked: 0

## Gaps
