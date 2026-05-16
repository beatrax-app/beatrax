# diederik

## What This Is

A local-only personal finance dashboard that pulls together transactions from ASN Bank, ICS Cards, PayPal, and Google Play into a single calm "this month at a glance" view. It resolves the routing chains between these accounts (PayPal → ASN or ICS, ICS → ASN via bulk iDEAL settlement) so that fixed monthly payments, real underlying funding sources, and upcoming cash flow are visible in one place instead of buried across statements.

## Core Value

**Show me, in one place, what I actually owe and where the money truly came from — across every account chain — so my monthly finances stop being a manual reconciliation puzzle.**

If everything else fails, the system must surface the complete picture of monthly fixed payments and the funding chain that connects them.

## Requirements

### Validated

<!-- Shipped and confirmed valuable. -->

- [x] Import ASN transactions via CSV, CAMT.053 (XML), and MT940 (hand-rolled) — validated in phase 2 with the cross-format dedup test passing on a 72-row real-statement corpus
- [x] Idempotent imports across formats — re-uploading the same statement, or uploading two formats covering the same period, never double-counts a transaction. Validated in phase 2 via the v3 fingerprint composer (no source_ref in the tuple) + `enriched_from` JSON column that records cross-format upgrades
- [x] Import ICS Cards PDF statements (Mijn ICS consumer portal export) with foreign-currency charges preserved as both original and settled-EUR. Validated in phase 3 against a real Feb 2026 Mijn ICS statement with 3 real FX rows (USD + GBP); column-aware statement-summary parser locked to the empirical revolving-credit token set; tier-1 SHA + tier-2 v3 fingerprint dedup both Green for `'ics-pdf'`
- [x] Multi-currency views — per-page currency toggle on `/transactions` (URL-bound via Livewire `#[Url]` with user-default fallback), per-currency tile rows on the dashboard in `original` mode (alphabetical, zero-activity filtered), conditional Effective-rate row on transaction detail. Validated in phase 3 with locale-aware `Money::format()` (EUR → nl_NL, others → en_US) and BigDecimal-only money arithmetic end-to-end

### Active

<!-- Current scope. Building toward these. -->

#### Ingestion

<!-- ICS Cards PDF ingestion validated in phase 3 — moved to Validated. -->
- [ ] Import PayPal activity via CSV export with funding-source detail preserved
- [ ] Scan Gmail (via Gmail API + OAuth2) and Outlook / Microsoft 365 (via Microsoft Graph + OAuth2) for transaction receipts — including a backfill of at least 3 months of history
- [ ] Ingest exported `.eml` / `.mbox` files as an alternative path (covers iCloud / Fastmail / any provider without an API)
- [ ] Support a future dedicated forwarding inbox (Gmail/Outlook only) without rework
- [ ] Scan all connected inboxes for any known sender pattern (no per-source inbox config required)

#### Categorization & Recurrence

- [ ] Auto-categorize transactions by merchant with a learning loop (user corrections improve future suggestions)
- [ ] Detect anything recurring at any cadence (monthly, quarterly, yearly) and normalize to a monthly-equivalent amount
- [ ] Surface a curated list of monthly fixed payments with their funding source and chain

#### Chain Resolution

- [ ] Link a PayPal charge to its underlying funding card/account (ASN or ICS) deterministically when reference IDs / order IDs are available
- [ ] Fall back to fuzzy matching on merchant + amount + date, presenting candidates for user confirmation
- [ ] Learn from user confirmations so similar future links auto-match
- [ ] Reconcile bulk ASN → ICS iDEAL settlements: break the lump sum back down into the ICS transactions it covered
- [ ] Forecast the next ICS settlement amount based on the upcoming statement, so the user knows what to pay before paying

#### Income

- [ ] Capture income (salary, refunds, transfers in, PayPal credits) as a first-class concept alongside outflows
- [ ] Detect recurring income (monthly salary, regular transfers) so cash-flow forecasting balances both sides
- [ ] Distinguish true income from internal transfers (ASN → ICS settlement is not income; salary is)

#### Forecasting

- [ ] Project per-account balance forward by day, using known recurring charges, recurring income, and pending settlements
- [ ] Show surplus / shortfall windows so the user can see when an account will dip
- [ ] Support "what-if" mutations (e.g. cancel a subscription, add a planned expense) without persisting them

#### Dashboard

- [ ] "This month at a glance" home view: month totals (in/out/remaining), fixed-payment list with chain icons, cash-flow chart
- [ ] Drill from any transaction into its full funding chain
- [ ] Drill from any fixed payment into its history and trend
- [ ] Default UI to recent (last 3–6 months); offer "show full history" toggle

#### Multi-Currency

<!-- ICS multi-currency preservation + EUR settled amount + per-page report toggle validated in phase 3 (see Validated). -->
- [ ] Extend the same dual-amount preservation to PayPal and Google Play sources

#### Platform & Privacy

- [ ] Run entirely on localhost — no cloud, no third-party processors, no telemetry
- [ ] Store data in a local database
- [ ] Keep IMAP credentials and any secrets in a local config file (filesystem-permission protected)
- [ ] Single-user authentication is sufficient for v1, but the data model must leave room for a partner / second user later without rewrite

### Out of Scope

<!-- Explicit boundaries. Includes reasoning to prevent re-adding. -->

- **Cloud hosting / multi-device sync** — Privacy-first design; user only needs this on one machine and prefers data never leaves the device. Revisit only after v1 proves itself.
- **Bank PSD2 / open-banking API integrations** (Tink, Plaid, Nordigen, Enable Banking) — ICS and Google Play don't have clean APIs anyway, so a uniform export + email-scan model is simpler and avoids token-management complexity in v1.
- **Outbound payments / actually initiating iDEAL** — System recommends amounts to pay ICS; the user executes the payment in their bank. No payment-initiation responsibility.
- **Investment / brokerage accounts** — Scope is day-to-day cash and card flow, not portfolio tracking.
- **Mobile app / native client** — Web UI on localhost is sufficient. Mobile is a hosted-deployment concern, which is itself out of scope.
- **iCloud Mail integration** — No public API; would force IMAP back into the stack. User confirmed iCloud is not where financial receipts arrive.
- **Tax / VAT reporting** — This is a personal cash visibility tool, not bookkeeping.
- **Multi-user / partner sharing in v1** — Single-user first. Data model is designed so this can be added later without migration pain.
- **Receipt-image OCR** — Email + CSV is the data spine. Receipt photos are a v2+ consideration.

## Context

**Why this project exists:**
The user's payments fan out through multiple providers — ASN for direct debits and iDEAL, ICS Cards for credit-card purchases (settled in bulk back to ASN), PayPal as an intermediate funder that pulls from either ASN or ICS depending on configuration, and Google Play as another billing layer. Tracing a single subscription (e.g. Netflix) back to the real funding source today requires manually cross-referencing four sources. The ICS bulk iDEAL settlement step is particularly painful because the payment from ASN carries no per-transaction specification, so reconciliation is purely after-the-fact.

**Technical environment:**
- macOS development machine
- PHP / Laravel chosen by user preference (good ecosystem for HTTP, CSV/MT940 parsing, IMAP, queues, and a clean local web UI)
- SQLite expected for local storage (zero-setup, single-file, ideal for personal use)
- No prior code in this directory — greenfield

**User context:**
- Single user (the developer)
- Comfortable with technical setup, including IMAP app-passwords and CLI imports
- Prefers a calm, content-first aesthetic over dense data-table or chart-heavy designs
- Wants a working app fast — vertical MVP per phase, not a six-month architecture exercise

## Constraints

- **Tech stack**: PHP 8.5 + Laravel 13 (latest released March 2026) — User preference, mature ecosystem; pin to current versions to stay supported and avoid legacy deprecation cycles
- **Email integration**: Provider APIs only (Gmail API, Microsoft Graph) — Avoids any dependency on `ext-imap` (removed from PHP 8.4 core) and the IMAP library churn. iCloud Mail is explicitly out of scope
- **Modular architecture**: Code is organized into bounded modules via `nwidart/laravel-modules` — Enforces clean boundaries between Ingestion, Ledger, Categorization, Recurring, Chains, Forecasting, EmailScan, etc. Cross-module access goes through public service classes or events; no module reaches into another's models or internals
- **Code quality gates (CI-enforced)**: Larastan at level 10 (max) with strict mode + Laravel Pint formatting + Pest unit/feature tests — Every PR must pass all three before merge. No frontend tests are required (the UI is server-rendered + thin; investment goes into backend correctness)
- **Dependency Injection only — no global helpers or facade calls**: All collaborators are constructor-injected. Forbidden: helper functions (`auth()`, `request()`, `config()`, `app()`, `now()` etc.) and facade static calls (`Auth::user()`, `DB::table()`, `Cache::get()`, etc.). Allowed: Eloquent models used directly (instantiation, `Model::find()`, relationships, query builder via `$model->newQuery()`). Reason: explicit dependencies make Larastan level 10 honest, unit tests trivial, and module boundaries enforceable
- **Project slicing**: Vertical MVP per phase — Each phase ends with an end-to-end demoable capability, not an isolated layer. Phase 1 must produce a working "see my ASN month" experience before Phase 2 begins
- **Hosting**: Local only (localhost) — Privacy requirement; financial data must never leave the machine
- **Idempotency**: All ingestion paths (CSV upload, IMAP scan, .eml import) must be safe to re-run — Same source + same transaction must never duplicate
- **History**: Full history retained forever — Long-term subscription-drift analysis requires it; pruning is a non-goal
- **Multi-user readiness**: Single-user v1 but schema must permit a second user later without migration pain — User intends to share with a partner once the product is proven
- **Currency**: Multi-currency tracking required from v1 — Google Play (USD) and some ICS merchants charge non-EUR; preserving both currencies prevents losing FX information that can't be recovered later
- **Secrets**: IMAP credentials live in a local config file, not the DB — Keeps secrets cleanly separable and out of any DB backups

## Key Decisions

<!-- Decisions that constrain future work. -->

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| PHP 8.5 + Laravel 13 (latest) | User preference; strong ecosystem; staying current avoids legacy deprecation pain | — Pending |
| Email via provider APIs (Gmail API + Microsoft Graph), not IMAP | Decouples from `ext-imap` deprecation; cleaner OAuth flow; iCloud explicitly out of scope so no IMAP fallback needed | — Pending |
| `nwidart/laravel-modules` for module structure | Enforces bounded contexts at the directory level — Ingestion / Ledger / Chains / Recurring / Forecasting / EmailScan never accidentally reach into each other's internals | — Pending |
| Vertical MVP phase slicing | Each phase demoable end-to-end; faster feedback; can pivot direction phase-by-phase without throwing away a layer | — Pending |
| Larastan level 10 strict + Pint + Pest as required CI gates | Highest practical static-analysis bar; uniform formatting; tests live in Pest only; no frontend tests (server-rendered UI doesn't justify their cost here) | — Pending |
| DI-only — no helpers, no facade calls; models direct | Explicit constructor dependencies; trivial unit tests; honest Larastan typing; clean module boundaries (a module that wants another module's service has to declare the dependency) | — Pending |
| Local-only deployment, no cloud | Privacy of financial data is paramount; user only needs single-machine access | — Pending |
| Mixed ingestion (CSV + IMAP + file import) instead of bank APIs | ICS and Google Play lack usable APIs; a uniform export/email model is simpler and avoids token-rotation complexity | — Pending |
| Scan all inboxes for everything (no per-source inbox config) | Lower setup friction; catches forwarded receipts the user forgot about | — Pending |
| Single-user v1, multi-user-ready schema | Don't ship complexity that isn't used yet, but avoid a painful migration later | — Pending |
| Idempotent imports as a hard requirement, not a nice-to-have | User explicitly flagged the risk of overlapping CSV downloads / repeated email scans | — Pending |
| Income is a first-class concept, not "negative expense" | Cash-flow forecasting must balance both sides; salary and refunds are structurally different from outflows | — Pending |
| Calm + readable aesthetic (Linear / Notion vibe) | User preference; this is a tool for daily glance, not a dense reporting console | — Pending |
| Secrets in config file, not DB | Simplest portable approach for a single user; keeps secrets out of DB backups | — Pending |
| Async chain resolution via Laravel Horizon + Redis (stack override, phase 5) | The chain-resolver job needs `ShouldBeUniqueUntilProcessing` per-user locking and a real dashboard. The original "no Horizon, no Redis" posture is flipped; the corresponding rows in `research/STACK.md` are moved out of "What NOT to Use" and into recommended. | — Active phase 5 |
| Redis runs as a loopback-bound Docker container `redis:7-alpine` (stack override, phase 5) | Single network-only carve-out from the no-Docker rule. Container is bound to `127.0.0.1:6379` only — never reachable beyond loopback — and persists via a named volume, not a bind mount, so the Sail-on-Mac performance trap does not apply. | — Active phase 5 |

### Stack additions (Phase 5)

The chain-resolution phase flipped the queue stack from the original "no Horizon, no Redis, no Docker" posture to a Horizon-supervised, Redis-backed model with a narrow Docker carve-out for the Redis container. Detailed rationale, version pins, and the loopback-bind invariant live in `research/STACK.md` under the section of the same name.

| Addition | Version | Carve-out scope |
|----------|---------|-----------------|
| `laravel/horizon` | `^5.46` | full recommendation |
| `predis/predis` | `^3.4` | full recommendation |
| Docker Engine | any recent | one named container only (`diederik-redis`), loopback-bound, named-volume persistence |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-05-16 — phase 5 wave 0 (Horizon + Redis queue infrastructure flip; Docker carve-out for loopback Redis container) in progress*
