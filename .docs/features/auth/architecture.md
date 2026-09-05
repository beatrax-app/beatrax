# `Auth` — architecture

The `Auth` module owns every credential the app holds for a human: usernames,
password hashes, single-use recovery codes, and the OAuth secrets connected
inboxes write through. It hosts the sign-in / sign-up / change-password /
reset-password Livewire surface, the recovery-code display, and the owner's
"manage users" view that creates partner accounts.

## What this module is for

A Beatrax install is local-only — no SMTP, no third-party identity
provider, no password-reset email link to fall back on. The user therefore
needs an authentication surface that closes that loop on its own:
twelve-character passwords, ten single-use recovery codes printed once at
signup, and a CLI escape hatch (`beatrax:reset-password`) when the user
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

`Public/` exports six action classes — the only sanctioned write paths
into the auth corpus — beside the recovery-code value types:

- **Actions/** — `SignupAction`, `LoginAction`, `LogoutAction`,
  `AddUserAction`, `ResetPasswordAction`, `RegenerateRecoveryCodesAction`.
  Each is constructor-injectable, single-purpose, and runs the relevant
  database mutations inside one transaction.
- **Recovery/** — `RecoveryCodeFormatter`, and `PendingRecoveryCodes`:
  the one home for the session key holding the plaintext sheet between
  minting it and showing it, and for how long that copy stays readable.
  Both are `Public/` because `Mobile` runs the second of the two
  ceremonies.

`Internal/` is everything else:

- **Internal/Fortify/** — the project's `FortifyServiceProvider`, which
  rewires the upstream Fortify pipeline so the credential field is
  `username` (not `email`) and the only authentication views the framework
  resolves are the module's Livewire pages.
- **Internal/Recovery/** — the four classes implementing the recovery-code
  ceremony: `RecoveryCodeGenerator` (cryptographic PRNG over a 31-character
  phone-readable alphabet), `RecoveryCodeMinter` (the single sanctioned
  issue-a-sheet path), `RecoveryCodeNormalizer`,
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
  exempting only that page and `/logout` — and, on a Livewire update,
  only the components that page is *for*, because `/change-password`
  renders in `layouts.app` and the shell mounts nine others beside it),
  `RequireDeveloperMiddleware` (gates the owner-only routes like
  `/settings/users/new`), `ForgetsSpentRecoveryCodes` (registered on
  the `web` group of *both* application roots; ends the one-time
  recovery-codes ceremony from the outside, because neither the mobile
  ceremony nor a reader who simply navigates away has an exit the
  screen itself can see —
  [the pending recovery codes live one request at a time](pending-recovery-codes-lifetime.md)).
- **Internal/Console/** — three artisan commands: `ResetPasswordCommand`
  (the CLI escape hatch), `GrantDevCommand`, `RegenerateRecoveryCodesCommand`.

The cross-user 404 posture is enforced as a contract test
(`tests/Feature/CrossUserIsolationTest.php`); any new authenticated surface
must keep that posture.

## Key services + events

- `SignupAction` — creates the first user in one transaction, and issues
  its ten hashed recovery codes through `RecoveryCodeMinter` inside it. Promotes the connection to a write lock before the
  existence check so concurrent first-launch signups serialise rather than
  race. Dispatches `UserInstalled` after commit so first-install listeners
  (e.g. the default-category seeder in
  [`Categorization`](../categorization/architecture.md)) run identically
  whether the install ceremony is the GUI signup or the
  `beatrax:install` console path.
- `AddUserAction` — owner-creates-partner. Mints NO recovery codes: the
  owner is never shown a partner's sheet and the partner is not present,
  so ten issued here were ten working credentials no human held.
  `ChangePasswordPage` mints the sheet at the partner's forced first
  password change instead — the first moment there is somebody to hand it
  to — and redirects into the existing recovery-codes ceremony with
  `RecoveryCodesDisplay::RETURN_TO_DASHBOARD`. It is gated on the account
  holding no sheet at all, so an ordinary password change is untouched.
  Asserts the caller's
  `is_developer` flag and throws `NotFoundHttpException` (not 403) when it
  is false, so the probing surface stays hidden.
- `ResetPasswordAction` — recovery-code-driven password reset. Rate-limits
  the attempt, verifies the username + code through
  `RecoveryCodeAuthenticator` (which marks the code consumed atomically),
  writes the new password hash, clears the forced-change flag, and severs
  every session and the recaller. Never logs the user in; the reset flow
  ends at `/login`.
- `RecoveryCodeAuthenticator` — the sole sanctioned reader of
  `user_recovery_codes`. Hashes the typed code, finds the matching unused
  row, stamps `used_at` in one update, and returns the user. Constant-time
  message ("That username and recovery code do not match…") regardless of
  whether the username existed.

The module listens for one thing: `Illuminate\Auth\Events\Login`, so that
[a remember-me recaller starts locked](#the-login-that-primes-nothing-remember-me).
Every reaction it *causes* (the default category seed, the wizard first-step
priming) lives in a downstream listener for `UserInstalled`, which the module
dispatches.

## Changing a password severs what the old one held

There are four ways the stored password changes, and all four are answering the
same question: the account may be in somebody else's hands. So all four go
through `Internal/Services/SessionRevoker`, which does two things and not one.

Deleting the `sessions` rows is the obvious half and on its own it severs
nothing. Laravel validates a recaller against `users.remember_token` alone —
never against the password hash, and `AuthenticateSession` is not in this
stack — so the cookie that was live before the reset is live after it, and the
next request mints a fresh session from it. The old password stops working and
whoever had the account still has it. `SessionRevoker` therefore rotates
`remember_token` in the same call, the way `SessionGuard::logout()` already
does on the way out.

| Path | What it severs |
|---|---|
| `ChangePasswordPage::submit()` | Every session but the one finishing the redirect, and the recaller. |
| `ResetPasswordAction` (recovery code) | Every session and the recaller; the caller is a guest with none to keep. |
| `ManageUserPage::setPartnerPassword()` | Every session and the recaller **of the partner**; the owner's own are untouched. |
| `ResetPasswordCommand` | Every session and the recaller. |

The last two used to sever nothing at all, contained only by the
`force_password_change_at_next_login` flag they set — which is a redirect, not
a revocation, and was never the design.

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
the row lock rather than racing — a code is consumed exactly once.

### What a failed attempt is allowed to cost

`/reset-password` is a **guest** route, so everything one attempt costs is
spent by a caller who has proved nothing. Three things bound it, and each
answers a different half of the same problem.

A failure against an **unknown** username writes no alert at all. A row with a
null `user_id` shows to every user, so recording one would hand an
unauthenticated caller the whole household's banner. The audit trail therefore
says nothing about a username nobody has, which is also why it reveals nothing
about which usernames exist.

A failure against a **known** username writes one `auth.recovery_code_failed`
row and then stops: `SystemAlertWriter::raiseOnceForUser()` refuses a second
while the first is unacknowledged. A redemption spends a code and so caps
itself at ten; a *failure* spends nothing, and twenty-eight submissions used to
mean twenty-eight `critical` rows. One open row says everything a hundred
would, and the same dedupe is what stops a reader who mistypes their own
recovery sheet from burying their own banner. Acknowledging it re-arms the
kind, so the next break-in attempt still reaches them.

`matchingCodeId()` costs a fixed ten bcrypt-12 hashes whatever the account
state — roughly 3.3 s of CPU — because that is what keeps a missing username
indistinguishable in timing from a wrong code. Lowering it would trade a
user-enumeration oracle for a cheaper request, so it stays where it is and
`ResetPasswordAction` bounds **how often** it can be spent instead: five
attempts per minute, keyed on the username as typed (so an unknown one is
metered exactly like a known one) and cleared on a successful redemption.

### Handing the codes over

The one-time display offers Copy codes and Download as .txt, and where the
download goes is decided by what the runtime can do with a file rather than by
whether it is a phone.

In a browser the blob `<a download>` reaches the download manager, and "Saved
as beatrax-recovery-codes-<name>.txt" is true.

On iOS the same click is answered by the shell's `WKDownloadDelegate`, which
`scripts/nativephp_ios_download_delegate.php` installs: the navigation is taken
as `.download`, the bytes are written to a temporary directory, and a
`UIActivityViewController` opens — so "Save to Files" puts the codes in iCloud
Drive, or wherever else the reader picks, outside the container that deleting
the app destroys. `RecoveryCodesDisplay` therefore does *not* route iOS to
`mobile.recovery-codes.export`; `MobilePlatform::savesWebViewDownloads()` is
the gate, and a platform the enum does not model keeps the endpoint. The import
wizard's `recovery_codes` step (`MobileImportBootstrap`) shows the same ten
codes and makes the same choice through the same gate.

The Android shell registers no `DownloadListener`, so the same click is dropped
without a word. There the screen calls `mobile.recovery-codes.export`, which
keeps a copy under `UserDataPathService::appPath('exports/…')` — a copy no file
manager can open and a reinstall destroys, which is exactly what
`auth::recovery_codes.saved_native` says, down to telling the reader to use Copy
codes if nothing appeared.

`ShareSheetExport` also hands that copy to `Share::file()`.
nativephp/mobile 4.1.0 ships the PHP `Share` facade and registers `Share.File`
in neither shell's bridge registry, so `scripts/nativephp_android_share_file.php`
adds it to the generated Android shell; `NativePHPCall` returns nil for an
unregistered function without raising, which is why the bridge asks
`nativephp_can('Share.File')` before it claims anything. Nothing on the iOS path
depends on it. That class is now the seam every download surface in the app
reaches a phone through — see
[a download the shell drops](../mobile/a-download-the-shell-drops.md).

The owner's copy of a partner's regenerated codes on `/settings/users/<name>`
takes the same fork: a `data:` URL on an `<a download>` where the WebView saves
one, and `ManageUserPage::downloadCodes()` into the share sheet where it does not.

Publishing the container instead was considered and rejected. `UIFileSharingEnabled`
and `LSSupportsOpeningDocumentsInPlace` expose the app's whole `Documents`
directory, and on this shell that directory holds `app/.env`,
`persisted_data/database/database.sqlite` and
`persisted_data/storage/app/secrets/` — the app key, every transaction and the
sync keyring — browsable and copyable over Finder file sharing, and deletable
from the Files app. `scripts/nativephp_exclude_data_from_backup.php` exists to
keep that same tree out of iCloud and Google backup; publishing it to Files to
surface one 249-byte file would undo that decision.

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
a 6–10 digit PIN (Argon2id-hashed; the bounds are
`AppLockPinShape::MINIMUM_LENGTH`/`MAXIMUM_LENGTH`) or, on capable devices, WebAuthn
platform biometrics. Both gate access to a per-session **data key** that
downstream encryption features (base-currency FX, at-rest field encryption)
read through `AppLockKeyService::release()`/`withhold()`.

Both the PIN verifier (`PinHasher`) and the wrap key (`AppLockKdf`) derive at
the one work factor the whole application shares, injected as
`Modules\Core\Public\Contracts\KdfCost` — 256 MiB and three passes in a shipped
install, libsodium's floor under the test suite. The parameters, the tests that
pin them, and why the substitution cannot be reached in production are
[The Argon2id cost, and why the suite does not pay it](../../architecture/argon2id-cost.md).

### Key-wrapping model

`AppLockProvisioner::enable()` double-wraps a 32-byte data key under a single
shared KDF salt. The key is freshly generated
(`random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)`) **only** where nothing is
encrypted under an existing one; where at-rest encryption is active it takes
the existing key or refuses, and `disable()` refuses outright. That invariant,
and why the app lock cannot be turned off once data is encrypted, is
[The app-lock data key, and the data that cannot outlive it](app-lock-data-key-lifetime.md).

The two wraps:

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

A re-provisioning may replace the data key, which would invalidate every
existing per-device biometric wrap (they hold the old key) — `enable()` and
`disable()` both delete all biometric credentials for the user so a stale
enrollment can never unlock with divergent key material. `enable()` deletes
them only once it holds a key, so a refused enable destroys nothing.

`changePin()` dispatches `AppLockPassphraseChanged` after a successful PIN change so a
Sync listener re-wraps the Group Data Key keyring under the (unchanged)
app-lock data key; the listener is deliberately best-effort/never-throw so
a keyring re-wrap failure can never surface as this method throwing
instead of returning its documented `bool`.

### Confirmation matrix

Each settings-surface mutation requires a different proof, matched to how
security-sensitive it is:

| Action | Requires |
|---|---|
| Enable (first PIN setup) | Account password (builds the password recovery wrap, and opens an existing one) |
| Disable | Current PIN — and refused outright while at-rest encryption is active |
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

#### The unlock moment has one name

`LockStateManager::unlock()` dispatches `Modules\Auth\Public\Events\AppLockUnlocked`,
carrying the session that now holds a key. It is dispatched from `unlock()` and
nowhere else because `unlock()` is the funnel: five production paths reach it —
`AppLockProvisioner::enable()`, `AppLockProvisioner::primeSessionAfterLogin()`,
`PinVerificationService::markUnlocked()`, `WebAuthnBiometricService`, and
`AppLockKeyService::admitDataKey()`. Announcing from any one of them, including
the public `admitDataKey()`, would miss the other four — a lock-screen PIN and a
biometric unlock among them — and a listener written against an event that fires
for some unlocks is correct only by accident.

It is a PHP event, not one of the browser-side names in `AppLockEvents`: an
unlock lands in a service during an ordinary request (a lock-screen POST, a
sign-in) where no Livewire component is mounted for `#[On]` to reach.

`EveryUnlockAnnouncesItselfTest` asserts one dispatch per path, and closes the
set with a structural check that nothing outside `LockStateManager` writes the
held-key session entry — so no sixth path can appear without coming through the
funnel. `LockStateManager`'s dispatcher argument is nullable only so
`new LockStateManager` keeps working in unit tests; every container-resolved
instance receives one, and `AppLockTestHarness` resolves one explicitly so the
sanctioned test seam is not the one unlock listeners never hear.

The first consumer is Sync's `HoldPairingCeremonyOpenOnUnlock`, which revives a
pairing ceremony whose TTL lapsed while the app sat locked.

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

Every path that drops the handle goes through one private
`releaseHandle()`, which calls `custodian->forget()` before the session
forgets it. Both `lock()` and `clearStaleLock()` use it: dropping the
handle alone is a no-op on web and desktop but leaves the raw key in the
Keychain/Keystore on mobile with nothing left that could ever name it.

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
entirely and emits a `SystemAlert`. `AppLockProvisioner::
primeSessionAfterLogin()` clears `failed_attempts` and `locked_until`
whenever it runs — the lock screen's own copy sends a reader who has
forgotten the PIN to sign back in with the account password, so arriving
there IS that credential being proved. Without it the meter stayed at the
cap that signed them out, and the next mistyped digit signed them out
again over a screen reading "0 attempts remaining". A corrupted wrap blob is treated as a
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

#### The login that primes nothing: remember-me

A remember-me recaller reaches neither `LoginAction` nor Fortify's pipeline.
`SessionGuard` resolves the user straight from the cookie into a **brand-new**
session, where the lock flag is simply absent — so `AppLockMiddleware` took the
unlocked branch, and re-locked only if the idle window had expired, which it had
not. Nothing primed a data key either. That is precisely the state the priming
above exists to prevent, arrived at through the one door it does not watch.

It is not only reachable by lifting the cookie. `SESSION_LIFETIME` is 30 days
and the recaller cookie is about five years, so an ordinary session expiry
re-authenticates the reader into an unlocked, key-less session with no attacker
anywhere.

`Internal/Listeners/StartLockedOnLogin` closes it from the other side: every
`Illuminate\Auth\Events\Login`, for an account with the lock enabled, starts
**locked**. The password paths unwrap the data key and unlock a moment later —
`$guard->login()` fires the event before `primeSessionAfterLogin()` runs, and
`PrepareAuthenticatedSession`'s regenerate carries session attributes across —
so the fail-closed default costs them nothing. What is left locked is the login
that proved no key, which is the recaller and nothing else.

Suppressing the remember cookie while the lock is on was the alternative. It
was not taken: it withdraws a convenience that has nothing to do with the lock,
and it would still leave any cookie already issued working.

### Lock screen (`LockScreen` Livewire page)

The `/lock` route offers exactly three things to do — PIN pad, biometric
prompt, sign out — and nothing else. Sign out is reachable through two
controls with the same POST target: the plain one, and the forgotten-code
signpost (`lock_screen.forgot_pin`) whose copy states that tapping it
signs you out, that the account password signs you back in, and that no
data is lost. The reset itself stays in Settings behind a password
login — putting it on the lock screen would reduce the app-lock's value
to the account password's for anyone holding a locked device, and the
lock exists precisely because it is a separate gate. The mobile screen
carries the same pair. PIN digits are never rendered in an
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
request the middleware compares this session's last activity to the
current time; past the configured idle timeout it locks the session and
redirects to `/lock`. To avoid a DB read on every request, the lock config
(`lock_enabled`, `idle_timeout_minutes`) is cached in the session and
re-read from the DB only once per a short TTL window.

The idle clock is PER SESSION, held in
`AppLockMiddleware::SESSION_LAST_ACTIVITY`. `user_app_lock_configs.
last_activity_at` is a single column keyed on `user_id`, so with two
sessions of one account — the ordinary shape here, the desktop bundle
plus a browser tab — every session wrote it and every session read it,
and activity in one held the other's idle timer open indefinitely. The
column is still written on every passing request, because
`EngageAppLock`'s unlock grace window and the client's own timer
read it; only the idle DECISION moved into the session. A session with no
stamp of its own yet falls back to the column, which is what the request
right after a sign-in does.

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

`lock.js` drops the privacy veil when the window is backgrounded *or*
blurred, and starts a grace timer on backgrounding alone; when that timer
elapses it posts to `/lock/engage`, which withholds the session key and
marks the session locked. A bare blur veils and nothing more — another
window taking focus while ours stays on screen is no app-switcher
snapshot, and counting it down locked a reader who clicked away for half
a minute against a thirty-minute idle setting. `AppLockMiddleware` tests
the session lock flag *before* it loads the user's lock config, so a
session locked this way stays locked regardless of whether a lock was
ever configured.

The window itself is `IdleTimeoutOptions::BACKGROUND_GRACE_SECONDS`, and
it has one definition. `AppLockClientConfig::backgroundGraceMs()` carries
it to the layout, which emits it as `window.beatraxGraceMs` beside
`beatraxIdleMs`; the timer reads it from there, `LockIdleClock` enforces
it server-side for the suspended-WebView case, and the app-lock settings
copy discloses it through the same constant. Leaving the foreground is a
second lock condition that the configured idle timeout does not govern,
which is why the settings screen has to say so.

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
  veil listeners, so `_serverLock()` is never reached. `window.beatraxGraceMs`
  is emitted from the same block, so the grace timer has no window to run
  when the veil listeners were never registered either.
- **Server.** `AppLockLiveness::isArmed()` asks
  `AppLockClientConfig::isEnabled()`, and both `EngageAppLock` and
  `RecordAppBackgrounded` return without touching the session when the lock
  is off — the route answers `204` either way. This is the authoritative
  check: a stale tab left open from before the lock was disabled, a second
  window, or a replayed request must not be able to strand the session. It
  is written once because two endpoints ask it, and the two answers drifting
  apart is the bug.

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
- `markColdStartEnrolled()` and `isColdStartEnrolled()` delegate to
  `ColdStartEnrolmentFlag`, which owns the `cold_start_biometric_enrolled`
  column on its own. `MobileColdStartVault` takes that collaborator rather
  than this gateway: the gateway is built from `AppLockProvisioner`, and the
  provisioner forgets the cold-start vault on enable and disable, so a vault
  that depended on the gateway would close a container cycle.
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

### Why the mobile runtime forced two changes here

Both of these were measured on an Android device, and neither reproduces on
the desktop shell.

**Livewire requests are recognised by route name, not the `X-Livewire`
header.** Under NativePHP's Android runtime the PHP process is persistent and
its superglobals are not fully rebuilt per request, so `HTTP_X_LIVEWIRE` set
by one Livewire POST is still present on every ordinary page load that follows
it in the same worker — for the rest of the app's life. The effect was that
after the first Livewire request of a session, `last_activity_at` stopped
moving entirely: every page load looked like machine traffic and took the
not-activity branch that skips `LockIdleClock::recordActivity()`. A reader was locked out five minutes after
their last Livewire request no matter how much they used the app, and no
navigation was ever remembered to return them to. Livewire's update endpoint
always carries its route name (it renames a custom route to end with it, hence
the leading wildcard in the pattern), and a route is resolved per request by
the router rather than read out of `$_SERVER`, so it cannot leak across
requests the way the header does. It is the same signal Livewire itself uses
to decide whether to run persistent middleware at all.

**`LockLifecycleController::resume()` answers with a body, not a status or a
redirect.** Neither survives the Android bridge: it follows the middleware's
redirect in-process and hands the page back as an ordinary response, so the
`fetch()` Response reads `redirected === false`. `lock.js` trusted that and
therefore never reloaded on the phone — the previous screen stayed rendered
and interactive over a locked session. A body the client has to parse cannot
be forged by transport that rewrites metadata: the lock screen is HTML and
fails the check.
