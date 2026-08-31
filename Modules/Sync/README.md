# Modules/Sync

Peer-to-peer device sync: an append-only op-log, Hybrid Logical Clocks, and
last-write-wins per field, merged against Beatrax's live SQLite schema — real
triggers, UNIQUE indexes, and pair-link foreign keys included.

Implementation detail for this module lives in
[`.docs/features/sync/`](../../.docs/features/sync/). Start with
`architecture.md`; the merge semantics are in `op-log-merge-rules.md` and the
pairing ceremony in `pairing-handshake.md`.

## Entry point

`Modules/Sync/Internal/Merge/OpLogReplayer.php`

```php
replay(array $entries, int $userId, RowHistoryPolicy $history = RowHistoryPolicy::FromDurableLog): void
```

The replayer takes an optional `SearchIndexWriterContract`. Passing `null`
disables FTS5 freshness updates — used by the `OpLogRebuilder` rebuild path,
which refreshes search in bulk afterwards, and by tests that do not need it.

## Conflict-scenario coverage

These tests pin the merge outcomes the model has to guarantee. They run
against the real schema, so a trigger or index change that breaks a
resolution shows up here.

| Test file | Scenario |
|-----------|----------|
| `Feature/ConcurrentSameFieldEditTest.php` | Two devices recategorize the same transaction; the HLC tie-break decides the winner |
| `Feature/DeleteVsEditTombstoneTest.php` | Delete-wins when the tombstone HLC is higher, edit-wins when it is lower |
| `Feature/ClockSkewHlcOrderingTest.php` | Three replay orderings of the same ops resolve to the same winner |
| `Feature/ImportDedupUnderMergeTest.php` | The fingerprint UNIQUE index deduplicates the same import arriving from two devices |
| `Feature/TriggerAwareRebuildTest.php` | UPDATE-trigger compose, `CREATE_ROW`, and the pair-link cascade with its forged-signature gate |
| `Feature/CrossUserScopeTest.php` | Cross-user replay is blocked at two independent guard layers |

```sh
vendor/bin/pest Modules/Sync/tests/ --no-coverage
```

## Trigger handling

`TriggerAwareRebuildTest` brackets a probe with `DROP TRIGGER` /
`CREATE TRIGGER`, which is only safe inside a `RefreshDatabase` test
transaction. **Production never drops a trigger** — the merge path applies
incremental `UPDATE` ops rather than rebuilding a row by full re-insert.
