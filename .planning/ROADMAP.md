# Roadmap: beatrax — v1.3 "Local & in sync"

**Created:** 2026-06-07
**Milestone:** v1.3 "Local & in sync"
**Granularity:** Standard (mapping fixed by definition: 41 requirements → 15 phases)
**Coverage:** 41/41 requirements mapped

> The largest milestone to date. Four tracks. **Track 4 (Phases 10–15) is the
> critical path and the dominant risk.** Tracks 1–3 (Phases 1–9) have no
> dependency on Track 4 and run in parallel — if sync slips, Tracks 1–3 still
> ship.

## Scheduling / Critical Path

- **Critical path:** Track 4, Phases 10 → 11 → (12, 13, 14) → 15. Phase 11 is
  the single biggest piece; Phase 10 (the op-log/CRDT spike) de-risks it and
  **must complete and validate before Phase 11 commits downstream sync work**.

- **Start the Phase 10 spike immediately.** It has no dependencies and gates the
  entire highest-risk track.

- **Tracks 1–3 (Phases 1–9) run in parallel with Track 4.** None of them depend
  on sync. If Track 4 slips, Tracks 1–3 are a shippable subset of v1.3.

- **Cross-track dependency:** Phase 14 (at-rest encryption) consumes LOCK-04
  from Phase 5 (the at-rest key-unlock gate). Phase 15 (mobile peer) depends on
  Phase 4 (PWA) plus the full sync stack (Phases 11–14).

## Phases

### Track 1 — Goals & motivation

- [x] **Phase 1: Base-currency FX conversion** - Pluggable + offline FX so non-EUR balances roll into one reporting currency. _(completed 2026-06-07)_
- [x] **Phase 2: Savings goals (SEED-003)** - Target amount/date, contribution tracking, forecast-driven finish date. (completed 2026-06-08)
- [x] **Phase 3: Savings pots / envelopes (SEED-011)** - Virtual sub-balances that reconcile against a real account. (completed 2026-06-09)

### Track 2 — Take it with you

- [ ] **Phase 4: Responsive + installable PWA (SEED-008)** - Phone-legible surfaces, installable, offline shell.
- [ ] **Phase 5: PIN / biometric app-lock (SEED-009)** - App-lock + biometric + idle re-lock + at-rest key-unlock gate (LOCK-04).

### Track 3 — Insight & records

- [ ] **Phase 6: Bills / cash-flow calendar** - Upcoming fixed payments on a calendar with running projected balance.
- [ ] **Phase 7: Tax / deductible tagging + per-year export** - Tag tax-relevant transactions and export a year's set.
- [ ] **Phase 8: Full-text search over history** - Fast, filterable search across all retained history.
- [ ] **Phase 9: Unusual-charge / anomaly alerts** - Baseline-relative anomaly flags through the existing alerts surface.

### Track 4 — Local-first E2E device sync (full P2P multi-master, SEED-001) — CRITICAL PATH

- [ ] **Phase 10: SPIKE — op-log/CRDT merge-layer prototype** - De-risk the merge model against the live SQLite schema.
- [ ] **Phase 11: Change-capture + CRDT merge engine** - Signed op-log + HLC; SQLite as a materialized view. The biggest single piece.
- [ ] **Phase 12: Device identity + pairing** - Ed25519/X25519 identity; QR + word-code pairing; safety numbers.
- [ ] **Phase 13: Encrypted transport + LAN-direct + zero-knowledge relay** - Noise XX/IK + XChaCha20-Poly1305, mDNS, ciphertext-only relay.
- [ ] **Phase 14: At-rest encryption per device + revocation/rekey** - Passphrase-derived at-rest key gated by app-lock; device removal rotates the group key.
- [ ] **Phase 15: Mobile client wired as a fully synced peer** - Mobile holds its own encrypted copy and syncs as a full peer with status.

## Phase Details

### Phase 1: Base-currency FX conversion

**Goal**: Every figure in the app can be expressed in one user-chosen base currency, closing the net-worth non-EUR exclusion gap while preserving each account's original currency.
**Depends on**: Nothing (first phase, Track 1)
**Requirements**: FX-01, FX-02, FX-03, FX-04
**Success Criteria** (what must be TRUE):

  1. User can pick a base reporting currency in settings and every roll-up renders in it.
  2. Non-EUR balances convert via a pluggable rate provider, and conversion still works fully offline against the bundled rate source.
  3. The net-worth roll-up now includes previously-excluded non-EUR accounts (converted), while each account still shows its own original currency.
  4. For any converted figure, the user can see the rate used, its source, and its as-of date.

**Plans**: 5 plans
Plans:

- [ ] 01-01-PLAN.md — FX module scaffold: contract, ConversionResult DTO, exchange_rates + user-column migrations, test infra
- [ ] 01-02-PLAN.md — Rate providers (ECB/Frankfurter/bundled) + registry + ExchangeRateService + fetch job + seeder + scheduler
- [ ] 01-03-PLAN.md — NetWorthQuery FX integration (FX-03): convert non-EUR accounts, preserve original currency
- [ ] 01-04-PLAN.md — Settings: base-currency picker + online-fetch toggle + manual refresh (FX-01)
- [ ] 01-05-PLAN.md — NetWorthCard FX disclosure UI + CSS primitives + stale marker (FX-04)

### Phase 2: Savings goals (SEED-003)

**Goal**: User can set savings goals and watch real cash-flow drive measurable, forecast-backed progress toward them.
**Depends on**: Phase 1 (goal amounts/progress express in the base currency)
**Requirements**: GOAL-01, GOAL-02, GOAL-03, GOAL-04, GOAL-05
**Success Criteria** (what must be TRUE):

  1. User can create a goal with a name, target amount, and target date.
  2. User can link a goal to a savings account so transfers into it count as contributions.
  3. Each goal shows contributed-vs-target and percent complete.
  4. Each goal shows a projected finish date derived from actual cash-flow via the existing Forecasting engine.
  5. User can edit, complete, or archive a goal.

**Plans**: 4 plans
Plans:

- [x] 02-01-PLAN.md — Goals module scaffold: goals table + Goal model + factory + provider/route registration + Wave 0 test harness
- [x] 02-02-PLAN.md — GoalProgressQuery + GoalProjectionService + GoalProgressRow DTO (contribution sum, FX, run-rate finish date)
- [x] 02-03-PLAN.md — GoalWriter: create/edit + parseAmount + account-ownership validation + markComplete/archive/restore lifecycle
- [x] 02-04-PLAN.md — /goals Livewire page + Flux create/edit modal + lifecycle UI + dashboard summary card + sidebar/dashboard wiring

### Phase 3: Savings pots / envelopes (SEED-011)

**Goal**: User can carve a single real account balance into named virtual pots that always reconcile back to the real balance.
**Depends on**: Phase 2 (pots can link to goals; shared savings/allocation surface)
**Requirements**: POTS-01, POTS-02, POTS-03, POTS-04
**Success Criteria** (what must be TRUE):

  1. User can create named virtual pots inside one account balance.
  2. User can fund a pot and move money between pots without any real bank transfer.
  3. The real account balance always equals allocated + unallocated; the app shows both.
  4. User can link a pot to a savings goal or a budget category.

**Plans**: 4 plans
Plans:
**Wave 1**

- [x] 03-01-PLAN.md — Pots module scaffold: schema, model, factory, DTOs, exceptions, routes, AccountBalanceQuery + Wave 0 RED tests

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 03-02-PLAN.md — PotBalanceQuery + PotWriter: reconciliation, over-allocation guard, append-only movements, archive-release

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 03-03-PLAN.md — /pots Livewire page + Flux modals + reconciliation header + inline history + sidebar entry
- [x] 03-04-PLAN.md — Goals D-10 override: linked-pot drives goal progress + goals modal pot-picker (POTS-04)

### Phase 4: Responsive + installable PWA (SEED-008)

**Goal**: The self-hosted web UI is usable on a phone and installs as a standalone app with an offline shell — the prerequisite for a mobile sync peer.
**Depends on**: Nothing (Track 2)
**Requirements**: PWA-01, PWA-02, PWA-03
**Success Criteria** (what must be TRUE):

  1. Every authenticated surface is legible and usable at a phone-width viewport.
  2. User can install beatrax as a PWA (manifest, icons, standalone display mode).
  3. With the network unavailable, the offline-shell service worker still serves the app shell.

**Plans**: TBD
**UI hint**: yes

### Phase 5: PIN / biometric app-lock (SEED-009)

**Goal**: User can lock the app behind a PIN or biometric independently of account login, and that unlock becomes the gate that releases the sync at-rest key.
**Depends on**: Nothing (Track 2). Produces LOCK-04, consumed later by Phase 14.
**Requirements**: LOCK-01, LOCK-02, LOCK-03, LOCK-04
**Success Criteria** (what must be TRUE):

  1. User can enable an app-lock with a numeric PIN that is separate from account login.
  2. User can unlock with an OS biometric where the platform supports it.
  3. The app re-locks on idle timeout and on resume from background.
  4. Unlocking the app-lock is what releases the at-rest encryption key used by sync (LOCK-04 gate is exercisable end-to-end).

**Plans**: TBD
**UI hint**: yes

### Phase 6: Bills / cash-flow calendar

**Goal**: User can see upcoming fixed payments laid out on a month calendar with a running projected balance, sourced from existing recurring detection.
**Depends on**: Nothing (Track 3). Reuses recurring detection + the scheduler/DriftAlerts plumbing.
**Requirements**: CAL-01, CAL-02, CAL-03
**Success Criteria** (what must be TRUE):

  1. User can see upcoming fixed payments on a month calendar, sourced from recurring detection.
  2. Each calendar day shows expected inflows/outflows and a running projected balance.
  3. User can drill from a calendar entry to its recurring series / counterparty.

**Plans**: TBD
**UI hint**: yes

### Phase 7: Tax / deductible tagging + per-year export

**Goal**: User can mark transactions as tax-relevant and pull a clean per-year export for their records.
**Depends on**: Nothing (Track 3)
**Requirements**: TAX-01, TAX-02, TAX-03
**Success Criteria** (what must be TRUE):

  1. User can tag a transaction as tax-relevant, optionally with a deduction category.
  2. User can view all tax-tagged transactions for a chosen year.
  3. User can export a year's tax-tagged set as CSV/PDF.

**Plans**: TBD

### Phase 8: Full-text search over history

**Goal**: User can find any transaction across all retained history by merchant or description, with fast, filterable results.
**Depends on**: Nothing (Track 3)
**Requirements**: SRCH-01, SRCH-02
**Success Criteria** (what must be TRUE):

  1. User can search transactions by merchant/description across all retained history.
  2. Results are filterable by date range, account, amount, and category, and stay fast on multi-year data.

**Plans**: TBD

### Phase 9: Unusual-charge / anomaly alerts

**Goal**: The system proactively flags charges that deviate from the user's baseline, surfaced through the existing alerts plumbing.
**Depends on**: Nothing (Track 3). Reuses DriftAlerts + the scheduler/queue.
**Requirements**: ANOM-01, ANOM-02
**Success Criteria** (what must be TRUE):

  1. The system flags charges unusual versus the user's baseline (large-vs-typical, first-time merchant).
  2. Anomaly flags surface through the existing alerts surface and are dismissible/acknowledgeable.

**Plans**: TBD

### Phase 10: SPIKE — op-log/CRDT merge-layer prototype

**Goal**: Prove the op-log/CRDT merge model works against the live SQLite schema before any downstream sync phase commits — de-risking the entire critical path.
**Depends on**: Nothing. **Start immediately.** Gates Phase 11.
**Requirements**: SYNC-04
**Success Criteria** (what must be TRUE):

  1. A prototype merge layer runs against the real SQLite schema (not a toy schema) and produces a deterministic result.
  2. Concurrent edits replayed through the prototype merge without data loss, with the resolution rules captured and validated.
  3. The spike produces a go/no-go finding (chosen approach, known risks) that Phase 11 can build on.

**Plans**: TBD

### Phase 11: Change-capture + CRDT merge engine

**Goal**: Local mutations are captured to an append-only, per-device-signed, HLC-ordered op-log, and the SQLite store becomes a deterministic materialized view of the merged log — the foundation every other sync phase builds on. The biggest single piece in the milestone.
**Depends on**: Phase 10 (spike must validate the model first)
**Requirements**: SYNC-01, SYNC-02, SYNC-03
**Success Criteria** (what must be TRUE):

  1. Every local mutation is captured to an append-only, per-device-signed op-log ordered by Hybrid Logical Clocks.
  2. The SQLite store is reproducible as a deterministic materialized view of the merged op-log.
  3. Concurrent edits from multiple devices merge without data loss (LWW-per-field / CRDT sets); imported rows dedup via the existing FingerprintComposer fingerprints (SYNC-03).

**Plans**: TBD

### Phase 12: Device identity + pairing

**Goal**: Each device has a self-generated cryptographic identity whose private keys never leave it, and the user can pair new devices safely and verify them by fingerprint.
**Depends on**: Phase 11 (pairing exchanges sync identity/state). Reuses libsodium/Ed25519 already used for auto-update manifest signing (`ElectronUpdateChannel.php`, `config/auto_update.php`).
**Requirements**: PAIR-01, PAIR-02, PAIR-03
**Success Criteria** (what must be TRUE):

  1. Each device generates a long-term Ed25519 (signing) + X25519 (key-agreement) identity on first run, with private keys never leaving the device.
  2. User can pair a new device by scanning a QR (public identity + one-time secret), with a typed word-code fallback.
  3. User can view a device list with a human-verifiable safety-number/fingerprint per device.

**Plans**: TBD
**UI hint**: yes

### Phase 13: Encrypted transport + LAN-direct + zero-knowledge relay

**Goal**: Paired devices establish forward-secret, mutually-authenticated sessions and sync directly over the LAN, falling back to a relay that only ever holds ciphertext when a peer is offline.
**Depends on**: Phase 11 (op-log to transport), Phase 12 (device identities to authenticate)
**Requirements**: XPORT-01, XPORT-02, XPORT-03
**Success Criteria** (what must be TRUE):

  1. Paired devices establish a mutually-authenticated, forward-secret session (Noise XX/IK, XChaCha20-Poly1305).
  2. Two online devices sync directly over the LAN via mDNS discovery.
  3. When a peer is offline, devices sync via a zero-knowledge store-and-forward relay that only ever holds ciphertext.

**Plans**: TBD

### Phase 14: At-rest encryption per device + revocation/rekey

**Goal**: Each device's local database is encrypted at rest behind the app-lock-gated key, and removing a device cleanly rotates and re-wraps the shared group key to the remaining trusted devices.
**Depends on**: Phase 5 (LOCK-04 unlock gate), Phase 12 (device identities to re-wrap to). Reuses the v1.2 encrypted-backup crypto (Argon2id + XChaCha20) and `UserDataPathService` for storage routing.
**Requirements**: CRYPT-01, CRYPT-02
**Success Criteria** (what must be TRUE):

  1. Each device's local database is encrypted at rest with a key derived from the user passphrase (Argon2id) and unlocked via the app-lock (LOCK-04).
  2. Removing a device rotates the shared group key and re-wraps it to the remaining trusted devices.

**Plans**: TBD

### Phase 15: Mobile client wired as a fully synced peer

**Goal**: The mobile client is a first-class sync peer — it holds its own encrypted local copy and participates in merge, not a thin remote view — and shows the user clear sync status.
**Depends on**: Phase 4 (PWA), Phase 11 (merge engine), Phase 12 (pairing), Phase 13 (transport), Phase 14 (at-rest encryption)
**Requirements**: MOBILE-01, MOBILE-02
**Success Criteria** (what must be TRUE):

  1. The mobile client holds its own encrypted local copy and participates as a full sync peer (not a thin remote view).
  2. The mobile client shows sync status ("all devices up to date · synced 2m ago") and initial-sync progress.

**Plans**: TBD
**UI hint**: yes

## Progress

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Base-currency FX conversion | 5/5 | Complete | 2026-06-07 |
| 2. Savings goals | 4/4 | Complete    | 2026-06-08 |
| 3. Savings pots / envelopes | 4/4 | Complete   | 2026-06-09 |
| 4. Responsive + installable PWA | 0/0 | Not started | - |
| 5. PIN / biometric app-lock | 0/0 | Not started | - |
| 6. Bills / cash-flow calendar | 0/0 | Not started | - |
| 7. Tax / deductible tagging + export | 0/0 | Not started | - |
| 8. Full-text search over history | 0/0 | Not started | - |
| 9. Unusual-charge / anomaly alerts | 0/0 | Not started | - |
| 10. SPIKE — op-log/CRDT merge layer | 0/0 | Not started | - |
| 11. Change-capture + CRDT merge engine | 0/0 | Not started | - |
| 12. Device identity + pairing | 0/0 | Not started | - |
| 13. Encrypted transport + relay | 0/0 | Not started | - |
| 14. At-rest encryption + revocation | 0/0 | Not started | - |
| 15. Mobile synced peer | 0/0 | Not started | - |

---
*Roadmap created: 2026-06-07 for milestone v1.3 "Local & in sync"*
