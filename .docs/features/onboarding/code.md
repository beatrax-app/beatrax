# `Onboarding` — code

The file-level map for the module.

## Directory layout

```
Modules/Onboarding/
├── Public/
│   ├── Events/
│   │   └── WizardCompleted.php
│   └── Services/
│       └── WizardProgressQuery.php
├── Internal/
│   ├── Services/
│   │   ├── WizardStepRegistry.php
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
│       └── components/        (11 anonymous components for the
│                                wizard UI shell)
├── Providers/
│   └── OnboardingServiceProvider.php
└── tests/
    ├── Unit/
    └── Feature/
```

## Public API

- **Services/**
  - `WizardProgressQuery::isComplete(User $user): bool` —
    "is the user past `done`?".
  - `WizardProgressQuery::currentStep(User $user): string` —
    "what step would the wizard land on right now?". Returns
    `'done'` when complete.
- **Events/**
  - `WizardCompleted` — `(int $userId)`. Raised by `DoneStep`
    when the user lands there for the first time.

## Internal services

- `Internal/Services/WizardStepRegistry::all(): list<string>`
  — the canonical ordered list of step ids.
- `Internal/Services/WizardProgressInitializer::initialize(User
  $user): void` — inserts six `wizard_progress` rows;
  idempotent on `UNIQUE(user_id, step_id)`.
- `Internal/Services/ResumeStepResolver::resumeStep(User
  $user): string` — returns the first incomplete step's id
  or `'done'`.
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
- `Internal/Http/Livewire/Steps/DoneStep` — dispatches
  `WizardCompleted`; offers a single "Take me to the
  dashboard" button.
- `Internal/Http/Livewire/StartingBalanceCard` — per-account
  starting-balance confirm card.

## Models + migrations

- `Models/WizardProgress` — maps to `wizard_progress`. Uses
  `BelongsToUser`. Columns: `(user_id, step_id, completed_at,
  meta)`. The `meta` JSON column carries per-step state
  (stashed `ImportRun` ids for the consolidated preview;
  per-account starting-balance picks).

Migrations:

- `2026_05_26_000001_create_wizard_progress_table.php` —
  initial create with `UNIQUE(user_id, step_id)`.

## Provider wiring

`OnboardingServiceProvider::register()`:

- Singletons every Internal service + the Public query.

`OnboardingServiceProvider::boot()`:

- Loads migrations.
- Loads routes + views (file-/dir-existence guarded).
- Subscribes `InitializeWizardProgressOnInstall` to
  `Core::UserInstalled`.
- Registers nine Livewire components under the `onboarding.*`
  alias namespace (the parent `SetupWizard`, the
  `StartingBalanceCard`, and the seven step SFCs).
