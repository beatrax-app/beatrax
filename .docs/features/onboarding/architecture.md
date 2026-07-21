# `Onboarding` — architecture

The `Onboarding` module hosts the first-run setup wizard at
`/setup-wizard`. It walks a freshly-installed user through nine steps
— `welcome` → `connect-bank` → `connect-paypal` → `connect-card` →
`connect-email` → `first-import` → `budgets` → `tax-country` → `done`
— tracks per-step progress in the `wizard_progress` table, and resumes
from the right step on every subsequent visit until completion.

`WizardStepRegistry` is the single source of truth for this order and
for which steps are skippable: the four connector steps
(`connect-bank`, `connect-paypal`, `connect-card`, `connect-email`)
plus `budgets` and `tax-country` are skippable; the bookend steps
(`welcome`, `done`) and `first-import` are not — a `skip` call on a
non-skippable step is a no-op at the `SetupWizard` layer. The resume
resolver, the parent `SetupWizard`'s next/skip navigation, and the
progress-dots UI all walk this one registry.

## What this module is for

A fresh install is a cold start: no accounts, no transactions, no
recurring series, no chains. The wizard's job is to take the user
from "I just installed this" to "I have at least one month of data
visible on the dashboard" with as few decisions per step as possible.
Each connector step delegates the actual upload / OAuth handshake /
parse to the owning module (`Ingestion`, `EmailScan`, `Import`);
this module is the choreographer.

The starting-balance UX is a key sub-flow. After the first import,
the wizard surfaces a starting-balance confirm card for each account
the import touched, pre-filled from
`DetectStartingBalancesQuery` (the Import-owned aggregator). The
user confirms or overrides; the value lands on
`accounts.starting_balance_minor` (the Ledger-owned column).

What the module explicitly does NOT do:

- It never owns connector implementations. Each step is a
  Livewire SFC that mounts the relevant module's surface (the
  upload wizard, the OAuth wizard modal, the PayPal upload, the
  ICS upload).
- It never persists transactions itself. The first-import step
  delegates to `ConfirmImport` (Import); the per-account
  starting-balance write goes through Ledger.
- It never re-runs already-completed steps. The
  `ResumeStepResolver` is the authoritative resumer; a user who
  has completed step 3 lands on step 4 on every return until the
  wizard is fully complete.

## Module boundary

`Public/` exposes a tiny cross-module surface:

- **Services/**
  - `WizardProgressQuery::isComplete($user)`,
    `currentStep($user)` — read-only progress queries that
    other surfaces can consult (e.g. the dashboard suppresses
    its "welcome tour" tile when the wizard is complete).
- **Events/**
  - `WizardCompleted` — `(userId)` raised when the user finishes
    the wizard. No listener in v1.0.0; reserved for future
    surfaces that want to react.

`Internal/` houses the implementation:

- **Internal/Services/WizardStepRegistry** — the canonical
  ordered list of step IDs (`welcome` → `connect_bank` →
  `connect_card` → `connect_paypal` → `connect_email` →
  `first_import` → `done`). The wizard's navigation uses this
  registry; tests assert the order.
- **Internal/Services/WizardProgressInitializer** — seeds one
  `wizard_progress` row per registry step for a fresh install.
  Insert-only per `(user_id, step_key)` — `UserInstalled` is
  re-dispatched by `beatrax:install` on every re-run against an
  already-installed account, so the initializer must never demote a
  row that has already progressed past `pending`. A step added to the
  registry *after* a user already finished the wizard (every existing
  row `done`/`skipped`) seeds as `skipped`, not `pending` — otherwise
  the resume resolver would drop a finished user back into the wizard
  at the new step on their next visit. A fresh install or an
  in-progress user still gets the normal `pending` seed.
- **Internal/Services/ResumeStepResolver** — looks up the
  user's `wizard_progress` rows and resolves the step to mount:
  any `in_progress` row wins first (drops the user back exactly
  where they were mid-step); else the first `pending` step in
  registry order (keeps the wizard moving forward past
  completed/skipped steps); else the empty-string sentinel
  meaning every step is done or skipped (callers bounce to `/`).
  Filters explicitly by `user_id` rather than relying on
  `BelongsToUser`'s global scope, which falls through under
  unauthenticated CLI/queue/test contexts.
- **Internal/Listeners/InitializeWizardProgressOnInstall** —
  `UserInstalled` listener that runs the initializer.
- **Internal/Http/Livewire/SetupWizard** — the parent SFC
  that mounts the right step.
- **Internal/Http/Livewire/Steps/** — nine step SFCs
  (`WelcomeStep`, `ConnectBankStep`, `ConnectPaypalStep`,
  `ConnectCardStep`, `ConnectEmailStep`, `FirstImportStep`,
  `BudgetsStep`, `TaxCountryStep`, `DoneStep`). `BudgetsStep` and
  `TaxCountryStep` are optional, skippable steps added after the
  original six-step design: `BudgetsStep` assigns this month's
  envelope amounts per expense category (via the shared,
  ownership-checked `EnvelopeWriter::setAssigned()` keyed to
  `PeriodQuery::current()` — new users start in the envelope model
  from day one, never the retired `category_budgets` table);
  `TaxCountryStep` records the user's tax country for the deduction
  corpus. Both follow the same shape as the connector steps: a
  `continue` action that persists and bubbles `wizard.step.completed`,
  a `skip` action that bubbles `wizard.step.skipped`, with the parent
  `SetupWizard` owning the `wizard_progress` mutation and the advance.
- **Internal/Http/Livewire/StartingBalanceCard** — the per-
  account confirm card the first-import step surfaces.

The wizard owns a dedicated layout under
`Resources/views/layouts/app-wizard.blade.php` plus eleven
anonymous Blade components for the per-step UI shell.

## Key services + events

- `WizardStepRegistry::all()` — ordered step ids; the wizard's
  navigation uses this.
- `WizardProgressInitializer::initialize($user)` — INSERT the
  six per-user `wizard_progress` rows; idempotent on
  `UNIQUE(user_id, step_id)`.
- `ResumeStepResolver::resumeStep($user)` — returns the
  first incomplete step's id, or `'done'` if every step is
  complete.
- `WizardProgressQuery::isComplete($user)` /
  `currentStep($user)` — Public reads.
- `WizardCompleted` event — raised when the user lands on
  `DoneStep`.

## wizard_progress cross-user posture

Every production read/write path against `wizard_progress` carries an
explicit `where('user_id', ...)` filter through the raw query builder.
The `BelongsToUser` global scope on the `WizardProgress` model is a
secondary guard that only fires when an Eloquent query reaches the
model inside an HTTP-bound request — it stays wired for any future
Eloquent surface, but the explicit-filter rule is the one every
current collaborator actually relies on. `status` is constrained to
`pending`/`in_progress`/`done`/`skipped` by a paired BEFORE
INSERT/UPDATE trigger pair, so an application-layer typo fails loud at
the DB boundary. `data` carries per-step opaque payload (chosen
connector, OAuth provider name, uploaded filename, stashed ImportRun
ids) so the wizard resumes mid-flow after a relaunch without
re-prompting.

## Data flow

The first-install ceremony:

```
UserInstalled
  → InitializeWizardProgressOnInstall::handle
       → WizardProgressInitializer::initialize($user)
            → INSERT one wizard_progress row per WizardStepRegistry
              step (idempotent on UNIQUE(user_id, step_key))

GET /setup-wizard
  → SetupWizard::mount
       → re-run WizardProgressInitializer (idempotent safety net for
         a manual URL hit that raced UserInstalled)
       → if ?force=1: reset every row for this user to pending +
         completed_at=null (the "Settings → re-run setup" affordance)
       → ResumeStepResolver::resolve($user) → step key ('' = all
         done/skipped, bounce to /)
       → render the matching Step SFC
```

`SetupWizard` is the parent component; step advancement is driven by
Livewire events dispatched from the active step, not a parent-method
call chain: `wizard.step.completed` marks the current row `done`,
`wizard.step.skipped` marks it `skipped` (a no-op on non-skippable
steps per `WizardStepRegistry::isSkippable()`), and both advance to
the next registry step. `skipRest` marks every non-done row `skipped`
and redirects to `/` in one action — the wizard_progress rows still
record what the user did vs. dropped, so a later `/setup-wizard` hit
resumes at `done` rather than restarting. `goToStep()` guards against
client-side tampering (`wire:model="currentStepKey"`-style attacks):
the target step must be in the registry and every step before it must
already be `done` or `skipped` — a user can walk back to a completed
step but never jump ahead.

The connector step pattern (e.g. ConnectBankStep):

```
ConnectBankStep::mount
  → user picks a bank format (CSV / CAMT.053 / MT940)
  → user uploads file
  → call Import::RunImport::preview
  → render preview
  → user clicks Continue
       → call Import::ConfirmImport
       → stash the resulting ImportRun id on wizard_progress
       → mark step complete
       → advance to next step
```

The first-import step (after connector steps):

```
FirstImportStep::mount
  → BuildConsolidatedPreviewQuery::for($user, $stashedImportRunIds)
       → renders a consolidated multi-source preview
  → for each account touched:
       → DetectStartingBalancesQuery::for($file)
       → StartingBalanceCard::mount($accountId, $detected)
            → user confirms or overrides
            → write accounts.starting_balance_minor (Ledger)
  → user clicks Commit
       → call Import::ConfirmImport (the final commit)
       → mark first_import step complete

DoneStep
  → dispatch WizardCompleted
```

`ConnectBankStep` extends the generic connector pattern with one extra
step: when the parsed statement contains an IBAN the user has no
`accounts` row for yet, it auto-creates a bank account for each unknown
IBAN, then re-previews the same stored file so the preview cache
resolves against the new account. Without this, every row would
resolve to `UnknownAccount`, land in status `error`, and the
consolidated commit surface would show "0 rows" even though rows were
visible. A CSV format pick additionally requires a bank-format hint
(ASN/ING) — enforced both by the step's own validator and, as a
backstop, by the `RunsImports` public-contract boundary — since CSV
dialects aren't self-describing the way CAMT.053/MT940 are.

`ConnectCardStep` and `ConnectPaypalStep` follow the same
auto-create-then-re-preview pattern as `ConnectBankStep`, keyed to
their own synthetic IBAN literals (`ICS-CARD` for ICS, `PAYPAL` for
PayPal — the same literals `IcsPdfAdapter`/`PaypalCsvAdapter` stamp on
every row and the alias bridge in `known_counterparty_ibans` maps to).
`ConnectCardStep` additionally accepts multiple PDF uploads per submit
(Mijn ICS only exports one month per PDF, so users typically download
several at once) and is tolerant of partial failure: a per-file parse
error is logged and skipped rather than aborting the whole submit,
and the step only surfaces a blocking error when every file failed.

`ConnectEmailStep` is the odd one out among connector steps — it has
no file upload and holds no secrets state itself. Gmail/Microsoft 365
OAuth lives entirely in
`Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal`,
mounted globally by the wizard layout; this step is a thin event
router: `authorizeProvider()` validates the provider against a closed
allow-list and dispatches `oauth-client-wizard:open`, the modal runs
the OAuth dance and dispatches `oauth-client-wizard:saved` back, and
this step's listener turns that into `wizard.step.completed`. Email is
the canonical "optional" step — skip is the most common exit path,
and the user can always connect later from Settings.

`ConnectPaypalStep` collects a single PayPal Rapport Transactiegegevens
CSV (an arbitrary user-chosen date range; additional CSVs go through
the standalone `/imports/upload` flow later). Its account-before-preview
ordering is load-bearing: it calls `EnsurePaypalAccountAction` to
idempotently create the synthetic `PAYPAL`-IBAN account **before**
running `RunsImports::runFromUpload()`, because the import pipeline
tags every row `error` when `AccountResolver` returns `UnknownAccount`
— inverting the order would cache an all-error preview on the first
pass and the consolidated `FirstImportStep` section would render
`READY · 0 ROWS`.

A second guard handles the pipeline's typed-parse-exception contract:
`ImportPipeline` converts a parse-time exception
(`UnsupportedPaypalCsvShapeException`, `UnsupportedPaypalCsvLanguageException`,
`SniffMismatchException`) into a single error-status `PreviewRowDto`
at row 0 rather than re-raising, so `runFromUpload()` returns normally
with a non-empty rows list — from the caller's view, a "successful"
preview. `ConnectPaypalStep::fatalParseMessage()` inspects the
returned preview and treats it as a non-advancing failure only when
**every** row is `error` and there are no unknown-IBAN naming prompts
(a mix of error and committable rows is a legitimate rare per-row
failure, not a fatal parse); on a fatal parse it surfaces the first
error row's message instead of stashing the poisoned import-run id.

## FirstImportStep — the consolidated commit surface

`FirstImportStep` reads every connector step's stashed ImportRun ids
back out of `wizard_progress.data` (bank writes one int, PayPal writes
one int, card writes an int array), unions and dedupes them preserving
insertion order (bank → PayPal → ICS card → email, so
`BuildConsolidatedPreviewQuery` renders sections in that deterministic
order), and feeds the same id list into `DetectStartingBalancesQuery`
to produce one `StartingBalanceCandidate` per detected account (two
candidates for the same account means a conflict — the blade groups by
`accountId` and switches that card into the conflict variant).

`commitEverything()` — named to avoid Livewire 3's reserved `$commit`
magic state-sync action, which would silently no-op a method literally
named `commit` — wraps every stashed run's `ConfirmImport(...,
dispatchChain: false)` call, every `accounts.starting_balance_*`
UPDATE, and the `wizard_progress` `first-import` row's `done` flip
into one `DB::transaction()`, so the commit is truly atomic: either
everything lands or nothing does. The preview is rebuilt at commit
time (Livewire invokes action methods without running `render()`
first) using only `ready`-status sections, so a half-broken upload
doesn't take the whole batch down. Chain resolution and recurring
detection are dispatched once, **after** the transaction commits —
their failure does not undo committed data; it just means the next
scheduled sweep catches up.

## StartingBalanceCard — the per-account state machine

The first-import step mounts one `StartingBalanceCard` per detected
account (plus one per account that needs manual entry). Each card
owns its own state machine and emits `starting-balance.confirmed`
back to the parent step whenever the user accepts a value; the parent
aggregates every emitted payload into a per-account map that the
commit-everything button writes to `accounts.starting_balance_*`
inside the same transaction that confirms the stashed ImportRuns.

- `detected` — one detector fired with a non-zero value (the common
  case): pre-filled value, a Confirm pill, a quiet Edit affordance.
- `conflict` — two detectors disagreed on the same account: a
  radio-button list of alternative candidates, earliest-date
  pre-selected.
- `editing` — the user clicked Edit (or started a manual entry):
  inline number + date inputs with Cancel + Save.
- `confirmed` — the user accepted a value: the card collapses to a
  single-line summary with a Change link.
- `manual-entry` — no detector fired for this account: the card mounts
  in an editing-shaped state with empty inputs and a lede explaining
  the auto-detect miss.

`save()` range-checks the minor amount (±€10M), requires a parseable
non-future ISO date, and — reading `transactions.posted_at` with an
explicit `user_id` predicate even though `BelongsToUser`'s global
scope would already cover it — surfaces an informational warning (not
a blocking error) when the accepted date is later than the account's
earliest imported transaction.
