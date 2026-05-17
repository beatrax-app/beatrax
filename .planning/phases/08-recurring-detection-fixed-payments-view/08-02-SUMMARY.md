---
phase: 08-recurring-detection-fixed-payments-view
plan: "02"
subsystem: recurring-detection
tags: [recurring, migrations, state-machine, public-surface, settings, sqlite-triggers, livewire]
requires:
  - phase: 08-recurring-detection-fixed-payments-view
    provides:
      - "Modules/Recurring/ bounded module shell"
      - "Public/Internal split + RecurringServiceProvider"
      - "noOtherRecurringSeriesStateMutator + noSynchronousDetectionInRequestLifecycle + noTransactionWritesFromRecurring arch invariants"
provides:
  - "recurring_series / recurring_series_occurrences / recurring_series_transitions tables (DDL + SQLite state-validation triggers + idempotency UNIQUE)"
  - "users.recurring_detection_window_months + users.recurring_income_min_amount_minor (defaults 18 / 200000)"
  - "RecurringSeriesStateMachine sole-mutator class with ALLOWED_TRANSITIONS map, PRAGMA busy_timeout fence, lockForUpdate, atomic state+audit write"
  - "InvalidStateTransitionException sentinel"
  - "SeriesDetector Public interface (container tag 'recurring.detector')"
  - "RecurringSeriesDto + RecurringOccurrenceDto + NextExpectedChargeDto + RecurringSeriesAmountTrendDto Public DTOs"
  - "RecurringSeriesDetected / Approved / Rejected / CadenceFlipped Public events"
  - "/settings exposes recurring detection window + income minimum preferences with calm validation"
affects:
  - "Modules/Recurring/Internal/Detectors/* (Plan 03 — detectors that fan out under the 'recurring.detector' container tag and call RecurringSeriesStateMachine for state changes)"
  - "Modules/Recurring/Public/Actions/* (Plan 03 — review-surface actions that wrap RecurringSeriesStateMachine + dispatch the new Public events)"
  - "Modules/Recurring/Public/Services/RecurringSeriesQuery + FixedPaymentsViewQuery (Plans 03/04 — DTO-projection queries)"
  - "Modules/Recurring/Internal/Http/Livewire/RecurringPage + RecurringReviewPage + FixedPaymentsCard (Plans 04/05)"
  - "Phase 9 / Phase 10 listeners that subscribe to the four Public events"
tech-stack:
  added: []
  patterns:
    - "Recurring migrations follow the Phase-5 card_statements anonymous-class + BEFORE INSERT/UPDATE state trigger shape verbatim"
    - "RecurringSeriesStateMachine mirrors EmailScan/InboxScanStateMachine: DatabaseManager + Clock DI, ALLOWED_TRANSITIONS const, lockForUpdate + PRAGMA busy_timeout=5000, atomic state + transitions write"
    - "Public surface (interface + DTOs + events) uses Spatie\\LaravelData\\Data for DTOs, final readonly classes for events, and an interface-only contract that Internal services implement"
    - "Users-row default block via Eloquent $attributes mirrors the migration's column default so freshly-constructed User instances surface the same value the DB would apply on insert"
key-files:
  created:
    - "Modules/Recurring/Database/Migrations/2026_05_18_010001_create_recurring_series_table.php"
    - "Modules/Recurring/Database/Migrations/2026_05_18_010002_create_recurring_series_occurrences_table.php"
    - "Modules/Recurring/Database/Migrations/2026_05_18_010003_create_recurring_series_transitions_table.php"
    - "Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php"
    - "Modules/Recurring/Models/RecurringSeries.php"
    - "Modules/Recurring/Models/RecurringSeriesOccurrence.php"
    - "Modules/Recurring/Models/RecurringSeriesTransition.php"
    - "Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php"
    - "Modules/Recurring/Internal/StateMachines/InvalidStateTransitionException.php"
    - "Modules/Recurring/Public/Contracts/SeriesDetector.php"
    - "Modules/Recurring/Public/Dto/RecurringSeriesDto.php"
    - "Modules/Recurring/Public/Dto/RecurringOccurrenceDto.php"
    - "Modules/Recurring/Public/Dto/NextExpectedChargeDto.php"
    - "Modules/Recurring/Public/Dto/RecurringSeriesAmountTrendDto.php"
    - "Modules/Recurring/Public/Events/RecurringSeriesDetected.php"
    - "Modules/Recurring/Public/Events/RecurringSeriesApproved.php"
    - "Modules/Recurring/Public/Events/RecurringSeriesRejected.php"
    - "Modules/Recurring/Public/Events/RecurringSeriesCadenceFlipped.php"
    - "Modules/Recurring/tests/Feature/RecurringMigrationTest.php"
    - "Modules/Recurring/tests/Unit/RecurringSeriesStateMachineTest.php"
    - "Modules/Recurring/tests/Unit/RecurringSeriesDtoTest.php"
    - "Modules/Core/tests/Feature/SettingsRecurringFieldsTest.php"
  modified:
    - "Modules/Recurring/Providers/RecurringServiceProvider.php"
    - "Modules/Core/Models/User.php"
    - "Modules/Core/Internal/Http/Livewire/SettingsPage.php"
    - "Modules/Core/Resources/views/livewire/settings-page.blade.php"
key-decisions:
  - "Eloquent $attributes default block on User for the two new recurring-related columns — keeps mount() reading int 18 / 200000 for freshly-created users where Eloquent does not refresh DB-side defaults after insert"
  - "HasMany relations on RecurringSeries return the unsorted base relation; consumer code applies orderBy at the read site (the phpstan strict-rules ruleset flags chained ->orderBy() on hasMany() return as a dynamic call on a static method)"
patterns-established:
  - "Pattern: a sole-mutator state machine pairs PRAGMA busy_timeout=5000 with lockForUpdate so concurrent sweep jobs serialise instead of failing — same shape used in Phase 6 InboxScanStateMachine and Phase 5 CardStatementStateMachine"
  - "Pattern: schema-level BEFORE INSERT / BEFORE UPDATE OF state SQLite trigger pair backs the arch-test sole-mutator invariant so out-of-band UPDATEs are rejected at the database boundary"
  - "Pattern: ALLOWED_TRANSITIONS map excludes same-state targets (idempotent no-ops live in Public Actions, never in the state machine)"
  - "Pattern: Public-event payloads stay narrow — only ints for FK ids plus the minimum strings consumers need to narrate the change"

requirements-completed:
  - REC-03
  - LED-06

duration: ~75min
completed: 2026-05-17
---

# Phase 8 Plan 02: Recurring Schema, State Machine, Public Surface Summary

**Three recurring tables + two users columns + the sole-mutator state machine + a five-class Public surface land alongside calm validation on /settings, locking the schema and contracts before any detector code arrives in Plan 03.**

## Performance

- **Duration:** ~75 min
- **Tasks:** 4 / 4
- **Files created:** 22
- **Files modified:** 4
- **Tests added:** 4 files (RecurringMigrationTest 8 cases, RecurringSeriesStateMachineTest 19 cases incl. 11-row dataset, RecurringSeriesDtoTest 9 cases, SettingsRecurringFieldsTest 7 cases) — 43 new test cases total

## Accomplishments

- Three new schema tables exist after `php artisan migrate`:
  - `recurring_series` with the documented BIGINT minor-unit columns, the `(direction, cluster_key, latest_currency)` UNIQUE, the `(user_id, state)` + `(user_id, state, next_expected_at)` read indexes, and the BEFORE INSERT / BEFORE UPDATE OF state trigger pair rejecting any state outside `{pending, approved, rejected, snoozed, cadence_changed}`.
  - `recurring_series_occurrences` with the UNIQUE(series, transaction) idempotency seam + the (series, observed_at) drill-in index.
  - `recurring_series_transitions` as the append-only audit ledger with the (series, transitioned_at) history index.
- Two new columns on `users` — `recurring_detection_window_months` (default 18) and `recurring_income_min_amount_minor` (default 200000) — verified to default correctly when no value is supplied at insert time.
- `RecurringSeriesStateMachine` is the sole legal mutator of `recurring_series.state`. Every transition opens a DatabaseManager transaction, sets `PRAGMA busy_timeout = 5000`, takes a `lockForUpdate()` row lock, validates against `ALLOWED_TRANSITIONS`, and atomically writes the new state + one `recurring_series_transitions` audit row. Singleton-bound via `RecurringServiceProvider::register()`.
- Public surface — one interface, four DTOs, four events — locked for Plans 03/04/05 to build against.
- `/settings` surfaces both new preferences with calm validation; out-of-range submits leave the users row untouched and return the locked single-sentence error message.

## Task Commits

1. **Task 1 — migrations + Eloquent models (TDD)**
   - `10d64f4` (test) — RED: 8 migration + model feature tests
   - `479a4b2` (feat) — GREEN: 4 migrations + 3 Eloquent models
2. **Task 2 — RecurringSeriesStateMachine (TDD)**
   - `a52240f` (test) — RED: 19 state-machine + schema-trigger backstop tests
   - `ce44bb5` (feat) — GREEN: state machine + InvalidStateTransitionException + provider singleton binding
3. **Task 3 — Public contract + DTOs + events (TDD)**
   - `a58bd81` (test) — RED: 9 Public-surface DTO + event + contract tests
   - `97d218d` (feat) — GREEN: SeriesDetector interface, 4 DTOs, 4 events
4. **Task 4 — /settings recurring-preference fields (TDD)**
   - `e5f5853` (test) — RED: 7 SettingsRecurringFieldsTest slices
   - `1dcd29e` (feat) — GREEN: User fillable + casts + $attributes defaults, SettingsPage Validate attributes + mount/save/messages extensions, Blade "Recurring detection" section

## Files Created / Modified

See `key-files.created` + `key-files.modified` in frontmatter. Two notable category mentions:

- **Migrations:** `2026_05_18_010001` through `2026_05_18_010004`. Ordering vis-à-vis Phase 6/7 migrations on `users`: the `_010004_add_recurring_settings_to_users.php` migration places its two new columns `after('auto_import_drop_folder')`, which is the most recent prior users-row column added in Phase 7 (`2026_05_17_010007`).
- **Service provider:** `Modules/Recurring/Providers/RecurringServiceProvider.php` now binds one singleton — `RecurringSeriesStateMachine::class`. Detectors, queries, and actions extend this list in Plan 03.

## SQLite Trigger Verification

The `recurring_series_state_check_insert` and `recurring_series_state_check_update` triggers fire as expected. The migration test `it rejects an invalid recurring_series.state value via the SQLite trigger` captures the literal QueryException message string:

```
SQLSTATE[HY000]: General error: 19 Invalid recurring_series.state value
```

The `it refuses a direct Eloquent update that bypasses the state machine — schema trigger fires` unit test confirms the same behaviour when bypassing the state machine via raw Eloquent.

## /settings Round-Trip Confirmation

`it round-trips both new fields into the users row in a single save` captures the dual-field write contract. Saved row after the assertion (per the test body):

```
recurring_detection_window_months = 36
recurring_income_min_amount_minor = 250000
```

The unchanged-row guarantee fires on every reject path: setting `recurringDetectionWindowMonths` to 2 (below the lower bound) or 61 (above) leaves `users.recurring_detection_window_months` at its prior value, and setting `recurringIncomeMinAmountMinor` to -1 leaves `users.recurring_income_min_amount_minor` at its prior value.

## Decisions Made

- **`$attributes` default block on User.** Eloquent does not refresh server-side default values after `User::create([...])`, so a freshly-created User instance had its two new recurring fields at 0 in memory while the DB row held the schema defaults. The cleanest fix — and the one that keeps `mount()` consumers free of null-coalescing — is to mirror the migration defaults on the model via `protected $attributes`. This stays consistent with the schema and applies to both freshly-constructed and DB-fetched instances.
- **No inline `->orderBy()` on HasMany relations.** Larastan strict mode flags chained `->orderBy(...)` on the return of `hasMany(...)` as a `staticMethod.dynamicCall`. The relations on `RecurringSeries` (`transitions()`, `occurrences()`) return the unsorted relation; the read-site queries apply ordering directly.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Worktree environment bootstrap**
- **Found during:** Pre-task-1 baseline run
- **Issue:** Freshly-spawned worktree had no `vendor/`, no `node_modules`, no `database/database.sqlite`, no `.env`, and no Vite manifest. Test runs failed because every Blade view referencing `@vite` could not resolve a manifest, and dotenv warnings produced noisy "missing .env" output.
- **Fix:** `composer install`, `npm install`, `touch database/database.sqlite`, `npm run build`, `cp .env.example .env && php artisan key:generate`. None of these touched repo state.
- **Files modified:** None (vendor + node_modules + sqlite + .env are gitignored).
- **Commit:** N/A — environment setup.

**2. [Rule 1 — Bug] Transaction insert in migration feature test was missing required columns**
- **Found during:** Task 1 GREEN
- **Issue:** The first draft of `RecurringMigrationTest` constructed Transaction rows with the canonical detector-shaped keys (`counterparty_raw`, `description`, `type`), but the actual `transactions` schema requires `value_date`, `settled_amount_minor`, `settled_currency`, `normalization_version`, `source_format`, `source_row_index`, `fingerprint_version`, and uses `counterparty_name` (not `counterparty_raw`). Builds failed with cascading NOT-NULL constraint violations.
- **Fix:** Aligned the Transaction inserts in the test with the column shape the schema actually requires (matches the pattern used in `Modules/Chains/tests/Contracts/ChainResolutionIdempotencyTest`).
- **Files modified:** `Modules/Recurring/tests/Feature/RecurringMigrationTest.php`
- **Commit:** `479a4b2` (part of the Task 1 GREEN commit)

**3. [Rule 1 — Bug] User defaults not pulled back into Eloquent after create**
- **Found during:** Task 4 GREEN
- **Issue:** `User::create([...])` in `SettingsRecurringFieldsTest`'s `beforeEach` writes the row without the two new columns. The DB applies the migration defaults (18 / 200000) but Eloquent does not refresh those values, so `mount()` reads `0` for both fields. Subsequent partial-save tests (one field set) then failed validation on the other field because `recurringDetectionWindowMonths=0` is below the `min:3` bound.
- **Fix:** Added a `protected $attributes` block on the `User` model mirroring the migration defaults so freshly-constructed instances surface the same values the DB would apply on insert.
- **Files modified:** `Modules/Core/Models/User.php`
- **Commit:** `1dcd29e` (part of the Task 4 GREEN commit)

**4. [Rule 1 — Bug] Useless `(int)` cast flagged by Larastan strict**
- **Found during:** Task 4 GREEN
- **Issue:** `(int) $user->recurring_detection_window_months` flagged as `cast.useless` because the User PHPDoc declares the property as `int`.
- **Fix:** Removed the redundant casts in `SettingsPage::mount` for the two new fields.
- **Files modified:** `Modules/Core/Internal/Http/Livewire/SettingsPage.php`
- **Commit:** `1dcd29e`

**5. [Rule 1 — Bug] PHPDoc shape narrower than parent property**
- **Found during:** Task 4 GREEN
- **Issue:** `@var array<string, int>` on `$attributes` was flagged because the parent `Illuminate\Database\Eloquent\Model::$attributes` declares `array<string, mixed>`.
- **Fix:** Widened the PHPDoc to `array<string, mixed>`.
- **Files modified:** `Modules/Core/Models/User.php`
- **Commit:** `1dcd29e`

---

**Total deviations:** 5 (4 auto-fixed Rule-1 bugs + 1 Rule-3 environment bootstrap). No Rule-4 architectural deviations.
**Impact on plan:** All five auto-fixes are correctness-required (schema alignment, model-instance state, static-analysis clean). No scope creep.

## Issues Encountered

None beyond the deviations above. The plan's pattern matches (cardinal of `card_statements` migration shape, `InboxScanStateMachine` for the state machine, `ChainLinkRow` for DTOs, `ChainHintDetected` for events, `SettingsPageTest` for the Livewire feature test) transferred cleanly with the column-name + field-shape corrections above.

## Verification

| Gate | Result |
| ---- | ------ |
| `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringMigrationTest.php` | 8 passed (23 assertions) |
| `vendor/bin/pest Modules/Recurring/tests/Unit/RecurringSeriesStateMachineTest.php` | 19 passed (30 assertions, 11-row ALLOWED_TRANSITIONS dataset) |
| `vendor/bin/pest Modules/Recurring/tests/Unit/RecurringSeriesDtoTest.php` | 9 passed (37 assertions) |
| `vendor/bin/pest Modules/Core/tests/Feature/SettingsRecurringFieldsTest.php` | 7 passed (25 assertions) |
| `vendor/bin/pest Modules/Core/tests/Feature/SettingsPageTest.php` | 11 passed (39 assertions) — no regression |
| `vendor/bin/pest Modules/Recurring/tests Modules/Core/tests tests/Contracts` | 118 passed, 1 skipped (the Plan 01 contract-test scaffold) |
| `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` | 24 passed (49 assertions) — `noOtherRecurringSeriesStateMutator` + `noSynchronousDetectionInRequestLifecycle` now scan against real code |
| `composer analyse` (Larastan level max + strict + livewire) | OK — no errors |
| `composer format:check` (Pint default Laravel preset) | passed |
| `composer test` (parallel, full suite) | 1252 passed, 7 skipped, 3 notices, 5 failed — all 5 failures are documented baseline EmailScan worktree-environment failures (see `deferred-items.md`); zero new failures introduced by this plan |
| `grep -c 'BelongsToUser' Modules/Recurring/Models/RecurringSeries.php` | 2 (FND-03 compliance) |
| `grep -c 'recurringDetectionWindowMonths' Modules/Core/Internal/Http/Livewire/SettingsPage.php` | 8 (Validate attribute, two mount lines, two save lines, four message keys) |
| `grep -c 'recurring_series_state_check_insert' Modules/Recurring/Database/Migrations/2026_05_18_010001_create_recurring_series_table.php` | 2 (CREATE + DROP) |
| `grep -c 'rec_series_uniq' Modules/Recurring/Database/Migrations/2026_05_18_010001_create_recurring_series_table.php` | 1 |
| `grep -c 'PRAGMA busy_timeout' Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` | 3 |
| `grep -c 'lockForUpdate' Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` | 2 |
| `grep -c 'ALLOWED_TRANSITIONS' Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` | 3 |

Baseline-failure comparison (per `.planning/phases/08-recurring-detection-fixed-payments-view/deferred-items.md`): the documented pre-existing EmailScan worktree-environment failures remain pre-existing. Sequential `vendor/bin/pest` runs of `TransactionDetailReclassifyTest > crossUser404` pass cleanly on this worktree (1 passed, 3 assertions); the failure documented on commit `41360a0` does not reproduce on the current base `b283f27`.

## Threat Flags

None. The new attack surface — the two SQLite triggers, the sole-mutator state machine, the Public surface, and the two new /settings preferences — is fully covered by the threat register entries T-08-05 through T-08-09b in the plan body:

- T-08-05 / T-08-06: Mitigated by the two-layer defence (arch test + schema triggers + append-only audit table).
- T-08-07: Mitigated — the state machine signature requires `$reason` and `$actor` (no overload accepts null); the unit test covers the contract.
- T-08-08: Mitigated — `lockForUpdate()` + `PRAGMA busy_timeout=5000` serialise concurrent transitions on the same series.
- T-08-09: Mitigated — `BelongsToUser` concern on all three models; `user_id` is carried on the row directly so cross-user reads are single-table queries.
- T-08-09b: Mitigated — `#[Validate]` enforces server-side bounds; out-of-range submits leave the row unchanged. `CurrentUser` resolves the authenticated user — never a request-supplied id — so cross-user writes are structurally impossible.

## Known Stubs

None. Every artifact this plan ships is fully populated and load-bearing for Plan 03 (the detector wave) and the Phase 9 / Phase 10 listeners.

## Next Phase Readiness

Plan 03 (Wave 2 — detectors + cadence inferrer + queued sweep job) can build directly against:

- The locked column shape on the three new tables (no migration churn in flight).
- The SQLite trigger backstop so a detector bug that tries to write an invalid state value fails loudly at the DB boundary.
- The `RecurringSeriesStateMachine` singleton ready for detector injection.
- The `SeriesDetector` Public contract for the new detector classes to implement, and the `'recurring.detector'` container tag the sweep job will iterate.
- The four Public events available for Phase 9 / Phase 10 listener registration.
- The two new /settings preferences (`recurring_detection_window_months`, `recurring_income_min_amount_minor`) ready for the detector to read off the User row.

No blockers carried forward.

## Self-Check: PASSED

Verified file existence + commit hashes (each via `[ -f path ] && echo FOUND || echo MISSING` and `git log --all | grep -q hash`):

- Modules/Recurring/Database/Migrations/2026_05_18_010001_create_recurring_series_table.php — FOUND
- Modules/Recurring/Database/Migrations/2026_05_18_010002_create_recurring_series_occurrences_table.php — FOUND
- Modules/Recurring/Database/Migrations/2026_05_18_010003_create_recurring_series_transitions_table.php — FOUND
- Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php — FOUND
- Modules/Recurring/Models/RecurringSeries.php — FOUND
- Modules/Recurring/Models/RecurringSeriesOccurrence.php — FOUND
- Modules/Recurring/Models/RecurringSeriesTransition.php — FOUND
- Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php — FOUND
- Modules/Recurring/Internal/StateMachines/InvalidStateTransitionException.php — FOUND
- Modules/Recurring/Public/Contracts/SeriesDetector.php — FOUND
- Modules/Recurring/Public/Dto/RecurringSeriesDto.php — FOUND
- Modules/Recurring/Public/Dto/RecurringOccurrenceDto.php — FOUND
- Modules/Recurring/Public/Dto/NextExpectedChargeDto.php — FOUND
- Modules/Recurring/Public/Dto/RecurringSeriesAmountTrendDto.php — FOUND
- Modules/Recurring/Public/Events/RecurringSeriesDetected.php — FOUND
- Modules/Recurring/Public/Events/RecurringSeriesApproved.php — FOUND
- Modules/Recurring/Public/Events/RecurringSeriesRejected.php — FOUND
- Modules/Recurring/Public/Events/RecurringSeriesCadenceFlipped.php — FOUND
- Modules/Recurring/tests/Feature/RecurringMigrationTest.php — FOUND
- Modules/Recurring/tests/Unit/RecurringSeriesStateMachineTest.php — FOUND
- Modules/Recurring/tests/Unit/RecurringSeriesDtoTest.php — FOUND
- Modules/Core/tests/Feature/SettingsRecurringFieldsTest.php — FOUND
- Commit 10d64f4 (Task 1 RED) — FOUND
- Commit 479a4b2 (Task 1 GREEN) — FOUND
- Commit a52240f (Task 2 RED) — FOUND
- Commit ce44bb5 (Task 2 GREEN) — FOUND
- Commit a58bd81 (Task 3 RED) — FOUND
- Commit 97d218d (Task 3 GREEN) — FOUND
- Commit e5f5853 (Task 4 RED) — FOUND
- Commit 1dcd29e (Task 4 GREEN) — FOUND

---
*Phase: 08-recurring-detection-fixed-payments-view*
*Plan: 02*
*Completed: 2026-05-17*
