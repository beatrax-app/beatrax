# File Association Spike — `.csv` / `.eml` Open With Beatrax

> Code-adjacent design note for the implementation in this directory. Records
> the cross-OS plumbing decisions settled during the spike for `Modules/Desktop`.

## Why a spike

NativePHP 2.2 exposes no first-class `fileAssociations` config key. The
NativePHP plugin DOES wire `app.on('open-file', ...)` on macOS to a Laravel
event (`Native\Desktop\Events\App\OpenFile` — verified in
`vendor/nativephp/desktop/src/Events/App/OpenFile.php` and
`nativephp/electron/electron-plugin/dist/index.js:44`). The plugin's
`second-instance` handler exists too, but it is used for the deep-link
protocol — it forwards the running-instance argv as `OpenedFromURL`, NOT as
an open-file path.

What we need to settle for `.csv` / `.eml`:

1. Register `.csv` / `.eml` so the OS offers "Open With Beatrax".
2. Acquire a single-instance lock on Windows + Linux (NativePHP only acquires
   it when a deep-link scheme is configured).
3. Handle THREE equivalent inputs:
   - macOS `app.on('open-file', path)` — already wired by NativePHP plugin.
   - Cold-start on Windows/Linux: parse `process.argv` for the file path.
   - Running-instance second-launch: `app.on('second-instance', argv)` —
     reuse the path and re-fire the same `OpenFile` event.
4. Forward the file path to PHP via the same `notifyLaravel('events', ...)`
   transport the macOS path already uses — keeps a single PHP-side
   subscriber (`Modules\Desktop\Internal\Listeners\HandleNativeOpenFile`).

## Cross-OS behavior summary

| OS | Cold start | Already running |
|----|------------|-----------------|
| macOS | `app.on('open-file')` fires before `app.whenReady()` — buffer the path until the PHP HTTP server is up | `open-file` re-fires; PHP's `Window::current()->url(...)` navigates the focused window |
| Windows | File path arrives as the LAST `process.argv` entry; Electron may inject flags ahead of it | `second-instance` event's argv carries the path as the last entry; Electron's single-instance lock must be acquired first |
| Linux | Same as Windows (path on `process.argv`; `.desktop` MIME registers via electron-builder) | Same as Windows |

## Edits made to the published Electron project

The published Electron project lives at `nativephp/electron/` (created by
`php artisan native:install --publish` in plan 15-05). Two files are
modified by this spike:

### `nativephp/electron/electron-builder.mjs`

Adds the `fileAssociations` block. Each entry registers both the macOS
`CFBundleDocumentTypes` and the Windows registry / Linux `.desktop` MIME
binding so the OS surfaces "Open With Beatrax" on a `.csv` or `.eml`. The
entries are placed BEFORE the spread of the optional `updaterConfig` so
both keys can be merged cleanly into the same exported object.

```js
fileAssociations: [
    {
        ext: 'csv',
        name: 'Comma-Separated Values',
        description: 'Bank or PayPal CSV export',
        role: 'Editor', // macOS — Beatrax reads + writes derived data
        mimeType: 'text/csv', // Linux — populates .desktop MimeType=
    },
    {
        ext: 'eml',
        name: 'Email Message',
        description: 'Email receipt for import',
        role: 'Viewer',
        mimeType: 'message/rfc822',
    },
],
```

`role` is the macOS `CFBundleTypeRole` value (Editor/Viewer/Shell). Windows
electron-builder ignores it. Linux uses `mimeType` for the `.desktop`
`MimeType=` line — both MIME strings above are IANA-registered.

### `nativephp/electron/src/main/index.js`

Adds the cross-OS argv-and-single-instance handling around the existing
NativePHP `bootstrap(...)` call. Three code paths converge on the same
PHP-side event: `Native\Desktop\Events\App\OpenFile` via
`notifyLaravel('events', { event: '...App\\OpenFile', payload: [path] })`.

Key behaviors:

- Acquire the single-instance lock UNCONDITIONALLY (independent of the
  deep-link scheme). If the lock is held, quit immediately so the existing
  instance receives the path via `second-instance`.
- On cold start: scan `process.argv` for the first existing file whose
  extension is `.csv` or `.eml` (case-insensitive). Defer the event POST
  until after `notifyLaravel('booted')` fires inside the NativePHP plugin
  — the PHP HTTP server is not bound before then.
- On `second-instance`: extract the path from the supplied argv the same
  way, focus the existing main window (D-03 — "focus the existing window
  and navigate; no second window"), and POST the event.
- The path extraction is shared with the cold-start path so behavior stays
  identical between cold and warm launches.

> The actual JS patch is intentionally minimal and lives in
> `nativephp/electron/src/main/index.js` (see commit history). The
> extraction helper rejects anything that doesn't `existsSync()` AND end
> in `.csv` / `.eml` — defence-in-depth alongside the PHP-side
> `FileOpenIntake` allow-list.

## PHP-side seam

`Modules\Desktop\Internal\Listeners\HandleNativeOpenFile` subscribes to
the NativePHP-emitted `\Native\Desktop\Events\App\OpenFile` event. It
hands the supplied path to `FileOpenIntake`, which canonicalises the
path, enforces the `.csv` / `.eml` allow-list, and dispatches the Public
`Modules\Desktop\Public\Events\FileOpenedFromOs` event. Module listeners
in `Modules/Import` and `Modules/Receipts` consume the Public event
through the `FileOpenedFromOs` Public surface only — they never reach
into `Native\Desktop\*` directly.

This keeps the `noNativePhpImportsOutsideDesktopModule` arch invariant
intact: the only NativePHP-event import in the entire codebase lives
inside `Modules/Desktop/Internal/Listeners/HandleNativeOpenFile.php`.

## Tested matrix

| Path | OS tested in spike | Outcome |
|------|--------------------|---------|
| `app.on('open-file')` cold start | macOS (dev box) | path reaches PHP — verified via the NativePHP plugin's existing wiring |
| `app.on('open-file')` running-app | macOS | path reaches PHP — verified via existing wiring + the new `second-instance` PHP-side equivalence test |
| `process.argv` cold start | Windows / Linux | NOT tested in this spike (no Windows/Linux dev box). Implementation mirrors documented Electron behavior; full smoke deferred to phase 17 CI / phase 21 beta per the brief |
| `app.on('second-instance')` running-app | Windows / Linux | Same — deferred |

## Chosen path-forwarding mechanism

The spike picks the simplest of the options surveyed:

- **Chosen:** re-use NativePHP's existing `notifyLaravel('events', { event:
  '\\Native\\Desktop\\Events\\App\\OpenFile', payload: [path] })` POST. One
  PHP-side subscriber. One JS-side helper used by both the
  `process.argv` cold-start case and the `second-instance` case.
- Rejected: a separate `additionalData` channel — Electron supports it but
  it bypasses NativePHP's existing event-emission contract and would
  require a parallel PHP subscriber for the new channel.
- Rejected: a dedicated Laravel route (`POST /_native/file-open`) — adds a
  second moving piece for no win; the existing transport already protects
  itself with `X-NativePHP-Secret`.

## Security notes

- The OS-supplied path is untrusted input. `FileOpenIntake` is the
  emission boundary — see T-15-09 in the plan threat register: extension
  allow-list, `realpath()` canonicalisation, directory + non-existence
  rejection, no `exec()`.
- The intake route (`FileOpenController` in `Modules/Desktop/Routes/web.php`)
  sits behind `['web']` ONLY (not `auth`) — a logged-out file-open must
  still be accepted so `PendingFileIntent` can save it across the login
  round-trip (D-04).
- The Electron renderer keeps NativePHP v2 secure-by-default settings;
  `nodeIntegration` is NOT enabled.

## Open items handed to plan 17 / phase 21

- Real cross-OS smoke (.msi on Windows, .deb / .AppImage on Linux) — CI
  matrix in phase 17 and the invite beta in phase 21 will exercise the
  argv paths against real installers.
- macOS notarised build: the `fileAssociations` `extendInfo` keys are
  declared, but notarisation testing waits for phase 17's Apple Developer
  ID setup.
