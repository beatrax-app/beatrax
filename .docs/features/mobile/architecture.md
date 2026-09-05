# `Mobile` — architecture

The `Mobile` module wires the NativePHP mobile app as a fully synced peer:
on-device first-launch bootstrap, biometric-primary app-lock (including a
cold-start enclave path), camera-first device pairing, a blocking resumable
initial-sync gate, and an outbound-only LAN/relay sync transport. The
phone never runs a listener or daemon — every native crossing is a single
bounded operation that opens, does one thing, and closes.

## Detecting the on-device runtime

Every native-capability gate in this module — `QrScanBridge::isAvailable()`,
`BiometricUnlockBridge`, `BiometricKeyVault`, `SecureStorageKeyCustodian`, the
`KeyCustodian` binding in `MobileServiceProvider`, and the mobile root's
`->booted()` reconciliation hook — asks `UserDataPathService::isMobileRuntime()`.

It must not be a bare `getenv('NATIVEPHP_PLATFORM')`. The runtime injects that
value as a server/env const rather than through `putenv()`, so `getenv()`
returns `false` on a real device and every gate above silently reports
"not mobile": the camera step never renders, biometric unlock is never offered,
the Keychain custodian is never bound, and the boot hook that recreates
`storage/framework` and re-points the session/cache/view config never runs. The
symptom is not an error — it is a device that quietly behaves like a desktop.
`UserDataPathService`'s private `platformSignal()` therefore reads `$_SERVER`
and `$_ENV` as well, and `isMobileRuntime()` keeps the structural fallback (the
sibling `persisted_data` directory, which only NativePHP mobile provisions) for
the config-load window where even those are not yet populated.

Gates that branch on *which* shell — `Internal\Http\Middleware\ClientSideRedirect`
and `Sync`'s `MulticastMdnsQuery` — ask `UserDataPathService::platform()`
instead, which returns a `Modules\Core\Public\Enums\MobilePlatform` case and
answers the decision through `needsClientSideRedirect()` rather than comparing
to `'android'`. A platform NativePHP names but that enum does not model reads as
`null` there, so a rename of the value cannot silently flip a shell gate the
other way.

The one gate with more than one caller asks through an object rather than the
enum: every screen that produces a file — `Core`'s `EncryptedBackupDownload`,
`Auth`'s `RecoveryCodesDisplay` and `ManageUserPage`, `Tax`'s `TaxPage`,
`Reports`' `ReportBuilder`, `Import`'s `AliasesSettingsPage` and this module's
`MobileImportBootstrap` — calls
`Public\Services\ShareSheetExport::replacesWebViewDownload()`, which is the
only place `savesWebViewDownloads()` is read. See
[a download the shell drops](a-download-the-shell-drops.md).

**The scan preview is in-page, not a separate activity.** The scanner
plugin's own surface is a full-screen `ScannerActivity`, which over the
pairing page reads as the app navigating somewhere else. The viewfinder frame
therefore hosts the WebView's own camera (`getUserMedia`) and decodes with the
platform `BarcodeDetector` — a browser API, never a bundled decode library —
in the `beatraxInlineScanner` Alpine component. The plugin's activity remains
the fallback via `QrScanBridge::open()` for any runtime offering neither, and
both paths funnel into the same `submitCode()`.

Android WebView refuses `getUserMedia` unless its `WebChromeClient` overrides
`onPermissionRequest`, and the generated shell does not. It never denies
either — the promise simply never settles — so the failure looks like a hang.
`scripts/nativephp_grant_webview_camera.php` injects that override, admitting
video capture only and denying every other resource. `native:install`
regenerates the shell, so the patch runs from the mobile root's
`post-update-cmd` (and `composer native:patch` on demand); it is idempotent
and fails loudly if the anchor moves, because a silently unpatched shell
degrades to exactly the behaviour it exists to fix.

The camera is never opened on load — only from the button. A preview that
starts on page render is a privacy surprise, and the user has not asked to
scan anything at that point. Decoding runs on `requestAnimationFrame`, so it
stops when the page is backgrounded rather than holding the camera open
behind a backgrounded finance app.

**The generated shell needs twenty-one patches, applied from the mobile root's
`post-update-cmd`.** The authoritative list is
`Modules\Mobile\Internal\Boot\NativeBuildPatches::SCRIPTS`, which
`scripts/nativephp_patch_all.php` mirrors; `native:install` regenerates the
native trees, so none is hand-edited; all are idempotent and fail loudly if
their anchor moves. Seven of them carry reasoning a reader cannot reconstruct
from the script:

- `nativephp_grant_webview_camera.php` adds the missing `onPermissionRequest`
  override so the in-page scanner can obtain a camera. Video capture only.
- `nativephp_android_file_chooser.php` adds the missing `onShowFileChooser`
  override and the matching activity-result plumbing. Android's default
  implementation returns false and shows nothing at all — no picker, no error
  — so every `<input type="file">` in the app was inert. That is not cosmetic:
  `/` redirects to `/imports/new` while the device holds no transactions, and
  every wizard drop-zone uses the same control, so a freshly installed phone
  could not take in a statement or reach the dashboard by any route.
- `nativephp_keep_webview_cookies.php` removes the `clearAllCookies()` call
  from `MainActivity.initializeEnvironment()`. It ran on every process start
  and took the Laravel session cookie with it, so each cold launch — and
  Android kills a backgrounded process routinely — landed on `/login` despite
  a valid session row on-device. The app-lock, not cookie lifetime, is the
  security boundary here. `clearAllCookies()` itself is left in place for
  callers that genuinely want a clean jar.
- `nativephp_android_single_content_type.php` makes the generated WebView
  client read `Content-Type` case-insensitively and supply it through the
  `WebResourceResponse` constructor alone. Its header map was case-sensitive
  while the bridge writes lowercase names, so the `mimeType` argument fell back
  to a literal `text/html` on every route; and Chromium appends the header map
  on top of the constructor's own `Content-Type`, so naming the field in both
  places emitted it twice. Every response in the Android shell carried two
  values against the iPhone's one, with `nosniff` telling the reader to believe
  the wrong first one:
  [android-content-type-is-emitted-once.md](android-content-type-is-emitted-once.md).
- `nativephp_ios_request_body_stream.php` drains `request.httpBodyStream` when
  `request.httpBody` is nil. WebKit populates the latter only for simple string
  bodies; a FormData or Blob body — every file upload — arrives as a stream, and
  the generated handler read only `httpBody`. PHP saw a `multipart/form-data`
  Content-Type with its boundary, a null CONTENT_LENGTH and a zero-byte
  `php://input`, so `$_FILES` was empty on every upload while ordinary Livewire
  round trips, which post strings, worked perfectly.
- `nativephp_ios_download_delegate.php` answers a download navigation with
  `.download` and adds the `WKDownloadDelegate` the shell never had. Without it
  a blob: URL from an `<a download>` fell through to `decisionHandler(.allow)`
  and the WebView *navigated onto the blob*: the app replaced by its own raw
  bytes, no chrome, no back gesture, nothing saved, and only a force-quit out.
  On the recovery-codes screen that destroyed one-time data.
- `nativephp_ios_theme_native_shell.php` hands the iOS shell the app's own
  theme, which it had no way to learn: there was no `UIStatusBarStyle` in
  `Info.plist` and no `preferredColorScheme` anywhere in the shell, so the
  status-bar clock and the home indicator took their polarity from the PHONE's
  theme and sat on the app's own background at roughly 1.05:1 whenever the two
  disagreed. The same file owns the other half — a WKWebView paints its own
  opaque white from the moment a document is torn down until the next one
  paints, so every navigation on a dark shell flashed white. The page reports
  its resolved theme on a `WKScriptMessageHandler` and the shell uses that one
  answer for both. Android splits the same work between
  `nativephp_theme_native_shell.php` and
  `nativephp_android_system_bar_appearance.php`; on iOS they are one file and
  one signal, and splitting them would let a tree exist where one patch applied
  and the other did not — a build failure rather than a cosmetic bug.

  The reported payload carries the reader's CHOICE as well as the resolved
  theme, and that is the load-bearing part. `preferredColorScheme` reaches the
  status bar by overriding the WINDOW's interface style, and a WKWebView inside
  an overridden window reports the override to `prefers-color-scheme` — where a
  reader on `system` resolves their theme. So the shell overrides nothing while
  they follow the phone, and the flag is what tells it.

**When each patch is present, and when it is not.** Worth stating plainly,
because the failure is silent — an unpatched shell builds, installs and runs,
and only the patched behaviour is missing:

- `composer update` regenerates the trees via `native:install` and then
  re-applies every one of them, because they follow it in `post-update-cmd`.
  Net effect: patched.
- `php artisan native:run android|ios` (and `native:build`) does **not**
  regenerate the tree, so patches already in it survive the build. A patch
  script added after the last `composer update` is therefore **not** in the
  build — writing the script is not applying it: the file-chooser fix was
  written, committed, built and installed, and the picker was still inert
  because nothing had run the script.
- `php artisan native:install` on its own regenerates the trees and drops every
  one of them.

`composer native:patch` runs them all on demand, and is the recovery for the
last case — and the step to run the first time a new patch script lands.

`NativeBuildPatches` re-applies the whole set immediately before `native:run` /
`native:build`, so a regenerated tree cannot ship without them. Three are listed
in its `REQUIRED_SCRIPTS` as well — the privacy manifest, the export compliance
key, and the App Store category — because a cosmetic patch that fails is visible
on the device while those three are invisible until App Store Connect refuses
the upload.

### Signed URLs cannot be absolute on iOS

The iOS shell serves the app from `php://127.0.0.1` and installs
`Native\Mobile\Support\Ios\PhpUrlGenerator`, whose `formatScheme()` answers
`php://` for every absolute URL Laravel writes. The verifier cannot follow it:
`hasValidSignature()` rebuilds the URL from `Request::url()`, which is
Symfony's and can only ever say `http://`. Measured on the device, the same
request reports `URL::to('/') === 'php://127.0.0.1'` and
`$request->root() === 'http://127.0.0.1'`, so the two halves hash different
strings and **every absolutely-signed URL fails its own check**.

Livewire's temporary-upload URL was the one that mattered: it answered 401 from
`abort_unless(request()->hasValidSignature(), 401)`, so no statement could be
imported and a fresh install could not reach the dashboard by any route.
`BridgeSignedUploadUrl` replaces Livewire's generator through its facade,
signing against the root the incoming request will present and returning the
URL relative so the WebView still resolves it on the `php://` origin — the
browser and the verifier need different halves of the same URL. Off that shell
it defers to the ordinary absolute URL, keyed on the scheme actually in use
rather than on the platform.

`livewire.preview-file` has the same shape and is left alone: nothing in the
app previews an upload (no control accepts an image, and nothing calls
`temporaryUrl()`), so it is unreachable here.

### A file cannot cross as multipart, so it crosses as base64

Fixing the signature produced a `200` and an empty upload. WebKit hands a
custom scheme handler **only string request bodies**: a FormData or Blob body
arrives as neither `httpBody` nor `httpBodyStream`. Measured against a probe
route in the running app:

| request body | bytes PHP received |
|---|---|
| `application/x-www-form-urlencoded` string | 15 of 15 |
| `application/json` string | 7 of 7 |
| `Blob` | 0 |
| `FormData` | 0 |

And a hand-built multipart body sent *as a string* reached `php://input` whole
while `$_FILES`, `$_POST` and `allFiles()` stayed empty — this runtime has no
SAPI doing rfc1867 parsing, so multipart cannot work here even when it arrives.

So the file crosses base64-encoded in a JSON body.
`resources/js/mobile-upload.js` intercepts Livewire's upload XHR and re-sends
it; `EncodedUploadTransport` middleware rebuilds the bytes and puts a real
`UploadedFile` into `$request->files` before Livewire's own controller reads
one. **It is a transport, not a second import path**: the controller, the
temporary disk, the preview, the verdicts and the confirm step are the same
code on every platform, and neither end knows which way the bytes came.

The client sends the byte count and a SHA-256 with each file and the server
verifies both, refusing anything that did not arrive whole or unchanged — a
truncated statement would import as a wrong number rather than fail. Base64
being ASCII is also what makes the format restriction go away rather than move:
a PDF crosses as safely as a CSV, which the string-typed bridge body could
never have done.

### The decode is streamed, and the advertised maximum is enforced

Encoding costs memory on both ends, and the server end had none of it bounded.
`base64_decode()` on the whole payload put a third full copy of the file beside
the raw request body and Laravel's parsed copy of the base64 string — about
four times the attached file live at once. Against the phone's 128 MB (the
interpreter's compiled default: NativePHP's Android shell writes a `php.ini`
with no `memory_limit` in it, and Beatrax patches in only the two upload
directives plus `zend.exception_ignore_args`), and with a routed `web` request
costing ~10 MB over a bare
harness, **20 MB — the size the product advertises in three places — was a
fatal**, not a 422. Memory exhaustion is `E_ERROR`: no response, no log entry,
no retry, nothing the reader could act on.

The decode now runs a slice at a time straight onto the staging file, so the
peak no longer tracks the payload: 20 MB went from 118.9 MB peak (and a fatal
once the routed baseline is counted) to 98.3 MB. The alphabet and quantum
alignment are validated in one allocation-free pass first, which is what lets
each slice decode without re-validating and keeps "refuses rather than repairs"
intact — the SHA-256 is still computed over what was actually written.

Two bounds sit in front of it. The **declared** size is checked against
`EncodedUploadTransport::MAX_BYTES` before a byte is decoded, because a limit
enforced by running out of memory is not a limit. And the *number* of files is
capped: `post_max_size` bounded the bytes and nothing bounded the count, so a
body of ten thousand one-byte entries was ten thousand staged temp files.

`MAX_BYTES` is the fourth place the same number is written — the other three
are `resources/js/mobile-upload.js`, which refuses the pick, and the two
shells' `php.ini` patches, which decide whether the request arrives at all.
None of the four can see the others, so `EncodedUploadTransportTest` reads all
four and fails on a drift: a body the client sends and the transport refuses is
a wasted upload, and one the shell drops never arrives to be refused.

Both halves are inert off that shell: the shim checks `location.protocol` and
does nothing on http/https, and the middleware acts only on its own marker
field, which an ordinary request never carries.

### The runtime is persistent, and request headers leak between requests

The embedded PHP process serves many requests. Its superglobals are not fully
rebuilt per request, so **`$_SERVER['HTTP_*']` set by one request is still
readable by every request that follows it in the same worker.**

Measured on device (2026-08-17): a Livewire POST sets `HTTP_X_LIVEWIRE`, and
from then on every ordinary page load in that app session sees it too. The
Kotlin side is innocent and its own log proves it — `PHPRequestHandler: 📤
Final request headers` for the offending GET contains no `X-Livewire` at all.
The leak is on the PHP side of the bridge.

**Never branch on a request header to decide what KIND of request this is.**
`AppLockMiddleware` did, in three places, and all three misbehaved: a locked
page load was answered with a Livewire JSON body and painted the raw payload on
screen as the whole page; `last_activity_at` stopped being refreshed by
navigation after the first Livewire request of a session, locking the reader out
mid-use; and no page was ever remembered to return to after an unlock, so every
unlock landed on the dashboard. Use something resolved per request instead —
the router's current route name is the obvious one.

**This class of bug is invisible to local testing.** PHP-FPM and `artisan
serve` build superglobals fresh per request, so the header never leaks there
and the same code is correct on a laptop, in CI, and in every feature test.
Anyone reproducing on a desktop will conclude the code is fine. Only the device
shows it, which is why the guard is a test that sends a *stale* header at an
ordinary page request and asserts it is still treated as one.

### The session store outlives the request that filled it

The same shape, one layer up. `session.store` is a container singleton and
`Manager::driver()` memoises what it builds, so a single `Illuminate\Session\Store`
object serves every request in the process. `StartSession` does not replace it —
it calls `setId()` on it with the incoming cookie and then `start()`.

That would be harmless if starting a session replaced its contents. It does not:

```php
// Illuminate\Session\Store::loadSession()
$this->attributes = array_replace($this->attributes, $this->readFromHandler());
```

`array_replace`, not assignment — and `save()` writes the attributes out and
clears the started flag without clearing the attributes. So on this runtime the
session a request sees is the *union* of the row storage holds for the incoming
id and whatever the previous request left in memory. Two consequences, both
demonstrated in `ForgetStaleSessionBetweenRequestsTest`:

- a request arriving under a **different** session id is served the previous
  id's data, including `login_web_*` and the app lock's `beatrax_locked` and
  `beatrax_data_key`. A fresh session is authenticated and unlocked;
- **destroying a session does not destroy it.** `ResetPasswordAction` deletes
  every `sessions` row for the user and `ChangePasswordPage` deletes every row
  but the caller's, both to sever a session after a suspected compromise. The
  row goes, `readFromHandler()` returns `[]`, and `array_replace` merges nothing
  over a session that is still entirely in memory.

The seam is `ForgetStaleSessionBetweenRequests`, prepended at this root so it
runs in the global stack before the `web` group reaches `StartSession`. It
empties the store when the store is not started. `isStarted()` is the whole
discriminator and is load-bearing in both directions: `Store::save()` ends a
request cycle by clearing that flag, which is what makes leftover attributes
recognisable as stale; and a session filled *before* a request — which is how
`withSession()` signs a caller in — is started, so emptying unconditionally
would blank every seeded session in this root's own suite.

That protection covers the *first* request only. `save()` clears the started
flag at the end of it, so the **second** request of a mobile-root feature test
meets an emptied store, and everything the harness seeded is gone unless the
test carries the session forward the way a WebView does: read
`session()->getId()` after the first request, pass it as
`config('session.cookie')` through `withCookie()`, and add `withCredentials()`
for `getJson()` — Laravel omits cookies from JSON requests without it. The same
test at the repo root passes without any of this, which is what makes a
mobile-root-only failure here look like a defect in the code under test.
`TheRecoveryCodesDoNotOutliveTheScreenThatShowsThemTest` is the worked example.

Nothing here rebuilds the session driver. `forgetGuards()` does, and rebuilding
registers a rebound callback the container never prunes, which on a process that
never restarts grows without bound.

**Off the device this is invisible for the same reason as the header leak.**
PHP-FPM and `php -S` — which is what the desktop shell runs — build a fresh
container per request, so the store is constructed and destroyed inside one
request and the merge can never see a second one.

The `post-update-cmd` invocation is `native:install --with-icu --quiet`, and both
flags are load-bearing. v4 inverted `--force`: overwriting the generated tree is
now the default (`--no-force` opts out) and `--force` means "re-download the PHP
binaries", which would add a ~45MB fetch to every `composer update`. v4 also
dropped `--publish` outright — passing it aborts the command, and with it the two
patches below. `--with-icu` is what keeps `ext-intl` in the bundled PHP
binaries; the money formatting the whole ledger renders through needs it, and its
absence shows up only on-device.

### `--with-icu` ships ICU code, not ICU locale data

`--with-icu` is not the whole story. The flag selects the `-icu` variant of the
prebuilt PHP binaries and `nativephp.lock` records `"icu": true`, so `ext-intl`
loads and `INTL_ICU_VERSION` answers — but the data package inside those binaries
is filtered to **English only**. Read the shipped archive to see it:

```
strings mobile-app/nativephp/android/app/src/main/staticLibs/arm64-v8a/libicudata.a \
  | grep -E '^icudt[0-9]+l/' | sort -u
```

At ICU 77 that lists 250 entries: `root`, `en`, 130 `en_XX` regionals, 114
`curr/en_XX`, and the shared `.icu`/`.nrm` blobs. There is no `nl`, and no other
language of the twenty-six the UI ships. `libicudata.a` is 2.3MB where a full ICU
data package is ~30MB.

The consequence: `new NumberFormatter($locale, …)` **throws for every locale
except English**, as `IntlException` when intl error-exceptions are on and as
`ValueError` from the constructor otherwise. That reaches the product through two
seams, both of which now catch it and render from marks the repo carries itself
(`Locale::groupMark()` / `Locale::decimalMark()`):

- `Money::format()` — every rendered amount. It asks ICU for the **reader's**
  locale ([money formatting](../ledger/money-formatting.md)); the currency
  chooses only the symbol. So on device it throws for all twenty-five
  non-English UI languages and works in English, whatever the amount is
  denominated in. The dashboard reading `EUR 3850.00` beside a hand-rolled
  Dutch `€ 1.237,89` was the older currency-anchored rule failing for EUR
  alone; under the reader's locale two amounts on one page can no longer
  disagree about notation, and a Dutch reader takes this fallback for every
  one of them.
- `Fmt::number()` — counts and percentages. Threw for all twenty-five non-English
  UI languages; only `/rules`, `/inboxes` and `/drift/watch` call it, so it had
  not been noticed.

Direct `Illuminate\Support\Number::currency()` calls have no such guard and 500'd
the page outright — `tests/Contracts/CurrencyRendersThroughMoneyArchTest` now
keeps them out of the tree.

**Do not delete the guards on the assumption that fuller ICU data can be
bundled.** The static libraries arrive inside a zip that `native:install`
downloads into `mobile-app/nativephp/`, which is gitignored and regenerated —
anything injected there is unreproducible and gone at the next install, and this
repository has no step that could re-apply it. Replacing the data set is a change
to the upstream NativePHP PHP build. The guards are also cheap to keep honest:
`Modules/Ledger/tests/Unit/MoneyFormatTest` asserts the ICU-less output is byte
for byte what ICU produces on a host that has the data, so if the data ever does
arrive nothing about the rendering changes.

## Navigation

**Sidebar entries no longer carry `wire:navigate`, and this is a mitigation
rather than a fix.** After a handful of drawer navigations the app hung on
device — stuck on the page it was leaving, with nothing recovering it short of
a relaunch. Dropping the attribute from the sidebar stops the hang. The root
cause is not yet understood, so nothing here explains *why* repeated
`wire:navigate` swaps wedge the WebView; it only records that they do.

That trade is real and was taken deliberately. Every sidebar tap is once again
a full page load — a Laravel boot through the persistent runtime, the layout's
nine Livewire mounts, and a re-parse of the whole asset bundle. That cost is
what the attribute was added to avoid, and it is the reason the phone felt slow
before. An app that is slow on every tap beats one that stops responding on the
fifth.

The mitigation is partial: eighteen `wire:navigate` attributes remain across
nine views, including `mobile-top-bar.blade.php`, which is itself a mobile-only
surface. If the hang is a property of the attribute rather than of the sidebar,
it is still reachable from all of them. Anyone touching mobile navigation
should treat that as the open question, not as settled ground.

The drawer's explicit close on `livewire:navigated` (`resources/js/app.js`)
stays. It is inert for sidebar taps now that those are full loads — which reset
the Alpine store anyway — and still required for the surfaces that kept the
attribute.

## First launch and route gating

`MobileFirstLaunchBootstrap` is the mobile mirror of the desktop
`FirstLaunchBootstrap`, wired at the mobile root's own `->booted()` hook
instead of a middleware attach point. It runs the framework `Migrator`
against whichever SQLite connection is currently configured — never a
shelled-out SQLite binary, which is absent on-device — and gates
`runPendingMigrations()` behind `hasPendingMigrations()` so repeated app
launches never re-run the migrator once the on-device database is caught
up. `isFreshInstall()` (an empty `users` table) is the single signal
`MobileEnsureDatabaseReady` uses to redirect an unauthenticated,
zero-account device to `mobile.welcome` instead of the desktop-shaped
`/login`. The exempt route-name prefix list on that middleware
(`mobile.welcome`, `signup`, `mobile.import`, `mobile.pair`,
`mobile.setup`, `setup`, the web manifest/icon routes, plus the Livewire
AJAX update endpoint) is deliberately name-prefix-matched so a fresh
device can reach the welcome screen, the signup ceremony, or the
import-bootstrap flow without looping back to itself.

### The migrations only a phone ever runs

The two platforms execute different migration sets. `artisan migrate` calls
`MigrateCommand::prepareDatabase()`, which loads
`database/schema/sqlite-schema.sql` and then runs only what that dump has not
already recorded. `MobileFirstLaunchBootstrap` calls `Migrator->run()`
directly, and the `Migrator` never loads a dump — the loader shells out to a
`sqlite3` binary the phone does not carry.

Measured 2026-09-04 at both Composer roots:

| | loads the dump | migrations run | tables |
|---|---|---|---|
| desktop / CI (`artisan migrate`) | yes | 104 replayed, 105 run | 102 |
| repo root, phone path | no | 209 | 102 |
| `mobile-app/` root, phone path | no | 210 | 103 |

The extra one at the mobile root is
`nativephp/mobile-local-notifications`'s own migration, dated `2026_03_29`, so
the phone runs it *first* — before `users` exists.

**104 of those migrations are code no desktop and no CI job has ever
executed.** One bug in that stretch reached every new install on 2026-08-29: a
foreign key SQLite cannot add in place, a table rebuild whose
`insert into "__temp__transactions" … from "transactions"` read as a user
write, and `ForgetNavCountsOnWrite` reaching a `cache` table a later migration
had not created yet. The run died on migration eighteen of two hundred and
nine and the app opened on thirteen tables of a hundred and two. SQLite
reports `supportsSchemaTransactions()` false, so the half-applied run stays
behind and every retry fails on `duplicate column name` — reinstall is the
only exit.

`Modules/Mobile/tests/Feature/TheMigrationsOnlyAPhoneEverRunsTest.php` runs
that chain the way a phone does and proves the schema it ends on is whole.
`tests/Support/first-launch-schema-probe.php` builds one schema per process —
a process of its own because the phone migrates onto the *default* connection
with a *database* cache store, and a test that swaps the default connection
inside a phpunit worker leaves `RefreshDatabase` holding one that no longer
exists. Both probes boot the same root, on a real SQLite file, differing in a
single environment variable: whether the dump is loaded before the migrator
runs. Anything the two schemas disagree on can therefore only have come from
the migrations in between. It compares tables, and per table every column with
its type, nullability, default and primary-key flag, every index with its
columns and uniqueness, and every foreign key with its delete rule.

It runs on every pull request from both roots with no workflow change: the
file lives in the `Mobile` testsuite, which `quality-shard` already picks up
through `.github/scripts/shard-testsuites.py` and which `mobile-quality`
already names. Two subprocesses cost about three seconds.

### A half-built schema outranks every route exemption

The exemption list above answers "no user account exists yet", which is a
different question from "the tables exist yet". `MobileEnsureDatabaseReady`
therefore asks `SchemaCompletionMarker::isRaised()` *ahead* of it, and the
only routes that survive a raised marker are the incomplete screen itself, the
Livewire endpoints its retry button needs, and the brand artefacts the lock
layout renders. Behind the exemption list, as it was first written, the
welcome screen still opened over a half-built schema and signup was one tap
further — the 2026-08-29 experience exactly.

The mobile root's `->booted()` hook raises the marker in its own `catch` as
well. `runPendingMigrations()` raises it in a `finally` of its own, but the
container resolution, `ensurePluginViewPaths()` and `hasPendingMigrations()`
all run before it and land in the same catch, and a throw from any of them
left the app opening on whatever schema it had with nothing but a log line.

`MobileWelcomeScreen` renders on a genuinely fresh device with two CTAs:
"Create account" (into the existing signup ceremony) and "Import from
another device" (into `MobileImportBootstrap`). Once an account exists,
any later landing on the welcome route bounces to the dashboard rather
than stranding a set-up user on the first-run screen.

## Fresh-device import bootstrap

`MobileImportBootstrap` is the fresh-device local-identity bootstrap the
welcome screen's "Import from another device" CTA leads into. Its step
machine is `collect_pin` (username/password/PIN form) -> `provisioning_failed`
(idempotent-safe retry, only reached if a later step throws after signup
already committed) -> `recovery_codes` -> a plain link into
`mobile.pair?mode=import`. Those three names are spelled once, in
`Internal\Identity\ImportBootstrapStep` — a vocabulary of its own, sharing
nothing with the pairing ceremony this wizard hands off to. On submit, in
strict order: `SignupAction`
runs the same first-user ceremony `/signup` uses (guarded to
`User::count() === 0`); `MobileLockGateway::enableAppLock()` mints the
app-lock key and leaves the session unlocked; `PairingGateway::
enableSyncIdentityWithoutEpoch()` enables sync identity without minting a
GDK epoch, so the phone's keyring starts empty and the desktop's
delivered epoch ids install without colliding against the epoch
control handler's idempotency guard.

The originally-submitted PIN/password are stashed server-side in the
session (never rendered to the client) for the lifetime of the
`provisioning_failed` retry window, and forgotten the instant
provisioning actually succeeds. The public properties holding them are
emptied at the same moment, because a public Livewire property rides the
serialized wire-snapshot payload to the browser on every later render.
While the form is still being filled those properties necessarily hold
what the reader typed, exactly as every other password screen in the app
does — a rejected submit deliberately leaves them alone (below).
The screen's own PIN gate is the provisioner's floor — six to ten digits,
nothing else — because it runs *before* `SignupAction` commits and the floor
runs after. A gate that admitted what the floor refuses made the account and
then could not finish the device: eleven digits (the input has no `maxlength`)
landed on `provisioning_failed` with the lock disabled, no self device row,
and a stash the retry replayed to the same refusal forever. Provisioning tells
a refused credential apart from a failed step — `DeviceProvisioningOutcome` —
so the screen names the rule instead of offering a retry that is arithmetic on
a fixed answer, and a fresh mount with the stash still present returns to the
failure rather than walking past it into the codes.

The retry path never re-runs `SignupAction` (the account already exists)
and reads only the session stash, never the emptied public properties —
reading those was a real bug that either permanently stranded the device
in `provisioning_failed` or minted the app-lock key from an empty
passphrase. `MobileImportIntentGate` durably marks the user as an import
device before either provisioning step, backing up the pairing screen's
own defense-in-depth echo of the same signal.

**The one-time recovery-codes display, and the way back onto it.** The
control that leaves the `recovery_codes` step is an ordinary link, not a
`wire:click`: a Livewire round trip from this screen has 419'd on device
and taken the only copy of the codes with it, and a GET carries no CSRF
token to expire. Nothing therefore reports the exit server-side, so the
render that shows the codes writes `mobile.import.recovery_codes_shown`
into the session itself. `mount()` enters `recovery_codes` only while the
plaintext codes are in the session *and* that marker is absent; a later
mount holding both — cancelling out of pairing walks back onto this
route — forgets the plaintext and falls through to the
`alreadyProvisioned` screen. Before the marker existed, cancelling
reprinted all ten codes under the line promising they are shown once,
with the confirmation checkbox reset. The codes themselves are bcrypt
digests in `user_recovery_codes` and the session payload is an encrypted
Laravel envelope, so the fault was a false promise, never plaintext at
rest. `ExportRecoveryCodes` reads the same session key, which
is why the display marks rather than consumes: a share-sheet export
fired from the screen must still find the codes.

That marker answers "were they shown"; it never answered "are they
still owed". Nothing on the way out cleared the plaintext, so a reader
who followed the link — or simply went elsewhere — left it in the
session for the rest of that session's life. The codes now end at the
first page load that is not the ceremony saying so, which is a rule
neither the link nor the reader can step around:
[the pending recovery codes live one request at a time](../auth/pending-recovery-codes-lifetime.md).

**Form validation.** Every broken rule is reported at once and scoped to
its own field, so the message renders under the box it describes and that
control carries `aria-invalid`. The form-level line is left for a
rejection that belongs to no box — `SignupAction` refusing under `signup`
because the device gained an owner mid-submit. Placing a `SignupAction`
rejection on its box is `Core`'s `ReportsFieldRejections` trait, shared
with `SignupPage`: the component supplies `FIELD_KEYS` and the lang key
the form-level line falls back to. A rejected submit empties nothing:
clearing the password pair while keeping the PIN meant a mistyped PIN
cost a 12-character passphrase retyped on a phone keyboard. The password
pair carries the live requirement checklist through the shared
`x-core::password-requirements` component, the same one `SignupPage`
renders, reading the same bindings the server validates.

## Biometric-primary unlock

`MobileLockScreen` is structurally identical to the Auth module's
`LockScreen`, reached via the narrow `MobileLockGateway` Public seam
(this module never imports `Modules\Auth\Internal\*` directly). The only
behavioral difference is the biometric trigger: instead of the browser
WebAuthn round-trip, `biometricPrompt()` calls `BiometricUnlockBridge::
prompt()` (native, bool-only) directly. A `true` result is only ever used
to read through the existing `AppLockKeyService::release()` gate:

- **Warm re-lock** (session still holds the data key): the bridge's
  bool prompt confirms the device holder before letting them back
  through.
- **Cold start** (no session key): biometric success alone cannot
  release a key that was never cached. Enrollment and PIN-floor gates
  are re-checked at the unlock boundary (not just in `mount()`'s
  visibility flag, since every Livewire method is client-invokable
  regardless of what rendered) — an unenrolled or floor-overdue device
  falls through silently to the always-visible PIN pad. When both gates
  pass, `BiometricKeyVault::recover()` is the biometric gate itself (no
  redundant bridge prompt): it yields a key only after the OS releases
  the enclave entry for a live biometric. On Android, recovery is
  asynchronous — the native prompt authenticates and stashes the
  decrypted blob in a transient native slot, then emits a bare
  `cold-start-recovered` signal (no key over the JS bridge);
  `onColdStartRecovered()` collects it PHP-side via
  `completePendingRecover()`.

A false/aborted biometric never reaches `AppLockKeyService::release()` in
either path — the PIN pad is always the fallback of last resort.

`BiometricKeyVault` stores a biometric-wrapped copy of the data key in an
enclave-gated entry (iOS `SecAccessControl(.biometryCurrentSet)`, Android
Keystore `setUserAuthenticationRequired`) via the first-party
`beatrax/mobile-biometric-vault` plugin.

That plugin lives in `mobile-app/nativephp-plugins/biometric-vault` and reaches
the build through three separate steps, all of which are required and none of
which implies the others: a Composer `path` repository plus a `require` entry
(without it the `BiometricVault` facade is not autoloadable at all, and every
`BiometricKeyVault` guard fails its `class_exists()` check and reports the
vault as simply unavailable), an entry in `NativeServiceProvider::plugins()`
(NativePHP treats that list as an opt-in security boundary — omitted, the
Swift/Kotlin sources never compile in), and `native:plugin:register`. It
carries an explicit `version` in its own `composer.json` because a path
package otherwise takes the *current branch name* as its version, which pins
the lockfile to whatever branch it was last resolved on.

### The vault bridge's own contract

`Beatrax\BiometricVault\BiometricVault` mirrors
`nativephp/mobile-secure-storage`'s PHP shape — a thin wrapper over
`nativephp_call` — with one difference the shape does not show: the entry is
enclave-bound, so `get()` yields the value only after a fresh Face ID or
Touch ID.

**The two platforms resolve `get()` differently, and a caller has to handle
both.** iOS resolves it synchronously, because the keychain blocks on the
biometric sheet, so the value comes back inline. Android is asynchronous
(`BiometricPrompt`), so `get()` returns `['async' => true]` and the value
arrives later on the `BiometricVault.Recovered` event. Code written against one
platform's timing does nothing at all on the other, silently.

An empty result from the bridge means it threw — an auth error other than
cancel, a locked device, something transient — and is reported as `failed`,
never as `missing`. The distinction is security-relevant: `missing` means
nothing is enrolled, `failed` means something is enrolled and authentication
did not succeed. Collapsing the two would let a failed unlock read as a device
that never enrolled.

**`pollRecovered()` depends on a native contract, not just a PHP one.** It
reads and consumes the transient blob the Android `BiometricPrompt` callback
stashed after a successful decrypt, and answers null when nothing is pending.
No biometric prompt happens there — the key never crosses the JS bridge in the
prompt result, so PHP collects it after the fact. The native `PollRecovered`
must therefore be single-shot, deleting the transient slot on read, *and* must
clear it when the app backgrounds or re-locks. Without both, a stashed blob can
be replayed by a later spoofed `cold-start-recovered` dispatch and admit a
session with no fresh biometric behind it. The PHP-side gates
(`isColdStartEnrolled` plus the PIN floor) defend in depth, but enclave
freshness rests entirely on consume-on-read.

Enrollment is PIN-rooted:
`ColdStartEnrollmentService::enroll()` re-verifies the PIN to obtain the
live data key, wraps it into the enclave via the vault, and records the
enrollment flag — every failure path (vault unavailable, wrong PIN,
native store failure) leaves nothing enrolled. `ClearColdStartVaultOnKeyRotation`
listens for `AppLockPassphraseChanged` and clears the enclave entry only
when the underlying data key actually rotated (`oldKek !== newKek`) — a
plain PIN change keeps the same data key and is a no-op, since a GDK
epoch rotation re-wraps under the same key and must not invalidate the
enclave blob.

Unlike the desktop custodian (whose handle is self-contained ciphertext),
`SecureStorageKeyCustodian` writes the raw key out of the session into
the Keychain/Keystore under a per-user slot name
(`beatrax.session.data_key.{userId}`), and returns that slot name itself
as the session's key handle — so `forget()` must delete the Keychain
entry on lock. The per-user scoping means that if two users are ever both
unlocked on the same device, one `store()` cannot overwrite the other's
key. If the entry is missing on read (device restore, OS eviction),
`read()` returns null — the caller's `AppLockKeyService::release()` then
falls back to a fresh PIN unlock rather than releasing the slot name as
if it were the key; the PIN remains the cryptographic root regardless.

`BiometricUnlockBridge` and `SecureStorageKeyCustodian` are the only
places in the codebase that call their respective native facades
(`Native\Mobile\Facades\Biometrics` / `SecureStorage`). Both guard on
`class_exists()` plus the on-device runtime signal
(`getenv('NATIVEPHP_PLATFORM')`), degrade to safe pass-through off-device,
and are intentionally not `final` so on-device tests can subclass and
override the native seam methods (the real facades are unreachable from
the repo-root toolchain). `SecureStorageKeyCustodian` moves the unlocked
data key out of the Laravel session and into the iOS Keychain / Android
Keystore under a per-user slot name, returning the slot name itself as
the session's key handle — `forget()` must therefore delete the Keychain
entry on lock, unlike the desktop custodian whose handle is
self-contained ciphertext.

## Camera-first pairing

`MobilePairingScan` extends the existing `PairingFlowModal` step machine
into a standalone mobile page (a separate, structurally analogous class,
not literal inheritance, since the Sync module's internal Livewire
components are off-limits to this module). Its step machine is `scan`
(default, camera viewfinder) | `enter_code` (word-code fallback) ->
`confirm` (safety-number) -> `success`. The camera path and the typed
word-code path both funnel into the identical `confirmMatch()` trust
gate: the safety-number is derived independently on both peers from both
stored public keys, and `device_registry.confirmed_at` is set only after
the underlying both-confirm transition — no new trust mechanism is
introduced. `QrScanBridge` is the only place that calls
`Native\Mobile\Facades\Scanner`; it decodes the `beatrax://pair?...`
envelope to recover the same raw token a word-code decodes to, and hands
it unmodified to the same `PairingGateway::acceptToken()` validation the
word-code path uses.

Those step names are spelled once, in the Sync module's public
`PairingWizardStep` enum this page shares with the desktop modal. `$step` and
`$entryStep` stay `string` properties for the reason the modal's own section in
the [Sync architecture](../sync/architecture.md) sets out, and are read back
through `tryFrom()`. `$entryStep` is narrowed further: it
carries no `#[Locked]`, and a reset honouring a value past the two entry arms
would walk a cancelled attempt onto a screen it never reached, so only `scan`
and `enter_code` are accepted there.

**Cross-device handshake.** The scanned QR carries the initiator's public
identity
(`QrScanBridge::extractIdentity()`). `submitCode()` seeds a local
`pairing_tokens` row from that identity before accepting (the fresh,
separate device database otherwise has no local pending row to accept
against), auto-configures this device's relay transport from any
`relay`/`rpin` query params the QR carries, and sends a signed
`PAIR_RESPONDER_ACCEPT` frame to the desktop's own separate database over
the relay — best-effort, since a delivery failure never dead-ends the
confirm step already rendered. `checkPairingState()`'s poll re-emits that
responder-accept idempotently while still on the confirm step and not
yet confirmed, so a single lost relay delivery self-heals rather than
stranding the ceremony. `confirmMatch()` sends this device's own signed
`PAIR_CONFIRM` to the bound initiator peer, and the poll re-emits it while the
row says this side confirmed — read from the row, not from the screen's
`awaitingPeer` flag, which a refused tap also sets. A refused confirmation is
rendered here as it is on the desktop: the words are re-derived from what the
row now binds and `pairing.errors.safety_number_changed` says why.

**Typed codes.** A word-code carries the token alone, so before seeding,
`submitCode()` asks `PairingGateway::discoverInitiatorOnLan()` for the public
identity the code cannot carry: it browses `_beatrax-sync._tcp` and fetches
`GET /pair/offer?token=…` from each discovered peer. From there the flow is
the QR flow, unchanged — seed, accept, compare safety numbers on both
screens. The discovered address proves nothing (an mDNS answer can be spoofed
by anyone on the network, and the offer therefore hands out public keys
only), so the human safety-number comparison remains the sole trust gate.
This lookup runs for every typed code, not only during an import. The row a
typed code names was minted on the desktop that issued it, so a phone that
does not ask the network has nothing to accept against and tells the reader
their code expired when it did not.

When discovery finds nothing at all, the screen says "cannot reach the other
device". A code that cannot be decoded at all never reaches the network, and
says so with `pairing.errors.invalid_code`. When something answered and
refused, it says
`pairing.errors.code_not_accepted` — "no device on this network accepted that
code" — rather than calling the code invalid: a typed code names no device, so
every desktop on the wifi is asked, and a housemate's laptop refusing a code it
has never seen says nothing about whether the code is good.

**Self-mint deferral.** The import branch never self-mints a GDK epoch —
it defers epoch acquisition entirely to the desktop's delivered epochs,
because self-minting would collide with the epoch control handler's
idempotency guard and permanently strand the desktop's epoch-1 history in
quarantine. The decision to defer is derived from durable state
(`MobileImportIntentGate::isImporting()` plus an empty local keyring),
never from the `?mode=import` query param alone — a re-entry to the
pairing route without the param (back button, bookmark, a relaunched
process) must still defer correctly, and a device whose keyring has
since converged must stop deferring so a later, unrelated pairing is
unaffected.

`MobileImportIntentGate` is the durable, per-user marker of whether a
device's local account originated from the import flow. It replaces an
earlier signal that lived only in the `?mode=import` query param —
re-read on every pairing-screen mount, which does not survive a re-entry
without the param (back button, bookmark, a relaunched process). Two
independent, idempotent callers mark the intent: `MobileImportBootstrap`'s
provisioning step (the authoritative source, at the moment sync identity
is enabled without an epoch) and `MobilePairingScan::mount()`, which is the
only writer on that screen: it echoes the observed query param into the
marker and then reads nothing but the marker, so the unlock return URL,
cancel and finish all survive a re-entry without the param. It carries no key
material and is never read by any crypto/admission code path — a plain
marker consumed only to pick between otherwise-ambiguous UI/completion
states.

## Blocking resumable initial sync

`SetupProgressScreen` is the post-pairing landing page: genuinely
blocking (no cancel/dismiss/back/skip anywhere), because a finance app
must never let the user see a half-populated balance. `mount()` reads
the durable `mobile_sync_progress` cursor — never a default-0 Livewire
property — so a cold-started process (an app-kill mid-pull, which
routinely happens on mobile OSes) renders at the true resumed percent on
its very first paint. `poll()` drives `InitialSyncPuller::pull()` forward
one bounded step at a time until `phase === 'complete'`.

`InitialSyncPuller::pull()` recomputes the true local applied-count on
every step via the same watermark-scoped local-op query the wire
protocol itself uses, read against the cursor's own persisted HLC
watermark (never the device's write-only clock state) — no new
dedup/resume logic is invented; a repeated call over an unchanged
watermark advances nothing. `syncOnce() === true` means the full
bidirectional catch-up exchange finished in that step, so whatever is
locally applied at that moment becomes the final expected-record count
(the phone has no way to learn the peer's total ahead of a completed
exchange). For an import-mode device, completion additionally requires
the desktop's GDK epochs to have installed and the full op-log history to
have been re-projected — finishing the sync leg alone is necessary but
not sufficient, since a relay-only or not-yet-delivered import would
otherwise report complete while landing on a dashboard of
decrypt-failed rows. The first `pull()` step to observe a non-empty
keyring re-projects exactly once (guarded by a `reprojected_at` stamp) so
any entry that arrived and was quarantined before the keyring was
populated now decrypts and projects. It replays what the quarantine
names, not the whole persisted op-log: the latter was measured fatal at
this ceiling, and the attempt is counted on disk before it runs so a pass
killed by memory exhaustion is visible on the next tick.

## Sync transport: LAN-first, relay fallback

`MobileSyncTriggerService::syncOnce()` orchestrates one bounded
foreground/manual sync burst — never a listener or daemon. A `null`
identity from `DeviceIdentityLoader` (locked app-lock, no key, or sync
never enabled) skips the tick entirely: no data is touched, no key is
cached anywhere outside the session. When a LAN host/port is supplied and
the network policy allows it, `LanSyncClient::syncOnce()` is dispatched
with exactly one bounded retry on a retryable outcome (covering the iOS
Local Network Privacy first-attempt connect denial, which can deny the
very first LAN connection an install ever makes). When LAN sync is
unavailable or still fails after that retry, the off-LAN leg drains the
configured relay mailbox — a real, bounded operation, never a fabricated
success. `NetworkPolicyResolver` implements the "sync on any network by
default, unless the user opted into pause-on-cellular" policy, reading
its toggle from a file-backed JSON policy (never `.env`) and defaulting
to "sync now" whenever the current connection type cannot be positively
confirmed as cellular/expensive.

`LanSyncClient` dials the desktop's already-running `sync:serve`
listener via `amphp/websocket-client`, completes the same Noise IK
handshake bytes the server side expects, drives one bounded bilateral
catch-up exchange reusing the existing `PeerCatchUpExchanger` and
`SyncSession` classes verbatim (so the mobile peer's receive path is
byte-identical to the desktop's), then closes in a `finally` — this class
never persists the connection and contains no listener/server/daemon
code. Immediately after catch-up, it additionally drains any pending GDK
epoch-wrap frames the desktop pushes over the same still-open,
already-authenticated Noise session, bounded by a short idle timeout and
a maximum frame count, and routes each through the `GdkEpochDeliveryGateway`
Public seam — this is the only new wire-adjacent behavior the mobile
peer adds; the catch-up/op-exchange protocol itself is unchanged.

`MobilePullCommand` (`sync:mobile-pull`) was the OS-scheduled background
cadence leg, invoked by Android WorkManager or iOS BGTaskScheduler (a
best-effort hint, not a guaranteed cadence) via the premium
`nativephp/mobile-background-tasks` plugin. **It is no longer scheduled, and
never was able to do its work.** Each firing is a key-less cold start: an
OS-scheduled tick has no session to attach to an in-app unlock, so
`AppLockKeyService::release()` returns null on every real firing, the sealed
device identity never opens, and the burst skips. Enabling sync requires an app
lock that can never be turned off again, so this holds on every device that can
sync at all — six firings and no syncs, measured on a paired, fully synced
SM-S928B. `Modules\Core\Public\Scheduling\MobileBackgroundSchedule::impossibleOnDevice()`
carries the declaration and the reason; the whole chain is in
[background-sync-cannot-hold-the-key.md](background-sync-cannot-hold-the-key.md).
The command itself still fans out over every `users` row (rather than a single
hard-coded user) since the schema stays multi-user-ready even in a single-user
v1, and each user's burst is isolated so one user's failure never stops the
rest — one `try` around the fan-out body, because an OS-scheduled process has
nobody to report a fatal to and `DeviceIdentityLoader::load()` throws on an
unreadable key-file.

The mobile-only schedule that remains — a bounded `queue:work` drain — is
declared in `Modules/Mobile/Routes/console.php`, and *when* the file is loaded
decides whether iOS ever runs it. The plugin hands
BGTaskScheduler the whole task list once, from
`BackgroundTasksServiceProvider::boot()`, which boots roughly thirty-seven
providers ahead of `MobileServiceProvider`. Loaded from `MobileServiceProvider::
boot()` the entry arrived after the manifest had already been registered, and
the phone logged twenty schedule events — the app-root `routes/console.php`
exactly — with every mobile-root entry absent and neither of them running ever.
The build-time half of the same plugin was never fooled:
`native:background-tasks:pre-compile` runs in an ordinary console process,
long after every provider has booted, so `BGTaskSchedulerPermittedIdentifiers`
in the generated `Info.plist` has always listed
`com.nativephp.task.sync-mobile-pull`. iOS was permitted to run the task and
was never asked to schedule it.
It is loaded from an `Application::booting()` callback instead: those all fire
after registration and before the first provider boots, which is the only
window where the schedule exists and the cache the `Schedule` overlap mutex
needs is bindable. It is a plain `require_once` rather than `loadRoutesFrom()`,
which drops the file outright once routes are cached — a console route is not
part of the route cache.

Two filters in `SchedulerManifestGenerator` drop schedules without failing
anything, and `Modules/Mobile/tests/Unit/IosBackgroundTaskManifestTest.php`
pins what they drop. That pin is empty now, and the section below is why.

LAN peer discovery is a separate story on iOS and currently does not work at
all: see
[ios-lan-discovery-entitlement.md](ios-lan-discovery-entitlement.md).

## The phone runs an artisan name on an interval, and nothing else

`BackgroundTasksServiceProvider::boot()` hands the platform a manifest built by
`SchedulerManifestGenerator`, and two filters decide what gets into it. An event
with no `$event->command` — every `Schedule::call()` closure — is dropped,
because Android WorkManager re-launches the app and runs an artisan name while
iOS BGTaskScheduler dispatches a registered identifier: neither can reach a
closure defined in a routes file. An event whose cron the generator has no
repeat period for is dropped too. Its map holds twelve expressions, every one of
them an interval, and no `dailyAt()` produces one.

Measured on a Samsung SM-S928B on 2026-08-29, from the app's own log under
`app_storage/laravel/storage/logs/laravel.log`:

```
BackgroundTasks: found 21 schedule event(s)
  skipped — could not extract command name    : 19
  skipped db:backup --force
        — unsupported cron expression 0 3 * * *:  1
  carried                                     :  1   (sync:mobile-pull)
```

Automatic backups did not exist on the phone. FX rates never refreshed, so every
converted figure went stale with nothing to say so. No reminder and no digest
ever arrived. Nothing failed and nothing surfaced it — the lines above are
`INFO`, in a log no user reads.

So a task the phone must run is a `Schedule::command()` in `routes/console.php`
whose body lives in a command class inside the module that owns the work: one
implementation, scheduled once, running the same way on both platforms. Its
expression has to be one of
`Modules\Core\Public\Scheduling\MobileBackgroundSchedule::RUNNER_INTERVALS`.

That class is also the declaration of intent. `requiredOnDevice()` names every
task the phone must run, `desktopOnly()` names the ones it deliberately does not
and why, and `mobileRootOnly()` names the two that exist only where
`nativephp/mobile-background-tasks` is installed. The `Background schedule`
probe in `beatrax:doctor` fails on anything required that stops reaching the
manifest, and
`Modules/Mobile/tests/Unit/TheBackgroundManifestCarriesEveryTaskThePhoneMustRunTest.php`
fails in CI for the same reason — including for a task that is scheduled but
declared in neither list, which is the shape the original twenty had.

The inbox-fetch pipeline is the one thing still written as closures, and that is
a decision rather than an omission: it fetches whole `.eml` bodies over IMAP
against credentials and an OAuth client provisioned through a desktop browser
flow, and its results reach the phone over sync. A phone-only household with a
connected inbox therefore gets no email scanning at all; that gap is stated in
`desktopOnly()` rather than left to be rediscovered on a device.

### A stated decision and a live control disagreed

`receipts.scan-drop-folder` was in that list too, on the reasoning that
`storage/app/inbox-drop/{userId}/` is a desktop filesystem affordance. The
switch that turns it on shipped to the phone anyway — enabled, not disabled —
under copy promising a scan every five minutes, and the entry was a
`Schedule::call()` closure that no device manifest could carry. It is a
`Schedule::command('receipts:scan-drop-folder')` in `requiredOnDevice()` now,
implemented by `Modules\Receipts\Internal\Console\ScanInboxDropFolderCommand`
and registered through `RegistersScheduledCommands` like every other one.

`desktopOnly()` answers "does the phone run this?". It cannot answer "does the
phone still offer this?", and nothing else asked. A task belongs in that list
only while no screen on a device offers it; the moment one does, the honest
choices are to run it or to withdraw the control, and leaving both in place is
the shape that shipped. The copy branch is the other half, written up as
[a cadence promised on a screen](../../conventions/invariants-from-shipped-failures.md#a-cadence-promised-on-a-screen-whose-device-never-registered-the-task)
whose device never registered the task.

The five-minute expression stays because that is the desktop's real cadence.
`SchedulerManifestGenerator` clamps it to its fifteen-minute floor on a device,
and past that BGTaskScheduler decides for itself when a registered identifier
gets a turn, so `AutoImportSettingsSection` reads
`Modules\Core\Public\Services\UserDataPathService::platform()` and the blade
picks a phone-shaped sentence that promises no interval at all.

The reciprocal question, asked of the rest of the list, found the same shape in
the inbox pipeline. All five of its entries — `desktop.email-scan.timer`,
`email-scan.incremental`, `email-scan.discovery`,
`email-scan.detect-ics-statement-ready` and
`receipts.process-fetched-inbox-messages` — are `Schedule::call()` closures with
sound reasons in `desktopOnly()`, and `/inboxes` ships to the phone sidebar
ungated, under copy reading "Connect Gmail and Microsoft 365 inboxes so Beatrax
can scan them for receipts". The wizard's connect-email step and the welcome
step's third row promised the same automatic capture.

Withdrawing the control is not the answer here the way running the task was for
the drop folder: no inbox table is in the sync merge registry, so a mailbox
connected on the desktop never reaches the phone's list, and the reader's real
option is the desktop app — which the phone can now name. `InboxesPage`,
`WelcomeStep` and `ConnectEmailStep` each read
`UserDataPathService::platform()` once in `mount()`, and `/inboxes` carries the
notice above its OAuth banners rather than inside a card's help text, because a
reader who learns it after tapping Connect has already been sent somewhere
pointless.

### Four more sentences branch, and one screen stopped denying the next

The same sweep, run over every `Modules/*/Resources/lang/en/*.php` string that
promises automatic or scheduled behaviour, found four more claims that hold on a
desktop and not on a device. Each reads `UserDataPathService::platform()` once in
`mount()`, the way `AutoImportSettingsSection` does, and the blade picks between
a desktop line and a `_phone` twin:

| Surface | The desktop keeps | The phone is told |
|---|---|---|
| `SettingsPage` · `core::settings.about_updates.body` | it updates itself, and a banner announces the next version | the App Store or Google Play installs new versions — all three `AutoUpdater` listeners open with `if (UserDataPathService::isMobileRuntime()) { return; }`, and `nativephp/desktop` is not a dependency of the mobile composer root |
| `SharedListSettingsPanel` · `community::settings.update_on_updates.help` | "every time Beatrax updates itself" | "every time a new version is installed from the App Store or Google Play" |
| `DevicesAndSyncSettingsSection` · `sync::devices.relay_endpoint_help` | offline devices sync via the relay | the relay holds changes until the reader syncs from that screen, because `impossibleOnDevice()` names `mobile.sync-pull` |
| `NotificationsSettingsSection` · `notifications::settings.background_note` | a deferred pass is picked up as the reader carries on using the open app | it arrives the next time the app is opened |

That last one was over-cautious rather than false: it was gated on encryption
alone, and `RunDeferredNotificationPasses` is `web` middleware on **both** roots,
so a desktop reader is already inside the app that replays the pass and telling
them to open it names a step they are past.

The worst of the set needed no branch at all, because the screen it sits on
exists only on a phone. `mobile::sync_complete.automatic_body` — the last screen
of setup — read "There is no sync button to press", and the first screen after
it, Data & devices, is built around one. `SyncScreen::syncNow()` is the only
caller of the burst outside the initial pull, so that button is not a shortcut
past an automatic mechanism; it **is** the mechanism. The three lines about
syncing on that screen now carry a `:action` placeholder, filled in
`SyncCompleteScreen::mount()` from `mobile::sync.sync_now` — the same key that
labels the button on the next screen. The two screens read one string, so they
can no longer name different things.

`core::alerts.messages.backup_overdue` came out of the same sweep and needed no
branch either: it told the reader to run `php artisan db:backup`, and neither
shipped bundle has a terminal to run it in. The daily run it also named is real,
and stays.

### A wall-clock hour the runner cannot express moves into the command

Four entries named an hour. Three did not need one. `db:backup` ran at 03:00 so
that an interactive session had stopped writing, but `VACUUM INTO` only reads
and a WAL reader never blocks a writer; `fx.daily-refresh` ran at 09:00 so the
day's first dashboard load saw fresh rates, but ECB publishes at ~16:00 CET, so
a midnight fetch reads the very publication 09:00 would have read;
`open-banking.daily-sync` ran at 06:00 to sit ahead of the FX refresh and the
notification pass. Those three became `->daily()`, and what is left of the
ordering — the rate refresh and the open-banking sync both landing before the
09:15 notification pass — is carried by the order they are defined in.

The daily notification pass is the exception. Payment reminders, the position
digest and savings prompts are pushes, and 09:15 is part of what they are: at
midnight `SuppressionEvaluator`'s quiet hours suppress delivery while the row is
still written, so the notification would exist and never be sent. The three
entries became one command, `notifications:daily-triggers`, on a fifteen-minute
interval, with the clock inside it — `Modules\Core\Public\Scheduling\DailyLocalWindow`
lets exactly one tick per local day through, at or after 09:15. The desktop
still fires at 09:15 to the minute, because that time sits on the fifteen-minute
grid, and a read-only `->when()` filter asking the same window is what keeps it
from spawning the other ninety-five artisan processes a day. The phone never
evaluates a schedule filter, so the command re-asks and claims for itself.

That last point generalises. `->timezone()`, `->skip()`, `->when()` and
`withoutOverlapping()` are all read by `schedule:run`, and the phone never runs
`schedule:run` — WorkManager invokes the artisan name directly. A condition that
has to hold on a phone belongs inside the command.

### Scheduled commands register outside `runningInConsole()`

`Modules\Core\Public\Support\RegistersScheduledCommands` exists to make that
non-optional, and its name is the reason. The Android bridge sets
`APP_RUNNING_IN_CONSOLE=true` before the cold-path `php_embed_init()`, so a
WorkManager firing that starts a fresh process boots providers in console mode.
On the hot path — app already alive, ephemeral runtime borrowing the existing
TSRM — it sets nothing, and the last value written was `false`. A command
registered behind `$this->app->runningInConsole()` is then missing from the
Artisan application the runner calls into, and the task ends in a
command-not-found the worker retries and nobody reads. Registering costs
nothing outside the console: `commands()` only adds an `ArtisanStarting`
listener.

### Queued work needs a worker the phone only runs in the foreground

These commands dispatch jobs rather than doing the work inline, which is what
keeps the desktop and the phone on one code path. NativePHP's queue worker is a
thread `MainActivity` starts, so with the app closed those jobs sit in `jobs`
until it is next opened, and a task that only enqueues is a task that has not
run. `Modules/Mobile/Routes/console.php` therefore schedules a bounded
`queue:work --stop-when-empty --max-time=55 --quiet`, behind an `onAnyNetwork`
macro guard that makes it mobile-only — the macro is registered by a package
only the mobile composer root requires, so the guard needs to ask the runtime
nothing. It is safe next to the in-app worker because a reserved job cannot be
reserved twice. It is the only entry left in that file: the sync burst that
used to sit beside it is declared impossible on a phone, above.

### Two loaders reach the console routes and only one is idempotent

`routes/console.php` is reached twice on the mobile Composer root, by two
mechanisms that do not share include state.

`Illuminate\Foundation\Console\Kernel::discoverCommands()` plain-`require`s
every path `withRouting(commands: …)` registers. `nativephp/mobile-background-tasks`
adds a second loader in `BackgroundTasksServiceProvider::register()`: a
`resolving(Schedule::class)` hook that `require_once`s `basePath('routes/console.php')`.
That hook is not redundant — the phone is served over HTTP through
`PHPSchemeHandler`, command discovery never runs there, and without it the
device would build its manifest from an empty schedule. But `require_once`
fires first, during provider boot, when `Modules/Mobile/Routes/console.php`
resolves `Schedule` for its own entry; command discovery then runs later in
`Kernel::bootstrap()` and `require`s the same file again. Its body executed
twice, and every `Schedule::command()` in it registered twice.

`mobile-app/routes` is a symlink to `../routes`, so this is one file loaded
twice, not two copies drifting apart. `__FILE__` resolves symlinks, which is why
it is a usable key.

**This is the divergence to remember: a duplicate background task is free on one
platform and fatal on the other.** Android WorkManager enqueues by unique name
and replaces, so twelve doubled tasks registered and ran there with nothing in
any log. iOS `BGTaskScheduler.register` throws `NSInternalInconsistencyException`
on the second handler for one identifier; `PHPScheduler.registerHandlers()` does
not catch it, and it is raised from `initBackgroundTasks()` before the first
frame. Build 1.3.0 (10300) on an iPhone 12 mini terminated on signal 6 on every
launch, naming `com.nativephp.task.recurring-detect` — merely the first of the
twelve it reached. The app was unusable, and the same manifest was healthy on
Android. A schedule written only against Android gets no feedback at all until
an iPhone refuses to open.

Three things hold it shut, at three levels:

- `Modules\Core\Public\Scheduling\ScheduleRegistrationGuard::firstLoad(__FILE__)`
  at the top of `routes/console.php` returns early on a second pass. It is keyed
  on the container instance rather than on a process-wide static, because a test
  process builds a fresh application per test whose `Schedule` starts empty — a
  process-wide latch would leave every application after the first with no
  schedule at all. It marks before registering anything, so a re-entrant load
  from the `resolving` hook cannot slip through.
- `scripts/nativephp_dedupe_background_task_identifiers.php` patches
  `SchedulerManifestGenerator::generate()` to key its task list by identifier.
  A patch script rather than a container binding because both call sites
  construct the generator with `new`, so nothing can be bound over it. It runs
  from the mobile root's `post-update-cmd` through `nativephp_patch_all.php`,
  and from `NativeBuildPatches` on every `native:run` / `native:build` /
  `native:package`.
- `Modules/Mobile/tests/Unit/IosBackgroundTaskManifestTest.php` boots a fresh
  **console** kernel in a subprocess and fails when `generateIdentifiers()`
  names anything twice, and
  `Modules/Mobile/tests/Unit/TheBackgroundManifestCarriesEveryTaskThePhoneMustRunTest.php`
  loads `routes/console.php` a second time and fails if the schedule grows.

Neither pre-existing test caught the original because both deduplicated before
asserting — `array_unique`, and `array_diff` comparing sets — and because the
boot-boundary probe boots the **HTTP** kernel, where `discoverCommands()` never
runs and the second load does not happen.

`background_task_identifiers.json` and the `BGTaskSchedulerPermittedIdentifiers`
array in `NativePHP/Info.plist` and `NativePHP-simulator-Info.plist` are
generated, not committed: `mobile-app/nativephp/` is gitignored, and
`native:background-tasks:pre-compile` writes all four from
`SchedulerManifestGenerator` during the build. `PluginHookRunner` invokes it with
`Artisan::call()`, in the same process as `native:build` — so the identifiers
shipped in a bundle are exactly what that build process's own schedule held,
which is where the duplicates came from.

## Wiring

`MobileServiceProvider` is the single-owner wiring surface for the whole
module: every Internal/Livewire/Commands class is bound via a
`class_exists()`-guarded, runtime-built FQCN string rather than a `use`
import, so the provider stays statically clean before each class ships
and never needs another structural edit once they do. `Routes/web.php`
dispatches every mobile page through a Closure rather than a bare
class-string action, because a string action is unsafe in two ways when
the target class does not exist yet: `RouteAction::makeInvokable()`
eagerly reflects the class at boot time, and `route:list` always reflects
a string-action's controller regardless of any `class_exists()` guard
around the `Route::get()` call itself. A Closure sidesteps both — the
target FQCN is resolved via the container and invoked only when a real
request reaches the route.

`NativeMobileAppServiceProvider` is invoked from `mobile-app/bootstrap/app.php`'s
`->booted()` hook, behind the same `isMobileRuntime()` guard as the storage
reconciliation. It used to be named by a `provider` key in
`mobile-app/config/nativephp.php`, which read like wiring but was not:
`config('nativephp.provider')` is consulted by `nativephp/desktop` alone, and
`nativephp/mobile` has never read it in either v3 or v4. So the provider booted
nowhere, and `DispatchMobileNotification` — subscribed only from here, by design
— was registered nowhere, which meant no mobile notification could ever be
delivered on device. The dead key is gone; the `->booted()` call is the wiring.

It is an inverted skeleton of the desktop equivalent:
`boot()` must never keep a background listener alive across requests
(neither iOS nor Android permit an always-on background process or
inbound connections while backgrounded), so any dial-out trigger wired
here is a bounded burst only. It separately subscribes the mobile
delivery adapter (`DispatchMobileNotification`) to the shared
`NotificationDeliverable` event — registered only here, never from
`MobileServiceProvider`, because this provider's `boot()` runs
exclusively in the true on-device runtime, while the shared provider also
loads in non-mobile contexts where the subscription has no business
firing. `DispatchMobileNotification` re-uses the exact same suppression
check (`SuppressionEvaluator::shouldDeliver()`) the desktop OS-notification
adapter calls — two independently-written suppression checks would drift
— and has no window-focus gate, since a phone has no focus concept: every
non-suppressed deliverable fires.

## The mobile root's own bootstrap

`mobile-app/bootstrap/app.php` is a real file, never a symlink, and so are
`artisan` and `mobile-app/phpunit.xml`. PHP canonicalises `__FILE__` through
symlinks, so a symlinked bootstrap would resolve `dirname(__DIR__)` to the
desktop parent and hand `base_path()` — and every `UserDataPathService`
accessor derived from it — the wrong root, booting the desktop app from the
phone's shell. The failure would be silent: a working app, pointed at the
wrong data.

Two hooks run before any request, and each exists because the next one is
too late.

`->booting()` creates the on-device database directory and touches the file
itself. Laravel's SQLite connector throws `Database file … does not exist`
rather than creating one, and `SqliteOptimizationsProvider` applies
`PRAGMA journal_mode = WAL` on `ConnectionEstablished` during provider boot —
earlier than `->booted()` — so the first cold boot fails without both. Off
device the directory already exists and both calls are no-ops.

`->booted()` is the attach point proven on a real device, guarded on
`UserDataPathService::isMobileRuntime()`. It does three things, in order:

1. **Repoints the live SQLite connection** at the one canonical
   `UserDataPathService::databaseFile()` path. The connection otherwise targets
   `…/Library/Application Support/…` while the accessor resolves
   `…/Documents/app/…` — two divergent, both-unmigrated files, which surfaces
   as `no such table: sessions`.
2. **Recreates the `storage/framework` tree** the native app-copy strips
   (views, cache, sessions), then drives session and cache through the
   reconciled database rather than the filesystem: the build caches config with
   file paths that do not exist at runtime, so a file-backed session or cache
   500s every request. `view.compiled` is the exception — Blade genuinely needs
   a directory, and an empty value raises "Please provide a valid cache path."
   on every render.
3. **Boots `NativeMobileAppServiceProvider`** and runs
   `MobileFirstLaunchBootstrap`.

A migrate failure inside that hook is logged and swallowed. A boot-time hook
that throws takes the whole shell down, and the app has to open before anything
can be repaired.

### Middleware order at this root

`prepend()` reverses: the last call is the first to run. `TrustedHostGuard` is
prepended last so it gates the `Host` the client asked for before `LoopbackOnly`
gates the interface the connection arrived on — the webview is loopback-served,
so it needs both. `ForgetGuardsBetweenRequests` is prepended second-to-first so
it runs after `RestoreFrameworkRedirector` has repaired the container binding it
depends on, and before anything reads the authenticated user: this runtime keeps
one container for the life of the process, so the guard otherwise still holds the
`User` model resolved at sign-in and every preference on it is stale.

`ForgetStaleSessionBetweenRequests` is prepended first, so of the two it runs
last, and that pairing is not free choice. The guard drop decides from the
session the previous request left in memory — `getSession()->has($guard->getName())`
— and the session drop is what empties it. Prepend them the other way round and
the guard sees an empty session on every request, concludes there is nobody to
refresh, and the stale `User` it exists to drop survives.

It drops that user only when the session still names them, and that condition is
load-bearing rather than defensive. Dropping is a *refresh*: `SessionGuard::user()`
re-reads the row from the session key, so a user bound straight onto the guard —
`actingAs()`, or any `Auth::setUser()` — has nothing behind it and is signed out
instead. Unconditional, this middleware answered every authenticated request in
the mobile root's own suite as a guest, and only there: no other root registers
it, so the same tests stayed green from the repo root.

`MobileEnsureDatabaseReady` is prepended to the `web` group rather than appended,
and that is not a style choice. List order is not run order: `SortedMiddleware`
re-sorts the group against the framework priority list, and `Authenticate`
implements `AuthenticatesRequests`, so it is hoisted ahead of anything unlisted
that sits behind it — which left this gate running *after* the very middleware it
exists to pre-empt. Prepending is stable because non-priority middleware are never
moved by that sort, and the gate reads only the matched route name and the
database, so it needs nothing `StartSession` sets up.

`MobileEnsureImportCompleted` is appended for the opposite reason: it needs an
authenticated user, so it has to run after `Authenticate`, not before it. Without
it, a device that created its account through the import bootstrap and then quit
had no route back into pairing — `isFreshInstall()` is false forever once a user
exists, so it simply landed on an empty dashboard. `SetLocale` is appended
alongside it because this root has its own bootstrap: omitting it left the
translator on `config('app.locale')`, with the language switcher writing
`session('locale')` and nothing reading it.

### What the provider manifest diverges by

`mobile-app/bootstrap/providers.php` is the desktop manifest with exactly one
removal and one addition, and anything else diverging is drift rather than
design. `Modules\Desktop\Providers\DesktopServiceProvider` is not registered:
the Desktop module ships no `module.json`, so it is not nwidart-discovered and
this hardcoded manifest is the only lever that keeps it — and its hard dependency
on the `nativephp/desktop` package, absent from this root's `vendor/` — out of
the mobile shell. `App\Providers\NativeServiceProvider` *is* registered here and
not on the desktop root, because it carries the NativePHP **mobile** plugin list.
The Mobile module itself is absent from both manifests: it ships a `module.json`
and loads from `modules_statuses.json`, as do Sync, Reports, Search, Tax,
Migration and Counterparties.

## Route registration before the component exists

`Routes/web.php` is the single-owner route registration for the whole
module. Every route target is dispatched through a Closure rather than a
bare class-string action, because a string action is unsafe in two
independent ways when the target Livewire class does not exist yet:
`RouteAction::makeInvokable()` eagerly reflects the class at
route-registration time (application boot) for a bare class-string
action, and `php artisan route:list` always reflects the controller class
for any string-action route regardless of any `class_exists()` guard
placed around the `Route::get()` call itself — the guard only controls
whether the route registers, not what `route:list` does with an
already-registered string action. A Closure sidesteps both: it takes the
"already a Closure" branch (no eager reflection), and `route:list`
reflects the Closure itself. The target FQCN is resolved via the
container and invoked only when a real request reaches the route.
Calling the resolved instance as `$component()` reproduces Livewire's own
page-component dispatch path exactly (mount + layout + render).

## Layout rendering gotcha

Every full-page mobile Livewire screen calls `$view->extends('layouts.app', [...])`
manually inside `render()` rather than using the `#[Layout(...)]` PHP
attribute. `layouts.app` is a traditional `@extends`/`@yield('content')`
Blade layout, not a Blade component; the `#[Layout(...)]` attribute
drives Livewire's component-slot layout mode (`@component`/`@slot('slot')`)
instead, which silently renders an empty page body against a
`@yield`-style layout.

## Throwaway spikes

`SpikeStoragePathCommand` and `SpikeSyncDialCommand` are throwaway
topology probes: the first dumps the NativePHP mobile storage-path
environment signals against `UserDataPathService`'s resolved paths (to
confirm the encrypted SQLite file lands inside the app sandbox); the
second proves the amphp/Revolt event loop can be driven to completion for
a single bounded dial-out burst from within the mobile runtime, without
attempting the real Noise handshake. Both are registered only from the
mobile-app root's own bootstrap, never the shared `MobileServiceProvider`.

## Native chrome over the web body

`AppShellScreen` is the SuperNative shell: a `NativeComponent` registered at
`/shell` whose top bar and bottom navigation are real SwiftUI / Jetpack Compose,
wrapped around a `<webview php>` still rendering the shared Livewire surface.
Nothing under it is reimplemented — the four destinations are `/`,
`/transactions`, `/calendar` and `/settings`, the same routes the desktop
serves. That is the point: the chrome is what makes a phone feel like a phone,
and it is the only part worth paying for twice. Converting the bodies would
fork the `resources/` symlink and mean maintaining every screen in two
languages.

`php` on the webview is what makes it the app's own runtime rather than a
sandboxed foreign document — shared session, asset pipeline, `window.Native`.
The `javascript` and `dom-storage` opt-ins are deliberately *not* passed: the
renderers force both on in php mode, and naming them would imply they were
optional here.

**Inline chrome does not survive as tree nodes.** `wrapWithChrome()` hoists
`<top-bar>` and `<bottom-nav>` onto the native shell, so a rendered tree has a
`native_root_tabs` root carrying `nav_title`, the `bottom_nav_item`s promoted
beside the content, and no `top_bar` or `bottom_nav` node at all. A test
asserting on those types fails against a screen that is entirely correct.

Three things fail silently here, and none of them is visible in the source:

- **A bare `<top-bar />` is dropped.** An empty chrome element contributes
  nothing to hoist, so the bar never reaches the shell and `nav_title` is
  absent. The `title` attribute is load-bearing, not decoration.
- **`BottomNavItem` reads a fixed key list.** `active` selects; `selected` is
  discarded without a word. Per-platform glyphs go on the item as
  `ios` / `android`, not as a nested `<icon>`.
- **The webview moves without telling the chrome.** A link followed inside the
  page never calls `open()`, so `@navigated` re-derives the active destination
  from the committed URL. It matches the longest destination path, because `/`
  prefixes everything and would otherwise light Home on every screen; a path no
  destination owns leaves the marker where it was, since a stale highlight
  reads better mid-flow than a wrong one.

**`nativephp/mobile-ui` is required, and the version skew is worth knowing.**
`nativephp/mobile`'s `registerCoreElements()` registers 26 elements — layout,
content, `pressable`, and the navigation chrome — and deliberately leaves
`button`, the text inputs, `toggle` and `webview` for a UI plugin, because
plugin discovery cannot override an existing registration. Installing
`nativephp/mobile-ui` takes the registry to 55. The runtime is 4.x; that
component library is 0.3.0.

The route registers behind `Route::hasMacro('native')` rather than the
`class_exists()` guard the web routes use. `Route::native()` is a macro
`nativephp/mobile` installs, and the desktop root loads this same file from a
tree where that package cannot exist. The screen itself is analysed at the repo
root through `tools/phpstan-stubs/native-mobile-edge.php`, on the same
`scanFiles` footing as the scanner stubs — excluding it instead would leave the
one part of the mobile UI that is real PHP unchecked.

## Build packaging: materializing the symlink shell for Bifrost

The `mobile-app/` root is a thin NativePHP-for-Mobile shell. Its own real
files are only the ones that must differ from the desktop root —
`composer.json`/`composer.lock` (a separate `vendor/` tree, because
`nativephp/mobile` and `nativephp/desktop` cannot co-exist in one
autoload root), `config/nativephp.php`, `config/view.php`, `bootstrap/`,
`storage/`, `artisan`, `Gemfile`, `nativephp-plugins/`, `scripts/`.
Everything else is a git-tracked symlink up to the desktop root: the
directories `app`, `Modules`, `resources`, `routes`, `public`, `tests`,
`database/migrations`, `database/schema`, and the fifteen shared
`config/*.php` files. Those links are the single-source-of-truth seam —
shared business logic is edited once at the root and the mobile shell
sees it with no duplication.

Bifrost (the NativePHP cloud build) pulls only the specified directory
and its children into the build container, so every one of those
parent-pointing symlinks dangles and the build fails. Git submodules are
not supported, and resolving symlinks in-container is fragile even when
the target happens to be present. The shell is therefore not itself
buildable in isolation — it must be *materialized* first.

`mobile-app/scripts/materialize.sh <out>` produces a copy of `mobile-app/`
with every symlink replaced by the real file/directory it targets
(`rsync --copy-links`), excluding what the build container regenerates
(`vendor/` — Bifrost runs `composer install`; native scaffolds under
`ios/`, `android/`, `nativephp/`, `build/`; runtime `storage/` and
bootstrap caches). It derives the symlink set by dereferencing rather than
from a hardcoded list, so a newly shared directory is picked up
automatically, and it hard-fails if any symlink survives the copy — a
non-self-contained tree can never reach Bifrost.

The materialized tree is published to a dedicated, fully-generated build
repo that Bifrost is pointed at (at its root — no experimental monorepo /
subfolder support required). That repo is derived output with a single
writer, the `mobile-bifrost-publish` workflow, and is never hand-edited,
so it cannot drift from the symlink SSoT. The workflow runs on `main`
pushes touching shared source or the shell, builds the frontend assets
(`public/build/` is served on-device), materializes, and mirrors the
result into the build repo via `rsync --delete`. It reads two settings:
the repo variable `BIFROST_BUILD_REPO` (target `owner/name`) and the
secret `BIFROST_BUILD_TOKEN` (a fine-grained PAT scoped to
`contents:write` on only the build repo). The build container additionally
needs the private-registry credentials for the paid `nativephp/mobile-*`
plugins (`COMPOSER_AUTH` / NativePHP licence), the same ones the
`mobile-app quality` CI job consumes.

## The on-device data is out of platform backup

Both platforms back up app data to the vendor's cloud by default, and both were
doing it. `native:install` generates an Android manifest with
`android:allowBackup="true"` pointing at Android Studio's sample rule files —
whose every rule is commented out — and iOS puts the store under Application
Support, which is in iCloud backup unless a file says otherwise.

What sits there is `persisted_data/`: the SQLite database with every
transaction, the sync keyring, and the staged secrets. For a product whose
stated position is local-first and end-to-end encrypted, a copy of all of it in
a Google or Apple account is the one exposure no amount of transport encryption
addresses — and it was silent, with nothing in the app or the build mentioning
it.

`scripts/nativephp_exclude_data_from_backup.php` closes both. On Android it
sets `allowBackup="false"` and fills in the rule files; the flag is what stops
cloud backup, and the rules are written as well because Android 12+ reads
`<device-transfer>` from `dataExtractionRules` independently of it. On iOS
there is no manifest flag, so the exclusion is a per-URL resource value set as
each directory is created in `getAppSupportDir`.

The two halves are guarded separately. They were not at first, and the Android
marker short-circuited the script before the iOS half ever ran — a patch that
reported success having done half its job.

Restoring a device therefore starts a fresh install with no data, which is what
[F4](../core/architecture.md) covers: the encrypted backup
the user takes deliberately is the recovery path, not the platform's.

## The phone's PHP has no `ext-zip`

The mobile runtime is a prebuilt static PHP shipped by `nativephp/mobile`, and
its `php_config.h` carries `#undef HAVE_ZIP` on both iOS and Android — with
`HAVE_ZLIB 1` beside it. Nothing in this repo compiles that binary, so this is a
constraint to design around rather than a build flag to flip.

`ext-zip` is the only extension the repo root requires that the phone cannot
supply, which is why `mobile-app/composer.json` does not list it: Composer has
no way to say "required on one target". Every other name in the root's require
block is either present in the mobile build or already guarded at its call site
the way the three `pcntl_signal` users are.

The whole of `Migration` stood on it — all three source products upload a ZIP —
so the wizard was dead on a phone, and said so by blaming the reader's file.
[Reading a ZIP without ext-zip](../migration/reading-a-zip-without-ext-zip.md)
covers the built-in reader that replaced it, what it still refuses, and how the
three endings are told apart.

## The pairing screen is the whole of sync setup on a phone

On a phone, `/data-devices` renders `MobilePairingScan` — the same component
`/mobile/pair` does. There is no separate "enable sync" step to reach first,
the way `DevicesAndSyncSettingsSection` provides one on the desktop. That makes
the scan screen responsible for establishing its own preconditions, and two of
them were originally treated as import-path concerns:

**The responder's identity.** `enableSyncIdentityWithoutEpoch()` had exactly one
caller, `MobileImportBootstrap`. A phone that reached the scanner any other way
had no `sync/identity/{userId}.enc`, so `DeviceIdentityLoader::load()` returned
null and `PairingGateway::acceptToken()` returned null before it ever looked at
the token. Identity-only is the right shape here for the same reason it is on
the import path: a responder receives the initiator's epochs on confirm, and
self-minting an epoch would strand the peer's.

**The local pending row.** `seedResponderToken()` was gated on import mode,
described as being for "a fresh, separate database with no local pending row".
That describes *every* phone — a phone's database is always separate from the
desktop's, so the token issued on the desktop is never present locally, and
`acceptToken()` has nothing to match. Seeding makes no trust decision: the row
is written `Pending` and still has to survive `acceptToken()` and the
both-sides confirm ceremony.

Anything that mints an identity must gate on `hasIdentityFile()` (the key-file)
and never on a null from the loader. `load()` returns null both when sync was
never enabled *and* when the app-lock holds the KEK, and minting over a locked
device's existing identity would orphan every pairing it already had.

Both failures surfaced as `pairing.errors.invalid_code` — "this code is invalid
or expired, ask the other device to generate a new one" — which points the user
at the one device that was working correctly. A null from the accept path now
distinguishes a locked identity from a genuinely bad code.
