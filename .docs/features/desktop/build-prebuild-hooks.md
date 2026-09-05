# The desktop prebuild hooks

NativePHP's published Electron scaffold is not quite what a signed, notarised,
auto-updating Beatrax bundle needs. Rather than fork it, the desktop build
**patches it on every run**: `config/nativephp.php` lists a `prebuild` array of
small PHP scripts under `scripts/`, and `php artisan native:build` executes them
in order before electron-builder starts.

Every script is idempotent and reapplied on each build, because the tree it
patches (`nativephp/`) is gitignored regenerated output — nothing it writes
survives to the next build.

This is the desktop counterpart of the mobile patch scripts documented in
[mobile architecture](../mobile/architecture.md). See also
[what the desktop build leaves behind](build-file-exclusions.md) for the copy
step these hooks run alongside.

## Order matters

NativePHP runs the `prebuild` entries in the order the array declares them, and
the first has to stay first: `nativephp_stage_build_resources.php` puts the icons
and the entitlements file into the directory that the signing configuration then
points at. Signing before staging leaves electron-builder with nothing to find.
The sections below follow the array's order.

A hook that fails does not stop the build. NativePHP's prebuild runner prints
`Command failed: …` for a non-zero exit and moves straight on to the next entry,
so a script whose anchor a NativePHP release has moved leaves a red line in the
build log and an installer that is still produced — without whatever that hook
was there to patch. Every script exits non-zero and writes its reason to STDERR
because that log line is the only signal there is.

## The hooks

### `nativephp_stage_build_resources.php` — put the assets where the builder looks

Copies the committed brand icons (`public/icon.*`) and the macOS Hardened
Runtime entitlements file (`build/entitlements.mac.plist`) into the
electron-builder `buildResources` directory. The `nativephp/` working directory
is gitignored, so these canonical sources must be staged in on **every** build
for electron-builder to discover them at all.

### `nativephp_developer_id_signing.php` — one identity for the whole bundle

Pins an **explicit** `mac.identity` (from `NATIVEPHP_MAC_IDENTITY`) plus Hardened
Runtime, so electron-builder signs the shell **and** the nested Electron
Framework and helper binaries with a single identity and Team ID.

Two things depend on that being explicit rather than auto-discovered:
notarisation (`build/notarize.js`, wired as an `afterSign` hook) requires
Hardened Runtime and a consistent Team ID; and ad-hoc auto-discovery produced a
Team ID mismatch between the outer shell and the nested Electron binaries on
Apple Silicon.

`scripts/nativephp_force_adhoc_signing.php` is the earlier hook this replaced.
It is still in the repo for local unsigned development builds, but it is not in
the `prebuild` chain.

### `nativephp_azure_publisher_name.php` — the key NativePHP omits

Adds the `publisherName` key to `win.azureSignOptions`. electron-builder 26
requires it, so without this patch **every** Azure Trusted Signing build aborts
during config validation — before signtool is ever reached. See
[repo security setup](../../runbooks/repo-security-setup.md) for how the
`NATIVEPHP_AZURE_*` values are provisioned.

### `nativephp_inject_macos_update_settings.php` — no differential downloads

Sets `autoUpdater.disableDifferentialDownload = true`, forcing the full-binary
update path. The full-binary path is the one covered end-to-end by the Ed25519
manifest signature plus the SHA-512 binary hash check in `ElectronUpdateChannel`;
the differential path validates the delta archive against the running bundle's
OS-level signature instead.

Where the setting lives is the awkward part. electron-builder 26 hard-removed
`disableDifferentialDownload` from `MacConfiguration` — it was always really a
runtime setter on the `autoUpdater` object, and older electron-builder versions
silently accepted the unknown config key where v26 strict-validates and aborts
the build. The correct location is therefore the compiled Electron main-process
JS, immediately after the destructure:

```js
const { autoUpdater } = electronUpdater;
```

The patch inserts the setter after that line in **both**
`electron-plugin/dist/server/api/autoUpdater.js` and
`electron-plugin/dist/index.js`, because each does its own destructure and uses
its own `autoUpdater` reference.

### `nativephp_inject_explicit_consent_updates.php` — nothing installs itself

Sets `autoDownload = false` and `autoInstallOnAppQuit = false` on
electron-updater, so no update downloads or installs until the Ed25519-signed
manifest has been verified and the user has explicitly consented. The
verification itself lives in `ElectronUpdateChannel` (see
[core architecture](../core/architecture.md) and `config/auto_update.php`).

### `nativephp_patch_electron_imports.php` — subpath imports

Exposes `#plugin/*` in the Electron `package.json` `imports` map so subpath
imports such as `#plugin/server/state.js` resolve under Node subpath imports.
The NativePHP-published template declares only the bare `#plugin` entry, which
makes the Vite/Rollup build abort.

### `nativephp_inject_persistent_tray.php` — a tray that outlives the window

Injects a native Electron `Tray` with a template-flagged icon and its own context
menu directly into the Electron main process, replacing NativePHP's `MenuBar`
facade.

The facade wraps the popover-style npm `menubar` library, which couples tray menu
items to the **focused BrowserWindow**. Once the user closes the main window with
the X button there is no focused window, and the tray's "Open Beatrax" item
silently does nothing — the app is running with no way back to it. The injected
menu handlers either show and focus an existing main window, or POST to
`/api/window/open` to reconstruct one from any state.

### `nativephp_inject_file_associations.php` — telling the OS that Beatrax opens these files

electron-builder writes the macOS `CFBundleDocumentTypes`, the Windows registry
entries and the Linux `.desktop` `MimeType=` line from a `fileAssociations` key,
and NativePHP's published `electron-builder.mjs` declares none. Without that
block no shell ever routes a document to Beatrax — so macOS's `open-file`, the
one leg NativePHP already forwards, never fires, and no argv ever carries a path
on the other two platforms. The whole `FileOpenIntake` route into the
application was unreachable everywhere at once.

The hook declares `.csv` and `.eml` document types, splicing them in ahead of
electron-builder's `protocols` key so the two OS-registration blocks sit
together. NativePHP 2.2 exposes no config key for this, and a hand edit to
`nativephp/electron-builder.mjs` does not survive `native:install --publish`.

Removing this hook fails nothing: the build succeeds, the app runs, and
double-clicking a statement opens it in something else.

### `nativephp_inject_file_open_ingress.php` — the two platforms that never emit `open-file`

Windows and Linux put the document path on `process.argv` at cold start and hand
it to an already-running instance through `app.on('second-instance')`. NativePHP
registers a `second-instance` handler only when a deep-link scheme is configured,
and forwards it as `OpenedFromURL` — a deep link, not a document.

The hook takes the single-instance lock unconditionally, scans argv last-first
for a `.csv`/`.eml` argument, and forwards both the cold-start and the
second-instance case through NativePHP's own `notifyLaravel('events', …)`
transport as the same `App\OpenFile` event the macOS leg raises. That keeps one
PHP-side subscriber and adds no HTTP route of Beatrax's own: a main-process POST
into the `web` group carries neither session nor CSRF token and could only ever
answer `419`. Without the lock, a second double-click opens a second copy of a
ledger application.

Its anchor is NativePHP's `NativePHP.bootstrap(...)` call, matched verbatim on
purpose. What PHP can pin about this script and the associations one is pinned in
`Modules/Desktop/tests/Unit/FileOpenIngressScriptsTest.php`; that a
double-clicked export on a packaged Windows or Linux build reaches
`FileOpenIntake` is only observable from a real installer.

### `nativephp_fix_php_binary_extraction.php` — byte-exact PHP binary

Patches NativePHP's `php.js` so electron-builder's `beforePack` step extracts the
bundled static PHP binary with `ditto`/`unzip` instead of the yauzl streaming
pipe. The streaming path inflates the arm64 Mach-O by roughly 5 MB, and codesign
then refuses it:

```
main executable failed strict validation
```

This is a hard precondition for Developer ID signing and notarisation, not an
optimisation.

## postbuild

The `postbuild` array is empty. Anything that needs to run after packaging on
macOS runs as an electron-builder `afterSign` hook instead (`build/notarize.js`),
because notarisation has to happen between signing and stapling — not after the
artifact is finished.
