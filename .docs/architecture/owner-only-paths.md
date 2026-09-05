# Owner-only paths

Beatrax keeps a household's whole financial life in files beside the
application: the SQLite ledger, the biometric cold-start key, the relay
credential, the loopback TLS private key, the bank connector secrets, plaintext
backup snapshots, and the `.eml` source documents behind individual
transactions. On a shared desktop account the only thing standing between those
files and a cohabiting operating-system user is the mode on disk.

`Modules\Core\Public\Support\OwnerOnlyPath` is the one place that settles it.

## Why asking is not enough

Three separate things go wrong with the obvious `@chmod($path, 0600);`.

**The answer is thrown away.** `chmod()` returns `false` when it fails —
because the file is owned by another account, because the path vanished,
because the volume refuses it. Discarded, that failure is invisible: the caller
carries on, reports success, and the file stays at whatever the umask left it.
A vault reported as enrolled over a key at `0644` is worse than no vault, since
the lock screen then stops asking for the code that was protecting it.

**The answer can be `true` and still be wrong.** On exFAT, on SMB, on a mapped
Windows share, `chmod()` succeeds and the stored mode is whatever the mount's
`fmask` says. A return-value check cannot see this; reading the mode back can.

**The file was already readable before the chmod ran.** `touch()` and
`fopen($path, 'wb')` create at `0666 & ~umask`, which is `0644` on every
default install. The window between creation and the chmod is small but it is
the window in which a reader gets a descriptor, and a descriptor obtained then
survives the chmod.

## What the class does instead

```php
$ownerOnly->file($path);       // true only if the file is 0600 on disk afterwards
$ownerOnly->directory($path);  // true only if the directory is 0700 on disk afterwards
```

A missing file is opened under `umask(0177)` so it is born `0600` rather than
narrowed to it. A missing directory is created at
`SecretFileMode::DIRECTORY`. Either way the mode is then re-read with
`fileperms()` and compared, and a mismatch is a `false` plus one PSR-3 error
carrying the path, the mode expected, the mode observed, and the exact `chmod`
command that repairs it.

Callers are expected to treat `false` as a refusal, not as a warning:

- `DesktopColdStartVault::enroll()` answers `false`, so the settings screen
  says the device declined to store the key and biometric unlock stays off.
- `RestoreEncryptedBackup` and `EncryptedBackupDownload` throw
  `BackupIoException`, so no plaintext snapshot of the database is produced.
- `LoopbackTlsCertificate` throws rather than write a private key it cannot
  keep private.
- The `->booting()` hook on both Composer roots logs and continues: a ledger
  the user can still open beats an application that will not start, and the log
  line names the file and the command.

## The log is user data too

The list at the top of this page had one omission for a long time, and it was
the least obvious member of it: `storage/logs/laravel-*.log`. Nothing on that
path holds a credential — `RedactSecretsProcessor` takes those out before the
formatter runs — but plenty of it is the household's own content. An upload
that fails to parse is logged with the name of the file, and a bank names a
statement for the account it covers: `ING_NL91ABNA0417164300_2026.csv` is an
IBAN written out in full, in a file at `0644`, kept for fourteen days.

`UploadFilename::sanitise()` looks like it would have stopped that and does
not. It folds away every character outside `[A-Za-z0-9_-]` because the result
is concatenated into a filesystem path — it is **path-traversal defence, not
redaction**, and an IBAN survives it byte for byte. The two halves of the fix
are therefore independent, and either one alone leaves the leak open:

- `filename` and its spellings are in `RedactSecretsProcessor`'s
  `PRIVATE_CONTENT_KEYS`, kept apart from `SECRET_KEYS` so that neither list
  has to describe a filename as a credential. Six Livewire upload paths across
  five modules logged it; a list beats deleting the key from each of them,
  because the seventh is written next month.
- `EnsurePrivateLogFiles` runs in the `->booting()` hook on both Composer
  roots, ahead of the database one, so the error line that hook may itself
  write does not land in a world-readable file on the way past.

### Why the channels alone are not enough

`single` and `daily` now declare `'permission' => SecretFileMode::FILE`, which
Laravel passes to `StreamHandler` and `RotatingFileHandler`. That covers the
files those two channels create, and it is still not sufficient for two
reasons. Monolog chmods **after** `fopen()`, so a new log spends the same
creation window at `0644` this page describes for every other file. And
`LogManager::createEmergencyLogger()` builds its handler from a path and a
level and nothing else, so a `permission` key on the `emergency` channel is
read by nobody — it would look like a decision and do nothing at all. The
`0700` directory is what closes both, along with the files an install that
predates this change already rotated out.

## The mode of a SQLite database is the mode of its WAL

SQLite derives the mode of `-wal` and `-shm` from the database file it opens.
That makes the one decision in the `->booting()` hook — see
[SQLite file pre-creation](sqlite-file-precreation.md) — cover the recently
written pages as well as the committed ones. It also means a ledger that
arrives at `0644` publishes its write-ahead log at `0644` too.

## The guard

`tests/Contracts/ADiscardedChmodIsAPermissionNobodyCheckedArchTest.php`
tokenises every shipping file and fails on a `chmod()` call that opens its own
statement — the shape whose answer has nowhere to go. Three files are pinned:
the class above, which discards the answer deliberately because it reads the
mode back instead, and two sites where a checked call over the same path has
already settled the mode.
