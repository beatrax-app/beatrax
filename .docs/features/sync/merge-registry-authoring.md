# Adding a table to the merge registry

The op-log replayer is deliberately schema-agnostic: it applies field-level
operations to whatever table an op names. That leaves it with two questions it
cannot answer on its own. When two devices changed the same column
concurrently, which change wins — or should they be combined? And when an op
says "create this row", which columns must be present before the insert can
possibly succeed?

`MergeRulesRegistry` is the answer to both. It maps table → field → merge
strategy, plus two per-table keys, and it is the only thing that has to change
to bring a new table under sync. There is no engine change.

## The shape

```php
'goals' => [
    'name'            => ['nullable' => false],
    'target_minor'    => ['nullable' => false],
    '_delete_wins'    => true,
    '_create_required' => ['name', 'target_minor'],
],
```

**Strategies.** The `MergeStrategy` enum names three: `Lww` (last writer wins,
per field), `GCounter` (a grow-only counter that sums rather than overwrites —
`merchant_memories.occurrence_count` is the one that needs it), and `OrSet` (an
observed-remove set, for a `{v, tag}`-shaped collection). **Lww is the default
and is never written out**: a field entry carries a `'strategy'` key only where
it is one of the other two. An unregistered table or field falls back to Lww
the same way.

**`_delete_wins`** decides the tie: when a tombstone and an edit carry the same
HLC, does the row die? Default true.

**`_create_required`** is the list below, and it is where the mistakes happen.

## The rule for `_create_required`

> List exactly the columns that are `NOT NULL` **and have no database default**.

"Exactly" is enforced in both directions by
`MergeRulesRegistrySchemaGuardTest`, for every registered table. A name that is
not such a column quarantines every create of that table; a column MISSING from
the list is worse, because the create passes the completeness gate and then dies
at the INSERT. `transactions.posted_at` / `.booked_at` / `.value_date` and
`goals.start_date` / `.target_date` all sat in that second gap.

Everything else is a consequence of that sentence, but the consequences are not
obvious, so here they are named.

### A NOT NULL column *with* a default must stay out

This is the trap that has bitten repeatedly. A column like
`saved_reports.pinned` is `NOT NULL` and therefore `nullable: false` in the
strategy map — but it has a database default, so an insert that omits it
succeeds. List it in `_create_required` and the replayer demands a value the op
never carries, and the create is refused for no reason.

Columns currently in that category: `saved_reports.pinned`,
`envelope_settings.overspend_mode`'s sibling `threshold_percent`,
`notifications.state`, `drift_alerts.state`, `anomaly_alerts.state`,
`system_alerts.created_at`, `recurring_series.state` / `.cadence` /
`.variance_tolerance_percent` / `.next_expected_confidence_low`,
`tax_deduction_categories.status` / `.sort_order`, `accounts.default_currency`,
and every counter on `import_runs`.

`categorization_rules` is the extreme case: `priority`, `combinator`, `active`
and `hits_count` all carry defaults and `user_id` is nullable, so the table has
*no* NOT-NULL-without-default column at all and its `_create_required` is
legitimately empty. `RuleSchemaMigrationTest` asserts that emptiness so nobody
"fixes" it later.

### Primary keys stay out — with one exception

The replayer seeds the primary key from the op's own `pk`, so an `id` column
never belongs in the list. `notifications.id` is the exception: it is a sha256
string computed by domain code before insert rather than a database
autoincrement, and the insert fails the `id` NOT NULL constraint if it is
missing. So that one *is* listed.

`anomaly_alerts.id` looks like the same case and is not. It is derived from the
`(user_id, transaction_id)` its own unique index names, so both devices compute
the same value for the same charge — and the applier still seeds it from the
op's pk, so it stays out.

### `user_id` is usually nullable, and sometimes is not

The multi-user convention leaves `user_id` nullable on most tables, which keeps
it out of the list. `forecast_scenarios`, `envelope_assignments`,
`envelope_settings` and `notification_preferences` are the exceptions where it
is `NOT NULL`, so it is required there.

### Every name must be a real column

`_create_required` is not free text. `MergeRulesRegistrySchemaGuardTest` holds
every registered table's list against the migration's actual
NOT-NULL-without-default set, in both directions; the per-table files
(`TransactionSplitsRegistryColumnsTest`,
`EnvelopeAssignmentsRegistryColumnsTest` and their siblings) additionally pin
the exact expected list where the column set carries a specific trap. A typo
here is a create that fails only on a peer, only during catch-up, and only for
that one table.

## Append-only ledgers declare no strategy at all

`envelope_moves`, `goal_contributions`, `pot_movements` and
`recurring_series_occurrences` are insert-and-never-edit. A row exists or it
does not, so there is no mergeable field, and each carries only
`_create_required` and `_delete_wins` with no strategy keys.

That is not an oversight to be filled in later — a SET op against one of these
is meaningless, and `SyncCaptureListener` reports an `edit` on
`goal_contributions` as an unknown mutation type rather than writing one.
`recurring_series_occurrences` leans on the same idempotency seam on the peer
that it uses locally: its `(series, transaction)` unique index is what absorbs a
duplicate replay, which `CreateRowInsertFailure::AlreadyPresent` classifies and
passes over in silence.

## Registration order is not insertion order

The rule groups in `MergeRulesRegistry` are arranged for a reader:
transactions in the first group, the accounts they reference in the third.
Replaying in that order sent a peer a transaction before the account existed,
SQLite refused the insert on the foreign key, and the whole catch-up aborted.

`CoveredTableOrder` fixes that by deriving the order from the **live foreign
keys** rather than from a hand-maintained list. `insertionOrder()` settles the
graph parents-first in at most one pass per table, appends any remaining cycle
in registry order rather than looping forever, and falls back to plain registry
order if the schema cannot be introspected. `deletionOrder()` is the exact
reverse, so a scoped delete never strands a foreign key.

Self-references are ignored — a table orders itself internally — and a foreign
key pointing at an uncovered table is skipped, because such a target is either
always present or already reported by the merge-rules schema contract.

So when you add a table you do **not** need to place it correctly among the
groups. You do need it to have real foreign keys, since that is what the
ordering reads.

Several per-table comments still name an ordering ("BEFORE transactions",
"AFTER pots") — those record the failure that motivated covering the table, not
a constraint the reader has to maintain by hand.

## The registry is also an allow-list

`isRegistered()` is what the replayer's table gate calls to quarantine an op
naming a table nobody registered. That is a security property, not tidiness: a
compromised peer must not be able to aim a `SET`, `DELETE` or `CREATE` at an
arbitrary wire-supplied table name that was never meant to be replayable at
all.

The same reasoning is why the Migration module registers only its two
*persistent* tables — `migration_source_map` and `migration_import_baseline`,
so a second device cannot silently diverge and double-import — while the six
per-run scratch staging tables and their parent run table are deliberately left
out.

## Merge rules are half the job

A table with merge rules and no capture is worse than a table with neither. It
ships in the pairing snapshot, so both devices start identical, and then every
later edit stays on the device that made it — the two devices agree about
history and disagree about the present, with nothing on screen saying so.
`SyncCaptureCoverageTest` holds the two lists against each other. See
[architecture.md](architecture.md) for the capture side.

## A few table facts worth not rediscovering

The registry names real columns, and several of them are not the columns you
would guess:

- `goals` stores its target as `target_minor` with a `target_date` deadline —
  not `target_amount_minor`.
- `pots` has no target column at all; the target lives on the linked goal.
- `counterparties` folds `website` and `logo_url` into a `metadata` JSON
  column, and its identity string is `slug`.
- `merchant_aliases.pattern` is the immutable first-seen raw description and
  the per-user identity column.
- The per-envelope notify threshold lives on `envelope_settings`, which is
  the only table that has ever carried one.
- `tax_transaction_tags.transaction_split_id` must replay, or a per-leg
  deduction collapses into a whole-transaction tag and corrupts exported tax
  amounts.
- `chain_links.to_transaction_id` is NULL by design on hint and
  exceeded-tolerance candidate rows, which a trigger pair on the table
  enforces — so it is deliberately absent from `_create_required`.
- `user_preferences.skipped_update_versions` is a grow-only list stored as a
  plain JSON array of strings rather than the `{v, tag}` shape `or_set`
  requires, so it merges as `lww`: two devices skipping different versions
  concurrently keep only the later one.
- `notification_preferences` rows sync so the other-devices settings panel can
  read them, but a device only ever *obeys* its own row. That policy lives in
  `SuppressionEvaluator`, not in the registry.
- `system_alerts` rows with a NULL `user_id` are system-wide and belong to no
  one; the backfill scopes on `user_id` and never captures them, deliberately.
- `users` is the one covered table with no `user_id` column: its own primary
  key IS the owner. `RowOwnership` self-scopes it, the wire pk is ignored (two
  devices mint different autoincrements for one reader), and a create or a
  tombstone for it is refused — a peer may edit the reader's settings, never
  mint or remove the reader. The row is also the one that is not all one
  thing, so the registry carries two extra lists beside the field map:
  `DEVICE_LOCAL_COLUMNS` (password, theme, the developer gate) and
  `ASKED_OF_EVERY_JOINER` (`country_code`). `EveryUserColumnIsPlacedTest`
  refuses a new column that lands in none of the three.

## See also

- [How a replay decides what the row should say](op-log-merge-rules.md) — the
  ordering and encoding rules these strategies feed.
- [Op-log replay under live triggers](oplog-replay-under-live-triggers.md)
- [Peer session lifecycle](peer-session-lifecycle.md) — where replayed ops
  arrive from.
- [Sync architecture](architecture.md) — the surrounding module.
