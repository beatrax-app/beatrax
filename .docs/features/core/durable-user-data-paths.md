# Durable user data paths

`UserDataPathService` decides, for every file the bundle reads or writes,
whether that file survives an application update. Get the answer wrong for
one path and the app keeps booting — it just loses something the user can
never get back.

## The problem

An update replaces the application bundle wholesale. On desktop that bundle
sits next to a separately-managed storage root; on NativePHP mobile the
bundle IS `base_path()`, and everything under it is wiped and re-shipped by
the installer.

Some of what the app writes is genuinely disposable — compiled views, route
and config caches, log files. Losing those on update is correct; they are
rebuilt on the next boot. But the same `storage/` tree also holds material
that cannot be regenerated from anything: the encryption keyring that
decrypts the user's own rows, the sync device identity, the secrets file,
and the local backups.

The obvious approach — resolve everything from `base_path()`, or from
Laravel's `storage_path()` which is derived from it — fails silently, and it
fails asymmetrically. The database was moved to the persisted store first,
so the rows survived an update. `storage/app` was not, so the keyring did
not. The outcome was a database full of ciphertext with no key: the app
launched, the account was there, and not one sensitive field could be
decrypted. Nothing errored at update time; the loss only surfaced on the
next read.

## Three roots, not one

The class resolves three distinct roots, and the whole design is in knowing
which one a given accessor belongs to.

**`projectRoot()`** is `base_path()` — the bundle. Code, migrations,
`public/` assets, `Modules/`. Replaced on every update. Nothing the user
owns may ever resolve here. `modulesPath()`, `migrationsPath()`,
`publicPath()` and `projectPath()` are the deliberately project-rooted
accessors.

**`storageRoot()`** is the `storage/` tree, honouring `NATIVEPHP_STORAGE_PATH`
when the packaged desktop build sets it. It deliberately does **not** branch
for mobile. `storage/framework` and `storage/logs` are disposable caches, and
relocating them into the persisted store would carry stale compiled views
across an update that was supposed to clear them. A test pins this
non-branching, so a future "consistency" fix that adds the branch fails
loudly rather than quietly.

**`appRoot()`** is `storage/app` — the durable half. Keyring, sync identity,
secrets, backups. This one **does** branch for mobile, onto the sibling
`persisted_data` store that survives installs.

`appRoot()` resolves in a fixed order, and the order matters:

1. `NATIVEPHP_STORAGE_PATH`, when set — the packaged desktop build.
2. `isMobileRuntime()` — the sibling `persisted_data/storage/app`.
3. Project-rooted `storage/app` — host development and tests.

The env var is checked first so a packaged desktop build never falls through
into the mobile structural check.

## Detecting the mobile runtime

The private `platformSignal()` reads `$_SERVER['NATIVEPHP_PLATFORM']`, then
`$_ENV['NATIVEPHP_PLATFORM']`, then `getenv('NATIVEPHP_PLATFORM')`. All three
are read because NativePHP injects the value as a server/env constant rather
than through `putenv()` — a bare `getenv()` returns `false` on a real device,
which would silently disable every gate that depends on it.

The public `platform()` maps that signal through
`Modules\Core\Public\Enums\MobilePlatform::tryFrom()` and returns
`?MobilePlatform`, so a shell this app models no behaviour for reads as `null`
at every call site instead of arriving as a raw string one of them might
happen to match.

Even all three together are not sufficient. In NativePHP's persistent
runtime the value is present when the `->booted()` hook fires but reads back
null when `config/*.php` is re-evaluated on a later request. Because
`config/database.php` resolves `databaseFile()` per request, a
platform-only check would intermittently fall back to the bundle database
path on-device — re-shipping the populated development `database.sqlite`
(a data leak) and defeating the fresh-install onboarding gate at the same
time.

`isMobileRuntime()` therefore keeps the raw `platformSignal()` as the fast
signal and adds a structural fallback: the sibling `persisted_data` directory,
provisioned by the native layer before the PHP runtime serves its first
request. Its existence is stable across every request-load, and it never
matches on desktop or host.

It asks `platformSignal()` rather than `platform()` deliberately: a shell
NativePHP names but `MobilePlatform` does not model is still a mobile runtime,
and answering `false` would send that device's durable user data back into the
wiped-and-reshipped bundle.

## The concrete failures each rule prevents

- **Keyring under the bundle** — undecryptable rows after the next update.
  This one already happened; it is why `appRoot()` branches at all.
- **Database under the bundle** — the shipped development `database.sqlite`
  becomes the user's database. A data leak, and the onboarding gate never
  fires because the database is not empty.
- **`storage/framework` under the persisted store** — compiled views and
  cached config outlive the update that replaced the code they were
  compiled from.
- **A raw `base_path()` / `storage_path()` / `database_path()` call
  anywhere else** — the arch invariant `noRawPathHelpersOutsidePathService`
  fails. This class is the allow-list, and it has exactly one entry.

`getenv()` is used throughout rather than Laravel's `env()` helper because it
is unconditional at every boot stage. That is what makes these static
accessors safe to call from `config/*.php` files, which are evaluated before
the container exists.

## Path traversal

`appPath()` takes a caller-supplied relative segment, so it splits the
argument on both separators and throws `InvalidArgumentException` on any
`..` component. The durable root holds the keyring and the secrets file; a
traversal out of it would let a caller name any file on the device.

## Why `config/view.php` must not call `realpath()`

Laravel ships `'compiled' => realpath(storage_path('framework/views'))`.
`realpath()` returns `false` for a directory that does not exist yet, and on
the NativePHP mobile runtime the app-copy rsync strips `storage/framework/*`
on every install and update. So on a genuine **cold** boot, `config/view.php`
— evaluated during config-load, before the container exists — could resolve
before `mobile-app/bootstrap/app.php`'s `->booted()` hook had recreated the
directory. `view.compiled` froze at the empty string, and Blade's `Compiler`
constructor threw *"Please provide a valid cache path."* on every render: a
500 on the first cold boot that self-healed on the next, warm one.

Calling `UserDataPathService::frameworkPath('views')` instead removes the
boot-order dependency rather than papering over it. It returns a stable,
non-empty absolute path (honouring `NATIVEPHP_STORAGE_PATH`) whether or not
the directory exists, and Blade's `Compiler::ensureCompiledDirectoryExists()`
creates the directory itself on first compile — so a not-yet-existing target
is harmless. On desktop and host the directory already exists at boot, so
this is a no-op there.

The `->booted()` hook still recreates the stripped `storage/framework/*` tree
(it also reconciles session and cache to the `database` driver), but nothing
in `view.compiled` depends on its timing any more.

## See also

- [`Core` architecture](architecture.md) — where this class sits in the
  module's public surface, and the rest of the shared primitives.
- [Mobile architecture](../mobile/architecture.md) — the device side of the
  persisted store.
- [SQLite file pre-creation](../../architecture/sqlite-file-precreation.md) —
  why the database file has to exist before the first connection.
