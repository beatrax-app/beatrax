# `Onboarding` — architecture

The `Onboarding` module hosts the first-run setup wizard at
`/setup-wizard`. It walks a freshly-installed user through nine steps
— `welcome` → `connect-bank` → `connect-paypal` → `connect-card` →
`connect-email` → `first-import` → `budgets` → `tax-country` → `done`
— tracks per-step progress in the `wizard_progress` table, and resumes
from the right step on every subsequent visit until completion.

`WizardStepRegistry` is the single source of truth for this order and
for which steps are skippable: the bookend steps (`welcome`, `done`)
are the only ones that are not — a `skip` call on a non-skippable step
is a no-op at the `SetupWizard` layer. Every other step is skippable,
`first-import` included: its commit button is disabled whenever nothing
is staged, which is the ordinary state for someone who skipped all four
connectors, so skip is that step's only enabled way forward. The button
and the registry entry are two halves of one feature — first-import once
shipped with the button but no entry, and the click round-tripped 200 OK
and went nowhere. The resume resolver, the parent `SetupWizard`'s
next/skip navigation, and the progress-dots UI all walk this one
registry.

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

This module has NO `Public/` directory. That is deliberate and
it is the reverse of an earlier plan: a `Public/Services`
holding `WizardProgressQuery` with `isComplete($user)` and
`currentStep($user)` was described for other surfaces to
consult, and neither method nor the directory was ever built.
Nothing outside the module needs to know where a user is in the
wizard — the surfaces that were going to ask (the dashboard's
"welcome tour" tile) resolve their own state instead, and a
cross-module read of wizard progress would make every step's
storage shape a published contract. If that need returns, it
should arrive as a contract with a named consumer, not as a
query left open in case someone wants it.

`WizardCompleted` — `(userId)`, dispatched by `DoneStep` when
the user lands on the final step — lives at
`Internal/Events/WizardCompleted` for the same reason. It has
no listener; a subscriber outside this module would be the
thing that forces the event onto a Public surface.

`Internal/` is therefore the whole module:

- **Internal/Services/WizardStepRegistry** — the canonical
  ordered list of step keys, nine of them (`welcome` →
  `connect-bank` → `connect-paypal` → `connect-card` →
  `connect-email` → `first-import` → `budgets` → `tax-country`
  → `done`), reachable through `steps()`. It also answers
  `isSkippable($stepKey)` for the seven non-bookend steps —
  `SetupWizard::skip()` gates on that list, so a step missing
  from it renders a skip button that dispatches and goes
  nowhere, which is how `first-import` shipped once. The
  wizard's navigation uses this registry; tests assert the
  order.
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
  `BudgetsStep`, `CountryStep`, `DoneStep`). `BudgetsStep` and
  `CountryStep` are optional, skippable steps added after the
  original six-step design: `BudgetsStep` assigns this month's
  envelope amounts per expense category (via the shared,
  ownership-checked `EnvelopeWriter::setAssigned()` keyed to
  `PeriodQuery::current()` — new users start in the envelope model
  from day one);
  `CountryStep` records the user's country preference through
  `Modules\Core\Public\Services\UserCountry`, which raises
  `UserCountryChanged`; Tax listens for that event and seeds the
  deduction corpus, so the wizard never has to know Tax exists. Its
  options come from `x-core::country-options`, the same list signup and
  Settings draw — the step used to write its own, under its own lang key,
  and had already drifted in German. That component is also what makes the
  stored country the *selected* option on a re-run; `mount()` had loaded it
  into `countryCode` while the select still drew the placeholder. The
  step key persisted in `wizard_progress` is still `tax-country`,
  because renaming it would need a data migration for no gain. Both follow the same shape as the connector steps: a
  `continue` action that persists and bubbles `wizard.step.completed`,
  a `skip` action that bubbles `wizard.step.skipped`, with the parent
  `SetupWizard` owning the `wizard_progress` mutation and the advance.
- **Internal/Http/Livewire/StartingBalanceCard** — the per-
  account confirm card the first-import step surfaces.

The wizard owns a dedicated layout under
`Resources/views/layouts/app-wizard.blade.php` plus twelve
anonymous Blade components for the per-step UI shell.

## Key services + events

- `WizardStepRegistry::steps()` — the ordered step keys; the
  wizard's navigation uses this. `isSkippable($stepKey)` is the
  other half of the registry.
- `WizardProgressInitializer::initialize($userId)` — takes the
  id, not the `User`. INSERTs one `wizard_progress` row per
  registry step (nine today) for rows the user does not already
  have. Idempotence is by reading the existing rows first and
  inserting only the gaps, with `UNIQUE(user_id, step_key)` as
  the backstop rather than the mechanism — the seed STATUS
  depends on what is already there, so a blind upsert would get
  it wrong: a step added after a user finished the wizard seeds
  `skipped`, not `pending`.
- `ResumeStepResolver::resolve($userId)` — returns the step key
  to mount: any `in_progress` row first, else the first
  `pending` step in registry order, else the empty string. It
  does NOT return `'done'` as a sentinel — `done` is a real
  step key in the registry, so a sentinel spelled the same way
  could not be told from "mount the done step". The empty
  string means every step is done or skipped, and `SetupWizard`
  redirects to `/` on it.
- `WizardProgressQuery::list($userId)` — the per-step status
  map the progress strip renders, defaulting an absent row to
  `pending` so a partially-seeded user still gets a coherent
  strip. Internal, and consumed only by `SetupWizard`.
- `WizardCompleted` event — carries the user id, dispatched by
  `DoneStep::finish()` just before it redirects to `/`. Nothing
  listens to it today; it is the seam, not a wiring.

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

## Leaving the wizard

The wizard has two exits and they mean different things.

`SetupWizard::leaveForNow()` is the "Resume later" affordance in
`.wiz-top`. It writes nothing: it redirects to `/` and leaves
`wizard_progress` exactly as it stands, so `ResumeStepResolver` still
finds the first pending step on the next visit and `isResuming` raises
the resume banner. It used to mark every not-done row `skipped`, which
abandoned parsed transactions that were staged and waiting for the commit
step, under a control whose aria-label says it "saves your progress".

The per-step "Skip this step" control is the other exit, and that one
does mark its own row `skipped` — through `SetupWizard::skip()`, gated
on `WizardStepRegistry::isSkippable()`.

Once every row is `done` or `skipped` there is nothing to resume.
`mount()` then renders the terminal step — `WizardStepRegistry::lastStep()`
with `allComplete` set — rather than redirecting. A redirect from
`mount()` is not available here: Livewire's `redirect()` calls
`skipRender()`, and on the NativePHP mobile runtime the abort that is
supposed to turn that into a 302 does not fire, so the route answered
200 and the layout painted around an empty slot.

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
       → if ?force=1 AND the relative signature validates: reset
         every row for this user to pending + completed_at=null
         (the "Settings → re-run setup" affordance, which signs
         its link); unsigned, it is logged and ignored
       → ResumeStepResolver::resolve($user) → step key ('' = all
         done/skipped, bounce to /)
       → render the matching Step SFC
```

`SetupWizard` is the parent component; step advancement is driven by
Livewire events dispatched from the active step, not a parent-method
call chain: `wizard.step.completed` marks the current row `done`,
`wizard.step.skipped` marks it `skipped` (a no-op on non-skippable
steps per `WizardStepRegistry::isSkippable()`), and both advance to
the next registry step. There is no bulk skip: `leaveForNow()` writes
nothing at all (see "Leaving the wizard" below), so the wizard_progress
rows keep recording what the user did versus dropped and a later
`/setup-wizard` hit resumes where they stopped. `goToStep()` guards against
client-side tampering (`wire:model="currentStepKey"`-style attacks):
the target step must be in the registry and every step before it must
already be `done` or `skipped` — a user can walk back to a completed
step but never jump ahead.

The connector step pattern (e.g. ConnectBankStep):

```
ConnectBankStep::mount
  → user picks a bank format (CSV / CAMT.053 / MT940)
  → user uploads file
  → call Import::RunsImports::runFromUpload($tmp, $format,
                                             $user, $filename,
                                             $formatHint)
  → render preview
  → user clicks Continue
       → call Import::ConfirmImport
       → stash the resulting ImportRun id on wizard_progress
       → mark step complete
       → advance to next step
```

The first-import step (after connector steps):

```
FirstImportStep::render
  → BuildConsolidatedPreviewQuery::build($stashedImportRunIds,
                                         $user, $sectionLimits)
       → renders a consolidated multi-source preview
  → DetectStartingBalancesQuery::collect($stashedImportRunIds,
                                         $user)
       → per account touched:
            → StartingBalanceCard ($accountId, $detected)
            → user confirms or overrides
            → write accounts.starting_balance_minor (Ledger)
  → user clicks Commit
       → ConfirmsImports per ready run (the final commit)
       → mark the first-import step done

DoneStep
  → dispatch WizardCompleted
```

### Auto-create the account, then re-preview

`ConnectBankStep` extends the generic connector pattern with one extra
step: when the parsed statement contains an IBAN the user has no
`accounts` row for yet, it auto-creates a bank account for each unknown
IBAN, then re-previews the same stored file so the preview cache
resolves against the new account. Without this, every row would
resolve to `UnknownAccount`, land in status `error`, and the
consolidated commit surface would show "0 rows" even though rows were
visible. The account is named after the CSV layout the user picked,
taking the label from the preset registry; CAMT.053 and MT940 name no
issuer, so an account created from one gets a neutral translated name
rather than a bank the user never chose. A CSV format pick
additionally requires a layout pick — enforced both by the step's own
validator and, as a backstop, by the `RunsImports` public-contract
boundary — since CSV dialects aren't self-describing the way
CAMT.053/MT940 are.

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

### What each connector step can actually read

The wizard is the first screen a new install shows, so a format it does
not offer is a capability the product has and never mentions. Two of the
three upload steps are narrower than the app, and only one of those two
is narrow by accident.

- **`ConnectBankStep`** offers CAMT.053, MT940, and every layout
  `CsvPresetRegistry::allLayouts()` holds. The CSV list is read from the
  registry rather than written out in the step: it used to be a
  hand-written constant naming ASN and ING NL only, while the registry —
  and the `/imports` screen that reads it through `ImportType::Csv` —
  already carried N26 and Revolut. A reader banking outside the
  Netherlands was shown two Dutch banks and a `Continue` that imported
  their file as CAMT.053. Adding a preset now offers it in the wizard;
  the registry holds bank presets only, so nothing has to exclude
  PayPal's own CSV. CAMT.053 and MT940 are ISO 20022 / SWIFT interchange
  formats and are not country-scoped in any way — nothing in
  `Modules/Ingestion` gates a format on a country.

- **`ConnectPaypalStep`** reads the per-event activity download and not
  the balance report, and — the part the copy has to carry —
  `PaypalCsvLanguageProfile` registers exactly one language signature,
  `nl`. PayPal names its reports in the account holder's own language,
  so a German or Estonian reader's export is refused at the sniffer with
  `UnsupportedPaypalCsvLanguageException`. The step therefore names the
  report by what it is, gives the Dutch names as `lang="nl"`-tagged
  helpers, and says outright that the Dutch export is the one read
  today. Registering a second signature is what makes that copy stale.

- **`ConnectCardStep`** is named for the category and states its issuer
  in the body. `IcsPdfAdapter` parses the Dutch-language Mijn ICS
  statement — Dutch month names and abbreviations, `Af`/`Bij` amount
  markers, EUR settlement — so the reach is one issuer's one layout
  whatever markets that issuer serves. A reader on another issuer is
  told to skip the step rather than left to discover it by uploading.

`asn-csv` and `ing-nl-csv` are genuinely one Dutch bank's layout each and
are named as such; `n26-csv` and `revolut-csv` are pan-European issuers.
There is no free-form column-mapping path: `GenericCsvAdapter` is the
engine the header-name presets run on, not a layout a reader can
configure, so a bank with no preset is directed at CAMT.053 or MT940.

`ConnectEmailStep` is the odd one out among connector steps — it has
no file upload and holds no secrets state itself. Gmail/Microsoft 365
OAuth lives entirely in
`Modules\EmailScan\Public\Http\Livewire\OAuthClientWizardModal`,
mounted globally by the wizard layout; this step is a thin event
router: `authorizeProvider()` validates the provider against a closed
allow-list and dispatches `oauth-client-wizard:open`, the modal runs
the OAuth dance and dispatches `oauth-client-wizard:saved` back, and
this step's listener turns that into `wizard.step.completed`. Email is
the canonical "optional" step — skip is the most common exit path,
and the user can always connect later from Settings.

`ConnectPaypalStep` collects a single PayPal Rapport Transactiegegevens
CSV (an arbitrary user-chosen date range; additional CSVs go through
the standalone `/imports/new` flow later). Its account-before-preview
ordering is load-bearing: it calls `EnsurePaypalAccountAction` to
idempotently create the synthetic `PAYPAL`-IBAN account **before**
running `RunsImports::runFromUpload()`, because the import pipeline
tags every row `error` when `AccountResolver` returns `UnknownAccount`
— inverting the order would cache an all-error preview on the first
pass and the consolidated `FirstImportStep` section would render
`READY · 0 ROWS`.

### Fatal-parse detection on the PayPal step

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
