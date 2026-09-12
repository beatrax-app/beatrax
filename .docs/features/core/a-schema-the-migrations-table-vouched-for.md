# A schema the migrations table vouched for

A row in `migrations` records that a statement once ran. It is not evidence
that the schema still carries what that statement wrote, and nothing in the app
ever compared the two — so a database that drifted out from under its own
migration rows ran indefinitely with no signal, and the migrations that would
have repaired it could never fire again there, because they were recorded as
having already run.

## The install that was found

One desktop install, 245 migrations recorded, measured on a `.backup` snapshot
rather than in place:

| Fact | A build from the migrations | That install |
|------|-----------------------------|--------------|
| Tables whose `sql` still declares `ON DELETE CASCADE` | 0 | **23** (42 keys) |
| `users_receipt_conflict_resolution_check_*` triggers | 2 | **0** |

Both repairs were recorded as run in that same database:
`2026_09_04_000001_let_the_application_decide_what_an_orphan_is` and
`Modules/Categorization/.../2026_05_28_010001_restore_users_receipt_conflict_resolution_triggers`.

Neither migration is at fault, and this page is not a bug report about either.
Both were rehearsed end to end against a copy of a real older database — 205
migrations, 69 cascade-carrying tables, live data — and the 40 pending
migrations applied cleanly, ending at 0 cascades and 2 triggers with
`integrity_check` ok, `foreign_key_check` clean, data intact, and a schema
byte-identical to a fresh install across 1,177 column and foreign-key lines and
246 indexes, triggers and views. The fresh-install path converges on the same
shape.

**The provenance of the drift is not established.** That database has been
through raw-SQL repairs and a backup restore, and carries a latched
`backup_corrupt` alert. What is established is the state, and that is enough:
an installed database can end up in a shape no code path produces, and the
application could not tell.

## Why each half matters

**A cascade is a second writer that announces nothing.** SQLite removes the
child, no `delete_tombstone` is written, the child's `create_row` ops stay live
in `op_log_entries`, and the peer either resurrects the row or quarantines it
forever. That is why all 114 of them were taken off tree-wide — see
[the sync architecture](../sync/architecture.md#a-row-a-parent-owns-is-deleted-by-the-application-not-the-database).

Measured on a copy of that same install: one `DELETE` of a single
`recurring_series` row removed 21 occurrences, `op_log_entries` stayed at 12,810
rows, and 504 `create_row` ops for those 21 now-deleted rows were still live in
the log afterwards — 24 per row, because `create_row` is emitted per field.

**The two `users` triggers are the only database-level guard on that enum
column.** `ApplyReceiptConflictResolution` writes a PHP enum's `->value` and is
safe on its own; the sync applier is a second writer, and it writes field values
arriving from a peer. The mechanism that removes them is recorded in the restore
migration's own comment: a SQLite table rebuild keeps the columns and the
indices and **silently drops every trigger**. It happened once, was repaired,
and happened again with nothing to notice.

## What notices now

`Modules\Core\Public\Support\SchemaShape` asks sqlite_master the two questions
directly, and `SchemaShapeHealthCheck` turns the answer into a label, a severity
and a sentence. `HealthCheckListener` reads it on `ConnectionEstablished`,
beside the WAL and durability probes, and raises one system-wide
`schema_shape_drifted` row — de-duplicated on the hour and on an open row of the
same kind, so a restart storm cannot become an alert storm. The row is
system-wide because a drifted schema is a fact about *this* machine's file; owned,
it would ride the op log to a peer whose schema is fine. `beatrax:doctor` prints
the same three values as a row.

The check is silent where `users` does not exist. That is not drift: a fresh
install opens connections all through its first `migrate`, every one of them
before the table the check asks about is there.

## What repairs it, and why a migration

`database/migrations/2026_09_12_000020_repair_a_schema_the_migrations_table_vouched_for.php`
re-runs both repairs, each guarded on what sqlite_master actually holds rather
than on what `migrations` says. A database already the right shape is untouched;
one carrying either fault is fixed no matter what its migration rows claim.

A migration rather than a repair the health check triggers, for two reasons:

- **It reaches the install without a terminal.** `FirstLaunchBootstrap::runPendingMigrations()`
  runs on *every* desktop launch, from `prepareBeforeWindow()` — before the
  window opens and before the first request. Neither shipped bundle has a
  terminal to run a command in, so a migration is the only repair path a reader
  never has to be told about.
- **It is the one quiet moment.** Stripping the clause means `PRAGMA writable_schema=ON`
  and an `UPDATE` against sqlite_master. That write does not bump the schema
  cookie, so any other connection already attached keeps its cached parse of a
  schema that no longer exists. At launch the migration runs before the sync and
  relay listeners are started; at `ConnectionEstablished` on a running app, four
  processes are attached to the live file.

So the migration closes the drift that is in hand, once. The health check is the
part that stays: whatever produced this state is not known, and if it happens
again the next start says so instead of running on in silence.

`PRAGMA foreign_keys` is read back and restored exactly as found. Leaving
enforcement off outlives the migration on the connection that ran it, and a
`set null` key that silently never fires looks nothing like a migration fault.

## What the guard holds

`Modules/Core/tests/Feature/ASchemaTheMigrationsTableVouchedForTest.php` builds
a database from the migrations alone and reads the invariants off *that*, rather
than off a list written beside them:

- a migrated-from-scratch schema carries no cascade and both guards, so neither
  expectation can rot into naming something nothing creates;
- the trigger bodies `SchemaShape` would restore are compared, whitespace
  normalised, against the ones `sqlite_master` actually holds after that build —
  change the enum's allowed values in a new migration without updating
  `SchemaShape` and this fails;
- the same schema, drifted by hand, is named by the check and then repaired, and
  the repair hands `PRAGMA foreign_keys` back both ways round.

`Modules/Core/tests/Feature/ADriftedSchemaRaisesABannerNoMigrationRowCouldTest.php`
holds the banner: raised for either fault alone, withdrawn by the start that put
the schema back, and silent on a schema that is the shape the migrations
declare — that last one is the positive control, without which "no row" would
mean "nothing was looked at".
