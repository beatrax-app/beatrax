# Signing identities

Every identity and credential the release pipeline requires, with its expiry and the
command or page that expiry is read from. One page, because the inventory used to live
in three and that is how five of them ended up recorded nowhere at all.

An expiry nobody is watching stops releases, and most of the ways this pipeline can
lose an identity are silent: `electron-builder.mjs` drops `azureSignOptions` when any
`NATIVEPHP_AZURE_*` value is empty and ships an unsigned installer from a green build;
the macOS Developer ID prebuild hook exits non-zero into a `runProcess()` that swallows
it. The workflows guard both sides of that — a build step that fails on an empty value,
and an artifact interrogated after the fact — but neither guard fires *before* a
certificate lapses. **Nothing warns. Read this page before a release.**

## The register

`Held as` names the GitHub repository secrets and variables an identity is made of.
Identities held in Bifrost's own credential panel carry no GitHub name; they are still
required, and Bifrost is where they are replaced.

| Identity | What it signs or unlocks | Held as | Expires | Where that date comes from |
|---|---|---|---|---|
| Developer ID Application certificate | The macOS `.app` inside the `.dmg` | `CSC_LINK`, `CSC_KEY_PASSWORD`, `NATIVEPHP_MAC_IDENTITY` | 2027-02-01 | `security find-certificate -c "Developer ID Application: Wessel Verheij" -p \| openssl x509 -noout -enddate` — capped by the Developer Program membership rather than the usual five years |
| Apple notarisation credentials | Submission to Apple's notary service | `NATIVEPHP_APPLE_ID`, `NATIVEPHP_APPLE_ID_PASS`, `NATIVEPHP_APPLE_TEAM_ID` | Never | An app-specific password carries no expiry; it is valid until revoked at appleid.apple.com → Sign-In and Security → App-Specific Passwords |
| Azure Trusted Signing certificate profile | The Windows `.exe` and `.msi` | `NATIVEPHP_AZURE_ENDPOINT`, `NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME`, `NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME`, `NATIVEPHP_AZURE_PUBLISHER_NAME` | 2028-10-17 | The identity validation behind profile `beatrax-public-trust`, in the Azure portal blade. It has no ARM surface, so it cannot be asserted from a script |
| Azure service-principal client secret | Authenticating to Trusted Signing | `AZURE_TENANT_ID`, `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET` | 2027-07-15 | `az ad app credential list --id <AZURE_CLIENT_ID> --query "[].endDateTime"` — the `rbac` secret, hint `S6~` |
| Android release keystore | The sideloadable `.apk` and every Play artefact | `ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`, `ANDROID_SIGNING_CERT_SHA256` | 2056-07-07 | `keytool -list -v -keystore app-release-key.jks` → *Valid from … until*. **Never replace this key**: a new one means existing installs cannot upgrade, only be uninstalled, and the ledger goes with the application |
| Ed25519 publisher key | The auto-update manifests and the release checksum file | `ED25519_PRIVATE_KEY` | Never | A raw libsodium keypair has no validity period. Rotation is a source change to the `publisher_public_key_hex` literal in `config/auto_update.php`; installed bundles keep verifying the old key until they cross over, so a rotation is a release, not an edit |
| NativePHP licence | Installing the paid `nativephp/mobile-*` packages from `plugins.nativephp.com` | `NATIVEPHP_LICENSE_EMAIL`, `NATIVEPHP_LICENSE_KEY` | Unread | The NativePHP account's subscription renewal date, at nativephp.com |
| Bifrost build-repo token | Pushing the materialized mobile tree to `beatrax-app/mobile-build` | `BIFROST_BUILD_TOKEN`, `BIFROST_BUILD_REPO` | Unread | github.com/settings/tokens?type=beta → the token's *Expires on*. A fine-grained PAT, so it has one |
| Workflow token | Reading the repo and editing the draft release | `GITHUB_TOKEN` | Per job | Minted by GitHub for the job and discarded with it. Nothing to renew |
| Apple Distribution certificate | The iOS `.ipa`, signed by Bifrost | Bifrost → Credentials → iOS | 2027-07-03 | `openssl pkcs12 -in <p12> -nokeys \| openssl x509 -noout -enddate`, or Apple Developer → Certificates |
| App Store Connect API key | Uploading a build to App Store Connect | Bifrost → Credentials → iOS (`AuthKey_M6KAC397L3.p8`) | Never | App Store Connect API keys carry no expiry; they are valid until revoked at App Store Connect → Users and Access → Integrations |
| App Store provisioning profile | Binding the iOS bundle id to the distribution certificate | Bifrost → Credentials → iOS (`AppStore_com.beatrax.mobile.mobileprovision`) | Unread | `security cms -D -i AppStore_com.beatrax.mobile.mobileprovision \| plutil -extract ExpirationDate raw -` |

A local iOS distribution build reads the same App Store Connect key through
`APP_STORE_API_*` in `mobile-app/.env` rather than from Bifrost;
`mobile-app/config/nativephp.php` names those keys in `cleanup_env_keys`, so they
are stripped before the bundle is written.

`Unread` means the identity has an expiry and nobody has read it yet. It is the one
placeholder this table accepts, and it is spelled that exact way so it can be grepped;
`EverySigningIdentityThePipelineNeedsIsRecordedArchTest` refuses any other
prose in that column. The reason for the rule is on this page's own history: a Trusted
Signing variable once held the literal text `(pending — set after cert profile is
created)`, copied out of 1Password, and every check confirmed the credential *existed*
rather than that it *resolved*.

## What the check enforces

`EverySigningIdentityThePipelineNeedsIsRecordedArchTest` compares this table
against the workflows, both directions:

- Every `secrets.*` and `vars.*` name referenced by a release-pipeline workflow appears
  in some row's `Held as` cell. A credential added to a build without a row here fails.
- Every name a row claims is referenced by one of those workflows. A row for a
  credential the pipeline no longer uses fails too, so the table cannot quietly become
  a museum.
- Every row carries an expiry and a source, and the expiry is an ISO date or one of
  `Never`, `Per job`, `Unread`.

A release-pipeline workflow is one that builds an installable bundle, uploads to a
release page, or publishes to the mobile build repo — detected from the workflow body
rather than named, so a new one is in scope the day it is written.

What the check cannot see is the three iOS rows: Bifrost holds them, no workflow names
them, and nothing here can query that panel. They are in the table because the pipeline
needs them, not because a test can prove it.

## Related

- [Repo security setup](repo-security-setup.md#release-signing) — how the desktop
  signing values are created and put in place, and why every gap in them fails silently
- [Mobile release](mobile-release.md) — what has to be uploaded to Bifrost's panel, and
  the after-release check that the published APK is signed by the expected key
- [Store submission](store-submission.md) — what a store listing has to declare
- [Verifying a release](verify-release.md) — the Ed25519 chain the publisher key signs
