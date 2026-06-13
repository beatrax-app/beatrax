---
phase: 09-unusual-charge-anomaly-alerts
plan: 03
subsystem: api
tags: [anomaly, public-surface, dto, spatie-data, actions, state-machine, suppression, cross-user-404, events, pest, laravel-modules]

# Dependency graph
requires:
  - phase: 09-01 (Anomaly module scaffold)
    provides: "AnomalyAlert/AnomalySuppressionRule models, AnomalyAlertStateMachine (sole mutator + dismissed->open undo edge), anomaly_alerts/anomaly_suppression_rules tables, AnomalyAlertFactory state factories, noTransactionWritesFromAnomaly arch invariant (reads allowed)"
  - phase: 09-02 (Anomaly evaluator + detectors)
    provides: "AnomalyEvaluator.evaluate entry point + filterSuppressed (band_low <= settled <= band_high) — consumed by the suppression test to prove skip-in-band / fire-above-band"
  - phase: 05-recurring-drift-alerts (DriftAlerts module)
    provides: "DriftAlertQuery / DriftAlertDto / DriftAlertDtoMapper / Acknowledge|Snooze|Dismiss Actions / lifecycle events / DriftAlertCrossUser404Test — cloned and re-keyed to the per-transaction anomaly shape"
  - phase: counterparties
    provides: "CounterpartyProfileQuery::identitiesForIds — batched merchant display-name resolution (the only cross-module read crossing)"
  - phase: ledger
    provides: "transactions table (read for transaction_id -> counterparty_id resolution) + Money value object"
provides:
  - "AnomalyAlertQuery: openForUser/historyForUser/dismissedForUser/openCountForUser (revival-aware) + openDetectorBreakdownForUser + id-DESC cursor pagination"
  - "AnomalyAlertDto (reasons[] + Money baseline/latest + dismissedAs, NO annualized/threshold) + AnomalySuppressionRuleDto"
  - "AnomalyAlertDtoMapper (static, pure: reasons JSON decode, nullable->zero Money, loud-fail on detected_at)"
  - "AnomalySuppressionRuleQuery::forUser — the D-18 settings list"
  - "AcknowledgeAnomalyAlert / SnoozeAnomalyAlert / DismissAnomalyAlert Public Actions (cross-user 404, state-machine transition, lifecycle events)"
  - "DismissAnomalyAlertAsExpected — dismiss + server-computed ±15% suppression-rule insert with source_anomaly_alert_id provenance (D-17)"
  - "RemoveAnomalySuppressionRule — removeRule (settings delete-only) + undoSuppression (delete by source + re-open via dismissed->open, D-18)"
  - "AnomalyAlertAcknowledged / AnomalyAlertSnoozed / AnomalyAlertDismissed Public events"
  - "seven Public surface bindings registered as singletons in AnomalyServiceProvider"
affects: [09-04-jobs, 09-05-ui]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Per-transaction alert read surface clones the per-series drift query but resolves the merchant via a permitted ledger READ (transaction_id -> counterparty_id) since anomaly_alerts carries no counterparty_id column"
    - "Server-computed suppression band: round(0.85x)/round(1.15x) normalised to [min,max] so the signed-amount band matches the evaluator's band_low <= settled <= band_high test"
    - "Snooze server-side bounds (now, now+6mo] live IN the Public Action (not the Livewire layer) so every caller is protected (T-09-10)"
    - "Two-path suppression removal: settings Remove (delete-only, anomaly stays dismissed) vs undo toast (delete-by-provenance + dismissed->open re-open)"
    - "DTO reasons[] + dismissedAs replace drift's annualized/threshold fields — point-in-time event semantics, not recurring drift"

key-files:
  created:
    - Modules/Anomaly/Public/Dto/AnomalyAlertDto.php
    - Modules/Anomaly/Public/Dto/AnomalySuppressionRuleDto.php
    - Modules/Anomaly/Internal/Mapping/AnomalyAlertDtoMapper.php
    - Modules/Anomaly/Public/Services/AnomalyAlertQuery.php
    - Modules/Anomaly/Public/Services/AnomalySuppressionRuleQuery.php
    - Modules/Anomaly/Public/Actions/AcknowledgeAnomalyAlert.php
    - Modules/Anomaly/Public/Actions/SnoozeAnomalyAlert.php
    - Modules/Anomaly/Public/Actions/DismissAnomalyAlert.php
    - Modules/Anomaly/Public/Actions/DismissAnomalyAlertAsExpected.php
    - Modules/Anomaly/Public/Actions/RemoveAnomalySuppressionRule.php
    - Modules/Anomaly/Public/Events/AnomalyAlertAcknowledged.php
    - Modules/Anomaly/Public/Events/AnomalyAlertSnoozed.php
    - Modules/Anomaly/Public/Events/AnomalyAlertDismissed.php
    - Modules/Anomaly/tests/Unit/AnomalyAlertDtoMapperTest.php
    - Modules/Anomaly/tests/Feature/AnomalyAlertQueryTest.php
    - Modules/Anomaly/tests/Feature/AcknowledgeAnomalyAlertTest.php
    - Modules/Anomaly/tests/Feature/SnoozeAnomalyAlertTest.php
    - Modules/Anomaly/tests/Feature/DismissAnomalyAlertTest.php
    - Modules/Anomaly/tests/Feature/AnomalyAlertCrossUser404Test.php
    - Modules/Anomaly/tests/Feature/AnomalySuppressionTest.php
    - Modules/Anomaly/tests/Feature/AnomalySuppressionUndoTest.php
  modified:
    - Modules/Anomaly/Providers/AnomalyServiceProvider.php

key-decisions:
  - "AnomalyAlertQuery resolves the merchant display name via a permitted ledger READ (transaction_id -> counterparty_id), not a counterparty_id column — anomaly_alerts keys per-transaction and has no counterparty_id"
  - "Suppression band stored as [min(boundA,boundB), max(boundA,boundB)] so round(1.15x) (more negative for an expense) becomes band_low and round(0.85x) becomes band_high, matching the evaluator's signed band test"
  - "Snooze (now, now+6mo] bounds enforced in SnoozeAnomalyAlert itself (T-09-10), 404 guard first so a cross-user probe never learns whether its target was in range"
  - "DismissAnomalyAlertAsExpected inserts no suppression rule when latest_amount_minor is null (first-time-only flag has no meaningful band) — the dismissal still stands"
  - "undoSuppression only fires the dismissed->open transition when the alert is actually dismissed; the state machine would reject any other source state"

patterns-established:
  - "Public read surface: revival-aware open filter (state=open OR (snoozed AND snoozed_until<=now)) copied verbatim from drift; names via CounterpartyProfileQuery::identitiesForIds; no raw cross-module SELECT"
  - "Lifecycle Action shape: where(id)->where(user_id)->first() => NotFoundHttpException on null, idempotent no-op on target state, state-machine transition with extraColumns, dispatch event"
  - "Server-computed, provenance-tracked suppression rules (source_anomaly_alert_id) enabling a clean undo-by-provenance delete + re-open"

requirements-completed: [ANOM-02]

# Metrics
duration: ~40 min
completed: 2026-06-13
---

# Phase 9 Plan 03: Anomaly Read/Write Public Surface Summary

**AnomalyAlertQuery (revival-aware open/history/dismissed + count + per-detector breakdown, id-DESC cursor) with a reasons[]/Money DTO + pure mapper, five Public Actions (acknowledge/snooze/dismiss/dismiss-as-expected/remove-rule) cloning the drift cross-user 404 guard, server-computed ±15% suppression rules with provenance, and the dismissed→open undo path — all reading merchants through the Counterparties Public surface and never trusting a client band.**

## Performance

- **Duration:** ~40 min
- **Started:** 2026-06-13
- **Completed:** 2026-06-13
- **Tasks:** 3 (all TDD)
- **Files modified:** 22 (21 created, 1 modified)

## Accomplishments
- `AnomalyAlertQuery` clones the drift read surface re-keyed to the per-transaction shape: `openForUser`/`historyForUser`/`dismissedForUser`/`openCountForUser` with the verbatim revival-aware open filter, `id DESC` cursor pagination, an `openDetectorBreakdownForUser` per-reason tile feed, and merchant names resolved via `CounterpartyProfileQuery::identitiesForIds` (the only cross-module crossing). `dismissedForUser` filters the plain `dismissed` state — no `dismissed_cancelled` anywhere.
- `AnomalyAlertDto` carries `reasons[]` + `dismissedAs` + a Money baseline/latest pair (NO annualized/threshold), and `AnomalyAlertDtoMapper` clones the drift mapper's Money/loud-fail discipline plus reasons JSON decode and nullable→zero-Money collapse. `AnomalySuppressionRuleDto` + `AnomalySuppressionRuleQuery::forUser` feed the D-18 settings list.
- Three lifecycle Actions (acknowledge/snooze/dismiss) transition via the sole-mutator state machine, enforce cross-user 404, and dispatch lifecycle events. Snooze bounds the target server-side to `(now, now+6mo]` inside the action (T-09-10).
- `DismissAnomalyAlertAsExpected` dismisses AND inserts one suppression rule per tripped reason with a server-computed ±15% band and `source_anomaly_alert_id` provenance; the band is derived from the persisted alert's `latest_amount_minor`, never the client (T-09-11). `RemoveAnomalySuppressionRule` provides the settings delete-only path and the undo-by-provenance path that re-opens the anomaly via the diverging `dismissed → open` edge (D-18).
- All gates green: 175 Pest tests (full Anomaly suite + BoundaryArchTest), Pint clean, PHPStan L10 strict clean on every touched source file. The suppression test proves the evaluator then SKIPS an in-band future charge and FIRES on a charge above the band.

## Task Commits

Each task committed atomically (TDD):

1. **Task 1: AnomalyAlertQuery + DTO + mapper + suppression-rule query** - `4d665f3` (feat)
2. **Task 2: Acknowledge/Snooze/Dismiss Actions + cross-user 404 + events** - `ff9e3aa` (feat)
3. **Task 3: DismissAnomalyAlertAsExpected (±15% suppression) + RemoveAnomalySuppressionRule (undo)** - `c319a56` (feat)

**Plan metadata:** _(this commit)_ (docs: complete plan)

## Files Created/Modified
See `key-files` frontmatter. Highlights:
- `Modules/Anomaly/Public/Services/AnomalyAlertQuery.php` — revival-aware read surface + per-detector breakdown + txn→counterparty name resolution.
- `Modules/Anomaly/Internal/Mapping/AnomalyAlertDtoMapper.php` — reasons JSON decode + nullable→zero Money + loud-fail on detected_at.
- `Modules/Anomaly/Public/Actions/DismissAnomalyAlertAsExpected.php` — server-computed ±15% band insert with provenance.
- `Modules/Anomaly/Public/Actions/RemoveAnomalySuppressionRule.php` — two-path removal (settings delete vs undo re-open).
- `Modules/Anomaly/Providers/AnomalyServiceProvider.php` — seven new Public singletons.

## Decisions Made
- **Merchant name via a ledger read, not a column (Task 1):** `anomaly_alerts` keys `UNIQUE(transaction_id)` and carries no `counterparty_id`, so the query resolves `transaction_id → counterparty_id` with a permitted `->table('transactions')->...->get()` READ (the `noTransactionWritesFromAnomaly` invariant forbids only writes), then resolves names via `identitiesForIds`.
- **Signed-amount band normalisation (Task 3):** for an expense, `round(1.15 * latest)` is the more-negative bound. Bands are stored as `[min, max]` so `band_low ≤ settled ≤ band_high` holds for the evaluator's signed comparison.
- **Snooze bounds in the Action (Task 2):** the `(now, now+6mo]` bound lives in `SnoozeAnomalyAlert` rather than the Livewire layer (where drift put it) so every caller — including Plan 05's UI and any future job — is protected.
- **No rule when latest is null (Task 3):** a first-time-only flag has no per-merchant amount baseline; dismiss-as-expected records no band in that case but the dismissal still stands.

## Deviations from Plan

None — plan executed exactly as written. The two implementation choices that needed reasoning (merchant-name resolution via a ledger read, and signed-band normalisation) are clarifications of the plan's intent given the Plan 01 schema, not departures from it; both are documented under Decisions Made.

## Issues Encountered
- **Event-fake assertion ordering:** the first pass resolved the lifecycle Actions in `beforeEach`, caching the singleton with the real dispatcher before `Event::fake()` ran in the test body, so `assertDispatched` saw nothing. Fixed by resolving each Action fresh inside the test after faking — matching the drift test pattern. Resolved before any commit.
- **PHPStan `cast.useless` on `(int) $user->id`:** `User::$id` is already `int` per PHPDoc. Removed the redundant casts in the three actions (the drift analog passes `$user->id` directly). Clean afterward.
- PHPStan was scoped to the touched paths with `php -d memory_limit=3G ./vendor/bin/phpstan analyse <files>` per the project memory note about host fd/memory limits on whole-repo runs.

## Known Stubs
None — the query, DTO, mapper, five actions, and two events are fully wired against real tables and the state machine. No placeholder data paths remain. (The provider's `boot()` retains explicit `TODO(Plan 04/05)` notes for the not-yet-built TransactionImported listener, scheduled sweeps, and Livewire surface — those are the planned wave boundary, not stubs in this plan's deliverables.)

## Threat Flags
None — no new network endpoint, auth path, file-access pattern, or trust-boundary schema change beyond the plan's threat model. The Public Actions add the cross-user 404 guards (T-09-09), the server-side snooze bound (T-09-10), and the server-computed suppression band (T-09-11) that the threat register prescribes.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- The Public read/write surface is complete and tested: Plan 05's `/drift` type switch can consume `AnomalyAlertQuery` + the five Actions + `AnomalySuppressionRuleQuery` directly, and the top-nav badge composer can read `openCountForUser` / `openDetectorBreakdownForUser`.
- The lifecycle events (`AnomalyAlertAcknowledged`/`Snoozed`/`Dismissed`) are available for Plan 04/05 listeners (badge refresh, forecasting exclusion) without re-reading the alert row.
- The suppression create + undo + settings-remove paths are wired and provenance-tracked, ready for the Plan 05 settings surface and the post-dismiss undo toast.

## Self-Check: PASSED
- All 21 created files verified present on disk.
- Task commits `4d665f3`, `ff9e3aa`, `c319a56` present in `git log`.
- Plan verification re-run: `./vendor/bin/pest Modules/Anomaly tests/Contracts/BoundaryArchTest.php` → 175 passed (585 assertions). Pint `--test` clean, PHPStan L10 strict clean on all touched source files.

---
*Phase: 09-unusual-charge-anomaly-alerts*
*Completed: 2026-06-13*
