---
phase: 09
slug: unusual-charge-anomaly-alerts
status: verified
threats_open: 0
asvs_level: 2
created: 2026-06-14
---

# Phase 09 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.
> Verified by `gsd-security-auditor` against the live implementation (register authored at plan time — mitigations verified, not retroactively scanned).

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| queue/console → DB | Backfill, detection, sweep, and revival jobs run outside HTTP; the `BelongsToUser` global scope does NOT fire, so every raw query must carry an explicit `where('user_id')` | Per-user transaction history, anomaly alerts, suppression rules |
| migration → SQLite | The state-trigger pair is the DB-layer enforcement that no invalid `anomaly_alerts.state` value lands, independent of app code | Alert lifecycle state |
| dismissed charge → suppression rule → future evaluation | Suppression bands are read at evaluation time; a too-wide band silently hides future charges | Server-computed ±15% band (counterparty + detector + direction) |
| native vs settled currency | Mixing currencies in the baseline produces false "large" flags | settled_amount_minor + settled_currency |
| Livewire client → Public Action | alertId / snooze ISO target / suppression edits / sensitivity+floor inputs are tamper-able and must be server-validated | Alert + suppression-rule identifiers, user settings |
| browser storage (phone PWA) | No financial data may be persisted in browser storage (Phase 4 constraint) | Alert render data (server-rendered only) |

---

## Threat Register

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-09-01 | Tampering | `anomaly_alerts.state` written outside the state machine | mitigate | `noOtherAnomalyAlertStateMutator` arch invariant (BoundaryArchTest.php:815-869) + SQLite BEFORE INSERT/UPDATE OF state trigger pair (migration 010001:81-92) | closed |
| T-09-02 | Tampering | Invalid state value inserted directly | mitigate | Trigger pair `RAISE(ABORT)` on state ∉ {open,acknowledged,snoozed,dismissed} (migration 010001:82-91) | closed |
| T-09-03 | Elevation of Privilege | Anomaly module writing transactions | mitigate | `noTransactionWritesFromAnomaly` arch invariant (BoundaryArchTest.php:871-918) — reads allowed, writes forbidden | closed |
| T-09-04 | Information Disclosure | Cross-user data leakage under queue/console | mitigate | All 3 models `use BelongsToUser`; per-user tables; explicit-filter discipline | closed |
| T-09-05 | Information Disclosure | Cross-user baseline read in a detector | mitigate | Explicit `where('user_id')` at every raw query: AnomalyEvaluator.php:62,209; LargeVsTypicalDetector.php:151; FirstTimeMerchantDetector.php:75,95; DuplicateChargeDetector.php:98 | closed |
| T-09-06 | Tampering / Repudiation | Silent suppression hiding a real fraudulent charge | mitigate | Suppression is the only insert-skip (`filterSuppressed` before insert); narrow rules (counterparty+band+detector+direction); user-visible + undoable; larger charge re-fires | closed |
| T-09-07 | Tampering | Wrong-currency comparison masking/fabricating anomalies | mitigate | Strict settled minor-units + currency comparison; AnomalyFxInvariantTest | closed |
| T-09-08 | Elevation of Privilege | Evaluator writing transactions | mitigate | `noTransactionWritesFromAnomaly` invariant; `insertGetId` targets `anomaly_alerts` only | closed |
| T-09-09 | EoP / Information Disclosure | Cross-user alert/suppression access via crafted id | mitigate | `where('id')->where('user_id')` + NotFoundHttpException in every Public Action; AnomalyAlertCrossUser404Test | closed |
| T-09-10 | Tampering | Tampered snooze target widening the window | mitigate | Server-side `(now, now+6mo]` bound (SnoozeAnomalyAlert.php:62-68) | closed |
| T-09-11 | Tampering / Repudiation | Client-supplied suppression band hiding fraud | mitigate | Band `round(0.85x)`/`round(1.15x)` computed server-side from persisted alert, never payload (DismissAnomalyAlertAsExpected.php:44-47,155-161) | closed |
| T-09-12 | Repudiation | Suppression invisibly hides a future fraudulent charge | mitigate | Append-only `anomaly_alert_transitions` audit; rules listed + removable; narrow band re-fires | closed |
| T-09-13 | Tampering | SQL injection via merchant-name suppression matching | mitigate | Parameterized query builder only; no `whereRaw`/`DB::raw`/string interpolation (grep clean) | closed |
| T-09-14 | Denial of Service | Inline detection slowing imports | mitigate | Listener only QUEUES a unique `DetectAnomaliesJob`; no baseline math inline (EvaluateAnomaliesOnTransactionImport.php:35-41) | closed |
| T-09-15 | Denial of Service | Backfill flooding the queue / re-running years | mitigate | `ShouldBeUniqueUntilProcessing` (uniqueId=userId) + conditional `whereNull('anomaly_backfilled_at')->update(...)` claim BEFORE walk (WR-01 hardened, BackfillAnomaliesJob.php:114-123) + `lazyById(500)` | closed |
| T-09-16 | Information Disclosure | Cross-user evaluation in per-user sweep/backfill | mitigate | Explicit `where('user_id')` on backfill (line 126) + safety-net sweep (line 95); revival sweep global-by-design, only `snoozed→open` | closed |
| T-09-17 | Tampering | A revival writing state outside the state machine | mitigate | Revival transitions via `AnomalyAlertStateMachine` only; illegal source caught (InvalidStateTransitionException) | closed |
| T-09-18 | Elevation of Privilege | Cross-user anomaly action / suppression Remove via crafted id | mitigate | Public Actions throw NotFoundHttpException; user-scoped Remove; AnomalyAlertCrossUser404Test + AnomalySuppressionUndoTest | closed |
| T-09-19 | Tampering | Out-of-range sensitivity/floor or tampered snooze target | mitigate | Server-side `sensitivity ∈ [1,100]` + `floor ≥ 0` (AnomalySettingsSection.php:89-98); snooze bounds reused | closed |
| T-09-20 | Repudiation | Invisible suppression hiding fraud | mitigate | Settings lists every rule with Remove; undo re-opens via `dismissed→open`; append-only audit | closed |
| T-09-21 | Information Disclosure | Financial data in phone browser storage | mitigate | Server-rendered Livewire only; no `localStorage`/`sessionStorage`/`$persist`/`indexedDB` in any Anomaly view (grep clean) | closed |
| T-09-SC | Tampering | npm/pip/composer installs | accept | No new external runtime packages this phase (composer.json declares no `require` additions) | closed |

*Status: open · closed*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-09-SC | T-09-SC | Supply-chain: Phase 9 introduced zero new runtime dependencies (Anomaly module is pure first-party code; composer.json adds no `require` entries), so a package-legitimacy audit is N/A. | Wessel Verheij | 2026-06-14 |

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-06-14 | 22 | 22 | 0 | gsd-security-auditor (verify-mitigations mode, ASVS L2) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-06-14
