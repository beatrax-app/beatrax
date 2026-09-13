# A predicate on a column that is not there

SQLite reads a **double-quoted name that matches no column as a string
literal** rather than raising. Laravel quotes every identifier, so a query
naming a column the table does not carry is not an error here — it is a clause
comparing a constant, and it answers confidently and wrongly.

```
sqlite> select "self_retired_at", typeof("self_retired_at") from device_registry limit 1;
self_retired_at|text
```

The directions are not equally survivable, and only the last one is loud:

| written | reaches SQLite as | while the column is absent |
|---|---|---|
| `whereNotNull('c')` | `"c" is not null` | **always true** — the clause stops narrowing, and every row qualifies |
| `whereNull('c')` | `"c" is null` | **always false** — nothing qualifies, and a set comes back empty |
| `where('c', $v)` | `"c" = ?` | false unless `$v` is the column's own name |
| `first(['c'])` | `select "c"` | the **string** `'c'`, so `!== null` is true |
| `orderBy('c')` | `order by "c"` | one constant for every row, so the rows come back unordered |
| `whereNull('t.c')` | `"t"."c" is null` | **raises `no such column: t.c`** |

**Naming the table is the cheapest mitigation in the tree, and for a long time
this page did not say so.** A qualified name is resolved against the table it
names rather than falling back to a string literal, so the clause that was
silently true or silently false becomes an error with the column in it. It
costs one token, it needs no connection in hand, and it works where asking does
not — inside a closure handed to `where()`, in a static helper that is given a
builder somebody else opened, on a screen a refusal must not reach.

Measured against `sqlite3` directly, on a table with no `gone` column:

```
sqlite> select count(*) from t where "gone" is null;            -- 0, silently
sqlite> select count(*) from t where "t"."gone" is null;
Error: in prepare, no such column: t.gone
```

The qualified form is accepted in the `WHERE` clause of an `UPDATE` and a
`DELETE` too, and raises there just the same, so a predicate above a write does
not have to be left bare. Two things it does **not** change: `select "t"."c"`
still names the result column `c`, and an alias replaces the table — a builder
opened as `table('x as c')` must be qualified `c.col`, because `x.col` raises
whether or not the column is there.

## Which columns this is actually about

A column declared in the original `create` cannot be absent while its table is
there, and an absent **table** raises. So the set at risk is exactly the columns
a migration added to a table that already existed — the `Schema::table()`
half, never the `Schema::create()` half. Measured at 40 tables and 126 columns.

That line is what
[`APredicateOnAColumnAMigrationAddedNamesItsTableArchTest`](../../tests/Contracts/APredicateOnAColumnAMigrationAddedNamesItsTableArchTest.php)
derives its subject from, rather than a list somebody has to remember to add to:
every predicate over one of those columns either names its table or asks the
schema. The guard holds the **predicate** half only. A projection is silent too
and is not mandated, because qualifying one is not free — the fix there is to
ask.

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

What a static rule *can* hold is the reader's posture rather than the schema:
whether a predicate over a column that may not have landed here is written so
that its absence is loud. That is the rule above, and it is why the guard is
about qualification and asking rather than about the column existing.

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
