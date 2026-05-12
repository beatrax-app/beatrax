# Phase 1: Foundation + ASN CSV Vertical Slice - Context

**Gathered:** 2026-05-12
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 1 delivers the spine of diederik: a working "see my ASN month" experience that proves the architecture before any second source touches the database. The user can install the app via an artisan command, log in, walk through a first-run upload wizard, drop in an ASN CSV export, preview the parsed rows (with NEW / DUPLICATE / ERROR per row), confirm, and land on a calm dashboard showing income / expenses / net for the current period, top spending categories, and recent transactions. Manual categorization works (assign / override / triage uncategorized in bulk). Re-uploading the same CSV is a no-op verified by a Pest test. Every foundational decision that would be expensive to retrofit lands in this phase: BIGINT minor units, `user_id` nullable on every domain table, multi-currency dual-amount columns, idempotent fingerprinting, SQLite WAL, the DI-only architecture, the 5-module split with Public/ boundaries enforced by Larastan.

This phase does NOT add new capabilities — no chain resolution, no recurring detection, no forecasting, no email, no second source. Those are later phases per ROADMAP.md.

</domain>

<decisions>
## Implementation Decisions

### Module Decomposition
- **D-01:** Phase 1 ships **5 modules** via `nwidart/laravel-modules`: `Core`, `Ledger`, `Ingestion`, `Import`, `Categorization`. Always prefer separate modules when appropriate (user explicit preference).
- **D-02:** Each module exposes a `Public/` namespace containing service classes + DTOs + events. Cross-module access goes through `Public/` only — never reach into another module's internals.
- **D-03:** A **custom Larastan rule** enforces the boundary: importing `Modules\<Other>\<NotPublic>` from outside that module fails CI. Implemented and active from Phase 1; Larastan runs at level 10 strict.
- **D-04:** `Ledger` owns the canonical models and DTOs: `Account`, `Transaction`, `Currency`, the Money value object wrapper. It exposes a public `RecordTransactions` action — the **only** writer to the `transactions` table.
- **D-05:** `Ingestion` holds the `SourceAdapter` contract (in `Public/`) and `AsnCsvAdapter` only. Adapters parse source → return Source DTOs. Nothing more.
- **D-06:** `Import` owns the **ImportPipeline orchestrator** plus the `Parse → Normalize → Fingerprint` stages. The pipeline accepts a `SourceAdapter` (from Ingestion) and calls Ledger's `RecordTransactions` action to persist. Three stages live in `Import/Internal/`; the orchestrator service lives in `Import/Public/`.
- **D-07:** `Categorization` owns `Category`, `MerchantMemory`, and the manual-categorize action. Phase 1 only includes manual categorization + per-merchant memory wiring is **not** done here (CAT-02 lives in Phase 7); only CAT-01 / CAT-03 / CAT-05 are in scope.
- **D-08:** `Core` holds `User`, the `BelongsToUser` trait, the `CurrentUser` service (Public/), auth wiring, shared kernel utilities (Money configuration, Pulse/health hooks).

### Auth & User Setup
- **D-09:** Auth is built on **Laravel Fortify** (backend routes for login / logout / password reset) with a **hand-written Livewire/Flux UI** matching the calm Linear/Notion aesthetic. No starter-kit UI; full control.
- **D-10:** First user is created via `php artisan diederik:install` — a single idempotent command that prompts for email + password, creates User id=1, seeds the default category tree, and initializes any required config (e.g. `period_start_day` default). Safe to re-run.
- **D-11:** Session policy: **30-day sessions, 'remember me' on by default.** Daily-use tool on a single localhost machine. Re-auth is required on password change.
- **D-12:** Current-user access pattern: **Two layers of indirection.** Domain modules inject `Core\Public\CurrentUser` (the project's own contract). `CurrentUser` itself injects `Illuminate\Contracts\Auth\Guard`. Domain code never directly depends on Laravel's `Guard`; this is the seam that makes the v2 multi-user transition clean. `auth()`, `Auth::user()`, etc. are forbidden everywhere per the project's DI-only constraint.

### Upload UX Flow
- **D-13:** Upload is a **preview-then-confirm wizard**, not sync-and-redirect:
  1. User uploads a file and declares the source as "ASN CSV" (no auto-detection, per ING-07)
  2. **Pre-parse validation** — MIME type + extension + header column sniff. Fast-fail bad uploads before the parser runs.
  3. Pipeline runs in-memory through Parse → Normalize → Fingerprint stages (no DB writes)
  4. Preview screen shows every parsed row with per-row status: **NEW** (will be inserted), **DUPLICATE** (fingerprint matches existing), **ERROR** (row could not be normalized — reason shown)
  5. If any row has an IBAN not yet known, the wizard prompts the user to name the Account before proceeding
  6. User clicks "Confirm import"
  7. Pipeline executes the Load stage (Ledger's `RecordTransactions` action) inside a DB transaction
  8. Results page summary: "N imported · M skipped (duplicates) · K errors" with an expand-to-see-skipped option
- **D-14:** Account mapping is **auto-by-IBAN**. Parser reads IBAN per row; if the IBAN matches an existing Account, rows go straight in; if not, the wizard inserts an account-naming step before the preview proceeds. Subsequent imports of the same IBAN never prompt again.
- **D-15:** Phase 1 is **synchronous** end-to-end — no queue worker required to run an import. ASN exports are small enough and the local SQLite is fast enough that running the pipeline in a Livewire action is acceptable. Queue infrastructure arrives in Phase 6 (email scanning).
- **D-16:** The fingerprint at the DB layer uses **a single composite `UNIQUE` index** on `(account_id, posted_at, amount_minor, currency, normalized_counterparty, source_ref)` (per research/SUMMARY.md). `normalized_counterparty` is computed during Normalize using a deterministic, versioned algorithm (lowercase, strip whitespace + punctuation, collapse repeated spaces). The version is stored alongside so the algorithm can evolve later without invalidating existing fingerprints.

### Dashboard Composition
- **D-17:** Home screen layout: **Top totals** (current period: in / out / net) → **Top spending categories** (ranked, with a small bar visualization) → **Recent transactions** (last 10, with a "view all" link). Uncategorized count badge sits in the page header linking to `/uncategorized`.
- **D-18:** Empty state is a **first-run wizard**, not a populated skeleton. If the user has no transactions yet, the dashboard route redirects to a wizard that walks them through their first CSV upload (the same Upload Wizard from D-13). Once at least one transaction exists, the dashboard becomes the landing route.
- **D-19:** **Period is user-configurable** — `period_start_day` is an integer 1-28 stored on the user record (or in a user-settings table; planner decides). Default = 1 (calendar month). For a salary cycle, set to e.g. 25 (period N runs from day 25 of month M to day 24 of month M+1). The current period is **derived** at query time; nothing is stored per-transaction. Prev/next arrows on the home view step the period by one. The install command (`diederik:install`) prompts for `period_start_day` once during setup.
- **D-20:** Uncategorized triage is its **own page** (`/uncategorized`), not a filter on the main transaction list. It's a focused inbox with single-key category assignment (the planner picks the keymap). A badge with the uncategorized count sits in the home page header linking to it.

### Claude's Discretion
- Concrete `nwidart/laravel-modules` directory layout inside each module (`Models/`, `Actions/`, `Services/`, `Internal/`, `Public/`) — pick a uniform shape; document it once and apply everywhere
- Naming for Public service classes (`ImportPipelineService` vs `RunImport` vs `ImportRunner` etc.) — pick consistent verbs
- Specific Larastan rule implementation (PHPStan custom rule or a `larastan.neon` ruleset with paths) — whichever is cleaner
- Wire-up of the Money value object: where the factory lives, where Currency table is seeded, exact column types for `amount_minor` (`BIGINT NOT NULL`) and `currency_code` (`CHAR(3)`)
- Layout primitives in Livewire/Flux for the calm aesthetic — choose Flux components that exist; don't invent new ones
- Default seed category tree (e.g. Income / Groceries / Subscriptions / Transport / Housing / Insurance / etc.) — pick a sensible Dutch-aware starting set; user can edit
- Exact Pest test organization (per-module `tests/Unit` + `tests/Feature` vs top-level mirroring) — keep consistent with nwidart conventions
- Routes / URL structure — keep RESTful and predictable (`/dashboard`, `/transactions`, `/imports/new`, `/imports/{id}/preview`, `/uncategorized`, `/settings`)
- Where `period_start_day` lives — User column vs UserPreferences table (planner picks)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-Level
- `.planning/PROJECT.md` — Project constraints (PHP 8.5 + Laravel 13, DI-only, nwidart modules, Larastan level 10 strict, Pint, Pest, no frontend tests, localhost-only, calm aesthetic)
- `.planning/REQUIREMENTS.md` — Full v1 requirements; Phase 1 covers FND-01..07 (minus FND-05), ING-01, ING-06, ING-07, ING-08, LED-01, LED-02, MC-01, CAT-01, CAT-03, CAT-05, UI-01, UI-04, UI-05, PLT-01, PLT-02, PLT-05
- `.planning/ROADMAP.md` §"Phase 1" — Goal + 5 success criteria; downstream phases that depend on what's built here

### Research (read before planning)
- `.planning/research/SUMMARY.md` — Executive summary, dependency graph, foundation decisions
- `.planning/research/STACK.md` — Library version pins + rationale; specifically the brick/money + league/csv + spatie/laravel-data + Pest 3 + Livewire 4 stack
- `.planning/research/ARCHITECTURE.md` — Modular monolith design, Phase 1 vertical slice recommendation, single-`transactions`-table-with-type-enum decision, fingerprint composition, multi-user readiness via `user_id` + `BelongsToUser` + `CurrentUser` facade-replacement
- `.planning/research/PITFALLS.md` — 19 pitfalls; Phase 1 must address: float money, unstable transaction identity, missing `user_id`, SQLite WAL backup story (deferred to Phase 11 but schema considerations land here)

### External
- Laravel 13 docs (`https://laravel.com/docs/13.x`) — Fortify, Livewire 4, queues (for awareness even though Phase 1 is sync), config repo contract
- nwidart/laravel-modules docs (`https://nwidart.com/laravel-modules/v6/introduction`) — module scaffolding, autoloading, asset compilation, custom command paths
- Flux UI docs (`https://fluxui.dev/`) — component library that ships with the Livewire starter kit
- `brick/money` README (`https://github.com/brick/money`) — Money value object API, rounding modes, currency registry
- `league/csv` docs (`https://csv.thephpleague.com/9.0/`) — streaming reader, BOM handling, encoding
- Larastan docs (`https://github.com/larastan/larastan`) — level 10 configuration, strict mode flags, custom rules

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **None — greenfield project.** The repo contains only `.planning/` and `CLAUDE.md`. No existing code, no installed dependencies, no `composer.json` yet.

### Established Patterns
- **None yet.** Phase 1 *establishes* the patterns that every subsequent phase will follow:
  - Module shape inside `Modules/<Name>/`
  - Public/ vs Internal/ namespace convention
  - Action class + DTO conventions
  - Test organization
  - `CurrentUser` injection pattern (replaces `auth()` / `Auth::user()`)
  - DI-only style (no facades or helpers)

### Integration Points
- Operating system: **macOS** (Laravel Herd) — `launchd` arrives in Phase 6, not Phase 1
- Database: **SQLite WAL mode** with `synchronous=NORMAL` — connection-level pragmas set in `Core` service provider boot
- HTTP entry: localhost only, `php artisan serve --host=127.0.0.1 --port=...` (or Herd's `diederik.test` if user prefers); a middleware refuses requests that arrive on any other interface
- CLI entry: `php artisan diederik:install` is the one-time setup; all other artisan commands assume install completed

</code_context>

<specifics>
## Specific Ideas

- **Calm Linear/Notion aesthetic** — quiet, content-first, monochrome with one accent color. UI-05 stays as the visual anchor for the planner's UI work.
- **Salary-cycle period model** — user mentioned wanting overviews to span "salary date to salary date" rather than calendar months. Locked as configurable `period_start_day`.
- **Strict modules from day one** — user explicit preference: "Always prefer to split things up in separate modules if appropriate." This sets the bar for every later phase: when in doubt, extract.
- **DI-only everywhere** — non-negotiable. Larastan rule against facade and helper usage is part of Phase 1's CI gate; the planner should ensure that a fixture test exists that breaks when someone tries to introduce `auth()` or `Auth::user()`.
- **Idempotency must be visible in the UI** — the preview wizard surfaces NEW / DUPLICATE / ERROR per row before commit; the results summary shows skipped counts after commit. This is the user's confidence loop for the idempotency contract.

</specifics>

<deferred>
## Deferred Ideas

- **Per-merchant categorization memory (CAT-02)** — explicitly belongs to Phase 7 per ROADMAP.md; touched in Phase 1 only by the data shape (Merchant + MerchantMemory tables can exist with no learning behavior yet). Planner: include the table; do not include the learning behavior.
- **User-defined categorization rules (CAT-04)** — Phase 7.
- **`db:backup` artisan command (FND-05)** — Phase 11 (Operational Hardening). Phase 1 enables WAL but does not ship the backup command yet.
- **Queue infrastructure (`launchd` plists, queue worker process)** — Phase 6 (email scanning is the first async workload).
- **Multi-currency dual-amount display** — schema lands in Phase 1 (MC-01); user-facing dual-currency toggle is Phase 3 (MC-02, UI-06).
- **Encrypted backups / OAuth secret storage layout** — Phase 6 needs it; Phase 1 only requires SQLite to live outside iCloud Drive (PLT-02), nothing more.
- **Healthcheck UI surface ("last scan: X")** — relevant when async workers exist; ship in Phase 6.
- **Settings UI for `period_start_day`** — install command sets it once; an in-app Settings page to change it can be added when forecasting/recurring need finer control. Phase 1 ships the install prompt + a minimal "Settings" form (or skips the form and exposes it via env / config edit). Planner decides whether to ship the Settings UI now or defer to a later phase.

</deferred>

---

*Phase: 1-Foundation + ASN CSV Vertical Slice*
*Context gathered: 2026-05-12*
