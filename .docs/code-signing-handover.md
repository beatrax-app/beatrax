# Code-Signing Handover — beatrax (all platforms)

> **Status as of 2026-07-16.** Ad-hoc effort (not a GSD phase). Goal: real
> code-signing for all four distribution targets. **3 of 4 are signing
> end-to-end; Windows is fully set up and only waiting on an Azure identity-
> validation clock.** Pick this back up any time — everything needed is below.
>
> **Update 2026-07-24 — mobile builds are moving to Bifrost.** The two *mobile*
> targets (iOS + Android) are migrating their **release/distribution** builds to
> NativePHP's **Bifrost** cloud, which builds from credentials you upload to its
> Credentials panel (mint the iOS cert + profile with
> `mobile-app/scripts/create-ios-signing.sh`), not from local Xcode/gradle. The
> local App Store Connect API-key wiring in `mobile-app/config/nativephp.php` has
> been retired (`app_store_connect` block removed); `development_team` /
> `IOS_TEAM_ID` **stays** because local on-device `native:run` still signs dev
> builds with it. The two **desktop** targets (macOS, Windows) are unchanged —
> Bifrost is mobile-only. Setup is paused on a Bifrost bug. The local mobile
> signing details below are kept for reference and remain the fallback until
> Bifrost is confirmed green.

## TL;DR scorecard

| Platform | Status | What's left |
|---|---|---|
| **Android** | ✅ Signed (local) → moving to Bifrost | Release build moves to Bifrost cloud (upload the keystore to its Credentials panel). Local keystore + env + vault stay for the fallback path. |
| **iOS** | ✅ Dev-signing wired → distribution moving to Bifrost | Local `development_team` still signs on-device dev runs. App Store distribution now runs in Bifrost (upload the cert + profile from `scripts/create-ios-signing.sh`). Blocked on a Bifrost bug. |
| **macOS** | ✅ Signed + notarized + stapled + Gatekeeper-accepted | Nothing. Future builds auto-notarize. Optionally produce a `.dmg`. |
| **Windows** | ⏳ Blocked on Azure org identity validation (1–20 business days) | Once **Completed**: create cert profile → set 1 env var → build. ~10 min of work. |

Commits: `9246b0e4` (iOS/Android wiring + bundled-`.env` leak fix), `8c150b81`
(macOS Developer ID + notarization + build-pipeline fixes).

---

## The one real remaining task: **Windows** (do this when Azure validation completes)

Everything except the certificate profile is already built: the Azure
**Artifact Signing account** (`beatraxsigning`, rg `rg-beatrax-signing`, North
Europe), a **service principal** (`sp-beatrax-signing`, appId
`4b5fdaa6-05c5-44b5-b676-09f69f8b640e`) with the **Artifact Signing Certificate
Profile Signer** role at the account scope, and all six env values staged in
the Beatrax vault (`beatrax Windows signing — Azure service principal`).

**Check validation status** (portal only — the CLI/ARM cannot read it):
Azure Portal → Artifact Signing account `beatraxsigning` → **Identity
validations**. Org validation runs 1–20 business days; watch for "Action
Required" emails.

**When status = Completed:**

1. Copy the **Identity validation Id** (GUID) from that blade.
2. Create the certificate profile (Azure CLI is installed; `az login` as
   `info@nightworks.io`):
   ```bash
   az artifact-signing certificate-profile create \
     -g rg-beatrax-signing --account-name beatraxsigning \
     -n beatrax-public-trust --profile-type PublicTrust \
     --identity-validation-id <THE-GUID>
   ```
3. Wire `mobile-app`/desktop env (desktop build reads these). Add to the **root**
   `.env` (git-ignored; all are already in `cleanup_env_keys` / covered by the
   `AZURE_*` wildcard so they never ship):
   ```
   NATIVEPHP_AZURE_ENDPOINT=https://neu.codesigning.azure.net
   NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME=beatraxsigning
   NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME=beatrax-public-trust
   AZURE_CLIENT_ID=<from vault>
   AZURE_CLIENT_SECRET=<from vault>
   AZURE_TENANT_ID=37747d4d-06de-4e9f-89e0-ac5a1e825b6e
   ```
   (Client id/secret are in the vault item above; `electron-builder.mjs` already
   wires `azureSignOptions` from these — it no-ops when unset.)
4. Update the vault item's `NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME` field
   (currently `(pending …)`).
5. Build: `php artisan native:build win --no-interaction` → signs automatically.

Eligibility note: Public-Trust certs require **organization** validation for EU
entities (individual validation is US/Canada only). Nightworks' **KvK** number
is the business identifier — validation was submitted as Organization.

---

## Secondary / verification tasks

- **iOS distribution** is now Bifrost's job, so the old "run one local
  `native:build ios` to confirm headless distribution signing" task is retired.
  Instead: mint the distribution cert + provisioning profile with
  `mobile-app/scripts/create-ios-signing.sh` (output lands in git-ignored
  `mobile-app/build-secrets/`), upload both into Bifrost → Credentials → iOS,
  then trigger a Bifrost build. Local on-device dev runs (`native:run`) are
  unaffected — they sign with `development_team` / `IOS_TEAM_ID` as before.
- **macOS `.dmg`** (optional): this run notarized the `.app` directly (the build
  was interrupted mid-`notarize.js`). Future `native:build mac` runs notarize
  automatically. If you want a distributable `.dmg`, just re-run the mac build
  (below) — it'll produce and notarize the dmg.
- **Physical two-device import-pairing UAT** (unrelated to signing, still open):
  row 3 of `.planning/phases/beatrax-15-…/15-DEVICE-UAT.md` — the cross-device
  confirm gate on real separate hardware.

---

## Where every credential lives

**Beatrax 1Password vault** (`op signin` first; vault id
`zuxpcjri34dobkzpkbiunhsl6q`):
- `beatrax Android release keystore — signing config` (store/key passwords, alias, SHA-256)
- `beatrax Android release keystore (app-release-key.jks)` (the keystore file itself)
- `beatrax iOS signing config (com.beatrax.mobile)` (team, cert, ASC key pointers)
- `beatrax account specific password` (Apple ID app-specific pw for notarization; username `info@nightworks.io`)
- `beatrax Windows signing — Azure service principal` (AZURE_CLIENT_ID/SECRET/TENANT + endpoint/account)

**Git-ignored `.env` files** (never committed; stripped from bundles via `cleanup_env_keys`):
- `mobile-app/.env`: `ANDROID_KEYSTORE_FILE/PASSWORD`, `ANDROID_KEY_ALIAS/PASSWORD`, `IOS_TEAM_ID`, `APP_STORE_API_KEY_ID/ISSUER_ID/KEY_PATH`
- root `.env`: `NATIVEPHP_APPLE_ID`, `NATIVEPHP_APPLE_ID_PASS`, `NATIVEPHP_APPLE_TEAM_ID`, `NATIVEPHP_MAC_IDENTITY`

**Files on disk (git-ignored):**
- `mobile-app/credentials/app-release-key.jks` — Android keystore (chmod 600)
- `mobile-app/.signing/AuthKey_M6KAC397L3.p8` — account-level ASC API key (reused from Happklaar)

**Reference IDs:** Apple Team `NV5645J73B` · ASC key `M6KAC397L3` · ASC issuer
`529f941a-6bb1-4cce-bddc-eaab2c68e623` · Azure sub
`95c564af-00ad-4337-a9ef-d22bd51cfc3a` · Azure tenant
`37747d4d-06de-4e9f-89e0-ac5a1e825b6e` · macOS notary submission (Accepted)
`c724de41-0057-4f16-bce0-edd06013bcef`.

---

## Host prerequisites to reproduce a build

- **Node**: v26.2.0 via nvm. Builds need it on PATH:
  `export PATH="$HOME/.nvm/versions/node/v26.2.0/bin:$PATH"` (the desktop
  build's subshells don't inherit nvm otherwise).
- **JDK**: openjdk@17 — now on PATH + `JAVA_HOME` via `~/.zshrc` (Android keytool).
- **Azure CLI**: installed (`az`); `az login` as `info@nightworks.io`. Extension `artifact-signing` added.
- **1Password**: `op signin` (desktop-app biometric) to read the Beatrax vault.
- **Signing identities in login keychain**: `Developer ID Application: Wessel
  Verheij (NV5645J73B)` (macOS) + `Apple Distribution: Wessel Verheij (NV5645J73B)` (iOS).

**macOS build command** (from repo root):
```bash
export PATH="$HOME/.nvm/versions/node/v26.2.0/bin:$PATH"
php artisan native:build mac arm64 --no-interaction
```
Signed output: `nativephp/electron/dist/mac-arm64/beatrax.app`. Verify with:
```bash
spctl -a -t exec -vvv nativephp/electron/dist/mac-arm64/beatrax.app
# → accepted / source=Notarized Developer ID
```

---

## How the desktop signing pipeline works (so it's maintainable)

Two committed **prebuild hooks** (in `config/nativephp.php` → `prebuild`, run on
every build, idempotent — no manual reapply):
- `scripts/nativephp_developer_id_signing.php` — pins `mac.identity` from
  `NATIVEPHP_MAC_IDENTITY` + Hardened Runtime so the WHOLE bundle (shell +
  nested Electron Framework/helpers) signs with one Team ID. Replaced the old
  `nativephp_force_adhoc_signing.php` (kept for local unsigned dev builds).
- `scripts/nativephp_fix_php_binary_extraction.php` — patches NativePHP's
  `php.js` to extract the static PHP Mach-O byte-exact via `ditto`/`unzip`. The
  upstream `yauzl` streaming pipe inflates the arm64 binary ~5 MB → codesign
  rejects it ("main executable failed strict validation"). **This was the
  hardest blocker.**

Notarization runs via NativePHP's `build/notarize.js` afterSign hook using
`NATIVEPHP_APPLE_ID` + app-specific password + `NATIVEPHP_APPLE_TEAM_ID`
(`nativephp-internal.notarization.*`).

### Gotchas / lessons (don't rediscover these)
- **`nativephp/electron` must be scaffolded** (`php artisan native:install
  --publish`, already done). If it's missing, re-run it; it won't clobber
  `config/nativephp.php` (it SKIPs existing).
- **Bare cert name is ambiguous** — `Wessel Verheij (NV5645J73B)` matches BOTH
  the Developer ID and Apple Distribution certs. The hook feeds electron-builder
  the bare common name (it prepends "Developer ID Application:" itself); direct
  `codesign -s` needs the FULL prefixed name.
- **`mobile-app/` is excluded from the desktop bundle** — it's the mobile shell
  with ~1.1 GB of iOS/Android build artifacts (incl. a read-only SwiftPM test
  fixture codesign choked on). Do NOT use `*/nativephp` as an exclude — fnmatch
  spans slashes and it also strips `vendor/nativephp` → "Class
  NativeServiceProvider not found".
- **Stale codesign temps** — a failed build leaves `.!!*` dirs in
  `vendor/nativephp/desktop/resources/build/`; `extraResources` re-copies them.
  Purge with `find vendor/nativephp/desktop/resources/build -maxdepth 1 -name
  '.!!*' -exec rm -rf {} +` before re-signing.
- **Notary "In Progress" for >1 hr** is an Apple queue backlog, not a build
  problem. Never re-submit — re-query by submission id:
  `xcrun notarytool info <id> --apple-id info@nightworks.io --team-id
  NV5645J73B --password "$(op read 'op://Beatrax/beatrax account specific
  password/password')"`, then `xcrun stapler staple <app>`.
- **`->booting` DB hook** (`bootstrap/app.php`) creates the empty SQLite file
  before any connection — required so `package:discover` boots in the copied
  build tree (where `database/*.sqlite` is stripped) AND on a runtime fresh
  install. Never seeds/migrates there; `FirstLaunchBootstrap` + the
  `desktop.setup`/`desktop.welcome` onboarding own migration + first-run UX.
