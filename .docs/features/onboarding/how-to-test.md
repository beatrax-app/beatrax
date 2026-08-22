# `Onboarding` — how to test

Practical recipes for exercising the `Onboarding` module in
isolation.

## Unit tests

- **Location:** `Modules/Onboarding/tests/Unit/`
- **What they test:**
  - `WizardProgressInitializerTest` — one row per registry
    step, re-fire stays at one row per step, a step already
    past `pending` is left alone, and a step added after the
    wizard finished seeds `skipped` rather than `pending`.
  - `ResumeStepResolverTest` — in_progress wins, then first
    pending in registry order, then the empty-string sentinel.
  - `WizardSkippableStepsTest` — which step keys `isSkippable()`
    admits.

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
  `UserInstalled` does not duplicate `wizard_progress` rows.
  `UNIQUE(user_id, step_key)` is the schema-level backstop, but
  the initializer does not lean on it: it reads the user's
  existing rows first and inserts only the missing steps, because
  it also has to choose the seed status — `skipped` for a step
  added after the wizard was finished, `pending` while the user
  is still mid-wizard.
  (`tests/Unit/WizardProgressInitializerTest.php`,
  `tests/Feature/ReRunWizardTest.php`)
- **The wizard always resumes from the first incomplete
  step.** An `in_progress` row wins over any pending one; failing
  that, the first `pending` step in registry order. A user with
  no incomplete step left gets the empty-string sentinel, NOT
  `'done'` — `'done'` is a real step key, and returning it would
  send a finished user back into the wizard.
  (`tests/Unit/ResumeStepResolverTest.php`,
  `tests/Feature/ResumeWizardTest.php`)
- **A fresh signup redirects to `/setup-wizard`.** The
  post-signup destination is the wizard, not the dashboard.
  (`tests/Feature/SignupRoutesToSetupTest.php`)
- **A skipped step persists as skipped.**
  Skip-this-step lands on the next step and sets that row's
  `wizard_progress.status` to `'skipped'` — the status column,
  not a flag inside the JSON. "Resume later" (`skipRest`) flips
  every remaining non-done row the same way in one call.
  (`tests/Feature/SkipWizardStepTest.php`)
- **The connector steps stash their resulting `ImportRun.id`
  on `wizard_progress.data`.** The first-import step's
  consolidated preview reads the stashed ids to build the
  preview without re-uploading. (`tests/Feature/ConnectPaypalStepStashesRunIdTest.php`,
  `tests/Feature/ConnectCardStepStashesAllRunIdsTest.php`)
- **The first-import step renders a consolidated
  multi-source preview.**
  `BuildConsolidatedPreviewQuery::build($importRunIds, $user,
  $sectionLimitOverrides = [])` returns a
  `ConsolidatedPreviewBatch` of per-source-format sections; the
  step renders them in a unified row list. The run ids come first
  because the query's job is to filter THEM — by owner, by the
  14-day stale window and by already-confirmed status — before any
  cache read. (`tests/Feature/ConsolidatedPreviewLoadTest.php`)
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
  initializer; it reads the rows that exist and inserts only the
  missing ones, so the half-applied set completes rather than
  raising on the unique index.
- **A user uploading two ASN exports in the same step** —
  the step accepts one upload per session; a second upload
  replaces the first (the user's "I picked the wrong file"
  case).
- **A user navigating to `/setup-wizard` after completion** —
  `ResumeStepResolver::resolve()` returns the EMPTY STRING (not
  `'done'`, which is a real step key), and `SetupWizard::mount`
  redirects to `/`. The `DoneStep` with its "back to dashboard"
  CTA is what the user sees on the visit where they finish:
  advancing past the last step sets `allComplete` in the
  already-mounted component. A fresh visit afterwards does not
  re-render it. `tests/Feature/ReRunWizardTest.php` asserts
  both halves: the redirect to `/` when every step is done and
  no force flag is set, and the `?force=1` reset that puts a
  finished user back at `welcome`.
- **A PayPal upload that fatally fails to parse** — the step
  does NOT get an exception to surface. `ImportPipeline` turns
  a typed parse failure into error ROWS rather than raising, so
  `RunsImports::runFromUpload` returns normally with an
  all-error preview, and `ConnectPaypalStep::submit` has to
  detect that itself: it calls its own `fatalParseMessage()`
  over the result, sets `uploadError`, logs a warning, and
  returns WITHOUT dispatching `wizard.step.completed` or
  stashing the run id. The stash is the load-bearing half —
  stashing here would hand `FirstImportStep` the poisoned
  cache. The step's `catch` around `runFromUpload` is a
  separate, unrelated path for a genuinely unreadable file, and
  it produces the generic "unreadable" message rather than a
  named one. The test asserts the message names the right
  export ("Rapport Transactiegegevens") and that
  `wizard_progress.data.paypal_import_run_id` stayed null.
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
  - [`Import`](../import/how-to-test.md) —
    `RunsImports::runFromUpload` (the contract, not the
    concrete `RunImport`; bound to it in the container),
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
  - Nothing. `Onboarding` has no `Public/` directory to import
    from, and `WizardCompleted` is `Internal/` with no
    listener.
  - The dashboard layout does not consult wizard progress, and
    there is no `isComplete` read anywhere to consult:
    `WizardProgressQuery` is `Internal/`, its only method is
    `list($userId)`, and `SetupWizard` is its only caller. A
    surface that genuinely needs "is the wizard done" should
    ask for a contract with its name on it rather than reach
    for this class.

## Configuration + feature flags

- The step ordering is fixed in `WizardStepRegistry::steps()`,
  and which steps may be skipped in
  `WizardStepRegistry::isSkippable()`. Changing either is a
  deliberate code change.
- The starting-balance divergence threshold is fixed in the
  `StartingBalanceCard` SFC; no per-user knob.
- No env flag changes the wizard's behaviour.
