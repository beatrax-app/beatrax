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

## The phone has no `ext-zip`

The NativePHP mobile PHP build ships without `ext-zip` — `#undef HAVE_ZIP` on
both iOS and Android — so `new ZipArchive` there is a bare `Error`, not a
catchable failure. `Migration` already met this on the read side; see
[reading a ZIP where there is no `ext-zip`](../migration/reading-a-zip-without-ext-zip.md).
Writing needed the same seam in the other direction, and it matters more here:
the phone can be the only device a household owns, so an export it cannot take
is not a degraded export, it is none.

`ArchiveWriterFactory` is the one place that asks. It answers with an
`ArchiveWriter`:

- `ZipArchiveWriter` — the extension, wherever it exists.
- `NativeZipWriter` — a writer in PHP for where it does not. It streams each
  entry through `deflate_init(ZLIB_ENCODING_RAW)`/`deflate_add()` and hashes it
  through `hash_init('crc32b')`, both `ext-zlib` and core, both present on the
  phone. Sizes are unknown until an entry is written, so the local header goes
  down with placeholders and is patched by seeking back — a data descriptor
  would work too, but a seekable output file makes the simpler shape available.

`NativeZipWriter` implements no ZIP64, so any entry or offset that would exceed
a four-byte field, and any archive over `0xFFFF` entries, is refused with a
`BackupIoException` naming the limit rather than written as an archive that
opens and then reads short.

The proof that it is a real ZIP is not the shape of the code: the test writes
one and opens it with `ext-zip`, asserting every entry's bytes match the source,
and verifies it again with the system `unzip -t`.

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
