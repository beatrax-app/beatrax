## Deferred Items (Phase 05)

### Pre-existing, unrelated to 05-05b scope

**TransactionTypeTest — "it rejects an invalid transactions.type with a CHECK-constraint violation"**

- File: `Modules/Ledger/tests/Unit/TransactionTypeTest.php` line 74
- Symptom: expects `QueryException` with message `'Invalid transactions.type value'` when inserting a row with `type = 'banana'`; the exception is not thrown.
- Verified pre-existing: failed identically on stashed `HEAD~3` (commit `75ebef4`) before any 05-05b changes were applied.
- Not in scope for plan 05-05b — discovered during the full `vendor/bin/pest` run while verifying no regression. Logged here per the GSD executor's "scope boundary" rule (out-of-scope discoveries are not auto-fixed).
- Recommended owner: a separate Ledger plan that audits the `transactions.type` BEFORE-INSERT trigger pair shipped by Phase 1 migration. The trigger may have regressed during a later migration refactor.
