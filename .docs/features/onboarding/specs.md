# `Onboarding` — specs

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
  - [`Core`](../core/specs.md) — `UserInstalled` event,
    `BelongsToUser`, `CurrentUser`.
  - [`Import`](../import/specs.md) — `RunImport::preview`,
    `ConfirmImport`, `BuildConsolidatedPreviewQuery`,
    `DetectStartingBalancesQuery`.
  - [`Ingestion`](../ingestion/specs.md) — `HeaderSniffer`
    (via the upload wizard).
  - [`Ledger`](../ledger/specs.md) — writes
    `accounts.starting_balance_minor` through the sanctioned
    writer.
  - [`EmailScan`](../email-scan/specs.md) — the
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
