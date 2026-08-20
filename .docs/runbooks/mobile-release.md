# Mobile Release

How an Android or iOS build is produced, signed and distributed. The desktop
equivalent is [`release-cut.md`](release-cut.md); the credential inventory is
in [`repo-security-setup.md`](repo-security-setup.md#release-signing).

This file exists because the configuration it describes has already been lost
twice. `mobile-app/.env` is machine-local and gitignored, so a re-minted copy
silently drops the signing keys — and every resulting failure is quiet. See
[`mobile-app/.env.example`](../../mobile-app/.env.example) for the key list.

## Two roots, two pipelines

`mobile-app/` is a second Composer root with its own `vendor/`, sharing the
domain code by symlink. Nothing in the desktop release pipeline touches it.

| Path | Produces | Signed by |
|---|---|---|
| Bifrost cloud | store-shaped builds (AAB / IPA) | credentials uploaded to Bifrost's own panel |
| `release.yml` `build (Android)` | the public sideloadable APK | the release keystore, from repo secrets |
| local `native:package` | ad-hoc builds for testing | whatever `mobile-app/.env` supplies |

Bifrost builds from `beatrax-app/mobile-build`, a fully generated mirror with a
single writer (`mobile-bifrost-publish.yml`). Never hand-edit that repo.

### The generated-shell patches and Bifrost

`materialize.sh` carries `scripts/nativephp_*.php` into the build repo, and
`NativeBuildPatches::locate()` finds them there. The mechanics are below, under
[The patch scripts in a Bifrost tree](#the-patch-scripts-in-a-bifrost-tree) —
read that section, not this heading, for what actually happens.

## Before a release

Set the version. Absent, builds carry `0.0.0-dev` / versionCode 1:

```sh
cd mobile-app
php artisan native:release patch     # or minor / major
```

`NATIVEPHP_APP_VERSION_CODE` must only ever increase. The scheme in use is
`major*10000 + minor*100 + patch`, and `mobile:package-android` derives it from
the version rather than trusting anything to have set it — CI exports only
`NATIVEPHP_APP_VERSION`, so an APK built without that derivation carries
nativephp/mobile's package default of 1 and Play refuses it as a downgrade.
The command reads the number back out of the generated Gradle file afterwards,
so "it was set" and "it shipped" are two separate checks.

Note that `native:release` writes `NATIVEPHP_APP_VERSION` alone, and on a `.env`
freshly copied from the template it writes nothing: it matches the key inside
the commented line, takes its update branch, and that branch's regex finds no
real assignment to replace. It reports success either way.

Check the bundle id. `mobile-app/config/nativephp.php` defaults to
`com.beatrax.mobile`, which is what the App Store profile and Bifrost expect —
but `mobile-app/.env` must carry it as a real assignment as well, because
`native:install` reads that file rather than `config()`. A commented or blank
key reads to it as absent, and it then generates `com.<user>.<random words>`,
writes that back and the build ships under it. That is how builds once went out
as `com.wessel.stormlunarbold`. `mobile:package-android` refuses to build
without the pinned key, and after packaging reads the `applicationId` back out of
the generated Gradle to confirm the APK carries the id it was meant to.

## Android

The public APK is built by CI from `ANDROID_KEYSTORE_BASE64` and its three
companions. The job refuses to run when any is empty, and verifies the
finished APK's **signer fingerprint** against `ANDROID_SIGNING_CERT_SHA256`.

Fingerprint rather than mere presence of a signature, because
`apksigner verify` passes on a debug-signed APK and Gradle's release type
silently drops its signing config when the keystore is absent. "Has a
signature" is not the property worth asserting.

For a local release build, restore the keystore from 1Password to
`mobile-app/credentials/app-release-key.jks` (that directory is gitignored)
and set the four `ANDROID_*` keys. Then:

```sh
cd mobile-app
php artisan mobile:package-android --build-type=release
```

Use `mobile:package-android`, never `native:package` directly and never
`native:run --build=release`. `native:run` has no signing validation, shells
straight to `assembleRelease`, and prints "Build complete!" over an
`app-release-unsigned.apk`. `native:package` does validate, but declares
`handle(): void` — so it exits 0 whether it built an APK, refused for want of an
Android project, or watched Gradle fail. The wrapper turns each of those into a
failure and names what is missing.

## iOS

Distribution goes through Bifrost, which signs from the `.p12` and provisioning
profile uploaded to its iOS credentials panel. Both live in the Beatrax
1Password vault:

- `beatrax iOS distribution .p12 (com.beatrax.mobile)` + its export password
- `Beatrax signing cer + mobileprovision` (the App Store profile)
- `Key beatrax iOS signing config` (the App Store Connect API key)

A local development build needs only `IOS_TEAM_ID`; Xcode automatic signing
handles the rest. A local *distribution* build additionally needs
`IOS_DISTRIBUTION_CERTIFICATE_PATH` and `_PASSWORD`. Quote the password in
`.env` — an unquoted `#` truncates it silently.

## Bifrost credentials — what must be uploaded

Bifrost signs from credentials held in its own panel, not from this repository
or from GitHub secrets. Nothing here can verify that panel, so this is the
checklist to work through in its UI. Every source below is in the Beatrax
1Password vault.

**Android** — Credentials → Android:

| Field | 1Password source |
|---|---|
| Keystore file | `beatrax Android release keystore (app-release-key.jks)` |
| Keystore password | `beatrax Android release keystore — signing config` → `password` |
| Key alias | same item → `key alias` |
| Key password | same item → `key password (keypass)` |

The keystore must be the one whose SHA-256 matches the fingerprint on that
item — a different key means existing installs cannot upgrade, and there is no
recovering from having shipped one.

**iOS** — Credentials → iOS:

| Field | 1Password source |
|---|---|
| Distribution `.p12` | `beatrax iOS distribution .p12 (com.beatrax.mobile)` |
| `.p12` password | `beatrax iOS distribution .p12 — export password` |
| Provisioning profile | `Beatrax signing cer + mobileprovision` → `AppStore_com.beatrax.mobile.mobileprovision` |
| App Store Connect key | `Key beatrax iOS signing config` → `AuthKey_M6KAC397L3.p8` |
| Key ID / Issuer ID | `beatrax iOS signing config (com.beatrax.mobile)` |

The `.p12` contains only the Apple Distribution identity. A keychain export
(`security export -t identities`) emits every identity on the machine,
including the Developer ID key used for the desktop app; that one belongs
nowhere near a mobile build service.

Note `Beatrax signing cer + mobileprovision` also holds `2GDW586LZ6.cer` — the
public half only, and a different team id from the `NV5645J73B` on the
certificates actually in use. Confirm which team the App Store app is
registered under before relying on it.

## After a release

Confirm the APK on the Release page is signed by the expected key:

```sh
apksigner verify --print-certs beatrax-<version>.apk
```

The SHA-256 must match the fingerprint recorded on the keystore's 1Password
item. A mismatch means it was signed by something else — do not distribute it.

## Expiry

The iOS distribution certificate expires **2027-07-03**. The Android keystore
does not expire, and must never be replaced: a new key means users cannot
upgrade over an existing install.

## The patch scripts in a Bifrost tree

`materialize.sh` carries `scripts/nativephp_*.php` into the output, because the
build repo *is* the mobile root standing on its own — `../scripts`, which is how
the dev tree reaches them, climbs out of it. `NativeBuildPatches::locate()`
probes `scripts/` before `../scripts/` and identifies the right one by content,
since both trees have a `scripts/` directory and only one has the patches in it.

`mobile-app/composer.json` still spells those hooks `../scripts/…`. That is
correct for the dev tree and inert everywhere else: `post-update-cmd` does not
fire for `composer install`, which is what CI and Bifrost run. The patches reach
a built artifact through `NativeBuildPatches`, not through composer — running
`composer update` inside a materialized tree would fail on those paths.

**Carrying the scripts is not the same as the scripts working there.** Every
script written before this note resolved the generated project as
`__DIR__/../mobile-app/nativephp/…`. In the dev tree that is right. In a
materialized tree the script sits at `<build-repo>/scripts/`, the project is at
`<build-repo>/nativephp/`, and `../mobile-app/nativephp` does not exist — so
each one printed *"no Android scaffold yet — skipping"* and exited 0. A Bifrost
AAB or IPA built that way carried NativePHP's own "N" icon, no WebView camera
permission and `allowBackup="true"`, from a clean green log. The first of those
is an instant rejection on both stores; the last means `adb backup` can pull the
ledger and staged key material off a device with USB debugging on.

Every one of them now resolves through `scripts/nativephp_scaffold_root.php`,
which probes `<root>/mobile-app/nativephp/…` then `<root>/nativephp/…`, with
`BEATRAX_NATIVE_ROOT` overriding both for a tree in neither shape. A skip now
means there is genuinely no scaffold, not that the script was looking in the
wrong place. `ScaffoldPatchesFindEitherRootTest` fails if one is pinned back to
a literal path, and exercises the materialized shape against a temporary tree.

## Export compliance and the French declaration

`nativephp_ios_export_compliance.php` writes `ITSAppUsesNonExemptEncryption`
= `true` into the iOS `Info.plist`, so App Store Connect stops re-asking the
export questionnaire on every upload. The answer is `true` because the app's
crypto is libsodium via PHP's `sodium` extension — a third-party library linked
into the bundle — and not the OS's own. Apple's documentation is explicit that
the answer covers "any third-party libraries you link against".

That answer has a paperwork consequence which is **not** Apple's to grant and is
not done. Ed25519, XChaCha20-Poly1305 and BLAKE2b are industry-standard
algorithms from outside the OS, which puts the app in the row of Apple's
documentation matrix requiring a **French encryption declaration (ANSSI)**. No
US CCATS follows — that row is for proprietary algorithms, and none are used.

Start the ANSSI declaration before France is in the release territories. When
ANSSI issues a code it goes in `ITSEncryptionExportComplianceCode` beside the
existing key; filing it also retires the annual BIS self-classification report,
which is otherwise owed for relying on exempt forms.
