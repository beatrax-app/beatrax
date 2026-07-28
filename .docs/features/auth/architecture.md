# `Auth` — architecture

The `Auth` module owns every credential the app holds for a human: usernames,
password hashes, single-use recovery codes, and the OAuth secrets connected
inboxes write through. It hosts the sign-in / sign-up / change-password /
reset-password Livewire surface, the recovery-code display, and the owner's
"manage users" view that creates partner accounts.

## What this module is for

A `diederik` install is local-only — no SMTP, no third-party identity
provider, no password-reset email link to fall back on. The user therefore
needs an authentication surface that closes that loop on its own:
twelve-character passwords, ten single-use recovery codes printed once at
signup, and a CLI escape hatch (`diederik:reset-password`) when the user
loses every code and is locked out of the machine. The trade-offs are
captured in [ADR 0010](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0010-recovery-codes-no-smtp.md).

The module also owns the asymmetric "owner / partner" model. The first
account created on a device is the owner: their `is_developer` flag is set
true at signup. The owner adds a partner via `/settings/users/new`, picks
the partner's initial password, and the partner is forced to change it on
first sign-in via the `force_password_change_at_next_login` flag. The same
owner can reset the partner's password from `/settings/users/{id}`; the
partner cannot reset the owner. The multi-user data scoping that backs
that asymmetry — every domain row carries `user_id` — is described in
[ADR 0008](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0008-multi-user-belongstouser.md).

What the module explicitly does NOT do: it never runs the
"send the user an email link" flow (no SMTP is in scope, see
[ADR 0010](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0010-recovery-codes-no-smtp.md)); it never reaches
into another module's tables (a partner has no automatic data — every
module's `BelongsToUser` rows are owner-scoped by default); and it never
exposes a cross-user surface (a probe for another user's resources returns
404, not 403, so the existence of the partner stays hidden).

## Module boundary

`Public/` exports six action classes that are the only sanctioned write
paths into the auth corpus:

- **Actions/** — `SignupAction`, `LoginAction`, `LogoutAction`,
  `AddUserAction`, `ResetPasswordAction`, `RegenerateRecoveryCodesAction`.
  Each is constructor-injectable, single-purpose, and runs the relevant
  database mutations inside one transaction.

`Internal/` is everything else:

- **Internal/Fortify/** — the project's `FortifyServiceProvider`, which
  rewires the upstream Fortify pipeline so the credential field is
  `username` (not `email`) and the only authentication views the framework
  resolves are the module's Livewire pages.
- **Internal/Recovery/** — the four classes implementing the recovery-code
  ceremony: `RecoveryCodeGenerator` (cryptographic PRNG over a 31-character
  phone-readable alphabet), `RecoveryCodeFormatter`, `RecoveryCodeNormalizer`,
  `RecoveryCodeAuthenticator` (the single sanctioned consume-on-success
  path).
- **Internal/Http/Livewire/** — the Livewire pages (`LoginPage`,
  `SignupPage`, `ResetPasswordPage`, `ChangePasswordPage`,
  `RecoveryCodesDisplay`, `AddUserPage`, `ManageUserPage`, `LockScreen`,
  `AppLockSettingsSection`, `AppLockKeyProbe`). All are constructor-free:
  the project's strict-rules PHPStan ruleset forbids property-based
  constructor injection on Livewire `Component` subclasses (and bans
  `auth()`/`Auth::user()`/facade lookups project-wide), so every service
  collaborator arrives as a parameter on the action method and on
  `render()` instead.
- **Internal/Http/Middleware/** — `FirstUserOnlyMiddleware` (gates the
  `/signup` route so it disappears the moment the owner exists),
  `ForcePasswordChangeMiddleware` (pushed onto the `auth` middleware
  group; redirects any authenticated user whose
  `force_password_change_at_next_login` is true to `/change-password`,
  exempting only that page and `/logout`), `RequireDeveloperMiddleware`
  (gates the owner-only routes like `/settings/users/new`).
- **Internal/Console/** — three artisan commands: `ResetPasswordCommand`
  (the CLI escape hatch), `GrantDevCommand`, `RegenerateRecoveryCodesCommand`.

The cross-user 404 posture is enforced as a contract test
(`tests/Feature/CrossUserIsolationTest.php`); any new authenticated surface
must keep that posture.

## Key services + events

- `SignupAction` — creates the first user + ten hashed recovery codes in
  one transaction. Promotes the connection to a write lock before the
  existence check so concurrent first-launch signups serialise rather than
  race. Dispatches `UserInstalled` after commit so first-install listeners
  (e.g. the default-category seeder in
  [`Categorization`](../categorization/architecture.md)) run identically
  whether the install ceremony is the GUI signup or the
  `beatrax:install` console path.
- `AddUserAction` — owner-creates-partner. Asserts the caller's
  `is_developer` flag and throws `NotFoundHttpException` (not 403) when it
  is false, so the probing surface stays hidden.
- `ResetPasswordAction` — recovery-code-driven password reset. Verifies
  the username + code through `RecoveryCodeAuthenticator` (which marks the
  code consumed atomically), writes the new password hash, and clears the
  forced-change flag. Never logs the user in; the reset flow ends at
  `/login`.
- `RecoveryCodeAuthenticator` — the sole sanctioned reader of
  `user_recovery_codes`. Hashes the typed code, finds the matching unused
  row, stamps `used_at` in one update, and returns the user. Constant-time
  message ("That username and recovery code do not match…") regardless of
  whether the username existed.

The module listens for nothing — every reaction it needs (the default
category seed, the wizard first-step priming) lives in a downstream
listener for `UserInstalled`, which the module dispatches.

## Recovery codes

`RecoveryCodeGenerator` draws each of the ten codes as five hyphenated
groups of four characters (e.g. `A2BJ-XK9M-PQ7N-RX4F-V8HD`) from a
31-character phone-readable alphabet that excludes the visually ambiguous
glyphs `I`, `L`, `O`, `0`, and `1`. Every character comes from
`random_int`, giving roughly 99 bits of entropy per code. Codes are
bcrypt-hashed at issue time in that same hyphenated shape.

A user re-typing a code by hand may vary case or add/drop/misplace
separators, so `RecoveryCodeNormalizer` folds the input to uppercase and
strips everything outside the generator's alphabet before
`RecoveryCodeAuthenticator` re-inserts the hyphens and hashes the result
in the identical shape it was stored in.

`RecoveryCodeAuthenticator::verify()` runs the whole match-and-consume
sequence inside one transaction with the candidate rows held under
`lockForUpdate()`, so a concurrent redemption of the same code blocks on
the row lock rather than racing — a code is consumed exactly once. Every
attempt, success or failure, writes a `system_alerts` audit row; a
failure against an unknown username still writes one, with a null
`user_id`, so the audit trail cannot itself reveal which usernames exist.

## Data flow

The signup ceremony, walked end-to-end:

```
POST /signup
  → SignupPage::register()
    → SignupAction()
      ├─ TX BEGIN
      ├─ UPDATE users SET id=id WHERE 0=1   (write-lock promotion)
      ├─ SELECT count FROM users            (must be zero)
      ├─ INSERT users (is_developer=1, force_password_change=0)
      ├─ generate 10 distinct recovery codes
      ├─ INSERT × 10 user_recovery_codes
      └─ TX COMMIT
    → Dispatch UserInstalled(userId)        (after commit)
    → Guard::login($user)
    → session.put('auth.signup.recovery_codes_plain', $codesPlain)
  → redirect /recovery-codes (display once)
```

The owner-resets-partner flow:

```
GET /settings/users/{username}             (developer-gated)
ManageUserPage::mount → assert is_developer, else 404
POST ManageUserPage::setPartnerPassword
  → write partner row inline:
       password = new hash
       force_password_change_at_next_login = true   ← owner-driven case
  → on next partner sign-in, ForcePasswordChangeMiddleware
    redirects to /change-password
```

(The recovery-code-driven `ResetPasswordAction` is a separate path —
`/reset-password`, taken by a user who has lost their password but holds a
recovery code. It clears the forced-change flag because the user has just
chosen the password they want.)

## App-lock (PIN / biometric)

A second, session-scoped lock sits in front of the app once a user opts in:
a 4–10 digit PIN (Argon2id-hashed) or, on capable devices, WebAuthn
platform biometrics. Both gate access to a per-session **data key** that
downstream encryption features (base-currency FX, at-rest field encryption)
read through `AppLockKeyService::release()`/`withhold()`.

### Key-wrapping model

`AppLockProvisioner::enable()` generates a fresh random 32-byte data key
(`random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)`) and double-wraps it under
a single shared KDF salt:

- **PIN wrap** (`pin_wrapped_key`) — the day-to-day unlock path.
- **Password wrap** (`password_wrapped_key`) — the recovery path. The
  account password is always available after re-authentication, so it
  survives a forgotten PIN (`AppLockProvisioner::rewrapForNewPin()`
  unwraps via the password and re-wraps under a new PIN; the underlying
  data key is unchanged).

Both wrap keys and the data key's local copies are zeroed with
`sodium_memzero()` after use. Because a value handed to `$session->put()`
is a shared (refcount > 1) buffer, that memzero is best-effort process
hygiene, not a guarantee — the session's own copy persists by design until
`lock()` removes it.

Enabling always mints a **new** data key, which invalidates every existing
per-device biometric wrap (they hold the old key) — `enable()` and
`disable()` both delete all biometric credentials for the user so a stale
enrollment can never unlock with divergent key material. `changePin()`
dispatches `AppLockPassphraseChanged` after a successful PIN change so a
Sync listener re-wraps the Group Data Key keyring under the (unchanged)
app-lock data key; the listener is deliberately best-effort/never-throw so
a keyring re-wrap failure can never surface as this method throwing
instead of returning its documented `bool`.

### Confirmation matrix

Each settings-surface mutation requires a different proof, matched to how
security-sensitive it is:

| Action | Requires |
|---|---|
| Enable (first PIN setup) | Account password (builds the password recovery wrap) |
| Disable | Current PIN |
| Change PIN | Current PIN |
| De-enroll biometric | Current PIN (verify-only — never touches PIN/wrap columns) |
| Forgot PIN → reset | Account password |
| Idle-timeout preset | Nothing — explicitly exempted; it only narrows the auto-lock window, never touches key material |

`AppLockProvisioner::verifyPin()` is side-effect-free by design so the
de-enroll confirmation can check the PIN without accidentally mutating
`failed_attempts`/backoff state, which is scoped to lock-screen unlock
attempts only.

### Session custody (`LockStateManager`)

Two session keys: a boolean lock flag, and a **custody handle** for the
data key (not necessarily the raw key — see `KeyCustodian` below). An
absent lock flag is treated as unlocked, covering both "no PIN set up yet"
and "fresh session" — there is no third state.

The data key lives in the Laravel session payload for as long as the
session stays unlocked, which the session driver serialises at rest (file
driver: `storage/framework/sessions/*`, owner-only permissions). This is
an accepted risk for a local-only, single-machine deployment: the session
store and the SQLite database share the same disk and OS user, so an
attacker who can read session files can already read the DB. `lock()`
removes the key from the session immediately; the only deliberate
persistent copies are the wrapped blobs in `user_app_lock_configs` /
`user_biometric_credentials`.

`KeyCustodian` (`Public/Contracts/KeyCustodian.php`) abstracts what the
"handle" actually is: on the default (web) custodian the handle IS the raw
key, so behaviour is unchanged from the pre-custodian shape; on
desktop/mobile bundles the custodian instead stores a `safeStorage`
ciphertext or a Keychain reference, so the raw key bytes never touch the
session payload at all. `LockStateManager::heldKey()` is the single
sanctioned reader — going through the custodian's `read()` rather than
reading the session key directly, which would yield the opaque handle
instead of the key on non-web bundles.

Lock-state changes are UI-scoped only: background queue/scheduler workers
hold their own in-memory copy of the data key, independent of any session
lock state. Locking the UI never revokes a running worker's copy — only
the next `release()` call in *that* session returns null.

### PIN verification + backoff (`PinVerificationService`)

Wraps the compare-and-unwrap in a `lockForUpdate()` + `transaction()` pair
(mirroring `RecoveryCodeAuthenticator`) so concurrent PIN attempts can't
race the failure counter. On a wrong PIN, `failed_attempts` increments;
crossing a threshold sets an escalating `locked_until` backoff window
(30s, 60s, then 300s and beyond) that short-circuits further attempts
without even hashing; reaching the hard cap signs the session out
entirely and emits a `SystemAlert`. A corrupted wrap blob is treated as a
non-counting failure (also alerted) rather than a crash. A successful PIN
unlock re-arms every one of the user's biometric credentials (see below)
and refreshes the "must re-enter PIN periodically" clock the mobile
cold-start biometric path reads.

### Biometric enrollment + assertion (`WebAuthnBiometricService`)

Uses `web-auth/webauthn-lib` directly (not a Laravel passkey wrapper).
Enrollment is only reachable once a PIN exists — biometric unlock is
strictly additive to the PIN, never a replacement for the wrap chain.
Both `rpId` (host portion of `APP_URL`) and the full origin are validated
independently on every assertion; validating only one of the two is a
known WebAuthn integration pitfall.

Each enrolled device gets its own random 32-byte "biometric wrap secret";
the data key is wrapped under that per-device secret (not under the PIN
wrap key), so the PIN wrap remains the cryptographic root and a single
compromised device secret cannot unwrap another device's copy. The stored
blob is `secret (32 bytes) || wrapped_key_bytes`, and is passed through
`SecretShield::protect()`/`reveal()` so the desktop bundle keeps it as
OS-keychain-bound ciphertext rather than a directly-readable DB value (a
no-op passthrough on web/mobile).

`BiometricDeviceStore` implements an arm/disarm cycle per credential:
after `BIOMETRIC_DISABLE_THRESHOLD` (5) consecutive failures a credential
is disarmed and refuses further biometric attempts until the next
successful PIN unlock re-arms every credential for that user. The
WebAuthn signature counter is updated only after a successful assertion,
rejecting non-increasing counters beforehand as replay protection against
a cloned authenticator. Browsers serialise the credential ID as unpadded
base64url while the store persists standard base64 at enrollment time —
assertion lookups normalise across both encodings before matching, with
standard base64 kept as a fallback for non-browser callers.

### Priming the session after credential login

A fresh password login must never leave a session in the incoherent
"unlocked UI, no data key" state — before `AppLockProvisioner::primeSessionAfterLogin()`
existed, that was exactly what happened, since a nominally-unlocked
session with no stored key made `AppLockKeyService::release()` return
null with nothing prompting the PIN to restore it. At login time the
plaintext account password is available, so the data key is recovered
through the password recovery wrap and the session is unlocked with the
key already in hand — password re-auth is a strictly stronger gate than
the PIN, so skipping the PIN screen here is sound. If the password wrap
cannot be unwrapped (e.g. a stale wrap after an account-password change),
the session starts locked instead and the PIN/biometric restores the key
via the lock screen — never the incoherent unlocked/key-less state. This
priming is a no-op when the lock is not enabled for the user.

### Lock screen (`LockScreen` Livewire page)

The `/lock` route offers exactly three actions — PIN pad, biometric
prompt, sign out — and nothing else. PIN digits are never rendered in an
`<input>`; the DOM only shows bullet glyphs, so no autocomplete,
clipboard, or OS password-manager capture can occur. The digits
accumulate client-side in transient Alpine state rather than a public
Livewire property, so they never appear in the `wire:snapshot` DOM
attribute or in a Livewire update response — the full PIN crosses the
wire exactly once, as a `submit()` method argument, forwarded straight to
`PinVerificationService`.

An active backoff window is checked *before* the PIN itself, so even a
correct PIN submitted during the window must not be reported as
"incorrect" — the copy distinguishes "too many attempts, try again in Ns"
from "incorrect PIN, N attempts remaining". The biometric prompt is
dispatched only on an explicit button tap, never on render, so the
browser's native biometric UI never auto-fires.

### Dev-console key-release probe (`AppLockKeyProbe`)

A Developer-Console-only Livewire component that makes the data-key
release gate visually inspectable: it exercises the exact Public contract
downstream encryption features consume (`AppLockKeyService::release()`
returns the data key when the session is unlocked, null when locked)
without any of those features needing to be deployed to verify the gate
works. It renders only a truncated SHA-256 fingerprint of the key, never
the raw bytes, and is mounted exclusively behind the `developer`
middleware — it must never appear on any non-developer surface.

### Idle-timeout enforcement (`AppLockMiddleware`)

The idle lock is server-authoritative: the global session lifetime is
already a 30-day rolling window, so `lock_enabled` (not the session
lifetime) controls whether the lock gate fires at all. On every gated
request the middleware compares `last_activity_at` to the current time;
past the configured idle timeout it locks the session and redirects to
`/lock`. To avoid a DB read on every request, the lock config
(`lock_enabled`, `idle_timeout_minutes`) is cached in the session and
re-read from the DB only once per a short TTL window; the
`last_activity_at` write still happens on every passing request so the DB
stays fresh independent of that cache.

Livewire update traffic (`wire:poll` machine polling included) must never
count as user activity, or any page with a polling component would hold
`last_activity_at` fresh forever and the idle lock would never fire on an
unattended machine — genuine interaction on Livewire-heavy pages is
reported instead by a plain-fetch activity heartbeat from `lock.js`.

The middleware is registered twice: once pushed onto the `auth` route
middleware group (covers every web+auth route), and once as a Livewire
persistent middleware (re-runs the gate on every `/livewire/update`
request), so a locked session cannot bypass the gate through the Livewire
update endpoint alone.

Route exemptions (`ALLOWED_ROUTE_NAMES`) let a locked session still reach
the lock screen, its challenge/verify endpoints, `logout`, and the mobile
unlock screen (`mobile.lock` — its own on-device biometric/PIN-fallback
surface, exempted so a locked mobile session isn't bounced away from its
own unlock screen back to the desktop/web `auth.lock` route). Biometric
*enrollment* is deliberately absent from that list: enrollment requires
the session data key, which a locked session never has, so exempting it
would only widen the locked-session surface for no benefit.

### Engaging the lock requires an enabled lock

`lock.js` drops the privacy veil and starts a 30-second grace timer when
the window is backgrounded or blurred; when that timer elapses it posts
to `/lock/engage`, which withholds the session key and marks the session
locked. `AppLockMiddleware` tests the session lock flag *before* it loads
the user's lock config, so a session locked this way stays locked
regardless of whether a lock was ever configured.

For a user who has never enabled the app-lock that combination is a
lockout: there is no PIN hash to verify against and no enrolled
biometric credential, so `/lock` renders a PIN pad that nothing can
open and `Sign out` is the only working control. The same hazard applies
to the veil on its own — once its grace window elapses the veil is only
ever lifted by a successful unlock.

The invariant is therefore that **a user without an enabled lock is
never veiled and never locked**, enforced at two levels:

- **Client.** `lock.js` reads `window.beatraxIdleMs`, which the
  authenticated layout emits only when the lock is enabled (via
  `AppLockClientConfig::idleTimeoutMs()`). When it is absent the store
  registers neither the idle watcher nor the `visibilitychange` / `blur`
  veil-and-grace listeners, so `_serverLock()` is never reached.
- **Server.** `LockEngageController` calls
  `AppLockClientConfig::isEnabled()` and returns `204` without touching
  the session when the lock is off. This is the authoritative check: a
  stale tab left open from before the lock was disabled, a second
  window, or a replayed request must not be able to strand the session.

`AppLockClientConfig` is the single place either layer asks whether a
lock exists, so the two checks cannot drift apart.

### Cross-module Public seams

**`KeyCustodian`** (`Public/Contracts/KeyCustodian.php`) is the custody
boundary for the at-rest data key while a session is unlocked. Rather
than parking the raw key bytes directly in the session payload,
`LockStateManager` hands the raw key to a `KeyCustodian`, receives an
opaque handle, and stores only that handle. The handle is deliberately
storage-model-agnostic so one call-site wiring serves every platform:

- Web/CI (`NullKeyCustodian`, this module) — the handle IS the raw key;
  `store`/`read` are identity, `forget` a no-op. Byte-for-byte the
  pre-custodian behaviour (see the Session custody section above).
- Desktop bundle (`DesktopKeyCustodian`, Desktop module) — the handle is
  the self-contained Electron `safeStorage` ciphertext of the key; it
  carries no external state, so `forget` is a no-op.
- Mobile bundle (`SecureStorageKeyCustodian`, Mobile module) — the raw
  key is written to the iOS Keychain / Android Keystore and the handle is
  only a reference token; `forget` MUST delete the backing entry.

An implementation that cannot reach its backing store right now (e.g. an
early-boot race) MUST degrade gracefully by returning the raw key
unchanged, matching the pass-through behaviour on web. `read()` returns
null when the custodian owns a real backing entry but cannot recover the
key from it (an evicted Keychain entry, ciphertext that no longer
decrypts) — callers must treat null as "no key held" and fall back to a
PIN unlock, never as key bytes. Wrap/unwrap of the key under the PIN- or
password-derived KEK stays entirely inside this module
(`AppLockKdf`/`AppLockKeyWrap`); a custodian never derives, wraps, or
unwraps anything, only protects the already-unwrapped key while held.

**`AppLockPassphraseChanged`** (`Public/Events/`) carries the old and new
app-lock data keys after a successful PIN change so a Sync listener can
re-wrap the Group Data Key keyring under the new key before `changePin()`
returns. In this codebase's key-wrap chain the data key itself never
rotates on a plain PIN change — only the PIN-derived wrap of that
persistent key changes — so `$oldKek`/`$newKek` are the same raw bytes
today; the event fires unconditionally anyway so the keyring is
re-encrypted with a fresh nonce on every passphrase change, and the
contract stays correct if a future flow ever does rotate the underlying
key. `$oldKek`/`$newKek` are raw, transient key material that must never
be logged, serialized, or persisted anywhere beyond this synchronous,
in-process dispatch; listeners must `sodium_memzero()` their own copies
once the re-wrap completes. This module has zero compile-time dependency
on Sync — Sync subscribes to this Auth-owned event from its own listener.

**`AppLockKeyService`** (`Public/Services/`) is the single authorized
release point for the session-held data key across the module boundary —
no other module may reach into `LockStateManager` or read the session's
data-key entry directly. `release()` returns null whenever the session is
locked or holds no key; `admitDataKey()` is the authorized inverse of
`withhold()`, used by the mobile cold-start biometric unlock, where the
key's *provenance* is the trust gate — the only sanctioned caller obtains
the key from a real secure-enclave recovery, never from a bypassable bare
boolean prompt. The class is intentionally not `final`: a Sync test
substitutes a release()-returns-null subclass to exercise a weak-key-window
guard, and the class has no invariants subclassing could violate.

**`BiometricKeyBlobCodec`** (`Public/Services/`) lets a non-Auth module
(the Mobile cold-start biometric vault) protect a data key with the same
primitive the desktop WebAuthn path uses, without importing
`Modules\Auth\Internal\*`. The blob format is identical to the WebAuthn
biometric-wrap blob: a fresh 32-byte random secret concatenated with
`AppLockKeyWrap::wrap($dataKey, $secret)`'s decoded bytes. Storing a fresh
random secret alongside the wrapped key — rather than the raw data key —
means the enclave-gated entry never holds the data key in plaintext;
recovering it requires both halves of the blob, which only leave the
secure enclave after a successful biometric. This class performs no
storage and no biometric interaction; it is pure crypto glue.

**`MobileLockGateway`** (`Public/Services/`) is a narrow Public
read/write surface letting the Mobile module's own lock-screen
implementation (`MobileLockScreen`, structurally identical to this
module's `LockScreen`) reuse the exact same PIN-verification and
biometric-enrollment-check collaborators without duplicating that logic
into a second module-owned copy — a crypto-logic drift risk — and without
reaching into `Modules\Auth\Internal\*` directly. Every method here is a
thin pass-through to an existing Internal collaborator; the `AppLockKeyService`
key-release path itself stays untouched by this gateway. Two behaviours
are gateway-specific rather than pure delegation:

- `unlockWithRecoveredKey()` stamps `last_activity_at` alongside admitting
  the recovered key, because a genuine cold start (app killed/rebooted)
  almost always exceeds the idle window — without that stamp the very
  next request would see stale activity, treat the session as
  idle-expired, and bounce the user straight back to the lock screen,
  making cold-start unlock useless whenever idle timeout is enabled. The
  PIN path is immune because `PinVerificationService::verify()` already
  stamps activity on success; this is the biometric equivalent.
- `pinFloorDue()` implements a periodic PIN-floor re-auth cadence
  (default 14 days, tunable) even though biometric is the everyday unlock
  root on mobile: due when there has never been a PIN unlock, or the last
  one is older than the floor — a successful PIN unlock refreshes the
  anchor and clears the floor.

### Transport notes

- `WebAuthnBiometricController`'s two JSON routes stay inside the standard
  `web` middleware group (CSRF enforced, no JSON exemption); `lock.js`
  reads the `XSRF-TOKEN` cookie and forwards it as the `X-XSRF-TOKEN`
  request header, which Laravel accepts as the supplied token.
- `LockEngageController` is posted to via `fetch` with `keepalive: true`,
  not `navigator.sendBeacon` — a beacon cannot set headers, and
  `VerifyCsrfToken` only accepts the token from a body field or one of
  the CSRF headers, so a beacon request would always 419.
- Both controllers are allow-listed in `AppLockMiddleware`'s route
  exemptions so a session that is already locked (or racing a lock) can
  still reach them to complete an unlock.
