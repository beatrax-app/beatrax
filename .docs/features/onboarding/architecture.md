# `Onboarding` — architecture

The `Onboarding` module hosts the first-run setup wizard at
`/setup-wizard`. It walks a freshly-installed user through six steps
— welcome → connect bank → connect card → connect PayPal → connect
email → first import → done — tracks per-step progress in the
`wizard_progress` table, and resumes from the right step on every
subsequent visit until completion.

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
- **Internal/Services/WizardProgressInitializer** — seeds the
  six per-user `wizard_progress` rows on first install.
- **Internal/Services/ResumeStepResolver** — looks up the
  user's `wizard_progress` rows and returns the first
  incomplete step's id.
- **Internal/Listeners/InitializeWizardProgressOnInstall** —
  `UserInstalled` listener that runs the initializer.
- **Internal/Http/Livewire/SetupWizard** — the parent SFC
  that mounts the right step.
- **Internal/Http/Livewire/Steps/** — seven step SFCs
  (`WelcomeStep`, `ConnectBankStep`, `ConnectCardStep`,
  `ConnectPaypalStep`, `ConnectEmailStep`, `FirstImportStep`,
  `DoneStep`).
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

## Data flow

The first-install ceremony:

```
UserInstalled
  → InitializeWizardProgressOnInstall::handle
       → WizardProgressInitializer::initialize($user)
            → INSERT 6 wizard_progress rows (idempotent on
              UNIQUE(user_id, step_id))

GET /setup-wizard
  → SetupWizard::mount
       → ResumeStepResolver::resumeStep($user) → step id
       → render the matching Step SFC
```

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
