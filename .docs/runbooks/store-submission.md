# What a store submission has to declare

Both stores' forms are written for a developer who receives data. Beatrax
operates no service that could receive any, so most of the honest answers are
"nothing" — and an answer of "nothing" is only defensible if it was *derived*
rather than asserted. A store answer that has drifted from what the product
actually does is a false statement to a regulator, not a stale document.

This page is where each declaration is derived, and it is the page to re-read
before every submission rather than the page to copy from once.

[The mobile release runbook](mobile-release.md) is the build and signing side of
the same submission.

## The outbound-call catalogue

Seven calls, and the enumeration is the point rather than the count. With every
optional feature off the only one that fires is the update check, and that can
be turned off too — at which point the application makes no network call at all.

| Call | Default | Reached through | Endpoint chosen by |
|---|---|---|---|
| Update check | On, disableable | `HttpPublisherManifestFetcher` | The bundle (a fixed publisher URL) |
| Mail provider API | Off until enabled | `GmailApiClient`, `GraphApiClient` | The reader's own mailbox and grant |
| Exchange-rate fetch | Off until enabled | `FrankfurterRateProvider` | A public rate feed the reader opts into |
| Open-banking aggregator | Off until enabled | `EnableBankingHttpClient` | The reader's own aggregator account and key |
| Sync peers | Off until paired | `LanSyncClient`, `SyncWebSocketHandler` | The reader's own devices, on their own network |
| Sync relay | Off until configured | `RelayClient` | A relay the reader configures |
| External-link opening | On demand | `OpenExternalUrlAction` | The reader, by clicking, against a host allow-list |

Naming the seam class for each one is not decoration. `DocsNameSymbolsThatExistArchTest`
fails the build when a page names a first-party class that no longer exists, so
renaming or deleting one of these seams breaks this table loudly and the
declarations below get re-derived rather than inherited.

What that does **not** catch is a *new* outbound call added through a seam this
table does not name. Nothing in the tree enumerates outbound HTTP call sites
today — `NothingShippedFetchesFromAThirdPartyHostArchTest` reads markup
subresources, not PHP clients — so a machine-readable catalogue with a
derivation test remains the open half of this.

## The privacy declaration

Derived from the table above, and it holds for both stores.

**Collected: nothing. Shared: nothing. Tracking: none.**

Every call in the table addresses an endpoint the reader chose and holds the
credentials for — their own mailbox, their own aggregator, a relay they run, a
feed they opted into — or is the reader's own browser opening a link they
clicked. The developer is not in any of those paths, and there is no
Beatrax-operated service for anything to reach. Both stores exempt data that
only ever moves between the device and a service the user already has.

The update check is the one call the bundle addresses rather than the reader,
and it carries no user-identifying data beyond what any HTTP request carries.

The iOS side of this is already mechanical: `PrivacyInfo.xcprivacy` declares
`NSPrivacyTracking` false and omits `NSPrivacyCollectedDataTypes` and
`NSPrivacyTrackingDomains` rather than shipping them empty, which is its own
rejection trigger. See `scripts/nativephp_ios_privacy_manifest.php`.

## The financial-features declaration

**Beatrax provides no financial feature.**

It reads files the reader supplies and, when the reader enables it, an
account-information feed their own key unlocks. It moves no money, holds no
funds, lends nothing, and files nothing on anyone's behalf.

Payment initiation is not merely disabled: the open-banking connector's scope
type has no member for it, so there is no value that could request one. That is
[ADR-0020](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0020-open-banking-byo-key-ais-only.md),
and changing this answer is a specification change before it is a form edit.

## The encryption declaration

**Non-exempt.** The cryptography is real and is not the operating system's:
Ed25519 signing and BLAKE2b hashing come from libsodium through PHP's sodium
extension, a third-party library linked into the bundle. Apple's exemption
covers encryption built into the OS, so CryptoKit's absence is what decides
this rather than the choice of algorithms.

The algorithms are standards-body work, so no US CCATS follows. A French ANSSI
declaration does, and it is **not filed** — France cannot be a release territory
until it is. `scripts/nativephp_ios_export_compliance.php` writes the plist key;
[the mobile release runbook](mobile-release.md) carries the paperwork.

## The application category

`public.app-category.finance`, set in two places that cannot disagree: the
`permissions` array in `mobile-app/config/nativephp.php`, and the
`INFOPLIST_KEY_LSApplicationCategoryType` build setting, which Xcode merges into
the processed plist independently. App Store Connect refuses a submission with
no category, and the generated project ships it empty.

Picking a milder category to attract a lighter review would be a false statement
about what the app does.

## Permissions

Every permission requested has a named consumer in shipped code, and the ones
that arrive from a dependency's manifest with no consumer here are pinned out at
merge — see `scripts/nativephp_strip_unused_permissions.php`, which also records
why `VIBRATE` and `WAKE_LOCK` deliberately stay.

Two of the removals are Play declaration problems rather than tidiness:
`USE_EXACT_ALARM` and `SCHEDULE_EXACT_ALARM` are restricted to apps whose core
purpose is precisely-timed action. A budgeting app is not one, so requesting
either makes the listing removable.

**The store reads the merged manifest, not the source one.** That script runs
before Gradle merges the plugin manifests, so what it proves is that nothing
here pins out a permission the merge has to contribute. The merged result is a
fact about an artefact:

```bash
aapt2 dump permissions app-release.apk
```

## Notes for review

A reviewer installing Beatrax gets an empty ledger and no credential to sign in
with, because there is nobody to have issued one. The sequence has to be
described rather than discovered.

> Beatrax stores everything on the device. There is no Beatrax server, no
> Beatrax account, and nothing to sign in to — so there is no demo login to
> supply, and this text is the substitute for one.
>
> **First launch.** The app opens on a signup screen. Create an account with any
> username and password you like; it exists only on this device and is not sent
> anywhere. That first account becomes the owner.
>
> **Signup then closes.** Once one account exists the signup screen answers
> "not found" — a second account is added from inside the app by the owner, not
> from the signup screen. This is deliberate: the device belongs to a household,
> not to the internet.
>
> **The app-lock is yours.** You will be asked to set a PIN. It is chosen by
> you, on the spot; it is not a code we issued and there is nothing to look up.
> If Face ID is offered, granting it is optional — the PIN always works.
>
> **Sample data.** An empty ledger shows very little, so load the sample
> household: open **Settings**, find **Sample data**, and press *Load sample
> data* and then *Add it to this account*. It takes a moment, then fills the
> ledger with accounts, transactions, budgets, goals and alerts. Every figure in
> it is invented; it is not any real person's finances.
>
> **What will not work in review, and why.** Syncing needs a second device of
> your own on the same network, and pairing is by scanning a code that device
> shows. Nothing about it reaches the internet, so there is nothing to
> demonstrate against a server.

### Where the sample-data control lives

`SampleDataCard`, on the settings page, rendered by the shell a store build
carries. It calls `SampleDataLoader::loadFor()` — the reader path, which is
`SampleDataScope::LedgerOnly`: the ledger and everything derived from it, over
the account already signed in. The developer path that invents its own accounts
and rewrites install state is a different scope and stays on `demo:seed`, a Dev
Console command and therefore absent from a store build.

The control adds and never replaces. `--reset` is not on it: tearing down what
is already there should cost more than one press, so it stays on the command
line. The card says both things before it acts — that it adds, and that the
write reaches paired devices.

The data itself is fine: the demo seeders are hand-authored literals
(`demo-1` / `demo-2`, a `PAYPAL-DEMO-1` sentinel pinned by
`OneSpellingPerSyntheticIbanArchTest`) and read none of the anonymised bank
fixtures.

## What a listing may not say

A listing is product copy and is bound by the same honesty rule as every screen.
Three statements the application is required to make constrain what the store
page may claim:

- **At-rest encryption does not cover everything.** Amounts, dates and the
  search index are plaintext by necessity. A listing may not describe the ledger
  as encrypted without that qualification.
- **A relay sees metadata** — sizes, timing, which device identifiers exchange
  traffic. Traffic analysis is not defended against.
- **A paired device is trusted.** Revoking one rotates the key going forward; it
  does not un-see what was already synced.

And a capability that is dead on a platform may not be described as though it
were not. **LAN peer discovery does not work on iOS**: it needs
`com.apple.developer.networking.multicast`, Apple grants that per team by
request, and no provisioning profile for `com.beatrax.mobile` carries it yet.
The iOS listing may not describe automatic discovery of other devices until it
does.

## Signing identities and their expiries

Every identity the pipeline requires, its expiry, and the command or page that
expiry is read from are in the
[signing identity register](signing-identities.md).

This section used to point at two other pages and add a third table of its own
for what neither covered — the App Store Connect API key, the provisioning
profile, the publisher key, the build token, the licence and the keystore
certificate. A split inventory is how six things a submission depends on came
to be recorded on none of the pages that hold it. There is one page now, and a
test compares it against the workflows in both directions.

Nothing still warns before an identity lapses, and a store listing turns that
from an inconvenience into an outage. Read the register before a submission.

## The Android artefact shapes

Two, and neither substitutes for the other. Play refuses an APK and takes an
AAB; a reader sideloading a direct download needs the APK and cannot install an
AAB. `mobile:package-android` builds either, and both release workflows now
build both, verify each against the recorded signing certificate, and upload
them together.

The signature check differs by shape, and the reason is worth keeping:
`apksigner` cannot read an AAB, which is a JAR-signed archive, so its
certificate comes out of `keytool -printcert -jarfile`. What that prints is the
SHA-256 of the same DER certificate `apksigner` digests for the APK, so one
recorded fingerprint — `ANDROID_SIGNING_CERT_SHA256` — answers for both.

`AStoreShapeIsBuiltBesideTheSideloadableOneArchTest` holds it there: a workflow
that builds the APK and not the AAB fails, and so does one that builds the AAB
without proving who signed it.

## The two desktop stores

Direct download is the desktop channel and is not retired by any listing. Both
desktop stores were previously recorded as out of scope on a technical ground
that turns out not to hold; the conclusion survives the correction, but the
reason does not, and a reason that is wrong cannot be used to schedule the work.

### What the entitlements actually are

`build/entitlements.mac.plist` carries exactly two hardened-runtime relaxations:
`com.apple.security.cs.allow-unsigned-executable-memory` and
`com.apple.security.cs.disable-library-validation`, applied by
`scripts/nativephp_developer_id_signing.php` alongside `hardenedRuntime: true`.

The recorded claim was that the App Sandbox a Mac App Store build must run under
*ignores one of them*. No Apple documentation, forum answer from Apple
engineering, or toolchain issue supports that. The two are Hardened Runtime
exceptions; the App Sandbox is an independent mechanism, and neither entitlement
has an App Sandbox counterpart for it to override. The rule the claim appears to
be a misapplication of is real — an entitlement that exists in *both* namespaces
is ineffective unless both grant it — but it does not reach either of these.

What actually happens on the App Store path is that both go inert together,
because that build runs with the Hardened Runtime **off**, and a Hardened
Runtime exception does nothing when the Hardened Runtime is disabled. Neither is
refused at signing: both are unrestricted entitlements that need no provisioning
profile to claim.

The premise underneath the claim is also doubtful. The interpreter is `exec`'d
as a child process, not loaded into the Electron process, and library validation
governs libraries loaded *into* a process. If `disable-library-validation` is
load-bearing today it is most likely for Electron's own native modules. Removing
it from a Developer ID build and launching would settle that in one build, and
is worth doing before anyone plans around it.

### What a Mac App Store build would actually require

Not a submission, and not an entitlements edit — a runtime and data-path change.
Spawning a bundled interpreter under the App Sandbox is explicitly supported by
Apple and is *not* the blocker. These are:

| Work | Size |
|---|---|
| ~~Adopt the `mas` Electron distribution and a second electron-builder target~~ — **done**. `scripts/nativephp_mac_app_store_lane.php` adds the block beside the Developer ID one, sandboxed and explicitly **not** hardened | — |
| ~~New entitlement files~~ — **done**. `entitlements.mas.plist` and `entitlements.mas.inherit.plist`, the second exactly two keys long | — |
| ~~Move the interpreter to `Contents/MacOS/`~~ — **done**. `scripts/nativephp_interpreter_into_macos.php` adds it to the mas lane's `extraFiles` and teaches the shell to prefer it; verified below | — |
| Relocate the data path into the sandbox container, and move `.env` and `bootstrap/cache` out of the bundle, which is read-only on an installed store build. Existing direct-download ledgers do not follow into a container | L |
| ~~Rework file intake~~ — **there is no open panel to inherit a grant from**; read from the code rather than measured, see below | — |
| Confirm a loopback listener and mDNS work under the sandbox — measured, not read | M, uncertain |
| Prove every spawned process is reaped on quit | S/M |
| Apple Distribution + Mac Installer Distribution identities instead of Developer ID; a `.pkg` rather than a `.dmg`; no notarisation on that lane | S+M |
| Remove self-update on that channel — required, and Electron disables `autoUpdater` in `mas` builds anyway. Both off switches already exist | S/M |
| Two-channel release engineering, and review risk on a bundled interpreter with no precedent found either way | M + unknown |

#### File intake needs no PowerBox grant, because it never had one

Scoped as M/L on the reasoning that a child process inherits only static
rights, so an open panel's grant would not reach PHP. Reading the paths, no
such grant exists to be inherited:

| direction | how it actually works | under the sandbox |
|---|---|---|
| statements, receipts, a backup coming **in** | Livewire `WithFileUploads` — an `<input type="file">` in the WebView. The browser reads the bytes and POSTs them to the loopback server | unaffected; PHP never opens the reader's path |
| an export or backup going **out** | PHP answers with a `BinaryFileResponse`; the shell's download handler writes it wherever the reader chooses | unaffected; the grant belongs to Electron, which has it |
| the auto-import **drop folder** | `storagePath('app/inbox-drop/<user>')` — an app-owned directory, not one the reader nominates | works, and moves into the container with the rest of the storage root |

The drop folder is the only one that changes for a reader, and it changes in
discoverability rather than in function: inside a container it is several levels
down in `~/Library/Containers`. That is listing copy under `F8-R26`, not a
capability that dies.

This is read from the code, not measured on a sandboxed build. The distinction
matters: everything above under "measured" was run.

#### The layout and the runtime, proven together

`scripts/measure_sandboxed_interpreter.php` builds a second bundle in the store
layout — interpreter at `Contents/MacOS/php`, signed with the two-key inherit
file, spawned by a parent signed with the full sandbox entitlements — because
that is the shape the shell actually uses:

| | |
|---|---|
| parent home | container |
| child spawned | ok |
| child exit | 0 |
| child home | **same container** |
| child loopback | bound |
| child pcre | works |
| child run directly | **killed at launch, exit 133 (expected)** |

The last row is the one worth reading twice. `com.apple.security.inherit` has
nothing to inherit from when nobody sandboxed the parent, so that binary is
SIGTRAP'd every time it is launched from a shell. It is reported here so nobody
later reads it as the relocation having broken something — the only honest test
of an inheriting child is a sandboxed parent spawning it.

Reviewed as well as run. Two bundles built in the two layouts and signed the
same way:

| bundle | `desktop:review-mac-bundle` |
|---|---|
| interpreter under `Contents/Resources/build/php/` | refused, and named |
| interpreter at `Contents/MacOS/php` | nothing refused |

#### What the store lane still needs from Apple, not from this repository

Two artefacts exist only in the Apple Developer portal, and the build fails
loudly without them rather than producing something unsubmittable:

- a **Mac App Store provisioning profile**, base64-encoded into the
  `MAS_PROVISIONING_PROFILE_BASE64` repository secret. The workflow decodes it
  to `build/embedded.provisionprofile` and reads the file back: base64 of an
  empty secret decodes to an empty file without complaining, and an empty
  profile fails much later as a signing error nobody can place.
- an **Apple Distribution** identity, as the `NATIVEPHP_MAS_IDENTITY`
  repository variable — the certificate's common name, not the whole
  `find-identity` line. This one is already held.
- a **Mac Installer Distribution** identity, for the `.pkg` the store takes in
  place of a `.dmg`. That certificate is a separate one and is **not** yet
  held.

Neither the identity nor the profile is written into the config unless **both**
are present. Half a config would turn a missing prerequisite into a confusing
signing error; the whole one missing makes electron-builder say so plainly.

### Running the store lane

`release (Mac App Store)` is **dispatch-only**, and deliberately not part of
`release.yml`. The store build needs artefacts that live in the Apple Developer
portal, and a release that fails because one of them has not been created yet is
a direct-download channel that stops shipping for a listing it does not depend
on. That is what "distribution is additive" means in practice.

It builds the Developer ID target first — `native:build` is what prepares the
tree, the interpreter and the CA certificate — then runs electron-builder once
more against the same prepared tree with `--mac mas`, so the store config block
is read rather than the whole preparation repeated.

Then it reads what it built with `desktop:review-mac-bundle`, and fails if it
found no bundle at all: a `find` that matched nothing and a bundle with no
findings print the same thing.

The store bundle carries **no updater**. Apple forbids an app updating itself
outside the store, and Electron disables `autoUpdater` in a `mas` build anyway —
writing `NATIVEPHP_UPDATER_ENABLED=false` into the shipped `.env` means the
in-app check is off too, rather than only the mechanism.

#### Reading a bundle instead of trusting the config

`php artisan desktop:review-mac-bundle <path-to.app>` opens a built bundle and
reports every reason App Store review would refuse it: an unsandboxed app, any
of the four entitlements the store does not take, an unsigned nested
executable, one living outside a `Contents/MacOS` directory, and a helper that
combines `inherit` with another sandbox right.

The rules are **calibrated against bundles Apple actually shipped**, which is
the only way to tell a real rule from a plausible one. Two of them were wrong
until a real store app said so:

| what the rule said | the bundle that disproved it |
|---|---|
| a nested helper must carry exactly `app-sandbox` + `inherit` | Amphetamine's login helper holds `files.user-selected.read-write` beside `app-sandbox`, because a nested `.app` is its own sandboxed program rather than an inheriting child |
| every executable in a sandboxed app must be sandboxed | Apple Configurator ships an unsandboxed `cfgutilscript` in its own `MacOS` directory; the rule only holds for an executable the app *launches* |

An Electron helper under `Contents/Frameworks` is recognised structurally. The
interpreter is not — a spawned binary and a bundled data file that happens to
be Mach-O look identical on disk — so the command names it explicitly.

The calibration itself runs in CI, against a recording rather than against
whatever happens to be installed: `php artisan desktop:record-mac-bundles`
walks `/Applications`, takes up to three bundles carrying a `_MASReceipt` plus
one Electron app without one, and writes what it read to
`Modules/Desktop/tests/Fixtures/mac-bundles-observed.json`. It refuses to write
a recording with only one side, because a fixture of store apps alone would let
rules that refuse nothing read as calibrated. Re-run it when Apple changes what
it accepts, and the diff is the change.

#### What the sandbox actually does to the interpreter, measured

The two unknowns recorded as "answerable only by building a sandboxed bundle
and measuring one" are answered. `php scripts/measure_sandboxed_interpreter.php`
copies the bundled interpreter into a minimal `.app`, signs it **ad-hoc** with
the store lane's sandbox entitlements — no keychain, no Apple identity, so it
reproduces on any Mac — and runs the same probe twice:

| probe | sandboxed | unsandboxed (control) |
|---|---|---|
| pcre jit ini | 1 | 1 |
| pcre match | works | works |
| loopback listener | bound | bound |
| loopback connect | connected | connected |
| udp socket | ok | ok |
| mdns group join | ok | ok |
| mdns send | 12 bytes | 12 bytes |
| proc_open | ok | ok |
| home | container | real home |
| mkdir under home | ok | ok |
| sqlite write | ok | ok |
| read /etc/hosts | allowed | allowed |
| write /tmp | **REFUSED** | allowed |

**Everything LAN sync needs survives.** A loopback listener binds and accepts,
a multicast group join and send to `224.0.0.251:5353` both succeed, outbound
TCP reaches the stack, and a child process spawns. So does SQLite, into the
container.

Two details matter more than they look:

- **The control run is the point.** A failure that reproduces unsandboxed is
  not a sandbox finding. `bind 5353` fails either way on a machine where
  another process already holds the mDNS port — reading that as a sandbox
  restriction is exactly how this measurement would lie.
- **PCRE's JIT works with no JIT entitlement at all.** The store lane's
  entitlements grant `com.apple.security.cs.allow-jit`, and this suggests even
  that is not load-bearing. A sandboxed build is not hardened by default —
  electron-builder hardens `mas` only when told to — so writable-executable
  memory is not being restricted in the first place.

The remaining cost is the one the measurement confirms rather than removes:
`HOME` is redirected to `~/Library/Containers/<bundle-id>/Data`, and the
sandboxed build cannot read the real home. A direct-download ledger does not
follow a reader into the store build, and that is a migration story, not a
runtime problem.

That redirect is also why the data-path work is smaller than it was scoped.
The shell sets `NATIVEPHP_STORAGE_PATH` to `join(app.getPath('userData'),
'storage')` and `bootstrapCache` to `join(app.getPath('userData'), 'bootstrap',
'cache')` — both already outside the read-only bundle, and `userData` derives
from the Application Support directory the sandbox redirects. **Expected to
relocate with no code change; measured for the interpreter, not yet for
Electron.** Measuring it is the first step of the build lane, not an
assumption to build on.

A sandboxed process also needs a bundle identity or the kernel kills it at
launch — SIGTRAP, exit 133, no output at all. The script refuses an empty
result table for that reason: a run that produced nothing reads exactly like a
run that found no problems.

#### What the interpreter actually needs, measured

The runbook used to carry both Developer ID relaxations as the cost of the
embedded interpreter. Asking the shipped binary narrows it:

| setting | value | consequence |
|---|---|---|
| `opcache.enable_cli` | `0` | PHP's own JIT never runs |
| `opcache.jit` | `disable` | compiled in by `enable-opcache-jit`, never used |
| `pcre.jit` | `1` | the one live consumer of writable-executable memory |
| linkage | static, no shared objects | nothing for library validation to reject |

So the store lane needs `com.apple.security.cs.allow-jit`, which **is**
permitted for App Store distribution, and neither
`allow-unsigned-executable-memory` nor `disable-library-validation`. The
alternative to `allow-jit` is `pcre.jit=0`, which the interpreter accepts —
that is a performance trade on regex-heavy paths, not a correctness one.

### The Microsoft Store is much cheaper than it looks

MSIX is *recommended*, not required. An EXE/MSI listing is a first-class product
type: you host the installer yourself, submit a versioned immutable download
URL, and the Store neither repackages nor re-signs it. That keeps the existing
NSIS artefact, keeps Azure Trusted Signing relevant — it becomes mandatory
rather than optional — and keeps self-update, which no policy bars for a
non-game desktop app on that path.

Choosing MSIX instead would import the same read-only-install-directory problem
as the Mac App Store, plus the loss of self-update, for no gain.

#### Why the per-file signature needed a patch at all

electron-builder decides what to sign in `WinPackager.shouldSignFile`, and with
`win.signExts` absent the answer for anything that is not an `.exe` is *no*:

```js
const backwardCompatibility = file.endsWith(".exe");
const signExts = this.platformSpecificBuildOptions.signExts;
if (!signExts?.length) {
    return backwardCompatibility || fallbackValue;   // fallbackValue is false
}
```

The bundle carries a whole PHP runtime under `extraResources`. `php.exe` was
signed; `php8ts.dll`, every bundled extension and every support library beside
it were not. Nothing said so: Windows checks the signature of what it is asked
to launch, and both the installer and the shell are signed, so a direct
download looked correct. Store certification is the first reader that opens
every file.

`scripts/nativephp_sign_every_pe_in_the_package.php` adds the key as a prebuild
hook. The workflow step that follows the installer check is the part that
proves it: it walks `dist/*unpacked*`, runs `Get-AuthenticodeSignature` over
every `.exe`, `.dll` and `.node`, and fails on any status other than `Valid` —
and also fails if it walked the tree and found *nothing*, because a check that
read no files passes identically to a package that is fully signed.

| Work | Size |
|---|---|
| A **Company** Partner Center account — an individual account cannot be converted, and financial features require a company. Start first; the verification latency is the long pole | S effort, 1–2 weeks |
| ~~Confirm **every** PE in the installer is signed~~ — **done**. `win.signExts` now covers `.exe`, `.dll` and `.node`, and both release workflows walk the unpacked package with `Get-AuthenticodeSignature` and refuse a build carrying an unsigned binary. See below | — |
| Silent install as a standard user, correct add/remove-programs metadata, clean uninstall — all three are certification tests, not guidance | S/M |
| A release step that publishes the new versioned URL to Partner Center | M |
| A privacy policy covering the LAN sync and the financial data, with express consent | M |
| Listing assets, age rating, localised descriptions, and the review notes above | M |
| An ARM64 interpreter, or an x64-only listing | M, uncertain |

## Related

- [The mobile release runbook](mobile-release.md) — building and signing the artefacts this page declares
- [Repo security setup](repo-security-setup.md) — the desktop signing identities
- [A purpose string in every language](../features/mobile/a-purpose-string-in-every-language.md)
- [The console on a shipped build](../features/dev-mode/the-console-on-a-shipped-build.md)
