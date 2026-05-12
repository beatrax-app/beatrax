# Phase 1: Foundation + ASN CSV Vertical Slice - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-12
**Phase:** 1-Foundation + ASN CSV Vertical Slice
**Areas discussed:** Module decomposition, Auth & user setup, Upload UX flow, Dashboard composition

---

## Module decomposition

### Q1: Which modules should Phase 1 ship with?

| Option | Description | Selected |
|--------|-------------|----------|
| Pre-split: Core + Ledger + Ingestion + Categorization | Four modules from day one. Boundaries enforced from commit 1. | |
| Two modules: Core + Diederik | Core + one big Diederik module; split when 2nd source arrives. | |
| Single 'App' module (no nwidart yet) | Defer nwidart to Phase 2. Conflicts with PROJECT.md. | |

**User's choice:** Free-text — "Always prefer to split things up in separate modules if appropriate." Interpreted as **maximum-split**: 5 modules (Core, Ledger, Ingestion, Import, Categorization).
**Notes:** This sets the bar for every later phase — when in doubt, extract.

### Q2: How strictly should cross-module access be enforced?

| Option | Description | Selected |
|--------|-------------|----------|
| Public service contracts + Larastan rule | Each module exposes `Public/`; Larastan blocks non-Public imports. | ✓ |
| Conventions only (no automated enforcement) | Document the rule; rely on code review. | |
| Public contracts only (no Larastan rule yet) | Defer the custom rule to a later phase. | |

**User's choice:** Public service contracts + Larastan rule.

### Q3: Where do Account, Transaction, Money DTOs live?

| Option | Description | Selected |
|--------|-------------|----------|
| Inside Ledger module | Ledger owns canonical models + DTOs. | ✓ |
| In a shared 'Domain' module | Common module others reference. Risk: junk drawer. | |
| In Core kernel | Lump with User/Auth. Mixes concerns. | |

**User's choice:** Inside Ledger module.

### Q4: Where does the ImportPipeline live?

| Option | Description | Selected |
|--------|-------------|----------|
| In Ingestion module | Pipeline + adapters together. | |
| Pipeline in Ingestion, persistence in Ledger | Adapters + pipeline together; Ledger owns persistence. | |
| Pipeline in its own 'Import' module | Separate Import module; Ingestion holds only adapters; Ledger holds only persistence. | ✓ |

**User's choice:** Pipeline in its own 'Import' module.
**Notes:** Reinforces the maximum-split preference. Phase 1 = 5 modules.

---

## Auth & user setup

### Q1: How should auth be implemented?

| Option | Description | Selected |
|--------|-------------|----------|
| Laravel 13 Livewire Starter Kit | Official template ships Livewire 4 + Volt + Flux UI auth scaffolding. | |
| Laravel Fortify backend + custom Livewire UI | Fortify backend, hand-written UI. More control. | ✓ |
| Minimal: one password in config | Conflicts with FND-03 (user_id everywhere). | |

**User's choice:** Fortify + custom UI.
**Notes:** Matches the "calm Linear/Notion" aesthetic preference; full UI control.

### Q2: How does the first user get created?

| Option | Description | Selected |
|--------|-------------|----------|
| artisan command on setup | `php artisan diederik:install` — idempotent CLI setup. | ✓ |
| Registration page on first visit | First HTTP visit shows account-creation; disabled after. | |
| Seeder in dev, manual artisan in prod | DatabaseSeeder for dev; tinker for prod. | |

**User's choice:** artisan command on setup.

### Q3: How long does a session last?

| Option | Description | Selected |
|--------|-------------|----------|
| Long-lived 30-day, remember-me on | Daily-use tool on a single machine. | ✓ |
| Standard 2-hour Laravel default | High friction for local-only personal tool. | |
| Never expires until logout | Maximum convenience; only OK because localhost. | |

**User's choice:** 30-day, remember-me on.

### Q4: How should code get the current user?

| Option | Description | Selected |
|--------|-------------|----------|
| Inject `Illuminate\Contracts\Auth\Guard` | Standard Laravel contract, no wrapper. | |
| Custom CurrentUser service in Core/Public | Wraps Guard; one extra layer. | |
| Both — CurrentUser wraps Guard; modules inject CurrentUser | Domain modules never depend on Laravel's Guard directly. | ✓ |

**User's choice:** Both layers. Modules inject `Core\Public\CurrentUser`; CurrentUser injects Guard.
**Notes:** This is the v2 multi-user seam.

---

## Upload UX flow

### Q1: What happens when the user uploads a CSV?

| Option | Description | Selected |
|--------|-------------|----------|
| Sync parse + immediate redirect to results | Run pipeline synchronously, show results. | |
| Queued job + import-status page | Requires queue worker in Phase 1. | |
| Preview-then-confirm wizard | In-memory parse → preview rows → user confirms → persist. | ✓ |

**User's choice:** Preview-then-confirm wizard.
**Notes:** User wants visibility into what's about to land before it lands. Highest control.

### Q2: How does the user see that idempotency worked?

| Option | Description | Selected |
|--------|-------------|----------|
| Results page summary | "N imported · M skipped (duplicates) · K errors". | ✓ |
| Toast + drill-in via Imports history page | Cleaner home flow; hides duplicates by default. | |
| Silent dedup, just show new count | Hidden tradeoff. | |

**User's choice:** Results page summary (post-confirm).

### Q3: Pre-parse validation?

| Option | Description | Selected |
|--------|-------------|----------|
| MIME + extension + header sniff | Fast-fails malformed uploads. | ✓ |
| Just MIME + extension | Errors arrive deeper in pipeline. | |
| Full parse-then-validate | Parser surfaces all errors. | |

**User's choice:** MIME + extension + header sniff.

### Q4: Account mapping?

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-create / match by IBAN | Parser reads IBAN; first time prompts user to name account. | ✓ |
| User picks Account from dropdown before upload | User must remember which file is which. | |
| Create one Account per IBAN seen, never prompt | Auto-create with IBAN as placeholder name. | |

**User's choice:** Auto-create / match by IBAN.

---

## Dashboard composition

### Q1: What does the home screen show in Phase 1?

| Option | Description | Selected |
|--------|-------------|----------|
| Top totals + top spend categories + recent transactions | Useful with one source + basic categories. | ✓ |
| Totals only | Spartan; thin until later phases. | |
| Totals + accounts grid + recent transactions | Thin with just one ASN account in Phase 1. | |
| Totals + 6-month sparkline + recent transactions | Only meaningful after several months of data. | |

**User's choice:** Top totals + top categories + recent transactions.

### Q2: Empty state?

| Option | Description | Selected |
|--------|-------------|----------|
| Centered 'Import your first ASN export' CTA | Single big card. | |
| Empty dashboard skeleton with em-dashes | Cluttered for an empty state. | |
| Wizard: upload → land on dashboard | Strongest first-run UX. | ✓ |

**User's choice:** Wizard.

### Q3: Time window — what 'current month' means?

| Option | Description | Selected |
|--------|-------------|----------|
| Calendar month, prev/next arrows | Simple to explain and test. | |
| Rolling 30 days | Easier on month boundaries. | |
| User-configurable per-view | Most flexible; overkill for Phase 1. | |

**User's choice:** Free-text — "should be configurable; I want overviews from salary date to salary date." Interpreted as: configurable `period_start_day` integer on the user record, default 1 = calendar month.
**Notes:** Salary-cycle period model. The current period is **derived**, not stored per-transaction. Prev/next arrows step by one period.

### Q4: Where does the uncategorized triage view live?

| Option | Description | Selected |
|--------|-------------|----------|
| Dedicated /uncategorized page + badge on home | Focused inbox; single-key category assignment. | ✓ |
| Filter on the main transaction list | Loses the 'inbox' feeling. | |
| Inline cards on the home view | Clutters home; conflicts with calm aesthetic. | |

**User's choice:** Dedicated /uncategorized page + badge on home.

---

## Follow-up: Period mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| Day-of-month integer + `period_start_day` setting | User sets 1-28; period derived at query time. | ✓ |
| Two presets only: Calendar / Salary cycle (day X) | Same outcome, more intent. | |
| Skip for Phase 1, defer to later | Cuts scope. | |

**User's choice:** Day-of-month integer + `period_start_day` setting.

---

## Claude's Discretion

The user explicitly or implicitly deferred these to Claude / the planner:
- Concrete `nwidart/laravel-modules` directory layout inside each module
- Naming for Public service classes (verb conventions)
- Specific Larastan rule implementation choice (PHPStan custom rule vs `larastan.neon` paths)
- Wire-up of the Money value object (factory location, Currency seed)
- Layout primitives in Livewire/Flux for the calm aesthetic
- Default seed category tree (Dutch-aware sensible starter set)
- Pest test organization (per-module vs top-level mirroring)
- Routes / URL structure
- Where `period_start_day` lives (User column vs UserPreferences table)
- Whether to ship an in-app Settings form for `period_start_day` in Phase 1, or only the install prompt

## Deferred Ideas

(See CONTEXT.md `<deferred>` for the full list.)

- CAT-02 (per-merchant memory) → Phase 7
- CAT-04 (user-defined rules) → Phase 7
- FND-05 (`db:backup` artisan) → Phase 11
- Queue worker / launchd → Phase 6
- Multi-currency UI toggle (MC-02, UI-06) → Phase 3
- OAuth secret storage layout → Phase 6
- Healthcheck UI ("last scan: X") → Phase 6
- In-app Settings UI for `period_start_day` → planner decides whether to ship in Phase 1 or defer
