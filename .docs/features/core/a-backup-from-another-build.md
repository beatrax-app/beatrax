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

**An older backup depended on the shell.** `Desktop`'s `EnsureDatabaseReady`
asks `hasPendingMigrations()` and redirects to the setup screen, so the desktop
is brought forward before the first request serves anything.
`Mobile`'s `MobileEnsureDatabaseReady` asks only whether the schema marker is
raised and whether this is a fresh install — so Android and iOS served every
request against the backup's older schema until the app was relaunched.

What that costs is not abstract. A build predating
`2026_09_04_000001_let_the_application_decide_what_an_orphan_is`, handed a
database from after it, deletes a parent row and silently leaves its children:
that migration stripped `ON DELETE CASCADE` from 23 tables on the premise that
the application deletes them itself, and the older application does not.

## The comparison reads the set already in the backup

The `migrations` table is inside every backup ever taken, and it is already the
authoritative record of the shape. A version marker introduced now would be
absent from all of them and could only judge the ones written afterwards — and
the backups that most need judging are the old ones.

So the comparison is a set difference over migration names:

| What the difference shows | What it means | What the reader is told |
|---|---|---|
| the backup names one this build lacks | the backup is newer | update Beatrax, then restore it again |
| this build names one the backup lacks | the backup is older | restore from a backup this version made |
| neither | the shapes match | nothing; it restores |

The names this build ships are read through Laravel's own `Migrator` — its
registered module paths plus the shared root — rather than listed anywhere, so
a module whose migrations are registered is counted by construction.

**A backup recording no migrations at all is restored.** That is every fixture,
every hand-built database, and any file written before migrations were tracked.
It makes no claim, so there is nothing to check, and refusing it would strand a
file over a question it never answered. `ARestoreOfADatabaseThisBuildCannotRead`
holds that case as the positive control: without it, a comparison that refused
everything would read exactly like one that refuses the right two things.

## What this costs

**A backup taken before an update cannot be restored after one.** Both
directions are refused, which is what makes the newer case safe and what closes
the gap on Android and iOS — and it means a mismatch either way is terminal for
that file.

On mobile that is the common case rather than a corner: `/mobile/restore` is
reached from a fresh install, a fresh install is whatever version the store is
serving, and the reader cannot choose an older one. A reader who updated between
backup and restore has no in-app route home.

The cost is accepted against the alternative on the same path — a restore that
reports success and leaves the reader's ledger being read by code that does not
match it — and it is written down here rather than left to be discovered.

## The refusal reaches a reader

A restore that refuses has to refuse *to somebody*. Both screens render
`RestoreRefusal::forThrowable()`'s sentence, and `db:restore` prints it with the
count of unmatched migrations beside it, which is the operator's half and never
the reader's.

The refusal is raised **before** the pre-restore snapshot, so a refused restore
leaves no snapshot and no trace: nothing has been touched, which is what the
copy says.

It also closes a hole on the path it removes. The pre-restore snapshot path is
the reader's only record of their undo, it is flashed to the session, and
`Auth`'s `LoginPage` is its only reader — so on the older-backup path, where the
migration gate redirected past `/login` to the setup screen, nothing ever
rendered it. That path no longer exists.

## See also

- [F4 backup, restore and recovery](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f4-backup-restore.md)
  — the ordering this sits inside, and the requirement that a refusal names its
  direction.
- [One export action](one-export-action.md) — what the archive carries.
- [A credential a restore cannot carry](../email-scan/a-credential-a-restore-cannot-carry.md)
  — the other thing a backup arrives without.
