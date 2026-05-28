# `Onboarding` — how to test

Practical recipes for exercising the `Onboarding` module in
isolation.

## Unit tests

- **Location:** `Modules/Onboarding/tests/Unit/`
- **What they test:**
  - `WizardProgressInitializerTest` — the seed insert is
    idempotent on `UNIQUE(user_id, step_id)`.
  - `ResumeStepResolverTest` — the resume math for every
    combination of completed / incomplete step rows.

## Feature tests

- **Location:** `Modules/Onboarding/tests/Feature/`
- **What they test:**
  - Migration cleanliness (`WizardProgressMigrationTest`).
  - Resume + re-run (`ResumeWizardTest`, `ReRunWizardTest`).
  - Signup redirects to the wizard
    (`SignupRoutesToSetupTest`).
  - Skip a step (`SkipWizardStepTest`).
  - The connector steps — bank (`ConnectBankStepFormatGatingTest`),
    card (`ConnectCardStepMultiFileTest`,
    `ConnectCardStepStashesAllRunIdsTest`), PayPal
    (`ConnectPaypalStepCacheContentsTest`,
    `ConnectPaypalStepConsolidatedPreviewTest`,
    `ConnectPaypalStepFatalParseUploadTest`,
    `ConnectPaypalStepReuseExistingAccountTest`,
    `ConnectPaypalStepStashesRunIdTest`), email
    (`ConnectEmailStepDispatchesGlobalOAuthOpenTest`).
  - The first-import step — load
    (`ConsolidatedPreviewLoadTest`,
    `FirstImportStepLoadMoreTest`,
    `FirstImportStepStaleIdFilterTest`,
    `FirstImportStepWidthTest`); commit
    (`FirstImportStepCommitEverythingTest`,
    `FirstImportStepCommitRollbackTest`).
  - The starting-balance card
    (`StartingBalanceCardIdempotencyTest`,
    `StartingBalanceCardValidationWarnTest`,
    `StartingBalanceConfirmCardsTest`).

## Contract / arch invariants

- The repo-wide module-boundary invariant — forbids any class
  outside `Modules\Onboarding\` from importing
  `Modules\Onboarding\Internal\*`.
- The repo-wide `noOnboardingWritesToTransactions` — the
  module never writes `transactions`; every persist routes
  through `Import::ConfirmImport`.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Onboarding/tests

# Just the resume math
vendor/bin/pest Modules/Onboarding/tests/Unit/ResumeStepResolverTest.php

# Just the first-import step
vendor/bin/pest Modules/Onboarding/tests/Feature --filter "FirstImportStep"

# Stop on first failure
vendor/bin/pest Modules/Onboarding/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A user landing on the wrong step after returning** —
  walk the `wizard_progress` rows for the user; the resume
  resolver returns the first row whose `completed_at` is null.
  If a step you completed is showing as incomplete, the
  completion write didn't land — check the matching
  feature test for that step.
- **The first-import step's preview is empty** — the
  stashed `ImportRun.id` array on
  `wizard_progress.meta` is wrong (empty or pointing at
  deleted runs). The stale-id filter
  (`FirstImportStepStaleIdFilterTest`) drops missing ids;
  if all ids are stale, the preview renders empty.
- **`UserInstalled` fires but `wizard_progress` rows are
  missing** — `InitializeWizardProgressOnInstall` is not
  subscribed; confirm the provider's `boot()` subscribed it.
- **`ConnectEmailStep` does not open the OAuth wizard** —
  the step dispatches a browser event
  (`oauth-wizard:open`); the receiving Livewire component
  is `EmailScan::OAuthClientWizardModal` which must be
  mounted in the layout. Confirm the modal is present in
  the wizard layout's Blade.
- **A starting-balance card shows a wildly different
  detected anchor** — the `DetectStartingBalancesQuery`
  returned the first non-empty detector's value; if CAMT.053
  is present alongside MT940, CAMT.053 wins (registry
  order). The user can override; the warning surfaces if
  the override diverges beyond threshold.
- **A wizard reset path needed** — there's no UI reset
  today; CLI escape hatch:
  `php artisan tinker` →
  `WizardProgress::query()->whereUserId($u->id)->delete()` →
  the next visit reseeds via `UserInstalled` re-dispatch
  (or just visit `/setup-wizard` — `ResumeStepResolver`
  with no rows returns the first step).
