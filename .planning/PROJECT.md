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
- ✓ "You could save here" insights from the support-resource corpus — v1.2 (SEED-010)
- ✓ Counterparties + support-resource profiles (cancel/help/cheaper-plan links) — v1.2
- ✓ Encrypted backup & restore (Argon2id + XChaCha20-Poly1305, quantum-safe by construction) — v1.2
- ✓ Self-hosted server deployment path (Docker Compose + bare metal + `beatrax:setup`) — v1.2

### Active — v1.3 "Local & in sync"

The largest milestone to date: four parallel tracks. Track 4 (local-first
end-to-end-encrypted device sync) is the critical path and the dominant risk.

**Track 1 — Goals & motivation**
- [ ] Base-currency FX conversion (closes the net-worth non-EUR exclusion gap)
- [x] Savings goals (target amount + date, contribution tracking, projected finish) — SEED-003 (validated in Phase 2, 2026-06-08)
- [x] Savings pots / envelopes (virtual sub-balances over a real account) — SEED-011 (validated in Phase 3, 2026-06-10)

**Track 2 — Take it with you**
- [x] Responsive + installable PWA over the self-hosted web UI — SEED-008 (validated in Phase 4, 2026-06-10)
- [x] PIN / biometric app-lock (also the at-rest key-unlock gate for sync) — SEED-009 (validated in Phase 5, 2026-06-12; Touch ID / desktop-bundle paths await desktop-runtime UAT)

**Track 3 — Insight & records**
- [ ] Bills / cash-flow calendar
- [ ] Tax / deductible tagging + per-year export
- [ ] Full-text search over transaction history
- [ ] Unusual-charge / anomaly alerts

**Track 4 — Local-first E2E device sync (full P2P multi-master)** — SEED-001
- [ ] Op-log / CRDT merge layer over SQLite (signed op-log + HLC, SQLite as a materialized view)
- [ ] Device identity + pairing (Ed25519/X25519, QR + word-code)
- [ ] Encrypted transport (Noise XX/IK + XChaCha20-Poly1305), LAN-direct (mDNS) + zero-knowledge relay
- [ ] At-rest encryption per device + device revocation/rekey
- [ ] Mobile client wired as a fully synced peer

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
*Last updated: 2026-06-12 — Phase 5 (PIN/biometric app-lock, SEED-009) complete: PIN lock with Argon2id + libsodium key-wrap chain, server-authoritative middleware (idle timeout + engage beacon), lock screen + privacy veil + cross-tab sync, settings (enable/change/disable PIN, forgot-PIN re-wrap), WebAuthn biometric enrollment/unlock, desktop hide/close lock listener, and the LOCK-04 AppLockKeyService release gate Phase 14 consumes (dev-console probe proves released/withheld). Browser QA + 24 review findings fixed; Touch ID + desktop-bundle paths tracked in 05-HUMAN-UAT.md. Prior: Phase 4 (Responsive + installable PWA, SEED-008) complete: installable PWA (manifest, icons, versioned service worker with app-shell cache that never caches financial HTML, offline page), mobile shell (top bar, drawer, bottom sheet), phone responsive pass across all ~36 surfaces, ApexCharts 3→5, phone infinite scroll on transactions (PWA-01/02/03).*
