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

The backup entry is written **first**, before any source document. That is what
lets a restore find it at the head of the archive without walking one.

## The boundary is a list, not a sweep

`UserDataLocations` states both halves by name — `EXPORTED` for what the archive
carries, `WITHHELD` for what it does not — and a test asserts the two together
are exactly the inventory. A location added to `all()` and to neither list fails
that test rather than being swept in by whichever branch reached it first.

Withholding is the half that needed spelling out. `secrets/` is one directory
from `private/imports/`, and it holds the open-banking connector credentials
([F7-R7](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f7-data-locations.md)):
a sweep of the storage root would put them inside a file the reader then mails
to themselves. The same reasoning bounds the packagers' copy, and it is written
down there because inference is what failed — a bundle once shipped `storage/app`
whole, carrying the signing key that made it. An archive is a copy with a
boundary, so it is bounded the same way.

Backups and logs are withheld for the plainer reason: the archive carries its
own snapshot, and a log file is not the reader's history. So are the key
material, the export's own staging directory, the extract directories the
migration importer unpacks into, and the loopback TLS certificate — the first
rides inside the snapshot already, and the rest are this install's working
files rather than the reader's documents.

The proof is not that the archive is non-empty. The test plants a credential
file, a keyring, an earlier backup and a leftover working artefact, and asserts
the archive holds the one statement it was given and none of the four.

## The third reader, which was not one

The inventory is described here as having three readers — the page, the
deletion, and the export. For a while it had two. `UserScopedFilePurge` carried
three lists of its own, and the disagreement between them was not a spelling:
`private/imports/` is in `all()` and the purge named nothing inside it, so every
statement a reader had ever imported outlived their account. Four more trees the
purge removed — the key material, the export staging, the migration extracts and
the TLS certificate — were in the purge and not in the inventory, so the page
that tells a reader how to remove every trace by hand did not name them. Two of
those four hold the keys to the reader's own sealed columns and this device's
signing identity.

So the deletion now reads the inventory, and the inventory answers the question
the deletion actually asks. That question is not the export's. The export wants
whole trees; a deletion wants the files **one account** owns, because a
household shares one install and most deletions are not the last one on the
device. `all()` is therefore partitioned three ways rather than iterated:

| Partition | What it answers |
|---|---|
| `accountScoped($userId)` | the paths this account owns inside each location — what a deletion removes whoever else is still here |
| `withoutAnAccountScope()` | locations with nothing one account owns, declined by name: a backup and a staged export are each the whole database, an extract directory is named for its run, the certificate is one per device |
| `notRemovedByAFilePurge()` | the database, whose rows go inside the deletion's own transaction and whose file the next account onboards into; and the log, which carries the residue report the deletion is still writing |

A test asserts those three together are exactly `all()`, the same shape the
export's two halves are held to. It deliberately does **not** accept the
device-wide sweep as an answer: that only runs when the last account leaves, so
a location reachable through it alone survives every deletion on a shared
device, which is precisely how the imported statements were lost. `Auth`
reaches all of it through `Core\Public\Services\UserDataPurgePlan`, because
the inventory is Core's `Internal\` and a second copy in the other module is
the shape this whole section is about.

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
- The finished archive is made owner-only before it is handed over; a mode
  that cannot be settled is a refusal rather than a download.

## An artefact directory that is not there

A reader who has only ever connected a bank and never imported a file has
no `imports` directory at all. `addDirectory()` returns on a missing path
rather than refusing, because an export that failed over a folder the
reader never created would be an export most readers could not take. The
archive is then the backup entry alone, which is correct.

## Taking the archive back in

An export the application cannot read back is a file it hands the reader and
then refuses. That is what shipped: every restore surface took a bare `.enc`,
its file picker was `accept=".enc"` — so the archive could not even be chosen —
and a reader who forced one through was told

> This file is not a Beatrax encrypted backup … Pick the `.enc` file the app
> wrote when you made the backup.

about a file the app wrote, naming a file it never wrote for them. The phone is
where that bites: `/mobile/restore` is the whole route home from a wipe, and the
build there has no `ext-zip` to unpack the archive with either.

`ExportArchiveBackup` closes it, in `RestoreEncryptedBackup` rather than in the
two screens, so `EncryptedBackupRestore`, `MobileRestoreFromBackup` and
`db:restore` all get it from one seam.

- `isArchive()` reads four bytes. `PK\x03\x04` is an archive; anything else goes
  to the encryptor unchanged, so the bare `.enc` path is untouched.
- `liftBackupInto()` reads the local file header at byte zero — name, method,
  compressed and uncompressed size — and streams that one entry into the same
  0700 `tmp-restore` directory the decrypt already uses, unlinked in a `finally`.

**Only the backup comes out.** The source documents beside it are the reader's
own files and are already on their machine; a restore that unpacked them would
be writing files nobody asked it to write, at paths the archive rather than this
application chose.

This is not a general ZIP reader and must not become one. It reads the head of
an archive **this application wrote**, and it verifies rather than assumes:
the entry name has to match `beatrax-backup-*.sqlite.enc`, a trailing data
descriptor is refused (neither writer here emits one), and a method other than
stored or deflated is refused by number. Somebody else's export opens here too —
a YNAB `.zip` gets *the archive holds no Beatrax backup*, not a mis-parse
reported as a damaged database. `Migration`'s `ArchiveReader` seam is the
general reader, and it stays where it is: it lives behind that module's
`Internal\` namespace, it is shaped around that module's error vocabulary, and
40 lines that read a header this module writes is not worth moving it.

## How the archive leaves the process

Not through Livewire. A Livewire component that returns a
`BinaryFileResponse` does not stream it: `SupportFileDownloads` runs
`ob_start()`, calls `sendContent()`, and `base64_encode()`s the buffer, so the
whole file is resident at about 2.33x its own size before it is copied again
into the JSON payload and again into a JS string.

That is affordable on a desktop and not on a phone. Measured on an iPhone 12
mini through `/dev/system`: `memory_limit` is **128M**, and a page render
already peaks at **99 MB**. The remaining headroom caps "export everything" at
single-digit megabytes — on the device whose owner is least likely to have
another copy of their data.

So the component stages the finished file and redirects to a plain
authenticated `GET`, which answers with a streamed
`BinaryFileResponse->deleteFileAfterSend()`. Nothing but the socket buffer is
ever resident.

`StagedExportHandover` is the seam. A claim is a 32-hex token naming
`['path', 'name', 'user']` in the session, and four properties make it safe to
put in a URL:

- **Owner-scoped.** The claim records the account that staged it, and `claim()`
  refuses a token whose owner is not the account asking. Session scope alone is
  not enough on a shared household install.
- **Containment-checked.** A claim is only honoured when its realpath is inside
  the staging directory, so a claim cannot name a file this application did not
  write.
- **Single-use, but only on success.** The token is unset when the download is
  granted, never when it is refused — otherwise a partner's probe would burn
  the owner's one download. The unset is the tidy-up and not the guard: nothing
  blocks two requests on one session, so both load the claims before either
  writes them back, and each unsets a token the other is still holding. The
  spend is an atomic lock on the token, taken only once the claim is granted,
  and never released — acquiring it *is* spending it. It is held for the hour
  the staging sweep leaves the file on disk.
- **Self-pruning.** Staging drops every `beatrax-*` file in the directory older
  than an hour before it adds a claim. Not just the `.zip` it hands over: an
  abandoned `.sqlite.enc` is the same whole database, and a plaintext `.sqlite`
  snapshot orphaned by a crashed encryption step is worse than either.

The route carries no `where()` constraint on the token segment. The claim
lookup is the only thing that can say whether a token names anything, and a
routing constraint would have made the route invisible to `CrossUserIsolationTest`,
which recognises a parameterised route by requesting it with a `1`.

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
- [F4 backup, restore and recovery](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f4-backup-restore.md)
  — the ordering the lifted backup then goes through unchanged.
