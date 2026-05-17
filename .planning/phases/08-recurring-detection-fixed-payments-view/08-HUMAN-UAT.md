---
status: partial
phase: 08-recurring-detection-fixed-payments-view
source: [08-VERIFICATION.md]
started: 2026-05-17T19:35:00Z
updated: 2026-05-17T19:35:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. /recurring grouped view visual rendering

expected: Open /recurring in the browser after approving at least one expense and one income series. Both the "Recurring expenses" section and "Recurring income" section render rows with display name, monthly-equivalent EUR amount, funding-chain icon stack, category badge, and next-expected-charge date. Data is real (not placeholder text), chain badge appears when a series has a linked chain_link, next-expected text shows relative date.
result: [pending]

### 2. /recurring/series/{id} ApexCharts chart rendering

expected: Open /recurring/series/{id} in the browser for an approved recurring series with multiple occurrences. The ApexCharts amount-over-time chart renders with actual data points on a date axis. EUR shadow line is visible for USD-priced series. The occurrences table below lists real historical occurrences with date and amount, each linking to the transaction drill-in.
result: [pending]

### 3. Bulk Approve sticky action bar UX

expected: Open /recurring/review, select multiple pending rows via checkboxes. The sticky action bar appears at the bottom of the page on first checkbox selection. After clicking "Bulk approve", a "N approved" Undo toast fires and selected rows move out of the Pending tab.
result: [pending]

## Summary

total: 3
passed: 0
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps
