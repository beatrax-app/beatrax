---
gsd_state_version: 1.0
milestone: v1.3
milestone_name: Local & in sync
status: executing
stopped_at: Completed 09-02-PLAN.md
last_updated: "2026-06-13T17:51:00.000Z"
progress:
  total_phases: 15
  completed_phases: 8
  total_plans: 47
  completed_plans: 44
  percent: 54
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
Plan: 2 of 5 — COMPLETE (09-02 Anomaly evaluator + three detectors)

- **Milestone:** v1.3 "Local & in sync"
- **Status:** Executing Phase 09
- **Phase:** 9 of 15 (unusual charge / anomaly alerts)
- **Plan:** 09-02 complete — AnomalyEvaluator + RobustStatistics + three detectors (large-vs-typical, first-time, duplicate) + RecurringSeriesQuery membership + suppression filtering + idempotent insert. Next: 09-03 read/write surface.
- **Progress:** [█████████░] 91%

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

- **Last session:** 2026-06-13T17:51:00.000Z
- **Stopped at:** Completed 09-02-PLAN.md (Anomaly evaluator + three detectors + suppression + idempotent insert)
- **Resume by:** Execute Plan 09-03 (anomaly read/write surface — AnomalyAlertQuery + Public Actions + suppression management). Run `/gsd:execute-phase 09`.

---
*State initialized: 2026-06-07 for milestone v1.3 "Local & in sync"*
