---
phase: 01-foundation-asn-csv-vertical-slice
fixed_at: 2026-05-13T09:58:00Z
review_path: .planning/phases/01-foundation-asn-csv-vertical-slice/01-REVIEW.md
iteration: 5
fix_scope: all
findings_in_scope: 7
fixed: 7
skipped: 0
status: all_fixed
---

# Phase 1: Code Review Fix Report (Iteration 5)

**Fixed at:** 2026-05-13T09:58:00Z
**Source review:** `.planning/phases/01-foundation-asn-csv-vertical-slice/01-REVIEW.md`
**Iteration:** 5 (retry — prior fixer-agent stream timed out before landing any commits)
**Fix scope:** all

**Summary:**

- Findings in scope: 7 (4 Blockers, 3 Warnings)
- Fixed: 7
- Skipped: 0
- Status: all_fixed

Each blocker landed as an atomic `fix(01): {id} ...` commit. W-01 and W-02 fold into the B-01 and B-02 commits per the prompt's own grouping ("also closes W-02" / "also fixes W-01 simultaneously"), and B-04 folds into B-01 because both changes are in the same test file and neither test runs in isolation. The independent W-03 finding gets its own commit. Both quality gates pass at the end of the run:

```
vendor/bin/phpstan analyse  → No errors
vendor/bin/pest              → Tests: 1 skipped, 239 passed (6746 assertions)
vendor/bin/pint --test       → passed
```

## Fixed Issues

### B-01: RecordTransactions idempotency regression on null user_id (closes W-02)

**Files modified:**
- `Modules/Ledger/Public/Actions/RecordTransactions.php`
- `Modules/Ledger/Public/Contracts/RecordsTransactions.php`
- `Modules/Ledger/tests/Feature/RecordTransactionsTest.php`

**Commit:** `b924786`

**Applied fix:** SQLite treats `NULL` as distinct in UNIQUE indexes, so a row written with `user_id = NULL` would slip past the composite UNIQUE on `(user_id, account_id, posted_at, ...)` on a re-import and silently duplicate. Production callers (NormalizeStage) always supply a real user, but the contract allowed `CanonicalTransaction.userId` to be null, so a misconfigured caller could bypass idempotency.

- `RecordTransactions::__invoke` now throws `InvalidArgumentException` as soon as it encounters a row with `userId === null`, before any DB write.
- `RecordsTransactions` interface PHPDoc spells out the precondition so implementers see it without reading the action body.
- `RecordTransactionsTest`'s `beforeEach` now seeds a user, propagates `userId` into every `canonical([...])` call, and a new regression test pins the null-user rejection behaviour.

This also closes the prompt's W-02 (the contract tightening was the W-02 ask).

**Note: requires human verification** — the additional precondition runs inside the existing `$this->db->connection()->transaction(...)` wrapper, so the throw correctly rolls back any preceding inserts in the same batch. Worth confirming the failure surface in `ConfirmImport` is acceptable (no try/catch today, the exception bubbles to a 500 if the production normalisation ever regresses — which would be the right failure mode for a contract violation).

### B-02: PHPStan errors (15 across 6 files) (closes W-01)

**Files modified:**
- `Modules/Categorization/Public/Services/CategoryOptionsQuery.php`
- `Modules/Core/Internal/Console/InstallCommand.php`
- `Modules/Core/Internal/Http/Livewire/Dashboard.php`
- `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php`
- `Modules/Ledger/Public/Actions/UpdateTransactionCategory.php`
- `Modules/Ledger/Public/Services/TopCategoriesByPeriodQuery.php`

**Commit:** `ccdddc9`

**Applied fix:** Six independent defects, fixed at the root each time:

- **`Dashboard::resolvePeriod()`** compared `CarbonImmutable|null === false`. The Carbon 3 `createFromFormat()` signature returns `CarbonImmutable|null`, so the check now compares against `null` (which also feeds a non-null value into the `format()` round-trip and `PeriodQuery::containing()` call on the same path). This is the prompt's W-01 — the user-controlled `periodStartStr` still falls through to `$periods->current()` on any parse failure.
- **`InstallCommand::resolveRealPath()`** re-checked `realpath()` output for `!== ''` after `is_string()`. Larastan refines `realpath()`'s return to `non-empty-string|false`, so the secondary check was always true. Dropped both redundant `!== ''` checks.
- **`InstallCommand::handle()`** called `User::query()->exists()`. `exists()` is in `Eloquent\Builder`'s `forwardCallsTo` passthru list, which Larastan surfaces as a static method, tripping `staticMethod.dynamicCall`. Routed the lookup through `DatabaseManager->connection()->table('users')->exists()` and added `DatabaseManager` to the command's constructor.
- **`FingerprintStage::isExistingFingerprint()`** had `Transaction::query()->...->exists()` hitting the same static-passthru rule. Replaced with raw query builder on `transactions` via `DatabaseManager`, also injected.
- **`UpdateTransactionCategory::__invoke()`** had `Category::query()->...->exists()` and `->whereNull()` matching the rule. The action's own NULL-or-current-user predicate already replicates Category's global scope's intent, so the lookup now uses the raw query builder directly. Closure parameter typed as `Illuminate\Database\Query\Builder` to keep the inner `whereNull()` / `orWhere()` chain resolvable.
- **`CategoryOptionsQuery::for()`** and **`TopCategoriesByPeriodQuery::loadCategories()`** passed bare closures (`static function ($q)`) into raw query builder `->where()` callbacks. Typed the parameter as `Illuminate\Database\Query\Builder` so PHPStan resolves the `whereNull()` / `orWhere()` chain to real methods.

Full `vendor/bin/phpstan analyse` reports zero errors; all existing tests still pass.

### B-03: TransactionTypeTest assertion now matches SQLite-trigger enforcement

**Files modified:**
- `Modules/Ledger/tests/Unit/TransactionTypeTest.php`

**Commit:** `0942125`

**Applied fix:** The allowed-types invariant on `transactions.type` was moved from a `Transaction::creating` model hook to a pair of SQLite `BEFORE INSERT` / `BEFORE UPDATE` triggers (so every write path — Eloquent `create/save` AND raw `insertOrIgnore` from the recorder — hits the same gate). The trigger raises `RAISE(ABORT, 'Invalid transactions.type value')`, surfaced as `Illuminate\Database\QueryException`, not the old `InvalidArgumentException` from the hook.

Updated the test to expect `QueryException` with the trigger's message text. The sibling `InvalidArgumentException` check inside `RecordTransactions` (application-layer pre-check for batches) is still exercised by `RecordTransactionsTest`'s bogus-type batch test.

### B-04: RecordTransactionsTest fingerprint_version assertion (folded into B-01 commit)

**Files modified:**
- `Modules/Ledger/tests/Feature/RecordTransactionsTest.php` (line 96/111)

**Commit:** `b924786` (same commit as B-01)

**Applied fix:** The "persists the SHA-256 fingerprint" test pinned `fingerprint_version === 1`, but `FingerprintComposer::NORMALIZATION_VERSION` was bumped to `2` in iteration 4 when the tuple was rebuilt around `user_id`. The fix replaces the literal `1` with `FingerprintComposer::NORMALIZATION_VERSION` so the test tracks the constant forward, and renames the `it(...)` description to "stamped with the current normalization version" so the intent is timeless.

**Why folded into B-01:** the same test calls `$this->canonical([...])` without `userId`, which now correctly throws under the B-01 rejection. The two changes cannot land independently — splitting them would leave one commit with a broken test suite. The combined commit ships them atomically.

### W-01: Dashboard CarbonImmutable nullable comparison (folded into B-02 commit)

**Files modified:**
- `Modules/Core/Internal/Http/Livewire/Dashboard.php` (line 99)

**Commit:** `ccdddc9` (same commit as B-02)

**Applied fix:** `$parsed === false` on a `CarbonImmutable|null` return value → `$parsed === null`. See B-02 above. This is one of the 15 PHPStan errors B-02 resolves; per the prompt's own grouping it lands in the same commit.

### W-02: RecordsTransactions contract tightening (folded into B-01 commit)

**Files modified:**
- `Modules/Ledger/Public/Contracts/RecordsTransactions.php`

**Commit:** `b924786` (same commit as B-01)

**Applied fix:** The interface PHPDoc now spells out the non-null-userId precondition for every implementer, so the runtime guard in `RecordTransactions` is documented at the public-API boundary. See B-01 above.

### W-03: Document FingerprintComposer::NORMALIZATION_VERSION semantics

**Files modified:**
- `Modules/Ledger/Public/Services/FingerprintComposer.php`

**Commit:** `20258a8`

**Applied fix:** Inline PHPDoc on the constant now records the current scheme: the user-scoped tuple shape (`user_id | account_id | posted_at | amount_minor | currency | counterparty_normalized | source_ref`) plus the `normalize()` rules (lowercase + NFD diacritic strip + non-alphanumeric collapse + 80-char truncate). Reads as a self-contained spec — a reader who sees `normalization_version = 2` on a stored row no longer has to dig through migration history or `git log` to learn what that means.

## Skipped Issues

_None._ All 7 in-scope findings were fixed.

---

_Fixed: 2026-05-13T09:58:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 5 (retry of the iteration-5 fixer pass after the prior agent stream-timed out)_
