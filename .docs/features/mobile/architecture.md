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
`platform()` therefore reads `$_SERVER` and `$_ENV` as well, and
`isMobileRuntime()` keeps the structural fallback (the sibling `persisted_data`
directory, which only NativePHP mobile provisions) for the config-load window
where even those are not yet populated.

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

**Known upstream behaviour — cold start signs the user out.** The generated
Android shell's `MainActivity.initializeEnvironment()` calls `clearAllCookies()`
(`CookieManager.removeAllCookies`) on every process start. The Laravel session
cookie goes with it, so a cold launch always lands on `/login` even though the
session row is still valid in the on-device database. A warm resume keeps the
cookie and returns to wherever the user was. This lives in
`nativephp/android/**/MainActivity.kt`, which `native:install` regenerates, so
it is not patchable from this repository — it needs an upstream change or a
scaffold-patch script of the kind `scripts/nativephp_*.php` already applies on
the Electron side.

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
already committed) -> `recovery_codes` -> redirect into
`mobile.pair?mode=import`. On submit, in strict order: `SignupAction`
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
provisioning actually succeeds — this exists because a public Livewire
property survives re-renders inside the serialized wire-snapshot payload
sent to the browser, which would otherwise leak plaintext credentials.
The retry path never re-runs `SignupAction` (the account already exists)
and reads only the session stash, never the emptied public properties —
reading those was a real bug that either permanently stranded the device
in `provisioning_failed` or minted the app-lock key from an empty
passphrase. `MobileImportIntentGate` durably marks the user as an import
device before either provisioning step, backing up the pairing screen's
own defense-in-depth echo of the same signal.

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
`beatrax/mobile-biometric-vault` plugin. Enrollment is PIN-rooted:
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

**Import-mode cross-device handshake.** When this pairing attempt was
reached via the fresh-device import bootstrap (`?mode=import`), the
scanned QR additionally carries the initiator's public identity
(`QrScanBridge::extractIdentity()`). `submitCode()` seeds a local
`pairing_tokens` row from that identity before accepting (the fresh,
separate device database otherwise has no local pending row to accept
against), auto-configures this device's relay transport from any
`relay`/`rtok` query params the QR carries, and sends a signed
`PAIR_RESPONDER_ACCEPT` frame to the desktop's own separate database over
the relay — best-effort, since a delivery failure never dead-ends the
confirm step already rendered. `checkPairingState()`'s poll re-emits that
responder-accept idempotently while still on the confirm step and not
yet confirmed, so a single lost relay delivery self-heals rather than
stranding the ceremony. `confirmMatch()` sends this device's own signed
`PAIR_CONFIRM` to the bound initiator peer unconditionally.

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
is enabled without an epoch) and `MobilePairingScan::mount()` (a
defense-in-depth echo of the observed query param). It carries no key
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
keyring re-projects the entire persisted op-log exactly once (guarded by
a `reprojected_at` stamp) so any entry that arrived and was quarantined
before the keyring was populated now decrypts and projects.

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

`MobilePullCommand` (`sync:mobile-pull`) is the OS-scheduled background
cadence leg, invoked by Android WorkManager or iOS BGTaskScheduler (a
best-effort hint, not a guaranteed cadence) via the premium
`nativephp/mobile-background-tasks` plugin. Each firing is an untrusted,
key-less cold start until an unlocked session has handed the trigger
service a usable device identity — an OS-scheduled tick has no
cookie/session to attach to an in-app unlock, so `AppLockKeyService::
release()` returns null on essentially every real firing. That is not a
defect: the command skips cleanly, touches nothing, and never caches a
key anywhere for background convenience. It fans out over every `users`
row (rather than a single hard-coded user) since the schema stays
multi-user-ready even in a single-user v1, and each user's burst is
isolated so one user's failure never stops the rest.

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

`NativeMobileAppServiceProvider` is the NativePHP-mobile-booted
application provider, an inverted skeleton of the desktop equivalent:
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
`@yield`-style layout. This was found and fixed as a real bug during
Phase 15 via a live authenticated HTTP GET, not a guess.

## Throwaway spikes

`SpikeStoragePathCommand` and `SpikeSyncDialCommand` are throwaway
topology probes: the first dumps the NativePHP mobile storage-path
environment signals against `UserDataPathService`'s resolved paths (to
confirm the encrypted SQLite file lands inside the app sandbox); the
second proves the amphp/Revolt event loop can be driven to completion for
a single bounded dial-out burst from within the mobile runtime, without
attempting the real Noise handshake. Both are registered only from the
mobile-app root's own bootstrap, never the shared `MobileServiceProvider`.

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
