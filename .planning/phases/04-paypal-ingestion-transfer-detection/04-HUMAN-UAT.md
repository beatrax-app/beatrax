---
status: partial
phase: 04-paypal-ingestion-transfer-detection
source: [04-VERIFICATION.md]
started: 2026-05-16T00:00:00Z
updated: 2026-05-16T00:00:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Upload a real PayPal CSV through the three-issuer wizard end-to-end (browser)
expected: Wizard shows PayPal issuer option → Activity Download (CSV) format → account-naming step on first upload → preview table with one row per logical payment → confirm → rows appear in /transactions list
result: [pending]

### 2. Open /transactions/{id} for a PayPal import row and exercise the Reclassify dropdown
expected: Dropdown lists all Transaction::TYPES except the current type; selecting a new type and clicking Save shows the inline Alpine toast; refreshing the page confirms the new type persists
result: [pending]

## Summary

total: 2
passed: 0
issues: 0
pending: 2
skipped: 0
blocked: 0

## Gaps
