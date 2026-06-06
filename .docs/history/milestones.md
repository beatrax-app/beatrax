# Milestones

Shipped milestones in reverse chronological order. Each entry is a snapshot
of the system at the cut: delivered scope, pinned stack, and the arch
invariants that hold the lines.

## v1.0 MVP — Cross-Account Personal Finance Dashboard

**Shipped:** 2026-05-19

The first end-to-end milestone. A local-only Laravel personal finance
dashboard that ingests ASN (CSV / CAMT.053 / MT940), ICS Cards (PDF), PayPal
(CSV), and email receipts (Gmail / Microsoft Graph / `.eml` / `.mbox`) into
a single canonical transaction ledger; resolves the cross-account funding
chains (PayPal funding, ASN → ICS bulk-iDEAL settlement decomposed into
individual card lines) so a charge can be traced end-to-end; detects
recurring expenses and income with subscription-drift alerts; projects
30/60/90-day per-account cash flow with what-if scenarios; and ships with
operational hardening (`db:backup` via `VACUUM INTO`, restore + verify,
doctor probes, the system-alerts banner, launchd-managed scheduler + queue
+ IMAP idle).

### Delivered capability surface

- **Idempotent multi-format ingestion** — ASN CSV, ASN CAMT.053, ASN MT940,
  ICS PDF, and PayPal NL-locale CSV all funnel through a single canonical
  pipeline with a v3 fingerprint dedup keyed on
  `(user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)`.
  Cross-format re-imports enrich existing rows via a source-format rank
  (camt053 > mt940 > csv) instead of duplicating. Hand-rolled MT940
  toolchain (lexer + per-tag parsers) ships; `genkgo/camt` handles CAMT.053;
  a bespoke text extractor handles ICS PDFs.
- **Multi-currency from day one** — every transaction preserves an original
  amount, currency, FX rate, and settled-EUR; a per-page EUR-only / original
  toggle binds to the URL with `#[Url]`; a `default_currency_view`
  preference persists per user; the dashboard tiles split by currency in
  original mode.
- **Chain resolution** — the differentiator. Deterministic PayPal-funder
  links via `raw_payload` reference IDs; a fuzzy fallback with weighted
  confidence (merchant 0.5 / amount 0.3 / window 0.2, capped at 0.99); ASN
  → ICS bulk-iDEAL settlement decomposition with ±€5 / ±2% / ±10-day
  tolerance; a candidate review queue with one-click promote / reject + a
  per-merchant learning loop; the chain drawer walks the graph via BFS with
  `MAX_DEPTH=5`.
- **Email-receipt ingestion** — Gmail + Microsoft Graph OAuth2 connect
  wizards; per-inbox UID-resume scanning; per-sender matchers (PayPal,
  ICS, Google Play) bound to the same fingerprint pipeline; `.eml` /
  `.mbox` drop-in path; user-defined rules with specificity scoring and
  per-merchant memory for auto-categorisation.
- **Recurring + drift + forecasting** — daily recurring detection (expense
  and income clusters, four cadence bands, configurable tolerance); drift
  alerts with annualised impact and acknowledge / snooze / cancel-what-if
  actions; 30/60/90-day per-account projection with R-7 percentile ranges
  + chain-aware routing + shortfall windows + non-persisted what-if
  scenarios with side-by-side comparison.
- **Operational hardening** — `php artisan db:backup` via `VACUUM INTO` +
  integrity check + 7-daily / 4-weekly retention + smart-skip on PRAGMA
  `data_version`; `db:restore --confirm --force-maintenance` triple-rail
  destructive command; `beatrax:doctor` SQLite-substrate probes;
  `HealthCheckServiceProvider` writing `system_alerts` rows on PRAGMA
  drift; user-visible `SystemAlertsBanner`; `beatrax:failed-jobs prune`
  CLI; `beatrax:install --launchd` for the macOS daemon plists.

### Stack pinned at cut

PHP 8.5 + Laravel 13 + Livewire 4 + Volt + Flux UI + Tailwind 4 + SQLite
(WAL + `synchronous=NORMAL`) + Pest 3 + Larastan level 10 strict +
Laravel Pint. Background work via the `database` queue driver in the
shipped bundle (Phase 14 carved Horizon out for desktop builds; Horizon
stays available for the local development runtime). Redis is dev-mode
only and loopback-bound. macOS scheduler / queue / IMAP-idle daemons run
via `launchd` plists in dev.

### Arch invariants enforced at the cut

- Constructor DI only — no facade calls or global helpers in `Modules/`
  (multiple `BoundaryArchTest` rules).
- All monetary amounts are BIGINT minor units + `brick/money` value
  objects (`NoFloatMoneyArchTest`).
- Every domain table has a nullable `user_id` plus the `BelongsToUser`
  trait (`UserIdColumnArchTest`).
- `ext-imap` forbidden — composer / lock gate.
- Per-module Public / Internal split, with cross-module access only through
  Public surfaces.

### Cumulative quality at cut

| Tests | Arch invariants | Modules |
| --- | --- | --- |
| 1644 | 34+ | 11 |

### Carry-over at close

- 25 human-UAT scenarios across five phases marked `partial`.
- Three verification artefacts marked `human_needed`.
- One dormant seed (the public-release / desktop-packaging / deep-modules-review
  capstone — became the v0.x closeout series).
- The PayPal Reporting API integration blocked behind a PayPal Business
  account upgrade — the CSV path covered the same data.
