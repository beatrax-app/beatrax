---
phase: 04-paypal-ingestion-transfer-detection
fixed_at: 2026-05-16T00:00:00Z
review_path: .planning/phases/04-paypal-ingestion-transfer-detection/04-REVIEW.md
iteration: 1
findings_in_scope: 15
fixed: 15
skipped: 0
status: all_fixed
---

# Phase 4: Code Review Fix Report

**Fixed at:** 2026-05-16
**Source review:** `.planning/phases/04-paypal-ingestion-transfer-detection/04-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope: 15
- Fixed: 15
- Skipped: 0

## Fixed Issues

### WR-01: Reconciliation gate is tautological — gap is always 0 in PayPal adapter

**Files modified:** `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php`, `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php`, `Modules/Import/Internal/Http/Livewire/PreviewWizard.php`, `Modules/Import/Resources/views/livewire/preview-wizard.blade.php`, `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalCsvAdapterTest.php`
**Commit:** 1bfd38f
**Applied fix:** Chose option (b) from the review's two options. The PayPal Activity Download does not ship explicit opening/closing balance rows (every funding-sweep row resets `Saldo` to 0), so no meaningful reconciliation gate can be computed without inventing assumptions. Removed the always-`'ok'` reconciliation gate from `PaypalCsvAdapter::buildStatementMetadata()`, removed the dead `reconciliationWarning()` method from `PreviewWizard`, removed the unreachable Blade panel from `preview-wizard.blade.php`, and updated `PaypalCsvAdapterTest` to assert the extras no longer carry the misleading keys. Walker counters (`skippedHoldCount`, `orphanChildCount`, and the new `skippedMalformedRowCount` added under WR-07) remain as the audit signal for this format.

### WR-02: `ConfirmImport` re-confirm returns inserted_count as duplicates field

**Files modified:** `Modules/Import/Public/Actions/ConfirmImport.php`, `Modules/Import/tests/Feature/PreviewWizardTest.php`
**Commit:** 5c88320
**Applied fix:** Replaced `duplicates: $importRun->inserted_count` with `duplicates: $importRun->duplicate_count` in the already-confirmed return branch. Added an inline comment documenting the semantics ("the original confirm already committed `inserted_count` rows and detected `duplicate_count` collisions; a second confirm returns the previously-persisted duplicate total verbatim"). Added a feature test `returns the persisted duplicate_count (not inserted_count) when an already-confirmed run is re-confirmed` that pins the contract by writing a deliberately-distinct `duplicate_count` to the persisted run before re-confirming.

### WR-03: Pair-detection booked_at window over-broadens beyond the documented ±3 days

**Files modified:** `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php`, `Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php`
**Commit:** 467330c
**Applied fix:** Replaced naive `subDays(3)` / `addDays(3)` arithmetic with whole-day boundaries: `$tx->booked_at->copy()->startOfDay()->subDays(self::WINDOW_DAYS)` for the lower bound and `$tx->booked_at->copy()->endOfDay()->addDays(self::WINDOW_DAYS)` for the upper bound. The window is now symmetric ±3 calendar days regardless of time-of-day. Added two regression tests: one that pins the inclusive symmetric ±3-day contract (May 15 12:00 ASN row pairs with a May 18 23:59 ICS partner), one that asserts day +4 partners are correctly rejected.

### WR-04: GSD-process leakage in production source files (project rule violation)

**Files modified:** 15 production source files + 13 test/script files across two commits.
**Commits:** c09f4ce (production source), d983f6d (tests + anonymizer script)
**Applied fix:** Swept every `D-NN`, `Pitfall N`, `Wave 0/1/2/3`, `T-04-W2-NN`, `Phase 4 SC #N`, `UI-SPEC`, `RESEARCH.md`, `CONTEXT.md`, `WAVE-0-FINDINGS.md`, and `PATTERNS.md` reference out of PHPDocs, inline comments, and test narrative comments in the phase-4 surface area. Replaced each one with a substantive description of what the code does today (e.g., "subtractive income rule: positive amount → income unless transfer/refund/fee" instead of "D-77"; "FX-direction safety net: walker identifies foreign leg by Currency != 'EUR'" instead of "Pitfall 2"; "deterministic pair-detection listener" instead of "Wave 2 Layer-1 listener"). Test it-descriptions stripped of `Phase 4 SC#N` / `T-04-W2-NN` tokens. Removed the inline IN-pairing breakdown of orphan-child rows in `PaypalTransactionRollupTest`'s 41-fixture test in favor of a tighter walker-contract description. The `phase-N` Pest group tokens (`->group('phase-4')`) were left in place — they're a runtime test-filter mechanism used pervasively across the codebase, not a planning-process reference.

### WR-05: Anonymous migration uses `app()` global helper twice

**Files modified:** `Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php`
**Commit:** 5ad40e4
**Applied fix:** Memoised the container lookup into a `private ?DatabaseManager $resolvedDb` property so the container is hit at most once per migration up/down call. Replaced both `app(DatabaseManager::class)` call sites with `Container::getInstance()->make(DatabaseManager::class)` (more explicit, matches the guidance in the prompt about acceptable migration escape hatches). The `schema()` helper now delegates to `db()`, eliminating the duplicate lookup. Added a PHPDoc explaining the memoised property is the standing exception to the DI-only rule for anonymous migrations.

### WR-06: `Modules\Transfers\Internal` is not covered by the boundary arch test

**Files modified:** `tests/Contracts/BoundaryArchTest.php`
**Commit:** 2d8c917
**Applied fix:** Added the exact arch assertion the review recommended: `arch('Modules\\Transfers\\Internal is only used inside Modules\\Transfers')->expect('Modules\\Transfers\\Internal')->toOnlyBeUsedIn('Modules\\Transfers')`. The new Transfers module's `Internal/Listeners/PairTransferCandidates` namespace is now boundary-enforced alongside `Ledger`, `Core`, `Ingestion`, `Import`, and `Categorization`.

### WR-07: PaypalTransactionRollup throws unchecked InvalidAmountException for malformed amount

**Files modified:** `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php`, `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalTransactionRollupTest.php`
**Commit:** ca380ba
**Applied fix:** Wrapped the `buildDto()` call inside Pass 3 in `try { ... } catch (InvalidAmountException) { $this->skippedMalformedRowCount++; continue; }` so a malformed parent amount drops just the affected logical-payment group instead of killing the entire file. Wrapped the per-child FX amount parse in the same way so a malformed child drops only that FX leg — the parent still produces a canonical DTO without the FX pair filled in. Added a new `skippedMalformedRowCount()` getter and surfaced it in `PaypalCsvAdapter::statementMetadata()->extras` (only when > 0). Added two regression tests: one for a malformed parent Bruto cell, one for a malformed child FX leg.

### IN-01: `nameAccount()` does not assert the supplied IBAN was in the unknown list

**Files modified:** `Modules/Import/Internal/Http/Livewire/PreviewWizard.php`
**Commit:** 742b3f4
**Applied fix:** Injected `PreviewCache` into `nameAccount()` and added a guard that reads `$preview->accountsToName` from the current preview cache and verifies the supplied `$iban` argument appears in that list before invoking the namer. A wire request that tries to name an arbitrary IBAN now gets `'This IBAN is not part of the current preview.'` in the error bag rather than reaching the namer (which would still user-scope the write, so this is defence-in-depth).

### IN-02 / IN-03: Reuse of one exception type for two failure modes + `Throwable` catch too broad

**Files modified:** `Modules/Ingestion/Public/Exceptions/UnknownPaypalEventTypeException.php`, `Modules/Ingestion/Public/Exceptions/MissingPaypalTransactionTypeMapException.php` (new), `Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php`, `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php`
**Commit:** de4edc1
**Applied fix:** Un-finalised `UnknownPaypalEventTypeException` so it can serve as the supertype. Introduced a new narrower exception, `MissingPaypalTransactionTypeMapException`, which extends `UnknownPaypalEventTypeException` and is thrown by `PaypalCsvEventTypeMap::transactionType()` when an event type is mapped as `parent` in `MAP` but has no entry in `TRANSACTION_TYPE`. Narrowed `ClassifyTransactionType`'s catch from `Throwable` to `UnknownPaypalEventTypeException` — that hierarchy covers both failure modes (unknown event type and missing internal mapping), but lets unrelated failures (DI errors, OOM, type-cast issues) propagate.

**Note on classification:** the new exception split + narrower catch is technically a logic/structure change. The catch's intent ("fall through to the subtractive default for any PayPal mapping miss") is preserved exactly — but reviewers should confirm that's still the desired semantics, since the narrower catch now lets `RuntimeException` subclasses other than `UnknownPaypalEventTypeException` propagate (previously `catch (Throwable)` swallowed them too). Status flagged as `fixed: requires human verification`.

### IN-04: Empty PayPal date string raises `InvalidAmountException`

**Files modified:** `Modules/Ingestion/Public/Exceptions/InvalidDateException.php` (new), `Modules/Ingestion/Internal/Adapters/Paypal/PaypalDateParser.php`, `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php`, `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalDateParserTest.php`
**Commit:** 7660be0
**Applied fix:** Introduced `InvalidDateException` (a plain `RuntimeException` subclass). Replaced all four `throw new InvalidAmountException(...)` sites in `PaypalDateParser` with `throw new InvalidDateException(...)`. Updated `PaypalTransactionRollup::rollup()` to catch both `InvalidAmountException` and `InvalidDateException` at the per-parent boundary so a malformed date still drops just one logical-payment group (same semantics as WR-07 for amounts). Updated `PaypalDateParserTest` to expect `InvalidDateException` and renamed the it-description from "throwing InvalidAmountException" to "throwing InvalidDateException".

### IN-05: `PaypalTransactionRollup::buildDto()` uses assignment-as-argument anti-pattern

**Files modified:** `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php`
**Commit:** 3f8c0d4
**Applied fix:** Replaced `sourceRef: $parentTxnId = $this->columns->value('transactionId', $language, $parentRow)` with the plain `sourceRef: $this->columns->value('transactionId', $language, $parentRow)`. The named-argument-with-side-effect anti-pattern is gone; `$parentTxnId` was already dead inside the function body, so the rewrite is semantically identical.

### IN-06: Listener pair lookup uses Eloquent re-load even though raw row already returned the id

**Files modified:** `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php`
**Commit:** a4ff9bc
**Applied fix:** Removed the Eloquent `Transaction::query()->where(...)->firstOrFail()` re-fetch and the two model `save()` calls. Injected `Clock` into the listener constructor and switched the symmetric pair write to two raw `$connection->table('transactions')->where(...)->update([..., 'updated_at' => $now])` statements that operate directly on the ids the partner-lookup query already returned. The pair write is still atomic (it inherits the outer `RecordTransactions` transaction frame) and still user-scoped. Updated the in-memory `$tx->pair_transaction_id` + `syncOriginalAttribute()` so the event payload model stays consistent with the persisted state for any downstream listeners observing the same event.

**Note on classification:** swapping the partner-write path from Eloquent `save()` to raw `update()` is a behavior-adjacent change (timestamps via the injected `Clock` rather than via Eloquent's model timestamps). Tests should confirm `updated_at` still moves on pair writes, and the existing `PairTransferCandidatesTest` covers the visible contract (paired ids on both rows). Status flagged as `fixed: requires human verification`.

### IN-07: `scripts/anonymize_paypal_csv.php` reads file into memory twice

**Files modified:** `scripts/anonymize_paypal_csv.php`
**Commit:** dc5d77c
**Applied fix:** Replaced the `file_get_contents()` + `tmpfile()` + `fwrite()` triple-buffer pattern with a direct `fopen()` on the input path. BOM detection is now done by reading the first three bytes and either continuing (if BOM) or `rewind()`-ing (if not), so subsequent `fgetcsv()` calls see the right starting offset. Verified the script still produces byte-identical output on the existing committed fixture (`Anonymised 86 rows. Mapped 0 distinct IDs. Detected 40 orphan-child rows`).

### IN-08: `PaypalCsvEventTypeMap::TRANSACTION_TYPE` parent-only invariant is implicit

**Files modified:** `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalCsvEventTypeMapTest.php` (new)
**Commit:** ceb9c85
**Applied fix:** Added a new unit test file that uses `ReflectionClass` to read the private `MAP` and `TRANSACTION_TYPE` constants and pins two invariants: (1) every event type in `TRANSACTION_TYPE` is classified as `'parent'` in `MAP`, and (2) every event type classified as `'parent'` in `MAP` has a corresponding `TRANSACTION_TYPE` entry. Also covers the two failure-mode exceptions: `UnknownPaypalEventTypeException` from `classify()` for unmapped event types, and the narrower `MissingPaypalTransactionTypeMapException` from `transactionType()` for child-classified event types.

## Skipped Issues

None — every in-scope finding was fixed.

---

_Fixed: 2026-05-16_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
