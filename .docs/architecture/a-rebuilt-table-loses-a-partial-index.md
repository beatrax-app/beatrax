# A rebuilt table loses a partial index

SQLite has no `ALTER TABLE ADD CONSTRAINT`, so Laravel adds a foreign key the
only way the engine allows: it creates a new table, copies the rows, drops the
old one, and recreates everything that hung off it. It recreates the indexes
from `PRAGMA index_list` and `PRAGMA index_info`, which report an index's
columns, its name and whether it is unique — **and nothing about its `WHERE`
clause**. A partial index therefore comes back as a plain one, under its own
name, with no error and no warning.

The repo already knew half of this. `2026_06_15_000004_add_note_to_transactions`
carries the comment "SQLite rebuilds the whole table on a column add and
silently drops every trigger with it", and recreates all four
`transactions_*_check_*` triggers by hand. Indexes look like they survive —
they are all still there, under their own names — so nobody checked what they
had become.

## What was lost

`2026_05_12_010005_create_transactions_table` declares:

```sql
CREATE INDEX transactions_uncategorized_idx
    ON transactions(user_id, posted_at) WHERE category_id IS NULL
```

Three days later `2026_05_15_010002_add_pair_transaction_id_to_transactions`
adds a self-referencing foreign key. Migrating a fresh database and reading
`sqlite_master` after each migration shows the predicate present after the
first and gone after the second:

```
AFTER 2026_05_12_010005_create_transactions_table
  -> CREATE INDEX transactions_uncategorized_idx ON transactions(user_id, posted_at) WHERE category_id IS NULL
AFTER 2026_05_15_010002_add_pair_transaction_id_to_transactions
  -> CREATE INDEX "transactions_uncategorized_idx" on "transactions" ("user_id", "posted_at")
```

`transactions_user_id_posted_at_index` already exists on exactly those two
columns, so what the rebuild left behind was a byte-for-byte copy of an index
already on the table — a second B-tree maintained on every write and read by
nothing that the first did not already answer.

`database/schema/sqlite-schema.sql`, the squashed dump every fresh install
loads, was taken a month later and froze the damaged form. Every desktop,
every phone and every `RefreshDatabase` run has had the plain index since.

The four other partial indexes in the schema — `categories_global_slug_uq`,
`community_merchant_mappings_global_pattern_uq`, `pots_active_goal_unique`,
`tax_tags_whole_tx_unique` — kept their predicates, and they appear in that
same dump with their `WHERE` intact. `schema:dump` is not the problem: it
preserves what it is given. Only `transactions` is rebuilt by a later
migration, and only its partial index was lost.

## What it cost

The index exists for one figure: how many transactions the reader has not
categorised. Two surfaces print it, both on the render path —
`Ledger\ThisPeriodAtAGlanceQuery` (the dashboard) and
`Categorization\TriageInbox::render()` (every assignment in the triage inbox,
which is the screen whose whole purpose is to shrink that number).

Measured on a file-backed fixture with the real schema, 10,000 transactions of
which 200 are uncategorised, no `ANALYZE` (nothing in the app runs it, so no
install has `sqlite_stat1`):

| | plan | per call |
| --- | --- | --- |
| plain index (shipped) | `SEARCH transactions USING INDEX transactions_fingerprint_uq (user_id=?)` | 3.155 ms |
| partial index | `SEARCH transactions USING INDEX transactions_uncategorized_idx (user_id=?)` | 0.060 ms |

Without the predicate the planner has no index that selects on
`category_id IS NULL` together with `user_id`, so it seeks every row the user
owns and tests the column one row at a time: the cost of the figure is the
size of the ledger, not the size of the answer. At 40,000 rows it is 12.767 ms
against 0.087 ms, which is the same line continued — linear in total rows on
one side, linear in uncategorised rows on the other.

## The guard

`tests/Feature/APartialIndexIsStillPartialAfterEveryMigrationTest.php` holds
three cases, and the shape matters more than the instance:

1. Every index any migration declares with a `WHERE` clause is read out of the
   migration sources — seven of them today, named explicitly so a regex that
   quietly stops reading fails rather than passing on an empty set.
2. Each of those is partial in the built schema.
3. **No table carries two indexes on the same key.** That is the fingerprint a
   dropped predicate leaves behind: the narrow index becomes a copy of the
   wide one beside it, which is exactly what happened here and exactly what
   nothing noticed for four months.

Case 3 also removed the one other duplicate in the schema:
`migration_import_baseline` carried both a plain and a unique index on
`(migration_source_map_id, field_name)`, the plain one left over from before
`2026_09_11_000001` added the unique. Inserting 30,000 baseline rows — one per
mapped row and field, which a real product migration reaches — took 61.7 ms
with it and 47.7 ms without.

## If you add a partial index

Write the whole `CREATE INDEX … WHERE …` as one string literal in the
migration. The guard reads the migration sources, and it closes up PHP string
concatenation before matching, but a name assembled from a constant is a name
it cannot see.

And if you add a foreign key, rename a column, or drop one from a table that
carries a partial index, recreate the index afterwards — the same way
`add_note_to_transactions` recreates the triggers.
