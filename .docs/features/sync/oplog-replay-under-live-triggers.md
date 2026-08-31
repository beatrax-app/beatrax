# Replaying the op-log against live SQLite triggers

The op-log stores field-level operations, not row snapshots. Replaying them means issuing
real `INSERT` and `UPDATE` statements against the live ledger tables — tables that carry
enum-check triggers, `NOT NULL` columns and foreign keys of their own.

The tempting assumption is that a replay is "just data", and that anything the app once
wrote can be written again. It cannot. A replay reconstructs a row from a *subset* of its
columns, in a different statement shape than the one that first created it, and the schema
notices. Three interactions decide whether a given replay succeeds.

## Updating a column is not the same as inserting a row

`transactions` is guarded by two triggers, `transactions_type_check_insert` and
`transactions_type_check_update`. Only the first one guards a whole row. The update trigger
is declared `BEFORE UPDATE OF type` — it fires exclusively when the `type` column itself is
assigned.

The consequence is that field-by-field replay onto an **existing** row is free of trigger
interference for every column except `type`. Setting `category_id` from an op-log entry does
not consult the enum check at all, because `category_id` is not in the trigger's column
list. No trigger bracketing, no suspension of constraints, no special replay mode is needed
for the update path.

## A rebuild from scratch is not free

The moment a replay stops updating and starts *creating* rows — the delete-everything,
re-insert-from-the-log rebuild — the insert trigger fires on every row, and it demands a
valid `type`. Op-log entries carry only the fields that were actually changed, so a row
reconstructed from its history will frequently have no `type` at all, or a value that the
enum check rejects.

There are exactly two ways out, and they are not equivalent:

1. **Guarantee the field set is complete.** Row-creation ops must carry `type` alongside
   everything else. This is the option to prefer: the constraint keeps doing its job.
2. **Bracket the insert.** Read the trigger's DDL out of `sqlite_master`, `DROP TRIGGER`,
   insert, then re-execute the saved DDL to recreate it. SQLite stores the original
   `CREATE TRIGGER` text verbatim in `sqlite_master.sql`, so the restore is exact — but the
   window between the drop and the restore is unguarded, and any failure in between leaves
   the database with a missing constraint.

## `CREATE_ROW` needs a complete snapshot, not a subset

A row can be created purely from op-log field ops, but only if **every** `NOT NULL` column
is present in the field set. A partial set fails on a constraint error, not on anything the
sync layer can recover from.

Two columns are special:

- `user_id` is never in the field set. The replayer injects it from the scope it was called
  with, which is also what prevents a replayed op from writing into another user's rows.
- The primary key is carried as an explicit `id` field op, and it is what makes replay
  idempotent. The insert is a plain `insert()` whose duplicate-key failure is classified and
  swallowed by `CreateRowInsertFailure::AlreadyPresent`, so replaying the same creation ops
  twice leaves one row. It used to be `insertOrIgnore`, which also swallowed the NOT NULL
  violation a partial field set produces — no row, no quarantine, no log line.

That last point used to work differently. Before the 2026-07-06 redesign,
`categorization_rules` had flat `field` / `match` / `value` / `category_id` columns and a
`UNIQUE (user_id, field, match, value)` index, and *that* index was what absorbed duplicate
replays. The redesign moved those columns out to `rule_conditions` and `rule_actions` and
dropped the unique index with them. Idempotency now rests entirely on the primary key being
present in the op set — omit the `id` op and a repeated replay silently duplicates the row.

## Tombstoning one side of a pair does not delete the other

Transfer transactions reference each other through `pair_transaction_id`, declared
`ON DELETE SET NULL`. When a replay applies a tombstone to one side of the pair, the
partner row **survives**: it stays in the ledger as an ordinary unpaired transaction and its
`pair_transaction_id` becomes `NULL`.

The link is severed silently, not cascaded. Anything downstream that treats a paired
transaction as guaranteed to have a live partner has to handle the null, and a rebuild that
replays the tombstone will reproduce the orphaning every time.

## See also

- [Sync architecture](architecture.md)
