---
phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
plan: 02
subsystem: database
tags: [chain-links, card-statements, eloquent, sqlite-triggers, state-machine, dto, laravel-data]

# Dependency graph
requires:
  - phase: 01-foundation
    provides: BelongsToUser trait + transactions table + accounts table + Eloquent base shapes
  - phase: 03-ics-cards-multi-currency-display
    provides: statement_summaries table + ICS PDF adapter that populates closing_balance_minor with the negative-sign convention
  - phase: 04-paypal-ingestion-transfer-detection
    provides: pair_transaction_id self-FK on transactions; canonical memoised $resolvedDb migration pattern
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    provides: Modules/Chains skeleton + ChainsServiceProvider + BoundaryArchTest invariants (D-84 / D-95) + fixture trio
provides:
  - chain_links table (kind/state/to_transaction_id triple trigger pair) + chain_links Eloquent model with array-cast evidence
  - card_statements table (state trigger pair + UNIQUE on user_id/account_id/period_start/period_end) + CardStatement Eloquent model
  - card_statement_credits table (reason trigger pair + nullOnDelete to_statement_id) + CardStatementCredit Eloquent model
  - chain_resolution_runs audit table (status trigger pair + non-nullable user_id) + ChainResolutionRun Eloquent model
  - One-shot back-population migration from statement_summaries (ICS-kind only, idempotent via insertOrIgnore)
  - 5 Public DTOs (ChainTree, ChainTreeNode, ChainLinkRow, CardStatementForecastTile, StatementSettlement) as final readonly Spatie\LaravelData\Data classes
  - CardStatementStateMachine — the single legal mutator of card_statements.state, singleton-bound, transaction-wrapped with PRAGMA busy_timeout
  - 19 schema-level tests + 3 back-population tests + 4 state-machine tests + JSON-contains smoke test
affects: [05-03, 05-04, 05-05, 05-05b]

# Tech tracking
tech-stack:
  added: []  # No new composer packages — the wave is pure schema + model code over the existing stack
  patterns:
    - "Trigger-pair enum CHECKs on Phase 5 tables mirror Phase 1 transactions.type (BEFORE INSERT + BEFORE UPDATE OF column for kind/state/reason/status; BEFORE INSERT + BEFORE UPDATE for the conditional-NULL invariant on chain_links.to_transaction_id)"
    - "Schema-level conditional-NULL enforcement: chain_links.to_transaction_id may only be NULL when state=candidate AND kind=ics_bulk_settle AND json_extract(evidence, '$.tolerance_used')='exceeded' — uses SQLite JSON1's json_extract inside a BEFORE-trigger guard"
    - "DataManager-direct read-then-write inside transaction() with PRAGMA busy_timeout=5000 — Phase 5 SQLite-locking idiom for state-machine mutators where lockForUpdate is a no-op"
    - "Migration-invocation pattern in test: require() the anonymous-class file and call up() directly when re-running idempotency is needed (Artisan::call('migrate') is a no-op once the file is recorded in the migrations table)"
    - "Spatie LaravelData Public DTOs: final classes extending \\Spatie\\LaravelData\\Data with public readonly properties — same shape Phase 3's PerCurrencyTile + DashboardSummary use"

key-files:
  created:
    - Modules/Chains/Database/Migrations/2026_05_16_010001_create_chain_links_table.php
    - Modules/Chains/Database/Migrations/2026_05_16_010002_create_card_statements_table.php
    - Modules/Chains/Database/Migrations/2026_05_16_010003_create_card_statement_credits_table.php
    - Modules/Chains/Database/Migrations/2026_05_16_010004_backpopulate_card_statements_from_statement_summaries.php
    - Modules/Chains/Database/Migrations/2026_05_16_010005_create_chain_resolution_runs_table.php
    - Modules/Chains/Models/ChainLink.php
    - Modules/Chains/Models/CardStatement.php
    - Modules/Chains/Models/CardStatementCredit.php
    - Modules/Chains/Models/ChainResolutionRun.php
    - Modules/Chains/Public/Dto/ChainTree.php
    - Modules/Chains/Public/Dto/ChainTreeNode.php
    - Modules/Chains/Public/Dto/ChainLinkRow.php
    - Modules/Chains/Public/Dto/CardStatementForecastTile.php
    - Modules/Chains/Public/Dto/StatementSettlement.php
    - Modules/Chains/Internal/CardStatementStateMachine.php
    - Modules/Chains/tests/Feature/ChainLinksSchemaTest.php
    - Modules/Chains/tests/Feature/ChainResolutionRunsSchemaTest.php
    - Modules/Chains/tests/Feature/ChainLinksJsonContainsSmokeTest.php
    - Modules/Chains/tests/Feature/CardStatementBackPopulationTest.php
    - Modules/Chains/tests/Unit/CardStatementStateMachineTest.php
  modified:
    - Modules/Chains/Providers/ChainsServiceProvider.php

key-decisions:
  - "chain_links.to_transaction_id NULL semantics enforced at the schema layer via a third trigger pair using SQLite JSON1's json_extract — replaces the resolver-side arbitrary first-expense workaround the original RESEARCH proposed; loud, structural, and identical regardless of the write path (Eloquent or raw insertOrIgnore)"
  - "Back-population sign convention locked at the write site: total_amount_minor preserves the negative closing_balance_minor from statement_summaries verbatim; open_balance_minor is the absolute value (positive remaining to settle). Test asserts both columns against a known seeded statement_summary (-84732 / 84732)"
  - "card_statements.state mutator is the singleton-bound CardStatementStateMachine — D-95 invariant; BoundaryArchTest noOtherCardStatementStateMutator enforces single-mutator from the production code surface"
  - "CardStatementStateMachine wraps the read-then-write in $db->connection()->transaction() with PRAGMA busy_timeout = 5000; SQLite's lockForUpdate is a no-op so the busy_timeout pragma is the load-bearing concurrency fence. Cross-user statement miss raises RuntimeException so partial writes never leak through the transaction frame"
  - "chain_resolution_runs.user_id is NON-NULLABLE (mirrors import_runs) — the BelongsToUser invariant still applies but the safer non-nullable shape eliminates a class of NULL-distinct-in-UNIQUE bugs at the schema layer"
  - "ChainLink.confidence intentionally left without an explicit cast — SQLite decimal columns return numeric strings from raw query builder reads; resolver code converts via (float) at the boundary so PHPStan strict-rules cast.string stays satisfied (mirrors the pattern Phase 3's TransactionRowDto uses)"
  - "ChainLink model in Wave 1 declared without a Currency/Money attribute cast — chain_links carry no money column directly; the downstream DTOs (StatementSettlement, ChainLinkRow) carry Money values constructed at the query boundary"

patterns-established:
  - "Triple trigger pair (chain_links kind + state + conditional-NULL on to_transaction_id) — sets the schema-level invariant shape future trigger-enforced columns can adopt without code-level branching"
  - "Migration-as-test helper: require() the anonymous-class file and invoke up() directly to re-run forward-only data migrations inside Pest tests (used in CardStatementBackPopulationTest)"
  - "DTO inventory under Modules/<Name>/Public/Dto/ — five DTOs in this wave establish that Phase 5 DTOs use Spatie\\LaravelData\\Data, final classes, readonly props, and @param array<NestedDto> docblock annotations for nested collections (ChainTree.nodes + ChainTreeNode.children)"
  - "Singleton-bound state machine in module ServiceProvider::register() — CardStatementStateMachine joins PairLookup as the second example of the convention; the resolver classes Wave 2 ships will follow the same pattern when they own write paths"

requirements-completed:
  - CHN-07  # chain_links table exists with state/confidence/evidence per D-82; the schema-level contract is fully delivered (D-84 arch invariant enforces resolver scope)
# NOT MARKED — DTO shapes shipped but functional behaviour lands in later waves:
#   - CHN-05 (tolerance-window decomposition math) → Wave 2 IcsSettlementResolver
#   - CHN-06 (next-ICS-settlement forecast tile)   → Wave 4 dashboard rendering + ThisPeriodAtAGlanceQuery::nextIcsSettlement()
#   - UI-02  (chain drill-in surface)              → Wave 4 ChainDrawer Livewire SFC
# This wave delivers the data-layer scaffolding (schema + DTOs + state machine) but does NOT
# deliver the user-visible feature behaviour those requirements describe. Marking them complete
# here would prematurely flag Phase 5 as feature-delivered (same posture 05-01-SUMMARY took
# when rolling CHN-07 / UI-02 back from the over-marked state).

# Metrics
duration: ~30min
completed: 2026-05-16
---

# Phase 5 Plan 02: Wave 1 Schema + Models + DTOs + State Machine Summary

**Five migrations create chain_links + card_statements + card_statement_credits + chain_resolution_runs with full enum trigger coverage; four Eloquent models with BelongsToUser; five LaravelData Public DTOs; and a singleton-bound CardStatementStateMachine that becomes the only legal mutator of card_statements.state.**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-05-16T16:30:00Z
- **Completed:** 2026-05-16T16:48:00Z
- **Tasks:** 2 of 2 (both `type="auto" tdd="true"`)
- **Files created:** 20 (5 migrations + 4 models + 5 DTOs + 1 state machine + 5 tests)
- **Files modified:** 1 (`Modules/Chains/Providers/ChainsServiceProvider.php`)
- **Tests added:** 24 (Wave 1 net new) — full ChainsUnit + ChainsFeature suites now total 30 tests, 117 assertions; full project suite at 624 passed / 1 pre-existing failure / 5 pre-existing skips

## Accomplishments

- Five migrations applied cleanly via `php artisan migrate:fresh` end-to-end (Phase 1 → 4 schema unchanged; Phase 5 schema fully shaped)
- chain_links + card_statements + card_statement_credits + chain_resolution_runs each carry a BEFORE INSERT / BEFORE UPDATE trigger pair for their enum-shaped string column; the third trigger pair on chain_links.to_transaction_id enforces the conditional-NULL semantics at the schema layer (no resolver-side guard required)
- One-shot back-population migration walks `statement_summaries` joined to `accounts` filtered on `accounts.kind = 'ics_card'`; inserts one card_statements row per surviving row via `insertOrIgnore`. Tests prove (a) the sign convention is preserved, (b) re-running yields zero new rows, and (c) ASN-kind statement_summaries are ignored
- Four Eloquent models follow the Phase 1 idiom verbatim — BelongsToUser trait + typed BelongsTo relations + json/integer/immutable_datetime casts + final class declaration. ChainLink.fromTransaction() / toTransaction() expose the dual Transaction edges
- Five Public DTOs land under `Modules/Chains/Public/Dto/`: ChainTree (composite, nested), ChainTreeNode (leaf with self-referential children for ICS fan-out), ChainLinkRow (review-queue row), CardStatementForecastTile (dashboard tile), StatementSettlement (state-machine return)
- CardStatementStateMachine implements the D-95 lifecycle exactly: settled when |newOpen| ≤ 1, overpaid when newOpen < -1, partially_settled when newOpen > 0 AND prevOpen > newOpen, otherwise unchanged. The constant `SETTLED_TOLERANCE_MINOR = 1` documents the ±€0.01 round-off tolerance
- ChainsServiceProvider::register binds CardStatementStateMachine as a singleton; the test asserts container resolution returns the same instance across two `$app->make()` calls
- BoundaryArchTest `noOtherCardStatementStateMutator` invariant stays green — the state machine is the allow-listed mutator, no other file under `Modules/Chains/` writes `card_statements.state`
- Larastan level 10 strict passes against all new files (0 errors across 184 analysed files); Pint format check clean

## Task Commits

1. **Task 1: Five migrations + ChainLinksSchemaTest + ChainResolutionRunsSchemaTest + ChainLinksJsonContainsSmokeTest** — `9f98ec4` (feat)
2. **Task 2: Models + DTOs + CardStatementStateMachine + ServiceProvider binding + back-population test + state-machine test** — `4faf269` (feat)

_Plan metadata commit follows in the final commit step._

## Files Created/Modified

### Created

- **Migrations**
  - `Modules/Chains/Database/Migrations/2026_05_16_010001_create_chain_links_table.php` — 11 columns + 6 triggers (kind insert/update, state insert/update, conditional-NULL on to_transaction_id insert/update); indexes on from_transaction_id, to_transaction_id, (user_id, state)
  - `Modules/Chains/Database/Migrations/2026_05_16_010002_create_card_statements_table.php` — 11 columns + state trigger pair; UNIQUE (user_id, account_id, period_start, period_end); index (user_id, state); import_run_id nullOnDelete
  - `Modules/Chains/Database/Migrations/2026_05_16_010003_create_card_statement_credits_table.php` — 8 columns + reason trigger pair; from_statement_id cascadeOnDelete + to_statement_id nullOnDelete; index (user_id, to_statement_id)
  - `Modules/Chains/Database/Migrations/2026_05_16_010004_backpopulate_card_statements_from_statement_summaries.php` — forward-only data migration with insertOrIgnore; skips rows with NULL period_start/period_end (defensive against incomplete Phase 3 statement metadata)
  - `Modules/Chains/Database/Migrations/2026_05_16_010005_create_chain_resolution_runs_table.php` — 10 columns + status trigger pair; non-nullable user_id; (user_id, created_at) index

- **Models**
  - `Modules/Chains/Models/ChainLink.php` — BelongsToUser + evidence array cast + fromTransaction/toTransaction relations
  - `Modules/Chains/Models/CardStatement.php` — BelongsToUser + integer + immutable_datetime casts + account/importRun relations
  - `Modules/Chains/Models/CardStatementCredit.php` — BelongsToUser + integer cast + fromStatement/toStatement relations
  - `Modules/Chains/Models/ChainResolutionRun.php` — BelongsToUser + integer + immutable_datetime casts (no relations beyond user; audit row)

- **DTOs**
  - `Modules/Chains/Public/Dto/ChainTree.php` — `(int rootTransactionId, array<ChainTreeNode> nodes)`
  - `Modules/Chains/Public/Dto/ChainTreeNode.php` — `(int transactionId, ?int chainLinkId, string counterpartyName, Money amount, CarbonImmutable bookedAt, string accountName, string kind, string confidenceTier, array<ChainTreeNode> children = [])`
  - `Modules/Chains/Public/Dto/ChainLinkRow.php` — review-queue row with both endpoints + confirmsRemaining auto-promotion hint
  - `Modules/Chains/Public/Dto/CardStatementForecastTile.php` — dashboard tile payload (Money amount, CarbonImmutable dueDate, int statementId, string state)
  - `Modules/Chains/Public/Dto/StatementSettlement.php` — `(int statementId, int previousOpenMinor, int newOpenMinor, string newState)`

- **State machine**
  - `Modules/Chains/Internal/CardStatementStateMachine.php` — single `applySettlement(int statementId, int deltaMinor, User user): StatementSettlement` public method; constructor DI for DatabaseManager + Clock; SETTLED_TOLERANCE_MINOR = 1

- **Tests** (24 net new — schema + back-pop + state-machine + JSON smoke)
  - `Modules/Chains/tests/Feature/ChainLinksSchemaTest.php` — 13 cases
  - `Modules/Chains/tests/Feature/ChainResolutionRunsSchemaTest.php` — 4 cases
  - `Modules/Chains/tests/Feature/ChainLinksJsonContainsSmokeTest.php` — 2 cases (whereJsonContains + whereRaw json_extract fallback)
  - `Modules/Chains/tests/Feature/CardStatementBackPopulationTest.php` — 3 cases
  - `Modules/Chains/tests/Unit/CardStatementStateMachineTest.php` — 4 dataset rows + cross-user + singleton-binding = 6 cases

### Modified

- `Modules/Chains/Providers/ChainsServiceProvider.php` — `register()` binds `CardStatementStateMachine::class` as a singleton; docblock rewritten to describe the current binding and the deferred surface (queries / actions / Livewire components ship in later waves)

### Trigger inventory

| Table | Trigger pair (insert + update) | Allowed values |
|-------|--------------------------------|----------------|
| chain_links | `chain_links_kind_check_insert/update` | `'paypal_funding'`, `'ics_bulk_settle'` |
| chain_links | `chain_links_state_check_insert/update` | `'candidate'`, `'confirmed'`, `'rejected'` |
| chain_links | `chain_links_to_transaction_id_check_insert/update` | NOT NULL unless `state='candidate' AND kind='ics_bulk_settle' AND json_extract(evidence, '$.tolerance_used')='exceeded'` |
| card_statements | `card_statements_state_check_insert/update` | `'open'`, `'partially_settled'`, `'settled'`, `'overpaid'` |
| card_statement_credits | `card_statement_credits_reason_check_insert/update` | `'overpayment'`, `'refund_after_close'` |
| chain_resolution_runs | `chain_resolution_runs_status_check_insert/update` | `'pending'`, `'running'`, `'complete'`, `'failed'` |

### JSON smoke test result

Both Test 1 (`whereJsonContains`) and Test 2 (`whereRaw json_extract` fallback) pass on the dev SQLite build. The Wave 3 auto-promotion counter can use either form; the resolver code will adopt `whereJsonContains` per Laravel idiom and keep the `whereRaw` fallback documented in 05-PATTERNS.md as a contingency.

## Decisions Made

See `key-decisions` in the frontmatter. The three most consequential at runtime:

1. **Schema-layer enforcement of the to_transaction_id conditional-NULL invariant.** The original RESEARCH proposed a resolver-side workaround (arbitrary first-expense pointer). The trigger pair using `json_extract` on the evidence JSON makes the invariant structural — every write path is checked, the resolver cannot accidentally insert a NULL endpoint in any other state/kind combination, and the failure mode is a loud `QueryException` with the precise reason string.
2. **Sign convention locked at the back-population write site.** `total_amount_minor` preserves the negative `closing_balance_minor` verbatim (the user owes money); `open_balance_minor` is the absolute value. The test asserts both columns against a known fixture (-84732 / 84732) so any future change to Phase 3's IcsPdfAdapter sign output trips a clear test failure.
3. **`PRAGMA busy_timeout = 5000` is the SQLite concurrency fence inside `applySettlement()`.** Laravel's `lockForUpdate()` is a no-op on SQLite — the `BEGIN IMMEDIATE` equivalent. The pragma asks SQLite to wait up to five seconds for a competing writer before raising `SQLITE_BUSY`. Combined with `ShouldBeUniqueUntilProcessing` on the resolver job (Wave 2), the state-machine read-then-write is structurally safe against parallel mutation.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] CardStatementBackPopulationTest had to invoke the migration's `up()` directly rather than via `Artisan::call('migrate')`**

- **Found during:** Task 2 verification (initial run of CardStatementBackPopulationTest)
- **Issue:** Plan Step 6 described running the back-population via `Artisan::call('migrate')`. Once Laravel's migrator has recorded the migration in the `migrations` table (which happens automatically at test boot via `RefreshDatabase`), subsequent `migrate` calls are a no-op — the test seeded a `statement_summaries` row AFTER the migration had already run with no rows to back-populate, so the migration's body never re-executed.
- **Fix:** Replaced `Artisan::call('migrate', [...])` with `require()` of the migration file followed by a direct `$migration->up()` invocation. The pattern is documented in the test's `runBackPopulation()` helper so future maintainers see the rationale inline.
- **Files modified:** `Modules/Chains/tests/Feature/CardStatementBackPopulationTest.php`
- **Verification:** All three test cases pass (`vendor/bin/pest --filter "CardStatementBackPopulationTest"`).
- **Committed in:** `4faf269` (Task 2 commit)

**2. [Rule 3 - Blocking] Back-population migration needed defensive null-period filter for incomplete Phase 3 statement_summaries**

- **Found during:** Task 2 verification (mental model check while drafting the migration)
- **Issue:** Some Phase 3 / Phase 4 statement_summaries rows carry NULL `period_start` / `period_end` (the columns are nullable on the source table). card_statements UNIQUE requires both boundaries, and the dashboard tile needs them for the due-date forecast (D-99 / D-100). Without the filter, NULL-period rows would either hit the UNIQUE constraint trivially (NULL is distinct in SQLite UNIQUE) and silently accept duplicates, or fail at write time depending on cast behaviour.
- **Fix:** Added `if ($row->period_start === null || $row->period_end === null) continue;` inside the foreach loop. NULL-period rows are skipped at back-population time; the resolver and dashboard never see them.
- **Files modified:** `Modules/Chains/Database/Migrations/2026_05_16_010004_backpopulate_card_statements_from_statement_summaries.php`
- **Verification:** Migration runs cleanly against the existing `migrate:fresh` baseline; the back-population test covers the ICS-only filter contract.
- **Committed in:** `9f98ec4` (Task 1 commit — the migration shipped in Task 1, filter included from the first commit)

**3. [Rule 3 - Pint auto-fix] Pint single-quote + ordered-imports + class-definition + braces-position normalisation**

- **Found during:** Task 1 verification (post-write `vendor/bin/pint --test`)
- **Issue:** Initial drafts of `2026_05_16_010001_create_chain_links_table.php` used double-quoted strings where single quotes suffice; the JSON smoke test imported `Illuminate\Database\Connection` implicitly via FQN.
- **Fix:** Ran `vendor/bin/pint` on the two affected files; format pass clean afterward.
- **Files modified:** `Modules/Chains/Database/Migrations/2026_05_16_010001_create_chain_links_table.php`, `Modules/Chains/tests/Feature/ChainLinksJsonContainsSmokeTest.php`
- **Verification:** `vendor/bin/pint --test` exits 0 with `{"tool":"pint","result":"passed"}`.
- **Committed in:** `9f98ec4` (Task 1 commit — formatter changes squashed in)

**4. [Rule 1 - Bug] CHN-05 / CHN-06 / UI-02 rolled back to Pending after over-marking them Complete during state updates**

- **Found during:** State update step (`gsd-sdk query requirements.mark-complete CHN-05 CHN-06 CHN-07 UI-02`)
- **Issue:** Plan frontmatter lists `requirements: [CHN-05, CHN-06, CHN-07, UI-02]`. The orchestrator's state-update protocol marks all listed requirements Complete after each plan. But Plan 05-02 is Wave 1 data-layer-only — CHN-05 (the decomposition matcher) ships in Wave 2 `IcsSettlementResolver`, CHN-06 (the forecast tile rendering) in Wave 4 dashboard, UI-02 (the chain drill-in surface) in Wave 4 `ChainDrawer`. The DTO shapes for those requirements ship in this wave (CardStatementForecastTile, ChainTree, ChainTreeNode), but the user-visible behaviour does not. Only CHN-07 (chain_links table with state/confidence/evidence) is fully delivered here.
- **Fix:** Reverted the three over-marked rows in `.planning/REQUIREMENTS.md` back to `[ ]` Pending + Pending traceability table state; left CHN-07 as Complete (delivered).
- **Files modified:** `.planning/REQUIREMENTS.md` (three rows rolled back)
- **Verification:** `grep -E 'CHN-0[567]|UI-02' .planning/REQUIREMENTS.md` shows CHN-05/CHN-06/UI-02 back at `[ ]` Pending; CHN-07 stays Complete.
- **Note for downstream plans:** Wave 2 (`05-03`) should re-run `requirements.mark-complete CHN-05` once `IcsSettlementResolver` ships; Wave 4 (`05-05`) should mark CHN-06 + UI-02 once the dashboard tile + chain drawer ship.

---

**Total deviations:** 4 auto-fixed (1 test-harness blocking, 1 defensive correctness, 1 formatter, 1 requirements-over-marking)
**Impact on plan:** Three correctness fixes were necessary for the verify block to pass cleanly; the requirements roll-back follows the same posture 05-01-SUMMARY took on its own over-marking event. No scope creep — every deviation kept the wave inside its declared data-layer boundary.

## Issues Encountered

- **Pre-existing TransactionTypeTest failure carried forward.** `Modules\Ledger\tests\Unit\TransactionTypeTest::it-rejects-an-invalid-transaction-type` fails under `vendor/bin/pest --parallel` exactly as documented in 05-01-SUMMARY and 05-01b-SUMMARY (environment-shaped — Pest parallel-mode SQLite trigger handling on this machine). 623 other tests pass. Out of scope per the wave's deviation rules.
- **Five pre-existing skipped tests carried forward.** Two HorizonBootsTest skips (Redis container reachability + QUEUE_CONNECTION=redis) and three CSV/MT940 cross-format dedup skips from Phase 2 Wave 3. Documented in earlier summaries; no Wave 1 work touched them.

## User Setup Required

None — Wave 1 is pure schema + model + state-machine code. No new composer packages, no new env vars, no Docker dependencies. The Wave 0 Docker Redis + Horizon setup from 05-01 remains the operator's only manual step for Phase 5.

## Threat Flags

No new security-relevant surface introduced beyond what the plan's `<threat_model>` already covers. The chain_links.evidence JSON column accepts resolver-emitted structured data only (the resolver writes; no user input reaches the column directly until Wave 3 ships the review-queue Confirm action, at which point a Public/Action class will own validation).

## Next Phase Readiness

Wave 1 schema + data layer is complete. Downstream waves inherit:

- **Wave 2 (`05-03`) IcsSettlementResolver** consumes `CardStatementStateMachine::applySettlement()` to mutate open_balance_minor + state inside the resolver pass; `ChainLink::create([...])` writes the bulk-settle chain_links; `ChainResolutionRun` records the dispatch's audit row.
- **Wave 3 (`05-04`) PaypalFundingResolver + auto-promotion** consumes `ChainLink::query()->whereJsonContains('evidence->signature_hash', ...)` (validated by the JSON smoke test) for the per-user counter that promotes the third same-signature confirmation.
- **Wave 4 (`05-05`) Dashboard tile + chain drawer** consume `CardStatementForecastTile` (dashboard) and `ChainTree` / `ChainTreeNode` (drawer) DTOs directly — the contracts are locked.
- **Wave 5 (`05-05b`) Card-statement credit carry-forward** consumes the existing `card_statement_credits` table to write overpayment / refund_after_close rows; the trigger pair already enforces the reason set.
- **BoundaryArchTest invariants** continue to bind: `noResolverWritesTransactions` is trivially satisfied (no resolver code yet), `noOtherCardStatementStateMutator` binds the production-code surface (state machine is the allow-listed exception).

No blockers identified for Wave 2.

## Self-Check: PASSED

Created files exist on disk:

- `Modules/Chains/Database/Migrations/2026_05_16_010001_create_chain_links_table.php` — FOUND
- `Modules/Chains/Database/Migrations/2026_05_16_010002_create_card_statements_table.php` — FOUND
- `Modules/Chains/Database/Migrations/2026_05_16_010003_create_card_statement_credits_table.php` — FOUND
- `Modules/Chains/Database/Migrations/2026_05_16_010004_backpopulate_card_statements_from_statement_summaries.php` — FOUND
- `Modules/Chains/Database/Migrations/2026_05_16_010005_create_chain_resolution_runs_table.php` — FOUND
- `Modules/Chains/Models/ChainLink.php` — FOUND
- `Modules/Chains/Models/CardStatement.php` — FOUND
- `Modules/Chains/Models/CardStatementCredit.php` — FOUND
- `Modules/Chains/Models/ChainResolutionRun.php` — FOUND
- `Modules/Chains/Public/Dto/ChainTree.php` — FOUND
- `Modules/Chains/Public/Dto/ChainTreeNode.php` — FOUND
- `Modules/Chains/Public/Dto/ChainLinkRow.php` — FOUND
- `Modules/Chains/Public/Dto/CardStatementForecastTile.php` — FOUND
- `Modules/Chains/Public/Dto/StatementSettlement.php` — FOUND
- `Modules/Chains/Internal/CardStatementStateMachine.php` — FOUND
- `Modules/Chains/tests/Feature/ChainLinksSchemaTest.php` — FOUND
- `Modules/Chains/tests/Feature/ChainResolutionRunsSchemaTest.php` — FOUND
- `Modules/Chains/tests/Feature/ChainLinksJsonContainsSmokeTest.php` — FOUND
- `Modules/Chains/tests/Feature/CardStatementBackPopulationTest.php` — FOUND
- `Modules/Chains/tests/Unit/CardStatementStateMachineTest.php` — FOUND

Commits exist in `git log`:

- `9f98ec4` (Task 1, feat) — FOUND
- `4faf269` (Task 2, feat) — FOUND

---
*Phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition*
*Plan: 02*
*Completed: 2026-05-16*
