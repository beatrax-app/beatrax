# One export action, and what has to be in it

A backup is the database. The reader's source documents — the statements
they imported, the mail the scanner pulled in, the receipts they dropped
into the watched folder — are files beside it, and no snapshot of the
database contains them. So "take a backup" is not "take your data with
you", and a reader who believed it was would find the documents missing
on the machine they moved to.

`ExportEverythingArchive` is the one action that puts both halves in one
file.

## What it builds

A `.zip` with two kinds of entry:

| Entry | What it is |
|---|---|
| `beatrax-backup-<stamp>.sqlite.enc` | A `VACUUM INTO` snapshot of the database, with the encryption keyring packed into it, encrypted under the reader's passphrase. |
| `artefacts/<location>/…` | Every file under each artefact directory, at its original relative path. |

The `<location>` segment is the key from `UserDataLocations`, so the three
artefact trees stay apart inside the archive:
`artefacts/artefacts_imports/`, `artefacts/artefacts_mail/`,
`artefacts/artefacts_drop/`.

## Why the halves are protected differently

The database goes in encrypted and the documents go in as they are, which
looks inconsistent until you ask what each one is.

The database is the derived whole: every transaction, every counterparty,
every note, joined up and searchable, plus — for a reader with encryption
at rest — the keyring that opens the sealed columns. That is the artefact
worth a passphrase, and `BackupKeyMaterial::packInto()` runs before the
encrypt for exactly that reason: a snapshot that left without the keyring
would be a copy of ciphertext nobody could read, including its owner.

The documents are the reader's own files, already sitting unencrypted in
the folders this archive copies from. Encrypting them into a format only
Beatrax opens would take something the reader can read today and hand it
back in a form they cannot. An export that does that is not portability.

The page says both things plainly rather than leaving the reader to infer
them (`core::help.export_passphrase_hint`).

## The staging discipline

Identical to `EncryptedBackupDownload`, and for the same reasons:

- Staging is `UserDataPathService::appPath('tmp-backups')`, forced to 0700
  through `OwnerOnlyPath::directory()` — never `sys_get_temp_dir()`, which
  is world-traversable at 1777.
- `VACUUM INTO` cannot run inside a transaction and refuses an existing
  target, so the path carries eight random hex characters.
- The plaintext snapshot is unlinked in a `finally`, so it never outlives
  the encryption step even when the encryptor throws.
- The finished archive is made owner-only before it is returned; a mode
  that cannot be settled is a refusal rather than a download.

## An artefact directory that is not there

A reader who has only ever connected a bank and never imported a file has
no `imports` directory at all. `addDirectory()` returns on a missing path
rather than refusing, because an export that failed over a folder the
reader never created would be an export most readers could not take. The
archive is then the backup entry alone, which is correct.

## Where it is offered

On `/help/data-locations`, mounted as `core.export-everything-download`,
to every signed-in reader. It used to be a `disabled` button behind
`users.is_developer`, titled with a promise about a Dev Mode route that
was never written — a data-portability control gated on a developer flag,
which is the wrong gate for it whether or not the route had shipped.

## Related

- [Durable user data paths](durable-user-data-paths.md) — which root each
  path resolves against, and why.
- [Sensitive columns at rest](../sync/sensitive-columns-at-rest.md#a-backup-of-the-database-alone-is-a-backup-of-ciphertext)
  — why the keyring rides inside the snapshot.
