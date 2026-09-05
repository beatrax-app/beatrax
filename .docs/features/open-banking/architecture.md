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
  target URL against a host allow-list of exactly one entry,
  `api.enablebanking.com`, with an explicit https-only + exact-host check,
  and only then (c) signs and attaches an RS256 bearer JWT. The allow-list
  check runs before the JWT is built, on every call site, so no
  response-derived follow-up URL can ever reach the network with a valid
  bearer attached. Guzzle's `allow_redirects` is `false` — a 3xx is surfaced
  as an error body, never followed, since a `Location` header could
  otherwise bypass the allow-list check or downgrade https to http.

  The list is one entry because every URL this client builds comes from
  `baseUri()`, which is the API host and nothing else. The bank's SCA origin
  is reached by the **reader's browser**, never by this client, so admitting
  it here would have authorised a bearer-token request no code path issues —
  at a host the aggregator's own response chooses.
- **The bank SCA host is validated before the browser is sent to it.**
  `StartBankConsent` resolves the SCA host from the aggregator's
  `/auth` response and rejects `localhost`, bare single-label hosts, and any
  IP literal in a loopback/link-local/private/reserved range before
  persisting it, then re-validates the consent redirect URL itself (https +
  host must equal the just-resolved SCA host) before handing the URL back to
  `OpenBankingConnectController` for the outward `Redirector::away()`.
  Resolving, allow-listing and checking are one decision and stay in one
  class: a controller holding half of it would be two files agreeing by
  hand. What that protects is the redirect: an aggregator
  response (or a TLS-defeating MITM) must not be able to point the reader's
  browser at an internal target or turn this into an open redirect.
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
JSON file at `storage/app/secrets/open-banking/<userId>.json`, never a DB
column, since any secret in SQLite would leak into DB backups. Writes are atomic
(`.tmp` file, `fwrite`, `fflush`/`fsync`, chmod 0600, then rename) so a crash
mid-write never leaves a half-written file; the write's brief-open umask is
narrowed to 0077 so the temp file is born non-world-readable before the
explicit chmod runs. The encoded JSON is passed through `SecretShield`
before it touches disk — identity on web, OS-keychain-bound ciphertext on
the desktop bundle. Every failure raises a typed `SecretsWriteFailed` whose
message never carries the JSON payload, only the path, so credential
material can never leak into a log line.

**Keyed on two axes.** One file per reader, and inside it one record per
bank: the application half (`application_id`, `private_key_pem`) that the
reader registered once, and a `connections` map from institution id to that
bank's own `session_id`, `consent_expires_at` and `bank_sca_host`. Every
public method on the repository takes an `int $userId` first — there is no
address in this store that does not name a reader, so reading another
reader's connector secret is unrepresentable rather than discouraged.

The pre-keying store was one file for the whole installation holding one
live session. A dated migration adopts it into the keyed store, deriving the
owner from the connection row carrying the stored institution rather than
guessing, so an installed reader crosses the upgrade still connected. See
[`secrets-at-rest.md`](secrets-at-rest.md#the-migration-out-of-the-installation-wide-store).

## Consent / OAuth dance

The connect → SCA → callback flow mirrors the EmailScan module's OAuth
shape, adapted for Enable Banking's two-step `/auth` → `/sessions` exchange
(there is no refresh-token concept; a session stays valid until the
`access.valid_until` requested at `/auth` time).

- **CSRF state** (`OpenBankingStateRepository`) is a 64-char random hex
  token stored in the session, bound to the initiating user id **and to the
  institution the consent was begun for**, single-use (pulled on consume
  regardless of outcome), compared with `hash_equals` (constant-time), and
  rejected once older than 10 minutes. `consumeState()` returns that
  institution, which is how the callback knows which bank it is finishing —
  a store asked to remember that for the reader could only hold one answer,
  which is what made a second bank unusable. Issuing and
  consuming it stay in the two controllers — the state exists only to be read
  back by the callback request — while the work either end of it is
  `StartBankConsent` and `CompleteBankConsent`
  ([a controller hands the work to an action](../../conventions/a-controller-hands-the-work-to-an-action.md)).
- **The DB write and the secrets-file write are ordered so a
  failure can be compensated.** In `CompleteBankConsent`, the
  `open_banking_connections` row is
  written first (inside a transaction); the chmod-600 secrets write happens
  after. If the secrets write fails, a brand-new row is deleted outright; an
  existing row (re-link) is rolled back to its pre-update
  `consent_expires_at`/`account_uid` snapshot rather than left advertising a
  fresh consent the secrets file cannot actually back.
- **One session per bank, addressed by both.** `StartBankConsent` merges
  the resolved SCA host into that institution's own record, so a consent
  begun at a second bank and abandoned at its login leaves the first bank's
  live session exactly where it was. `CompleteBankConsent` writes the new
  session under the institution the OAuth state named.
  `OpenBankingFetchService::buildFetch()` loads by `(user, institution)`,
  so a connection can neither reach another bank's session nor overwrite
  it — pairing one bank's session with another bank's account uid is not a
  refusal any more, it is unaddressable.

## Settings page: server-authoritative enable gate

`OpenBankingSettingsPage` (`/settings/open-banking`) is the trust surface:
an off-by-default toggle gated behind a loud third-party-data warning, and
one always-visible transparency panel per connected bank carrying that
bank's own consent-expiry re-link flow. The
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
- The session acknowledgement carries a TTL of two hours — long enough to
  cover a first-time Enable Banking application registration plus SCA — so
  an abandoned tab cannot leave a standing, indefinitely-valid
  authorization sitting in the session. The value is the private
  `OpenBankingSettingsPage::ackTtlSeconds()` rather than a constant another
  class can reach, so the page that issues the acknowledgement is the only
  thing that decides when it lapses. A lapsed acknowledgement surfaces a
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
  itself, is what actually prevents a cross-user enable. It stands up only
  the row the reader just consented to: the banks already connected keep
  their own consent and their own place in the schedule, because each holds
  its own secret and enabling one says nothing about the others.
- The screen renders one `OpenBankingConnectionCard` per connected bank —
  its own consent pill, its own freshness line, its own **Sync now** — so
  nothing on the page has to choose which of two connected banks it speaks
  for. `connectAnotherBank()` opens the wizard straight at the bank picker,
  since the application is already registered and the third-party warning
  was answered when the first bank was linked.
- `disconnect()` is deliberately all-or-nothing: it deletes the reader's
  whole secrets file and blanks `enabled`/`consent_expires_at` on **every**
  row belonging to them, however many banks they had connected. The reader
  is turning the connector off, not one bank; a row left enabled would keep
  its place in tomorrow's schedule after they believe they are off, and a
  session left on disk would still be spendable.

## Dedup / fingerprint parity contract

`EnableBankingSourceAdapter` is the load-bearing guarantee that a
remote-fetched transaction and the same real-world transaction imported
from an ASN CAMT.053 export collide on the exact same fingerprint — "zero
net-new duplicates" depends on every mapped field matching the CAMT
adapter's choices byte for byte:

- `bookedAt` **and** `postedAt` are both the aggregator's `booking_date`,
  zeroed to midnight — never `value_date`. `value_date` is carried
  separately (outside the fingerprint tuple).
- Amounts are derived via `Brick\Money`, never `(float)`, parsed at the
  scale the row's **own** currency declares rather than a fixed hundred, and
  explicitly negated when `credit_debit_indicator === 'DBIT'`. A yen has no
  minor unit: parsed at the hundred, every JPY row lands a hundred times the
  figure the bank sent, and a fractional yen the currency cannot express is
  accepted instead of skipped. `MoneyInput::tryToMinor()` takes the currency
  code for exactly that. The CAMT adapter never parses a decimal string at
  all — `genkgo/camt` hands it a `Money\Money` already in the currency's own
  minor units — so the EB path has to reach the same integer by parsing at
  the same scale, or the two adapters disagree on the same transaction and
  fingerprint parity is gone.
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
- The `continuation_key` walk is bounded — a repeated cursor, 100 pages or
  25,000 scanned rows — and the generator RETURNS a `FetchWalk` naming why
  it stopped, so a truncated window can never be mistaken for a quiet one.
  See [Fetch cursor](fetch-cursor.md#bounding-the-page-walk).

`OpenBankingFetchService::buildFetch()` eagerly materializes the adapter's
generator (`iterator_to_array`) before handing rows to the shared import
pipeline, rather than passing the lazy generator straight through — the
pipeline's preview builder swallows any exception raised mid-iteration into
an opaque per-row error status (correct for an upload, since a bad file
still renders a preview), which would otherwise hide every fetch failure
from `SyncOpenBankingAccountJob`'s two-timestamp accounting and
consent-failure detection.

## Sync job and freshness accounting

`OpenBankingSyncRunner` owns the two-timestamp rule, and both entry points
— `SyncOpenBankingAccountJob` (dispatched by the `open-banking:sync-due`
schedule entry, which is registered `->daily()` and so fires at midnight)
and `OpenBankingConnectionCard::syncNow()` (the button on one bank's own
card) — go through it.
`last_successful_sync_at` is written **only** in the success branch, never
in a `finally` — a failed attempt must never advance the freshness signal a
user reads as "how current is my data." Every attempt (success or failure)
writes `last_attempt_at`/`last_attempt_status` independently, so a
silently-failing scheduled sync stays visible. Every write is scoped to the
connection's `user_id` as well as its id: the owner can be cleared by a
cascading delete while a fetch is in flight, and a timestamp then describes
an attempt made for somebody who is gone.

The freshness signal is **not** the fetch cursor. `fetched_through_at` is,
and it advances only from a `committedThrough` the write returned — never
from the window the fetch asked for. `preview()` cannot produce one, so
"Sync now" leaves the cursor where it was; `fetchAndConfirm()` produces one
only when the page walk also reached the end of the bank's pages. The
window it opens re-reads a backdating overlap behind that cursor, and a
walk that stopped on a bound is recorded as `SyncAttemptStatus::Truncated`
rather than passed off as a success. All three decisions, and the numbers
behind them, are [Fetch cursor](fetch-cursor.md).

`SyncAttemptStatus::NothingImported` is the third non-success, and the only
one where the bank, the consent and the walk all worked: rows arrived and not
one of them could be filed. It is derived in one place —
`OpenBankingFetchResult::attemptStatus()` — so the status the connection row
keeps and the status the outcome hands back cannot be two readings of the
same fetch. Why it is neither `Ok` nor `Error`, and why the run is announced
rather than failed, is [A feed that imports nothing](a-feed-that-imports-nothing.md).

A 401/403 is also written onto the row, not merely logged as a failed
attempt: `consent_revoked_at` is stamped in the same UPDATE, and
`ConsentStatus::Revoked` is what the tile, the status row and the banner
then read. Without it a withdrawn session kept reading "Connected" off a
`consent_expires_at` still months away — see [Consent
window](consent-window.md#revocation-is-not-expiry).

The runner returns an `OpenBankingSyncOutcome` and the two entry points
differ only in what they do with it. The job rethrows a retryable failure so
the queue's retry envelope applies; the page turns the same failure into a
flash, because a browser round trip has no retry envelope. A 401/403 from
Enable Banking is terminal either way: `EnableBankingApiException` carries
the HTTP status as typed state and `consentFailureWithin()` walks
`getPrevious()`, so the detection survives the import pipeline wrapping what
it rethrows. It dispatches `OpenBankingConsentFailed`, which
`RaiseOpenBankingReconsentAlert` turns into a deduplicated `system_alerts`
row (at most one active alert per `(user, connection)`).

The runner also takes the job's **own** uniqueness key for the duration of
the fetch. `ShouldBeUniqueUntilProcessing` releases that lock before
`handle()` runs, so the fetch itself was unguarded: a "Sync now" click could
race the scheduled job against the same connection, and whichever UPDATE
landed second decided `last_successful_sync_at`. A manual sync that cannot
take the key reports that a sync is already running rather than starting a
second one.

Both entry points and the fetch service independently re-check `enabled` +
a live consent on pickup — a race where the user disables the connection or
the consent expires while a job sits queued must still no-op with zero fetch
attempt and zero timestamp write. That consent boundary is
`ConsentWindow`'s: see [Consent window](consent-window.md).

## Onboarding wizard

`OpenBankingWizardModal` renders one branch per case of `WizardStep`:
`Keypair` generates a local RSA keypair — the private key is written
straight to the secrets file and never assigned to a public Livewire
property, so it cannot round-trip into a `wire:snapshot`; `Register` is an
informational step to register the application in the Enable Banking portal
using the public key + redirect URI; `ApplicationId` takes the
`application_id` back from the portal; `Bank` chooses the institution (ASN,
SNS, or a free-text "other" institution id — never hardcoded); and
`Consent` hands off to the consent/SCA dance. The done and error states are
not a step of the wizard: `OpenBankingCallbackController` redirects to
`settings.open-banking` with a flash value and the settings page renders
it. A reconnect flow (triggered from the consent-expiry banner on the
affected bank's own card) can skip straight to `WizardStep::Bank`, reusing the
already-registered application — `open()` only honors a requested start
step when `hasApplication()` is true, so a reconnect can never accidentally
regenerate a keypair or wipe an existing registration. The requested step
also has to be a step: it arrives on a client-triggerable Livewire event and
picks which branch the modal renders, so `WizardStep` is what it is matched
against. A number outside the enum is not honoured at all, because a step
number no branch matches rendered a dialog with a heading, no controls, and
no way out of it.

## Connection alerting

Two faults are worth a standing `system_alerts` row per connection: a consent
the bank withdrew (`OpenBankingConsentFailed` →
`RaiseOpenBankingReconsentAlert`) and a run that filed none of the rows it
fetched (`OpenBankingImportedNothing` →
`RaiseOpenBankingNothingImportedAlert`). Both go through `ConnectionAlerts`,
which raises at most one active row per `(user_id, kind, connection_id)` — the
existence check filters on `acknowledged_at IS NULL`, so once a prior alert is
acknowledged a fresh failure creates a new row. It is not
`SystemAlertWriter::raiseOnceForUser()`, which dedups per kind alone: a reader
with two banks needs to be told about each of them.

The dedup lookup prefers SQLite's `json_extract` against the `metadata`
column, falling back to a dual-needle LIKE match (`%"connection_id":N,%` OR
`%"connection_id":N}%`) on a SQLite build without the JSON1 extension compiled
in — a single needle would let `connection_id=1` falsely match
`connection_id=10`/`11`/`123`. The raise never throws upward (a try/catch
around the insert, logging a warning on failure) since its callers are
listeners running inside somebody else's error recovery.

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

The module's entire `Public/` directory is one file:
`Modules/OpenBanking/Public/Http/Livewire/OpenBankingStatusRow.php`. Its
Livewire alias `openbanking.open-banking-status-row` is mounted by
`Modules/Mobile/Resources/views/livewire/sync-screen.blade.php`, and that
mount is the module's only cross-module surface. It is pinned in
`pinnedCrossModuleLivewireMounts`.

Everything else this module owns is `Internal\` and importing it from
another module is refused by `BoundaryRule` — including
`RemoteSourceAdapter`, `FetchWindow`, `OpenBankingCredentials`,
`OpenBankingConnectionView`, `OpenBankingConsentFailed`,
`OpenBankingSecretsRepository`, `OpenBankingFetchService` and
`OpenBankingConnectionQuery`, each of which reads like a public name and is
not one. A neighbour that needs any of them needs a new `Public/` contract
instead.

`RemoteSourceAdapter` is worth one note even so: it is the remote-fetch
analog of the local-file `SourceAdapter` contract and deliberately has no
`statementMetadata()` method, since a balance reading is a point-in-time
value, not an opening/closing pair.
