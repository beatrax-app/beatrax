# Phase 4 — Deferred Items (Out of Scope)

Issues discovered during Phase 4 execution that are NOT caused by this phase's
changes. Logged here so they don't get lost; not fixed inside Phase 4.

## Pre-existing test failure: `TransactionTypeTest::it rejects an invalid transaction type at the DB layer`

- **Discovered:** Phase 4 Plan 03 (Wave 2), Task 1
- **Test:** `Modules/Ledger/tests/Unit/TransactionTypeTest.php` line 51-75
- **Failure:** "Exception Illuminate\Database\QueryException not thrown."
- **Reproducibility:** Reproducible on `b57c0dd` (Phase 4 Plan 02 HEAD) before any Wave 2 changes; verified via `git stash` + `git checkout b57c0dd -- ...` round-trip.
- **Phase 4 Plan 02 SUMMARY claim:** "537 passed, 3 skipped, 3 notices" — inconsistent with current environment. Suspect environment-specific (Pest parallel-mode SQLite trigger handling on this machine) or a race between RefreshDatabase and the BEFORE-INSERT trigger creation.
- **Manual verification of trigger logic:** Working as expected when run outside the Pest harness (verified via `php -r` direct insert against `sqlite` connection: trigger fires, QueryException thrown).
- **Action:** Not in scope for Phase 4 Plan 03. Surface for the verifier; fix in a follow-on plan or Phase 5 maintenance pass.
