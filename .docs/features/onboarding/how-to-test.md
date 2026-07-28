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

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Onboarding` module.

## Behavioral contracts

- **The seed listener is idempotent.** Re-dispatching
  `UserInstalled` does not duplicate `wizard_progress` rows;
  the schema's `UNIQUE(user_id, step_id)` is the guard, and
  the initializer uses INSERT-OR-IGNORE semantics.
  (`tests/Unit/WizardProgressInitializerTest.php`,
  `tests/Feature/ReRunWizardTest.php`)
- **The wizard always resumes from the first incomplete
  step.** A user who completed step 3 and returns lands on
  step 4; a fully-complete user lands on `done`.
  (`tests/Unit/ResumeStepResolverTest.php`,
  `tests/Feature/ResumeWizardTest.php`)
- **A fresh signup redirects to `/setup-wizard`.** The
  post-signup destination is the wizard, not the dashboard.
  (`tests/Feature/SignupRoutesToSetupTest.php`)
- **A skipped step persists as skipped.**
  Skip-this-step lands on the next step with
  `wizard_progress.meta.skipped = true`; the user can return
  to the skipped step explicitly via the navigation.
  (`tests/Feature/SkipWizardStepTest.php`)
- **The connector steps stash their resulting `ImportRun.id`
  on `wizard_progress.meta`.** The first-import step's
  consolidated preview reads the stashed ids to build the
  preview without re-uploading. (`tests/Feature/ConnectPaypalStepStashesRunIdTest.php`,
  `tests/Feature/ConnectCardStepStashesAllRunIdsTest.php`)
- **The first-import step renders a consolidated
  multi-source preview.**
  `BuildConsolidatedPreviewQuery::for($user, $runIds)` returns
  per-source preview sections; the step renders them in a
  unified row list. (`tests/Feature/ConsolidatedPreviewLoadTest.php`)
- **The first-import step's commit is all-or-nothing.** A
  rollback during commit unwinds every per-account write.
  (`tests/Feature/FirstImportStepCommitEverythingTest.php`,
  `tests/Feature/FirstImportStepCommitRollbackTest.php`)
- **`StartingBalanceCard` is idempotent.** Confirming the
  same starting-balance value twice is a no-op; the
  underlying write goes through Ledger's sanctioned writer.
  (`tests/Feature/StartingBalanceCardIdempotencyTest.php`)
- **`StartingBalanceCard` warns on an out-of-range
  override.** A user-typed value that diverges from the
  detected anchor beyond threshold surfaces a warning the
  user can confirm.
  (`tests/Feature/StartingBalanceCardValidationWarnTest.php`)
- **The starting-balance card surfaces only for accounts the
  first import touched.** No card for an account with no
  imported rows.
  (`tests/Feature/StartingBalanceConfirmCardsTest.php`)
- **`WizardCompleted` fires once when the user lands on
  `DoneStep` for the first time.** Subsequent visits don't
  re-fire.
- **`ConnectEmailStep` is a link, not a wizard.** The step
  dispatches `oauth-wizard:open` on the browser; the actual
  OAuth UI lives in `EmailScan::OAuthClientWizardModal`.
  (`tests/Feature/ConnectEmailStepDispatchesGlobalOAuthOpenTest.php`)
- **The connect-bank step gates the format picker on the
  upload.** Picking CAMT.053 then attempting an MT940 upload
  raises a friendly validation error.
  (`tests/Feature/ConnectBankStepFormatGatingTest.php`)
- **`ConnectPaypalStep` reuses an existing PayPal Account
  row when present.** A user who already has a `paypal`-kind
  account does not create a second one.
  (`tests/Feature/ConnectPaypalStepReuseExistingAccountTest.php`)
- **`ConnectCardStep` supports multi-file ICS uploads.** The
  user can upload several monthly PDFs in one step; every
  produced `ImportRun.id` is stashed.
  (`tests/Feature/ConnectCardStepMultiFileTest.php`)
- **The consolidated-preview's load-more handler filters
  stale ids.** An `ImportRun.id` that was deleted before the
  first-import-step page reload no longer appears.
  (`tests/Feature/FirstImportStepStaleIdFilterTest.php`,
  `tests/Feature/FirstImportStepLoadMoreTest.php`)

## Edge cases

- **A user who closed the wizard mid-upload** — the previous
  step's stashed run id survives; the resume lands the user
  back on the same step with the upload in flight.
- **A user whose first install left a half-applied
  `wizard_progress` set** (e.g. the initializer was
  interrupted) — the next install dispatch re-runs the
  initializer; the unique constraint makes the existing rows
  no-ops; missing rows are inserted.
- **A user uploading two ASN exports in the same step** —
  the step accepts one upload per session; a second upload
  replaces the first (the user's "I picked the wrong file"
  case).
- **A user navigating to `/setup-wizard` after completion** —
  the resume resolver returns `'done'`; the wizard shows the
  `DoneStep` with a "back to dashboard" CTA.
- **A PayPal upload that fatally fails to parse** — the
  step surfaces the error from `RunImport::preview` directly;
  the user retries.
  (`tests/Feature/ConnectPaypalStepFatalParseUploadTest.php`)
- **A user who installs, completes the wizard, deletes
  every account, and re-runs the wizard** — the
  `wizard_progress` rows survive but every step's `meta` is
  irrelevant; the user explicitly resets by deleting the
  rows (currently a CLI escape hatch).

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `UserInstalled` event,
    `BelongsToUser`, `CurrentUser`.
  - [`Import`](../import/how-to-test.md) — `RunImport::preview`,
    `ConfirmImport`, `BuildConsolidatedPreviewQuery`,
    `DetectStartingBalancesQuery`.
  - [`Ingestion`](../ingestion/how-to-test.md) — `HeaderSniffer`
    (via the upload wizard).
  - [`Ledger`](../ledger/how-to-test.md) — writes
    `accounts.starting_balance_minor` through the sanctioned
    writer.
  - [`EmailScan`](../email-scan/how-to-test.md) — the
    `OAuthClientWizardModal` the `ConnectEmailStep` link
    dispatches to.
- **Depended on by**
  - No module imports `Onboarding`'s Public surface today;
    `WizardCompleted` is a future-subscriber event.
  - The dashboard layout may consult
    `WizardProgressQuery::isComplete` to suppress its
    welcome tour.

## Configuration + feature flags

- The step ordering is fixed in `WizardStepRegistry::all()`.
  Reordering is a deliberate code change.
- The starting-balance divergence threshold is fixed in the
  `StartingBalanceCard` SFC; no per-user knob.
- No env flag changes the wizard's behaviour.
