# `OpenBanking` — architecture

The `OpenBanking` module is an off-by-default, optional connector that links
an ASN or SNS account via the Enable Banking PSD2 aggregator (BYO-key: the
user registers their own Enable Banking application) and lands booked
transactions through the existing import-preview pipeline, idempotently, so
they collide with the same rows an ASN CAMT.053/CSV export would produce.
The whole surface is opt-in and reversible: nothing is fetched, and no
credential exists on disk, until the user completes the consent wizard.

## Security posture: AIS-only, egress-guarded, BYO-key

- **AIS-only by construction.** `EnableBankingAccessScope` is a typed DTO
  with exactly three booleans (`balances`, `transactions`, `accounts`) and
  no `payments` property anywhere in the class — a payment-initiation scope
  can never be requested because there is no field to set it on. Adding
  PIS support would require a reviewable code change, not a silent runtime
  array-key addition.
- **`EnableBankingHttpClient` is the sole HTTP boundary** to Enable Banking.
  Every request (a) resolves the caller's own credentials, (b) checks the
  target URL against a host allow-list — `api.enablebanking.com` plus the
  resolved per-connection bank SCA host once one is known — with an
  explicit https-only + exact-host check, and only then (c) signs and
  attaches an RS256 bearer JWT. The allow-list check runs before the JWT is
  built, on every call site, so no response-derived follow-up URL can ever
  reach the network with a valid bearer attached. Guzzle's `allow_redirects`
  is `false` — a 3xx is surfaced as an error body, never followed, since a
  `Location` header could otherwise bypass the allow-list check or
  downgrade https to http.
- **The bank SCA host is validated before it ever widens the allow-list.**
  `OpenBankingConnectController` resolves the SCA host from the aggregator's
  `/auth` response and rejects `localhost`, bare single-label hosts, and any
  IP literal in a loopback/link-local/private/reserved range before
  persisting it — an aggregator response (or a TLS-defeating MITM) can never
  smuggle an internal target into the egress allow-list. The same controller
  also re-validates the consent redirect URL itself (https + host must equal
  the just-resolved SCA host) before issuing the outward redirect.
- **The RSA private key never leaves the machine.** `EnableBankingJwtSigner`
  signs locally (via `firebase/php-jwt`, not hand-rolled base64url +
  `openssl_sign`); only the resulting signed token crosses the wire.
  `OpenBankingWizardModal::generateKeypair()` writes the private key
  straight to the secrets file and never assigns it to a public Livewire
  property, so it structurally cannot appear in a `wire:snapshot` payload
  sent to the browser.

## Secrets storage

`OpenBankingSecretsRepository` is the one class in the module allowed to
touch the filesystem secrets path (enforced by an arch test) — a chmod-600
JSON file at `storage/app/secrets/open-banking.json`, never a DB column,
since any secret in SQLite would leak into DB backups. Writes are atomic
(`.tmp` file, `fwrite`, `fflush`/`fsync`, chmod 0600, then rename) so a crash
mid-write never leaves a half-written file; the write's brief-open umask is
narrowed to 0077 so the temp file is born non-world-readable before the
explicit chmod runs. The encoded JSON is passed through `SecretShield`
before it touches disk — identity on web, OS-keychain-bound ciphertext on
the desktop bundle. Every failure raises a typed `SecretsWriteFailed` whose
message never carries the JSON payload, only the path, so credential
material can never leak into a log line.

**Single-user v1 caveat**: this is one global secrets file with no per-user
or per-connection keying — a second user's `save()` would silently overwrite
the first user's credentials. `guardSingleUser()` logs a loud warning (not a
hard throw, to avoid breaking DB-less unit tests) whenever a write happens
while more than one user account exists. Per-user keying is required before
a second user can safely use this connector.

## Consent / OAuth dance

The connect → SCA → callback flow mirrors the EmailScan module's OAuth
shape, adapted for Enable Banking's two-step `/auth` → `/sessions` exchange
(there is no refresh-token concept; a session stays valid until the
`access.valid_until` requested at `/auth` time).

- **CSRF state** (`OpenBankingStateRepository`) is a 64-char random hex
  token stored in the session, bound to the initiating user id, single-use
  (pulled on consume regardless of outcome), compared with `hash_equals`
  (constant-time), and rejected once older than 10 minutes.
- **The callback's DB write and the secrets-file write are ordered so a
  failure can be compensated.** The `open_banking_connections` row is
  written first (inside a transaction); the chmod-600 secrets write happens
  after. If the secrets write fails, a brand-new row is deleted outright; an
  existing row (re-link) is rolled back to its pre-update
  `consent_expires_at`/`account_uid` snapshot rather than left advertising a
  fresh consent the secrets file cannot actually back.
- **Single-live-session model.** `OpenBankingSecretsRepository` holds
  exactly one Enable Banking session at a time. `OpenBankingConnectionQuery`
  resolves "the" connection only via the secrets file's currently-active
  `institutionId` — never by picking "the most recent row" — so a stale row
  from a previously-linked, since-superseded institution is never surfaced
  as a second simultaneously-live connection. `OpenBankingSettingsPage::
  enableOpenBanking()` enforces the same invariant on write: enabling one
  connection row disables every other row for the user (and blanks their
  consent), so a stale prior-institution row can never keep being picked up
  by the daily-sync scheduler. `OpenBankingFetchService::buildFetch()`
  additionally refuses to fetch when the connection row's institution
  doesn't match the secrets file's currently active session's institution —
  pairing one bank's session with another bank's account uid would be at
  best a confusing misreported failure, at worst a cross-account exposure
  risk if the aggregator's API were ever lenient about the mismatch.

## Settings page: server-authoritative enable gate

`OpenBankingSettingsPage` (`/settings/open-banking`) is the trust surface:
an off-by-default toggle gated behind a loud third-party-data warning, an
always-visible transparency panel, and the consent-expiry re-link flow. The
interaction contract spans a full page navigation to the bank's consent
screen and back, so the "the warning was shown and acknowledged" state
cannot live on a single component instance — it has to survive in the
session.

- `requestEnable()` opens the warning modal and sets the server-side,
  `#[Locked]` `$warningShown` flag — never the client-bound `$acknowledged`
  Livewire property, which is forgeable via a crafted request.
  `confirmWarning()` checks `$warningShown` first; a direct call that skips
  `requestEnable()` is a structural no-op regardless of a forged
  `$acknowledged=true`. On success it persists an **epoch timestamp** (not a
  bare boolean) to the session and clears `$warningShown` (single-use).
- The session acknowledgement carries a TTL (`ACK_TTL_SECONDS`, 2 hours —
  long enough to cover a first-time Enable Banking application registration
  plus SCA) so an abandoned tab cannot leave a standing, indefinitely-valid
  authorization sitting in the session. A lapsed acknowledgement surfaces a
  visible "re-confirm to finish enabling" CTA (`reconfirmEnable()`) rather
  than silently leaving a fully-consented connection disabled.
  `OpenBankingWizardModal::cancel()` clears the flag immediately on an
  explicit cancel, so the TTL is only the residual exposure window for a
  silently-abandoned tab, not the normal-case window.
- `enableOpenBanking()` — the one method that ever flips
  `open_banking_connections.enabled = true` — independently re-validates the
  fresh-acknowledgement flag on every call and scopes every write to the
  current user's own `user_id`; `$pendingConnectionId` is attacker-settable
  as a Livewire property, so the `user_id` predicate, not the property
  itself, is what actually prevents a cross-user enable.
- `disconnect()` clears the on-disk secrets entry and blanks `enabled`/
  `consent_expires_at` on **every** row belonging to the user, not just the
  one connection currently displayed — otherwise an orphaned row from a
  different, previously-linked institution would keep being picked up by
  the scheduler after the user believes they fully disconnected.

## Dedup / fingerprint parity contract

`EnableBankingSourceAdapter` is the load-bearing guarantee that a
remote-fetched transaction and the same real-world transaction imported
from an ASN CAMT.053 export collide on the exact same fingerprint — "zero
net-new duplicates" depends on every mapped field matching the CAMT
adapter's choices byte for byte:

- `bookedAt` **and** `postedAt` are both the aggregator's `booking_date`,
  zeroed to midnight — never `value_date`. `value_date` is carried
  separately (outside the fingerprint tuple).
- Amounts are derived via `Brick\Money`, never `(float)`, and explicitly
  negated when `credit_debit_indicator === 'DBIT'`.
- Counterparty name/IBAN follow the same DBIT→creditor / CRDT→debtor
  direction rule the CAMT adapter applies.
- Only `status === 'BOOK'` rows are consumed; PSD2 `'PDNG'` (pending) rows
  are dropped before they ever reach the canonical DTO — there is no
  concept of a pending transaction anywhere downstream.
- A missing `booking_date`/`value_date` throws rather than falling through
  to `CarbonImmutable::parse('')`, which silently resolves to the wall
  clock — a fingerprinted date derived from fetch-time rather than the
  aggregator's own data would break re-fetch idempotency permanently (two
  fetches of the same incomplete row would each stamp a different date and
  never re-collide).
- A single malformed-money row is skipped (logged) at the row level rather
  than aborting the whole fetch generator.

`OpenBankingFetchService::buildFetch()` eagerly materializes the adapter's
generator (`iterator_to_array`) before handing rows to the shared import
pipeline, rather than passing the lazy generator straight through — the
pipeline's preview builder swallows any exception raised mid-iteration into
an opaque per-row error status (correct for an upload, since a bad file
still renders a preview), which would otherwise hide every fetch failure
from `SyncOpenBankingAccountJob`'s two-timestamp accounting and
consent-failure detection.

## Sync job and freshness accounting

`SyncOpenBankingAccountJob` (the scheduler + "Sync now" target) and
`OpenBankingSettingsPage::syncNow()` both follow the same two-timestamp
rule: `last_successful_sync_at` is written **only** in the success branch,
never in a `finally` — a failed attempt must never advance the freshness
signal a user reads as "how current is my data." Every attempt (success or
failure) writes `last_attempt_at`/`last_attempt_status` independently, so a
silently-failing scheduled sync stays visible. A 401/403 from Enable
Banking (detected by inspecting `EnableBankingHttpClient`'s error message
for `"HTTP 401"`/`"HTTP 403"`, since no typed exception distinguishes a
consent failure yet) is terminal for that attempt and dispatches
`OpenBankingConsentFailed`, which `RaiseOpenBankingReconsentAlert` turns
into a deduplicated `system_alerts` row (at most one active alert per
`(user, connection)`). Any other failure rethrows so the queue's retry
envelope applies. Both the job and the fetch service independently re-check
`enabled` + `consent_expires_at > now()` on pickup — a race where the user
disables the connection or the consent expires while a job sits queued must
still no-op with zero fetch attempt and zero timestamp write.

## Onboarding wizard

`OpenBankingWizardModal` walks the user through six steps: (1) generate a
local RSA keypair — the private key is written straight to the secrets file
and never assigned to a public Livewire property, so it cannot round-trip
into a `wire:snapshot`; (2) an informational step to register the
application in the Enable Banking portal using the public key + redirect
URI; (3) paste back the pasted `application_id`; (4) choose the bank (ASN,
SNS, or a free-text "other" institution id — never hardcoded); (5) hand off
to the consent/SCA dance; (6) render the done/error state from the
callback's flash values. A reconnect flow (triggered from the settings
page's consent-expiry banner) can skip straight to step 4, reusing the
already-registered application — `open()` only honors a requested start
step when `hasApplication()` is true, so a reconnect can never accidentally
regenerate a keypair or wipe an existing registration.

## Reconsent alerting

`RaiseOpenBankingReconsentAlert` writes a single un-acknowledged
`system_alerts` row whenever `OpenBankingConsentFailed` fires, deduplicated
to at most one active row per `(user_id, connection_id)` — the existence
check filters on `acknowledged_at IS NULL`, so once a prior alert is
acknowledged a fresh failure creates a new row. The dedup lookup prefers
SQLite's `json_extract` against the `metadata` column, falling back to a
dual-needle LIKE match (`%"connection_id":N,%` OR `%"connection_id":N}%`) on
a SQLite build without the JSON1 extension compiled in — a single needle
would let `connection_id=1` falsely match `connection_id=10`/`11`/`123`. The
listener never throws upward (a try/catch around the insert, logging a
warning on failure) since its caller is typically already mid-error-recovery.

## Local dev/UAT: HTTPS loopback tunnel

Enable Banking requires an `https://127.0.0.1:PORT/...` redirect URI (unlike
Google/Microsoft, it does not extend the native-client loopback exception to
plain HTTP), but `php artisan serve` is plain-HTTP. `open-banking:serve-tls`
(registered only behind `runningInConsole()`) stands up a stunnel-style TCP
tunnel: it terminates TLS with a throwaway self-signed certificate
(`LoopbackTlsCertificate`, CN/SAN `127.0.0.1`+`localhost`, key written 0600)
on the loopback redirect port and pumps decrypted bytes to a plain
`artisan serve` backend — no HTTP parsing, so keep-alive and Livewire
polling flow through unchanged. This is a local single-user dev tool only;
the certificate carries no trust beyond "the developer clicked through it
once."

## Public surface

- **Contracts** — `RemoteSourceAdapter` (the remote-fetch analog of the
  local-file `SourceAdapter` contract; deliberately has no
  `statementMetadata()` method since a balance reading is a point-in-time
  value, not an opening/closing pair).
- **Dto** — `FetchWindow`, `OpenBankingCredentials`, `OpenBankingConnectionView`.
- **Events** — `OpenBankingConsentFailed`.
- **Services** — `OpenBankingSecretsRepository`, `OpenBankingFetchService`,
  `OpenBankingConnectionQuery`.
