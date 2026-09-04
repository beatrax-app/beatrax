# A download the shell drops

Driven on a Galaxy S24 (SM-S928B) and an iPhone side by side, on the same
build. On `/reports`, `↓ CSV exporteren` runs its Livewire round-trip on both —
`BRIDGE_TOTAL [/livewire-.../update] 27ms` in logcat. On the iPhone the
accessibility tree then holds `XCUIElementTypeActivityListView`, `Save to
Files` and `AirDrop`. On the Samsung:

| looked at | found |
|---|---|
| `/sdcard/Download/` | nothing |
| `run-as com.beatrax.mobile ls ./app_storage/persisted_data/storage/app/exports/` | no such directory |
| the page | unchanged |
| the toast container | empty |

The iOS shell answers a download navigation with `.download` and presents a
`UIActivityViewController`, which `scripts/nativephp_ios_download_delegate.php`
installs. The Android shell registers no `DownloadListener` at all, so an
`<a download>`, a blob URL and a `Content-Disposition: attachment` response are
each dropped with no file, no error and no console entry. That is what
`Modules\Core\Public\Enums\MobilePlatform::savesWebViewDownloads()` has always
said. What was missing is anything that acted on the answer.

## The seam

`Modules\Mobile\Public\Services\ShareSheetExport` writes the payload into the
app's own data directory at 0600 and hands that path to
`Modules\Mobile\Internal\Native\NativeShareSheet`, which calls `Share::file()`
— the method the Android shell registers via
`scripts/nativephp_android_share_file.php`. The reader gets the OS share sheet
— "Save to Files", "Send to…" — and the file lands wherever they choose,
outside the container a reinstall destroys.

The split is not decoration. The seam has to be `Public\` because six modules
reach it, and a vendor facade may only be named from the module's own
`Internal\` side, so `NativeShareSheet` is the only file in this feature that
mentions `Native\Mobile`. Everything above it deals in booleans and a
`FileExportOutcome`, which is also what lets a test stand in for a phone
without naming the package.

It answers three questions and no more:

- `replacesWebViewDownload()` — must this download come through here? False on
  the desktop, in a browser and on iOS. True on Android, and true on a shell
  NativePHP names that `MobilePlatform` does not model: of the two ways to be
  wrong about an unknown phone, one costs a share sheet nobody needed and the
  other is the silence this page is about.
- `isAvailable()` — can this shell take a file at all? Safe to call anywhere;
  false on web, CI and desktop without touching the native facade.
- `export()` / `exportFile()` — hand a payload over, from memory or from a file
  already on disk. `exportFile()` **moves** its source rather than copying it:
  an encrypted database snapshot is the size of the database, and a second copy
  left behind fills a phone the reader cannot sweep out.

Both return a `Modules\Mobile\Public\Enums\FileExportOutcome`, and its three
cases are the whole point. `Shared` — the sheet opened. `Unsupported` — this
shell registers no `Share.File`, so nothing was written. `Failed` — the write
or the handover refused. Every one of them has a sentence in
`mobile::export`, and `FileExportOutcome::message()` is how it reaches the
screen. There is deliberately no fourth case for "nothing happened".

`Share::file()` returns void and `nativephp_call()` swallows a name the shell
never registered, so calling it proves nothing; `nativephp_can('Share.File')`
is asked first, which is the only reason `Unsupported` can be told apart from
`Shared`.

## Every surface that produces a file

Each of these asks `replacesWebViewDownload()` first, streams the download when
the answer is no, and hands the payload to the sheet when it is yes. Nothing
about the desktop, browser or iOS behaviour changes.

| surface | file |
|---|---|
| `EncryptedBackupDownload::download()` | `beatrax-backup-<stamp>.sqlite.enc` |
| `TaxPage::exportCsv()` / `::exportPdf()` | `beatrax-tax-<year>.csv` / `.pdf` |
| `ReportBuilder::export()` | `beatrax-report-<slug>.csv` |
| `GET /reports/export` | `beatrax-report-<slug>.csv` |
| `AliasesSettingsPage::exportYaml()` | `aliases.yaml` |
| `ManageUserPage::downloadCodes()` | `beatrax-recovery-codes-<name>.txt` |
| `ExportRecoveryCodes` | `beatrax-recovery-codes-<name>.txt` |

`GET /reports/export` is a plain navigation rather than a Livewire action, so
it answers with the outcome sentence as `text/plain` — 200 when the sheet
opened, 503 when it did not. The two recovery-codes screens pass their own
share-sheet title and message, because that sheet has something specific to
say.

## The backup was withheld, not delivered

`EncryptedBackupDownload` was the one surface that asked
`savesWebViewDownloads()` — and hid itself when the answer was false, telling
the Android reader to make the backup in the desktop app. `EncryptedBackupRestore`
sat on the same screen and was offered to that same reader: back up nowhere,
restore from anywhere. A phone-only Android user could never take a copy of
their own financial data at all.

"Cannot be saved" was true of the WebView download route, never of the phone.
With the sheet as the delivery route the feature is offered again, and
`core::backup.download.no_download_route` now speaks only for a shell that
registers no `Share.File` — the one case where there really is nothing to
offer. A refused handover unlinks the encrypted file rather than leaving whole
databases piling up in a container nobody can reach.
