# A predicate on a column that is not there

SQLite reads a **double-quoted name that matches no column as a string
literal** rather than raising. Laravel quotes every identifier, so a query
naming a column the table does not carry is not an error here — it is a clause
comparing a constant, and it answers confidently and wrongly.

```
sqlite> select "self_retired_at", typeof("self_retired_at") from device_registry limit 1;
self_retired_at|text
```

The two directions are not equally survivable:

| written | reaches SQLite as | while the column is absent |
|---|---|---|
| `whereNotNull('c')` | `"c" is not null` | **always true** — the clause stops narrowing, and every row qualifies |
| `whereNull('c')` | `"c" is null` | **always false** — nothing qualifies, and a set comes back empty |
| `where('c', $v)` | `"c" = ?` | false unless `$v` is the column's own name |
| `first(['c'])` | `select "c"` | the **string** `'c'`, so `!== null` is true |

## This is a property of the database, not of the source

The column is declared. `device_registry.self_retired_at` is added by
`2026_09_12_000040_a_retired_self_row_verifies_history_and_is_not_a_device`, and
on a fully migrated database every query above behaves. What goes wrong is a
**database the migration has not run on yet**, and the window between a build
landing and its migration running is an ordinary state — the desktop app already
running started before it.

So no amount of reading the source finds this. A guard whose rule is "every
column literal matches a column the migrations declare" passes every site
below. The question is what the process is pointed at.

**Measuring it needs the right copy.** A SQLite file in WAL mode carries recent
writes — schema changes included — in its `-wal` sidecar until a checkpoint. So
`cp nativephp.sqlite` alone reproduces the defect and
`cp nativephp.sqlite nativephp.sqlite-wal nativephp.sqlite-shm` followed by
`PRAGMA journal_mode=delete` does not. The plain copy is what a naive backup and
a pre-checkpoint snapshot give you. Say which one you have before reporting
either result.

## Three causes wear this symptom, and only one is this page

Two readers can both misbehave for reasons that need different fixes:

1. **A declared column the migration has not applied here.** This page. No
   static rule sees it; the reader has to ask the database.
2. **A type mismatch, not an absence.** `where('failed_slices', '<', 3)` on a
   column that is TEXT compares text to an integer, and SQLite's type ordering
   puts every text value above every integer. The column exists and the
   comparison is still wrong.
3. **A literal that is not a column at all** — the typo. That one *is* static,
   and it is the only one an arch rule can hold.

Grouping them by the symptom — "a predicate that did not apply" — is what led to
a guard being proposed for a defect it would have passed.

## What to do

Ask the database before you act on the clause, and refuse rather than degrade:

```php
$missing = SchemaShape::missingColumns($connection, 'device_registry', ['confirmed_at', 'self_retired_at']);

if ($missing !== []) {
    throw ColumnNotDeclaredException::on('device_registry', $missing);
}
```

`SchemaShape::missingColumns()` reads `pragma table_info`, which is the one
reader the double-quote rule cannot fool. Refusing beats degrading: a reader
that drops the clause and carries on still produces an answer, and nothing in
that answer says it was computed without the clause.

**An empty set is the dangerous shape.** A list built by a query that silently
matched nothing is not distinguishable from a list that is genuinely empty, and
a filter built from an empty list frequently *widens* rather than narrows —
`whereNotIn('c', [])` compiles to `1 = 1`. That is how one of these turned into
an unscoped `delete()`.

## Related

- [Invariants from shipped failures](invariants-from-shipped-failures.md) — including
  the rule that mandated `self_retired_at` into eight query sites without
  checking any of them
- [A check another writer can invalidate](a-check-another-writer-can-invalidate.md)
- [Rows the log holds and the table does not](../features/sync/architecture.md#a-predicate-on-a-column-the-migration-has-not-added)
  — the health check this was found through
