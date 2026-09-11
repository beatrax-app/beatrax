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
'widgets' => [
    'name'             => ['nullable' => false],
    'sort_order'       => ['nullable' => true],
    '_delete_wins'     => true,
    '_create_required' => ['name'],
],
```

The table is invented, and that is the point. A template naming a real one is a
template somebody pastes into the registry, and it stops being true the day that
table gains a NOT NULL column: `MergeRulesRegistrySchemaGuardTest` holds every
registered table's list against its own migration in both directions, so the
paste fails the build — and reads as correct to everyone who does not run it.
An invented name cannot be pasted in by accident, and has no migration to drift
from.

**Strategies.** The `MergeStrategy` enum names four: `Lww` (last writer wins,
per field), `GCounter` (a grow-only counter that sums rather than overwrites —
`merchant_memories.occurrence_count` is the one that needs it), `OrSet` (an
observed-remove set, for a `{v, tag}`-shaped collection), and `JsonKeyUnion` (a
JSON object whose KEYS are independent facts, merged key by key). **Lww is the
default and is never written out**: a field entry carries a `'strategy'` key
only where it is one of the other three. An unregistered table or field falls
back to Lww the same way.

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
`notifications.state`, `drift_alerts.state`, `anomaly_alerts.state`,
`system_alerts.created_at` (`useCurrent()`), `recurring_series.state` /
`.cadence` / `.variance_tolerance_percent` / `.next_expected_confidence_low`,
`tax_deduction_categories.status` / `.sort_order`, `accounts.default_currency`,
and every counter on `import_runs`.

`envelope_settings.threshold_percent` is the one that is easy to file here and
does not belong: it is `nullable()` with no default at all, because null means
"use the default threshold", so it stays out for the plainer reason. Its sibling
`overspend_mode` is the column of that table that is `NOT NULL` without a
default, and it is what `_create_required` names there beside `user_id` and
`category_id`.

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

## When a column is really several columns

A JSON column whose keys are independent facts is not one field, whatever the
schema says. Under `Lww` the whole blob merges as a unit, so a device that
writes one key overwrites every key the other device wrote — and because each
writer read-modify-writes the whole map, that is every writer. Absence is
usually not neutral either: a key nobody has written falls back to a default,
so losing a key does not leave that fact untouched, it resets it.

`JsonKeyUnion` is the answer, and it is currently declared on
`transactions.field_provenance`, `counterparties.metadata` and
`users.community_settings`. Two preconditions have to hold before it is safe,
and each one has already bitten:

**1. No writer may remove a key.** A key union cannot express an absence: an
absent key reads to the peer as "this device never held it", so the peer's own
copy survives. `LabelCounterparty` cleared the default-name flag with `unset()`,
which under a key union would have handed the reader's own wording back as a
placeholder. Clear to a **present null** instead — a null value is carried, an
absent key is not.

**2. The op has to carry a map, not the stored JSON text.** A writer that reads
the column back through the query builder — which `WriteUserPreference` and
`OpLogBackfiller::captureUserSettings()` both do deliberately, so a JSON column
travels as its stored text and a cast column as its stored scalar — hands over a
string. `JsonKeyUnionStrategy::decode()` refuses a non-object, so every op on
the column quarantines instead of merging. `OpLogWriter::writeSet()` decodes a
string on any column the registry declares a key union, which covers both
producers and the next one; a value that is not an object is passed through
untouched so the strategy's own refusal is what reports it.

Whole-value `Lww` round-trips that string correctly, which is why the wire shape
only becomes a problem the moment the strategy changes.

The residual is worth knowing: because every writer restates the whole map,
per-key LWW still loses a key when the second writer's restated map is stale for
a key the first device just changed. It is strictly better than losing keys the
device never held, but it is not a full per-key CRDT. A list nested **inside**
the map (`counterparties.metadata.merged_from`) is not protected at all — that
needs an `OrSet` on its own column.

### A derived column is not a mergeable one

Where a column can be computed from other columns on the same row, declaring a
strategy for it does not help: the copy and its sources are separate fields with
separate HLC ticks, so any strategy still lets them disagree. The stored column
is an index hint, and something has to re-derive it. There are two places to do
that, and the choice matters. See [architecture.md](architecture.md) — *One
announcement is not one op*.

**The reader re-derives** when the derivation is cheap and the column is only a
lookup key. Match on the stored value if it keeps the query indexed, then
confirm against the source before answering.
`ScenarioSeriesResolver::existingTemplateScenario()` and
`RecurringSeriesDtoMapper` are the accepted templates.

**The applier re-derives** when the readers are many, or the value is itself a
key other code matches on. `ReplayedRows` names the seam in its own header —
modules keeping derived state hear about a replay through `PeerRowsApplied` —
and a module listener on that event gets three properties that matter:

- it **cannot fail the merge**: `OpLogReplayer` catches a throwing listener and
  the rows are stored either way;
- it **announces nothing**, and must not. The value is derived, so every device
  recomputes the same thing from the same merged row; an op would hand a peer
  back a column it can compute, and loop;
- it runs **after commit**, so it sees the merged row rather than one field of it.

`RederiveFingerprintOnMergedRows` is the template. Guard such a listener three
ways and pin each as a case that still passes with the listener unregistered —
those controls are the only thing separating "re-derives what crossed" from
"rewrites every row a replay touched": skip a row below the current version of
the derivation, which belongs to the sweep that owns that migration; skip a row
already correct; and stay inside the user the replay ran for.

**Do not applier-derive a value whose derivation reads local state.**
`counterparties.slug` looks like the same fix and is not:
`CounterpartySlugResolver::resolveUnique()` walks suffixes against the slugs
*this device* holds, so two devices can derive different values — and because
`CounterpartyResolverService` keys its `firstOrCreate()` on `(user_id, slug)`,
the slug is the row's identity. Deriving it per device trades a merge
disagreement for divergent identities. That one is a product decision.

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

## A foreign key is carrying more than one thing

Three separate mechanisms answer *"which columns of this table name a row in
another one?"* by reading the live foreign keys:

| reader | what it does |
|---|---|
| `CoveredTableOrder::parentColumns()` | feeds `PeerRowAliases::translate()`, which rewrites a peer's id to this device's |
| `ImportSyncCapture::parentIdsFor()` | sends a row's parents beside it |
| `CoveredTableOrder::dependencies()` | orders parents before the rows naming them |

So a column left without a constraint is not translated, not captured and not
ordered — and the reason it was declined is usually about something else
entirely. Both live cases said so in their own migration: *"a counterparty
leaving must not cascade its transaction history away"* and *"`recurring_series`
belongs to another module"*. Good reasons, about deletes and module boundaries,
that also silently turned off three things nobody was weighing.

Declare such a column in `CoveredTableOrder::UNCONSTRAINED_PARENTS` rather than
adding the constraint. `AnIdThatCrossesIsTranslatedOnArrivalArchTest` holds every
`*_id` on every covered table to that question, and names the one still open:
`migration_source_map.beatrax_id` points at whichever table `source_entity_type`
says, so no single target can be declared for it.

The smell is a `unsignedBigInteger('x_id')` beside siblings that use
`foreignId()->constrained()`.

## A gate covers one arrival path, not three

A row arrives three ways — created, set, deleted — and each has its own gate
list. `SplitOverfillGate` stops a split's legs summing past their transaction and
was called from `admissiblePayload()`, which only `applyCreatedRow()` reaches; a
peer op **raising an existing leg** walked past it for as long as it existed.

When you add or audit a gate, name which of the three it sits on and check the
other two by hand. And when you gate a `SET`, remember that a rebalance announces
its *whole* set: the raised leg routinely arrives before the lowered one, so a
verdict read off the stored siblings alone refuses a batch that balances
exactly. Resolve the batch's arriving values first.

Before writing a gate at all, ask whether the row it would refuse is **unwanted
or merely early**. A split's extra leg is unwanted; a lone envelope-move leg is
early in a paged catch-up, and refusing it would break ordinary sync. Where the
answer is "early", what the invariant wants is a detector that reports after a
sync completes, not a refusal.

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
