# beatrax

> GSD planning baseline bootstrapped 2026-06-07 at the start of v1.3. The
> project shipped v1.0–v1.2 tracked in `.docs/` + `CHANGELOG.md` (not GSD
> `.planning/`); this file is synthesised from `CLAUDE.md`,
> `.docs/history/milestones.md`, and `CHANGELOG.md` rather than re-gathered.
> `CLAUDE.md` remains authoritative for the tech stack and hard constraints.

## What This Is

beatrax (internal codename *diederik*) is a **local-only personal finance
dashboard** that pulls transactions from ASN Bank, ICS Cards, PayPal, and
Google Play into a single calm "this month at a glance" view. It resolves the
routing chains between accounts (PayPal → ASN/ICS, ICS → ASN via bulk-iDEAL
settlement) so fixed monthly payments, true funding sources, and upcoming cash
flow are visible in one place instead of buried across statements. Built for a
single privacy-conscious household; ships as a NativePHP desktop app and as a
self-hosted web app.

## Core Value

**Show me, in one place, what I actually owe and where the money truly came
from — across every account chain — so my monthly finances stop being a manual
reconciliation puzzle.** If everything else fails, the system must surface the
complete picture of monthly fixed payments and the funding chain that connects
them.

## Requirements

### Validated

<!-- Shipped and confirmed across v1.0–v1.2. -->

- ✓ Idempotent multi-format ingestion (ASN CSV/CAMT.053/MT940, ICS PDF, PayPal CSV, email receipts) — v1.0
- ✓ Multi-currency tracking with original + settled-EUR preserved — v1.0
- ✓ Cross-account chain resolution (PayPal funding, ASN→ICS bulk-iDEAL decomposition) — v1.0
- ✓ Email-receipt ingestion (Gmail / Microsoft Graph / .eml / .mbox) — v1.0
- ✓ Recurring detection + subscription-drift alerts — v1.0
- ✓ 30/60/90-day per-account forecasting with what-if scenarios — v1.0
- ✓ Operational hardening (db:backup/restore, doctor, system-alerts, launchd daemons) — v1.0
- ✓ PHP 8.5 runtime floor + desktop packaging + bounded large-import persistence — v1.1
- ✓ Category Budgets — v1.2
- ✓ Cash book (manual/off-bank entries via a synthetic source) — v1.2 (SEED-007)
- ✓ Net-worth roll-up (assets − liabilities, per-account breakdown) — v1.2 (SEED-005)
- ✓ Month-over-month spending comparison — v1.2 (SEED-006)
- ✓ Tax-deductible tagging + per-year CSV/PDF export (TAX-01/02/03: tag with optional category+note+year-override on four surfaces, /tax year cockpit, 6-country deduction corpus, setup-wizard step) — v1.3 Phase 7
- ✓ Full-text search over all retained history (SRCH-01/02: FTS5 trigram index over merchant/description/tax-note, ⌘K palette server hits + entity sections, /transactions search-and-filter surface with date/account/amount/category filters + typed tokens) — v1.3 Phase 8
- ✓ Unusual-charge / anomaly alerts (ANOM-01/02: new Anomaly module cloning DriftAlerts — robust MAD/percentile large-vs-typical + large-AND-first-time + duplicate-window detectors, one-alert/multi-reason aggregation behind UNIQUE(transaction_id), server-computed ±15% suppression band, /drift type switch + reason chips + dashboard tile + amber nav badge + settings, reactive queue + chunked backfill + snooze-revival + safety-net sweep) — v1.3 Phase 9
- ✓ Base-currency FX conversion (pluggable + offline FX so non-EUR balances roll into one reporting currency) — v1.3 Phase 1
- ✓ Savings goals (target amount + date, contribution tracking, forecast-driven finish) — SEED-003, v1.3 Phase 2
- ✓ Savings pots / envelopes (virtual sub-balances over a real account) — SEED-011, v1.3 Phase 3
- ✓ Responsive + installable PWA (phone-legible surfaces, installable, offline app-shell that never caches financial HTML) — SEED-008 / PWA-01/02/03, v1.3 Phase 4
- ✓ PIN / biometric app-lock (Argon2id + libsodium key-wrap, server-authoritative idle re-lock, WebAuthn biometric, LOCK-04 at-rest key-unlock gate) — SEED-009, v1.3 Phase 5
- ✓ Bills / cash-flow calendar (upcoming fixed payments on a calendar with a running projected balance) — v1.3 Phase 6
- ✓ "You could save here" insights from the support-resource corpus — v1.2 (SEED-010)
- ✓ Counterparties + support-resource profiles (cancel/help/cheaper-plan links) — v1.2
- ✓ Encrypted backup & restore (Argon2id + XChaCha20-Poly1305, quantum-safe by construction) — v1.2
- ✓ Self-hosted server deployment path (Docker Compose + bare metal + `beatrax:setup`) — v1.2

### Active — v1.4 "Sync" (Local-first E2E device sync)

v1.3 "Local & in sync" **shipped 2026-06-14 as `v1.3.0`** — its three
independent tracks (Goals & motivation, Take-it-with-you, Insight & records;
Phases 1–9) are all in Validated above. Its fourth track — local-first
end-to-end-encrypted device sync, always the critical-path / highest-risk
effort and designed as a separate shippable boundary — is now carved out as its
own milestone, **v1.4 "Sync"**.

**v1.4 "Sync" — Local-first E2E device sync (full P2P multi-master) — SEED-001**
- [ ] Op-log / CRDT merge layer over SQLite (signed op-log + HLC, SQLite as a materialized view) — Phases 10 (spike) + 11
- [ ] Device identity + pairing (Ed25519/X25519, QR + word-code) — Phase 12
- [ ] Encrypted transport (Noise XX/IK + XChaCha20-Poly1305), LAN-direct (mDNS) + zero-knowledge relay — Phase 13
- [ ] At-rest encryption per device + device revocation/rekey (consumes LOCK-04 from v1.3 Phase 5) — Phase 14
- [ ] Mobile client wired as a fully synced peer (depends on the v1.3 PWA + the full sync stack) — Phase 15

### Out of Scope

<!-- Explicit boundaries with reasoning to prevent re-adding. -->

- **Live open-banking / PSD2 connector (SEED-002)** — deferred to a later milestone; carries provider research + privacy trade-offs and is a standalone effort. File/email import stays the recommended path.
- **Household / second concurrent user** — schema is multi-user-ready but a real shared-household surface is a separate milestone; not in v1.3.
- **Cloud sync that can read data** — violates the local-only core promise; sync stays end-to-end encrypted, zero-knowledge.
- **iCloud Mail ingestion** — out of scope per project constraints (provider APIs only).
- **Acting on the user's behalf (auto-cancel/switch contracts)** — beatrax informs via official links; it never transacts.

## Context

- Three milestones shipped (v1.0 2026-05-19 → v1.2 2026-06-07); 1644+ tests, 34+ arch invariants, 20 modules at the v1.0 cut and growing.
- Foundations v1.3 reuses: **libsodium / Ed25519** (auto-update manifest signing — `Modules/Core/Public/Services/ElectronUpdateChannel.php`, `config/auto_update.php`), **`UserDataPathService`** (single storage-path authority), **`FingerprintComposer`** (merge-safe import idempotency), the **`SourceAdapter`** registry, **DriftAlerts + the scheduler/queue**, and the v1.2 **encrypted-backup** crypto (Argon2id + XChaCha20).
- Server-deployment landed in v1.2, which is what unblocks the mobile (SEED-008) and device-sync (SEED-001) work.
- Living docs: `CLAUDE.md` (stack + constraints), `.docs/` (features, ADRs, history, deployment), `CHANGELOG.md` (release source of truth).

## Constraints

- **Tech stack**: PHP 8.5 + Laravel 13 + Livewire 4 + Volt + Flux UI + Tailwind 4 + SQLite (WAL) — pinned per CLAUDE.md.
- **Hosting**: Local only (desktop NativePHP, or self-hosted on a LAN/VPN) — financial data must never leave the user's control.
- **Modular architecture**: `nwidart/laravel-modules`; cross-module access only through Public services/events; per-module Public/Internal split.
- **Code quality gates (CI)**: Larastan level 10 strict + Laravel Pint + Pest. No float money (BIGINT minor units + brick/money). No `ext-imap`.
- **Idempotency**: every ingestion path safe to re-run (fingerprint dedup).
- **History**: retained forever, never pruned.
- **Multi-user readiness**: every domain table carries a nullable `user_id` + `BelongsToUser`.
- **Secrets**: local config file / OS keychain, never `.env`.
- **GSD workflow**: file changes go through a GSD command (see CLAUDE.md "GSD Workflow Enforcement").

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| v1.3 sync = full P2P multi-master (not hub-and-spoke) | User wants no "main" device; maximum resilience, all copies equal | — Pending (de-risk via the op-log/CRDT spike before committing downstream sync phases) |
| v1.3 scoped as one large milestone (4 tracks, ~15 phases) | User opted for maximal scope over splitting into v1.3/v1.4 | ⚠️ Revisit — Track 4 is the critical path; Tracks 1–3 ship independently if sync slips |
| Bootstrap GSD baseline now (PROJECT/REQUIREMENTS/ROADMAP/STATE) | v1.0–v1.2 ran on `.docs/`; v1.3 adopts full GSD tracking | — Pending |
| Skip milestone-level research | Novel areas (CRDT, Noise, FTS) are researched per-phase at plan-phase; SEED notes mandate it there | — Pending |
| Open-banking (SEED-002) deferred | Standalone, research/compliance-heavy; not core to v1.3's themes | ✓ Good |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd:transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd:complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-06-14 — v1.3 "Local & in sync" SHIPPED as `v1.3.0` (Phases 1–9, Tracks 1–3: base-currency FX, savings goals + pots, installable PWA, PIN/biometric app-lock, bills calendar, tax tagging + export, full-text search, anomaly alerts). Track 4 (local-first E2E device sync, SEED-001) carved out as the new active milestone v1.4 "Sync" (Phases 10–15). Closing-phase detail — Phase 9 (Unusual-charge / anomaly alerts, ANOM-01/02) complete: new Modules/Anomaly cloning the DriftAlerts shape — AnomalyEvaluator orchestrating three detectors (robust median/MAD + per-category p95 large-vs-typical, large-AND-first-time, 7-day duplicate window excluding recurring series), one-alert/multi-reason aggregation behind the UNIQUE(transaction_id) idempotency seam, pre-insert suppression with a server-computed ±15% band, sole-mutator state machine with a dismissed→open undo edge; Public surface (AnomalyAlertQuery + DTO/mapper exposing reasons[], five Actions with cross-user 404 guard); orchestration (queue-only TransactionImported listener → (userId,txnId)-unique DetectAnomaliesJob, chunked idempotent backfill guarded by anomaly_backfilled_at, hourly snooze-revival + safety-net sweep); UI (/drift type switch consuming the Public query, reason chips, separate dashboard tile + amber nav badge, Settings anomaly section with sensitivity/floor + removable suppression rules, first-activation backfill). Driven browser UAT passed across desktop + phone (type switch, ack/snooze/mark-as-expected/dismiss, tile + badge reactivity, suppression list + remove); deep code review --fix closed 2 blockers (null-band suppression gap, synthetic-large muting) + 7 warnings + IN-03 with +18 regression tests; verification passed 30/30; suite 3662 green, PHPStan L10 strict + Pint clean. Prior: Phase 8 (Full-text search over history, SRCH-01/02) complete: new Search module (FTS5 trigram external-content index over counterparty/description/tax-note, class_exists-guarded provider), synchronous SearchIndexWriter (actor-verified, atomic delete-then-insert) on every import/tag write + chunked search:reindex command + doctor FTS health probe, SearchQuery read service (FTS MATCH + AND semantics + amount branch + EUR-only summary totals + cursor pagination + sentinel-escaped highlight/snippet), ⌘K palette server endpoint (transaction + entity sections, token autocomplete) and the /transactions search-and-filter surface (search box, date/account/amount/category filter popovers + phone bottom sheet, highlighted result rows, no-results state, typed account:/category:/amount:/after:/before: tokens). Driven browser QA across desktop + phone; UAT found+fixed 4 issues (FTS-highlight stored XSS, palette Livewire-4 \$wire/__v_raw 500, palette name x-html XSS, Category-chip user_id scoping); standard code review 16 findings (13 fixed incl. multi-user index isolation + atomic FTS upsert + filters-only crash + advertised-token wiring, 3 perf/by-design deferred); verification passed 2/2; suite 3657 green, PHPStan L10 clean. Prior: Phase 7 (Tax-deductible tagging + per-year export) complete: Tax module (two-table schema, tagging actions, override-aware TaxYearQuery), 6-country deduction corpus + settings section + setup-wizard step, /tax year cockpit with seasonal default, CSV (D-15 audit columns, formula-escaped) + PDF (dompdf v3, locked down) exports, tax badge + picker + batch Tag-all across transactions list / detail / counterparty profile / cash book. Driven browser QA on all surfaces; deep review 23/23 findings fixed; verification passed 24/24. Prior: Phase 5 (PIN/biometric app-lock, SEED-009) complete: PIN lock with Argon2id + libsodium key-wrap chain, server-authoritative middleware (idle timeout + engage beacon), lock screen + privacy veil + cross-tab sync, settings (enable/change/disable PIN, forgot-PIN re-wrap), WebAuthn biometric enrollment/unlock, desktop hide/close lock listener, and the LOCK-04 AppLockKeyService release gate Phase 14 consumes (dev-console probe proves released/withheld). Browser QA + 24 review findings fixed; Touch ID + desktop-bundle paths tracked in 05-HUMAN-UAT.md. Prior: Phase 4 (Responsive + installable PWA, SEED-008) complete: installable PWA (manifest, icons, versioned service worker with app-shell cache that never caches financial HTML, offline page), mobile shell (top bar, drawer, bottom sheet), phone responsive pass across all ~36 surfaces, ApexCharts 3→5, phone infinite scroll on transactions (PWA-01/02/03).*
