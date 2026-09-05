# The explicit-consent auto-update gate

The desktop bundle ships with electron-updater, and electron-updater's
default behaviour is the thing this page exists to prevent: on launch it
calls `checkForUpdatesAndNotify()`, silently downloads whatever the
configured feed offers, and installs it the next time the app quits. The
only integrity it checks is TLS to the feed plus the SHA-512 the feed's
*own* manifest declares — a self-consistent statement. Anyone who can
serve that feed can therefore serve a manifest and a matching binary of
their choosing, and the app installs it without the user ever seeing a
prompt.

Beatrax replaces that with a gate in two halves: **a publisher identity
the feed cannot forge**, and **a human click before any byte is
downloaded**.

## The trust anchor

`config/auto_update.php` pins `publisher_public_key_hex` — a 32-byte
Ed25519 public key, hex-encoded, written as a literal and deliberately
**not** env-overridable. A public key is not a secret, so committing it
is the design; making it env-overridable would let a runtime `.env` write
swap the trust anchor for an attacker's key. The matching secret half
lives in the release pipeline only.

Every release publishes, alongside the installers, the per-platform
electron-updater manifest and a detached hex signature sibling:

| Platform | Manifest | Signature |
|---|---|---|
| Windows | `latest.yml` | `latest.yml.sig` |
| macOS | `latest-mac.yml` | `latest-mac.yml.sig` |
| Linux | `latest-linux.yml` | `latest-linux.yml.sig` |

Those three rows are the cases of `Modules\Core\Internal\Enums\OsFamily`, and
`updateManifestSuffix()` is the whole mapping — Windows' empty suffix is its own
answer, not a fallback. `PHP_OS_FAMILY` also reports `BSD`, `Solaris` and
`Unknown`; `HttpPublisherManifestFetcher` reads those through `tryFrom()`, gets
`null`, logs, and fetches nothing. It must not fetch the suffix-less manifest
for them: the Windows SHA-512 can never match a non-Windows binary, so the
update would fail verification on every check, forever, on that OS alone.

The `preview` channel uses `beta*.yml` instead of `latest*.yml`.
`config/auto_update.php`'s `manifest_feed_url` (env
`AUTO_UPDATE_FEED_URL`) is the base URL those sit under; the release
workflow sets it from the GitHub context, so moving the repository
re-points the feed rather than stranding a hardcoded owner. **Left unset
— self-hosted builds, the web app, the mobile runtime — the fetch yields
`null` and no update is ever surfaced or applied.** That is a build
having no feed, not a failure; it is not the reader's off switch, which
is the next section and reaches inside a bundle that does have one.

## The off switch

The check is an outbound call, and the privacy stance says it must be
possible to stop it. There are **two** callers, on opposite sides of the
process boundary, and switching the feature off has to reach both:

1. **electron-updater's own poll**, in the Electron main process.
   NativePHP's `startAutoUpdater()` runs `checkForUpdatesAndNotify()` at
   bootstrap if `config('nativephp')`'s `updater.enabled` is `true`. It
   reads that config once, out of the JSON the `native:config` artisan
   command prints — a separate PHP process Electron runs before the app
   is served.
2. **Beatrax's own manifest fetch**, in PHP.
   `HttpPublisherManifestFetcher` GETs the signed manifest and its `.sig`
   from `auto_update.manifest_feed_url`, on `UpdateAvailable` and again
   on `UpdateDownloaded`.

The reader's answer lives in `users.auto_update_check_enabled`, defaulting
to **on** — the signed manifest is the only binary-integrity signal a
bundle without a paid signing identity has, so being on is the shipped
posture and the switch exists to leave it. It is **device-local**
(`MergeRulesRegistry::DEVICE_LOCAL_COLUMNS`) beside `close_behavior` and
`theme`: an update check is a property of an installed binary. A phone is
updated by its store — all three listeners return early on a mobile
runtime — so a phone's answer arriving on a desktop would switch off that
desktop's only integrity signal, from a screen where the switch governs
nothing. `UpdateCheckSettingsSection` renders it, and offers no switch at
all on a phone.

`UpdateCheckPreference::enabled()` is the single reader of the column, and
it answers **for the device, not for one account**: the banner is recorded
at `user_id => null` so every account sees the one notification, and the
call is one call from one machine. It is false as soon as any account has
switched it off. An unreadable row — first launch, before the table
exists — logs and answers `true`, so the shipped posture is never lost to
a missing answer.

The two callers are stopped in two places:

- `HttpPublisherManifestFetcher::manifestUrl()` returns `null` before it
  builds a URL. That method is the only place this feature composes a
  feed URL, so both listeners are covered by the one refusal, and every
  downstream check fails closed rather than proceeding unverified.
- `ApplyUpdateCheckChoiceToStartupConfig` listens for `CommandStarting`
  and narrows `nativephp.updater.enabled` when the command is
  `native:config`. It can only ever turn the value off: a build that
  ships without a feed stays without one whatever the row says.

### `NATIVEPHP_UPDATER_ENABLED` is read on both sides of the build

One name, two readers, and while it was unset they disagreed in silence.
`electron-builder.mjs` compares it to the **string** `'true'`, so unset
meant no `publish` block — and with no publish provider electron-builder
writes no `app-update.yml` into the bundle, leaving electron-updater with
no feed to poll. `config/nativephp.php` defaulted the same name to `true`,
so the boot hook called `checkForUpdatesAndNotify()` regardless. The
release workflow now sets it explicitly at build time **and** writes it
into the bundled `.env`, so neither side rests on a default;
`.env.example` leaves it commented out because a local `native:build` has
no `GITHUB_OWNER`/`GITHUB_REPO` to publish against.

That flag says only whether the bundle *has* a feed. Whether a launch
polls it is the reader's switch, narrowing the same value at
`native:config` time.

## Holding electron-updater at the door

`autoDownload` and `autoInstallOnAppQuit` are runtime setters on
electron-updater's `autoUpdater` object, not electron-builder
configuration, so they cannot be set from `config/nativephp.php`.
`scripts/nativephp_inject_explicit_consent_updates.php` runs as a
`prebuild` hook and injects both as `false` into the compiled Electron
main-process JS, immediately after the `const { autoUpdater } =
electronUpdater;` destructure. The patch is idempotent — a file that
already sets `autoDownload` is left alone.

With both false, electron-updater still *discovers* an update and still
fires `UpdateAvailable`, but it stops there. Nothing is on disk yet.

## The three listeners

All three are registered in `DesktopServiceProvider::boot()` behind the
`nativephp-internal.running` guard, so they exist only inside the
bundle. Each also refuses outright under `UserDataPathService::
isMobileRuntime()` — the app stores own the mobile update path, and a
desktop binary must never be applied there.

**1. `Modules\Desktop\Internal\Listeners\VerifyAndAnnounceUpdate`**
— on `UpdateAvailable`.

`ElectronUpdateChannel::poll()` fetches the manifest for the running
platform and its `.sig`, verifies the detached signature over the **raw
manifest bytes** against the pinned key, and suppresses a version that
already has an unacknowledged `system_alerts` row (so repeated polls do
not stack duplicate banners). The listener then adds one more check
electron-updater cannot make for itself: the version the signed manifest
names must equal the version electron-updater offered. Without it, a feed
could serve a genuinely signed manifest for one release while offering
the binary of another. Only when both agree does
`RecordUpdateAvailableAlert` write the row the banner renders from.

Which row depends on the release's age. `ElectronUpdateChannel::
alertKindFor()` measures the manifest's publish date against the 30-day
staleness threshold and the running bundle's own `nativephp.version`; a
release older than that earns `update.stale` (warning severity, and the
banner offers acknowledge-only actions rather than an install), anything
newer earns `update.available` (info). Both carry `currentVersion` and
`latestVersion` in metadata, which is what the stale copy names. The
idempotency guard spans every `update.*` kind, so a stale row already
recorded for a version suppresses a later availability row for it.

`update.critical` has no producer: nothing in the electron-updater
manifest format declares a release security-critical, so the kind, its
banner branch and its copy exist ahead of a signal to drive them.

**2. `Modules\Desktop\Internal\Listeners\TriggerUpdateDownload`**
— on `Modules\Core\Public\Events\UpdateInstallRequested`.

This event is raised by the user clicking install on that banner. It is
the only thing that calls `AutoUpdater::downloadUpdate()`, which is what
`autoDownload = false` was holding back. This is the consent step: no
click, no download.

**3. `Modules\Desktop\Internal\Listeners\VerifyAndInstallDownload`**
— on `UpdateDownloaded`.

The binary is now on disk but has not run. This listener re-fetches the
manifest and fails closed on **every** unverifiable branch, in one
condition:

- the feed is unreachable or the manifest unparseable (`null`),
- the Ed25519 signature does not verify against the pinned key,
- the signed manifest's version is not the version that was downloaded,
- the downloaded file's SHA-512 is not the digest that manifest names.

Any of those logs at `critical` and returns, leaving the file on disk
uninstalled. `AutoUpdater::quitAndInstall()` is reached only when all
four pass.

## Details that matter

- **The signature covers bytes, not a parsed value.**
  `HttpPublisherManifestFetcher` keeps the raw response body and hands
  *that* to `verifyManifest()`. Verifying a re-serialised YAML value
  would let a semantically-equivalent re-encoding pass.
- **The digest is normalised, not reinterpreted.** electron-updater
  writes `sha512` as base64; `verifyBinary()` compares hex. The fetcher
  base64-decodes, checks the result is exactly 64 bytes, and hex-encodes.
  A digest that is not 64 bytes drops the whole manifest rather than
  reaching the binary check as a bad expectation.
- **The `.sig` sibling is hex.** A non-hex or odd-length body is treated
  as a corrupt signature and refused, never fed to `hex2bin`.
- **`verifyBinary()` uses `hash_equals`**, so a partial-byte match cannot
  leak through timing.
- **Fetch timeout is 10 seconds**, and every fetch failure — offline,
  DNS, malformed YAML, unparseable date — collapses to the same `null`.
  Nothing from the update path bubbles an exception into app boot.
- **macOS differential downloads are disabled** by a sibling prebuild
  hook (`nativephp_inject_macos_update_settings.php`). The differential
  path performs its own OS-signature check that the ad-hoc-signed bundle
  fails; the full-binary path keeps the SHA-512 verification above, so
  integrity is preserved either way.

## Where to look

- Listeners: `Modules/Desktop/Internal/Listeners/VerifyAndAnnounceUpdate.php`,
  `TriggerUpdateDownload.php`, `VerifyAndInstallDownload.php`
- Verification + channel resolution:
  `Modules\Core\Public\Services\ElectronUpdateChannel`
- Feed fetch + manifest parse:
  `Modules\Core\Internal\AutoUpdate\HttpPublisherManifestFetcher`
- Configuration: `config/auto_update.php`, `config/nativephp.php`
- The off switch: `Modules\Core\Public\Services\UpdateCheckPreference`,
  `Modules\Core\Public\Http\Livewire\UpdateCheckSettingsSection`,
  `Modules\Desktop\Internal\Listeners\ApplyUpdateCheckChoiceToStartupConfig`
- Tests: `Modules/Desktop/tests/Feature/AutoUpdate/ExplicitConsentUpdateGateTest.php`
  (listeners against an in-memory manifest) and
  `UpdateFeedSmokeTest.php` (the same chain driven through the real
  fetcher over a faked feed, per platform, including a manifest signed by
  a key other than the pinned one).

See also [`architecture.md`](architecture.md) for the rest of the desktop
module.
