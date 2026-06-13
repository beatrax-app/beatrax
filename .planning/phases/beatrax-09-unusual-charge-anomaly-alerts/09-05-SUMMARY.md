---
phase: 09-unusual-charge-anomaly-alerts
plan: 05
subsystem: ui
tags: [anomaly, livewire, drift-page, type-switch, dashboard-tile, sidebar-badge, settings, suppression-rules, backfill, calm-slate, flux, pest]

# Dependency graph
requires:
  - phase: 09-03 (Anomaly read/write Public surface)
    provides: "AnomalyAlertQuery (open/history/dismissed + openCountForUser + openDetectorBreakdownForUser), AnomalyAlertDto (reasons[] + Money baseline/latest, no annualized), AnomalySuppressionRuleQuery::forUser, five Public Actions (acknowledge/snooze/dismiss/dismiss-as-expected/remove-rule) — all consumed by the UI"
  - phase: 09-04 (Anomaly detection orchestration)
    provides: "BackfillAnomaliesJob (userId-unique, anomaly_backfilled_at wholesale guard) — dispatched on first settings activation (D-13)"
  - phase: 05-recurring-drift-alerts (DriftAlerts module)
    provides: "DriftPage (#[Url(as:'tab')] tab machinery + method-param DI), drift-page.blade / drift-alert-row.blade / dashboard-drift-badge — cloned + re-shaped for anomalies; the registerTopNavBadgeComposer ViewFactoryContract pattern"
  - phase: 16-developer-mode (Core sidebar)
    provides: "core::livewire.app-sidebar + NavCountsService navCounts map — the live nav surface the anomaly badge merges into"
provides:
  - "/drift type switch (#[Url(as:'type', except:'drift')]) owned by DriftAlerts, consuming Anomaly Public AnomalyAlertQuery + five Actions — drift|anomaly each with full Open/History/Dismissed lifecycle (D-02)"
  - "anomaly-alert-row partial: reason micro-chips (large/first-time/duplicate, color-coded), baseline->actual sub-line, no per-year column; phone <details> action disclosure with ≥44px stacked chips"
  - "DashboardAnomalyBadge tile (open count + detector breakdown, hidden at zero) + amber sidebar 'Unusual charges' nav badge merged into navCounts['anomaly'] via a ViewFactoryContract composer (D-03)"
  - "AnomalySettingsSection: sensitivity + min-floor (server-validated [1,100]/>=0), first-activation BackfillAnomaliesJob dispatch, visible+removable suppression-rules list (D-11/D-18)"
affects: [phase-09-verification, future-anomaly-surfaces]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Cross-module Public composition in a Livewire page: DriftPage (owned by DriftAlerts) consumes Modules\\Anomaly\\Public\\Services\\AnomalyAlertQuery + Public Actions via method-param DI — no Internal crossing, mirroring how /drift already consumes Recurring's series query"
    - "Two-level alerts navigation: a segmented type switch (#[Url type]) above the existing lifecycle tabs (#[Url tab]); both reset the cursor, type defaults to 'drift' to preserve bookmarks"
    - "Nav badge composer merges a revival-aware count into the existing sidebar navCounts map (rather than the dead core::livewire.top-nav), keeping navCounts['anomaly'] === AnomalyAlertQuery::openCountForUser"
    - "Settings first-activation dispatch: read anomaly_backfilled_at BEFORE the write; dispatch BackfillAnomaliesJob only when null — the job stamps the column on completion so subsequent saves never re-dispatch"
    - "Phone action chips: a per-row <details summary='Actions'> disclosure swaps in for the inline chip row below 640px, full-width min-h-[44px] touch targets"

key-files:
  created:
    - Modules/Anomaly/Internal/Http/Livewire/DashboardAnomalyBadge.php
    - Modules/Anomaly/Internal/Http/Livewire/AnomalySettingsSection.php
    - Modules/Anomaly/Resources/views/livewire/dashboard-anomaly-badge.blade.php
    - Modules/Anomaly/Resources/views/livewire/anomaly-settings-section.blade.php
    - Modules/Anomaly/Resources/views/livewire/partials/anomaly-alert-row.blade.php
    - Modules/Anomaly/Resources/views/livewire/partials/anomaly-action-chips.blade.php
    - Modules/Anomaly/tests/Feature/AnomalyAlertsHomeTest.php
    - Modules/Anomaly/tests/Feature/DashboardAnomalyBadgeTest.php
    - Modules/Anomaly/tests/Feature/TopNavAnomalyBadgeTest.php
    - Modules/Anomaly/tests/Feature/AnomalySettingsSectionTest.php
  modified:
    - Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php
    - Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php
    - Modules/DriftAlerts/tests/Feature/DriftPageTest.php
    - Modules/Anomaly/Providers/AnomalyServiceProvider.php
    - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
    - Modules/Core/Resources/views/livewire/dashboard.blade.php
    - Modules/Core/Resources/views/livewire/settings-page.blade.php

key-decisions:
  - "Nav badge wired through the sidebar's navCounts map, not a top-nav composer: the drift analog composes core::livewire.top-nav, which Phase 16 replaced with core::livewire.app-sidebar. The anomaly composer therefore composes app-sidebar and MERGES navCounts['anomaly'] = openCountForUser (revival-aware) into the existing map, so the live sidebar badge actually renders and stays exactly equal to the Public count."
  - "Repurposed the /drift page heading from 'Drift alerts' to 'Alerts' (D-02 unified home). The DriftPageTest assertion was updated to match; drift rows/empty-states are otherwise unchanged."
  - "First-activation backfill keys on anomaly_backfilled_at being null at save time (there is no on/off boolean — detection is always on; saving sensitivity/floor IS the activation). The userId-unique job + wholesale guard make a duplicate dispatch a no-op."
  - "Suppression-rule cross-user assertion in tests reads via a raw DB query: the BelongsToUser UserScope hides another user's row from the acting user's Eloquent query, which would mask a true deletion."

patterns-established:
  - "anomaly-action-chips is a shared partial rendered twice per row (inline on >=640px, stacked inside the phone <details>) so the four chips + snooze popover have a single source of truth"
  - "Reason chips are read-only decorations on the headline: first_time=blue, duplicate=amber, large/other=direction-aware tint, each with an aria-label='Reason: ...'"

requirements-completed: [ANOM-02]

# Metrics
duration: ~70 min
completed: 2026-06-13
---

# Phase 9 Plan 05: Anomaly UI Surface Summary

**The `/drift` page becomes the unified alerts home: a segmented type switch (Subscription drift | Unusual charges) owned by DriftAlerts but consuming Anomaly's Public `AnomalyAlertQuery` + five Actions, anomaly rows with color-coded reason chips and a baseline→actual sub-line (no per-year column), a distinct dashboard "Unusual charges" tile + amber sidebar badge merged into `navCounts['anomaly']` (revival-aware) via a ViewFactoryContract composer, and a Settings "Anomaly detection" section (server-validated sensitivity + floor, first-activation `BackfillAnomaliesJob` dispatch, and the visible + removable suppression-rules list) — completing ANOM-02.**

## Performance
- **Duration:** ~70 min
- **Completed:** 2026-06-13
- **Tasks:** 3 autonomous build tasks (the 4th is a human-verify checkpoint, still open)
- **Files modified:** 17 (10 created, 7 modified)

## Accomplishments
- **Task 1 — /drift type switch + anomaly row (`f27a06e`):** `DriftPage` gains `#[Url(as:'type', except:'drift')]` + `setType()` and five anomaly action methods (`acknowledgeAnomaly`/`snoozeAnomaly`/`dismissAnomaly`/`markAnomalyExpected`/`undoAnomalySuppression`), each injecting the matching Anomaly Public Action as a method param (no `Modules\Anomaly\Internal` import — grep-verified 0). `render()` branches on `type`, reading open/history/dismissed from `AnomalyAlertQuery`. `anomaly-alert-row.blade` clones the drift row with reason micro-chips + a `baseline {x} → actual: {y} · detected {date} · sensitivity ±{N}%` sub-line and NO `/yr` column; `anomaly-action-chips.blade` renders the four chips (Acknowledge emerald-primary when single-reason) inline on desktop and inside a per-row `<details>` disclosure (≥44px stacked) on phone. The page heading is now "Alerts" with the segmented type switch above the lifecycle tabs.
- **Task 2 — dashboard tile + amber nav badge (`200f641`):** `DashboardAnomalyBadge` renders the open count + detector breakdown ("{N} open · {L} large · {F} first-time · {D} duplicate", non-zero only), hidden at zero, linking `?type=anomaly`. The provider registers both Livewire components and a `core::livewire.app-sidebar` composer (ViewFactoryContract, boot-scoped memo) that merges the revival-aware `openCountForUser` into `navCounts['anomaly']`; the sidebar shows the amber `.side-badge.alert` "Unusual charges" item when > 0.
- **Task 3 — Settings section + first-activation backfill (`cf2324e`):** `AnomalySettingsSection` loads + persists `anomaly_sensitivity_percent` and `anomaly_min_amount_minor` with server-side bounds (sensitivity ∈ [1,100], floor ≥ 0; T-09-19), dispatches `BackfillAnomaliesJob` once on the first save while `anomaly_backfilled_at` is null (D-13), and renders the collapsible suppression-rules list (`AnomalySuppressionRuleQuery::forUser`) with a per-row `Remove` calling `RemoveAnomalySuppressionRule::removeRule` (cross-user no-op). Mounted on `/settings#anomaly-detection` after the Drift threshold section.
- All gates green on touched files: 213 Pest in the Anomaly suite + BoundaryArchTest, 165 in DriftAlerts (3 pre-existing todos), 27 across the Core sidebar/settings/dashboard render tests; Pint clean; PHPStan L10 strict clean on the four touched source files.

## Task Commits
1. **Task 1: /drift type switch + anomaly alert row** — `f27a06e` (feat)
2. **Task 2: dashboard anomaly tile + amber sidebar nav badge** — `200f641` (feat)
3. **Task 3: Settings "Anomaly detection" section + first-activation backfill** — `cf2324e` (feat)

**Plan metadata:** _(this commit)_ (docs: complete plan)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Nav badge wired through the sidebar's navCounts map, not a top-nav composer**
- **Found during:** Task 2
- **Issue:** The plan's Task 2 says to clone `registerTopNavBadgeComposer`, which composes `core::livewire.top-nav` — but Phase 16 replaced the top-nav with `core::livewire.app-sidebar` (the drift TopNavDriftBadgeTest cases are marked `todo` for exactly this reason). A verbatim clone would compose a dead view and the sidebar badge would never render.
- **Fix:** The Anomaly composer composes `core::livewire.app-sidebar` and MERGES `navCounts['anomaly'] = AnomalyAlertQuery::openCountForUser($user)` into the existing `navCounts` map the `AppSidebar` component already provides. This keeps the plan's contract (ViewFactoryContract, never `view()`; `navCounts['anomaly']` === `openCountForUser`; amber `.side-badge.alert` > 0) while targeting the live nav surface.
- **Files modified:** AnomalyServiceProvider.php, app-sidebar.blade.php
- **Verification:** TopNavAnomalyBadgeTest asserts the rendered sidebar carries `{N} open unusual charges` + `side-badge alert`, and that the count equals `openCountForUser` (revival-aware: a snoozed-but-expired alert counts, a still-snoozed one does not).

**2. [Rule 1 - Bug] `use` statement inside a conditional `@php` block**
- **Found during:** Task 1 (first render of `?type=anomaly`)
- **Issue:** The anomaly branch's `@php` block opened with `use Modules\Ledger\...\Money;`, but a PHP `use` import cannot live inside an `if (...) { }` body — Blade compiled the branch under the `@if ($type==='anomaly')`, raising a parse error.
- **Fix:** Removed the `use` from the nested block; `Money` is already imported by the unconditional top-of-file `@php` block, so it is in scope.
- **Files modified:** drift-page.blade.php
- **Verification:** AnomalyAlertsHomeTest renders `?type=anomaly` and asserts the rows + chips.

**3. [Rule 1 - Bug] DriftPageTest heading assertion + cross-user suppression assertion**
- **Found during:** Task 1 / Task 3
- **Issue:** (a) The `/drift` heading changed to "Alerts" (D-02), breaking `assertSeeText('Drift alerts')`. (b) The cross-user suppression-remove assertion read through Eloquent, but the `BelongsToUser` UserScope hides the other user's row from the acting user, so the count read 0 regardless of whether the row survived.
- **Fix:** Updated the drift test assertion to "Alerts"; switched the cross-user survival check to a raw `DatabaseManager` query that bypasses the global scope.
- **Files modified:** DriftPageTest.php, AnomalySettingsSectionTest.php
- **Verification:** Both suites green.

**Total deviations:** 3 auto-fixed (1 blocking architecture-fit, 2 bugs). None changed plan scope; deviation 1 is the one production change of substance and is the correct binding of the nav badge to the post-Phase-16 sidebar.

## Issues Encountered
- PHPStan was scoped to the touched paths with `php -d memory_limit=3G ./vendor/bin/phpstan analyse <files>` per the project memory note about host fd/memory limits on whole-repo runs.

## Known Stubs
None — every surface is wired against the real Public Query/Actions and the live `navCounts` map. The anomaly rows, tile, badge, and settings list all read real data; the first-activation toggle dispatches the real `BackfillAnomaliesJob`.

## Threat Flags
None — no new network endpoint, auth path, or trust-boundary schema change beyond the plan's threat model. The prescribed mitigations are applied: T-09-18 cross-user actions throw `NotFoundHttpException` (Plan 03) and the settings `Remove` is user-scoped; T-09-19 sensitivity/floor are server-validated and the snooze keeps the (now, now+6mo] bound; T-09-20 every suppression rule is listed with a `Remove` + the post-dismiss "Undo" re-opens the anomaly; T-09-21 the surface is server-rendered Livewire with no client persistence of alert data.

## User Setup Required
None — but the dev DB was manually migrated (`php artisan migrate`, DB_CONNECTION=sqlite) so the four 09-01 anomaly migrations + the users settings columns are present for the browser UAT (project memory: the dev DB does not auto-migrate). A queue worker + scheduler should be running so detection/backfill jobs process during UAT.

## Next Phase Readiness
- The anomaly UI is complete and gated; the only remaining step is the human-verify checkpoint (Task 4), which the orchestrator owns. After UAT approval, Phase 9 verification + the phase-finish review can run.

## Self-Check: PASSED
- All 10 created files verified present on disk.
- Task commits `f27a06e`, `200f641`, `cf2324e` present in `git log`.
- Plan verification re-run: `./vendor/bin/pest Modules/Anomaly tests/Contracts/BoundaryArchTest.php` → 213 passed; DriftAlerts 165 passed; Core sidebar/settings/dashboard render tests 27 passed. Pint clean; PHPStan L10 strict clean on the four touched source files. Dev DB migrated.

---
*Phase: 09-unusual-charge-anomaly-alerts*
*Completed: 2026-06-13 (build tasks; human-verify checkpoint pending)*
