# A backup from another build

A backup carries a database, and a database has a shape the code around it
expects. Restoring one whose shape does not match the build reading it produced
no error and no refusal: the swap succeeded, `PRAGMA integrity_check` passed,
the command printed success, and the application then ran against a schema its
own code did not match.

`BackupSchemaGeneration` is what looks, before anything is touched.

## The two directions are not symmetric

**A newer backup is unrecoverable, and was undetectable.** Migrations only move
forward, so there is no path from that database to a shape this build reads.
Nothing saw it either: `FirstLaunchBootstrap::hasPendingMigrations()` iterates
the migrations the build *has* and asks whether each was recorded as run — and a
newer database answers yes to every one of them. The reverse question, whether
the database names a migration the build has never heard of, was asked nowhere.

**An older backup has a path forward, and it is the one every upgrade already
takes.** The migrations between the two builds are on disk; what was missing was
anywhere to run them. Restoring one used to depend on the shell instead:
`Desktop`'s `EnsureDatabaseReady` asks `hasPendingMigrations()` and redirects to
the setup screen, so the desktop was brought forward before the first request
served anything. `Mobile`'s `MobileEnsureDatabaseReady` asks only whether the
schema marker is raised and whether this is a fresh install — so Android and iOS
served every request against the backup's older schema until the app was
relaunched.

What that costs is not abstract. A build predating
`2026_09_04_000001_let_the_application_decide_what_an_orphan_is`, handed a
database from after it, deletes a parent row and silently leaves its children:
that migration stripped `ON DELETE CASCADE` from 23 tables on the premise that
the application deletes them itself, and the older application does not.

So the older direction is brought forward by the restore itself, on both
shells, and the newer one is refused.

## The comparison reads the set already in the backup

The `migrations` table is inside every backup ever taken, and it is already the
authoritative record of the shape. A version marker introduced now would be
absent from all of them and could only judge the ones written afterwards — and
the backups that most need judging are the old ones.

So the comparison is a set difference over migration names:

| What the difference shows | What it means | What happens |
|---|---|---|
| the backup names one this build lacks | the backup is newer | refused: update Beatrax, then restore it again |
| this build names one the backup lacks | the backup is older | those migrations are run on the staged copy, then it is restored |
| neither | the shapes match | nothing runs; it restores |

The names this build ships are read through Laravel's own `Migrator` — its
registered module paths plus the shared root — rather than listed anywhere, so
a module whose migrations are registered is counted by construction.

**This depends on the squashed dump keeping its files.** `schema:dump --prune`
deletes the migration files the dump covers, and the moment it does, all 104 of
them stop being migrations this build has while remaining migrations every
backup records — turning every backup ever taken into one from a newer build.
Nothing about the comparison would look wrong; it would simply refuse
everything. `ABackupFromAnOlderBuildIsBroughtForwardTest` pins the precondition
directly, by reading the names out of `database/schema/sqlite-schema.sql` and
requiring a file for each.

**A backup recording no migrations at all is restored, and nothing is run.**
That is every fixture, every hand-built database, and any file written before
migrations were tracked. It makes no claim, so there is nothing to compare, and
running this build's whole set over a shape nothing knows would be a worse
answer than leaving it alone. The same test holds that case as the positive
control: without it, a comparison that refused everything would read exactly
like one that refuses the right thing.

## The forward run happens on a copy, and only ever on a copy

**SQLite runs no schema transaction.** `Grammar::$transactions` is `false` and
`SQLiteGrammar` does not override it, so `Migrator::runMigration()` never wraps
a migration in one. A run that fails part way leaves everything it applied
behind, and the failing migration is not recorded in `migrations` — so re-running
it meets its own half-finished work. This is not theoretical: one bug in that
stretch reached every new install on 2026-08-29, the run died on migration
eighteen of two hundred and nine, and the app opened on thirteen tables of a
hundred and two. Reinstall was the only exit.

There is therefore exactly one safe place to run migrations during a restore: a
file that can be thrown away. `bringUpToDate()` takes a **staged** path and runs
against a `_restore_forward` connection built from the live connection's own
settings with the database path swapped, so the migrations meet the same
foreign-key, journal and locking behaviour they would meet on a real run. It is
held to the same standard as `LiveDatabaseTransplant`: **a failure refuses with
the live database unopened**.

Both paths hand it a copy, and neither has a branch that does not:

- `RestoreEncryptedBackup` runs it on the file the decryption wrote into
  `RestoreStagingArea`, which is a copy by construction.
- `db:restore` copies the operator's file into the staging area **first, always**
  — not only when there is something to run. Lifting the keyring out of a backup
  and migrating it forward both rewrite it, and a guarantee that holds while a
  predicate and the code it guards agree is not a guarantee. The file named on
  the command line is never written to.

The copy is not free. A restore already writes two full-sized files — the
`VACUUM INTO` pre-restore snapshot and the pages the transplant writes into the
live database — and this makes it three.

## The ordering, and what a refusal leaves behind

The forward run comes **before** the pre-restore snapshot.

A refused restore leaves no snapshot, because it leaves nothing at all: the
live database has not been opened, no file has been written to the backups
directory, and the staged copy — including the half-built schema a failed run
left on it — is discarded in a `finally`. That is what the copy tells the reader
and it is true of both refusals.

A restore that migrates and then swaps is **not** a refusal, and takes its
snapshot like any other. The reader's undo is the database that was there
before, which is exactly as recoverable as it is for a restore that ran no
migrations at all.

## Right on both shells

The two platforms execute different migration sets, and only one of them ever
loads a schema dump. `artisan migrate` loads `database/schema/sqlite-schema.sql`
and starts after the migrations that dump already recorded;
`MobileFirstLaunchBootstrap` drives `Migrator::run()` directly, and the
`Migrator` never loads a dump at all.

The forward run calls `Migrator::run()`, so **no dump is loaded on either
shell** — which is the only correct answer here. A staged backup is already a
populated database; loading a squashed schema over it would be the destructive
case, not the safe one. It is the phone's machinery on the desktop's path, and
the path set is spelled the way `MobileFirstLaunchBootstrap` spells it:
`Migrator::paths()` plus the shared root the framework only adds inside its own
migrate command.

`TheMigrationsOnlyAPhoneEverRunsTest` is what keeps the two sets equal, and it
is the reason a forward run on the desktop can be trusted to produce the same
schema the phone would reach by replaying everything.

## What this costs

**Restoring an older backup changes the shape of the data, not only its
contents.** It is a schema-changing operation, and it is described as one: both
restore screens say so before the reader starts, in
`core::backup.restore.updates_an_older_backup`, and `db:restore` says it in the
confirmation prompt. Afterwards there is nowhere to say it — a completed restore
signs the reader out onto `/login`.

**A backup from a newer build is still terminal for that file.** On mobile that
remains the sharper edge: `/mobile/restore` is reached from a fresh install,
a fresh install is whatever version the store is serving, and the reader cannot
choose an older one. What has changed is that this is now the only direction
with no route home, rather than both of them.

**The migrations have to be re-runnable against a real older database.** They
already do — that is what every in-place upgrade asks of them — but a restore
now asks it of a database that has been sitting in a file for months, which is
the same requirement with a longer gap.

## The refusal reaches a reader

A restore that refuses has to refuse *to somebody*. Both screens render
`RestoreRefusal::forThrowable()`'s sentence, and `db:restore` prints it with a
count beside it, which is the operator's half and never the reader's.

The two refusals are told apart, because one of them is worth trying again and
the other is only worth updating Beatrax for:

| Raised | Reader is told | Count beside it |
|---|---|---|
| `BackupFromANewerBuildException` | update Beatrax, then restore it again | migrations this build has never seen |
| `BackupCouldNotBeBroughtUpToDateException` | try again; the log records which step stopped it | migrations left unrun |

It also closes a hole on the path it replaces. The pre-restore snapshot path is
the reader's only record of their undo, it is flashed to the session, and
`Auth`'s `LoginPage` is its only reader — so on the old older-backup path, where
the migration gate redirected past `/login` to the setup screen, nothing ever
rendered it. An older backup no longer takes that path.

## See also

- [F4 backup, restore and recovery](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f4-backup-restore.md)
  — the ordering this sits inside, and the requirement that a refusal names what
  it refuses.
- [The migrations only a phone ever runs](../mobile/architecture.md#the-migrations-only-a-phone-ever-runs)
  — why the two shells build the same schema by different routes.
- [One export action](one-export-action.md) — what the archive carries.
- [A credential a restore cannot carry](../email-scan/a-credential-a-restore-cannot-carry.md)
  — the other thing a backup arrives without.
