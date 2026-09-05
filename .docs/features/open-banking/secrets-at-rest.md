# Open banking secrets at rest

The Enable Banking connector holds three things that are individually enough to
impersonate the user against their bank: the RSA private key the JWT signer uses,
the aggregator `application_id`, and the live `session_id` a consent grants. They
have to survive an app restart, so they have to be written down somewhere.

The obvious place — a column on `open_banking_connections` — is the wrong one.
The app's own backup feature copies the whole SQLite database, and the desktop
bundle syncs it; a secret in a column is a secret in every backup the user ever
takes, on every device the database reaches. So the credentials live outside the
database entirely, in JSON files, and every layer below exists to make those
files uninteresting to anyone who obtains a copy of one.

`Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository` is the only
class in the module permitted to build that path. An arch test
(`OpenBankingSecretsFileGuardTest`) enforces it, and a second guard asserts that
no file which references `DatabaseManager` also references a raw credential field
name — which is why callers go through
`EnableBankingHttpClient::sessionIdFrom()` rather than indexing a response array
themselves. The repository owns the shape and the keying; the file mechanics —
the two encryption layers, the permissions and the atomic write — are
`OpenBankingSecretsFile`, which is handed an absolute path and knows nothing
about readers or banks.

## Two axes, because the store has two

A secret here is addressed by **who** and by **which bank**. Both were missing,
and they were one gap rather than two: they are two axes of the same file, and
closing them separately would have meant migrating it twice.

One file per reader:

```
storage/app/secrets/open-banking/<userId>.json
```

resolved through `UserDataPathService::appPath()` so it follows
`NATIVEPHP_STORAGE_PATH` into the desktop bundle's own data directory.

Inside it, the application half the reader registered once, and one record per
bank hanging off it:

```json
{
  "application_id": "…",
  "private_key_pem": "…",
  "connections": {
    "ASNBNL21": { "session_id": "…", "consent_expires_at": "…", "bank_sca_host": "…" },
    "SNSBNL21": { "session_id": "…", "consent_expires_at": "…", "bank_sca_host": "…" }
  }
}
```

The application half is per reader because the reader registers with the
aggregator themselves; the connection records are per bank because a PSD2
consent is. Two banks share one registration and hold two sessions.

The directory is created `0700` and each file `0600`, from
`SecretFileMode::DIRECTORY` and `SecretFileMode::FILE` — the one place the
on-disk permissions of every secret-blob store in the app are decided, so
this store cannot drift a digit away from the `.eml` stores beside it.

## Reading somebody else's is unaddressable, not discouraged

Every public method on the repository takes `int $userId` as its first
parameter. There is no accessor that returns "the" secret, so a caller cannot
reach a reader's credentials without naming that reader — and a fetch names a
bank as well:

| Method | Answers |
|--------|---------|
| `hasApplication($userId)` | is this reader's registration finished |
| `saveApplication($userId, $applicationId, $privateKeyPem)` | the wizard's two writes |
| `rememberScaHost($userId, $institutionId, $host)` | merged into that bank's record only |
| `rememberSession($userId, $institutionId, $sessionId, $expiresAt)` | the completed consent |
| `load($userId, ?$institutionId)` | the application half, plus that bank's record when named |
| `loadOrThrow($userId, $institutionId)` | the same, refusing when the bank is not linked |
| `connectedInstitutions($userId)` | which banks this reader holds a session for |
| `clear($userId)` | this reader's file, and no other |

`legacyInstitutionId()` is the one exception, and deliberately so: it reads the
pre-keying file, which by construction names no reader, and returns an
institution id rather than any credential material.

The single exemption is pinned by reflection in
`OneReadersConnectorSecretIsUnreachableFromAnothersTest`, so a reader-less
accessor added later fails the build rather than passing review.

The runtime used to log a warning when a second account existed and carry on
writing the one global file. There is nothing left to warn about: the write goes
to the named reader's own file, and a reader who has connected nothing gets the
same answer as a reader who does not exist.

## Two encryption layers, applied inner to outer

A write encodes the payload as pretty-printed JSON and then wraps it twice:

1. **The Laravel encrypter (`APP_KEY`)**, `encrypt($json, serialize: false)`.
2. **`SecretShield`**, `protect($ciphertext)`.

A read undoes them outer to inner: `reveal()` first, then `decrypt()`.

Both layers exist because `SecretShield` is not always real. On the desktop
bundle it binds to an OS-keychain-backed implementation and the bytes on disk are
`safeStorage` ciphertext. Everywhere else — web, mobile, docker, the test suite —
it binds to `PassthroughSecretShield`, which is the identity function. With only
the shield, the private key would sit on disk as plain JSON on every one of those
targets. The `APP_KEY` layer is what makes the file ciphertext regardless of which
shield is bound; the shield is what binds the desktop copy to the machine so that
a stolen `.env` alone does not open it.

### The legacy plaintext read path

Files written before the `APP_KEY` layer existed hold shielded-but-unencrypted
JSON. `decrypt()` raises `DecryptException` on those bytes.
`OpenBankingSecretsFile::decryptAtRest()` catches it and returns the revealed
bytes as-is, so an existing connection keeps working across the upgrade; the next
write re-persists it through both layers. The catch is deliberately narrow — a
`DecryptException` is the only failure it swallows.

## The migration out of the installation-wide store

The store shipped as one file, `storage/app/secrets/open-banking.json`, global to
the installation and holding exactly one live session. Real installs have one.
A migration dated after the schema-dump cutoff adopts it, and the only hard
question is whose it is.

It is **derived, never guessed**:

1. If the stored record names an institution, the owner is the `user_id` of the
   `open_banking_connections` row carrying that institution. That row is what
   says whose session this was.
2. With no institution and exactly one account on the installation, the
   application half goes to that account. There is no consent at stake.
3. Otherwise the file is left exactly where it is. Nothing is guessed onto a
   reader, and nothing is deleted.

The keyed file is written first and the old one removed only once it is on disk,
so an interrupted migration leaves the reader connected rather than holding
neither copy. An unreadable store is left in place and logged — the settings
screen already answers that state on screen, and a migration that deleted it
would turn a repairable file into a lost one.

**No shape forces a re-authorisation.** A reader with a live bank crosses the
upgrade still connected: `AnInstallationWideStoreIsAdoptedByItsOwnerTest` proves
it by running the scheduled sync afterwards and asserting the adopted session is
what reached the aggregator. Case 3 loses no consent either, because a store with
no institution never had one.

Because the migration is inert on an empty database — no rows, no accounts, no
owner — it is a no-op in every test that boots a fresh schema.

## The atomic write

`OpenBankingSecretsFile::write()` never writes the destination file directly. It
writes a sibling `.tmp` and renames it, so a crash or a full disk mid-write
leaves the previous credentials intact rather than a half-written file that reads
back as corrupt.

`writeTempFile()` does, in order:

1. `umask(0077)` **before** `fopen`. Without this the temp file is born at
   `fopen`'s default mode and is world-readable for the window between creation
   and the explicit `chmod` below — small, but a window on a file containing an
   RSA private key.
2. `fopen($tmp, 'wb')`, `flock(LOCK_EX)`, `fwrite`.
3. A short-write check: `fwrite` returning fewer bytes than `strlen($bytes)` is
   treated as a failure, not a success.
4. `fflush`, then `fsync` where the function exists, so the bytes are on the
   platter before the rename claims they are.
5. `flock(LOCK_UN)`, `fclose`, then `chmod 0600`.
6. A `finally` that restores the prior umask, so the narrowed value cannot leak
   into unrelated writes later in the same request.

Any failure unlinks the temp file. `performRename()` is a one-line `@rename`
wrapper only so a test can force the rename failure branch without a destructive
filesystem setup; a failed rename also unlinks the temp file before throwing.

Every failure path throws `SecretsWriteFailed`, whose message carries the path and
never the payload — a write error must not spill credential material into a log
line.

A merge (`rememberScaHost`, `rememberSession`) reads the reader's file, changes
one bank's record and writes the whole file back through that same path. That is
what lets a consent begun at a second bank and abandoned at its login screen
leave the first bank's live session exactly where it was.

## `hasApplication()` versus `load()`

The two look like they should agree and deliberately do not.

- `hasApplication($userId)` requires **both** `application_id` and
  `private_key_pem`.
- `load($userId)` requires **only** `private_key_pem`, and falls back to `''` for
  a missing `application_id`.

The onboarding wizard is why. `OpenBankingWizardModal::generateKeypair()` writes
the private key straight to the reader's secrets file at step 1, and the user does
not paste the `application_id` back until step 3. Between those two steps the file
is half-populated, and `load()` has to be able to return the just-generated key so
step 3 can merge the pasted id into it. `hasApplication()` answers the different
question — "is registration finished?" — which is what the reconnect flow checks
before allowing a jump straight to the bank picker.

`loadOrThrow($userId, $institutionId)` is the non-null companion for call sites
that cannot proceed without credentials, chiefly the fetch path. It refuses twice:
`notConfigured()` when the reader has no application at all, and
`bankNotLinked()` when they have one but hold no session for the named bank —
a connection row that outlived its session material is a connection nobody can
fetch, and it says so rather than reaching for whichever session is nearest.

The `OpenBankingCredentials` DTO is fabricated in the repository and nowhere else,
which is what lets the arch guard assert a single credential source.

## Directory permissions are set once, not on every write

`ensureSecretsDirectory()` returns immediately when the directory already exists.
It does not re-`chmod` on subsequent writes. Re-applying `0700` every time would
silently undo any widening an operator applied on purpose — a backup agent given
read access, for instance — and a security control that quietly reverts an
administrator's decision is a support incident, not a hardening measure. The
`0700` is applied exactly once, on the `mkdir` that creates the directory, and a
`chmod` failure there is fatal rather than logged.

## Nothing here travels

A connector session arriving on a paired device would be a session that device
could spend. Four things keep it here, and none of them is an accident:

- `open_banking_connections` is declared a device-secret table in Sync's own
  coverage test, so the row never enters the op log.
- The secret itself is not a row at all. There is no file transport in the
  replication path, and `OpenBankingSecretsFileGuardTest` fails if anything under
  `Modules/Sync` so much as names this store.
- `storage/app` is excluded by name from both packagers' bundle copies, and from
  Android auto-backup, device transfer and iCloud.
- The database backup is a `VACUUM INTO` of the database. The credentials were
  moved out of the database precisely so that it cannot carry them.

Deleting an account takes its file with it:
`secrets/open-banking/%d.json` is in `UserScopedFilePurge::OWNED`, not only in the
device-wide sweep that runs for the last account on the device. SQLite reuses row
ids, so a file left behind is a file a future account would inherit.

## See also

- [`architecture.md`](architecture.md) — the module map, the AIS-only scope, and
  the consent dance that produces the `session_id` stored here.
