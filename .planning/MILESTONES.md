# Milestones

## v1.0 MVP — Cross-Account Personal Finance Dashboard (Shipped: 2026-05-19)

**Phases completed:** 11 phases, 66 plans, 154 tasks
**Timeline:** Project start through 2026-05-19

### Delivered

A local-only Laravel personal finance dashboard that ingests ASN (CSV / CAMT.053 / MT940), ICS Cards (PDF), PayPal (CSV), and email receipts (Gmail / Microsoft Graph / `.eml`/`.mbox`) into a single canonical transaction ledger; resolves the cross-account funding chains (PayPal → underlying card / bank, ASN → ICS bulk-iDEAL settlement decomposed into individual card lines) so a charge can be traced end-to-end; detects recurring expenses and income with subscription-drift alerts; projects 30/60/90-day per-account cash flow with what-if scenarios; and ships with operational hardening (`db:backup` via `VACUUM INTO`, restore + verify, doctor probes, `system_alerts` banner, launchd-managed scheduler + queue + IMAP idle).

### Key accomplishments

- **Idempotent multi-format ingestion (Phases 1–4)** — ASN CSV, ASN CAMT.053, ASN MT940, ICS PDF, and PayPal NL-locale CSV all funnel through a single canonical pipeline with v3 fingerprint dedup `(user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)`. Cross-format re-imports enrich existing rows via a source-format rank (camt053 > mt940 > csv) instead of duplicating. Hand-rolled MT940 toolchain; `genkgo/camt` for CAMT.053; bespoke ICS PDF text extractor.
- **Multi-currency from day one (Phase 3)** — every transaction preserves original amount + currency + FX rate + settled-EUR; per-page `EUR-only` vs `original` toggle with `#[Url]` binding and `default_currency_view` user preference; per-currency dashboard tiles in original mode.
- **Chain resolution — the differentiator (Phase 5)** — deterministic PayPal-funder links via `raw_payload` reference IDs, fuzzy fallback with weighted confidence (merchant 0.5 / amount 0.3 / window 0.2, capped at 0.99), ASN → ICS bulk-iDEAL settlement decomposition with ±€5 / ±2% / ±10-day tolerance, candidate review queue with one-click promote / reject + per-merchant learning loop, chain drawer with BFS walker.
- **Email-receipt ingestion (Phases 6–7)** — Gmail + Microsoft Graph OAuth2 connect wizards, per-inbox UID-resume scanning, per-sender matchers (PayPal / ICS / Google Play) bound to the same fingerprint pipeline, `.eml`/`.mbox` drop-in path, user-defined rules with specificity scoring + per-merchant memory for auto-categorization.
- **Recurring + drift + forecasting (Phases 8–10)** — daily recurring detection (expense + income clusters, 4 cadence bands, configurable tolerance), drift alerts with annualized impact + acknowledge/snooze/cancel-what-if actions, 30/60/90-day per-account projection with R-7 percentile ranges + chain-aware routing + shortfall windows + non-persisted what-if scenarios with side-by-side comparison.
- **Operational hardening (Phase 11)** — `php artisan db:backup` via `VACUUM INTO` + integrity check + 7-daily/4-weekly retention + smart-skip, `db:restore --confirm --force-maintenance` triple-rail destructive command, `diederik:doctor` SQLite-substrate probes, `HealthCheckServiceProvider` writing `system_alerts` rows on PRAGMA drift, user-visible `SystemAlertsBanner`, `diederik:failed-jobs prune` CLI, `diederik:install --launchd` for macOS daemon plists.

### Architecture invariants enforced via arch tests

- Constructor DI only — no facade calls or global helpers in `Modules/` (multiple `BoundaryArchTest` rules)
- Codebase agnostic from GSD — zero references to `.planning/` / PLAN.md / RESEARCH.md in runtime code or comments
- All monetary amounts are BIGINT minor units + `brick/money` value objects — `NoFloatMoneyArchTest`
- Every domain table has nullable `user_id` + `BelongsToUser` trait — `UserIdColumnArchTest`
- `ext-imap` forbidden — composer/lock gate
- Per-module Public/Internal split, with cross-module access only through Public surfaces

### Stack pinned

PHP 8.5 + Laravel 13 + Livewire 4 + Volt + Flux UI + Tailwind 4 + SQLite (WAL + `synchronous=NORMAL`) + Pest 3 + Larastan level 10 strict + Laravel Pint. Background work via `database` queue driver + `laravel/horizon` (added in Phase 5 for chain-resolution jobs) + Redis (loopback-bound only) + macOS `launchd` plists.

### Known deferred items at close

9 items deferred to next milestone (see [STATE.md → Deferred Items](STATE.md)):

- 25 human-UAT scenarios across Phases 03, 04, 06, 08, 11 (`partial` status)
- 3 verification artifacts marked `human_needed` (Phases 03, 08, 11)
- 1 dormant seed: `SEED-001-public-release-milestone` (desktop packaging / CI / deep Modules rev)
- ING-09 (PayPal Reporting API) blocked behind a PayPal Business account upgrade — CSV path (ING-05) covers the same data

### Archived artifacts

- [milestones/v1.0-ROADMAP.md](milestones/v1.0-ROADMAP.md) — full phase details + plans
- [milestones/v1.0-REQUIREMENTS.md](milestones/v1.0-REQUIREMENTS.md) — 67 / 68 requirements complete (ING-09 deferred-with-trigger)

---
