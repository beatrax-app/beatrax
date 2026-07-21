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
captured in [ADR 0010](../../adr/0010-recovery-codes-no-smtp.md).

The module also owns the asymmetric "owner / partner" model. The first
account created on a device is the owner: their `is_developer` flag is set
true at signup. The owner adds a partner via `/settings/users/new`, picks
the partner's initial password, and the partner is forced to change it on
first sign-in via the `force_password_change_at_next_login` flag. The same
owner can reset the partner's password from `/settings/users/{id}`; the
partner cannot reset the owner. The multi-user data scoping that backs
that asymmetry — every domain row carries `user_id` — is described in
[ADR 0008](../../adr/0008-multi-user-belongstouser.md).

What the module explicitly does NOT do: it never runs the
"send the user an email link" flow (no SMTP is in scope, see
[ADR 0010](../../adr/0010-recovery-codes-no-smtp.md)); it never reaches
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
- **Internal/Http/Livewire/** — the seven Livewire pages (`LoginPage`,
  `SignupPage`, `ResetPasswordPage`, `ChangePasswordPage`,
  `RecoveryCodesDisplay`, `AddUserPage`, `ManageUserPage`).
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
