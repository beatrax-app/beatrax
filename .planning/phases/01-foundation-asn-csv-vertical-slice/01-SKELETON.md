# Walking Skeleton — diederik

**Phase:** 1
**Generated:** 2026-05-12

## Capability Proven End-to-End

> One sentence: the smallest user-visible capability that exercises the full stack.

A signed-in user, served on `http://127.0.0.1` only, can upload an ASN CSV export, preview it as NEW / DUPLICATE / ERROR per row, confirm the import, and land on a calm monochrome "this month at a glance" dashboard that shows in / out / net for the current period — and re-uploading the same CSV adds zero rows.

## Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Language runtime | PHP 8.5 (pin via `composer.json`) | CLAUDE.md tech-stack constraint; PHP 8.4 dropped `ext-imap`, project bans it (PLT-05); 8.5 is current at March 2026 |
| Framework | Laravel 13 (`laravel/framework: ^13.0`) | CLAUDE.md tech-stack constraint; Fortify, Livewire 4, queues, container contracts, custom-cast multi-column support |
| Module system | `nwidart/laravel-modules` ^13.0 with 5 modules: `Core`, `Ledger`, `Ingestion`, `Import`, `Categorization` | D-01..D-08 lock module decomposition; bounded contexts at directory level; each module exposes `Public/` and hides `Internal/` |
| Module boundary enforcement | Custom Larastan rule `BoundaryRule` + `JoeyMckenzie/facadeless` + `canvural/larastan-strict-rules` at `level: max` (level 10), zero baseline | D-03 + DI-only constraint; CI-enforced; no human discipline needed |
| Data layer | SQLite 3.45+ in WAL mode with `synchronous=NORMAL`, `foreign_keys=ON`, `busy_timeout=5000`, `temp_store=MEMORY` | FND-06; local-only single-user app; brick/money + BIGINT minor units for all amounts (FND-04, FND-07); test DB is `:memory:` |
| Money | `brick/money` ^0.13 wrapped in `Modules\Ledger\Public\ValueObjects\Money`; Eloquent custom cast `MoneyMinorCast` reads `(amount_minor, currency)` columns | FND-04 + FND-07; never float; integer aggregation in SQL, Money composition at the query boundary |
| Idempotency | Two-layer: file-level UNIQUE `(user_id, sha256)` on `import_runs`; row-level composite UNIQUE on `transactions (account_id, posted_at, amount_minor, currency, counterparty_normalized, source_ref)` + SHA-256 `fingerprint` mirror column; persistence via `insertOrIgnore` | D-16, ING-06; `IdempotencyContractTest` is a Pest dataset every future adapter inherits |
| Auth | Laravel Fortify (headless backend) + hand-written Livewire 4 `LoginForm` styled with Flux UI; 30-day remember-me by default; `LoopbackOnly` middleware enforces `SERVER_ADDR ∈ {127.0.0.1, ::1}` | D-09, D-11, FND-01, FND-02, PLT-01 |
| Current user access | Two-layer indirection: domain code injects `Core\Public\Contracts\CurrentUser`; the service injects `Illuminate\Contracts\Auth\Factory`; `auth()` / `Auth::*` are PHPStan-banned | D-12, DI-only constraint |
| UI runtime | Livewire 4 class-based components in `Modules\<X>\Http\Livewire\` + Blade + Flux UI components + Tailwind 4 (CSS-first config) + Inter font with `tabular-nums` on every money / date cell | UI-05; Linear/Notion calm aesthetic locked in `01-UI-SPEC.md` |
| Upload UX | Preview-then-confirm wizard: pre-parse sniff → in-memory Parse+Normalize+Fingerprint → preview NEW/DUPLICATE/ERROR per row → optional inline account-naming for unknown IBAN → confirm → `RecordTransactions` inside a single DB transaction; **synchronous, no queue** | D-13..D-15, ING-07, ING-08 |
| Period model | `period_start_day` integer 1..28 on `users` row; current period derived at query time by `PeriodQuery::containing($instant)`; prev/next step by one month; default 1 (calendar month) | D-19 |
| Test runner | Pest 3 + `pest-plugin-laravel` + `pest-plugin-arch`; per-module `tests/Unit` + `tests/Feature`; top-level `tests/Contracts/` for cross-module invariants; SQLite `:memory:` for tests; `RefreshDatabase` by default | Validation Architecture in `01-RESEARCH.md`; greenfield, no legacy baseline |
| Code style | Laravel Pint (default preset); CI gate `vendor/bin/pint --test` | CLAUDE.md quality gate |
| Deployment target | Local-only: Laravel Herd serves `https://diederik.test` (or `php artisan serve --host=127.0.0.1`); no cloud, no containers, no telemetry; SQLite path validated to be outside iCloud / OneDrive / Dropbox at install time | PLT-01, PLT-02; privacy-first constraint |
| Directory layout | `app/PhpStan/Rules/*` for custom Larastan rules; `Modules/<Name>/` per nwidart with the standard shape (Public/Internal/Models/Database/Providers/Routes/Http/Resources/tests) documented in `01-RESEARCH.md §Module Layout`; top-level `tests/Contracts/`, `tests/Feature/`, `tests/fixtures/` for cross-module work | D-02 + research §Module Layout |

## Stack Touched in Phase 1

- [x] Project scaffold — `composer create-project laravel/laravel`, Livewire 4 starter, nwidart modules, Pest, Larastan (level max + 3 rule packages), Pint
- [x] Routing — Fortify-registered `/login` (POST) + `LoopbackOnly` middleware on every route + module-local `web.php` files (Core, Import, Ledger, Categorization)
- [x] Database — `users`, `accounts`, `transactions`, `categories`, `currencies`, `merchants`, `merchant_memories`, `import_runs` migrations; `diederik:install` creates User id=1, `DefaultCategoryTreeSeeder` seeds the Dutch-aware tree, `RecordTransactions` writes transactions, dashboard query reads them
- [x] UI — `/login`, `/` (dashboard), `/imports/new`, `/imports/{id}/preview`, `/imports/{id}`, `/transactions`, `/uncategorized` — all Livewire 4 class components, all Flux-based, all keyboard-accessible per UI-SPEC
- [x] Deployment — `https://diederik.test` via Herd; documented in README. Production cloud deployment is permanently out of scope (PLT-01)

## Out of Scope (Deferred to Later Slices)

These belong to later phases of the roadmap. They MUST NOT appear in Phase 1 plans or implementations.

- **Queue workers / launchd plists** — Phase 6 (first async workload is email scanning). Phase 1 is synchronous (D-15).
- **`db:backup` artisan command + `VACUUM INTO`** — Phase 11 (FND-05). Phase 1 enables WAL and documents the threat (Time Machine on `.sqlite-wal`) but ships no backup logic.
- **Multi-currency display + dual-currency toggle (UI-06, MC-02)** — Phase 3. The schema lands now (six money columns from day one), but the UI never renders a non-EUR amount.
- **CAMT.053 / MT940 ingestion (ING-02, ING-03)** — Phase 2.
- **ICS Cards CSV/Excel (ING-04)** — Phase 3.
- **PayPal CSV / Reporting API + transfer pairing (ING-05, ING-09, LED-04, LED-05)** — Phase 4.
- **Chain resolution (CHN-01..07, UI-02)** — Phase 5.
- **Email receipt ingestion (EML-01..08) + OAuth secret storage (PLT-03) + launchd background workers (PLT-04)** — Phase 6.
- **Email template matchers + per-merchant memory learning (EML-05, EML-07, CAT-02, CAT-04)** — Phase 7. Phase 1 ships the `merchants` and `merchant_memories` tables empty so Phase 7 needs no schema migration.
- **Recurring detection + fixed-payments view + recurring income (REC-01..05, LED-06, UI-03)** — Phase 8.
- **Subscription drift alerts (REC-06..08)** — Phase 9.
- **Forecasting + what-if (FCT-01..05)** — Phase 10.
- **In-app Settings UI for `period_start_day`** — install command sets it; the dashboard surfaces the current period; deferring an in-app edit form is per the open-questions recommendation in `01-RESEARCH.md §Open Questions #5`.
- **`pair_transaction_id` on transactions, transfer detection, income vs internal-move flagging** — Phase 4.
- **Dark mode** — defer to operational polish; Tailwind 4 makes it a config-only change later.
- **Charts (ApexCharts / Chart.js)** — Phase 8 (fixed-payments view). Phase 1 dashboard uses only HTML + Tailwind bars for the top-categories sparkline.

## Subsequent Slice Plan

Each later phase adds one vertical slice on top of this skeleton without altering its architectural decisions:

- **Phase 2:** Add `AsnCamt053Adapter` and `AsnMt940Adapter` to `Modules\Ingestion\Internal\Adapters\Asn\`; they implement the same `SourceAdapter` contract; both inherit the existing `IdempotencyContractTest` via dataset entries; `EndToEndId` becomes the `source_ref` for CAMT rows.
- **Phase 3:** Add `IcsCsvAdapter` + `IcsXlsxAdapter` in a new `Modules\Ingestion\Internal\Adapters\Ics\` subtree; six money columns already exist on `transactions` so dual-currency rows persist with no migration; new `UI-06` toggle on the transaction list.
- **Phase 4:** Add `PayPalCsvAdapter` (and optionally the Reporting API path) in `Modules\Ingestion\Internal\Adapters\Paypal\`; new `Modules\Transfers` module (or extension of Ledger) hangs `pair_transaction_id` + income detection on the existing `transactions.type` enum.
- **Phase 5:** New `Modules\Chains` module — owns `chain_links` table + deterministic + fuzzy resolvers + bulk-iDEAL decomposer; consumes Ledger's `TransactionListQuery` via Public surface only.
- **Phase 6:** New `Modules\EmailScan` module — Gmail API + Microsoft Graph + queue (database driver promoted from no-queue), `launchd` plists committed under `deploy/launchd/`, OAuth secrets in `~/.diederik/config.json` (chmod 600).
- **Phase 7:** Wire `TransactionCategorized` event (already fired from Phase 1) to a `MerchantMemoryListener` in a new `Modules\Learning` (or `Categorization\Internal\Listeners`); `MerchantMemory` table gains writes; matchers from EmailScan feed pre-suggested categories.
- **Phase 8:** New `Modules\Recurring` module — clustering, cadence inference, fixed-payments view; depends on ≥3 months of imported data.
- **Phase 9:** Add `drift_alerts` table + alerts view + acknowledge/snooze/what-if-cancel actions — no new module, extension of Recurring.
- **Phase 10:** New `Modules\Forecast` module — 30/60/90-day projections, ranges, what-if mutations (in-session only, never persisted).
- **Phase 11:** `Modules\Core\Internal\Console\BackupCommand` shipping `php artisan db:backup` via `VACUUM INTO` + `PRAGMA integrity_check`; document the supported path; forbid `cp database.sqlite` in README.

**Invariant the skeleton commits to:** every later phase adds modules or actions; **no later phase reshapes the `transactions` table, the fingerprint contract, the `Public/` vs `Internal/` boundary, the DI-only rule, or the Pest contract tests**. The skeleton is the bedrock.
