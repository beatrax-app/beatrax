# Why the Tax PDF still uses dompdf

The desktop shell embeds Chromium, and Chromium prints to PDF. That makes
`dompdf` — 13 MB of vendor tree for one feature — look like an obvious
deletion. It is not. This note records what a spike against the real
installed packages found, so the question does not get re-opened from
first principles every time someone reads the dependency list.

## The answer is mobile

`nativephp/mobile` exposes no print or PDF API. The facade surface is
Biometrics, Browser, Camera, Device, Dialog, File, Geolocation, Haptics,
Microphone, MobileWallet, Network, PushNotifications, Scanner,
SecureStorage, Share, System — and `System` is only appearance and
platform predicates. The four `pdf` matches anywhere under
`mobile-app/vendor/nativephp/` are a MIME-map entry, a download-extension
regex, a Material icon name, and `Barcode.FORMAT_PDF417`.

Tax is enabled on mobile, `/tax` is registered from the shared module
tree, and the same `TaxPage::exportPdf()` runs there. That is why
`dompdf/dompdf` is a direct require in `mobile-app/composer.json` and not
merely inherited: on the phone it is the only PDF engine there is.
`Share::file()` shares a file that already exists; something still has to
produce the bytes.

Removing dompdf deletes the Tax export on Android and iOS.

## What the desktop API actually is

`Native\Desktop\System::printToPDF(string $html, ?array $settings = [])`
posts to a local Express route inside the Electron main process and
returns **base64**, not PDF bytes. It renders in a hidden `BrowserWindow`,
so it does work without a visible window — but it needs Electron running,
and the HTTP client's timeout is an hour.

Two upstream defects are worth knowing before anyone builds on it: the
facade docblock advertises the wrong signature, copy-pasted from
`promptTouchID`; and the Express app registers no error middleware, so a
load failure returns an HTML 500, the JSON decode yields null, and a
method typed `: string` throws a `TypeError` rather than reporting the
failure.

## The output would change, visibly

Measured on the real Tax template, 80 rows across 4 categories:

| | dompdf | Chromium default | Chromium tuned |
|---|---|---|---|
| Paper | A4 | **US Letter** | A4 |
| Table and summary shading | rendered | **dropped** | rendered |
| Fonts | 2 non-embedded base-14 | 9 embedded subsets | 9 embedded subsets |
| Size | 10 KB | 71 KB | 71 KB |

`setPaper('A4', 'portrait')` has no equivalent; paper size moves into an
untyped settings array whose default is Letter. `printBackground`
defaults to false, and the template leans on background colour for the
summary block, table headers and subtotal rows. Pagination differs even
at matched A4.

The deep problem is fonts. dompdf lays out from `.afm` metric files
committed in the vendor tree, so the same export is byte-stable on every
machine. Chromium embeds subsets resolved from the host font stack, so
the same export paginates differently on macOS, Windows and Linux. The
7.5 MB font directory that makes dompdf look heavy is exactly what buys
that determinism.

## The test would stop testing anything

`TaxExportPdfTest` renders in-process and asserts on real bytes. CI test
jobs are bare runners with no Electron and no display, so the call fails
at connect. Worse than failing: the API base URL is `localhost:4000`, and
a developer with the desktop app running would have the test silently
drive their live app's Chromium instead of failing — with an hour-long
client timeout. That is the same port-4000 hazard already documented for
the Sync suite.

## Weight, measured

13 MB across six packages, against a 1.3 GB vendor tree — about 1%.
None of the six is required by anything else, so all of it is genuinely
reclaimable, but only on the platform that already ships 200 MB of
Chromium, and not at all on the platform where it is the only engine.

## What would change this

- `nativephp/mobile` shipping a print or PDF API. This is the
  load-bearing one; re-spike if it lands.
- The Tax PDF needing something dompdf cannot render — webfonts, grid or
  flex layout, SVG charts. The template is deliberately CSS 2.1 today.
- dompdf going unmaintained, or an unfixed advisory against it.
- A decision that the Tax PDF is desktop-only, reflected by turning Tax
  off for the mobile shell.

If a Chromium path is ever wanted *in addition* on desktop, note the
seam: `Native\Desktop\` is quarantined to `Modules/Desktop/` by an
architecture test, so Tax cannot call it directly. It would need a
contract, a Desktop adapter, and a fallback — and the fallback has to
emit a PDF, which is dompdf.
