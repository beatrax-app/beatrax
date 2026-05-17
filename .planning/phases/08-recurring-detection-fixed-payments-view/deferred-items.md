# Deferred Items — Phase 8

Pre-existing issues discovered during plan 08-01 execution. Each is unrelated to the
current task's changes (SCOPE BOUNDARY rule) and is logged here for a future fix.

## Pre-existing test failures on base commit `41360a0`

These already fail on a clean checkout of the phase 8 base branch and were NOT introduced
by plan 08-01. Verified by running each test against `git diff = empty`.

### `Modules\Ledger\tests\Feature\TransactionDetailReclassifyTest > it crossUser404`

- **File:** `Modules/Ledger/tests/Feature/TransactionDetailReclassifyTest.php:163`
- **Failure:** Expected `Exception` not thrown when User B (`$intruder`) calls
  `Livewire::test(TransactionDetail::class, …)->call('reclassify', 'expense')` against
  User A's transaction.
- **Diagnosis:** The HTTP route returns 404 correctly (line 152 passes), but the
  defence-in-depth assertion on the Livewire component (lines 160–163) does not
  throw — Livewire's mount() appears to succeed silently for the intruder under
  PHP 8.5 / Livewire 4.x in this worktree environment.
- **Action:** Out of scope for plan 08-01 (Wave 0 scaffolding). Logged for a future
  Ledger-targeted fix.

## Pre-existing environment-dependent failures (worktree-only)

These pre-date this worktree and are caused by the worktree's freshly-created
`storage/app/inbox/` directory tree not matching the umask/permission expectations the
EmailScan integration tests embed:

- `Modules\EmailScan\tests\Integration\EmlOrphanCleanupTest` (chmod temp file)
- `Modules\EmailScan\tests\Integration\BackfillGmailTest` (file_get_contents failure)
- `Modules\EmailScan\tests\Integration\BackfillGraphTest` (missing blob)
- `Modules\EmailScan\tests\Integration\GraphTwoPhaseTest` (could not open temp file)
- `Modules\EmailScan\tests\Integration\GmailCursorExportTest` (chmod temp file)
- `Modules\EmailScan\tests\Integration\GraphCursorExportTest` (chmod temp file)
- `Modules\EmailScan\tests\Feature\OAuthClientWizardModalTest` (× 2)
- `Modules\EmailScan\tests\Feature\OAuthClientWizardModalMicrosoftTest`
- `Modules\EmailScan\tests\Feature\OAuthCallbackGmailTest`
- `Modules\EmailScan\tests\Feature\OAuthCallbackMicrosoftTest`
- `Modules\EmailScan\tests\Feature\OAuthConnectControllerTest` (× 2)

These tests are excluded from the default `composer test` PHPUnit suites (they run in
the named `EmailScanIntegration` testsuite which the project's `composer test` script
does not invoke). They are NOT covered by the plan 08-01 verify hook
`composer test -- --testsuite=Unit,Feature,Contracts --stop-on-failure`. Logged only
for completeness.
