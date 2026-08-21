# Modules/Sync — KEPT THROWAWAY SPIKE (Phase 10, SYNC-04)

This module is a **kept-in-repo reference harness** from the Phase 10 op-log/CRDT spike. It is
**NOT the production merge engine** — Phase 11 owns that. Do not extend or depend on this module
for production sync work.

## Purpose

Validates that the append-only op-log / Hybrid Logical Clock / LWW-per-field model works against
Beatrax's live SQLite schema (real triggers, UNIQUE indexes, pair-link FKs). The authoritative
output is the findings document, not the code.

**Authoritative output:** `.planning/phases/beatrax-10-spike-op-log-crdt-merge-layer-prototype/10-FINDINGS.md`

## Conflict-scenario tests (all GREEN)

```
vendor/bin/pest Modules/Sync/tests/ --no-coverage
```

| Test file | Scenario |
|-----------|----------|
| `Feature/ConcurrentSameFieldEditTest.php` | D-05: two devices recategorize the same transaction; device-b wins HLC tie |
| `Feature/DeleteVsEditTombstoneTest.php` | D-06: delete-wins (tombstone HLC > edit HLC) and edit-wins (reversed) |
| `Feature/ClockSkewHlcOrderingTest.php` | D-07: three replay orderings all resolve to same winner (hlc_l=2000) |
| `Feature/ImportDedupUnderMergeTest.php` | D-08: fingerprint UNIQUE index deduplicates same import across two devices |
| `Feature/TriggerAwareRebuildTest.php` | Probe A: UPDATE trigger compose; Probe B: CREATE_ROW; Probe C: pair-link cascade + forged-sig gate |
| `Feature/CrossUserScopeTest.php` | T-10-02: cross-user replay blocked at two independent guard layers |

## What is stubbed (NOT production)

- **`Internal/Signing/DeviceKeySigner.php`** uses throwaway Ed25519 keypairs generated at test time. Real device identity (key provisioning, macOS Keychain storage, pairing) is Phase 12.
- **DROP TRIGGER / CREATE TRIGGER** bracketing in `TriggerAwareRebuildTest` Probe A works only inside `RefreshDatabase` test transactions. Production must NEVER drop triggers. Phase 11 uses incremental UPDATE ops instead of full-rebuild INSERT.
- **No FTS5 update:** `OpLogReplayer` bypasses Eloquent model events; `transaction_search_docs` is not updated after replay. Phase 11 must dispatch `SearchIndexWriter` events post-replay.

## Entry point

`Modules/Sync/Internal/OpLog/OpLogReplayer.php` — `replay(array $entries, int $userId): void`
