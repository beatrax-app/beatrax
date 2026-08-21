# Open banking secrets at rest

The Enable Banking connector holds three things that are individually enough to
impersonate the user against their bank: the RSA private key the JWT signer uses,
the aggregator `application_id`, and the live `session_id` a consent grants. They
have to survive an app restart, so they have to be written down somewhere.

The obvious place — a column on `open_banking_connections` — is the wrong one.
The app's own backup feature copies the whole SQLite database, and the desktop
bundle syncs it; a secret in a column is a secret in every backup the user ever
takes, on every device the database reaches. So the credentials live outside the
database entirely, in a single JSON file, and every layer below exists to make
that file uninteresting to anyone who obtains a copy of it.

`Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository` is the only
class in the module permitted to touch that path. An arch test
(`OpenBankingSecretsFileGuardTest`) enforces it, and a second guard asserts that
no file which references `DatabaseManager` also references a raw credential field
name — which is why callers go through
`EnableBankingHttpClient::sessionIdFrom()` rather than indexing a response array
themselves.

## Where the file lives

`storage/app/secrets/open-banking.json`, resolved through
`UserDataPathService::appPath()` so it follows `NATIVEPHP_STORAGE_PATH` into the
desktop bundle's own data directory. The constant `PATH_RELATIVE` is written
relative to the `storage/app` root, with **no leading `app/`** — `appPath()`
already supplies that segment.

The directory is created `0700` and the file `0600`, from
`SecretFileMode::DIRECTORY` and `SecretFileMode::FILE` — the one place the
on-disk permissions of every secret-blob store in the app are decided, so
this store cannot drift a digit away from the `.eml` stores beside it.

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
`decryptAtRest()` catches it and returns the revealed bytes as-is, so an existing
connection keeps working across the upgrade; the next `save()` re-persists it
through both layers. The catch is deliberately narrow — a `DecryptException` is
the only failure it swallows.

## The atomic write

`writeAtomic()` never writes the destination file directly. It writes a sibling
`.tmp` and renames it, so a crash or a full disk mid-write leaves the previous
credentials intact rather than a half-written file that reads back as corrupt.

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

## `hasApplication()` versus `load()`

The two look like they should agree and deliberately do not.

- `hasApplication()` requires **both** `application_id` and `private_key_pem`.
- `load()` requires **only** `private_key_pem`, and falls back to `''` for a
  missing `application_id`.

The onboarding wizard is why. `OpenBankingWizardModal::generateKeypair()` writes
the private key straight to the secrets file at step 1, and the user does not
paste the `application_id` back until step 3. Between those two steps the file is
half-populated, and `load()` has to be able to return the just-generated key so
step 3 can merge the pasted id into it. `hasApplication()` answers the different
question — "is registration finished?" — which is what the reconnect flow checks
before allowing a jump straight to the bank picker.

`loadOrThrow()` is the non-null companion for call sites that cannot proceed
without credentials, chiefly the HTTP boundary that signs every request. The
`OpenBankingCredentials` DTO is fabricated in this class and nowhere else, which
is what lets the arch guard assert a single credential source.

## Directory permissions are set once, not on every write

`ensureSecretsDirectory()` returns immediately when the directory already exists.
It does not re-`chmod` on subsequent writes. Re-applying `0700` every time would
silently undo any widening an operator applied on purpose — a backup agent given
read access, for instance — and a security control that quietly reverts an
administrator's decision is a support incident, not a hardening measure. The
`0700` is applied exactly once, on the `mkdir` that creates the directory, and a
`chmod` failure there is fatal rather than logged.

## Single-user caveat

There is one global secrets file. It is not keyed by user or by connection, so a
second user's `save()` would overwrite the first user's credentials outright.
`guardSingleUser()` logs a warning whenever a write happens while more than one
user account exists. It warns rather than throws on purpose: `userCount()` has to
work in unit tests that run without a database, where it catches and returns
`null`, and a hard throw would turn "we have not built per-user keying yet" into a
broken test suite.

Per-user or per-connection keying is required before a second user can safely use
this connector.

## See also

- [`architecture.md`](architecture.md) — the module map, the AIS-only scope, and
  the consent dance that produces the `session_id` stored here.
