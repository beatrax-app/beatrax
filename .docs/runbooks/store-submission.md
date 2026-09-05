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
> household: *(control not yet reachable — see below.)* Every figure in it is
> invented; it is not any real person's finances.
>
> **What will not work in review, and why.** Syncing needs a second device of
> your own on the same network, and pairing is by scanning a code that device
> shows. Nothing about it reaches the internet, so there is nothing to
> demonstrate against a server.

### The sample-data control does not exist yet

`demo:seed` is an artisan command marked developer-only, reachable from a
terminal and a Composer toolchain. Neither exists inside a signed store build,
so a reviewer cannot load sample data at all today, and the paragraph above
cannot be completed honestly until an in-app control exists.

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
| Adopt the `mas` Electron distribution and a second electron-builder target; the signing hook injects `hardenedRuntime: true` unconditionally today | M |
| New entitlement files: sandbox + `allow-jit` for the app, and exactly `app-sandbox` + `inherit` for the child — Apple aborts a child carrying any other sandbox entitlement | S |
| Move the interpreter from `Contents/Resources/build/php/` to `Contents/MacOS/`, where nested executables are required to live, and sign it with that pair | M |
| Relocate the data path into the sandbox container, and move `.env` and `bootstrap/cache` out of the bundle, which is read-only on an installed store build. Existing direct-download ledgers do not follow into a container | L |
| Rework file intake: a child process inherits only *static* rights, so PowerBox grants from an open panel do not reach the PHP side | M/L |
| Confirm a loopback listener and mDNS work under the sandbox — measured, not read | M, uncertain |
| Prove every spawned process is reaped on quit | S/M |
| Apple Distribution + Mac Installer Distribution identities instead of Developer ID; a `.pkg` rather than a `.dmg`; no notarisation on that lane | S+M |
| Remove self-update on that channel — required, and Electron disables `autoUpdater` in `mas` builds anyway. Both off switches already exist | S/M |
| Two-channel release engineering, and review risk on a bundled interpreter with no precedent found either way | M + unknown |

### The Microsoft Store is much cheaper than it looks

MSIX is *recommended*, not required. An EXE/MSI listing is a first-class product
type: you host the installer yourself, submit a versioned immutable download
URL, and the Store neither repackages nor re-signs it. That keeps the existing
NSIS artefact, keeps Azure Trusted Signing relevant — it becomes mandatory
rather than optional — and keeps self-update, which no policy bars for a
non-game desktop app on that path.

Choosing MSIX instead would import the same read-only-install-directory problem
as the Mac App Store, plus the loss of self-update, for no gain.

| Work | Size |
|---|---|
| A **Company** Partner Center account — an individual account cannot be converted, and financial features require a company. Start first; the verification latency is the long pole | S effort, 1–2 weeks |
| Confirm **every** PE in the installer is signed, `php.exe` and its DLLs included — the requirement is per-file, and a default electron-builder config misses a bundled interpreter | S/M |
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
