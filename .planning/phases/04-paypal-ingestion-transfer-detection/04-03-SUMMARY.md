---
phase: 04-paypal-ingestion-transfer-detection
plan: 03
subsystem: ledger
tags: [transfers, pair-detection, event-listener, module, migration, classify-transaction-type, led-04]

# Dependency graph
requires:
  - phase: 04-paypal-ingestion-transfer-detection
    provides: Wave 0 TransactionImported event (Modules/Import/Public/Events) + RecordTransactions sync in-tx dispatch + PaypalCsvEventTypeMap (classify/transactionType vocabulary)
  - phase: 04-paypal-ingestion-transfer-detection
    provides: Wave 1 PaypalCsvAdapter (canonical rows now flow end-to-end into the ledger) + per-row rawPayload manifest with `format: 'paypal-csv'` + `events: [...]`
  - phase: 02-asn-statement-coverage-camt-053-mt940
    provides: NormalizeStage amount-sign default (negative→expense, positive→income) that ClassifyTransactionType refines
  - phase: 03-ics-cards-multi-currency-display
    provides: synthetic-IBAN account modeling (ICS-CARD precedent) that the listener relies on for cross-account matching
provides:
  - Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php — self-referential nullable FK with ON DELETE SET NULL + partial index `transactions_unpaired_transfer_idx` covering the listener hot-path (user_id, account_id, booked_at) WHERE pair_transaction_id IS NULL AND type IN ('transfer_out', 'transfer_in')
  - Modules/Ledger/Models/Transaction.php — `pair_transaction_id` in $fillable, `@property int|null $pair_transaction_id`, `pair(): BelongsTo<Transaction, $this>` relation
  - Modules/Ledger/Public/Dto/CanonicalTransaction.php — immutable `withType(string $type): self` clone-with-override (mirrors StatementSummaryData::withImportRunId)
  - Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php — D-76 / D-77 typing stage; cross-account-IBAN flip, PayPal event-type map, subtractive income detector, refund/fee/adjustment preservation; constructor-DI'd via PaypalCsvEventTypeMap + DatabaseManager; NEVER queries the `transactions` table (Pitfall 3 grep gate enforced)
  - Modules/Import/Internal/Pipeline/ImportPipeline.php — ClassifyTransactionType stage wired between NormalizeStage and FingerprintStage; one-line invocation per row inside the per-row try/catch so classification errors surface as ERROR-status preview rows rather than aborting the whole file
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php — rawPayload manifest now carries `language` alongside `format` and `events` so ClassifyTransactionType can look up parent event types without re-detecting from the header row
  - Modules/Transfers/ (new bounded module — composer.json, Providers/TransfersServiceProvider.php, Internal/Listeners/PairTransferCandidates.php, tests/{Pest.php, TestCase.php, Unit/.gitkeep, Feature/PairTransferCandidatesTest.php})
  - bootstrap/providers.php / bootstrap/cache/services.php — TransfersServiceProvider registered after CategorizationServiceProvider
  - composer.json — Modules\Transfers\Tests\ autoload-dev psr-4 entry
  - phpunit.xml — Modules/Transfers/tests/{Unit,Feature} testsuite directories
  - tests/Pest.php — Modules/Transfers added to the module-test wire-up so feature tests inherit RefreshDatabase + the booted Laravel app
  - Modules/Ledger/tests/Feature/PairTransactionSchemaTest.php — 6 schema + DTO invariants (FK + cascade + index + pair() + withType())
  - Modules/Import/tests/Feature/ClassifyTransactionTypeTest.php — 10 classification cases (refund/fee preserve; transfer_in/transfer_out flip; D-77 income; cross-user safety; PayPal event-type map; Pitfall 3 grep gate)
  - Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php — 9 listener cases (ASN↔ICS pair; half-pair; partner-lands-later; idempotency; no-self-pair; cross-user safety; symmetric write; event tampering; provider registration)
affects: [04-04, 04-05, 05-*, 08-*]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "ClassifyTransactionType pipeline stage decouples typing from pair detection (D-76 / Pitfall 3). The stage is a pure pre-load transformer — it NEVER queries the transactions table (grep-gate enforced in ClassifyTransactionTypeTest) and NEVER mutates pair_transaction_id (the listener's domain). Refund/fee/adjustment rows are preserved, cross-account-IBAN matches flip the type to transfer_in/transfer_out, PayPal event-type map resolves the parent type, and D-77's subtractive rule promotes the remaining positive amounts to income."
    - "Self-referential FK pattern (`pair_transaction_id` BIGINT UNSIGNED NULLABLE) with ON DELETE SET NULL: deletes propagate as NULL rather than orphan-pointing into the void. Cross-currency pairs are valid so there is no DB-layer CHECK on amount-sum; the listener that writes pairs owns the equal-and-opposite invariant. NOT in the v3 fingerprint tuple — adding the column does not require a fingerprint version bump or a re-derive pass."
    - "Partial index for listener hot-path: `CREATE INDEX transactions_unpaired_transfer_idx ON transactions(user_id, account_id, booked_at) WHERE pair_transaction_id IS NULL AND type IN ('transfer_out', 'transfer_in')`. Keeps the partner-lookup query cheap even at 100k+ rows because the index only covers the unpaired-transfer subset that the listener queries on every fire."
    - "Constructor-DI Dispatcher inside an Internal listener, no facade. Mirrors RecordTransactions' Dispatcher injection (Wave 0 pattern). The listener itself is auto-resolved by Laravel's event system; no service-provider binding needed."
    - "Synchronous in-transaction event listener (no ShouldHandleEventsAfterCommit, no ShouldQueue, no nested DB::transaction wrapper). Inherits the outer RecordTransactions transaction frame so same-import-batch partner rows pair atomically — both writes commit or roll back together (Pitfall 1)."
    - "Raw DatabaseManager query builder for Account + Transaction lookups inside the listener — matches the project-wide staticMethod.dynamicCall strict-rules posture used in TopCategoriesByPeriodQuery. The partner Eloquent model is loaded only after the partner id is resolved, so the symmetric save() path stays in standard Eloquent (preserves timestamps + casts + the BEFORE-UPDATE type trigger)."
    - "Defensive event-payload mismatch assertion: `$event->transaction->user_id === $event->user->id` raises RuntimeException on mismatch. T-04-W2-02 mitigation — the event is in-process / in-transaction so this should never fire in production but surfaces fast and loud if a future regression in event construction inverts the payload."
    - "Bounded module with empty Public/ surface (D-80). Modules/Transfers/ ships the listener + service provider only; Public/ stays empty until a second consumer (Phase 5 chain resolver) needs to ask 'is row X paired?'. Same posture as Phase 4's earlier Wave 0 plan-task that left Modules/Import/Public/Events/TransactionImported's only subscriber pending until this plan."
    - "Per-module Pest.php is inert (documented in tests/Pest.php). The root tests/Pest.php's `foreach module → testCase` loop is the single source of truth for binding RefreshDatabase + the module's TestCase to its Feature suite. Adding a new module is a three-line change: phpunit.xml testsuite entry + composer.json autoload-dev psr-4 entry + tests/Pest.php map entry."

key-files:
  created:
    - Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php
    - Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php
    - Modules/Transfers/composer.json
    - Modules/Transfers/Providers/TransfersServiceProvider.php
    - Modules/Transfers/Internal/Listeners/PairTransferCandidates.php
    - Modules/Transfers/tests/Pest.php
    - Modules/Transfers/tests/TestCase.php
    - Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php
    - Modules/Transfers/tests/Unit/.gitkeep
    - Modules/Ledger/tests/Feature/PairTransactionSchemaTest.php
    - Modules/Import/tests/Feature/ClassifyTransactionTypeTest.php
    - .planning/phases/04-paypal-ingestion-transfer-detection/deferred-items.md
  modified:
    - Modules/Ledger/Models/Transaction.php (pair_transaction_id in $fillable + PHPDoc + pair() BelongsTo)
    - Modules/Ledger/Public/Dto/CanonicalTransaction.php (withType clone-with-override)
    - Modules/Import/Internal/Pipeline/ImportPipeline.php (ClassifyTransactionType injected + invoked between Normalize and Fingerprint)
    - Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php (rawPayload now stamps `language` alongside `format` + `events`)
    - bootstrap/providers.php (TransfersServiceProvider registered)
    - bootstrap/cache/services.php (regenerated by composer dump-autoload)
    - composer.json (Modules\Transfers\Tests\ autoload-dev psr-4 entry)
    - phpunit.xml (Modules/Transfers/tests/{Unit,Feature} testsuite directories)
    - tests/Pest.php (Modules/Transfers added to the per-module wire-up loop)

key-decisions:
  - "Pre-existing TransactionTypeTest failure (`it rejects an invalid transaction type at the DB layer`) is treated as out-of-scope. Verified reproducible on `b57c0dd` (Phase 4 Plan 02 HEAD) before any Wave 2 change via git-stash + checkout round-trip. Test fires the trigger correctly when invoked outside the Pest harness (verified via direct `php -r` insert against the on-disk sqlite connection). Logged to .planning/phases/04-paypal-ingestion-transfer-detection/deferred-items.md for the verifier."
  - "ClassifyTransactionType uses raw DatabaseManager `db->connection()->table('accounts')->count() > 0` for the cross-account-IBAN predicate rather than Eloquent's `Account::query()->exists()`. The Larastan strict-rules `staticMethod.dynamicCall` rule forbids Eloquent's chained static-call shape. Same pattern PreviewWizard::needsIcsAccountName uses for the same predicate shape (project memory entry from Phase 3-04). One trade-off: the stage no longer needs `Modules\Ledger\Models\Account` imported, keeping the module surface narrower."
  - "PaypalTransactionRollup now stamps `language` onto every emitted rawPayload (alongside `format` and `events`). ClassifyTransactionType reads it during step 3 to look up the parent event type via PaypalCsvEventTypeMap::transactionType(). The language was already locked at the StatementSummaryData.extras level (Wave 1 D-65) but per-row carry was the simplest fix — re-detecting language inside the typing stage would have leaked HeaderSniffer knowledge into the Import module. Tests for the rollup walker continue to pass (25 PayPal-related tests GREEN)."
  - "Plan-task TDD discipline: each task followed RED → GREEN. Three RED test commits (89010e7 + b4e5a75 + b5ed81e) landed before their corresponding feat commits (e3f2f27 + 3f39767 + 56fd500). Larastan level-10 strict + Pint clean across the full sequence; no `--no-verify` invocations. Same pattern Plans 04-01 / 04-02 established."
  - "Listener body uses raw DatabaseManager for the partner-row lookup (whereBetween / whereIn / whereNull / orderBy chain) per the same `staticMethod.dynamicCall` posture, and then loads the partner Transaction via Eloquent firstOrFail() for the symmetric save(). Two-step shape lets the where-chain stay strict-rules-clean while the writes still flow through Eloquent's BEFORE-UPDATE type trigger + timestamps + casts."
  - "Phase 4 SC#3 demoability is validated by the listener-level `pairsAsnIcsSettlement` Pest test rather than via an actual back-to-back ASN CAMT.053 + ICS PDF import. Reason: the existing Phase 2 CAMT.053 fixture (Modules/Ingestion/tests/fixtures/asn/camt053-sample.xml) and the Phase 3 ICS PDF fixture (Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt) do not share a synthesised iDEAL settlement counterparty-IBAN pair in the redacted state. A real-data overlap is deferred until the user uploads both an ASN CAMT export AND an ICS PDF export covering the same settlement period; until then the listener contract is proven at the synthetic-fixture level, which exercises the exact same code path the production pipeline traverses."
  - "Modules/Transfers/ Public/ surface stays empty in Phase 4 (D-80 honoured). No PairLookup service, no events emitted from the Transfers module. Phase 5's chain resolver is the projected first consumer — promote to Public when it arrives."

patterns-established:
  - "Stage-chain extension pattern: ImportPipeline gains a fourth stage between NormalizeStage and FingerprintStage with a single constructor parameter + a single inline invocation. Any future per-row enrichment stage (Phase 7 email-receipt enrichment, Phase 8 recurring-detection) extends the same chain shape."
  - "Listener-as-bounded-module pattern: a new domain capability (cross-account pair detection) lands as one Module/<Name>/ directory with Providers + Internal/Listeners only, no migrations, no routes, no Public/ surface. The model + schema live on the domain module that already owns the table (Ledger.Transaction + the pair_transaction_id column on `transactions`); the cross-cutting behavior gets its own bounded module to keep the boundary clean. Same shape any future event-subscribed-only module will follow."
  - "Raw DatabaseManager + Eloquent firstOrFail() hybrid for write-after-lookup flows: the search uses raw Query Builder to satisfy strict-rules; the Eloquent model loads once an id is known so the write inherits casts + timestamps + triggers. Reusable shape for any future module that needs to do a wide-filter lookup followed by a single-row save."

requirements-completed:
  - "LED-04 (transfer-pair detection with pair_transaction_id schema + Layer-1 deterministic listener; ASN↔ICS bulk-iDEAL settlement + PayPal↔ASN sweep pair when both legs land; cross-user pairing is impossible)"

# Metrics
metrics:
  duration: "~18min"
  tasks_completed: 3
  files_created: 12
  files_modified: 9
  commits: 6
  date_completed: 2026-05-16
---

# Phase 4 Plan 03: Wave 2 Transfer-Pair Backbone Summary

**One-liner:** Wave 2 lands the `pair_transaction_id` self-FK + partial
index, the `ClassifyTransactionType` pipeline stage that types every
row before fingerprinting (cross-account-IBAN flip / PayPal event-type
map / D-77 subtractive income), and the new `Modules/Transfers/`
bounded module whose `PairTransferCandidates` listener subscribes to
the Wave 0 `TransactionImported` event and atomically pairs ASN↔ICS
and PayPal↔ASN transfer legs — bringing Phase 4 Success Criterion #3
GREEN.

## Performance

- **Duration:** ~18 minutes
- **Started:** 2026-05-15T22:00:03Z (date rolled to 2026-05-16 mid-execution)
- **Completed:** 2026-05-16T00:17Z (local)
- **Tasks:** 3 (each test → feat pair = 6 commits)
- **Files created:** 12
- **Files modified:** 9

## Accomplishments

- **Schema**: `pair_transaction_id` self-referential nullable FK with
  ON DELETE SET NULL ships on `transactions`. The accompanying partial
  index `transactions_unpaired_transfer_idx` covers
  `(user_id, account_id, booked_at) WHERE pair_transaction_id IS NULL
  AND type IN ('transfer_out', 'transfer_in')` — listener hot-path
  cheap at any row count.
- **Typing**: `ClassifyTransactionType` sits between NormalizeStage
  and FingerprintStage; every row gets a final type assignment BEFORE
  fingerprinting and persistence. Cross-account-IBAN flip,
  PayPal-event-type map (via `PaypalCsvEventTypeMap::transactionType`),
  refund/fee/adjustment preservation, and D-77's subtractive income
  detector all converge in one stage.
- **Module**: `Modules/Transfers/` is the project's first bounded
  module whose Public/ surface is intentionally empty (D-80). The
  listener + service-provider pair is the entire Phase 4 surface.
- **Listener**: `PairTransferCandidates` is synchronous + in-tx (no
  `ShouldHandleEventsAfterCommit`, no `ShouldQueue`); inherits the
  outer `RecordTransactions` transaction frame so same-batch
  partner rows pair atomically. Cross-user pairing is impossible —
  every Account + Transaction query filters on `$user->id`, and a
  defensive event-payload mismatch raises `RuntimeException`.
- **Phase 4 SC#3** is GREEN at the listener-contract level: an ASN
  `transfer_out` row with `counterparty_iban='ICS-CARD'` paired
  with an ICS `transfer_in` row with `counterparty_iban='NL57ASNB…'`
  pairs atomically.

## Task Commits

Each task followed RED → GREEN — two commits per task:

1. **Task 1: Migration + Transaction model + CanonicalTransaction::withType()**
   - `89010e7` (test) — failing PRAGMA-introspection + withType DTO test
   - `e3f2f27` (feat) — migration + Transaction::pair() + withType() clone

2. **Task 2: ClassifyTransactionType pipeline stage**
   - `b4e5a75` (test) — failing classification cases + Pitfall-3 grep gate
   - `3f39767` (feat) — stage + ImportPipeline wiring + rollup language stamp

3. **Task 3: Modules/Transfers/ + PairTransferCandidates listener**
   - `b5ed81e` (test) — scaffold composer.json + TestCase + 9 failing listener cases
   - `56fd500` (feat) — listener body + provider + bootstrap/providers.php

## Files Created/Modified

### Created
- `Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php`
  — Self-FK + partial index. Anonymous-class migration mirroring the
  shape of `add_raw_payload_to_transactions.php` (most-recent column-add
  migration); preserves the DI-only-exception comment verbatim.
- `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php`
  — 5-step algorithm: preserve refund/fee/adjustment → cross-account-
  IBAN flip → PayPal event-type map → subtractive income detector →
  default to NormalizeStage's amount-sign default.
- `Modules/Transfers/composer.json` — `diederik/transfers` laravel-module
  package manifest, mirrors Modules/Ingestion/composer.json shape.
- `Modules/Transfers/Providers/TransfersServiceProvider.php` —
  Dispatcher subscription only. No `loadMigrationsFrom` /
  `loadRoutesFrom` / `loadViewsFrom` (the module has none).
- `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` —
  Deterministic Layer-1 match with the WINDOW_DAYS=3 tolerance constant.
- `Modules/Transfers/tests/Pest.php` + `tests/TestCase.php` +
  `tests/Unit/.gitkeep` + `tests/Feature/PairTransferCandidatesTest.php`
  — Module test harness.
- `Modules/Ledger/tests/Feature/PairTransactionSchemaTest.php` —
  6 schema + DTO invariants (FK + ON DELETE SET NULL cascade + partial
  index existence + pair() relation + withType() immutability).
- `Modules/Import/tests/Feature/ClassifyTransactionTypeTest.php` —
  10 classification cases plus the Pitfall-3 grep gate.
- `.planning/phases/04-paypal-ingestion-transfer-detection/deferred-items.md`
  — Pre-existing TransactionTypeTest failure logged for the verifier.

### Modified
- `Modules/Ledger/Models/Transaction.php` — `pair_transaction_id`
  added to `$fillable` + PHPDoc property annotation + `pair()`
  BelongsTo relation.
- `Modules/Ledger/Public/Dto/CanonicalTransaction.php` — immutable
  `withType()` clone-with-override (preserves every other field).
- `Modules/Import/Internal/Pipeline/ImportPipeline.php` —
  `ClassifyTransactionType` injected into the constructor and invoked
  between `normalize->run()` and `fingerprint->classify()` inside the
  per-row try/catch.
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php`
  — rawPayload manifest now carries `language` alongside `format`
  and `events`.
- `bootstrap/providers.php` + `bootstrap/cache/services.php` —
  TransfersServiceProvider registered.
- `composer.json` — `Modules\Transfers\Tests\` autoload-dev entry.
- `phpunit.xml` — testsuite directories added.
- `tests/Pest.php` — Modules/Transfers added to the per-module
  RefreshDatabase wire-up loop.

## Decisions Made

See the `key-decisions` frontmatter array. Highlights:

1. **Pre-existing failure logged, not fixed.** `TransactionTypeTest::it
   rejects an invalid transaction type` was failing on Wave 1 HEAD
   (`b57c0dd`) before any Wave 2 change. Manual verification confirms
   the trigger fires correctly outside the Pest harness; the failure
   is environment-shaped (Pest parallel-mode SQLite trigger handling
   on this machine). Logged in `deferred-items.md` for verifier.

2. **Raw Query Builder + Eloquent firstOrFail hybrid** for listener
   writes. Strict-rules-clean lookup; Eloquent save() preserves
   timestamps + casts + the BEFORE-UPDATE type trigger.

3. **rawPayload.language stamping** rather than threading language
   through the pipeline. Single-line change to
   `PaypalTransactionRollup::buildDto()`; ClassifyTransactionType
   reads it during step 3 without leaking HeaderSniffer knowledge
   into the Import module.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Larastan strict-rules forbade `Account::query()->exists()` inside ClassifyTransactionType**

- **Found during:** Task 2 (`composer analyse` first run)
- **Issue:** Plan's algorithm-spec used Eloquent's chained query-builder
  shape (`Account::query()->where(...)->exists()`), but Larastan's
  strict-rules `staticMethod.dynamicCall` rule (level 10 strict) flags
  every Eloquent magic method call.
- **Fix:** Switched to raw `DatabaseManager::connection()->table('accounts')->count() > 0`
  — same predicate, strict-rules-clean. Matches the
  `PreviewWizard::needsIcsAccountName` pattern established in
  Phase 3-04 (project-memory).
- **Files modified:** `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php`
- **Verification:** `composer analyse` → 0 errors; 10/10
  ClassifyTransactionTypeTest cases GREEN.
- **Committed in:** `3f39767` (Task 2 commit)

**2. [Rule 3 — Blocking] Larastan strict-rules forbade `Transaction::query()->whereBetween()/whereIn()/whereNull()/orderBy()` inside PairTransferCandidates**

- **Found during:** Task 3 (`composer analyse` after listener implementation)
- **Issue:** Same root cause as deviation 1 — Eloquent's chained
  static-call shape (`whereBetween`, `whereIn`, `whereNull`,
  `orderBy`) all trip `staticMethod.dynamicCall`.
- **Fix:** Listener does the partner-id lookup via raw
  `DatabaseManager::connection()->table('transactions')->...->first(['id'])`,
  then loads the partner Eloquent model via `Transaction::query()
  ->where('user_id', $user->id)->where('id', $partnerId)->firstOrFail()`
  for the symmetric save(). Two-step shape keeps the where-chain
  strict-rules-clean while the writes still flow through Eloquent's
  BEFORE-UPDATE type trigger + timestamps.
- **Files modified:** `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php`
- **Verification:** `composer analyse` → 0 errors; 9/9
  PairTransferCandidatesTest cases GREEN.
- **Committed in:** `56fd500` (Task 3 commit)

**3. [Rule 2 — Missing Critical] PaypalTransactionRollup did not stamp `language` on per-row rawPayload**

- **Found during:** Task 2 (`ClassifyTransactionType` design — step 3 needs the language)
- **Issue:** Wave 1's PaypalTransactionRollup wrote `rawPayload =
  {format: 'paypal-csv', events: [...]}` but the language was only
  persisted on `statement_summaries.extras`, not on each emitted
  CanonicalTransaction's rawPayload. ClassifyTransactionType's step
  3 needs the language to call `PaypalCsvEventTypeMap::transactionType()`.
- **Fix:** Added `'language' => $language` to the rawPayload literal
  in `PaypalTransactionRollup::buildDto()`. Single-line change.
- **Files modified:** `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php`
- **Verification:** 25 PayPal-related tests stay GREEN (rollup +
  adapter + import); ClassifyTransactionType's PayPal cases (6 + 7
  in ClassifyTransactionTypeTest) GREEN.
- **Committed in:** `3f39767` (Task 2 commit)

**4. [Rule 3 — Blocking] Module-test discovery infrastructure**

- **Found during:** Task 3 (running `PairTransferCandidatesTest` for the
  first time — "no tests found")
- **Issue:** A new Modules/Transfers/ module needs three coordinated
  changes for its Feature tests to inherit RefreshDatabase + the
  booted Laravel app: phpunit.xml testsuite entries, composer.json
  autoload-dev psr-4 entry, AND a row in the
  `tests/Pest.php` per-module wire-up `foreach` loop. The plan's
  `<action>` only enumerated the composer.json + bootstrap/providers.php
  changes; the phpunit.xml + tests/Pest.php entries weren't in scope.
- **Fix:** Added all three:
  - `phpunit.xml` — `Modules/Transfers/tests/{Unit,Feature}` testsuite directories
  - `composer.json` — `Modules\Transfers\Tests\` psr-4 entry under autoload-dev
  - `tests/Pest.php` — `Modules/Transfers => Modules\Transfers\Tests\TestCase::class` row
- **Files modified:** `phpunit.xml`, `composer.json`, `tests/Pest.php`
- **Verification:** `vendor/bin/pest --filter PairTransferCandidatesTest`
  picks up + runs all 9 tests cleanly.
- **Committed in:** `b5ed81e` (Task 3 RED commit — bundles the scaffold)

---

**Total deviations:** 4 auto-fixed (2 blocking strict-rules, 1 missing
critical for downstream stage, 1 blocking test-discovery infrastructure).
**Impact on plan:** All four are correctness-required and zero scope
creep. Each was committed inline with the task that exercised it.

## Issues Encountered

- **Pre-existing TransactionTypeTest failure** (see Decision #1 + deferred-items.md).
- **Pint auto-applied formatting** to the test files (Pint result:
  `fixed` then `passed`) — standard outcome, no action required.
- **bootstrap/cache/services.php** is tracked in git and was
  regenerated by `composer dump-autoload` when the new provider
  landed. Committed alongside `bootstrap/providers.php` so the
  on-disk cache matches the new provider registration.

## User Setup Required

None — no external service configuration required.

## Self-Check

### File existence

- `Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php` — FOUND
- `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php` — FOUND
- `Modules/Transfers/composer.json` — FOUND
- `Modules/Transfers/Providers/TransfersServiceProvider.php` — FOUND
- `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` — FOUND
- `Modules/Transfers/tests/Pest.php` — FOUND
- `Modules/Transfers/tests/TestCase.php` — FOUND
- `Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php` — FOUND
- `Modules/Ledger/tests/Feature/PairTransactionSchemaTest.php` — FOUND
- `Modules/Import/tests/Feature/ClassifyTransactionTypeTest.php` — FOUND
- `.planning/phases/04-paypal-ingestion-transfer-detection/deferred-items.md` — FOUND

### Commit existence

- `89010e7` — test(04-03): add failing pair_transaction_id schema + withType DTO tests — FOUND
- `e3f2f27` — feat(04-03): add pair_transaction_id self-FK + Transaction::pair() + CanonicalTransaction::withType() — FOUND
- `b4e5a75` — test(04-03): add failing ClassifyTransactionType pipeline-stage tests — FOUND
- `3f39767` — feat(04-03): add ClassifyTransactionType pipeline stage + wire between Normalize and Fingerprint — FOUND
- `b5ed81e` — test(04-03): scaffold Modules/Transfers/ + add failing pair-detection feature tests — FOUND
- `56fd500` — feat(04-03): wire Modules/Transfers/ + PairTransferCandidates listener — FOUND

### Gate sequence (TDD plan-task verification)

All three tasks followed RED → GREEN: each task's `test(...)` commit
landed BEFORE its `feat(...)` commit. Larastan level-10 strict + Pint
clean throughout. The plan-level `type: execute` plan does not require
a single feature-level RED → GREEN cycle; per-task TDD compliance is
the governing contract and it is satisfied.

### Quality gates

- `composer analyse` — exits 0 (Larastan level max + strict-rules + Livewire extension)
- `composer format:check` — exits 0 (Pint)
- `composer test` — 561 passed, 3 skipped, 3 notices, 1 failed. The
  single failure is the pre-existing `TransactionTypeTest::it rejects
  an invalid transaction type` documented under Decisions and
  `deferred-items.md`. Net of Wave 2 work the suite GREENed 9 new
  Transfers tests + 10 new ClassifyTransactionType tests + 6 new
  PairTransactionSchemaTest tests = 25 new GREEN tests with no
  regressions in Phase 1/2/3 (the failure is not a regression — it
  was failing on Wave 1 HEAD too).

## Self-Check: PASSED

## Pointer to Wave 3

Wave 3 (plan 04-04) implements the user-facing reclassify action on
the transaction detail page + the dashboard income-rollup that
excludes transfers + the breaks-pair-on-reclassify invariant. Wave 2
assumes for Wave 3:

- `transactions.pair_transaction_id` is the canonical link column.
  Reclassifying one side of a pair to non-transfer MUST set both
  sides' `pair_transaction_id` to NULL atomically (the invariant
  Wave 3 will enforce via a `BreakPair` Public action under
  `Modules/Transfers/`).
- ClassifyTransactionType has already typed every row at import
  time. Wave 3's reclassify path is therefore strictly a
  user-override on already-typed rows — Wave 3 does NOT re-invoke
  ClassifyTransactionType.
- The dashboard's "this month" income rollup is currently agnostic
  to `transactions.type`; Wave 3 narrows the income tile + chart
  queries to `WHERE type = 'income'` (or `WHERE type IN ('income',
  'refund')` per the user's preference — decision deferred to
  04-04 planning) so transfer rows never inflate the totals.

## Threat Flags

No new threat surface introduced beyond the plan's `<threat_model>`.
The Wave 2 mitigations for T-04-W2-01 through T-04-W2-07 are all in
place:

- T-04-W2-01 (info disclosure via cross-user Account lookup):
  ClassifyTransactionType's Account query filters on `$user->id`;
  cross-user test covers it.
- T-04-W2-02 (tampering via cross-user partner write):
  PairTransferCandidates filters every query on `$user->id` AND
  asserts `$event->transaction->user_id === $event->user->id`;
  cross-user + tamper feature tests cover both layers.
- T-04-W2-03 (elevation via forged event): in-process / in-tx event
  + body assertion + cross-user feature test.
- T-04-W2-04 (race on $tx->save() / $partner->save()): accepted —
  SQLite WAL single-writer serialisation; same-batch pairing
  inherits the outer transaction frame.
- T-04-W2-05 (FK orphan on partner delete): migration's ON DELETE
  SET NULL action; PairTransactionSchemaTest `it resets the
  surviving row's pair_transaction_id to NULL when its partner is
  deleted` is the runtime check.
- T-04-W2-06 (ClassifyTransactionType queries transactions):
  Pitfall-3 grep gate test inside ClassifyTransactionTypeTest
  asserts zero `Transaction::` in the stage source after
  comment-stripping.
- T-04-W2-07 (ShouldHandleEventsAfterCommit regression):
  PairTransferCandidates implements neither
  `ShouldHandleEventsAfterCommit` nor `ShouldQueue`; the
  `partner-lands-later` test exercises the same-import-batch
  in-transaction observability the absent interface preserves.

---
*Phase: 04-paypal-ingestion-transfer-detection*
*Completed: 2026-05-16*
