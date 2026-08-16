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

## Before a release

Set the version. Absent, builds carry `0.0.0-dev` / versionCode 1:

```sh
cd mobile-app
php artisan native:release patch     # or minor / major
```

`NATIVEPHP_APP_VERSION_CODE` must only ever increase. The scheme in use is
`major*10000 + minor*100 + patch`, so it can be derived from the tag rather
than incremented by hand.

Check the bundle id. `config/nativephp.php` defaults to `com.beatrax.mobile`,
which is what the App Store profile and Bifrost expect. An override in
`mobile-app/.env` beats that default — this is how builds once went out as the
framework's `com.wessel.stormlunarbold` placeholder.

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
php artisan native:package android --build-type=release
```

Use `native:package`, never `native:run --build=release` — the latter has no
signing validation, shells straight to `assembleRelease`, and prints
"Build complete!" over an `app-release-unsigned.apk`.

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
