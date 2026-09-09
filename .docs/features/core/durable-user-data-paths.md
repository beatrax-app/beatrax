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

**`storageRoot()`** is the `storage/` tree, honouring whichever name the
running shell announces it under: `NATIVEPHP_STORAGE_PATH` from the packaged
desktop build, `LARAVEL_STORAGE_PATH` from both mobile shells. It still does
**not** branch on `NATIVEPHP_PLATFORM`, and a test pins that; what it reads is
an announcement, in the same shape the desktop's has always been read.

Reading only the desktop's name is what shipped. On iOS that put
`laravel.log` in `Documents/app/storage/logs` — the bundle
`AppUpdateManager.removeItem(atPath: appPath)` deletes whole on every version
change, and the one data tree on the device that nothing excludes from iCloud
— while the app's own `booted()` hook created `storage_path('logs')` beside it
and wrote nothing there. Measured on an iPhone 12 mini, iOS 26.5.2: 250 lines
across five launches in the first, none in the second.

The reason recorded for not reading it was that relocating
`storage/framework` into the persisted store would carry stale compiled views
across an update meant to clear them. Both halves of that turned out not to
hold. Android's shell already points `VIEW_COMPILED_PATH` and `CACHE_PATH`
inside `persisted_data/storage/framework`, and `mobile-app/bootstrap/app.php`'s
`booted()` hook already re-points `view.compiled` at `storage_path()` on both
platforms — so compiled views were never in the bundle to begin with. And a
compiled view is expired against its source's mtime, which an update rewrites,
so a surviving one is recompiled on first render rather than served.

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

On Android `LaravelEnvironment.kt` has always created it. On iOS nothing native
did — the directory was made by the `->booting()` hook's own `@mkdir`, which is
inside the PHP runtime rather than ahead of it. `prepareDurableStore()`, injected
into the iOS shell by `scripts/nativephp_exclude_data_from_backup.php`, now
creates it in `preparePhpEnvironment()` before PHP is embedded. That ordering is
not cosmetic: `NSURLIsExcludedFromBackupKey` is set on a node that exists, so a
shell arriving after PHP's `@mkdir` would be flagging a directory it did not
make, one launch late.

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
- **The log file under the bundle** — `Documents/app` is deleted whole on
  every iOS version change, so the log of the update you are diagnosing is
  the first thing the update destroys. It is also the only data tree on the
  device outside the two the shell flags, so it was the only one iCloud
  copied. Reading the shell's announcement is what moves it.
- **A raw `base_path()` / `storage_path()` / `database_path()` call
  anywhere else, or a storage path written out by hand** — the arch
  invariant `noStoragePathHardCodedOutsideUserDataPathService` fails. It
  bans the three helpers, the container spellings of the same question
  (`$app->storagePath()`, `$this->laravel->databasePath()`, `App::` and the
  rest), and the literals `database.sqlite` and `storage/app/`, across
  `Modules`, `app` and `config`.
- **The same question asked of the container** — the rule read only the
  helper spelling for as long as it existed, and its pattern excluded
  anything after `->` outright. Two classes went through that hole:
  `ScanInboxDropFolderJob` and `FileDropEmlBlobStore` resolved the reader's
  dropped receipts and file-drop mail through `$app->storagePath('app/…')`.
  On a checkout, on the desktop and on Android that is the same directory
  `appPath()` answers, which is why it read as a spelling difference. On iOS
  it is not, and `UserDataLocations` — the one inventory behind the "where is
  my data" page, the deletion procedure and the export — answers `appPath()`.
  A delete would have walked an empty tree and reported success.

`getenv()` is used throughout rather than Laravel's `env()` helper because it
is unconditional at every boot stage. That is what makes these static
accessors safe to call from `config/*.php` files, which are evaluated before
the container exists.

## Three storage-root names, and which sources each is read from

`NATIVEPHP_PLATFORM` is read from `$_SERVER`, `$_ENV` and `getenv()`;
`NATIVEPHP_STORAGE_PATH` is read with a bare `getenv()`; `LARAVEL_STORAGE_PATH`
is read from all three. The asymmetry is not drift — each variable reaches PHP
by its own route, and is read the way that route delivers it.

**`NATIVEPHP_STORAGE_PATH` is set by the desktop shell only.** The Electron
plugin puts it in the object it hands to the spawned PHP process
(`resources/electron/electron-plugin/src/server/php.ts`), so it arrives as a
genuine process environment variable and `getenv()` is the primitive that
reads it. No mobile shell sets it at all, and its absence is what hands the
`appRoot()` decision to `isMobileRuntime()`.

**`LARAVEL_STORAGE_PATH` is the mobile shells' name for the same thing**, and
it is the name Laravel's own `Application::storagePath()` reads — from `$_ENV`
first, then `$_SERVER`, never `getenv()`. iOS sets it with `setenv()` in both
`NativePHPApp.swift:setupEnvironment()` and `PersistentPHPRuntime.boot()`;
Android sets it through `LaravelEnvironment.kt`'s `setEnvironmentVariables`
batch, alongside `VIEW_COMPILED_PATH` and `CACHE_PATH`. It is read from all
three sources for the same reason `platformSignal()` is: Android hands its
environment to PHP as server consts, so a bare `getenv()` is blind on that
half of the phones while looking perfectly correct on the other.

The two mobile shells do **not** announce the same tree relative to
`base_path()`, which is why `storageRoot()` and `appRoot()` stay separate
accessors rather than one deriving from the other:

| | `base_path()` | announced storage root | durable store |
| --- | --- | --- | --- |
| Android | `<files>/laravel` | `<files>/persisted_data/storage` | `<files>/persisted_data` |
| iOS | `<container>/Documents/app` | `<container>/Library/Application Support/storage` | `<container>/Documents/persisted_data` |

On Android the announcement and the store are the same tree; on iOS they are
two, and both are excluded from backup by
`scripts/nativephp_exclude_data_from_backup.php` — the announcement through
the `getAppSupportDir()` patch, the store through `prepareDurableStore()`.
`Documents/app` is flagged by neither, which is the whole reason the log file
had to leave it.

**`NATIVEPHP_PLATFORM` does have a `$_SERVER`-only route.** Each embedded
webview gets its own PHP context, and those slots pass request state by
inlining `$_SERVER['NATIVEPHP_PLATFORM'] = 'ios';` into the eval rather than
calling `setenv()` — deliberately, so the slots can run concurrently without
racing the persistent lane's environment churn. A bare `getenv()` is blind to
that, which is the whole reason the three-source read exists.

Resolved roots, measured rather than reasoned about:

| | `storageRoot()` | `appRoot()` | `databaseFile()` |
| --- | --- | --- | --- |
| host / test | `<base>/storage` | `<base>/storage/app` | `<base>/database/…` |
| desktop | `$NATIVEPHP_STORAGE_PATH` | `…/app` | `…/database/…` |
| Android | `$LARAVEL_STORAGE_PATH` (= `…/persisted_data/storage`) | `…/persisted_data/storage/app` | `…/persisted_data/database/…` |
| iOS | `$LARAVEL_STORAGE_PATH` (= `…/Library/Application Support/storage`) | `…/persisted_data/storage/app` | `…/persisted_data/database/…` |

The desktop row is the live packaged behaviour; the iOS row is confirmed
against a real device, where `persisted_data/storage/app/sync/identity/*.enc`
and the database both sit in the persisted store, and where the announced
storage root was read off the running app: `Library/Application
Support/storage` carried `framework/views`, `framework/cache/data` and
`framework/native_routes.json` while `Documents/persisted_data` carried the
database. The `storageRoot()` column is what the two disagreed about. On desktop the app's own
default connection is not this file's `databaseFile()` at all — the vendored
desktop service provider rewrites `database.default` to its own `nativephp`
connection, which in debug builds is `database/nativephp.sqlite` inside the
project and in packaged builds is `NATIVEPHP_DATABASE_PATH`.

## One name for the store, read by two very different callers

The directory name, the database's relative path and `storage/app` live in
`Modules\Core\Public\Support\PersistedStore`, not in this class. That looks
like indirection for its own sake until you see who the second caller is:
`scripts/nativephp_exclude_data_from_backup.php` builds the iOS shell's
backup-exclusion from the same constants, so the tree the shell flags is the
tree this class resolves, by construction.

They were not held together before, and they disagreed. The exclusion was set on
`Library/Application Support` while the store is under `Documents`, so the SQLite
ledger, the GDK keyring, the sync identity and the staged secrets were all in
iCloud backup. A hardware check reported the requirement satisfied because it
confirmed "a database" was excluded without recording which one — and the file it
could have read was a 4 KB empty stub. `ExcludeDataFromBackupScriptTest` now
asserts the excluded directories against what this class answers, rather than
against a string in a Swift file.

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
- [One export action](one-export-action.md) — `UserDataLocations`, the
  inventory built on these accessors, and the archive that bundles it.
- [Mobile architecture](../mobile/architecture.md) — the device side of the
  persisted store.
- [SQLite file pre-creation](../../architecture/sqlite-file-precreation.md) —
  why the database file has to exist before the first connection.
