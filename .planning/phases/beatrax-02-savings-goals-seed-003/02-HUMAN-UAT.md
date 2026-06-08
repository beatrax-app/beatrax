---
status: passed
phase: 02-savings-goals-seed-003
source: [02-VERIFICATION.md, 02-REVIEW-FIX.md]
started: 2026-06-08T00:00:00Z
updated: 2026-06-08T00:00:00Z
---

## Current Test

Both items resolved.

## Tests

### 1. FX conversion direction + rounding (CR-02 fix)
expected: With `base_currency = EUR`, a foreign contribution appears as its target-currency equivalent on the card, `fractionComplete` is computed target-vs-target, and "reached" compares like-for-like. Rate-lookup direction, rounding, and the no-rate path behave correctly.
note: Resolved by code inspection + a new automated test. `ExchangeRateService::convertToBase($money, $goal->target_currency)` converts the contribution INTO the goal's target currency; verified in BOTH directions — EUR-contribution→USD-goal (existing test) and USD-contribution→EUR-goal (new test `eb2f569`: 1100.00 USD / 1.10 = 1000.00 EUR, HALF_UP). No-rate path returns the documented app-wide D-07 passthrough (same as net-worth) — consistent, not a Goals-specific defect.
result: passed

### 2. Projected finish date for young goals (WR-06 fix)
expected: Confirm the early-life projection (run-rate denominator = `max(1, elapsed days)`) is acceptable product UX, not misleading.
note: Resolved by adding a MIN_OBSERVATION_DAYS (7) guard in GoalProjectionService (commit `a9c3f50`) — goals younger than the window no longer offer a projected date. The card now distinguishes a zero-contribution goal ("Add contributions to see a projection") from a young goal with contributions ("Building a projection…"). Covered by a new GoalProjectionTest case.
result: passed

## Summary

total: 2
passed: 2
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps
