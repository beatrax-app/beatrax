# Module boundaries

Beatrax is structured as thirty-five bounded modules under `Modules/`. Each module
owns a slice of the domain, exposes a narrow public surface, and is forbidden
from reaching into another module's internals. This document names the
modules, describes the shape of the boundary, and lists the arch invariants
that enforce it.

The choice to use `nwidart/laravel-modules` as the structural carrier is
recorded in [ADR 0001](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0001-modular-architecture.md). The DI-only rule
that operates inside each module is recorded in
[ADR 0002](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0002-di-only-rule.md). This file describes the layout those
two decisions produced.

## The modules

| Module | What it owns |
| --- | --- |
| `Anomaly` | Per-transaction unusual-charge detection (large-vs-typical, first-time merchant, duplicate-charge) surfaced on `/drift`, with its own state machine, audit-transition table, and suppression rules |
| `Auth` | Username/password auth, recovery codes, OAuth-secret repository, owner-resets-partner flow, the `force_password_change` posture |
| `Budgets` | Per-category monthly spending budgets and progress tracking |
| `Calendar` | Month-grid calendar of transactions and scheduled charges (`CalendarQuery`) |
| `CashBook` | Manual / cash transaction entry into the canonical ledger |
| `Categorization` | Rule-based auto-categorization, per-user merchant memory, the categorization-rules CRUD surface, the receipt-vs-statement enrichment conflict resolver |
| `Chains` | PayPal→funder + ICS bulk-iDEAL settlement chain resolution, the `chain_links` ledger, the per-user `ShouldBeUniqueUntilProcessing` resolver job |
| `Community` | Optional community-merchant-mapping dataset opt-in toggles + corpus distribution |
| `Core` | Users + sessions + system alerts + user preferences; the `BelongsToUser` trait; the `diederik:doctor` and `db:backup` console commands. The kernel every module depends on — it owns no screen that reads across modules (see `Shell`) |
| `Counterparties` | Counterparty resolution pipeline + index/profile/triage surfaces (`/counterparties`) |
| `Desktop` | NativePHP shell glue — the entire `Native\Laravel\*` import surface lives here and nowhere else |
| `DevMode` | Developer-mode gate + dev-console pages (logs, queue, audit, doctor, palette) |
| `DriftAlerts` | Drift detection over recurring series, drift-alert state machine, acknowledge/snooze/what-if-cancel actions |
| `EmailScan` | Gmail + Microsoft Graph OAuth, per-inbox UID-resume scan state, the inbox-scan state machine, `.eml`/`.mbox` drop-in |
| `Forecasting` | 30/60/90-day cash-flow projections, scenario mutations (non-persistent), R-7 percentile bands, shortfall windows |
| `FX` | Multi-currency exchange-rate infrastructure and base-currency conversion |
| `Goals` | Savings goals funded by a linked pot or explicitly attributed transactions, with projected finish dates |
| `Import` | The ImportPipeline orchestrator + per-format adapters (ASN CSV/CAMT/MT940, ICS PDF, PayPal CSV), the preview wizard |
| `Ingestion` | The canonical-transaction DTO + source-adapter registry + statement-summary writer + account-resolver contract |
| `Ledger` | Transactions, accounts, categories, merchants, import runs, currencies, statement summaries — the canonical store |
| `Migration` | One-time migration wizard importing a full budget file (YNAB4, nYNAB, or Actual Budget) into Beatrax's envelope model |
| `Mobile` | Mobile client peer (NativePHP-for-Mobile): on-device encrypted SQLite, client-only sync transport (LAN-direct + relay), biometric app-lock, resumable initial sync |
| `Notifications` | Persistent, deduplicated notification store (deterministic sha256 PK from trigger+subject+occurrence), the `/notifications` inbox, per-device preferences, quiet-hours-defer, proactive + reactive triggers |
| `Onboarding` | First-run wizard progress tracking + the multi-step onboarding flow |
| `OpenBanking` | Optional, off-by-default open-banking connector — links ASN/SNS via the Enable Banking aggregator (BYO-key) and lands booked transactions + balances idempotently through the import-preview pipeline |
| `Position` | The single Public definition of "your current position" (net worth + budgets + upcoming charges + shortfalls), composed from other modules' Public seams; register-only, no routes or views |
| `Pots` | Virtual savings pots / envelope allocations over real account balances |
| `Receipts` | Receipt-vs-statement matching (PayPal/ICS/Google-Play receipt parsers), the file-imports table, the matcher-key indexing |
| `Recurring` | Recurring-series detection (any cadence), the always-suggest-never-auto-apply state machine, the per-series acknowledgement |
| `Reports` | User-composable report builder (metric × dimension × period × filters × currency × viz) with saved/pinned reports |
| `Search` | Full-text transaction search and entity-name navigation via an FTS5 trigram index and the ⌘K palette |
| `Shell` | The application's own screens — the primary navigation, the dashboard, and the settings page, plus the net-worth and spending-trend cards. It composes every other module rather than owning a domain slice, which is why it is the one module nothing else depends on |
| `Sync` | The CRDT op-log / HLC merge layer — append-only, per-device-signed ops with HLC ordering, LWW-per-field, tombstones, and import dedup over the migrated SQLite schema |
| `Tax` | Tax-deductible tagging, per-year categorisation, and CSV/PDF export for Dutch IB/OB tax filing |
| `Transfers` | Self-transfer detection across accounts, transfer-pair resolution |

## The Public / Internal / Models split

Every module has a fixed directory layout:

```
Modules/<Name>/
├── Public/         ← contracts, DTOs, events, facades other modules MAY import
│   ├── Contracts/  ← interfaces the module's owners promise to keep stable
│   ├── Dto/        ← spatie/laravel-data DTOs other modules consume
│   ├── Events/     ← events other modules MAY listen for
│   └── Services/   ← concrete services other modules MAY inject
├── Internal/       ← actions, jobs, listeners, parsers, pipeline stages — NEVER imported elsewhere
│   ├── Actions/
│   ├── Console/
│   ├── Http/
│   ├── Jobs/
│   ├── Listeners/
│   ├── Pipeline/
│   └── ...
├── Models/         ← Eloquent models; other modules MAY use directly (see ADR 0002)
├── Database/
│   ├── Migrations/
│   ├── Seeders/
│   └── Factories/
├── Routes/         ← web.php / api.php — each module owns its own URL surface
├── Resources/      ← views, lang files, brand assets owned by the module
├── Providers/      ← the module's service provider (wires DI bindings, registers schedules, boots Livewire components)
└── tests/          ← Unit/, Feature/, Contracts/, Arch/ — module-owned, run by the same Pest CLI
```

The rule is simple: a module MAY import from another module's `Public/` and
`Models/` namespaces; a module MAY NOT import from another module's `Internal/`
namespace. Cross-module behaviour goes through the importing module's
`Public/Contracts/`: it declares the interface; the owning module implements
it; the binding is wired in the owning module's service provider.

A worked example: the `Import` module needs to apply auto-categorization to
a freshly-parsed canonical transaction. `Import` declares no Categorization
internals; it injects the `AppliesAutoCategory` contract from
`Modules\Categorization\Public\Contracts\` and calls its `apply()` method.
`Categorization` implements that contract inside
`Modules/Categorization/Internal/Pipeline/ApplyAutoCategoryStage.php`. The
import pipeline never knows or cares which class implements the contract; the
arch tests guarantee `Import` never reaches into `Categorization/Internal/`
directly.

## The arch invariants that hold the line

`tests/Contracts/BoundaryArchTest.php` ships the arch invariants that guard the
module-boundary contract. Selected examples:

- **`pinnedCrossModuleInternalImports`** — the import half of the boundary. It
  scans `Modules/`, `tests/` and `app/` textually for a `use` of another
  module's `Internal\`, and asserts the result equals two pinned literal lists:
  a production list holding exactly the `Mobile` → `Sync` protocol crossings,
  and a test list holding every crossing the suite makes today. A third
  assertion bans naming a cross-module `Internal` symbol inline instead of
  importing it, which is the form `BoundaryRule` cannot see.
- **`pinnedCrossModuleLivewireMounts`** — the half no import declares. A Blade
  view mounts a Livewire component by registered string alias, so a
  cross-module mount creates a real dependency with nothing to statically
  analyse. Every such pair is pinned, and every alias must be registered by
  exactly one module provider.
- **`crossModuleRawTableWrites`** — a module writing a table another module
  created must be on a pinned allow-list naming the file, the table, and how
  many such writes it makes. The table-to-module map is derived from the
  migrations at test time, never hand-maintained; reads stay unrestricted. See
  [Table ownership](table-ownership.md).
- **`crossModuleSchemaAlterations`** — the same pin for a module adding columns
  to a table it does not own. Fourteen such pairs exist and they are accepted
  by design; the invariant makes a fifteenth a decision rather than a diff.
- **`noOtherInboxScanStateMutator`** — `inbox_scan_state` mutations go
  through `InboxScanStateMachine` only. Same shape applies to
  `recurring_series.state`, `drift_alerts.state`, and `card_statements.state`.
- **`noAuthFacadeOrHelper`** — the `Auth` facade and `auth()`/`session()`
  helpers are forbidden across `Modules/*` outside an explicit allow-list.
  This enforces [ADR 0002](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0002-di-only-rule.md).
- **`noFacadeCallsFromCoreConsoleCommands`** /
  **`noLaravelGlobalHelpersInCoreConsoleCommands`** — even the
  Console-bootstrap layer respects the DI-only rule.
- **`noHorizonImportsInShippedBuildCode`** — Horizon imports are restricted
  to one allow-listed provider that guards itself with the
  `BEATRAX_RUNTIME=local` runtime check (see
  [ADR 0007](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0007-database-queue-driver.md)).
- **`noNativePhpImportsOutsideDesktopModule`** — `Native\Laravel\*` and
  `Native\Desktop\*` symbols are forbidden outside `Modules/Desktop/`.
- **`noShellContractOutsideAllowList`** — the narrower
  `Native\Desktop\Contracts\Shell` import is restricted to one allow-listed
  action plus a single fallback.
- **`noStoragePathHardCodedOutsideUserDataPathService`** — raw `storage_path()`
  / `database_path()` literals are forbidden outside `UserDataPathService`,
  which is what makes the per-OS user-data-directory paths
  (see [ADR 0006](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0006-nativephp-desktop-shell.md)) work.
- **`paymentTypeHinterContract`** — every `*Hinter` class under
  `Modules/Import/Internal/Parsers/` must implement the `PaymentTypeHinter`
  contract; this is the per-adapter extension hook for the
  classifier stage.
- **`noMerchantAliasesQueryWithoutUserIdFilter`** — every raw query against
  `merchant_aliases` must carry an explicit `where('user_id', $userId)`
  filter. This is the BelongsToUser arch invariant the trait cannot enforce
  (raw queries bypass Eloquent scopes); see
  [ADR 0008](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0008-multi-user-belongstouser.md).
- **`everyDevModeRouteAppliesEnsureDeveloperModeMiddleware`** — every route
  under `/dev/*` must carry the `ensureDeveloperMode` middleware.
- **`darkCompanionUtilitiesOnThemedViews`** — every `bg-white` or
  `text-slate-900` Tailwind utility in a themed module view must carry a
  matching `dark:` companion class.

A violation of any invariant fails the Pest run, which fails the PR gate.
The full list lives in `tests/Contracts/BoundaryArchTest.php`; the file is
the load-bearing safety net for the entire module structure. The import half
is only half the boundary — [Table ownership](table-ownership.md) covers the
half the modules cross through the database.

### The static-analysis half: `app/PhpStan/Rules/BoundaryRule.php`

A custom Larastan rule catches the same class of violation one layer
earlier, at `phpstan analyse` time rather than at test-run time. From a
file whose namespace (or, failing that, filesystem path) resolves to
`Modules\X\…`, any `use` import targeting another module `Y` (`Y != X`)
must begin with `Modules\Y\Public\…` or `Modules\Y\Models\…` — anything
else under `Modules\Y\` (`Internal`, `Database`, `Providers`,
`Resources`, `Routes`, and so on) fails the rule, even directories that
currently hold no PHP classes, so a future module cannot silently gain
a public surface. Files outside `Modules\` are not governed by this
rule; facade/helper bans are enforced separately by
`canvural/larastan-strict-rules`.

It is the stricter of the two guards where it runs, and the narrower in
where it runs. `phpstan.neon` excludes `Modules/*/Database/Migrations`,
`Modules/*/Database/Seeders`, `Modules/*/Routes` and every `tests/`
directory from analysis, and the rule returns early for any file that is
not inside a module at all — so a migration, a seeder, a test, or an
`App\` class may import another module's `Internal\` and PHPStan will
never say so. `pinnedCrossModuleInternalImports` covers exactly that
gap, which is why the two are not redundant. The importer module is detected via
the declared namespace first (so the deliberate violation fixtures
under `app/PhpStan/Rules/Fixtures/` exercise the rule without needing
to live inside `Modules/`), falling back to the filesystem path when
the namespace is anonymous.

## Events as the second boundary path

Where a contract would be too narrow — when one module reacts to a thing
another module does, rather than calling it — the modules use Laravel
events. The owning module raises an event from its `Public/Events/`
namespace; any other module's `Internal/Listeners/` may listen.

Examples:

- `Modules\Ingestion\Public\Events\StatementSummaryRecorded` — raised by
  Ledger after a CAMT/MT940 statement-summary row lands; the Chains
  resolver listens to schedule chain-resolution for the affected user.
- `Modules\Auth\Public\Events\UserPasswordChanged` — raised on a successful
  password change; the session middleware listens to invalidate competing
  sessions for the same user.

Events stay in `Public/Events/` because they are part of the module's public
surface — once another module listens for one, removing it is a breaking
change.

## Where to look next

- [Ingestion pipeline](ingestion-pipeline.md) — the end-to-end flow from
  raw source file to canonical `Transaction` row, crossing the
  `Import` → `Ingestion` → `Ledger` modules and the `Categorization`
  - `Counterparties` boundaries.
- [Chain resolution](chain-resolution.md) — the read-mostly resolver that
  reaches across `Ledger`, `Counterparties`, and `Chains` without writing
  outside its own `chain_links` table.
- [Categorization](categorization.md) — the categorizer's two-pass shape
  (rule-based + per-user memory) plus the ≥40% confidence gate.
- [Table ownership](table-ownership.md) — which module owns which table, how
  that map is derived from the migrations, and the pinned cross-module writes
  and schema alterations.
- [Data model](https://github.com/beatrax-app/spec/blob/main/20-architecture/data-model.md) — the table-level ERD that the modules
  collectively own.
