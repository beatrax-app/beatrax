# Phase 5 — Test Migrations Audit Trail

This file tracks cross-phase test migrations / deletions affecting Phase 5
coverage. Per-plan summaries live alongside as `05-XX-SUMMARY.md` files.

## Test Migrations

- 2026-05-18 — `Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php` retired;
  the dashboard tile assertions (cases verifying the "Next ICS settlement" tile
  rendered the amount + due-date copy, and the hidden-state when no statement
  existed) were ported verbatim into
  `Modules/Forecasting/tests/Feature/ForecastHighlightsTileTest.php` (Phase 10
  Plan 10-04 Task 3, dashboard tile consolidation per D-1013). The failed-job
  toast tests (chain_resolution_runs.status='failed' read + cross-user isolation
  guard for issue #1 + #8) were preserved in the same module as
  `Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php` since the
  toast remains Phase 5 functionality. CHN-06 (next ICS settlement visible)
  coverage is unchanged — the underlying `CardStatementQuery::nextSettlementForUser`
  Public service (added by Plan 10-02 Task 4) remains the source of truth and the
  new tile is a strict superset of the legacy tile's surface.
