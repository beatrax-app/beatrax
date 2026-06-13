---
gsd_state_version: 1.0
milestone: v1.3
milestone_name: Local & in sync
status: executing
stopped_at: Completed 09-04-PLAN.md
last_updated: "2026-06-13T19:05:00.000Z"
progress:
  total_phases: 15
  completed_phases: 8
  total_plans: 47
  completed_plans: 46
  percent: 57
---

# State: beatrax

> Project memory. The single source of truth for "where are we right now."

## Project Reference

- **Project doc:** `.planning/PROJECT.md`
- **Requirements:** `.planning/REQUIREMENTS.md`
- **Roadmap:** `.planning/ROADMAP.md`
- **Core value:** Show me, in one place, what I actually owe and where the money truly came from — across every account chain — so my monthly finances stop being a manual reconciliation puzzle.
- **Current focus:** Phase 09 — unusual-charge-anomaly-alerts

## Current Position

Phase: 09 (unusual-charge-anomaly-alerts) — EXECUTING
Plan: 4 of 5 — COMPLETE (09-04 Anomaly detection orchestration: jobs + listener + scheduler)

- **Milestone:** v1.3 "Local & in sync"
- **Status:** Executing Phase 09
- **Phase:** 9 of 15 (unusual charge / anomaly alerts)
- **Plan:** 09-04 complete — DetectAnomaliesJob ((userId,txnId)-unique, off the import txn) + EvaluateAnomaliesOnTransactionImport listener (queues, never inline, D-12/T-09-14) + BackfillAnomaliesJob (lazyById full-history, anomaly_backfilled_at wholesale guard, idempotent/resumable, D-13/D-14) + ReviveExpiredAnomalySnoozesJob (global hourly snoozed->open + audit) + SafetyNetAnomalySweepJob (per-user 30d NOT-EXISTS catch-up) + two scheduler entries (anomaly.revive-snoozes, anomaly.safety-net-sweep). All four jobs share the single AnomalyEvaluator::evaluate() path. Next: 09-05 UI (/drift type switch + settings toggle dispatching the backfill + nav badge + dashboard tile).
- **Progress:** [██████████] 95%

```
Phases [██              ] 2/15
```

**Next action:** Start Phase 3. Run `/gsd:discuss-phase 3` (recommended) or `/gsd:plan-phase 3`. Phase 2 left 2 tracked dev-DB test goals ("Emergency fund", "Holiday fund") created during UAT — archive/remove them if unwanted.

## Performance Metrics

| Metric | Value |
|--------|-------|
| Phases planned | 15 |
| Phases complete | 0 |
| Plans complete | 0 |
| Requirements mapped | 44/44 |

## Accumulated Context

### Decisions

- v1.3 sync = full P2P multi-master (not hub-and-spoke); de-risk via the Phase 10 op-log/CRDT spike before committing Phase 11+.
- v1.3 scoped as one large milestone (4 tracks, 15 phases); Tracks 1–3 ship independently if Track 4 slips.
- GSD baseline adopted at v1.3 (v1.0–v1.2 ran on `.docs/`).
- Milestone-level research skipped; novel areas (CRDT, Noise, FTS) researched per-phase at plan-phase.
- goals route registered as closure stub (returns 501) until Plan 04 wires GoalsPage::class.
- newFactory() override added to Goal model to resolve module-local GoalFactory (bypasses Laravel default resolver).
- GoalProgressQuery uses raw DatabaseManager (not Eloquent) to avoid phpstan-strict-rules staticMethod.dynamicCall on whereIn.
- TRAILING_WINDOW_DAYS=HORIZON_LIMIT_DAYS=90 in GoalProjectionService (D-07 tunables, aligns run-rate window with max forecast horizon).
- archivedForUser() created in Plan 02 (not 04) — Plan 04 only consumes it.
- GoalWriter injects only DatabaseManager (not GoalProgressQuery) — intentional parallel-safe decoupling; own inline assertOwnedAccountOrNull() query.
- GoalWriter::update() throws InvalidArgumentException on cross-user/missing goal; lifecycle methods silently no-op (consistent with write-returns-result vs fire-and-forget patterns).
- GoalsPage: createGoal()/updateGoal() separate actions with editGoalId property — matches Wave 0 test stub names (Plan 01 stubs are authoritative over plan spec naming).
- GoalsSummaryCard: top-3 nearest-finishing sorted by projectedFinishDate (null last) using usort, no extra ForecastQuery dependency.
- goals.index route closure stub swapped for GoalsPage::class in Plan 04 once the class exists at boot time.
- HandlesTaxTagging placed in Public namespace (not Internal) to comply with TaxBoundaryTest arch rule — cross-module traits must be in Public.
- Tax popover rendered via @include() not <x-component> so Livewire view-scope properties (taxPickerTxId etc.) are accessible without prop passing.
- Alpine $wire.taxPickerTxId watch drives popover open/close (not @js() snapshot which only captures initial value).
- taxTagStateFor issues ONE whereIn query per render for the full page batch (Pitfall-1 guard).
- batchSuggestionDismissed flag prevents batch suggestion re-surfacing after apply or dismiss (Pitfall-7 guard).
- [09-01] anomaly_alerts is a NEW per-transaction module keyed UNIQUE(transaction_id), not an extension of drift_alerts (D-01/D-16).
- [09-01] AnomalyAlertStateMachine adds the diverging `dismissed -> open` undo edge (D-18); acknowledged stays terminal — only divergence from the drift map.
- [09-01] noTransactionWritesFromAnomaly narrowed vs the Recurring analog to permit Transaction::query() reads (evaluator needs baselines) — forbids only writes (Transaction::create + table-builder writes).
- [09-01] anomaly baseline/latest/currency columns nullable since first-time-merchant flags carry no per-merchant amount baseline.
- [09-02] Large-vs-typical = median + k×MAD robust z (per-counterparty ≥5 samples) with per-category p95 fallback; settled-currency-only comparison; sensitivity->k clamp curve (50% -> 3.0); all tunables named constants.
- [09-02] first-time-large carries BOTH first_time and large — a baseline-less new merchant's large-vs-overall finding IS the large evidence (evaluator records large when first-time fires).
- [09-02] FirstTimeMerchant judges large-vs-overall with a lower OVERALL_HISTORY_MIN (3) than the per-merchant thin cutoff (5).
- [09-02] Duplicate = same counterparty + exact settled amount/currency/direction within 7 days, excluding pairs where BOTH are approved-recurring-series members (new RecurringSeriesQuery::seriesMembershipForTransactionIds Public method).
- [09-02] Suppression checked BEFORE insert (D-17): reasons dropped per matching anomaly_suppression_rules row (counterparty OR null-counterparty fallback, detector, direction, amount band, currency); reasons canonically ordered before persistence.
- [09-03] AnomalyAlertQuery resolves merchant name via a permitted ledger READ (transaction_id -> counterparty_id) since anomaly_alerts has no counterparty_id column; names via CounterpartyProfileQuery::identitiesForIds (no raw cross-module SELECT).
- [09-03] Suppression band stored as [min(round(0.85x),round(1.15x)), max(...)] so the signed-amount band matches the evaluator's band_low <= settled <= band_high test (round(1.15x) is the more-negative band_low for an expense).
- [09-03] Snooze (now, now+6mo] bounds enforced IN SnoozeAnomalyAlert (not the Livewire layer) so every caller is protected (T-09-10); 404 guard runs first.
- [09-03] DismissAnomalyAlertAsExpected inserts no rule when latest_amount_minor is null (first-time-only flag has no band); RemoveAnomalySuppressionRule has two paths — settings delete-only vs undo (delete-by-source_anomaly_alert_id + dismissed->open re-open, D-18).
- [09-04] All four anomaly jobs (reactive DetectAnomaliesJob, BackfillAnomaliesJob, SafetyNetAnomalySweepJob, ReviveExpiredAnomalySnoozesJob) route through the single AnomalyEvaluator::evaluate() path — zero duplicated detection logic.
- [09-04] TransactionImported listener stays synchronous but only QUEUES a (userId,txnId)-unique DetectAnomaliesJob — baseline math never runs inline in the import transaction (D-12/T-09-14, asserted).
- [09-04] BackfillAnomaliesJob is userId-unique + wholesale-no-ops when users.anomaly_backfilled_at is set (D-13); walks full history via lazyById(500), idempotent/resumable on UNIQUE(transaction_id), lands alerts in Open with no muting (D-14). Dispatched on first activation by the Plan 05 settings toggle (no dispatch site yet).
- [09-04] SafetyNetAnomalySweepJob recency window = 30 days by transactions.created_at (import time); NOT EXISTS against anomaly_alerts so only genuinely-unalerted recent rows are re-evaluated; per-user fan-out via lazyById(100). The one-shot backfill owns full history.
- [09-04] Both per-user jobs inject Clock (not the now() global helper) for the backfilled-at stamp / window cutoff — satisfies larastanStrictRules.noGlobalLaravelFunction + deterministic under setTestNow().
- [09-04] anomaly.revive-snoozes stays global (state machine resolves user from the row, only flips snoozed->open — T-09-16); anomaly.safety-net-sweep fans out per-user. routes/console.php is outside the PHPStan paths scope by design, so the new Schedule:: entries are not gated there.

### Critical path

- Track 4 (Phases 10–15) is the critical path and highest risk: 10 → 11 → (12, 13, 14) → 15.
- Phase 11 is the single biggest piece; Phase 10 must validate before it commits.
- Cross-track: Phase 14 consumes LOCK-04 from Phase 5; Phase 15 needs Phase 4 + Phases 11–14.

### TODOs

- Pre-existing test break (predates Phase 4): `Modules/DriftAlerts/tests/Feature/GlobalDriftThresholdSettingTest` — 4 tests fail with `baseCurrency => "Please choose a currency."` on settings save; the tests predate Phase 1's required baseCurrency field. Fix test setup (or relax validation) — surfaced 2026-06-10 during Phase 4 Wave 0 post-merge gate.
- Host-toolchain note: `EmailScan` Integration (DiscoveryScanNoEmlBlobs, EmlOrphanCleanup) + `DevMode` (CommandSpawner, ArtisanStreamReconnect) tests are flaky under `pest --parallel` (pass serially) — run gates serially or fix isolation.
- Pre-existing test break (predates Phase 6, verified failing at fe0f513): `CrossUserIsolationTest` "covers or allow-lists every auth-gated GET route" guard fails on 14 uncovered routes from earlier phases (goals.index, pots.index, counterparties.*, budgets.index, cashbook.index, chains.index/hints, drift.watch, settings.aliases, community.mystery-merchants, core.help.data-locations, dev.logs.stats). Each needs a probe case or allow-list entry — surfaced 2026-06-12 during Phase 6 Wave 1 post-merge gate (calendar.index WAS covered there).

### Blockers

- (none)

## Session Continuity

- **Last session:** 2026-06-13T19:05:00.000Z
- **Stopped at:** Completed 09-04-PLAN.md (Anomaly detection orchestration — reactive DetectAnomaliesJob + TransactionImported listener + full-history BackfillAnomaliesJob + ReviveExpiredAnomalySnoozesJob + SafetyNetAnomalySweepJob + two scheduler entries)
- **Resume by:** Execute Plan 09-05 (anomaly UI — /drift type switch consuming AnomalyAlertQuery + settings toggle dispatching BackfillAnomaliesJob on first activation + top-nav badge + dashboard tile). Run `/gsd:execute-phase 09`.

---
*State initialized: 2026-06-07 for milestone v1.3 "Local & in sync"*
