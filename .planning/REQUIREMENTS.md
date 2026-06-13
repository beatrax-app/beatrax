# Requirements: beatrax — v1.3 "Local & in sync"

**Defined:** 2026-06-07
**Core Value:** Show me, in one place, what I actually owe and where the money truly came from — across every account chain.

> v1.3 is the largest milestone to date. Four tracks. Track 4 (device sync) is
> the critical path; Tracks 1–3 have no dependency on it and ship in parallel.

## v1.3 Requirements

### Track 1 — Goals & motivation

#### FX (base-currency conversion)
- [ ] **FX-01**: User can set a base reporting currency for the whole app.
- [ ] **FX-02**: System converts non-EUR account balances into the base currency using a pluggable rate provider, with a bundled offline rate source as fallback.
- [ ] **FX-03**: Net-worth roll-up includes previously-excluded non-EUR accounts (converted), while preserving each account's original currency.
- [ ] **FX-04**: User can see the rate, its source, and its as-of date for any converted figure.

#### GOAL (savings goals) — SEED-003
- [x] **GOAL-01**: User can create a savings goal with a name, target amount, and target date.
- [x] **GOAL-02**: User can link a goal to a savings account so contributions are tracked from transfers into it.
- [x] **GOAL-03**: System shows progress toward each goal (contributed vs target, % complete).
- [x] **GOAL-04**: System projects a realistic finish date from actual cash-flow via the Forecasting engine.
- [x] **GOAL-05**: User can edit, complete, or archive a goal.

#### POTS (savings pots / envelopes) — SEED-011
- [ ] **POTS-01**: User can create named virtual pots within a single account balance.
- [ ] **POTS-02**: User can fund a pot and move money between pots without a real bank transfer.
- [ ] **POTS-03**: Pots reconcile so the real account balance always equals allocated + unallocated.
- [ ] **POTS-04**: User can link a pot to a savings goal or a budget category.

### Track 2 — Take it with you

#### PWA (responsive + installable) — SEED-008
- [ ] **PWA-01**: All authenticated app surfaces are usable and legible on a phone-width viewport.
- [ ] **PWA-02**: User can install beatrax as a PWA (manifest, icons, standalone display).
- [ ] **PWA-03**: An offline-shell service worker serves the app shell when the network is unavailable.

#### LOCK (PIN / biometric app-lock) — SEED-009
- [ ] **LOCK-01**: User can enable an app-lock with a numeric PIN, separate from account login.
- [ ] **LOCK-02**: User can unlock with an OS biometric where the platform supports it.
- [ ] **LOCK-03**: The app re-locks on idle timeout and on resume from background.
- [ ] **LOCK-04**: The app-lock unlock gates release of the at-rest encryption key used by sync.

### Track 3 — Insight & records

#### CAL (bills / cash-flow calendar)
- [ ] **CAL-01**: User can see upcoming fixed payments on a month calendar, sourced from recurring detection.
- [ ] **CAL-02**: Each calendar day shows its expected inflows/outflows and a running projected balance.
- [ ] **CAL-03**: User can drill from a calendar entry to its recurring series / counterparty.

#### TAX (deductible tagging + export)
- [x] **TAX-01**: User can tag a transaction as tax-relevant (with an optional deduction category).
- [x] **TAX-02**: User can view all tax-tagged transactions for a chosen year.
- [x] **TAX-03**: User can export a year's tax-tagged set (CSV/PDF) for their records.

#### SRCH (full-text search over history)
- [x] **SRCH-01**: User can search transactions by merchant/description across all retained history.
- [x] **SRCH-02**: Search results are filterable (date range, account, amount, category) and fast on multi-year data.

#### ANOM (unusual-charge alerts)
- [ ] **ANOM-01**: System flags charges that are unusual versus the user's baseline (large-vs-typical, first-time merchant).
- [ ] **ANOM-02**: Anomaly flags surface through the existing alerts surface and are dismissible/acknowledgeable.

### Track 4 — Local-first E2E device sync (full P2P multi-master) — SEED-001

#### SYNC (change-capture + CRDT merge engine)
- [ ] **SYNC-01**: Local mutations are captured to an append-only, per-device-signed op-log ordered by Hybrid Logical Clocks.
- [ ] **SYNC-02**: The SQLite store is a deterministic materialized view of the merged op-log.
- [ ] **SYNC-03**: Concurrent edits from multiple devices merge without data loss (last-writer-wins-per-field / CRDT sets); imported rows dedup via existing fingerprints.
- [ ] **SYNC-04**: A spike validates the merge model against the live SQLite schema before downstream sync phases commit.

#### PAIR (device identity + pairing)
- [ ] **PAIR-01**: Each device generates a long-term Ed25519 (signing) + X25519 (key-agreement) identity on first run; private keys never leave the device.
- [ ] **PAIR-02**: User can pair a new device by scanning a QR (carrying the public identity + one-time secret), with a typed word-code fallback.
- [ ] **PAIR-03**: User can view a device list with a human-verifiable safety-number/fingerprint per device.

#### XPORT (encrypted transport + relay)
- [ ] **XPORT-01**: Paired devices establish a mutually-authenticated, forward-secret session (Noise XX/IK, XChaCha20-Poly1305).
- [ ] **XPORT-02**: Devices sync directly over the LAN when both are online (mDNS discovery).
- [ ] **XPORT-03**: When a peer is offline, devices sync via a zero-knowledge store-and-forward relay that only ever holds ciphertext.

#### CRYPT (at-rest encryption + revocation)
- [ ] **CRYPT-01**: Each device's local database is encrypted at rest; the key is derived from the user passphrase (Argon2id) and unlocked by the app-lock (LOCK-04).
- [ ] **CRYPT-02**: User can remove a device, which rotates the shared group key and re-wraps it to remaining trusted devices.

#### MOBILE (synced mobile peer)
- [ ] **MOBILE-01**: The mobile client holds its own encrypted local copy and participates as a full sync peer (not a thin remote view).
- [ ] **MOBILE-02**: The mobile client shows sync status ("all devices up to date · synced 2m ago") and initial-sync progress.

## Future Requirements (deferred)

### Open banking (SEED-002)
- **OB-01**: Optional, opt-in live transaction import via a choice of PSD2 aggregator adapters (bring-your-own-key), off by default, with a loud third-party-data warning and a transparency panel.

### Household
- **HH-01**: A real shared-household surface for a second concurrent user (schema is already multi-user-ready).

## Out of Scope

| Feature | Reason |
|---------|--------|
| Cloud sync that can read data | Violates local-only core promise; sync stays E2E-encrypted, zero-knowledge |
| Auto-cancel / auto-switch contracts | beatrax informs via official links; never transacts on the user's behalf |
| Malicious-paired-device / compromised-OS defense | A paired device legitimately holds keys (per SEED-001 threat model) |
| iCloud Mail ingestion | Provider APIs only, per project constraints |
| Live open-banking in v1.3 | Standalone, research/compliance-heavy — deferred (OB-01) |

## Traceability

Each v1.3 requirement maps to exactly one phase. See `.planning/ROADMAP.md`.

| Requirement | Phase | Status |
|-------------|-------|--------|
| FX-01 | Phase 1 | Pending |
| FX-02 | Phase 1 | Pending |
| FX-03 | Phase 1 | Pending |
| FX-04 | Phase 1 | Pending |
| GOAL-01 | Phase 2 | Complete |
| GOAL-02 | Phase 2 | Complete |
| GOAL-03 | Phase 2 | Complete |
| GOAL-04 | Phase 2 | Complete |
| GOAL-05 | Phase 2 | Complete |
| POTS-01 | Phase 3 | Pending |
| POTS-02 | Phase 3 | Pending |
| POTS-03 | Phase 3 | Pending |
| POTS-04 | Phase 3 | Pending |
| PWA-01 | Phase 4 | Pending |
| PWA-02 | Phase 4 | Pending |
| PWA-03 | Phase 4 | Pending |
| LOCK-01 | Phase 5 | Pending |
| LOCK-02 | Phase 5 | Pending |
| LOCK-03 | Phase 5 | Pending |
| LOCK-04 | Phase 5 | Pending |
| CAL-01 | Phase 6 | Pending |
| CAL-02 | Phase 6 | Pending |
| CAL-03 | Phase 6 | Pending |
| TAX-01 | Phase 7 | Complete |
| TAX-02 | Phase 7 | Complete |
| TAX-03 | Phase 7 | Complete |
| SRCH-01 | Phase 8 | Complete |
| SRCH-02 | Phase 8 | Complete |
| ANOM-01 | Phase 9 | Pending |
| ANOM-02 | Phase 9 | Pending |
| SYNC-04 | Phase 10 | Pending |
| SYNC-01 | Phase 11 | Pending |
| SYNC-02 | Phase 11 | Pending |
| SYNC-03 | Phase 11 | Pending |
| PAIR-01 | Phase 12 | Pending |
| PAIR-02 | Phase 12 | Pending |
| PAIR-03 | Phase 12 | Pending |
| XPORT-01 | Phase 13 | Pending |
| XPORT-02 | Phase 13 | Pending |
| XPORT-03 | Phase 13 | Pending |
| CRYPT-01 | Phase 14 | Pending |
| CRYPT-02 | Phase 14 | Pending |
| MOBILE-01 | Phase 15 | Pending |
| MOBILE-02 | Phase 15 | Pending |

**Coverage:**
- v1.3 requirements: 44 total (the prose elsewhere said "41"; the actual count of distinct v1.3 REQ IDs, excluding deferred OB-01/HH-01, is 44 — see note below)
- Mapped to phases: 44
- Unmapped: 0 ✓

> **Count note (2026-06-07):** PROJECT.md and the earlier draft of this file
> describe v1.3 as "41 requirements". Counting the distinct REQ IDs actually
> listed (excluding deferred OB-01 and HH-01) yields **44**. The roadmap maps
> all 44 to exactly one phase each, with no orphans or duplicates, so coverage
> is complete regardless of the headline number. The "41" figure in PROJECT.md
> is stale and should be corrected to 44 at the next milestone-doc review.

---
*Requirements defined: 2026-06-07*
*Last updated: 2026-06-07 after roadmap creation (traceability populated, 44/44 mapped)*
