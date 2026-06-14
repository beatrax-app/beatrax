# Milestones

Historical record of shipped milestones. Newest first.

---

## v1.3 "Local & in sync" — shipped 2026-06-14 (`v1.3.0`)

**Phases:** 9 (Phases 1–9, Tracks 1–3) · **Plans:** 41 · **Scope carved out:** Track 4 (device sync) deferred to v1.4.

Three independent tracks shipped; the fourth (local-first E2E device sync) was always designed as a separate shippable boundary and became its own v1.4 milestone.

### Delivered

- **Base-currency FX conversion** (Phase 1) — pluggable + offline FX so non-EUR balances roll into one reporting currency.
- **Savings goals** (Phase 2, SEED-003) — target amount/date, contribution tracking, forecast-driven finish date.
- **Savings pots / envelopes** (Phase 3, SEED-011) — virtual sub-balances that reconcile against a real account.
- **Responsive + installable PWA** (Phase 4, SEED-008, PWA-01/02/03) — phone-legible surfaces across ~36 views, installable, offline app-shell that never caches financial HTML.
- **PIN / biometric app-lock** (Phase 5, SEED-009) — Argon2id + libsodium key-wrap, server-authoritative idle re-lock, WebAuthn biometric, and the LOCK-04 at-rest key-unlock gate (consumed later by v1.4 Phase 14).
- **Bills / cash-flow calendar** (Phase 6) — upcoming fixed payments on a calendar with a running projected balance.
- **Tax / deductible tagging + per-year export** (Phase 7, TAX-01/02/03) — tag tax-relevant transactions on four surfaces, /tax year cockpit, 6-country deduction corpus, CSV + PDF export.
- **Full-text search over history** (Phase 8, SRCH-01/02) — FTS5 trigram index over merchant/description/tax-note, ⌘K palette server hits, /transactions search-and-filter surface with typed tokens.
- **Unusual-charge / anomaly alerts** (Phase 9, ANOM-01/02) — new Anomaly module: robust MAD/percentile large-vs-typical + first-time-merchant + duplicate detectors, server-computed ±15% suppression band, /drift type switch + reason chips + dashboard tile + amber nav badge + settings, reactive queue + chunked backfill + sweeps.

### Quality

Each phase ran the full GSD gate chain: browser-MCP UAT, code review (`--fix`), goal verification, and (Phase 9) threat verification. Final suite at ship: 3662 tests green; PHPStan L10 strict + Pint clean across the codebase. Requirements: all v1.3 (Tracks 1–3) requirements Complete.

### Deferred to v1.4

- Track 4 — local-first E2E device sync (SEED-001): op-log/CRDT merge engine, device pairing, encrypted transport + relay, at-rest encryption/revocation, mobile synced peer (Phases 10–15).
- Tracked open items at ship (carried into v1.4 scope or backlog): SEED-001 (sync, → v1.4), SEED-002 (open-banking, out of scope), `windows-queue-worker-timeout` debug session (root cause found), plus UAT/verification gaps and context questions surfaced during the milestone.

### Archive

- Full v1.3 roadmap snapshot: `.planning/milestones/v1.3-ROADMAP.md`
