# `Onboarding` — code

The file-level map for the module.

## Directory layout

```
Modules/Onboarding/
├── Internal/
│   ├── Enums/
│   │   └── WizardStepStatus.php
│   ├── Events/
│   │   └── WizardCompleted.php
│   ├── Exceptions/
│   │   └── EveryStagedRunWasRefusedException.php
│   ├── Services/
│   │   ├── WizardStepRegistry.php
│   │   ├── WizardProgressQuery.php
│   │   ├── WizardProgressInitializer.php
│   │   └── ResumeStepResolver.php
│   ├── Listeners/
│   │   └── InitializeWizardProgressOnInstall.php
│   └── Http/Livewire/
│       ├── SetupWizard.php
│       ├── StartingBalanceCard.php
│       └── Steps/
│           ├── WelcomeStep.php
│           ├── ConnectBankStep.php
│           ├── ConnectCardStep.php
│           ├── ConnectPaypalStep.php
│           ├── ConnectEmailStep.php
│           ├── FirstImportStep.php
│           ├── BudgetsStep.php
│           ├── CountryStep.php
│           └── DoneStep.php
├── Models/
│   └── WizardProgress.php
├── Database/
│   ├── Migrations/
│   │   └── 2026_05_26_000001_create_wizard_progress_table.php
│   └── Seeders/
│       └── Demo/DemoWizardProgressSeeder.php
├── Routes/
│   └── web.php
├── Resources/
│   └── views/
│       ├── layouts/
│       │   └── app-wizard.blade.php
│       ├── livewire/
│       │   ├── setup-wizard.blade.php
│       │   ├── starting-balance-card.blade.php
│       │   └── steps/...
│       └── components/        (12 anonymous components for the
│                                wizard UI shell)
├── Providers/
│   └── OnboardingServiceProvider.php
└── tests/
    ├── Unit/
    └── Feature/
```

## Module surface

This module has **no `Public/` directory**. Nothing outside
`Onboarding` resolves one of its services, listens to its event or
reads `wizard_progress` — the wizard is a self-contained screen,
and the only inbound edge is the `UserInstalled` listener. Treat
every class below as internal.

- `Internal/Services/WizardStepRegistry::steps(): list<string>` —
  the canonical ordered list of step keys: `welcome`,
  `connect-bank`, `connect-paypal`, `connect-card`,
  `connect-email`, `first-import`, `budgets`, `tax-country`,
  `done`. `WizardStepRegistry::isSkippable($stepKey)` gates
  `SetupWizard::skip()`; every step but `welcome` and `done` is
  skippable, and a key missing from that list gives a skip button
  that dispatches and goes nowhere — how `first-import` shipped
  once.
- `Internal/Services/WizardProgressQuery::list(int $userId):
  array<string, array{status: string, completed_at: ?string}>` —
  one entry per registry step, in registry order, falling back to
  `pending`/null for a step with no row so a partially-seeded user
  still renders a coherent progress strip. There is no
  `isComplete` and no `currentStep`: "is the user done?" is read
  off this map by the caller, and "where does the wizard land?" is
  `ResumeStepResolver`'s question.
- `Internal/Services/WizardProgressInitializer::initialize(int
  $userId): void` — inserts one `wizard_progress` row per registry
  step that has none, inside one transaction. It is idempotent by
  reading the existing rows first rather than by leaning on
  `UNIQUE(user_id, step_key)`, because it also has to decide the
  seed status: a step added after a user finished the wizard seeds
  as `skipped`, not `pending`, so shipping a new step does not
  re-open a closed wizard.
- `Internal/Services/ResumeStepResolver::resolve(int $userId):
  string` — the first `in_progress` step key the jump gate
  admits, else the earliest such `pending` one, else `''`. The
  empty string is the "nothing left to resume" answer; it is not
  the `done` step key.
- `Internal/Services/WizardStepRegistry::isReachable(string
  $stepKey, array $progress): bool` — the jump gate itself, in
  its one home. True when the key is in `steps()` and every key
  before it is `done` or `skipped`.
- `Internal/Events/WizardCompleted` — `(int $userId)`, dispatched
  by `DoneStep::finish()` before it redirects to `/`. Nothing
  listens to it today; it exists as the seam.
- `Internal/Exceptions/EveryStagedRunWasRefusedException` —
  `(int $runsOffered)`, raised inside `commitEverything()`'s
  transaction when the confirm took none of the runs it was
  offered, so the rollback keeps the step off `done`.
- `Internal/Listeners/InitializeWizardProgressOnInstall::handle(UserInstalled
  $event)` — runs the initializer for the just-installed user.
- `Internal/Http/Livewire/SetupWizard` — parent SFC. Resolves
  the resume step and mounts the matching child.
- `Internal/Http/Livewire/Steps/WelcomeStep` — pure-UI step;
  intro + "Let's begin".
- `Internal/Http/Livewire/Steps/ConnectBankStep` — format
  picker (CSV / CAMT.053 / MT940) + upload; delegates parse +
  preview to Import.
- `Internal/Http/Livewire/Steps/ConnectCardStep` — multi-file
  ICS PDF upload; stashes every produced `ImportRun.id` for the
  consolidated preview later.
- `Internal/Http/Livewire/Steps/ConnectPaypalStep` — PayPal CSV
  upload; offers reuse of an existing PayPal Account row.
- `Internal/Http/Livewire/Steps/ConnectEmailStep` — dispatches
  a global `oauth-wizard:open` browser event so the
  `EmailScan::OAuthClientWizardModal` is the source of truth for
  the connect UI. The step is the link, not the wizard.
- `Internal/Http/Livewire/Steps/FirstImportStep` — the
  consolidated multi-source preview, the per-account
  starting-balance cards, the final Commit button.
- `Internal/Http/Livewire/Steps/BudgetsStep` — the `budgets`
  step; first-run budget setup.
- `Internal/Http/Livewire/Steps/CountryStep` — the `tax-country`
  step. The step key and the class name differ on purpose: the
  key names what the answer is for, the class names the question.
- `Internal/Http/Livewire/Steps/DoneStep` — dispatches
  `WizardCompleted`; offers a single "Take me to the
  dashboard" button.
- `Internal/Http/Livewire/StartingBalanceCard` — per-account
  starting-balance confirm card.

## Models + migrations

- `Models/WizardProgress` — maps to `wizard_progress`. Uses
  `BelongsToUser`. Columns: `(user_id, step_key, status, data,
  completed_at)`. The `data` JSON column carries per-step state
  (stashed `ImportRun` ids for the consolidated preview;
  per-account starting-balance picks). The key is `step_key`,
  never `step_id`, and the model is what a reader should reach
  for; the services themselves go through the query builder.

Migrations:

- `2026_05_26_000001_create_wizard_progress_table.php` —
  initial create with `UNIQUE(user_id, step_key)` and an
  `INDEX(user_id, status)` for the per-user status map every
  wizard request reads before it resolves a step.
  It also installs two SQLite triggers rejecting a `status`
  outside `pending` / `in_progress` / `done` / `skipped`, so the
  enum is enforced by the database and not only by PHP.

## Provider wiring

`OnboardingServiceProvider::register()`:

- Singletons the four Internal services (`WizardStepRegistry`,
  `WizardProgressInitializer`, `ResumeStepResolver`,
  `WizardProgressQuery`). None of them is bound to a contract,
  because none of them is reachable from another module.

`OnboardingServiceProvider::boot()`:

- Loads migrations, routes and views through
  `loadModuleResources('onboarding')`.
- Subscribes `InitializeWizardProgressOnInstall` to
  `Core::UserInstalled`.
- Registers eleven Livewire components under the `onboarding.*`
  alias namespace: the parent `SetupWizard`, the
  `StartingBalanceCard`, and the nine step SFCs.
