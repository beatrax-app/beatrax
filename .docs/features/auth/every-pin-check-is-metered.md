# Every PIN check is metered

The app-lock PIN is one secret. Anything that compares a typed code against
`user_app_lock_configs.pin_hash` — or unwraps `pin_wrapped_key` with it — is
checking that secret, whichever screen took the digits.

`PinVerificationService::verify()` is the only place that does it.

## Why one door

The meter used to be scoped to the lock screen, and the settings panels checked
the same code with nothing counting. The reasoning written beside the bypass was
that "callers here are already unlocked" — which is true, and is exactly the
situation the meter matters in. An unlocked session is what somebody who picked
up an open laptop has. Three panels answered them:

| panel | what a correct guess bought |
| --- | --- |
| Disable app lock | the lock, removed |
| Change PIN | a PIN the guesser chose |
| Remove biometric unlock | the enrolment, removed — and the code, confirmed |

The last one matters on its own. It answers yes or no about a secret, without
counting, and the answer can be carried to the lock screen, which does count.
A meter one surface enforces is a meter, only, on that surface.

## The shape

`AppLockProvisioner` no longer holds a raw comparison. Every PIN it is handed
goes through `proveCurrentPin()`, which is the metered verifier, and the data
key that comes back is the one the caller re-wraps — so a PIN change derives
once rather than verifying a hash and then deriving again.

The provisioner's own unmetered check is gone rather than made private. A
private raw check is a public one a later edit can promote back; there is
nothing here to promote.

## What the meter is keyed on

`user_app_lock_configs` has one row per user (`user_id` is unique), so
`failed_attempts` and `locked_until` are per account, not per surface and not
per session. Routing the settings panels through the verifier therefore gives
one shared counter: ten guesses spread across four screens are ten guesses.

A successful proof clears it, wherever it was proved — the same thing a
successful sign-in does to the sign-in limiter.

## The cap on a settings screen

`F3-R19` mandates the escalating backoff and a sign-out at the hard cap. The
response is not softened for Settings. Signing the session out is not a
punishment for mistyping; it is the only response that ends the access a
guesser is using, and a reader who reaches it signs back in with the account
password — which clears the meter on the way through
(`AppLockProvisioner::primeSessionAfterLogin()`).

Reaching the cap costs real time: the backoff arms at five failures and
escalates 30s → 60s → 300s, so ten wrong codes are not ten quick tries.

## What a refused PIN says

`AppLockCredentialRejections::refusedPin()` owns the sentence, so every screen
that meters gives the same three answers: the wait when a backoff window is
open, the count when one has been spent, and the bare refusal when there is no
meter to read. Before this, the settings panels said "Incorrect PIN." to a
correct PIN refused by a backoff window they had no idea existed.

## Related

- [architecture.md](architecture.md) — where the lock's pieces live.
- [app-lock-data-key-lifetime.md](app-lock-data-key-lifetime.md) — what the PIN
  wraps and why losing it strands data.
