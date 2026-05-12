# Phase 1: Foundation + ASN CSV Vertical Slice — Research

**Researched:** 2026-05-12
**Domain:** Laravel 13 + Livewire 4 modular monolith / SQLite WAL / ASN CSV ingestion / idempotent fingerprinting / DI-only architectural style
**Confidence:** HIGH for stack pins, module shape, DI patterns, SQLite WAL config, idempotency invariant; MEDIUM for exact ASN CSV column layout (empirical sample required); HIGH for "what to defer" mapping.

---

## Summary

Phase 1 is a **walking skeleton in 5 nwidart modules** (`Core`, `Ledger`, `Ingestion`, `Import`, `Categorization`) that proves the entire spine before any second source touches the database: a hand-written Fortify+Livewire login, a preview-then-confirm ASN CSV upload wizard, a calm "this period at a glance" dashboard, a manual categorization triage page, and the four contracts every later phase will inherit — **DI-only with constructor injection (no facades, no helpers, models direct)**, **idempotent imports via a single composite UNIQUE index plus a versioned `normalized_counterparty`**, **BIGINT minor units + brick/money everywhere**, and **a `Public/` vs `Internal/` namespace split enforced by a Larastan custom rule at level 10 strict**.

Everything Phase 1 needs is well-trodden Laravel 12/13 territory: Fortify is documented to be headless (custom views via `Fortify::loginView()`), Livewire 4's `mount()` resolves dependencies through the service container so constructor-style DI is honest, league/csv handles the BOM/encoding/delimiter quirks of Dutch bank CSVs, and brick/money's `Money::ofMinor()` is the canonical entry into a custom Eloquent cast. The DI-only constraint is enforced today by two open-source PHPStan rule packages (`canvural/larastan-strict-rules` for helper bans, `JoeyMckenzie/facadeless` for facade bans) so the project does not need to hand-roll the rule from scratch.

The one piece that **must be confirmed empirically before tasks are written** is the exact ASN CSV column layout (no official ASN documentation is reachable via WebFetch; only the binary PDF "Bestandsbeschrijving export bestand ASN Online Bankieren" carries the contract). Plan-phase must include a "drop a real export at `.planning/phases/01-…/samples/asn-real.csv` and pin the fixture" task as the first deliverable in the ingestion wave.

**Primary recommendation:** Build the 5 modules with the directory shape in `## Module Layout` below; lock the `transactions` schema in Wave 1; wire the `CurrentUser` and `Money` seams in Wave 1; defer queue, launchd, MT940/CAMT.053, and per-merchant learning to their respective later phases.

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Module Decomposition**
- **D-01:** Phase 1 ships **5 modules** via `nwidart/laravel-modules`: `Core`, `Ledger`, `Ingestion`, `Import`, `Categorization`. Always prefer separate modules when appropriate (user explicit preference).
- **D-02:** Each module exposes a `Public/` namespace containing service classes + DTOs + events. Cross-module access goes through `Public/` only — never reach into another module's internals.
- **D-03:** A **custom Larastan rule** enforces the boundary: importing `Modules\<Other>\<NotPublic>` from outside that module fails CI. Implemented and active from Phase 1; Larastan runs at level 10 strict.
- **D-04:** `Ledger` owns the canonical models and DTOs: `Account`, `Transaction`, `Currency`, the Money value object wrapper. It exposes a public `RecordTransactions` action — the **only** writer to the `transactions` table.
- **D-05:** `Ingestion` holds the `SourceAdapter` contract (in `Public/`) and `AsnCsvAdapter` only. Adapters parse source → return Source DTOs. Nothing more.
- **D-06:** `Import` owns the **ImportPipeline orchestrator** plus the `Parse → Normalize → Fingerprint` stages. The pipeline accepts a `SourceAdapter` (from Ingestion) and calls Ledger's `RecordTransactions` action to persist. Three stages live in `Import/Internal/`; the orchestrator service lives in `Import/Public/`.
- **D-07:** `Categorization` owns `Category`, `MerchantMemory`, and the manual-categorize action. Phase 1 only includes manual categorization + per-merchant memory wiring is **not** done here (CAT-02 lives in Phase 7); only CAT-01 / CAT-03 / CAT-05 are in scope.
- **D-08:** `Core` holds `User`, the `BelongsToUser` trait, the `CurrentUser` service (Public/), auth wiring, shared kernel utilities (Money configuration, Pulse/health hooks).

**Auth & User Setup**
- **D-09:** Auth is built on **Laravel Fortify** (backend routes for login / logout / password reset) with a **hand-written Livewire/Flux UI** matching the calm Linear/Notion aesthetic. No starter-kit UI; full control.
- **D-10:** First user is created via `php artisan diederik:install` — a single idempotent command that prompts for email + password, creates User id=1, seeds the default category tree, and initializes any required config (e.g. `period_start_day` default). Safe to re-run.
- **D-11:** Session policy: **30-day sessions, 'remember me' on by default.** Daily-use tool on a single localhost machine. Re-auth is required on password change.
- **D-12:** Current-user access pattern: **Two layers of indirection.** Domain modules inject `Core\Public\CurrentUser` (the project's own contract). `CurrentUser` itself injects `Illuminate\Contracts\Auth\Guard`. Domain code never directly depends on Laravel's `Guard`; this is the seam that makes the v2 multi-user transition clean. `auth()`, `Auth::user()`, etc. are forbidden everywhere per the project's DI-only constraint.

**Upload UX Flow**
- **D-13:** Upload is a **preview-then-confirm wizard**, not sync-and-redirect (NEW / DUPLICATE / ERROR per row).
- **D-14:** Account mapping is **auto-by-IBAN**. Unknown IBAN triggers an inline account-naming step.
- **D-15:** Phase 1 is **synchronous** end-to-end — no queue worker required to run an import.
- **D-16:** Fingerprint at the DB layer uses **a single composite `UNIQUE` index** on `(account_id, posted_at, amount_minor, currency, normalized_counterparty, source_ref)`. `normalized_counterparty` is computed during Normalize using a deterministic, versioned algorithm (lowercase, strip whitespace + punctuation, collapse repeated spaces). The version is stored alongside.

**Dashboard Composition**
- **D-17:** Home: Top totals (in/out/net) → Top spending categories → Recent transactions (last 10). Uncategorized count badge in page header.
- **D-18:** Empty state is a **first-run wizard**, not a populated skeleton.
- **D-19:** **Period is user-configurable** — `period_start_day` integer 1–28 on the user record. Default = 1. Current period is **derived** at query time. Prev/next arrows step by one period. Install command prompts for it once.
- **D-20:** Uncategorized triage is its **own page** (`/uncategorized`) with single-key category assignment.

### Claude's Discretion

- Concrete `nwidart/laravel-modules` directory layout inside each module (`Models/`, `Actions/`, `Services/`, `Internal/`, `Public/`) — pick a uniform shape; document it once and apply everywhere
- Naming for Public service classes (`ImportPipelineService` vs `RunImport` vs `ImportRunner` etc.) — pick consistent verbs
- Specific Larastan rule implementation (PHPStan custom rule or a `larastan.neon` ruleset with paths) — whichever is cleaner
- Wire-up of the Money value object: where the factory lives, where Currency table is seeded, exact column types for `amount_minor` (`BIGINT NOT NULL`) and `currency_code` (`CHAR(3)`)
- Layout primitives in Livewire/Flux for the calm aesthetic — choose Flux components that exist; don't invent new ones
- Default seed category tree — pick a sensible Dutch-aware starting set; user can edit
- Exact Pest test organization (per-module `tests/Unit` + `tests/Feature` vs top-level mirroring) — keep consistent with nwidart conventions
- Routes / URL structure — RESTful and predictable (`/dashboard`, `/transactions`, `/imports/new`, `/imports/{id}/preview`, `/uncategorized`, `/settings`)
- Where `period_start_day` lives — User column vs UserPreferences table (planner picks)

### Deferred Ideas (OUT OF SCOPE)

- **Per-merchant categorization memory (CAT-02)** — Phase 7. Schema (Merchant + MerchantMemory) can exist; no learning behaviour.
- **User-defined categorization rules (CAT-04)** — Phase 7.
- **`db:backup` artisan command (FND-05)** — Phase 11.
- **Queue infrastructure (`launchd` plists, queue worker process)** — Phase 6.
- **Multi-currency dual-amount display** — schema lands in Phase 1 (MC-01); user-facing dual-currency toggle is Phase 3 (MC-02, UI-06).
- **Encrypted backups / OAuth secret storage layout** — Phase 6.
- **Healthcheck UI surface ("last scan: X")** — Phase 6.
- **Settings UI for `period_start_day`** — install command sets it; planner may defer in-app Settings UI.
</user_constraints>

<phase_requirements>
## Phase Requirements

Each REQ-ID from the phase scope is mapped here with a research-support pointer.

| ID | Description (REQUIREMENTS.md) | Research Support |
|----|-------------------------------|------------------|
| FND-01 | App binds to `127.0.0.1` only; no external network exposure | `## Auth + Loopback Binding`; defense in depth: Herd serves at 127.0.0.1 by default, plus a `LoopbackOnly` middleware that 404s any request whose `Request::server('SERVER_ADDR')` is not loopback |
| FND-02 | User can log in with single-user credential | `## Auth + Loopback Binding`; Fortify backend + hand-written Livewire form |
| FND-03 | Every domain table has nullable `user_id` + `BelongsToUser` trait | `## Canonical Transaction Schema`; `Core/Public/BelongsToUser` trait + migration template |
| FND-04 | Signed `BIGINT` minor units; no `REAL`/`FLOAT` | `## Canonical Transaction Schema` + `## Don't Hand-Roll` |
| FND-05 | `db:backup` artisan command | **DEFERRED to Phase 11** per CONTEXT.md |
| FND-06 | SQLite WAL mode + `synchronous=NORMAL` at app startup | `## SQLite WAL Configuration` |
| FND-07 | Currency arithmetic uses `brick/money` value objects | `## Money Wire-Up` |
| ING-01 | Upload ASN CSV → imports | `## ASN CSV Ingestion` |
| ING-06 | Re-upload same statement → no duplicates (fingerprint enforced at DB layer) | `## Idempotency Invariant` |
| ING-07 | User declares source format (no auto-detect) | `## Upload Wizard Architecture` — source select dropdown |
| ING-08 | Every imported row preserves link back to raw source row | `## Canonical Transaction Schema` — `raw_source_id` FK + `source_row_index` int |
| LED-01 | Each account has distinct record with type + currency | `## Canonical Transaction Schema` — `accounts` table |
| LED-02 | Each transaction has a `type` (expense/income/transfer-out/transfer-in/fee/refund) | `## Canonical Transaction Schema` — `transactions.type` enum |
| MC-01 | Foreign-currency charges preserve original + settled amounts | `## Canonical Transaction Schema` — six money columns from day 1 |
| CAT-01 | Categorize by selecting from a tree of categories | `## Categorization Data Model` |
| CAT-03 | Override auto-suggestions; corrections update per-merchant memory | `## Categorization Data Model`; Phase 1 covers the override; learning loop deferred to CAT-02 in Phase 7 |
| CAT-05 | See/triage uncategorized transactions in bulk | `## Categorization Data Model` — `/uncategorized` triage page |
| UI-01 | "This month at a glance" home view | `## This-Period-At-A-Glance Query` + 01-UI-SPEC.md |
| UI-04 | Default to recent window (3–6 months); "show full history" toggle | `## This-Period-At-A-Glance Query` — 3-month default per UI-SPEC |
| UI-05 | Calm, content-first, Linear/Notion monochrome aesthetic | Locked in 01-UI-SPEC.md (separate artifact) |
| PLT-01 | App runs entirely on localhost | `## Auth + Loopback Binding` |
| PLT-02 | SQLite DB lives outside iCloud/OneDrive/Dropbox | `## Platform & Privacy Guardrails` |
| PLT-05 | `composer.json` does not declare `ext-imap`; CI lints accidental dependence | `## Platform & Privacy Guardrails` |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

The following CLAUDE.md directives are non-negotiable and constrain every plan in this phase:

| Constraint | Effect on Phase 1 |
|-----------|-------------------|
| **PHP 8.5 + Laravel 13** | Pin `composer.json`; CI must run on 8.5; no PHP 8.4-or-earlier shims |
| **Email integration: provider APIs only — no `ext-imap`** | `composer.json` must NEVER list `ext-imap`; CI grep gate (PLT-05) is a Phase 1 deliverable; iCloud Mail explicitly out of scope (no impact on Phase 1, but locked in) |
| **`nwidart/laravel-modules`** | Use 13.0.0+ (released Mar 19, 2026, Laravel 13 compat); Phase 1 produces 5 modules per CONTEXT D-01 |
| **Larastan level 10 strict + Pint + Pest CI-enforced** | All three are wave-0 gates; no frontend tests required |
| **DI-only — no helpers, no facade calls; models direct** | This is the single load-bearing constraint of Phase 1. See `## DI-Only Patterns` |
| **Vertical MVP per phase** | Phase 1 must produce a working "see my ASN month" experience before Phase 2 begins |
| **Local only (localhost)** | FND-01 + PLT-01 are non-negotiable; loopback middleware required |
| **Idempotency** | Every ingestion path must be safe to re-run; verified by Pest test (ING-06) |
| **History retained forever** | No pruning logic anywhere in Phase 1 |
| **Multi-user readiness** | `user_id` nullable on every domain table; `BelongsToUser` trait; `CurrentUser` indirection |
| **Multi-currency from v1** | Six money columns on `transactions` from the first migration |
| **Secrets in local config, not DB** | Fortify config + APP_KEY in `.env`; no other secrets in Phase 1 (IMAP is Phase 6) |

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Authentication backend (Fortify) | API / Backend | — | Fortify is headless; only login routes + credential check |
| Login UI (Livewire) | Frontend Server (SSR via Livewire) | — | Server-rendered Blade + Livewire roundtrip; no JS auth state |
| ASN CSV upload form | Frontend Server (Livewire) | API / Backend (file storage) | Livewire upload handler writes to `storage/imports/`; Import module orchestrates |
| ASN CSV parsing | API / Backend (`Modules\Ingestion`) | — | Pure PHP; no client work |
| Idempotency / fingerprinting | API / Backend (`Modules\Import` + DB UNIQUE) | Database / Storage | UNIQUE index enforces invariant; fingerprint computed server-side |
| Money arithmetic | API / Backend (`Modules\Ledger` + brick/money) | — | Value object lives entirely server-side; integer minor units in DB |
| "This period at a glance" totals | Database / Storage (SUM/GROUP BY) | Frontend Server (Livewire render) | Aggregate in SQLite; assemble Money objects at the boundary; render via Livewire |
| Manual categorization triage | Frontend Server (Livewire + Alpine.js keymap) | API / Backend (mutation) | Single-key assignment is client-side keymap → wire:click → server save |
| Loopback enforcement | API / Backend (middleware) | OS / network (Herd binds 127.0.0.1) | Defense in depth: middleware refuses non-loopback even if Herd misconfigured |
| Period derivation | API / Backend (query layer) | — | `period_start_day` on user record; period bounds computed at query time; nothing per-transaction |
| Pest test suite | API / Backend (CI) | — | No frontend tests in scope; all tests are server-side |

**Sanity check:** The UI is server-rendered Livewire — there is no "client / browser" tier with state. The browser holds DOM only. All decision-making (auth, fingerprint, idempotency, money arithmetic, totals) lives in the API/Backend tier; the Database tier owns persistence + UNIQUE invariants; the Frontend Server tier renders. This matches the modular monolith decision from `.planning/research/ARCHITECTURE.md` and avoids the most common mis-assignment (putting business logic in browser-side JS).

---

## Module Layout

### Directory shape (uniform across all 5 modules)

`nwidart/laravel-modules` v13.0.0 generates this skeleton; the project standardises on the following shape inside each `Modules/<Name>/`:

```
Modules/<Name>/
├── module.json                          # nwidart manifest
├── composer.json                        # module-local; merged via merge-plugin
├── Public/                              # CROSS-MODULE SURFACE — facade-replacement, no internals
│   ├── Contracts/                       # interfaces other modules type-hint
│   ├── Actions/                         # invokable use-cases (single __invoke method)
│   ├── Services/                        # query services + readers
│   ├── Dto/                             # spatie/laravel-data objects
│   └── Events/                          # cross-module events
├── Internal/                            # PRIVATE — other modules MUST NOT import
│   ├── Pipeline/Stages/                 # only for Import module
│   ├── Parsers/                         # only for Ingestion module
│   └── ...
├── Models/                              # Eloquent models — direct use is OK per CLAUDE.md
├── Database/Migrations/                 # module-scoped migrations
├── Database/Seeders/
├── Database/Factories/
├── Providers/
│   ├── <Name>ServiceProvider.php        # main module provider
│   └── RouteServiceProvider.php         # if module has routes
├── Routes/
│   ├── web.php
│   └── console.php
├── Http/
│   ├── Livewire/                        # Livewire components live here, NOT in Volt SFC
│   ├── Middleware/
│   └── Controllers/                     # rarely needed; Livewire handles most
├── Resources/views/                     # Blade
└── tests/
    ├── Unit/                            # pure PHP tests
    └── Feature/                         # full Laravel boot
```

**Key rule (enforced by Larastan custom rule per D-03):** outside `Modules\<X>\`, only `Modules\<X>\Public\…` and `Modules\<X>\Models\…` may be imported. `Modules\<X>\Internal\…` is private. Test files inside `Modules\<X>\tests\` may import their own `Internal\…`.

### Module-by-module Phase 1 surface

#### `Modules\Core`

**Public surface (Phase 1):**
- `Core\Public\Contracts\CurrentUser` — interface returning `int id()`, `User user()`, `int periodStartDay()`
- `Core\Public\Services\CurrentUserService` — implementation; injects `Illuminate\Contracts\Auth\Factory` (the `auth` container binding's official contract)
- `Core\Public\Concerns\BelongsToUser` — trait applied to every domain Eloquent model; adds `user_id` global scope (when not in install bootstrap)
- `Core\Public\Contracts\Clock` — interface `now(): CarbonImmutable` for testable time; impl `SystemClock`
- `Core\Public\Events\UserInstalled` — fired by `diederik:install`

**Internal:**
- `Core\Internal\Console\InstallCommand` — `php artisan diederik:install`
- `Core\Internal\Http\Middleware\LoopbackOnly` — refuses non-loopback (`SERVER_ADDR` not `127.0.0.1` / `::1`) with 404
- `Core\Internal\Providers\FortifyServiceProvider` — registers `Fortify::loginView(...)`, `Fortify::authenticateUsing(...)`

**Models:** `User`

**Routes:** `/login` (Fortify route; rendered with Core's Livewire `LoginPage` component), `/logout`

#### `Modules\Ledger`

**Public surface:**
- `Ledger\Public\Contracts\RecordsTransactions` — interface with `record(iterable<CanonicalTransaction>): RecordResult`
- `Ledger\Public\Actions\RecordTransactions` — **the only writer to `transactions`**
- `Ledger\Public\Services\PeriodQuery` — given `(User, instant)` returns `Period {start, endExclusive, label}`; period derivation
- `Ledger\Public\Services\ThisPeriodAtAGlanceQuery` — returns `{inflowMinor, outflowMinor, netMinor, topCategories, recentTransactions}` for the dashboard
- `Ledger\Public\Services\TransactionListQuery` — paginated/filterable list with recent-window default (90 days)
- `Ledger\Public\Dto\CanonicalTransaction` — spatie/laravel-data immutable DTO; the only shape `RecordTransactions` accepts
- `Ledger\Public\Dto\MoneyDto` — `{minorUnits: int, currency: string}` for boundary crossing (NOT a brick/money object directly, to keep DTOs serializable)
- `Ledger\Public\ValueObjects\Money` — wrapper around `Brick\Money\Money` with `ofMinor`, `plus`, `minus`, `toMinor`; used in domain code only

**Internal:**
- `Ledger\Internal\Casts\MoneyMinorCast` — Eloquent custom cast: `(amount_minor, currency)` columns → `Money` value object, immutable
- `Ledger\Internal\Services\FingerprintComposer` — composes the 6-tuple deterministic key; NOT to be called outside Ledger; the canonical hash function

**Models:** `Account`, `Transaction`, `Currency`

**Routes:** none (Ledger is pure domain)

#### `Modules\Ingestion`

**Public surface:**
- `Ingestion\Public\Contracts\SourceAdapter` — `format(): string`, `parse(string $localPath, AccountResolver $accounts): Generator<SourceTransactionDto>`
- `Ingestion\Public\Dto\SourceTransactionDto` — raw-but-typed row (booked_at, value_date, amount_minor, currency, counterparty_raw, counterparty_iban, description, source_ref, source_row_index, raw_payload)
- `Ingestion\Public\Contracts\AccountResolver` — `resolve(string $iban): AccountResolution` (returns `Known(Account)` or `Unknown(iban)` sealed result)
- `Ingestion\Public\Services\HeaderSniffer` — pre-parse validator (MIME + extension + header columns)

**Internal:**
- `Ingestion\Internal\Adapters\Asn\AsnCsvAdapter` — implements `SourceAdapter` for the ASN CSV format
- `Ingestion\Internal\Adapters\Asn\AsnCsvColumnMap` — single source of truth for column positions/header names (post-empirical confirmation)
- `Ingestion\Internal\Adapters\Asn\AsnCsvHeaderProfile` — for HeaderSniffer

**Models:** none in Phase 1 (`Source` registry can be a seeded enum/constant)

#### `Modules\Import`

**Public surface:**
- `Import\Public\Contracts\RunsImports` — interface
- `Import\Public\Actions\RunImport` — invokable: `__invoke(UploadedFile $file, string $sourceFormat): ImportPreviewResult` (preview phase) and a separate `ConfirmImport` action to persist
- `Import\Public\Dto\ImportPreviewResult` — `{importId, rows: array<PreviewRowDto>, accountsToName: array<UnknownIban>}`
- `Import\Public\Dto\PreviewRowDto` — `{rowIndex, status: 'new'|'duplicate'|'error', canonical?: CanonicalTransaction, error?: string}`
- `Import\Public\Dto\ImportConfirmResult` — `{importId, inserted, duplicates, errors}`
- `Import\Public\Services\AccountNamer` — when wizard supplies a name for an unknown IBAN, persists the new `Account`

**Internal:**
- `Import\Internal\Pipeline\ImportPipeline` — orchestrates the three stages
- `Import\Internal\Pipeline\Stages\ParseStage` — invokes the `SourceAdapter`; collects errors
- `Import\Internal\Pipeline\Stages\NormalizeStage` — normalizes counterparty (lowercase, strip punctuation, collapse whitespace), versioned; produces `CanonicalTransaction`
- `Import\Internal\Pipeline\Stages\FingerprintStage` — calls `Ledger\Internal\Services\FingerprintComposer` (**exception to the Public-only rule: Import is allowed to read Ledger's internal Fingerprint composer via an explicit DI binding — alternatively, expose the composer in `Ledger\Public` as `FingerprintComposer` since the algorithm is the contract that defines idempotency**). Recommend: **expose it in `Ledger\Public\Services\FingerprintComposer`** — it's part of the cross-module contract, not an internal.
- `Import\Internal\Persistence\ImportRun` — model + migration; records `(id, source_format, raw_file_path, sha256, started_at, completed_at, inserted_count, duplicate_count, error_count, status)`

**Models:** `ImportRun` (internal — but exposed as a read-only entity via `Import\Public\Services\ImportRunsQuery` for the results page)

**Routes:** `/imports/new`, `/imports/{id}/preview`, `/imports/{id}`

#### `Modules\Categorization`

**Public surface:**
- `Categorization\Public\Actions\AssignCategory` — invokable: assigns a Category to a Transaction (uses `Ledger\Public\Services\TransactionListQuery` to find the row; writes to `transactions.category_id` via a Ledger-exposed `UpdateTransactionCategory` action — Ledger remains the only writer; Categorization owns the *decision*, Ledger owns the *write*)
- `Categorization\Public\Services\UncategorizedTriageQuery` — returns paginated rows where `category_id IS NULL` ordered by `booked_at DESC`
- `Categorization\Public\Services\TopCategoriesByPeriodQuery` — used by the dashboard
- `Categorization\Public\Events\TransactionCategorized` — fired post-assignment (Phase 7 will listen to update MerchantMemory)

**Internal:**
- `Categorization\Internal\Seeders\DefaultCategoryTreeSeeder` — Dutch-aware default tree
- `Categorization\Internal\Http\Livewire\TriageInbox` — keymap, single-key assignment

**Models:** `Category`, `Merchant` (schema only — no learning behaviour in Phase 1), `MerchantMemory` (table can exist empty — schema land here so Phase 7 adds behaviour without migration)

**Routes:** `/uncategorized`, `/categories` (read-only list of seeded categories)

> **Caveat on the "Ledger is the only writer" rule (D-04):** `transactions.category_id` is a non-domain-changing column (a manual classification label). The cleanest interpretation is: Ledger exposes a `Ledger\Public\Actions\UpdateTransactionCategory($transactionId, $categoryId)` action and Categorization calls it. This preserves the invariant without coupling Categorization to a Ledger model save.

### How modules talk

| From | To | Mechanism |
|------|----|-----------|
| Web/Livewire | Any module | Inject `<Module>\Public\Actions\...` or `<Module>\Public\Services\...` via constructor / `mount()` |
| `Import` | `Ingestion` | Inject `Ingestion\Public\Contracts\SourceAdapter` (resolved via tagged service registry by `sourceFormat` string) |
| `Import` | `Ledger` | Inject `Ledger\Public\Contracts\RecordsTransactions` and `Ledger\Public\Services\FingerprintComposer` |
| `Import` | `Core` | Inject `Core\Public\Contracts\CurrentUser` for `user_id` stamping |
| `Categorization` | `Ledger` | Inject `Ledger\Public\Actions\UpdateTransactionCategory` (Phase-1 read access through `Ledger\Public\Services\TransactionListQuery`) |
| `Core` install command | `Categorization` | Fire `UserInstalled` event; Categorization listener seeds default tree (decoupled — install command does NOT directly import Categorization classes) |

No module imports another's `Models\` directly; queries route through Public Query Services. Eloquent models are still allowed *within* a module per CLAUDE.md.

[VERIFIED: nwidart/laravel-modules releases v13.0.0 (March 19, 2026, Laravel 13 compat) — WebSearch + Packagist]
[VERIFIED: ARCHITECTURE.md `## Project Structure` informs the responsibilities map]

---

## DI-Only Patterns

This is the **single load-bearing constraint** of the project (CLAUDE.md + MEMORY.md `feedback_laravel_di_only.md`). Every plan in Phase 1 must comply.

### Forbidden (must NEVER appear in non-test code)

| Forbidden | Why | Replace with |
|----------|-----|--------------|
| `auth()`, `auth('web')`, `Auth::user()`, `Auth::id()` | Helper / facade | Inject `Core\Public\Contracts\CurrentUser` |
| `request()`, `Request::input(...)` (facade) | Helper / facade | Type-hint `\Illuminate\Http\Request` in method signature |
| `config()`, `Config::get(...)` | Helper / facade | Inject `\Illuminate\Contracts\Config\Repository` |
| `app()`, `App::make(...)`, `resolve()` | Service locator | Inject the concrete type / contract |
| `route()`, `URL::to(...)`, `url()` | Helper / facade | Inject `\Illuminate\Contracts\Routing\UrlGenerator` |
| `now()`, `today()` | Helper (and untestable) | Inject `Core\Public\Contracts\Clock` |
| `cache()`, `Cache::*` | Helper / facade | Inject `\Illuminate\Contracts\Cache\Repository` |
| `event()`, `Event::dispatch(...)` | Helper / facade | Inject `\Illuminate\Contracts\Events\Dispatcher` |
| `session()`, `Session::*` | Helper / facade | Inject `\Illuminate\Contracts\Session\Session` |
| `DB::table(...)`, `DB::transaction(...)` | Facade | Inject `\Illuminate\Database\DatabaseManager` or `\Illuminate\Database\ConnectionInterface` |
| `Log::info(...)` | Facade | Inject `\Psr\Log\LoggerInterface` |
| `Storage::disk(...)` | Facade | Inject `\Illuminate\Contracts\Filesystem\Factory` |
| `Schema::create(...)` in migrations | Facade | **EXEMPT inside `database/migrations/`** — migrations are framework lifecycle code, not domain code; document the exemption in `phpstan.neon` `excludePaths` |

### Allowed (per CLAUDE.md)

- Eloquent instantiation: `new Transaction()`
- Eloquent static finders: `Transaction::find($id)`, `Transaction::where(...)->get()` — these are model-bound, not facades
- Eloquent relationships and query builder via `$model->newQuery()`
- Trait usage on Eloquent models

### Canonical equivalents

#### Routes — DI-only route definitions

Laravel 12+ ships `bootstrap/app.php` with route loaders. Use the **route file form** (Routes are defined in module-local `Routes/web.php` files that nwidart auto-loads via the module's `RouteServiceProvider`); inside route files, the `Route::` facade is the framework's defined entry point. **Route files are exempt from the facade ban** (analogous to migrations — they are framework lifecycle, not domain code). Document the exemption in `phpstan.neon` `excludePaths` for `**/Routes/*.php`.

Inside controllers / Livewire components, use:
```php
final class ImportNewPage extends Component
{
    public function __construct(
        private UrlGenerator $urls,            // \Illuminate\Contracts\Routing\UrlGenerator
    ) {}

    public function redirectToPreview(int $importId): RedirectResponse
    {
        return redirect()->to($this->urls->route('imports.preview', ['id' => $importId]));
        // Even cleaner: inject \Illuminate\Routing\Redirector and call $this->redirector->route(...)
    }
}
```

#### Livewire components — constructor + mount DI

Livewire 4 components are re-constructed on every request, so:
- **Service dependencies (stateless):** inject via `mount(...)` — Livewire resolves type-hinted parameters from the container automatically. [VERIFIED: livewire.laravel.com/docs/4.x/lifecycle-hooks]
- **Long-lived dependencies that must survive subsequent requests:** use `boot(ServiceA $a, ServiceB $b)` — Livewire calls `boot()` on every request including hydration.
- **Constructor:** Volt SFC class-based components support `__construct(...)` in Livewire 4 syntax. But prefer `mount()` / `boot()` to match the framework's lifecycle model. [VERIFIED: livewire.laravel.com/docs/4.x/components]

```php
// Livewire 4 component — DI-only example
namespace Modules\Import\Http\Livewire;

use Livewire\Component;
use Modules\Import\Public\Actions\RunImport;
use Modules\Import\Public\Dto\ImportPreviewResult;

final class UploadWizard extends Component
{
    public ?int $importId = null;
    public ?string $sourceFormat = 'asn-csv';

    // Stateless deps resolved via boot — survive subsequent requests
    public function boot(
        private RunImport $runImport,
    ): void {}

    public function submit(): void
    {
        $upload = $this->validate(['file' => 'required|file|max:10240'])['file'];
        $result = ($this->runImport)($upload, $this->sourceFormat);
        $this->importId = $result->importId;
    }

    public function render(): View
    {
        return view('import::livewire.upload-wizard');
    }
}
```

#### Queue jobs — constructor DI for handle()

```php
// Queue jobs are not used in this phase; the pattern is documented here for future reference.
final class ExampleJob implements ShouldQueue
{
    public function __construct(
        private readonly int $payloadId,        // serializable state
    ) {}

    public function handle(SomeService $service): void {
        // Laravel resolves $service from the container on dispatch
        $service->process($this->payloadId);
    }
}
```

#### Artisan commands — constructor DI

```php
final class InstallCommand extends Command
{
    protected $signature = 'diederik:install';

    public function __construct(
        private readonly Hasher $hasher,                 // \Illuminate\Contracts\Hashing\Hasher
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly DefaultCategoryTreeSeeder $seeder,
    ) {
        parent::__construct();
    }

    public function handle(): int { /* ... */ }
}
```

### Enforcement — Larastan ruleset

Phase 1 ships a `phpstan.neon` (or `larastan.neon`) with:

1. **`larastan/larastan` at `level: max`** (which is **level 10** in current Larastan; the search-result claim that "the scale stops at 9" is outdated — Larastan tracks PHPStan's max level which is 10 as of 2026).
2. **`canvural/larastan-strict-rules`** — bans helper functions globally; `allowedGlobalFunctions` whitelist for `__()`, `trans()` (if i18n is needed) only.
3. **`JoeyMckenzie/facadeless`** — bans every Laravel facade by default; `allow` list empty (no exemptions).
4. **Custom rule: `BoundaryRule`** — for each `Modules\<X>\…` namespace, forbid `use Modules\<Y>\Internal\…` and `use Modules\<Y>\Models\…` from outside `<Y>`. Implementation: a `PHPStan\Rules\Rule` that scans `Use_` and `Name` AST nodes, computes the importing module from the file path, and emits an error if the imported namespace is `Modules\OtherModule\(Internal|Models)\...` unless the file path is inside `OtherModule`. ~120 lines.
5. **`excludePaths`** for `database/migrations/`, `**/Routes/*.php`, and `**/tests/**` — these are framework-lifecycle and test code; facade/helper usage is fine.

[VERIFIED: canvural/larastan-strict-rules on Packagist — `allowedGlobalFunctions` confirmed]
[VERIFIED: JoeyMckenzie/facadeless on GitHub — banning Laravel facades is the stated purpose]
[CITED: phpstan.org/developing-extensions/rules — custom rule API]

---

## ASN CSV Ingestion

### State of empirical knowledge

**HIGH confidence (verified via WebSearch + ASN's own URLs in the results):**
- ASN Bank exports transactions in **PDF, CSV, and CAMT.053** formats from the ASN Online Bankieren portal. [VERIFIED: WebSearch — asnbank.nl official "Bestandsbeschrijving export bestand ASN Online Bankieren" file]
- The CSV option has a checkbox labelled **"CSV met IBAN"** (CSV with IBAN). [VERIFIED: WebSearch — bunni.nl / kompar.nl writeups]
- Dutch banks generally use **comma-separated CSV with the period as decimal** in their export formats (despite the locale convention being the opposite), to make tooling compatible. [ASSUMED — community converters all reference period-decimal; needs empirical confirmation]
- The CSV is **headerless** in most Dutch banks' historical exports (ABN, ING, ASN — fixed-position columns). [ASSUMED — needs empirical confirmation against a real export]

**MEDIUM confidence (from community open-source converters):**
- ASN CSV historically has **17 columns** in fixed order, no header row, fields quoted. The widely-cited layout (from `cwverhey/HomeBankCSV` and `dsprenkels/asn2ynab`) is:

  | # | Field | Notes |
  |---|-------|-------|
  | 0 | Boekingsdatum | `dd-mm-yyyy` |
  | 1 | Opdrachtgever IBAN/BBAN | Your account IBAN |
  | 2 | Tegenrekening IBAN/BBAN | Counterparty IBAN (may be empty) |
  | 3 | Naam tegenrekening | Counterparty name |
  | 4 | Adres tegenrekening | (often empty) |
  | 5 | Postcode tegenrekening | (often empty) |
  | 6 | Plaats tegenrekening | (often empty) |
  | 7 | Valutasoort rekening | `EUR` |
  | 8 | Saldo voor mutatie | Account balance pre-tx |
  | 9 | Valutasoort mutatie | `EUR` |
  | 10 | Transactiebedrag | Amount, signed, period as decimal (e.g. `-12.34`) |
  | 11 | Journaaldatum | `dd-mm-yyyy` (booking date in journal) |
  | 12 | Valutadatum | `dd-mm-yyyy` (value date) |
  | 13 | Interne transactiecode | ASN internal code (e.g. `8810`) |
  | 14 | Globale transactiecode | Higher-level code |
  | 15 | Volgnummer transactie | Sequence number per day |
  | 16 | Betalingskenmerk | Payment reference / "omschrijving" |
  | 17 | Omschrijving | Free-text description (multi-line; literal `\r` may appear) |

  *(Some open-source tools report 17 columns, others 18 — there's a known historical discrepancy around the `Betalingskenmerk` field. Empirical confirmation against a 2026 export is required.)* [ASSUMED]

**LOW confidence (will be resolved by the empirical-sample task):**
- Whether the 2026 ASN CSV format has a header row (likely **no** but must be confirmed)
- Encoding: very likely **Windows-1252 or ISO-8859-15** for legacy CSV; could be UTF-8 in a newer export. league/csv's `CharsetConverter` handles both transparently. [ASSUMED]
- Delimiter: comma `,` is community-reported; some Dutch banks use semicolon `;`. Must verify. [ASSUMED]
- BOM presence: unlikely in ASN export but league/csv strips it automatically via `Reader::setBom()` / `BOM::tryFrom()`. [CITED: csv.thephpleague.com/9.0/interoperability/encoding/]

### Plan-phase deliverable: empirical sample task

The FIRST task in the ingestion wave MUST be: **"Drop a real anonymized ASN CSV export from the user at `tests/fixtures/asn-sample-1.csv`, alongside `tests/fixtures/asn-sample-1.md` documenting the empirical column layout, and pin the column indices in `Modules\Ingestion\Internal\Adapters\Asn\AsnCsvColumnMap`."** Until that fixture exists, the AsnCsvAdapter is implemented against the [ASSUMED] layout above with a `TODO: confirm against real export` comment block, and a Pest test loads the fixture.

### league/csv usage pattern

```php
use League\Csv\Reader;
use League\Csv\CharsetConverter;
use League\Csv\Statement;

$reader = Reader::createFromPath($localPath, 'r');

// Detect & strip UTF-8 BOM (no-op if absent)
$reader->setHeaderOffset(null);              // ASN: headerless
$reader->setDelimiter(',');                  // confirm via HeaderSniffer
$reader->setEscape('');                      // PHP 8.4+ deprecation guard

// Convert legacy encoding to UTF-8 transparently
CharsetConverter::addTo($reader, 'windows-1252', 'utf-8');

foreach ((new Statement())->process($reader) as $i => $row) {
    yield new SourceTransactionDto(
        bookedAt: CarbonImmutable::createFromFormat('d-m-Y', $row[0]),
        ownIban: $row[1],
        counterpartyIban: $row[2] ?: null,
        counterpartyName: $row[3],
        currency: $row[9],
        amountMinor: $this->parseAmountMinor($row[10]),     // "-12.34" -> -1234
        valueDate: CarbonImmutable::createFromFormat('d-m-Y', $row[12]),
        sourceRef: $row[15] ?: null,                         // Volgnummer
        description: $this->joinDescription($row[16], $row[17] ?? ''),
        rawPayload: $row,
        sourceRowIndex: $i,
    );
}
```

[CITED: csv.thephpleague.com/9.0/ — Reader API, CharsetConverter, Statement]

### Amount parsing — never via float

```php
private function parseAmountMinor(string $raw): int
{
    // ASN: "-12.34" or "+12.34" or "12.34" — period decimal, signed, two digits
    $normalized = str_replace(['+', ' ', "\u{A0}"], '', trim($raw));
    if (!preg_match('/^(-?)(\d+)\.(\d{2})$/', $normalized, $m)) {
        throw new InvalidAmountException("Cannot parse ASN amount: '{$raw}'");
    }
    $sign = $m[1] === '-' ? -1 : 1;
    $whole = (int) $m[2];
    $fractional = (int) $m[3];
    return $sign * ($whole * 100 + $fractional);
}
```

**Never** `(int)((float) $raw * 100)` — float roundtrip can corrupt `0.29` into `28` instead of `29`. [CITED: research/PITFALLS.md Pitfall 1]

### Description handling

ASN's `Omschrijving` field can contain MT-940-style multi-line narratives (literal `\r` or `\n`-separated). Normalize during Normalize stage:
- Collapse whitespace
- Strip leading/trailing punctuation
- Limit to a sane max length for `counterparty_normalized` (e.g. 120 chars truncate-with-ellipsis for display; full text retained in `description`)

`counterparty_normalized` is computed from the **counterparty name** (column 3), not the free-text description. If the name is empty (common for direct debits without a labelled counterparty), fall back to the first sane token of the description. The exact algorithm:

```
counterparty_normalized = normalize(name_or_fallback)
  where normalize = lowercase
                  → strip diacritics
                  → strip punctuation except '&' and digits
                  → collapse whitespace runs to single space
                  → trim
                  → truncate to 80 chars
```

Versioned constant: `Ledger\Public\Services\FingerprintComposer::NORMALIZATION_VERSION = 1`. Stored alongside the fingerprint on `transactions.fingerprint_version` so the algorithm can evolve (Phase 7 / 9) without invalidating Phase 1 rows.

---

## Canonical Transaction Schema (Phase 1)

### Why a single table with a type enum

Per ARCHITECTURE.md `## Why a single 'transactions' table, not full double-entry`: one row owns the perspective of one account; transfers create paired rows; chain links live in a separate table (Phase 5). Phase 1 implements only the single-row case (no transfer pairing yet — ING-05/LED-04 are Phase 4).

### `transactions` migration shape

```php
Schema::create('transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();   // FND-03
    $table->foreignId('account_id')->constrained()->cascadeOnDelete();             // LED-01
    $table->string('type', 32);                                                    // LED-02: expense|income|transfer_out|transfer_in|fee|refund|adjustment
    $table->date('posted_at');                                                     // canonical booking date
    $table->dateTime('booked_at');                                                 // when it appeared on the statement
    $table->date('value_date');                                                    // economic date
    // Native currency (what the user actually paid in):
    $table->bigInteger('amount_minor');                                            // FND-04 signed cents
    $table->char('currency', 3);                                                   // FND-07 ISO 4217
    // Settled currency (what the account actually saw):
    $table->bigInteger('settled_amount_minor');                                    // MC-01 from day 1
    $table->char('settled_currency', 3);                                           // MC-01
    $table->decimal('fx_rate_used', 18, 8)->nullable();                            // MC-01
    // Counterparty:
    $table->string('counterparty_name')->nullable();
    $table->string('counterparty_iban', 34)->nullable();
    $table->string('counterparty_normalized', 80);                                 // computed during Normalize
    $table->unsignedSmallInteger('normalization_version');                         // for migration safety
    // Description (free text, can be long, multi-line):
    $table->text('description')->nullable();
    // Category (nullable for triage):
    $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();   // CAT-01
    // Source provenance (ING-08):
    $table->string('source_format', 32);                                           // e.g. 'asn-csv'
    $table->foreignId('import_run_id')->constrained();                             // ING-08 link to raw_source
    $table->unsignedInteger('source_row_index');                                   // ING-08
    $table->string('source_ref')->nullable();                                      // bank-provided per-tx ref
    // Fingerprint (ING-06):
    $table->char('fingerprint', 64);                                               // sha256 hex
    $table->unsignedSmallInteger('fingerprint_version');                           // bump on algorithm change
    // Lifecycle:
    $table->string('status', 16)->default('cleared');                              // cleared|pending
    $table->timestamps();

    // INDEXES — dashboard queries:
    $table->index(['user_id', 'posted_at']);                                       // primary dashboard scan
    $table->index(['account_id', 'posted_at']);                                    // per-account list
    $table->index(['category_id', 'posted_at']);                                   // top-categories per period
    $table->index(['user_id', 'category_id', 'posted_at'])
          ->where('category_id IS NULL');                                          // triage page
    // FINGERPRINT UNIQUE — D-16:
    $table->unique([
        'account_id',
        'posted_at',
        'amount_minor',
        'currency',
        'counterparty_normalized',
        'source_ref',
    ], 'transactions_fingerprint_uq');
});
```

**Note on the UNIQUE index:** SQLite treats NULL values as distinct in UNIQUE indexes, which is correct for `source_ref` (a NULL means "no bank reference"; multiple such rows are not duplicates if other fields differ — they'll still be caught by `(account_id, posted_at, amount_minor, currency, counterparty_normalized)`). To be safe, the `Ledger\Public\Services\FingerprintComposer` ALSO computes a `fingerprint` hex column and there's a separate `UNIQUE(account_id, fingerprint)` — this is the canonical de-dup key, and the composite UNIQUE above is the "defense in depth" tuple. CONTEXT.md D-16 says "a single composite UNIQUE" — the cleanest interpretation honouring that wording is: **the composite UNIQUE IS the fingerprint, and the `fingerprint` column is just its SHA-256 hex for human-readable reference**. Drop the redundant second UNIQUE.

[CITED: research/ARCHITECTURE.md `## Domain Model` for column shape; CONTEXT.md D-16 for the canonical tuple]

### `accounts` migration shape

```php
Schema::create('accounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained();
    $table->string('name');                                                        // user-supplied during wizard step
    $table->string('slug')->unique();                                              // derived from name; URL-safe
    $table->string('kind', 16);                                                    // e.g. 'asn'
    $table->string('iban', 34)->unique();                                          // D-14 IBAN-based mapping
    $table->char('default_currency', 3)->default('EUR');
    $table->timestamps();

    $table->index(['user_id', 'kind']);
});
```

### `categories` migration shape (Phase 1)

```php
Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained();
    $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('kind', 16);                                                    // expense|income|transfer
    $table->unsignedInteger('display_order')->default(100);
    $table->timestamps();
});
```

### `currencies` migration shape (seeded)

```php
Schema::create('currencies', function (Blueprint $table) {
    $table->char('code', 3)->primary();                                            // ISO 4217
    $table->string('name');
    $table->unsignedTinyInteger('minor_unit');                                     // 2 for EUR, 0 for JPY
});
```

Seeded with at minimum: EUR, USD, GBP. brick/money has its own currency registry; this table is for FK integrity and display.

### `merchants` & `merchant_memories` (schema only in Phase 1)

Tables created but no writes / no learning. Phase 7 hangs CAT-02 behavior on them. Keeping the migration in Phase 1 means no schema change needed in Phase 7.

```php
Schema::create('merchants', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained();
    $table->string('canonical_name');
    $table->json('aliases')->nullable();
    $table->foreignId('default_category_id')->nullable()->constrained('categories');
    $table->timestamps();
});

Schema::create('merchant_memories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained();
    $table->string('normalized_counterparty');
    $table->foreignId('category_id')->constrained();
    $table->unsignedInteger('confirmation_count')->default(0);
    $table->timestamp('last_confirmed_at')->nullable();
    $table->timestamps();

    $table->unique(['user_id', 'normalized_counterparty']);
});
```

### `import_runs` migration shape

```php
Schema::create('import_runs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained();
    $table->string('source_format', 32);
    $table->string('raw_file_path');
    $table->char('sha256', 64);                                                    // file-level idempotency
    $table->dateTime('uploaded_at');
    $table->dateTime('confirmed_at')->nullable();
    $table->unsignedInteger('inserted_count')->default(0);
    $table->unsignedInteger('duplicate_count')->default(0);
    $table->unsignedInteger('error_count')->default(0);
    $table->string('status', 16)->default('previewed');                            // previewed|confirmed|discarded
    $table->timestamps();

    $table->unique(['user_id', 'sha256']);                                         // re-upload exact same file: detect at this layer too
});
```

The `(user_id, sha256)` UNIQUE on import_runs is **the first layer of idempotency**: same file = same import_run row, never re-runs the pipeline. The composite UNIQUE on transactions is **the second layer**: catches cross-file overlap (two CSVs covering the same period).

---

## Idempotency Invariant (Project-Wide)

### The contract every adapter MUST satisfy

> **Re-importing the same source data — whether as the same file, a re-downloaded file with cosmetic byte differences, or an overlapping period in a different file — produces ZERO new rows in `transactions`.**

### How the invariant is enforced

1. **File layer** — `import_runs(user_id, sha256)` UNIQUE detects byte-identical re-uploads. The preview wizard short-circuits: "This file was already imported on YYYY-MM-DD as Import #N."
2. **Row layer** — `transactions` composite UNIQUE on `(account_id, posted_at, amount_minor, currency, counterparty_normalized, source_ref)` enforced by SQLite. Conflicts are detected and counted as duplicates (not inserted, not failed).
3. **Application layer** — `Ledger\Public\Actions\RecordTransactions` uses `INSERT … ON CONFLICT DO NOTHING` (SQLite's `INSERT OR IGNORE` semantics through Laravel's `insertOrIgnore()`) inside a single DB transaction per import. Returns `(inserted, duplicates)` counts.

### Why all three layers

- **File layer** alone fails when the user re-downloads from ASN's portal a day later — same data, different file (whitespace, BOM, ordering differences produce a different sha256).
- **Row layer** alone is sufficient for correctness but expensive: every row triggers an INSERT attempt. The file layer short-circuits the common case at zero DB cost.
- **Application layer** wraps the two so the user-facing report is "N imported, M duplicates" — without this, conflict counting requires a per-row dance.

### The shared Pest test contract

Every adapter Phase 2+ adds (MT940, CAMT.053, ICS, PayPal, Email matchers) must pass this test, parameterized by adapter:

```php
// tests/Contracts/IdempotencyContractTest.php
arch('every SourceAdapter passes the idempotency contract', function () {
    // Architecture test: every class implementing SourceAdapter is in the list
})->expect('Modules\Ingestion\Public\Contracts\SourceAdapter')->toBeImplementedBy([
    AsnCsvAdapter::class,
    // Future adapters (e.g. AsnCamt053Adapter, AsnMt940Adapter) are added the same way.
]);

it('produces zero new rows when the same file is imported twice', function (
    string $adapterClass,
    string $samplePath,
) {
    $this->seedFixtureUser();
    $runOnce = $this->runImport($adapterClass, $samplePath);
    $runTwice = $this->runImport($adapterClass, $samplePath);

    expect($runOnce->inserted)->toBeGreaterThan(0);
    expect($runTwice->inserted)->toBe(0);
    expect($runTwice->duplicates)->toBe($runOnce->inserted);
})->with([
    [AsnCsvAdapter::class, __DIR__ . '/../fixtures/asn-sample-1.csv'],
    // Future adapters append entries here; the test body is format-agnostic.
]);

it('produces zero new rows when an overlapping period is imported from a new file', function (
    string $adapterClass,
    string $base,
    string $overlap,
) {
    $this->seedFixtureUser();
    $first = $this->runImport($adapterClass, $base);
    $second = $this->runImport($adapterClass, $overlap);

    // overlap covers some of the same days but not all
    expect($second->inserted)->toBeLessThan($first->inserted);
    expect($second->duplicates)->toBeGreaterThan(0);
})->with([
    [AsnCsvAdapter::class, __DIR__ . '/../fixtures/asn-jan.csv', __DIR__ . '/../fixtures/asn-jan-feb.csv'],
]);
```

The dataset is the seam: when Phase 2 adds an MT940 adapter, the test files come with it, the contract test stays unchanged. [VERIFIED: pestphp.com/docs/datasets — parameterized tests via `->with(...)` is the canonical pattern]

[CITED: research/PITFALLS.md Pitfall 2 — unstable transaction identity]

---

## This-Period-At-A-Glance Query (UI-01, UI-04)

### Period derivation

`period_start_day` is stored on `users` (or `user_preferences` — planner picks; recommend `users` column for Phase 1 simplicity, migrate to `user_preferences` only when more settings exist). Default = 1.

Period boundaries are derived at query time by `Ledger\Public\Services\PeriodQuery`:

```php
final class PeriodQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentUser $currentUser,
    ) {}

    public function currentFor(CurrentUser $user): Period
    {
        return $this->containing($this->clock->now());
    }

    public function containing(CarbonImmutable $instant): Period
    {
        $startDay = $this->currentUser->periodStartDay();    // 1..28
        $candidate = $instant->setDay($startDay)->startOfDay();
        $start = $instant->day >= $startDay
            ? $candidate
            : $candidate->subMonthNoOverflow();
        $endExclusive = $start->addMonthNoOverflow();
        return new Period($start, $endExclusive);
    }

    public function previous(Period $p): Period { return $this->containing($p->start->subDay()); }
    public function next(Period $p): Period     { return $this->containing($p->endExclusive); }
}
```

The 1..28 ceiling avoids edge cases at month-end (no day 30 in February).

### The dashboard query (SQLite, brick/money assembly at boundary)

`Ledger\Public\Services\ThisPeriodAtAGlanceQuery::for(User, Period): DashboardSummary`:

```php
public function for(User $user, Period $period): DashboardSummary
{
    $rows = $this->connection->table('transactions')
        ->where('user_id', $user->id)
        ->whereBetween('posted_at', [$period->start->toDateString(), $period->endExclusive->subDay()->toDateString()])
        ->selectRaw('
            SUM(CASE WHEN amount_minor > 0 THEN amount_minor ELSE 0 END) AS inflow_minor,
            SUM(CASE WHEN amount_minor < 0 THEN -amount_minor ELSE 0 END) AS outflow_minor,
            SUM(amount_minor) AS net_minor
        ')
        ->first();

    return new DashboardSummary(
        inflow:  Money::ofMinor((int) ($rows->inflow_minor  ?? 0), 'EUR'),
        outflow: Money::ofMinor((int) ($rows->outflow_minor ?? 0), 'EUR'),
        net:     Money::ofMinor((int) ($rows->net_minor     ?? 0), 'EUR'),
        topCategories:     $this->topCategoriesFor($user, $period),
        recentTransactions: $this->recentTransactionsFor($user, $period, limit: 10),
    );
}
```

**Key principles:**
- Aggregate as **integers in SQL** (`SUM(amount_minor)`); never `SUM(amount_minor / 100.0)`. Floats never enter the pipeline.
- Construct `Money` objects **once at the query boundary** when assembling the DTO. Inside the service or view layer, you have value objects; inside the DB, you have integers.
- All amounts are EUR in Phase 1 (ASN only). Multi-currency totals (Phase 3+) will require a `MoneyBag` aggregating per-currency sums.
- The `wherein` index `(user_id, posted_at)` makes this query <5ms at any realistic scale.

### Top categories per period

```sql
SELECT category_id, SUM(-amount_minor) AS spend_minor
FROM transactions
WHERE user_id = :user AND amount_minor < 0
  AND posted_at >= :start AND posted_at < :end_exclusive
  AND category_id IS NOT NULL
GROUP BY category_id
ORDER BY spend_minor DESC
LIMIT 5
```

Returns `array<{categoryId, label, spend: Money, percentageOfTotal: float}>` after Money assembly. Spending bars in the UI normalize against the largest category.

### Recent transactions

```sql
SELECT id, posted_at, counterparty_name, category_id, amount_minor, currency
FROM transactions
WHERE user_id = :user
  AND posted_at >= :start AND posted_at < :end_exclusive
ORDER BY posted_at DESC, id DESC
LIMIT 10
```

UI-04: a separate `/transactions` page lifts the limit and applies the 90-day recent-window default per the UI-SPEC. The dashboard's "view all" link routes there.

### Caching strategy

**Phase 1: no caching.** Single user, ~1k rows after a month of imports, dashboard query in <5ms. Add caching only if Phase 8+ pre-aggregation becomes useful. ARCHITECTURE.md §"Keeping it 'calm' on years of transactions" describes the `account_balance_snapshots` pattern; defer until measured slowness.

---

## Categorization Data Model

### Tables (Phase 1)

- `categories` — tree via `parent_id` self-reference (see `## Canonical Transaction Schema`). Seeded by `DefaultCategoryTreeSeeder` during install.
- `transactions.category_id` — nullable FK; NULL means "uncategorized."
- `merchants` and `merchant_memories` — schema only (Phase 7 hangs CAT-02 behaviour).

### Default category tree (Dutch-aware seed)

Suggested Phase 1 seed — `display_order` ensures the keymap on `/uncategorized` is stable:

```
Income
├── Salary
├── Refunds
└── Other income

Housing
├── Rent / Mortgage
├── Utilities (Energy, Water)
└── Internet & Phone

Groceries

Transport
├── Public transport (NS, OV)
├── Fuel
└── Car maintenance

Insurance
├── Health
├── Liability
└── Other

Subscriptions
├── Streaming
├── Music
├── Cloud / Software
└── Memberships

Eating out
Cash withdrawal
Healthcare
Personal care
Donations
Transfers (internal)
Fees & charges
Other / Uncategorized
```

`Other / Uncategorized` is a sink: never set by the user manually (preserve `NULL` semantics for triage).

### `AssignCategory` action

```php
final class AssignCategory
{
    public function __construct(
        private readonly UpdateTransactionCategory $updater,       // Ledger\Public\Actions
        private readonly CurrentUser $currentUser,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(int $transactionId, ?int $categoryId): void
    {
        ($this->updater)($this->currentUser->user(), $transactionId, $categoryId);
        $this->events->dispatch(new TransactionCategorized(
            transactionId: $transactionId,
            categoryId: $categoryId,
            userId: $this->currentUser->id(),
        ));
        // MerchantMemory updates are out of scope for this listener; it is a no-op for now.
    }
}
```

### Triage query (`/uncategorized`)

```php
public function triageFor(User $user, int $limit = 50, ?int $cursorId = null): TriageBatch
{
    $q = Transaction::query()
        ->where('user_id', $user->id)
        ->whereNull('category_id')
        ->orderByDesc('posted_at')
        ->orderByDesc('id')
        ->limit($limit + 1);                                 // +1 for cursor

    if ($cursorId !== null) {
        $q->where('id', '<', $cursorId);
    }

    $rows = $q->get();
    $hasMore = $rows->count() > $limit;

    return new TriageBatch(
        rows: $rows->take($limit),
        nextCursor: $hasMore ? $rows[$limit - 1]->id : null,
    );
}
```

Uses the partial index `transactions(user_id, category_id, posted_at) WHERE category_id IS NULL` for speed.

### Keymap (Alpine.js binding inside the Livewire triage component)

Per UI-SPEC: `1`-`9` assign the user's top-9 most-used categories (initially the seeded ones in `display_order` order), `↑/↓` move cursor, `Enter` commits, `/` focuses search, `Esc` clears pending. The keymap is pure Alpine state; the `wire:click` fires when the user presses Enter to save the batch — single roundtrip for N pending assignments.

---

## Auth + Loopback Binding

### Fortify wiring

```php
// Modules\Core\Internal\Providers\FortifyServiceProvider
final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(Fortify $fortify, Repository $config): void
    {
        // Custom Livewire login view
        Fortify::loginView(function () {
            return view('core::auth.login');                 // Blade wrapper that mounts Livewire LoginForm
        });

        // 30-day remember-me session (D-11) is via session.lifetime config — boot can override:
        $config->set('session.lifetime', 60 * 24 * 30);      // 30 days in minutes
        $config->set('session.expire_on_close', false);

        // No registration view — single-user (D-10)
        Fortify::ignoreRoutes();                              // we register only login/logout below
        // Or use config('fortify.features') and remove 'registration', 'reset-passwords' as needed
    }
}
```

[VERIFIED: laravel.com/docs/13.x/fortify — `Fortify::loginView()`, headless backend]

### Hand-written Livewire login component

```php
// Modules\Core\Internal\Http\Livewire\LoginForm
final class LoginForm extends Component
{
    public string $email = '';
    public string $password = '';
    public bool $remember = true;                            // D-11 default on

    public function submit(StatefulGuard $guard, Hasher $hasher, Redirector $redirector): void
    {
        $this->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Fortify uses its own auth pipeline; alternatively we delegate to it via POST /login.
        // Cleaner: submit the Livewire form values to Fortify's /login endpoint via wire:submit + POST.
        // Even cleaner: bypass Fortify's HTTP entry, call its AuthenticateUserPipeline directly via DI.
        // Recommend: form submits via wire:submit to a method that re-posts via TestResponse-style
        // internal call OR uses Fortify::authenticateUsing closure.
    }
}
```

**Recommended approach**: keep Fortify's `/login` POST route as the actual authentication entry point; the Livewire form posts to it via a regular `<form action="{{ route('login') }}">` with `wire:submit.prevent` disabled. This keeps the auth pipeline framework-canonical and the UI hand-controlled. Errors flow back as flash messages → Livewire reads them on next render.

### Loopback enforcement (FND-01, PLT-01)

**Two-layer defense:**

1. **Server bind:** Laravel Herd binds to 127.0.0.1 by default; `php artisan serve` also defaults to 127.0.0.1. Document in README that any production-style serving (PHP-FPM behind nginx) MUST explicitly bind to 127.0.0.1 only.

2. **Application middleware** — even if the server is misconfigured, the app refuses non-loopback requests:

```php
// Modules\Core\Internal\Http\Middleware\LoopbackOnly
final class LoopbackOnly
{
    private const LOOPBACK_ADDRESSES = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        $serverAddr = $request->server('SERVER_ADDR');

        if ($serverAddr !== null && !in_array($serverAddr, self::LOOPBACK_ADDRESSES, true)) {
            // Defensive 404 — never reveal that the app exists on non-loopback
            abort(404);
        }

        return $next($request);
    }
}
```

Registered in `bootstrap/app.php` as a global middleware (Laravel 11+ middleware syntax).

**Pest verification test:**
```php
it('refuses requests arriving on a non-loopback interface', function () {
    $this->withServerVariables(['SERVER_ADDR' => '192.168.1.10'])
         ->get('/dashboard')
         ->assertNotFound();
});

it('allows requests on the loopback interface', function () {
    $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1'])
         ->actingAs($this->fixtureUser())
         ->get('/dashboard')
         ->assertOk();
});
```

### Session config (D-11)

`config/session.php`:
- `lifetime` = `60 * 24 * 30` = 43200 minutes (30 days)
- `expire_on_close` = `false`
- `secure` = `false` (localhost; never sent over the wire that needs HTTPS)
- `same_site` = `'strict'` (defense in depth even on localhost — prevents random extension/tab leaking)
- `http_only` = `true`

---

## Money Wire-Up

### `Money` value object location and shape

`Modules\Ledger\Public\ValueObjects\Money` — wraps `Brick\Money\Money`:

```php
namespace Modules\Ledger\Public\ValueObjects;

use Brick\Money\Money as BrickMoney;
use Brick\Math\RoundingMode;

final class Money
{
    private function __construct(
        private readonly BrickMoney $inner,
    ) {}

    public static function ofMinor(int $minor, string $currencyCode): self
    {
        return new self(BrickMoney::ofMinor($minor, $currencyCode));
    }

    public function plus(self $other): self
    {
        return new self($this->inner->plus($other->inner));   // throws if currencies differ
    }

    public function minus(self $other): self
    {
        return new self($this->inner->minus($other->inner));
    }

    public function toMinor(): int { return $this->inner->getMinorAmount()->toInt(); }
    public function currency(): string { return $this->inner->getCurrency()->getCurrencyCode(); }
    public function isNegative(): bool { return $this->inner->isNegative(); }
    public function format(string $locale = 'nl_NL'): string { return $this->inner->formatTo($locale); }
}
```

### Eloquent cast: `MoneyMinorCast`

Custom cast that reads two columns (`amount_minor` + `currency`) and presents a single `Money` attribute:

```php
namespace Modules\Ledger\Internal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

final class MoneyMinorCast implements CastsAttributes
{
    public function __construct(
        private readonly string $minorColumn = 'amount_minor',
        private readonly string $currencyColumn = 'currency',
    ) {}

    public function get($model, string $key, $value, array $attributes): Money
    {
        return Money::ofMinor(
            minor: (int) $attributes[$this->minorColumn],
            currencyCode: (string) $attributes[$this->currencyColumn],
        );
    }

    public function set($model, string $key, $value, array $attributes): array
    {
        if (!$value instanceof Money) {
            throw new InvalidArgumentException("Money cast expects {Money}, got " . get_debug_type($value));
        }
        return [
            $this->minorColumn => $value->toMinor(),
            $this->currencyColumn => $value->currency(),
        ];
    }
}
```

On the Eloquent model:
```php
final class Transaction extends Model
{
    protected $casts = [
        'amount'         => MoneyMinorCast::class,      // exposes Money from (amount_minor, currency)
        'settled_amount' => [MoneyMinorCast::class, 'settled_amount_minor', 'settled_currency'],
        'posted_at'      => 'immutable_date',
        'booked_at'      => 'immutable_datetime',
        'value_date'     => 'immutable_date',
    ];
}
```

### Where the factory lives

`Money::ofMinor($int, $code)` is the **only** public construction path. No `fromString`, no `fromFloat`, no parser-of-money-strings. Adapters parse their source representation into `int $minor` via their own parser (e.g. `parseAmountMinor` in AsnCsvAdapter), then call `Money::ofMinor` at the boundary.

[VERIFIED: brick/money — `ofMinor()` accepts integer minor units and currency code; immutable; arithmetic methods preserve immutability]
[VERIFIED: Laravel custom-cast pattern with two backing columns — laravel.com/docs/13.x/eloquent-mutators]

### Currency table seeding

`Modules\Ledger\Internal\Seeders\CurrenciesSeeder` runs during `diederik:install` and seeds EUR, USD, GBP at minimum. brick/money has its own currency registry (ISO 4217); the DB table provides FK integrity for `transactions.currency` and `transactions.settled_currency`.

---

## SQLite WAL Configuration (FND-06)

### Per Laravel 11+ pattern: `config/database.php` connection config

```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => env('DB_DATABASE', database_path('database.sqlite')),
    'prefix' => '',
    'foreign_key_constraints' => true,
    'journal_mode' => 'WAL',         // FND-06
    'synchronous' => 'NORMAL',       // FND-06
    'busy_timeout' => 5000,          // 5s lock wait (single writer = SQLite still needs this)
],
```

Laravel reads these and applies them when connections are opened. [VERIFIED: laravel-news.com/using-sqlite-in-production-with-laravel; ryangjchandler.co.uk/posts/enabling-wal-mode-with-sqlite-in-laravel]

### Alternative if config keys are insufficient: ServiceProvider boot

```php
// Modules\Core\Internal\Providers\SqliteOptimizationsProvider
final class SqliteOptimizationsProvider extends ServiceProvider
{
    public function boot(DatabaseManager $db): void
    {
        $connection = $db->connection();
        if ($connection->getDriverName() !== 'sqlite') return;

        $connection->statement('PRAGMA journal_mode = WAL');
        $connection->statement('PRAGMA synchronous = NORMAL');
        $connection->statement('PRAGMA busy_timeout = 5000');
        $connection->statement('PRAGMA foreign_keys = ON');
        $connection->statement('PRAGMA temp_store = MEMORY');
    }
}
```

`journal_mode = WAL` is persistent (set once per DB file), but `synchronous`/`busy_timeout`/`temp_store` are connection-scoped — boot is the canonical place.

### What WAL means for Phase 1

- Readers no longer block writers — the Livewire dashboard can render while an import is committing.
- WAL creates `.sqlite-wal` and `.sqlite-shm` sidecar files. CONTEXT.md notes the schema considerations land in Phase 1; `db:backup` (FND-05) is Phase 11. Document in the README that **plain `cp` of `database.sqlite` is forbidden** — Phase 11 will provide the safe backup command.

[CITED: sqlite.org/wal.html — WAL mode + synchronous=NORMAL contract]
[CITED: research/PITFALLS.md Pitfall 10 — backups must use online backup or `VACUUM INTO`]

---

## Upload Wizard Architecture (D-13, D-14)

### State machine

```
[ Upload page ] --submit-> [ Pre-parse validation ]
                                     |
                       valid? --no-> [ Error: bad file ]
                                     |
                                    yes
                                     v
                     [ Parse + Normalize + Fingerprint (in-memory) ]
                                     |
                          unknown IBAN found?
                                     |
                                    yes -> [ Inline account-naming step ]
                                                     |
                                                     v
                                            [ User names account → save Account row ]
                                                     |
                                                     v
                                     [ Preview table: NEW / DUPLICATE / ERROR per row ]
                                                     |
                                       --discard-> [ Discard import — ImportRun.status='discarded' ]
                                       --confirm-> [ Persist via RecordTransactions ]
                                                     |
                                                     v
                                            [ Results summary page ]
```

### Storage layout

- Raw uploaded file: `storage/imports/{user_id}/{import_run_id}/{original_filename}`
- `import_runs.raw_file_path` points to it.
- File persists even after `discarded` for debugging; cleanup is deferred (operational hardening, Phase 11).

### In-memory preview cache

The preview wizard parses + normalizes + fingerprints in-memory, presents rows to the user, then re-runs the persist phase only on confirm. **The parsed rows are cached server-side keyed by `import_run_id`** (Laravel cache repository, 30-minute TTL). This avoids re-parsing on confirm.

Alternative (cleaner, no cache): persist the `CanonicalTransaction` DTOs to a temporary `pending_imports` table during preview, delete on confirm-or-discard. **Recommend the cache approach for Phase 1** — simpler, no migration, and the user typically confirms within seconds.

### Per-row status determination (NEW / DUPLICATE / ERROR)

- **ERROR:** Adapter raised an exception during parse, or the row failed currency/amount validation in Normalize, or the row's account couldn't be resolved.
- **DUPLICATE:** The composed fingerprint already exists in `transactions` (via lookup, not insert attempt).
- **NEW:** Neither of the above.

This is computed **during preview**, not via INSERT attempt — so the rendered preview is honest.

---

## Platform & Privacy Guardrails

### PLT-01 (localhost only)

- Herd binds to 127.0.0.1 by default
- `LoopbackOnly` middleware refuses non-loopback (`## Auth + Loopback Binding`)
- Pest test verifies the refusal

### PLT-02 (SQLite path outside iCloud/OneDrive/Dropbox)

- `php artisan diederik:install` validates `database_path()` is not under any of:
  - `~/Library/Mobile Documents/`
  - `~/iCloud Drive/`
  - `~/OneDrive/`
  - `~/Dropbox/`
  - any path containing the literal token `.icloud` (macOS placeholder)
- If detected, install command refuses and prints a helpful message
- README documents the recommended path: `~/Development/diederik/database/database.sqlite`

### PLT-05 (no `ext-imap`)

- `composer.json` MUST NOT contain `"ext-imap"` in `require` or `require-dev`
- CI grep gate (a Pest test or a tiny shell check):

```php
it('does not depend on ext-imap', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
    expect($require)->not->toHaveKey('ext-imap');
});
```

[CITED: research/PITFALLS.md Pitfall 5; php.watch/versions/8.4/imap-unbundled]

### `Cache-Control: no-store` on authenticated routes

Defense against browser back-button finance-data leaks (PITFALLS Security 4):

```php
// Modules\Core\Internal\Http\Middleware\NoStoreFinancialData
final class NoStoreFinancialData
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }
}
```

Applied to the `auth` middleware group.

---

## Pest 3 Test Architecture

### Folder layout

Tests follow nwidart per-module convention:

```
Modules/<Name>/tests/
├── Unit/                                # pure PHP, no DB
│   └── ...
├── Feature/                             # full Laravel boot, DB, HTTP
│   └── ...
├── Contracts/                           # shared cross-module contracts
│   ├── IdempotencyContractTest.php
│   └── BoundaryArchTest.php
├── fixtures/
│   ├── asn-sample-1.csv
│   └── asn-jan.csv
├── Pest.php                              # module-local Pest config
└── TestCase.php                          # extends framework TestCase
```

Plus a top-level `tests/` for cross-module integration tests (the wizard happy path; the install command end-to-end).

### Datasets for the row-shape parity tests

```php
dataset('asn_csv_rows', [
    'Albert Heijn debit'      => [['2026-05-03', 'NL00ASNB...', 'NL00INGB...', 'AH AMSTERDAM', /* ... */], expectedCanonical: [...]],
    'Salary credit'           => [['2026-05-25', 'NL00ASNB...', 'NL00ABNA...', 'EMPLOYER B.V.', /* ... */], expectedCanonical: [...]],
    'Multi-line description'  => [['...', '...', '...', 'PAYPAL EUROPE', /* ... */, "EUR 12.34\rREF: 123"], expectedCanonical: [...]],
]);

it('normalizes ASN CSV rows to canonical transactions', function (array $row, array $expected) {
    $canonical = $this->normalizer->normalize($row);
    expect($canonical->toArray())->toMatchArray($expected);
})->with('asn_csv_rows');
```

### Architecture tests (`pest-plugin-arch`)

```php
// Modules/<X>/tests/Contracts/BoundaryArchTest.php
arch('Models are only imported within their own module')
    ->expect('Modules\Ledger\Models')
    ->toOnlyBeUsedIn(['Modules\Ledger']);

arch('No facade usage in domain code')
    ->expect('Illuminate\Support\Facades')
    ->not->toBeUsedIn('Modules');

arch('DTOs are immutable readonly')
    ->expect('Modules\Ledger\Public\Dto')
    ->toBeReadonly();
```

### Snapshot tests (`spatie/pest-plugin-snapshots`)

For CSV parsing parity — given a fixture, the normalized output is captured as a snapshot:

```php
it('produces consistent canonical output for the ASN sample', function () {
    $rows = iterator_to_array($this->adapter->parse(__DIR__ . '/fixtures/asn-sample-1.csv', $this->accountResolver));
    expect($rows)->toMatchSnapshot();
});
```

When the sample changes (or the normalizer changes), the snapshot diff makes the change visible in review.

### Test database & transactions

- Use SQLite **in-memory** (`:memory:`) for tests — fast, clean, parallel-safe with Pest's `--parallel`.
- `RefreshDatabase` trait or per-test transaction rollback.
- Disable WAL pragmas in test config (in-memory doesn't support WAL).

---

## Larastan Level 10 Strict + DI Gotchas

### Known issues running level 10 on Laravel 12/13 + Livewire 4

1. **"Target class [livewire] does not exist"** — known false-positive when Larastan analyses Livewire-using code without the Livewire extension. Fix: install `calebdw/larastan-livewire` and add to `phpstan.neon` `includes:`. [VERIFIED: GitHub larastan/larastan discussion #1345]

2. **Eloquent magic — return types of `Model::find()`, `where()->first()`** — Larastan provides good Eloquent stubs but level 10 still flags some cases. Fix: explicit `@return Builder<Transaction>` PHPDoc on scopes; or use `$model->newQuery()` and lean on the `Builder` type.

3. **Livewire component property typing** — public properties on components must have explicit `@var` annotations or be typed PHP properties; Livewire 4 supports typed properties.

4. **`spatie/laravel-data` DTOs** — `Data::from(...)` returns `static`; Larastan handles it well in 4.x.

5. **brick/money** — fully typed; no special config needed.

### Acceptable baseline

`phpstan-baseline.neon` is for **legacy errors only**. Phase 1 starts at level 10 with **zero baseline entries** — the baseline file MUST NOT exist at the end of Phase 1. Any error is either a real bug or a missing PHPDoc annotation.

### `phpstan.neon` template for Phase 1

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/calebdw/larastan-livewire/extension.neon
    - vendor/canvural/larastan-strict-rules/rules.neon

parameters:
    level: max                      # level 10 in current Larastan
    paths:
        - Modules
        - app
        - bootstrap/app.php
    excludePaths:
        - Modules/*/Database/Migrations
        - Modules/*/Routes
        - Modules/*/tests
        - database/migrations
    checkMissingIterableValueType: true
    checkGenericClassInNonGenericObjectType: true
    reportUnmatchedIgnoredErrors: true
    strictRules:
        allowedGlobalFunctions:
            - 'app_path'
            - 'base_path'
            - 'config_path'
            - 'database_path'
            - 'resource_path'
            - 'storage_path'
            - 'public_path'
            - 'view'                # template helper; OK in Livewire render() return
    rules:
        - App\PhpStan\Rules\BoundaryRule       # custom — Modules\X\Internal banned from outside X
```

[VERIFIED: larastan.org — level scale + extension model]

---

## Walking Skeleton — Thinnest Path Through All Seams

To prove the spine, the **minimal end-to-end demo** must traverse:

1. **HTTP routing** — `/login` → POST `/login` → `/` (dashboard)
2. **DI container** — Livewire `LoginForm` resolves `StatefulGuard` via mount
3. **DB** — User row exists (from `diederik:install`), session row created, transaction inserts on confirm
4. **Livewire reactivity** — login form, upload wizard, dashboard reload, triage assignment
5. **Pest test runner** — green on idempotency contract + boundary arch test
6. **Larastan** — green at level 10 strict
7. **Pint** — green
8. **No queue worker** — D-15 sync

### Riskiest seams (highest blast radius if broken)

| Seam | Why risky | Mitigation |
|------|-----------|------------|
| **Larastan custom BoundaryRule** | Wrong rule → either nothing is enforced (silent permission) or false positives block everything | Ship the rule WITH its own tests; verify it fires on a deliberately-bad fixture file |
| **MoneyMinorCast on two columns** | Buggy cast → silent currency mixing | Pest test: round-trip Money → DB → Money → ensure both `amount_minor` and `currency` survive |
| **Composite UNIQUE on (account_id, posted_at, amount_minor, currency, normalized_counterparty, source_ref)** | If NULL semantics are wrong, duplicates slip in | Test with NULL `source_ref` for two non-duplicate rows AND for two duplicate rows |
| **Fortify + hand-written Livewire form** | Fortify's auth pipeline is opaque; custom form drift breaks auth silently | E2E Pest test: `POST /login` with right creds → redirect to `/`; wrong creds → flash error; both via the real Fortify pipeline |
| **`period_start_day` derivation** | Off-by-one on month boundaries is a "looks done but isn't" bug | Pest dataset: 28 cases (day-1..day-28 × month-boundary instants) |
| **DI-only enforcement in Livewire components** | Livewire's `mount()` does the injection — drift to using helpers inside the component goes unnoticed | Larastan + arch test that `Modules\**\Http\Livewire\**` does not import `Illuminate\Support\Facades` |
| **`AccountResolver` for unknown IBAN** | Unknown IBAN must pause the wizard, not silently create an account | Pest feature test for both paths |

### Seams that can be deferred (low risk in Phase 1)

- Queue worker (D-15)
- launchd plists (Phase 6)
- WAL backup automation (Phase 11)
- Per-merchant memory learning (Phase 7)
- Chain resolution (Phase 5)
- Cache invalidation (no caching in Phase 1)

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| CSV parsing with BOMs, encodings, delimiters | A custom `fgetcsv` loop | **`league/csv`** | Handles UTF-8 BOM, Windows-1252→UTF-8, streaming, header mapping; 173M installs |
| Money arithmetic | Float multiplication or BCMath-by-hand | **`brick/money`** | Immutable Money objects, exact integer arithmetic, currency safety, `ofMinor()` factory |
| DTOs with validation | Hand-rolled value classes | **`spatie/laravel-data`** | Typed, immutable, validates, serializes; the only sane DTO in 2026 Laravel |
| Authentication backend | Custom controllers + middleware | **Laravel Fortify** | Headless, battle-tested, handles password reset, session timing, 2FA when needed |
| Facade-ban static analysis | Custom regex on source | **`JoeyMckenzie/facadeless`** | PHPStan-native, configurable allow list |
| Helper-ban static analysis | Same | **`canvural/larastan-strict-rules`** | `allowedGlobalFunctions` whitelist |
| Livewire static analysis | Skip it | **`calebdw/larastan-livewire`** | Resolves computed properties + `Component` magic |
| Custom Pest dataset infrastructure | Hand-rolled parameterized loops | **Pest 3 `->with(...)`** | First-class parameterization, named cases, bound datasets resolved after `beforeEach` |
| Single-file Livewire components | Manual class + view files | **Livewire 4 SFC syntax (or Volt)** | Single .php file with class + Blade in one |
| Module boundary enforcement | Code review only | **Custom PHPStan rule** + **prinsfrank/larastan-architecture-rules** | CI fails on violation; no human discipline needed |

**Key insight:** The Laravel + Livewire + Pest + PHPStan ecosystem in 2026 has dedicated libraries for each architectural concern Phase 1 cares about. Hand-rolling any of the above is a regression to 2018-era Laravel; every replacement is well-maintained, has >100k installs, and is already in the project's stack pin.

---

## Common Pitfalls (Phase-1-specific)

### Pitfall 1: Float roundtrip in amount parsing

**What goes wrong:** `(int)((float)"0.29" * 100)` returns 28 on some PHP builds (float `0.28999...` truncates).
**Why it happens:** IEEE-754 has no exact `0.29`.
**How to avoid:** Parse via regex into integer minor units; never go through float (`## ASN CSV Ingestion`).
**Warning signs:** Any `(int)((float)$value * 100)` or `round($amount * 100)` pattern.

### Pitfall 2: Description-text in fingerprint

**What goes wrong:** Bank rewrites narrative between two CSV exports; same logical transaction produces different fingerprints; row imported twice.
**Why it happens:** Free-text descriptions are unstable (PITFALLS Pitfall 2).
**How to avoid:** Fingerprint uses `counterparty_normalized` (name field), NOT `description`. Per D-16.
**Warning signs:** Description-string fields in any fingerprint composer; missing `normalization_version` column.

### Pitfall 3: Livewire `auth()` slip-in

**What goes wrong:** A Livewire component reaches for `auth()->user()` because mount-time DI feels heavy.
**Why it happens:** Lazy paths exist; Livewire docs sometimes show `auth()->user()` examples.
**How to avoid:** Larastan ban (`facadeless`); arch test that `Modules\**\Http\Livewire\**` cannot import facades.
**Warning signs:** First PR introducing a Livewire component without a `mount(CurrentUser $user)` injection.

### Pitfall 4: `__construct` on Livewire components

**What goes wrong:** Constructor runs on every request, but Livewire re-hydrates components from JSON between requests — constructor-injected services don't persist by design. The state can confuse a developer who expects construct-once semantics.
**Why it happens:** Constructor-bias from non-Livewire Laravel.
**How to avoid:** Use `mount()` for one-time init, `boot()` for every-request services. Document the pattern in CONVENTIONS.md (Phase 1 deliverable).
**Warning signs:** Service properties initialised in `__construct` instead of `boot()`.

### Pitfall 5: SQLite UNIQUE + NULL semantics

**What goes wrong:** SQLite treats each NULL as distinct in a UNIQUE index — duplicates with NULL `source_ref` slip through.
**Why it happens:** SQL standard says NULL ≠ NULL; SQLite follows it.
**How to avoid:** Ensure `normalized_counterparty` is NEVER NULL (compute a sentinel like `'_no_counterparty'` if name + description are both empty). Then the composite UNIQUE catches every duplicate.
**Warning signs:** `counterparty_normalized` column without NOT NULL constraint.

### Pitfall 6: Volt SFC migration footguns (Livewire 3 → 4)

**What goes wrong:** Tutorials online still reference Livewire 3 patterns (`@volt` directives, different component class extension).
**Why it happens:** Livewire 4 shipped Jan 2026; ecosystem documentation is mixed.
**How to avoid:** Pin to Livewire 4 (verified in CONTEXT canonical_refs); reference only `livewire.laravel.com/docs/4.x` in plans; reject any 3.x example in code review.
**Warning signs:** Component classes extending `Livewire\Volt\Component` instead of `Livewire\Component`; `@volt` directives in Blade.

### Pitfall 7: Period boundary off-by-one with `period_start_day`

**What goes wrong:** `setDay(28)` on Feb 14 jumps forward to Feb 28; the user expected the period to start Jan 28.
**Why it happens:** Carbon's `setDay` rolls into next month when day > current month length, but here the issue is "before vs after start day" arithmetic.
**How to avoid:** The algorithm in `PeriodQuery::containing()` (`## This-Period-At-A-Glance Query`); 28-case Pest dataset.
**Warning signs:** Off-by-one user complaints; salary on the 25th showing as "previous period."

### Pitfall 8: Loopback bypass via `X-Forwarded-For`

**What goes wrong:** If Laravel trusts a reverse proxy, `SERVER_ADDR` may be the proxy's IP and a remote attacker can bypass loopback check via forged headers.
**Why it happens:** Misconfigured `TrustProxies` middleware.
**How to avoid:** Phase 1 has no reverse proxy. Ensure `app/Http/Middleware/TrustProxies.php` is empty (Laravel 11+ uses `$middleware->trustProxies(at: [])` in bootstrap).
**Warning signs:** Any non-empty trusted-proxy list in `bootstrap/app.php`.

### Pitfall 9: SQLite WAL files getting backed up by Time Machine

**What goes wrong:** `.sqlite-wal` and `.sqlite-shm` mid-write get snapshotted; restore is corrupt.
**Why it happens:** Time Machine doesn't know SQLite WAL semantics.
**How to avoid:** Per PLT-02, DB is outside iCloud Drive; document in README that the DB path should be excluded from Time Machine, or wait for Phase 11's `db:backup` artisan command.
**Warning signs:** None visible until restore fails. Document the threat.

### Pitfall 10: ASN CSV encoding misdetection

**What goes wrong:** Windows-1252-encoded bytes interpreted as UTF-8 produce mojibake in counterparty names; "café" becomes "cafÃ©"; normalized fingerprints diverge between systems.
**Why it happens:** Dutch banks historically export legacy encodings.
**How to avoid:** Always invoke `League\Csv\CharsetConverter::addTo($reader, 'windows-1252', 'utf-8')`. Empirically verify via the sample task. league/csv's converter is idempotent if the input was already UTF-8.
**Warning signs:** Non-ASCII characters in `counterparty_normalized` looking wrong; fingerprint mismatches across re-imports.

---

## Code Examples

### `RecordTransactions` action (Ledger)

```php
namespace Modules\Ledger\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Dto\RecordResult;
use Modules\Ledger\Public\Services\FingerprintComposer;

final class RecordTransactions
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly FingerprintComposer $fingerprints,
    ) {}

    /**
     * @param iterable<CanonicalTransaction> $canonical
     */
    public function __invoke(iterable $canonical): RecordResult
    {
        $inserted = 0;
        $duplicates = 0;

        $this->db->connection()->transaction(function () use ($canonical, &$inserted, &$duplicates): void {
            foreach ($canonical as $row) {
                $fingerprint = $this->fingerprints->compose($row);
                $attrs = $row->toAttributes() + [
                    'fingerprint' => $fingerprint,
                    'fingerprint_version' => $this->fingerprints->version(),
                ];

                $effected = Transaction::query()->insertOrIgnore($attrs);
                if ($effected === 1) {
                    $inserted++;
                } else {
                    $duplicates++;
                }
            }
        });

        return new RecordResult(inserted: $inserted, duplicates: $duplicates);
    }
}
```

[CITED: research/ARCHITECTURE.md `## Pattern 1: Action Pattern`]

### `FingerprintComposer` (Ledger — exposed in Public)

```php
namespace Modules\Ledger\Public\Services;

use Modules\Ledger\Public\Dto\CanonicalTransaction;

final class FingerprintComposer
{
    public const NORMALIZATION_VERSION = 1;

    public function compose(CanonicalTransaction $tx): string
    {
        $tuple = implode('|', [
            $tx->accountId,
            $tx->postedAt->toDateString(),
            $tx->amountMinor,
            $tx->currency,
            $tx->counterpartyNormalized,
            $tx->sourceRef ?? '',
        ]);

        return hash('sha256', $tuple);
    }

    public function normalize(string $rawName): string
    {
        $s = mb_strtolower($rawName, 'UTF-8');
        $s = $this->stripDiacritics($s);
        $s = preg_replace('/[^\p{L}\p{N}& ]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_substr(trim($s), 0, 80, 'UTF-8');
    }

    public function version(): int
    {
        return self::NORMALIZATION_VERSION;
    }

    private function stripDiacritics(string $s): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    }
}
```

### `CurrentUser` service (Core)

```php
namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser as Contract;

final class CurrentUserService implements Contract
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function id(): int
    {
        $user = $this->auth->guard('web')->user();
        if (!$user instanceof User) {
            throw new NotAuthenticatedException();
        }
        return (int) $user->getKey();
    }

    public function user(): User
    {
        $user = $this->auth->guard('web')->user();
        if (!$user instanceof User) {
            throw new NotAuthenticatedException();
        }
        return $user;
    }

    public function periodStartDay(): int
    {
        return $this->user()->period_start_day ?? 1;
    }
}
```

[CITED: laravel.com/docs/13.x/container — `Illuminate\Contracts\Auth\Factory` is the canonical contract for the `auth` binding]

---

## State of the Art

| Old Approach | Current Approach (2026) | When Changed | Impact for Phase 1 |
|--------------|-------------------------|--------------|--------------------|
| `auth()`, `Auth::user()` everywhere | Inject `Illuminate\Contracts\Auth\Factory` + project's own `CurrentUser` wrapper | Pre-existing; CLAUDE.md project policy | Locked: no facades anywhere |
| `Money` as float / DECIMAL | `BIGINT` minor units + `brick/money` value objects + Eloquent custom cast | Industry consensus ~2020; reinforced by every personal-finance app post-mortem | Locked: FND-04 + FND-07 |
| Hand-rolled `fgetcsv` loops | `league/csv` streaming Reader + CharsetConverter | League CSV stable ~2018; standard since ~2020 | Adopt |
| Hand-rolled DTOs | `spatie/laravel-data` immutable, validated, serialisable | Spatie v4 (2024) made it production-default | Adopt at every module boundary |
| Livewire 3 with `@volt` directive | Livewire 4 with single-file class-based components (SFC) | Livewire 4 GA Jan 2026 | Adopt — match the version pin |
| Breeze / Jetstream starter scaffolding | Fortify headless backend + hand-rolled Livewire UI | Project policy (CONTEXT D-09); calm aesthetic | Adopt |
| `ext-imap` extension | Pure-PHP IMAP via `webklex/laravel-imap` | PHP 8.4 dropped ext-imap (Nov 2024); PLT-05 enforces | N/A in Phase 1; lockout via composer.json check |
| PHPUnit-only test style | Pest 3 with arch tests + datasets + snapshots | Pest 3 GA 2024; Spatie/Livewire/Filament all Pest by 2026 | Adopt |
| `phpstan-laravel` standalone | `larastan` (renamed; current under `larastan/larastan`) | ~2021 | Adopt level max (10) |

**Deprecated / outdated patterns to avoid:**
- Laravel Breeze / Jetstream UI scaffolds — explicitly NOT in stack
- Livewire 3 component class syntax (extending `Livewire\Volt\Component`)
- Floating-point money columns
- PSD2 / open-banking adapters for ASN (out of scope hard-exclusion per REQUIREMENTS.md)

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | ASN CSV is 17–18 columns, comma-delimited, headerless, period-decimal, Windows-1252 or UTF-8 encoded | `## ASN CSV Ingestion` | HIGH — wrong column map = wrong amounts/dates parsed; mitigated by "first task = empirical sample fixture" |
| A2 | ASN CSV column 0 = `Boekingsdatum` (`dd-mm-yyyy`), column 10 = `Transactiebedrag` (signed amount), column 15 = `Volgnummer`, column 3 = counterparty name | `## ASN CSV Ingestion` | HIGH — same as A1; resolve with real export |
| A3 | Larastan's "level max" === level 10 in 2026 (the documented "9 is max" claim is outdated) | `## DI-Only Patterns — Enforcement`, `## Larastan Level 10` | LOW — even if level 9 is actually max, the config keyword `level: max` always means "the highest"; CI behaviour is identical |
| A4 | ASN bank does not use semicolon `;` as delimiter (community converters use comma) | `## ASN CSV Ingestion` | LOW — league/csv `setDelimiter()` is a one-line fix; HeaderSniffer can also auto-detect |
| A5 | The user-facing flow "single-user app on localhost" tolerates Fortify's standard CSRF protection without special handling | `## Auth + Loopback Binding` | LOW — Fortify ships sane CSRF defaults; only an issue if a future API needs different behavior |
| A6 | `php artisan diederik:install` running idempotently is acceptable (re-running with the same email password = no-op) | `## Module Layout — Core` | LOW — clarify with user if running install twice is meant to overwrite the password or be a no-op |
| A7 | `period_start_day` lives as a column on `users`, not a `user_preferences` table | `## Canonical Transaction Schema` | LOW — planner's discretion per CONTEXT.md; either decision is reversible |
| A8 | Categorization's `Public/Actions/AssignCategory` calling `Ledger/Public/Actions/UpdateTransactionCategory` is the correct interpretation of "Ledger is the only writer to `transactions`" | `## Module Layout — Categorization` | MEDIUM — if user disagrees, Categorization writes directly to `transactions.category_id` (faster, less elegant); confirm during plan-checker |
| A9 | The composite UNIQUE on `transactions` IS the fingerprint contract; the `fingerprint` SHA-256 column is redundant for uniqueness (a human-readable derivative) | `## Canonical Transaction Schema` | LOW — keeping both is harmless; resolving down to one is a cleanup |
| A10 | Larastan custom BoundaryRule will be ~120 LOC; canvural + facadeless + larastan-livewire combine cleanly without conflicts | `## DI-Only Patterns — Enforcement` | LOW — all three are independent rule registrations |
| A11 | The Livewire upload component's preview cache (server-side, 30-minute TTL keyed on `import_run_id`) is acceptable; alternative `pending_imports` table can be added if cache eviction proves problematic | `## Upload Wizard Architecture` | LOW — both approaches are reversible |
| A12 | Volt single-file syntax and Livewire 4 class-based syntax are interchangeable; project uses Livewire 4 class form for consistency with non-Volt components | `## Module Layout` | LOW — preference, not correctness |

**User-facing confirmation needed for:** A1 + A2 (real export), A6 (install command semantics), A8 (Categorization writes).

---

## Open Questions (RESOLVED)

1. **Exact 2026 ASN CSV column layout**
   - What we know: Historical layout from open-source converters; "CSV met IBAN" naming convention.
   - What's unclear: Whether ASN has changed format since 2020 (community converters all date pre-2021).
   - Recommendation: First task in the ingestion wave = drop a real export at `tests/fixtures/asn-sample-1.csv`, pin in `AsnCsvColumnMap`, snapshot-test the parsed output.
   - **RESOLVED:** Real anonymized ASN export pinned by Plan 04 T-01-04-01 BLOCKING `checkpoint:human-action`. The `AsnCsvAdapter` (Plan 04 T-01-04-03) is written test-first against `tests/fixtures/asn-sample-1.csv`; `AsnCsvColumnMap` carries an `EMPIRICAL` PHPDoc marker recording confirmation date and any deltas from the [ASSUMED] layout. Includes the Windows-1252 vs UTF-8 sub-question: Plan 04 T-01-04-02's `HeaderSniffer` runs `mb_detect_encoding` and feeds `league/csv\CharsetConverter` (per `AsnCsvHeaderProfile::SOURCE_ENCODING`); column-map detection and encoding are empirical, not assumed.

2. **Are AsnCsvAdapter + RecordTransactions idempotent across PHP serialization re-encoding?**
   - What we know: Composite UNIQUE catches at DB layer regardless.
   - What's unclear: Whether running PHP `serialize(unserialize($x))` on a DTO before fingerprinting produces a different `counterparty_normalized` value.
   - Recommendation: Pest test that explicitly serializes a CanonicalTransaction, unserializes, then fingerprints — must match the direct path.
   - **RESOLVED:** Phase 1 eliminates the `unserialize` boundary entirely. Plan 05 T-01-05-01's `PreviewCache` serializes via `json_encode($canonicalTransactions)` and rehydrates via `CanonicalTransaction::from($row)` (spatie/laravel-data) — no `unserialize` on user data anywhere. Plan 03's `FingerprintComposerTest` covers the normalize-determinism contract (same input → same SHA-256); the composite UNIQUE on `transactions` is the second layer of defense regardless of serialization path.

3. **Should `MerchantMemory` schema include a `version` column from Phase 1?**
   - What we know: Phase 7 will own learning behaviour.
   - What's unclear: Whether the normalization algorithm version stored on `transactions.fingerprint_version` should also be on `merchant_memories` so the Phase 7 learning logic can detect stale memories.
   - Recommendation: Add `normalization_version` to `merchant_memories` now (one extra column, zero risk).
   - **RESOLVED:** Plan 03 T-01-03-01 ships `merchant_memories` with the `normalization_version` column included in the migration (`2026_05_12_010006_create_merchant_memories_table.php`). Phase 7's CAT-02 learning can detect stale memories without a follow-up migration. The own-IBAN auto-detection sub-question (how `EloquentAccountResolver` recognises the fixture's own IBAN on first parse) is also resolved: Plan 05 T-01-05-01's `EloquentAccountResolver` looks up Account by IBAN scoped to the current user; the `seedFixtureUserAndAccount()` helper on `tests/TestCase` (Plan 01 T-01-01-03) seeds `User id=1` plus an Account row with `iban = 'NL00ASNB0123456789'` (the documented anonymization-protocol value from Plan 04 T-01-04-01), so the resolver returns Known on first parse and the `IdempotencyContractTest` does not stall on unknown-IBAN prompts.

4. **Default `period_start_day` UX in install command**
   - What we know: D-19 says the install command prompts.
   - What's unclear: Whether to default-yes "1 (calendar month)" or prompt with help text first.
   - Recommendation: Prompt with explanation: "Period start day (1 = calendar month, 25 = salary cycle starting 25th): [1]" — non-blocking; default 1.
   - **RESOLVED:** Plan 02 T-01-02-02's `diederik:install` command prompts with explanatory copy "Period start day (1-28, 1=calendar month)" and accepts a `--period-start-day` CLI flag (default 1). Persisted on `users.period_start_day` (A7); the `InstallCommandTest` covers both the option-driven and prompted paths. `Modules\Ledger\Public\Services\PeriodQuery` (Plan 03) reads the column via constructor-injected `CurrentUser` — no `config()` helper, no separate `app_config` table needed for Phase 1.

5. **Settings page — ship in Phase 1 or defer?**
   - What we know: D-19 closing note says planner picks; UI-SPEC lists it for completeness.
   - Recommendation: Defer to a later micro-phase. Phase 1 ships install command + manual `.env`-style override for now. The dashboard surfaces the current period prominently; changing it is rare.
   - **RESOLVED:** Defer to a later micro-phase. Phase 1 ships only (a) the `diederik:install` prompt (Plan 02 T-01-02-02) and (b) the implicit `users.period_start_day` column (Plan 02 migration); no in-app UI surface. This deferral is recorded in `01-CONTEXT.md` §Deferred Ideas ("Settings UI for `period_start_day` — defer to a later phase"). To change the value after install in Phase 1, the user edits the DB directly or re-runs install on a fresh DB — acceptable for a single-user localhost tool.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.5 | Runtime | unknown — needs user check | — | none — install via Herd |
| Laravel Herd | Dev server | unknown | — | `php artisan serve` |
| Composer 2.x | Dependency install | unknown | — | none — required |
| Node + npm | Tailwind 4 build | unknown | — | Herd ships Node |
| SQLite 3.45+ | Local DB | unknown — Herd ships SQLite | 3.45+ | none — required |
| Git | VCS | confirmed (repo exists) | — | n/a |

**Missing dependencies with no fallback:** verify PHP 8.5, Composer 2.x, Node, SQLite via a `php artisan diederik:doctor` command shipped in Phase 1.

**Missing dependencies with fallback:** Herd → manual `php artisan serve` is fine for development.

> **Plan-phase note:** Add a Wave 0 task: "Run `php -v`, `composer -V`, `node -v`, `sqlite3 -version` and pin minimum versions in `composer.json` / `package.json` `engines`."

---

## Validation Architecture

> Nyquist validation is **enabled** (config.json `workflow.nyquist_validation: true`).

### Test Framework

| Property | Value |
|----------|-------|
| Framework | **Pest 3.x** (built on PHPUnit 11) |
| Config file | `phpunit.xml` (Pest reads it) + `tests/Pest.php` (Pest-specific config: arch presets, datasets) |
| Quick run command | `vendor/bin/pest --filter=<TestName> --stop-on-failure` |
| Full suite command | `vendor/bin/pest --parallel` |
| Coverage gate (informational) | `vendor/bin/pest --coverage --min=70` (not CI-enforced in Phase 1 — focus is correctness over coverage %) |

### Phase Requirements → Test Map

| Req ID | Behaviour | Test Type | Automated Command | File Exists? |
|--------|-----------|-----------|-------------------|-------------|
| FND-01 | App refuses non-loopback bind | Feature | `pest tests/Feature/LoopbackOnlyTest.php -x` | ❌ Wave 0 |
| FND-02 | Single-user login works via Fortify + Livewire | Feature | `pest tests/Feature/Auth/LoginFlowTest.php -x` | ❌ Wave 0 |
| FND-03 | Every domain table has `user_id` nullable | Arch | `pest tests/Contracts/UserIdColumnArchTest.php -x` | ❌ Wave 0 |
| FND-04 | No `REAL`/`FLOAT` on money columns | Arch | `pest tests/Contracts/NoFloatMoneyArchTest.php -x` | ❌ Wave 0 |
| FND-06 | SQLite WAL + synchronous=NORMAL on connection | Unit | `pest Modules/Core/tests/Unit/SqlitePragmasTest.php -x` | ❌ Wave 0 |
| FND-07 | Money arithmetic uses brick/money | Unit | `pest Modules/Ledger/tests/Unit/MoneyValueObjectTest.php -x` | ❌ Wave 0 |
| ING-01 | ASN CSV upload imports transactions | Feature | `pest Modules/Import/tests/Feature/AsnCsvImportTest.php -x` | ❌ Wave 0 |
| ING-06 | Same-file re-upload → 0 new rows | Feature | `pest tests/Contracts/IdempotencyContractTest.php -x` | ❌ Wave 0 |
| ING-07 | Source declared in UI (no auto-detect) | Feature | `pest Modules/Import/tests/Feature/UploadWizardTest.php::test_requires_source_declaration -x` | ❌ Wave 0 |
| ING-08 | Raw source row link preserved | Unit | `pest Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php::test_preserves_raw_payload -x` | ❌ Wave 0 |
| LED-01 | Accounts have type + currency | Unit | `pest Modules/Ledger/tests/Unit/AccountModelTest.php -x` | ❌ Wave 0 |
| LED-02 | Transaction.type enum populated | Unit | `pest Modules/Ledger/tests/Unit/TransactionTypeTest.php -x` | ❌ Wave 0 |
| MC-01 | Both original + settled amount columns present | Arch | `pest tests/Contracts/MoneyColumnsArchTest.php -x` | ❌ Wave 0 |
| CAT-01 | Category tree assignable to transaction | Feature | `pest Modules/Categorization/tests/Feature/AssignCategoryTest.php -x` | ❌ Wave 0 |
| CAT-03 | Override existing categorization | Feature | `pest Modules/Categorization/tests/Feature/AssignCategoryTest.php::test_overrides_existing -x` | ❌ Wave 0 |
| CAT-05 | Triage page lists uncategorized rows | Feature | `pest Modules/Categorization/tests/Feature/TriagePageTest.php -x` | ❌ Wave 0 |
| UI-01 | Dashboard shows period totals | Feature | `pest Modules/Ledger/tests/Feature/DashboardTest.php -x` | ❌ Wave 0 |
| UI-04 | Recent-window default = 90 days | Feature | `pest Modules/Ledger/tests/Feature/TransactionListTest.php::test_defaults_to_recent_window -x` | ❌ Wave 0 |
| UI-05 | Aesthetic compliance | **manual** (UI-SPEC checker, not unit-testable) | n/a | n/a |
| PLT-01 | Localhost-only middleware | Feature | (same as FND-01) | ❌ Wave 0 |
| PLT-02 | DB path validation in install command | Feature | `pest Modules/Core/tests/Feature/InstallCommandTest.php::test_refuses_icloud_path -x` | ❌ Wave 0 |
| PLT-05 | composer.json lacks ext-imap | Arch | `pest tests/Contracts/NoExtImapTest.php -x` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest <touched-module-or-file> --stop-on-failure` — typically <5s
- **Per wave merge:** `vendor/bin/pest --parallel` — full suite, ~30s on a fresh project, scales linearly
- **Phase gate (before `/gsd-verify-work`):** Full suite green + Larastan level max green + Pint green

### Wave 0 Gaps (all missing — greenfield project)

- [ ] **Framework install:** `composer require pestphp/pest pestphp/pest-plugin-laravel pestphp/pest-plugin-arch --dev`
- [ ] **Pest init:** `vendor/bin/pest --init`
- [ ] `tests/Pest.php` — global config (arch presets, base `TestCase`)
- [ ] `tests/TestCase.php` — extends framework TestCase, applies `RefreshDatabase` trait by default
- [ ] `tests/Contracts/IdempotencyContractTest.php` — covers ING-06 (parameterized via dataset for Phase 2+)
- [ ] `tests/Contracts/UserIdColumnArchTest.php` — covers FND-03 (DB introspection of `information_schema`-equivalent via `sqlite_master`)
- [ ] `tests/Contracts/NoFloatMoneyArchTest.php` — covers FND-04 (regex against migration files for `REAL`/`FLOAT` on `*amount*` columns)
- [ ] `tests/Contracts/MoneyColumnsArchTest.php` — covers MC-01 (DB introspection: both `*_minor` + `*_currency` columns present)
- [ ] `tests/Contracts/NoExtImapTest.php` — covers PLT-05
- [ ] `tests/Contracts/BoundaryArchTest.php` — Modules cross-import enforcement
- [ ] `tests/fixtures/asn-sample-1.csv` — anonymized real ASN export (gated on user-provided fixture)
- [ ] `tests/fixtures/asn-jan.csv` + `tests/fixtures/asn-jan-feb.csv` — overlapping-period fixtures derived from the real export
- [ ] `Modules/<X>/tests/` skeleton inside each module (Core, Ledger, Ingestion, Import, Categorization) with a per-module `TestCase.php`

### Manual-only Validation (UI-05)

UI-05 (calm Linear/Notion aesthetic) cannot be unit-tested. Validated by:
- `/gsd-ui-checker` already ran on 01-UI-SPEC.md and approved.
- Phase 1 verify-work step manually opens `/login`, `/`, `/imports/new`, `/uncategorized` and checks against the UI-SPEC contract.
- No regression infrastructure (Dusk, Percy) shipped in Phase 1.

---

## Sources

### Primary (HIGH confidence)

- [Laravel 13 Fortify documentation](https://laravel.com/docs/13.x/fortify) — `Fortify::loginView()` for custom views; headless backend
- [Laravel 13 Eloquent Mutators & Casting](https://laravel.com/docs/13.x/eloquent-mutators) — custom cast pattern for multi-column attributes
- [Livewire 4 Components](https://livewire.laravel.com/docs/4.x/components) — SFC syntax + mount/boot lifecycle
- [Livewire 4 Lifecycle Hooks](https://livewire.laravel.com/docs/4.x/lifecycle-hooks) — `mount(...)` DI; `boot(...)` for every-request services
- [brick/money on Packagist](https://packagist.org/packages/brick/money) — 0.13.0, immutable Money, `Money::ofMinor()`
- [league/csv documentation](https://csv.thephpleague.com/9.0/) — Reader, CharsetConverter, Statement
- [league/csv encoding interop](https://csv.thephpleague.com/9.0/interoperability/encoding/) — BOM handling, charset conversion
- [SQLite WAL official](https://sqlite.org/wal.html) — WAL semantics + synchronous=NORMAL contract
- [nwidart/laravel-modules on Packagist](https://packagist.org/packages/nwidart/laravel-modules) — Laravel 13 supported via v13.0.0 (Mar 2026)
- [Pest 3 datasets](https://pestphp.com/docs/datasets) — parameterized tests via `->with(...)`
- [larastan/larastan on GitHub](https://github.com/larastan/larastan) — level max, Laravel-aware static analysis
- [canvural/larastan-strict-rules on Packagist](https://packagist.org/packages/canvural/larastan-strict-rules) — `allowedGlobalFunctions` whitelist
- [JoeyMckenzie/facadeless on GitHub](https://github.com/JoeyMckenzie/facadeless) — PHPStan rule banning Laravel facades
- [calebdw/larastan-livewire](https://github.com/calebdw/larastan-livewire) — Livewire support for Larastan
- [PHPStan custom rules](https://phpstan.org/developing-extensions/rules) — AST-walking custom rule API

### Project-internal (HIGH confidence)

- `.planning/PROJECT.md` — constraints; DI-only policy
- `.planning/REQUIREMENTS.md` — REQ-ID to test mapping
- `.planning/research/STACK.md` — version pins
- `.planning/research/ARCHITECTURE.md` — modular monolith, single-table-with-type, fingerprint composition
- `.planning/research/PITFALLS.md` — 19 cross-project pitfalls
- `.planning/research/SUMMARY.md` — executive summary, dependency graph
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-CONTEXT.md` — locked Phase 1 decisions
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-UI-SPEC.md` — locked UI contract
- `CLAUDE.md` — project policy
- `~/.claude/projects/-Users-wesselverheij-Development-diederik/memory/feedback_laravel_di_only.md` — DI-only feedback

### Secondary (MEDIUM confidence — community-reported, awaiting empirical confirmation)

- [cwverhey/HomeBankCSV on GitHub](https://github.com/cwverhey/HomeBankCSV) — historical ASN CSV layout
- [dsprenkels/asn2ynab on GitHub](https://github.com/dsprenkels/asn2ynab) — ASN CSV converter
- [bartn/php-ASN-ynab-csv-converter on GitHub](https://github.com/bartn/php-ASN-ynab-csv-converter) — PHP ASN converter
- [bunni.nl: CSV-bestand bij ASN Bank downloaden](https://bunni.nl/banktransacties/mt940-asn-bank/) — Dutch user guide
- [Laravel News: Using SQLite in production with Laravel](https://laravel-news.com/using-sqlite-in-production-with-laravel) — WAL config patterns
- [Ryan Chandler: Enabling WAL mode with SQLite in Laravel](https://ryangjchandler.co.uk/posts/enabling-wal-mode-with-sqlite-in-laravel) — connection-level pragmas
- [Honeybadger: Building Livewire Components with Volt](https://www.honeybadger.io/blog/laravel-volt/) — Volt + Livewire patterns

### Tertiary (LOW confidence — single source)

- ASN bank's own PDF "Bestandsbeschrijving export bestand ASN Online Bankieren" — referenced but not fetched (binary PDF); the authoritative source if accessible

---

## Metadata

**Confidence breakdown:**
- Standard stack: **HIGH** — all libraries pinned and verified against Packagist; Laravel 13 + Livewire 4 + nwidart v13 compatibility confirmed
- Module layout: **HIGH** — derived from ARCHITECTURE.md + CONTEXT.md decisions; nwidart directory shape is conventional
- DI-only patterns: **HIGH** — every helper/facade has a documented contract equivalent; enforcement via three open-source PHPStan rule packages
- ASN CSV exact format: **MEDIUM** — depends on a real export to confirm; mitigated by "first task = fixture"
- Idempotency invariant: **HIGH** — composite UNIQUE + insertOrIgnore is a textbook pattern
- Auth + loopback: **HIGH** — Fortify headless + LoopbackOnly middleware are well-documented patterns
- Money wire-up: **HIGH** — brick/money + custom Eloquent cast is a standard pattern
- SQLite WAL: **HIGH** — Laravel 11+ supports it in config + boot
- Pest test architecture: **HIGH** — Pest 3 datasets + arch tests + snapshots all stable
- Pitfalls: **HIGH** — inherited from project-level PITFALLS.md; Phase 1 inherits the relevant ones
- Walking skeleton seams: **HIGH** — each seam mapped to its Pest test and Larastan rule

**Research date:** 2026-05-12
**Valid until:** 2026-06-11 (30 days for stable framework choices); 2026-05-19 (7 days for ASN CSV layout assumptions — re-verify on first sample drop)

---

## RESEARCH COMPLETE
