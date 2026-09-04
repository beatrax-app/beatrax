# `EmailScan` — architecture

The `EmailScan` module connects a user's Gmail or Microsoft 365 inbox
via OAuth, fetches receipt-shaped messages on a per-inbox cursor,
stores the raw `.eml` blobs for the receipt-matcher pipeline, runs the
incremental scan + the deeper backfill scan as queued jobs, and surfaces
the inbox health (last-seen, error states, OAuth re-consent prompts)
on the `/inboxes` page.

## What this module is for

A finance dashboard's accuracy depends on more than statements:
receipts carry the per-line breakdown (Google Play game vs in-app
purchase; PayPal merchant memo vs SEPA descriptor; ICS USD amount
before FX conversion). The user's inbox is the canonical source —
every payment provider sends a receipt to a known address — and this
module is the bridge from that inbox to the rest of the app.

The privacy posture is sharp: no IMAP password lives in
the database. OAuth client secrets and tokens DO live in the
`oauth_secrets` table, encrypted at rest via Laravel's `encrypted`
cast on the `OAuthSecret` Eloquent model. Re-consent is required when
the OAuth provider invalidates the token; the user re-clicks Connect
and the same `inbox_scan_state` resumes from its cursor.

What the module explicitly does NOT do:

- It never speaks IMAP. The only paths in are Gmail API and Microsoft
  Graph (see [provider transport hardening](provider-transport-hardening.md)).
  `ext-imap` was removed from PHP 8.4 core; the project avoids it
  entirely.
- It never stores plaintext OAuth secrets. The `OAuthSecret` model's
  `client_secret` and `tokens_blob` columns carry ciphertext;
  decryption happens only at the moment the OAuth provider HTTP
  call is composed.
- It never receipt-matches. The `.eml` blobs land in
  `EmlBlobStore`; the [`Receipts`](../receipts/architecture.md)
  module owns matching them against canonical transactions.

## Module boundary

`Public/` exposes the cross-module surface:

- **Services/**
  - `OAuthSecretsRepository` — single sanctioned read/write path for
    `oauth_secrets`. Handles encryption-at-rest and the safe-write
    sequence (write `.bak`, write `.new`, atomic rename).
  - `EmlBlobStore` — per-message `.eml` blob persistence under the
    user-data path.
  - `InboxQuery`, `InboxMessageQuery`, `KnownSenderQuery`,
    `DiscoveredSenderQuery`, `InboxesBadgeCount` — read-side
    queries.
- **Actions/**
  - `PromoteDiscoveredSender::__invoke($senderId, $user)` — move a
    discovered sender into the `known_senders` allow-list. The
    `EntityMutated` sync signal is returned out of the transaction
    closure and dispatched once the commit returns, never from inside
    it: a listener firing mid-transaction reads the pre-transaction row,
    and a rollback afterwards leaves it having acted on a promotion that
    never happened (`DispatchAfterCommitArchTest`).
  - `DismissDiscoveredSender::__invoke($senderId, $user)` — hide a
    discovered sender from the panel.
- **DTOs/**
  - `InboxCredentials`, `ScanCursor`, `InboxMessageDto`,
    `InboxHealthDto`, `InboxHealthLine`, `DiscoveredSenderDto`,
    `KnownSenderDto`, `EmailScanHealthTile`.
- **Events/**
  - `InboxTokenFailed` — raised by the Gmail / Graph API clients
    when a refresh-token call fails irrecoverably. Consumed by
    `RaiseReconsentAlertOnTokenFailure` (this module's listener) to
    write a single de-duped `system_alerts` row of kind
    `oauth_reconsent_required`.

`Internal/` houses the implementation:

- **Internal/OAuth/** — `GoogleOAuthProvider`,
  `MicrosoftOAuthProvider`, `OAuthStateRepository`, the typed
  exceptions (`InvalidGrantException`, `InvalidStateException`,
  `ReconsentRequiredException`, `OAuthExchangeFailed`).
- **Internal/Clients/** — `GmailApiClient` +
  `GmailApiClientContract`, `GmailInboxResources` (the authorized
  Gmail resources the client calls through), `GraphApiClient` +
  `GraphApiClientContract`, the typed exceptions
  (`CursorExpiredException`, `RateLimitedException`), and the
  in-test `Fake*ApiClient` fakes.
- **Internal/Jobs/** — `DiscoveryScanJob` (the initial sender
  discovery), `IncrementalScanJob` (the on-cadence message pull),
  `BackfillInboxJob` (the user-triggered historical backfill), and
  `GraphDeltaWalk`, the one collaborator both scan jobs use to follow a
  `$delta` across its `@odata.nextLink` pages.
- **Internal/InboxScanStateMachine** — the SOLE sanctioned mutator
  of `inbox_scan_state.status`. Throws
  `InvalidStateTransitionException` on any illegal transition.
- **Internal/Listeners/** — `RaiseReconsentAlertOnTokenFailure`
  (writes the system_alerts row), `EmitOAuthReauthRequiredAlert`
  (per-request belt-and-braces from the sidebar composer).
- **Internal/Actions/** — `ResolveInboxToReconnect` (turns an
  `?inbox_id=` into an owned, same-provider row or a 404) and
  `ConnectInboxFromGrant` (exchanges the code, writes the inbox rows
  and the encrypted tokens, compensates a failed secret write, and
  settles the re-consent alert).
- **Internal/Http/Controllers/** — `OAuthConnectController`
  (begins the OAuth handshake; PKCE + state are owned here),
  `OAuthCallbackController` (consumes the state and the verifier, then
  turns the action's outcome into a redirect).
- **Internal/Http/Livewire/** — `InboxesPage` (`/inboxes`),
  `OAuthClientWizardModal` (Connect-an-inbox wizard),
  `BackfillWindowModal` (date-range picker for the backfill).
- **Internal/LoopbackRedirectUri** — composes the loopback OAuth
  redirect URI; the loopback port is configurable via the
  `OAUTH_LOOPBACK_PORT` env var so a user with a busy port can
  override.

## Key services + events

- `OAuthSecretsRepository` — the encrypted-at-rest credential store,
  split along the line that matters: the per-provider app
  registration (`hasProviderClient($provider)`,
  `saveProviderClient($provider, $clientId, $clientSecret,
  $redirectUri)`, `loadProviderClient($provider)`) and the per-inbox
  grant (`loadInbox($inboxId)`, `saveInboxRefreshToken(...)`,
  `rotateRefreshToken(...)`, `removeInbox($inboxId)`). Note the
  absence of a `$userId` parameter: the repository resolves the
  reader through `CurrentUser`, so a caller cannot ask for someone
  else's row by passing an id.

  This used to be a chmod-0600 JSON file written through a
  `.bak` / `.new` / atomic-rename sequence. It is not any more — it
  is an Eloquent `OAuthSecret` row in the per-user SQLite database,
  with the client secret and the refresh tokens passed through
  `SecretShield` (the OS keychain on desktop, identity elsewhere).
  `SecretsWriteFailed` survived the move and is still what a failed
  write raises, but it now wraps a DB save, not a rename; its message
  never carries the credential payload.
- `EmlBlobStore::put($messageId, $bytes)` / `pathFor($messageId)` /
  `exists(...)` / `delete(...)` — per-message `.eml` blob persistence
  under the user-data path. Files are written `chmod 0600` so only
  the running user can read.
- `InboxScanStateMachine` — single sanctioned mutator of
  `inbox_scan_state`. `applyStatus($inboxId, $newStatus,
  $errorMessage)` moves the status under a `lockForUpdate()` inside a
  transaction; `applyRateLimited($inboxId, $retryAfterSeconds)`,
  `resetRetryAttempts($inboxId)` and `backoffForAttempt($attempt)`
  own the retry schedule, and `recordCursor($inboxId, $cursor)` /
  `recordBackfillProgress($inboxId, $progress)` own the position.
- The two API clients do not share a method name, because the two
  providers do not share a pagination model. Gmail walks history ids
  (`listSenderMessages($inboxId, $senderPatterns, $pageToken,
  $windowStart)`, `currentHistoryId($inboxId)`,
  `listHistory($inboxId, $startHistoryId)`); Graph walks delta and
  next links (`listSenderMessagesPaged($inboxId, $senderPatterns,
  $windowStart, $nextLink)`, `deltaPage($inboxId, $deltaLink,
  $sinceOverride)`). Both answer `getRawMessage($inboxId,
  $providerMessageId)`. On token-refresh failure both raise
  `ReconsentRequiredException`; the calling job catches it and fires
  `InboxTokenFailed`.
- `IncrementalScanJob::handle()` — pulls since the last cursor;
  stores each message via `EmlBlobStore`; advances cursor; raises
  `InboxMessagesFetched` so `Receipts` can match.
- `BackfillInboxJob::handle()` — user-triggered historical pull
  bounded by the modal's window. Chunked so the queue worker stays
  responsive.
- `DiscoveryScanJob::handle()` — initial sender discovery; reads a
  bounded sample of recent messages, writes
  `discovered_senders` rows the user reviews on the panel.
- `RaiseReconsentAlertOnTokenFailure::handle($event)` — single
  de-duped `system_alerts` row per inbox in `oauth_reconsent_required`
  kind; the `SystemAlertsBanner` renders it with a Reconnect link.

## Data flow

The OAuth-connect handshake:

```
/inboxes → OAuthClientWizardModal
  → user picks Gmail or Microsoft 365
  → OAuthConnectController::__invoke
       → composes state + PKCE
       → OAuthStateRepository::store($state, $userId)
       → redirect to OAuth provider authorization URL
  → user grants consent
  → provider redirects to /oauth/callback?code=…&state=…
  → OAuthCallbackController::__invoke
       → OAuthStateRepository::validate($state) → InvalidStateException
       → ConnectInboxFromGrant
            → provider->exchange($code, $codeVerifier)
            → OAuthSecretsRepository::store(...) (encrypted-at-rest)
            → Inbox row created, InboxScanState row created ('idle')
       → redirect /inboxes
```

The incremental scan cycle:

```
scheduler tick (every ~15 min)
  → IncrementalScanJob (per inbox, ShouldBeUniqueUntilProcessing)
       → state machine: idle → scanning
       → OAuthSecretsRepository::retrieveFor (decrypted)
       → GmailApiClient::messagesSince($cursor)
            (refresh token if needed; ReconsentRequiredException
             on failure)
       → for each new message:
            → EmlBlobStore::put($messageId, $bytes)
            → INSERT inbox_messages
       → advance scan cursor
       → state machine: scanning → idle

on ReconsentRequiredException:
  → state machine: scanning → error
  → dispatch InboxTokenFailed
       → RaiseReconsentAlertOnTokenFailure
            → INSERT system_alerts (kind=oauth_reconsent_required)
       → user sees banner → clicks Reconnect → starts OAuth flow again
```

The backfill (user-triggered):

```
/inboxes → BackfillWindowModal
  → user picks start/end date
  → dispatch BackfillInboxJob (per inbox)
       → chunked pull from start to end
       → progress visible via wire:poll
```

## OAuth connect + callback controllers

Both routes are thin by contract
([a controller hands the work to an action](../../conventions/a-controller-hands-the-work-to-an-action.md)):
they resolve the request, hand it to an action, and turn what comes back into a
redirect. The state and the PKCE verifier are the exception and stay in the
controllers, because both are one-shot request glue — the state exists only to
be read back by the callback, the verifier only to survive between the two.

`OAuthConnectController` (`GET /oauth/connect/{provider}`) computes
the loopback redirect URI server-side from the injected config
repository, so a query-string-supplied `redirect_uri` cannot smuggle a
different value into the consent URL. It resolves the provider wrapper
through `MailOAuthProviders` keyed on the `MailProvider` enum — an
unknown `{provider}` is a 404 before anything else runs — issues a
per-flow random state via `OAuthStateRepository`, stashes the optional
existing-inbox id for the reconnect path, and redirects to the
provider's authorization URL. `ResolveInboxToReconnect` owns the
reconnect path: it resolves the existing inbox via the Public
`InboxQuery` service (scoped to the current user, enforcing the
cross-user 404 invariant inside the query) rather than a raw DB read,
and rejects a reconnect whose `inbox_id` targets a different provider
than the existing row — allowing a Gmail consent dance to complete
against a Microsoft inbox row would write a Gmail refresh token under
an inbox whose schema still claims `provider='microsoft'`, permanently
breaking the inbox on the next scan. The rejection uses the same
`NotFoundHttpException` shape as the cross-user 404 path so a provider
mismatch is not enumerable from the response.

`OAuthCallbackController` (`GET /oauth/callback/{provider}`) verifies
the CSRF state and the originating-user binding (the state carries the
`user_id` that initiated the dance; `consumeState` rejects the
callback unless the current authenticated user matches), then hands
the code and the verifier to `ConnectInboxFromGrant`. Provider errors
at the consent screen itself (e.g. user canceled) arrive via the
`error` query parameter and are handled before the state is consumed.

`ConnectInboxFromGrant` exchanges the authorization code for tokens via
the matched provider wrapper, then persists the inbox row(s) and the
chmod-600 credentials in two sequential steps: a DB transaction first
(update the existing inbox row on reconnect, or insert a new `inboxes`
+ `inbox_scan_state` row pair on first connect), then the credential
write second. A new inbox without a refresh token is rejected before
either write — Google returns no refresh token when the OAuth consent
screen is still in "Testing" status, and persisting such an inbox would
leave it permanently `needs_reauth` on the very first scan. Because the
secret write happens after the DB commit (the inbox id is only known
once `insertGetId()` returns), a failed credential write on the
new-inbox path triggers a compensating rollback that deletes both
just-inserted rows; the reconnect path instead returns a refusal, since
a prior refresh token Google already invalidated cannot be un-rotated.
On success it acknowledges any active `oauth_reconsent_required`
`system_alerts` row for the inbox (with a LIKE-based fallback for
SQLite builds without the JSON1 extension) and lifts a `needs_reauth`
scan state back to `idle` through `InboxScanStateMachine` (the sole
sanctioned mutator). That lift is the whole point of the Reconnect
button: `needs_reauth` is terminal for both scan jobs, so rotating the
secret while leaving the status alone hands the user a working grant on
a permanently dead inbox. Only that one status is lifted — a row
mid-backfill keeps its own lifecycle.

Every refusal — no code, a refused grant, a failed exchange, a missing
refresh token, an unwritable credential row — comes back as an
`InboxConnectionResult` carrying the line the reader is shown, and the
controller flashes it. The token-exchange failures (`InvalidGrantException`,
`OAuthExchangeFailed`) log the developer's sentence and hand the reader a
translated one, rather than reaching a raw exception page. On success the
redirect lands at `/inboxes` with a flash that auto-opens the backfill window
modal.

## Livewire surfaces

`BackfillWindowModal` is the Flux modal SFC for the backfill window
picker. It auto-opens once after the OAuth callback redirect via the
`backfill-window:open` Livewire event (`InboxesPage::mount()` reads
the `open_backfill_modal` session flash and dispatches the event), and
re-opens via the inline Edit link on every row in the connected-inboxes
table. The body is a single 1-12 month slider (default 3) plus a
Confirm button; submit clamps the value to `[1, 12]` defensively,
persists `backfill_window_months` on the inbox row, and dispatches
`BackfillInboxJob` through the injected Bus contract. Every read/write
against the `inboxes` row scopes to the current user; a foreign id
resolves to the same `NotFoundHttpException` as a genuinely missing
inbox. Service collaborators arrive as parameters on action methods
and `render()`, since constructor injection is banned on Livewire
components by the strict-rules plugin.

## `/inboxes` page (`InboxesPage`)

Renders the empty-state hero when the user has no connected inboxes
and the table-driven layout once at least one exists. The
Connect-Gmail and Connect-Microsoft-365 buttons share a single
`openWizard()` action that branches on `$provider`, dispatching
`oauth-client-wizard:open` so `OAuthClientWizardModal` sets its
provider property and mounts under the provider-suffixed name
(dispatching `modal-show` directly from `openWizard()` would target a
DOM name that doesn't exist for any provider other than the
default-rendered one). Action methods take collaborators as
parameters, since constructor injection is banned on Livewire
components by the strict-rules plugin.

`mount()` handles two hand-offs from the OAuth callback flow: the
single-use `open_backfill_modal`/`oauth_canceled`/`oauth_failed`
session flashes (pulled via `pull()` so a later `wire:poll` tick or
back-button revisit doesn't re-fire the modal or repaint a stale
banner; the read is guarded by `hasSession()` so a direct Livewire test
harness without a bound session still mounts), and the
`?reconnect={id}` query parameter the `SystemAlertsBanner` Reconnect
link uses — resolved via `InboxQuery::findForUser` (current-user
scoped, returns `null` for a foreign id, logged at `info` so an
operator can see attempted reconnects against a foreign/missing inbox,
but silent to the user per the 404-not-403 contract).

Every row action (`editWindow`, `scanNow`, `reconnect`,
`promoteSender`, `dismissSender`) resolves its target inbox through
`InboxQuery::findForUser` (or the action's own internal guard),
raising `NotFoundHttpException` for a foreign id so wire-payload
forgery cannot act on another user's row — the same response shape as
a genuinely missing inbox. `scanNow` additionally no-ops with a toast
when the inbox is already `backfilling`/`scanning` (the Blade also
disables the button in those states, so the no-op path is only
reachable via forged payload), and answers a `needs_reauth` inbox with
"reconnect first" rather than dispatching — the job exits on its first
status read for a revoked grant, so dispatching there reported a scan
that never ran; its dispatched `IncrementalScanJob`
carries its own `ShouldBeUnique` constraint as the authoritative
dedup. `reconnect` redirects to
`/oauth/connect/{provider}?inbox_id={id}` so `OAuthConnectController`
scopes the consent flow to the existing row, preserving its
`inbox_messages`, stored `.eml` blobs, and provider-side cursor rather
than backfilling from scratch. `acknowledgeReconnect` (listening for
`oauth-client-wizard:reconsented`) optimistically clears the user's
`oauth_reconsent_required` alert for the inbox the moment the OAuth
wizard signals a re-consent dance started — the real token refresh
happens server-side in `ConnectInboxFromGrant`, and if it fails,
`RaiseReconsentAlertOnTokenFailure` re-raises a fresh alert on the next
`IncrementalScanJob`, so the system self-heals rather than depending on
this optimistic clear being correct. `render()` skips the
discovered-senders query entirely when the user has no connected
inboxes, since the panel has nothing to attach to and the join would
always return zero rows — this saves a query on every empty-state
render and `wire:poll` tick.

`mount()` also reads `Modules\Core\Public\Services\UserDataPathService::platform()`
once into a `#[Locked] bool $onPhone`, and the Blade picks the `_phone` half of
five copy pairs from it: `intro_phone`, `connect_body_phone`,
`not_scanned_yet_phone`, `gmail_card_body_phone` and
`microsoft_card_body_phone`, plus a `phone_heading`/`phone_body` notice rendered
above the OAuth banners and the hero. `/inboxes` is in the phone sidebar and
ungated — `MobileSurfaceParityTest` renders it as a primary surface — while
every one of this module's five schedule entries sits in
`Modules\Core\Public\Scheduling\MobileBackgroundSchedule::desktopOnly()` as a
`Schedule::call()` closure, which `SchedulerManifestGenerator` drops because
`$event->command` is null. Nothing on a phone scans a mailbox on a schedule, no
inbox table is in the sync merge registry so a mailbox connected on the desktop
never appears in this list on a phone, and `DiscoveryScanJob` has exactly one
dispatcher in the tree — the dropped `email-scan.discovery` closure — so the
discovered-senders panel is unreachable there. The desktop strings are unchanged
and still say what the desktop scheduler really does; see
[a screen that keeps its promise on one platform](../../conventions/invariants-from-shipped-failures.md#a-screen-that-keeps-its-promise-on-one-of-the-platforms-it-ships-to).

`ConnectInboxFromGrant::missingRefreshTokenMessage()` branches on the same
signal. The desktop refusal explains itself by an access token that dies "within
the hour", which reads as a promise of hourly scanning; the `_phone` variants
keep the step that fixes the refusal and drop that half of the sentence.

`OAuthClientWizardModal` is the Flux modal SFC for bring-your-own
OAuth client registration. A single component renders both the Gmail
and Microsoft 365 variants; the `open()` event sets `$provider` and
`submit()` branches on it to apply provider-specific validation
(Google: `client_id` ends in `.apps.googleusercontent.com`,
`client_secret` starts with `GOCSPX-`, and `publishedConfirmed` is
required since Google's Testing-mode refresh tokens expire after 7
days; Microsoft: `client_id` is a UUID v4, `client_secret` is
non-empty, no publication-confirmation equivalent). Once the provider
is set, `open()` dispatches `modal-show` itself (rather than letting
the caller dispatch it) targeting the now-correct
`oauth-client-wizard-{provider}` name — the modal is the only surface
that knows its own provider-suffixed name at that point, since the
caller's dispatch would otherwise race ahead of the provider being set
and target a DOM name that doesn't exist yet for a non-default
provider. On valid submission, `submit()` wipes `$clientId`/
`$clientSecret` off the component into locals before the external
`saveProviderClient()` call, so a thrown `SecretsWriteFailed` cannot
leave the secret on the component instance (which would otherwise
round-trip back to the browser inside the `wire:snapshot` payload on
the next render); the wizard then redirects into the per-inbox consent
flow, threading `$reconnectInboxId` back through to
`OAuthConnectController` on the re-consent path so the existing inbox
row is preserved rather than a new one created.

## A device that schedules no scan cannot be behind one

The dashboard's "Email scan health" tile paints one dot per connected inbox,
and the amber one means *stale*: a scan was due and did not happen. That is a
diagnosis, and it needs a schedule to be a diagnosis of. On a phone there is
none — every email-scan entry is a `Schedule::call()` closure named in
`MobileBackgroundSchedule::desktopOnly()`, and `SchedulerManifestGenerator`
drops each one because `$event->command` is null — so `last_scan_at` stays
null for as long as the mailbox is connected and the dot stayed amber forever,
for the ordinary condition, with nothing the reader could do to clear it.

The state is reachable: `/inboxes` and both OAuth routes are registered under
plain `web`/`auth` with no platform gate, the Connect buttons are deliberately
live on a phone, and `inboxes` is in no sync merge rule — so a row on a phone
is one connected *there*. Only `InboxScanStateMachine` writes `last_scan_at`,
on success-shaped transitions from the two scan jobs, which nothing on a phone
dispatches without a Scan-now tap.

`Modules\EmailScan\Public\Services\InboxScanSchedule::runsOnThisDevice()` is
the seam. It answers true off-device, and on a phone it asks whether
`email-scan.incremental` is still listed in `desktopOnly()` — so a phone that
one day gains the scan retires this by moving that one line, rather than by
somebody remembering a hardcoded platform check exists.
`ThisPeriodAtAGlanceQuery::lineStatusFor()` reads it and answers
`'unscheduled'` where it is false, at any age including never. The tile draws
that neutral, beside the copy that already tells a phone reader the same thing
(`email-scan::health.not_scanned_yet_phone`, "not scanned on this phone") —
a screen explaining a state is normal while its own dot flagged it as a problem
was the contradiction this closes.

`'healthy'` still means what it says on a phone, because a Scan-now tap is a
real scan; it is only the absence of one that stops being a fault.

## `InboxScanStateMachine`

The single legal mutator of `inbox_scan_state.status`,
`inbox_scan_state.retry_attempts`, the provider cursor columns
(`last_history_id`, `last_delta_link`), and the per-inbox
`inboxes.backfill_progress` JSON column. `backfill_progress` lives on
`inboxes` but is functionally a per-inbox lifecycle signal, so every
per-inbox lifecycle write flows through this one class; a
`BoundaryArchTest` invariant (`noOtherInboxScanStateMutator`) blocks
every other write path under `Modules/EmailScan/`.

Public surface: `applyStatus()` validates the transition against
`ALLOWED_TRANSITIONS` and writes the new status + optional
`error_message` (an invalid transition raises
`InvalidStateTransitionException` and the wrapping transaction rolls
back); `last_scan_at` advances only on success transitions
(`idle`/`backfilling`/`scanning`) so `rate_limited`/`needs_reauth`/`error`
keep the prior timestamp and the UI can honestly show "stuck since X".
`applyRateLimited()` is the convenience surface the scan jobs use on a
provider rate-limit signal: transitions to `rate_limited`, increments
`retry_attempts`, and stamps an error message of the form "Retry after
Xs". `resetRetryAttempts()` zeros the counter on success transitions
so a long-lived inbox doesn't carry a stale count across recovery
cycles. `backoffForAttempt(int $attempt)` returns the per-attempt
exponential backoff from `BACKOFF_SCHEDULE` (`[60, 300, 900, 3600]`
seconds), clamping both ends. `recordCursor()` writes whichever cursor
column matches the cursor's provider; an empty `ScanCursor` is a no-op
so callers can funnel "no new cursor learned" through the same gate as
a real write, and a provider mismatch against the inbox row's own
provider raises `InvalidArgumentException` so a Gmail cursor can never
land on a Microsoft inbox row.

`recordBackfillProgress()`'s payload carries `fetched_count`,
`total_estimated` and `last_message_date` for the UI, plus
`page_cursor` and `window_months` for the walk itself: they are what a
retried `BackfillInboxJob` resumes from, and the window is part of the
key so a fresh backfill over a different range never adopts the old
range's cursor. `InboxQuery` reads only the first two and ignores the
rest.

`ALLOWED_TRANSITIONS` has a few entries that look unusual at first
glance: `idle → idle` is a re-entrant no-op so the backfill job's
"no senders configured" early-exit can re-touch the row with an
`error_message` without a sentinel transition; `backfilling →
backfilling` and `scanning → scanning` are re-entrant no-ops so a
recovery dispatch landing on a row whose previous worker died without
flipping back to idle can resume cleanly; `needs_reauth` is terminal
except for `idle` (the user clicked Reconnect) and itself (re-entrant
safe, since a second `invalid_grant` during the brief window before a
Reconnect succeeds must not throw); `error` permits every non-terminal
state so a transient infrastructure blip recovers on the next
scheduler tick without manual intervention.

Every write path opens a transaction, sets `PRAGMA busy_timeout =
5000`, and reads the row via `lockForUpdate()`. SQLite's `FOR UPDATE`
clause is a no-op (the engine has only one writer); the `busy_timeout`
pragma is the load-bearing fence — it tells SQLite to wait up to five
seconds for a competing writer before raising `SQLITE_BUSY`, so two
concurrent `IncrementalScanJob` runs that briefly contend on the row
serialise rather than fail.

## `BackfillInboxJob`

The queued chunked fetcher for one connected inbox's user-triggered
historical backfill.

**Concurrency contract:** `ShouldBeUnique` keyed on `inboxId` blocks
every second dispatch for the same inbox until the worker finishes
(not just starts) — a multi-page backfill takes minutes, and a
queue-level lock released at handle-entry would let two workers walk
the same inbox's first page in parallel, racing on
`backfill_progress` and the per-page cursor (and would also trigger
the state machine's reject on the duplicate `idle → backfilling`
re-entry). The unique-lock store resolves via the shared `LockStore`
helper from `config('cache.locks_store')`. `tries = 3` +
`backoff = [60, 300, 900]` matches the project-wide retry envelope so
the per-inbox state machine's `rate_limited`/`error` transitions ride
the same curve.

The window lower bound is `subMonthsNoOverflow`, matching
`Ledger`'s `PeriodQuery`: plain month arithmetic on a run landing on the
31st overflows into the month *after* the one the reader selected, and
the oldest days of the chosen window are then never walked.

**Provider dispatch:** Gmail walks `users.messages.list` +
`users.messages.get?format=raw`. `users.messages.list` carries no
historyId of its own, so the baseline cursor comes from a single
`users.getProfile` call issued *before* the walk — a message arriving
mid-walk then sits above the baseline and the first incremental tick
replays it, where a post-walk read would skip it permanently.
Microsoft walks `/me/messages` with an
OData `$filter` over the sender allow-list + a `receivedDateTime`
lower bound; after the walk completes, a
`/me/mailFolders/inbox/messages/delta` baseline call captures the
`@odata.deltaLink` cursor (a two-phase pattern, since Graph's delta
endpoint doesn't support a historical window filter the way the plain
messages endpoint does). That baseline goes through `GraphDeltaWalk`,
not a single `deltaPage` call: Graph puts `@odata.deltaLink` on the
LAST page only, so a baseline that paginates hands back a `nextLink`
and no cursor at all — and with no cursor written,
`runMicrosoftIncremental` returns immediately on every tick from then
on, with no Gmail-style historyId recovery to fall back to. The wall-clock anchor for that baseline call
is captured before any provider call so the post-walk
`deltaPage(null, anchor)` filter uses the pre-walk timestamp — this
closes the multi-hour-backfill race window where a message arriving
mid-walk, after the walker's cursor has paged past its position, would
otherwise be missed by both the walk's filter and a baseline whose
lower bound was captured after the walk completed. Provider-stamped
`receivedDateTime` is the canonical `internal_date` for Microsoft so a
missing in-body `Date:` header never silently lands on the wall clock;
Gmail's `users.messages.list` has no per-message `receivedDateTime`, so
its branch falls back to in-body `Date:` header parsing via the
project `Clock` (never a raw `new DateTimeImmutable('now')`, so
test-frozen time is honoured).

**Per-page walk contract.** The next cursor is read *before* the page's
emptiness is considered: both providers legitimately answer an empty
page while still handing back a link to the next one, and treating the
emptiness as "walk finished" silently dropped every message past it.
The walk resumes rather than restarts — `page_cursor` and
`fetched_count` are persisted through `recordBackfillProgress` on every
page, and a retried attempt picks up from them when
`window_months` matches, so a run interrupted at page nine does not
re-walk pages one to eight. The count is of messages *indexed*, not
rows inserted: a page a prior attempt already landed inserts nothing,
and counting inserts made the progress bar run backwards on every
retry. `MAX_WALK_PAGES` (200) is defence in depth against a provider
answering every page with another link; at two seconds a page the 280s
job timeout bites long before it, so reaching the ceiling means the
walk was not making progress. The entry `idle → backfilling`
transition is guarded the way `IncrementalScanJob` guards
`→ scanning`: a `needs_reauth` inbox is skipped rather than throwing an
`InvalidStateTransitionException` from outside the try that records
failures, where neither `transitionOnScanError` nor `failed()` could
have recorded it.

**Per-message walk** (`walkAndPersist`, shared by both providers via
closures — `fetchNextPage`, `extractMessageId`, `fetchRawEml`,
`extractInternalDate`): fetch raw bytes, parse the four RFC 822 header
values needed for the index row, write the `.eml` to disk first, then
open a small DB transaction that inserts the `inbox_messages` row with
`insertOrIgnore` (the `(inbox_id, provider_message_id)` unique
constraint makes a retry of the same id a no-op). A message already
fetched+indexed is skipped before any provider call — relevant because
the "extend window" flow re-runs a backfill overlapping the prior
window, and the cursor-expiry fallback walk can also re-walk recent
messages; refetching would burn provider quota for nothing. On a
transaction failure the catch block unlinks the `.eml` so there is
never an orphan blob without a matching index row. The already-indexed
check, the `.eml`-then-DB-transaction write and its orphan cleanup live
on a single per-run `InboxScanContext` readonly collaborator (built once
in the job's `handle`/`prepareScan` and passed to each per-message
helper), so `BackfillInboxJob` and `IncrementalScanJob` share one copy
of that logic instead of each carrying nine positional arguments through
their provider branches. Per page,
`backfill_progress` is bumped through the state machine (so
`BoundaryArchTest`'s `noOtherInboxScanStateMutator` covers it
alongside status/cursor columns) and the loop sleeps two seconds via
`Sleep::sleep` (fakeable via `Sleep::fake()`) so the provider quota
envelope isn't exhausted by a tight loop.

**Cooperative-shutdown trade-off:** `Sleep::sleep` blocks the worker for
the full two seconds without checking whether `queue:restart` has
signalled a restart (workers
honour that signal between jobs, not mid-sleep), so a long backfill
extends a restart's completion lag by up to a few minutes per inbox.
Acceptable for the single-user v1 deployment; the cleaner shape for
multi-user readiness is `release(60)` between pages instead, but that
requires reshaping the job's state-carry-across-dispatch contract (the
per-page accumulators currently live on `handle()`'s stack frame).

**Error envelope** (both provider branches): `RateLimitedException` →
transition to `rate_limited`, rethrow so the queue worker schedules the
next attempt. `InvalidGrantException` and `InboxNotConfiguredException`
→ transition to `needs_reauth` and swallow the throw. Both are
permanent: a revoked grant needs a Reconnect and an inbox with no
persisted credentials needs the wizard, and neither is something a
later attempt can reach. Rethrowing is what schedules the retry, so
only a condition a later attempt could clear may leave through it. Any
other throwable → transition to `error` (with the
first 500 chars of the message) and rethrow so the job-failed listener
can surface the failure. `failed()` (Laravel's post-retry-exhaustion
hook) applies the same terminal `error` transition; if that write
itself fails (e.g. `SQLITE_BUSY`), the failure is logged as a warning
rather than escalated, since an invalid transition here (e.g. an
already-`needs_reauth` inbox failing again) is an acceptable no-op but
a genuine write failure would otherwise leave the inbox stranded with
no UI signal.

## `DetectIcsStatementReadyJob`

Per-user metadata-only detector for the ICS "statement ready" nudge.
Mirrors `Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob`'s
per-user query shape, but its entire input surface is
`inbox_messages.sender_email`/`.subject` — it never resolves an `.eml`
path via `EmlBlobStore`, never reads message bytes, and structurally
cannot parse transaction data (enforced simply by never importing
anything body-shaped).

Deliberately status-agnostic (no `WHERE status = ...` clause):
`Modules\Receipts\Internal\Matchers\IcsReceiptMatcher::canHandle()`
already claims every `ics.nl`/`icscards.nl` sender for its own
independent, hourly receipt-parsing pass, and will likely have flipped
a co-matched row's `status` to `'unmatched'` by the time this job
runs. Gating on status would silently miss the exact rows this
detector cares about — the two jobs are independent hourly-cadence
consumers of the same table with no ordering guarantee between them,
and that co-claim is expected and harmless.

Sender-domain match is exact-equality on the domain part (never
substring), mirroring `IcsReceiptMatcher::canHandle()`'s spoofing
defence — `ics.nl.attacker.example` must not match.

No new "already nudged" bookkeeping lives on the EmailScan side: a
matching row dispatches `IcsStatementReady` on every tick this job
runs, and `Modules\Notifications\Internal\Support\NotificationWriter`'s
`insertOrIgnore` on the deterministic
`(userId, triggerType, subjectKey, occurrence='Y-m-d')` key absorbs the
repeats into a single persisted notification per statement-arrival-day
— the same idempotency seam every other trigger listener in this
codebase already relies on, so no second dedup mechanism is introduced
here.

Sender allow-list + subject pattern are read from the tunable
`email-scan.ics_statement_ready` config block: they are a best guess,
because no real ICS statement-ready email sample was ever available to
derive them from, so a config-only correction fixes them once a real
sample surfaces — no redeploy.

## `DiscoveryScanJob`

Daily per-user broad-keyword discovery scan. Walks each connected
inbox with a broad subject-keyword query (`receipt OR factuur OR
betaling OR invoice OR order OR bevestiging` — `DISCOVERY_KEYWORDS`,
mixing English + Dutch terms to match the user's likely receipt-sender
pool) minus senders already promoted to `known_senders` or explicitly
dismissed via the `/inboxes` panel. New sender metadata lands as
`discovered_senders` rows in `state='candidate'`. The
promotion-threshold cap (occurrences within a window) lives in the
panel-rendering query (`DiscoveredSenderQuery`), not here — this job
collects every observation so the UI's threshold/all-observations
toggle has a complete picture to work from.

`last_seen_at` only ever moves forward: the upsert takes
`max(existing, new)`. Graph rejects `$orderby` alongside `$search`, so
discovery results arrive unordered and an older message can follow a
newer one. Stamping it verbatim walked the value backwards out of
`DiscoveredSenderQuery`'s 90-day window, and the sender vanished from
the panel on the exact pass that took it to `MIN_OCCURRENCES`.

**Discovery-loop invariants:** no `.eml` blobs are ever persisted from
this surface — the Gmail client calls
`users.messages.get?format=metadata&metadataHeaders=['From','Date']`
and the Graph client uses `$search` with
`$select=id,from,subject,receivedDateTime`, so only header metadata
crosses the wire. Dismissed/already-added senders are excluded from
the query and defensively re-filtered client-side, so even a provider
query-syntax failure to honour the exclude list is caught before any
`discovered_senders` upsert. Per-message `sender_email` is lowercased
so case-different variants of the same address collapse to one row
via the `(user_id, inbox_id, sender_email)` unique constraint.

`DISCOVERY_MAX_PAGES = 10` bounds the per-inbox per-day walk (10 pages
× 100 candidates/page = 1000 observations) — large enough that a busy
inbox's receipt senders surface even past the first 100 newest
broad-keyword matches, bounded enough that a misbehaving provider
can't exhaust the worker's heap or burn the day's quota. Mirrors
`IncrementalScanJob`'s `FALLBACK_WALK_HARD_CAP` semantics. Earlier the
job called the discovery-candidates method exactly once and ignored
the pagination token — for any inbox whose receipt senders sat past
the first page, discovery silently failed; walking pages up to the
cap fixed this.

**Concurrency contract** (mirrors `BackfillInboxJob`/`IncrementalScanJob`):
`ShouldBeUnique` keyed on `userId` blocks every second dispatch for the
same user until the worker finishes, since a lock released at
handle-entry would let two workers race on the `discovered_senders`
upsert and silently double-count `occurrence_count`. `uniqueFor=600`
(10 minutes) matches `IncrementalScanJob` — discovery completes in
seconds-to-minutes per inbox, so the lock shouldn't linger past a
worker crash. `tries=3` + `backoff=[60,300,900]` matches the
project-wide retry envelope. The connection sets `PRAGMA busy_timeout
= 5000` once at the top of `handle()`, inheriting across every
subsequent query for its duration — the daily discovery scan runs
concurrently with the hourly incremental scan (different
`ShouldBeUnique` keys: per-user vs per-inbox), and without the pragma
a single contended write would throw mid-loop and silently abort the
per-user pass halfway through.

**Error envelope:** `RateLimitedException` on the discovery query
silently aborts the daily run (the next scheduler tick retries
tomorrow — no state-machine transition, since `discovered_senders` has
no per-inbox lifecycle column and the absence of new rows for one day
is its own signal). Any other `Throwable` is swallowed (no facade
access to `Log` in module code) and the loop continues to the next
inbox — discovery is best-effort, and a single inbox's failure must
not abort the whole per-user pass.

The exclude list combines two sources of "do not surface again": every
`known_senders` pattern (some full addresses like `paypal.com`, others
`@`-prefixed domain suffixes like `@ics.nl` — both forms pass through
the provider's exclude operator without translation), and every
`discovered_senders` row in `state='dismissed'` or `'added'` for the
user (dismissed = explicit no, added = already promoted, but the
discovered row stays for audit).

## `IncrementalScanJob`

Per-inbox hourly incremental fetcher. Walks the Gmail historyId cursor
(`provider='gmail'`) or the Microsoft Graph delta-link
(`@odata.deltaLink`, `provider='microsoft'`) from the value previously
written by `BackfillInboxJob`'s baseline phase. New messages discovered
on the walk land as `.eml` blobs on disk plus `inbox_messages` index
rows with `status='fetched'` — the same atomic .eml-then-DB-tx
ordering the backfill job uses.

**Concurrency contract** (mirrors `BackfillInboxJob`): `ShouldBeUnique`
keyed on `inboxId` blocks every second dispatch until the worker
finishes, since a lock released at handle-entry would let two workers
race on the cursor write and trigger the state machine's duplicate
`scanning` reject. `uniqueFor=600` (10 minutes) is shorter than the
30-minute backfill ceiling, since incremental scans complete in
seconds. `BackfillInboxJob` shares the same `uniqueId` derivation (the
raw inbox id), so an hourly tick landing while a backfill is in flight
collapses cleanly into the existing lock. `tries=3` +
`backoff=[60,300,900]` matches the project-wide retry envelope.

**Error envelope:** `CursorExpiredException` on the cursor-walk
endpoint triggers a date-bounded fallback walk (`listSenderMessages`/
`listSenderMessagesPaged`) capped at `last_scan_at` minus
`FALLBACK_WALK_DAYS` (7) plus a hard `FALLBACK_WALK_HARD_CAP` (500)
defensive message ceiling so a misbehaving provider can't exhaust the
heap even if the 7-day window cap somehow fails to bound the result
set. After the fallback walk the cursor is re-baselined for Microsoft
only: the Gmail walk reads `users.messages.list`, which carries no
historyId, so the stored cursor is left untouched and the next tick
re-attempts `users.history.list` against it.
Microsoft issues a fresh `deltaPage(null, $walkStartedAt)` baseline
call anchored to the pre-walk timestamp — the same pre-walk-timestamp
pattern `BackfillInboxJob` uses to close the gap-window race where
messages arriving during the fallback walk could otherwise fall
outside both the walk's filter and the new baseline's lower bound.
`RateLimitedException` flips status to `rate_limited` via
`applyRateLimited` (bumps `retry_attempts`, stamps "Retry after Xs")
and rethrows so the queue worker honours the project-wide backoff.
`InvalidGrantException` and `InboxNotConfiguredException` transition to
`needs_reauth` and are swallowed (both terminal until the user hits
Reconnect or finishes the OAuth-client wizard; rethrowing either would
spend the whole retry budget on a condition no attempt can clear). Any
other `Throwable`
transitions to `error` (first 500 chars of the message) and rethrows
so the job-failed listener can surface the failure. `failed()`
(Laravel's post-retry-exhaustion hook) applies the same terminal
`error` transition via container-resolved `InboxScanStateMachine`,
swallowing an invalid transition rather than escalating it into a hard
queue-worker error. The swallow that matters is `needs_reauth → error`,
and it is deliberate in both directions: `needs_reauth` is the *more*
actionable state — it is what raises the Reconnect banner, where
`error` only says "try again later" — so a later failure must not
degrade it. The per-job `failed()` hook replaced an earlier
`JobFailed` listener that regex-parsed the serialized job payload,
which was fragile against serializer-format changes and any future job
class whose property name happened to share a prefix with `inboxId`.

**A message the provider will not hand over does not stall the walk.**
`MessageUnavailableException` (a `users.messages.get` 404) and
`GmailRawDecodeException` are permanent for that id, so the fetch is
skipped and the batch carries on to the cursor write. Letting either
out would leave the cursor where it was, and every later tick would
read the same history, meet the same message and abort again — one
unfetchable message freezing the mailbox for good.

**Two early-exit paths skip the provider call entirely:** a
`needs_reauth` inbox exits immediately on the first status read (no
provider API call to burn refresh attempts against a known-revoked
grant), and an empty cursor transitions to idle and exits. For Gmail
that exit first adopts a `users.getProfile` baseline, so an inbox
backfilled before the baseline call existed starts delivering deltas
on the tick after next instead of staying idle forever; Microsoft's
`last_delta_link` waits for the backfill to write it.
A third case collapses the contention where a backfill is mid-flight:
the state machine rejects `backfilling → scanning`, so the job detects
that upfront via `InvalidStateTransitionException` and skips rather
than erroring — the backfill sets `last_scan_at` when it finishes.

Both provider branches skip a message already fetched+indexed before
any provider call, since the history/delta walk (and the fallback
walk) can legitimately re-surface a message a prior pass already
persisted, and refetching would burn quota for nothing (`insertOrIgnore`
would short-circuit the DB write regardless, and the atomic `.eml`
rename would just overwrite an identical file).

**Both branches apply the sender allow-list client-side, and neither
may skip it.** Graph's delta endpoint doesn't honour a from-address
`$filter` server-side the way the plain messages endpoint does, and
`users.history.list` has no sender filter at all — its records carry
only a message id. The Microsoft branch filters on the delta page's own
`from` metadata before fetching bytes. Gmail cannot: it has to pull the
raw message, parse its headers once via
`InboxScanContext::parseHeaders`, and gate on the parsed sender before
anything reaches `storeParsedMessage`. Nothing is written for a sender
outside the allow-list — no `.eml` on disk, no `inbox_messages` row —
and the cursor still advances, so a filtered-out message never stalls
the walk. Without that gate this reads "only the senders you
allow-listed" and writes the user's entire incoming mail stream to
disk, sender and subject in plaintext columns. (Both fallback walks
filter server-side via `listSenderMessages`/`listSenderMessagesPaged`
already.)

**A Graph delta spans pages.** `fetchGraphDelta` goes through
`GraphDeltaWalk`, which follows `@odata.nextLink` until the page
carrying `@odata.deltaLink` arrives. Reading only the first page lost
every message after it AND left the cursor unmoved, with `status=idle`
so the UI showed a healthy inbox while all later mail was dropped.
`GraphDeltaWalk::PAGE_CAP` (25) bounds one tick; the `nextLink` it
stops on is itself a resumable delta URL, so it is recorded as the
cursor and the next tick continues from there rather than re-walking.

## OAuth alert listeners

`EmitOAuthReauthRequiredAlert` writes a one-time "re-authorize Gmail
and Microsoft" warning to `system_alerts` after OAuth secrets moved to
per-user storage. The signal that a move happened is the presence of
the renamed rollback file `email-oauth.json.pre-phase-12.bak`; when
that file exists and the current user has no `oauth_secrets` rows yet,
the user hasn't re-authorized any provider, so a warning surfaces
once. The check is cheap to skip (absent `.bak` file returns
immediately), and a de-dup guard ensures a second invocation doesn't
add a duplicate un-acknowledged row, so it's safe to call on every
authenticated request.

`RaiseReconsentAlertOnTokenFailure` writes a single un-acknowledged
`system_alerts` row of kind `oauth_reconsent_required` whenever an
inbox's OAuth token refresh raises `InboxTokenFailed`. Dedup pattern:
at most one active (un-acknowledged) row per `(user_id, inbox_id)` —
once the user re-consents, the modal hands the alert id back to
`InboxesPage::acknowledgeReconnect`, which stamps `acknowledged_at` via
the existing `AcknowledgeSystemAlert` action; the next failure (if
any) creates a fresh row because the existence check filters on
`acknowledged_at IS NULL`. The dedup query prefers SQLite's
`json_extract` against the `metadata` column, falling back to a
LIKE-based form (anchoring the trailing boundary with both a
comma-terminated and brace-terminated needle, so `inbox_id=1` doesn't
falsely match `inbox_id=10`/`inbox_id=11`) when the extracted-column
predicate throws on an older SQLite without the JSON1 extension
compiled in. Token text never appears in the row: the metadata blob
carries only the integer `inbox_id` and the short provider string, and
the `message` column is a static "Reconnect your Gmail"/"Reconnect
your Outlook" literal chosen from the provider field.

## `MimeHeaderParser`

Thin facade over `zbateson/mail-mime-parser` that pulls the four
header values the fetcher persists into `inbox_messages` at write
time: lowercase-normalised sender email, optional display name,
optional decoded subject, and the RFC 822 Date stamp. Single surface
method, `parseHeadersWithFallbackDate()`, so the caller must resolve
the missing-Date-header fallback at the call site — production
callers pass either the provider-stamped internal date (Gmail
`internalDate`/Graph `receivedDateTime`) or an explicit
`$clock->now()->toDateTimeImmutable()` where no provider date is
available. Routing the fallback through `Clock` at the call site keeps
test-frozen time honoured and the parser itself deterministic (it
never reaches for `new DateTimeImmutable('now')`). `sender_email` is
lowercased at parse time per the project's normalisation rule (the
`+plus` strip is explicitly out of scope here); the display name and
subject are returned verbatim after zbateson's RFC 2047 decode of any
Q-encoded/B-encoded runs. Stateless and singleton-safe — the
underlying `MailMimeParser` keeps no per-call state, so one instance
serves every fetcher worker without contention.

## OAuth providers, state, and typed exceptions

`GoogleOAuthProvider` (thin wrapper over
`League\OAuth2\Client\Provider\Google`) and `MicrosoftOAuthProvider`
(thin wrapper over `TheNetworg\OAuth2\Client\Provider\Azure`) each own
three concerns: reading the per-install OAuth client id + secret out of
the chmod-600 JSON repository on every call (so the controller never
holds credentials in memory across requests); mapping the underlying
library's `IdentityProviderException` to the module's two typed
sentinels (`InvalidGrantException` for the `needs_reauth` transition,
`OAuthExchangeFailed` for everything else) without ever including the
raw token or request body in the message; and always requesting the
right scopes + consent prompt so the provider issues a refresh token on
every consent (Google: `access_type=offline` + `prompt=consent`;
Microsoft: `Mail.Read` + `offline_access` + `User.Read` against
`tenant=common`, `defaultEndPointVersion=2.0`, `prompt=consent` —
required for the always-on background scanner). Microsoft Graph refresh
tokens are single-use (every refresh rotates the token; the caller
persists the new one via `OAuthSecretsRepository::rotateRefreshToken`).
Both providers are instantiated per call rather than cached as a
constructor property, since the redirect URI is computed by the caller
and may differ across reconnect flows, and the chmod-600 read is cheap
enough (~1KB) that per-call cost isn't worth memoising. Both classes
are non-final so feature tests can substitute a stub subclass via
`$this->app->instance(...)` — the contract is enforced by the
singleton binding + constructor signature, not the `final` modifier,
the same pattern `OAuthSecretsRepository` uses for its
`performRename()` failure-injection hook. Both providers persist the scope the token response
*granted*, falling back to the full requested string only when the
response omits one. The consent screen lets a user untick a scope; a
recorded "requested" scope then leaves an inbox the app believes can
read mail, and the first scan 403s into a generic `error` rather than
the actionable `needs_reauth`. The requested fallback is the full
`gmail.readonly + userinfo.email` pair rather than just
`gmail.readonly`, so a later out-of-band revoke of `userinfo` also
surfaces as `needs_reauth`.

`MicrosoftOAuthProvider::mapIdentityProviderException` reads the error
body through the PSR-7 stream the league Azure provider actually
passes — the previous `is_array()` arm never ran. It went unnoticed
because the flat `{"error":"invalid_grant"}` body repeats the code in
the exception message; Azure's nested `{"error":{"code":…}}` shape does
not, and that one fell through to a retryable `OAuthExchangeFailed`. `MicrosoftOAuthProvider::readEmail`
reads Microsoft Graph's `/me` response, preferring `mail` and falling
back to `userPrincipalName` — for consumer Outlook.com accounts `mail`
is often null and `userPrincipalName` holds the routable address, while
work/school accounts typically have both fields match.

`OAuthStateRepository` is per-flow random OAuth state stored in the
Laravel session. `issueState()` generates a 64-character hex token (32
random bytes) under a per-provider session key; `consumeState()` pops
the value (single-use — removed regardless of match outcome) and
returns the associated inbox id only when the candidate state matches
via `hash_equals` (constant-time, avoiding the timing attack a naive
`===` would expose) *and* the stored `user_id` matches the
caller-supplied current user (closing the cross-user-attach window
that arises when the authenticated user changes between authorize and
callback — shared browser, session reuse, or a future multi-user
install) *and* the entry is younger than `MAX_AGE_SECONDS` (600 — long
enough for a typical OAuth round-trip including an MFA prompt, short
enough that a state token surviving an unusually long session can't be
replayed days later).

Typed exceptions: `InvalidGrantException` (provider returned
`invalid_grant`, caught by the state machine to transition the inbox to
`needs_reauth`), `OAuthExchangeFailed` (any other
`IdentityProviderException`), `InvalidStateException` (OAuth callback
state mismatch, mapped to HTTP 400 — the CSRF defence for
`/oauth/callback/{provider}`), and `ReconsentRequiredException`
(carries the typed `(inboxId, userId, provider)` triple so
`RaiseReconsentAlertOnTokenFailure` can write a scoped `system_alerts`
row without pulling secrets back off disk). Every one of these
exception messages carries only the provider's short error string —
never the request body, never any token payload.

## Service provider wiring

`EmailScanServiceProvider::register()` declares singleton bindings for
the Public read services (`InboxQuery`, `KnownSenderQuery`,
`InboxMessageQuery`, `InboxesBadgeCount`, `OAuthSecretsRepository`) and
the Internal OAuth surface (`GoogleOAuthProvider`,
`MicrosoftOAuthProvider`, `OAuthStateRepository`) — all collaborators
are stateless and singleton-safe. `boot()` conditionally loads
migrations/routes/views, registers the `/inboxes` Livewire SFC + the
OAuth-client wizard modal SFC + the backfill-window modal SFC, and
wires the nav badge View Factory composer.

**Failed-job lifecycle:** `BackfillInboxJob` and `IncrementalScanJob`
each define their own `failed(Throwable, InboxScanStateMachine)`
method; Laravel resolves `InboxScanStateMachine` via container DI and
the job flips its own `inbox_scan_state.status` to `error` with the
truncated exception message. The state machine remains the sole
mutator of the status column (`BoundaryArchTest` invariant). Per-job
`failed()` hooks tie failure handling to the typed job class itself,
keeping the lookup independent of Laravel's serialized-payload format.
The queued jobs' `uniqueVia()` callbacks resolve their lock store
through `Modules\Core\Public\Support\LockStore::forUniqueJobs()`, the
single sanctioned `Cache` facade caller (a `BoundaryArchTest` carve-out).

`registerNavBadgeComposer()` merges the "Inboxes" badge integer into
the `navCounts` array of the `shell::livewire.app-sidebar` view via the
View Factory contract (mirroring `ChainsServiceProvider`'s equivalent
composer),
resolving the contract through `$this->app->make()` so the DI-only
invariant stays visible at the call site rather than via the `view()`
global helper. The composer fires only when the view is actually
rendered (the sidebar mounts on every authenticated page); each
invocation reads `CurrentUser` per-request (never cached across
requests) and a by-reference per-boot memo collapses repeated renders
in one request down to a single `InboxesBadgeCount` query. The same
composer also runs the `EmitOAuthReauthRequiredAlert` listener, since
this is the per-request hook closest to "the user is looking at the
app", and the listener's own `.bak`-file pre-check plus de-dup guard
keep it a no-op on every request after the first.

## `IcsStatementReady` event

Dispatched by `DetectIcsStatementReadyJob` when an `inbox_messages`
row's `sender_email`/`subject` match the tunable ICS statement-ready
pattern. Metadata-only by construction: the detector's entire input
surface is `sender_email`/`subject` (plaintext columns populated at
fetch time), so this event structurally cannot carry transaction
data — none was ever read from the message body.

`internalDate` is
`Modules\Notifications\Internal\Listeners\PersistIcsStatementReady`'s
occurrence-key source (`->format('Y-m-d')`) — the statement-arrival
day, deliberately not the message id, so a bank-side resend on the
same day collapses to one notification rather than fracturing into a
second, while two distinct statements arriving on different days in
the same month each get their own nudge.

`final readonly` mirrors
`Modules\Recurring\Public\Events\PaymentReminderDue`'s minimal
constructor-only shape — EmailScan dispatches this Public event and
knows nothing about the notification store; the Notifications-side
listener imports this event, never the other way around.

## `LoopbackRedirectUri`

Single source of truth for the
`http(s)://127.0.0.1:PORT/oauth/callback/{provider}` URI the
OAuth-client wizard prints for the user to paste into Google Cloud
Console/Azure Portal, and that `OAuthConnectController` and
`ConnectInboxFromGrant` both compute server-side when issuing and
consuming the consent dance.

Promoted from `Modules\EmailScan\Internal` to `Modules\EmailScan\Public`
so `Modules\OpenBanking`'s consent dance
(`OpenBankingConnectController`/`OpenBankingCallbackController`)
consumes the same provider-agnostic port-resolution + URI-shaping
logic as a sanctioned cross-module Public dependency rather than
duplicating the port-resolution chain — it was already keyed on a bare
`{provider}` string. EmailScan's own gmail/microsoft flows are
unaffected; they keep calling `forProvider()` with the default
`$scheme` (`'http'`).

Enable Banking's Control Panel requires an HTTPS-only registered
redirect URI, even for the loopback IP — unlike Google/Microsoft, it
does not extend the RFC 8252 native-client exception to plain HTTP on
`127.0.0.1`. OpenBanking therefore calls
`forProvider('open-banking', scheme: 'https')`; the caller is
responsible for actually terminating TLS on that loopback listener (a
self-signed local certificate) — the `open-banking:serve-tls` artisan
command provides a stunnel-style TLS terminator tunnelling the HTTPS
loopback port to a plain `artisan serve` backend, since the plain dev
web server this project standardizes on doesn't itself terminate TLS.

Google/Microsoft both reject `https://*.test` redirect URIs (their
native-app spec requires the loopback IP shape); Enable Banking
additionally rejects the bare-HTTP loopback exception those two
providers do allow. The URI is therefore always shaped
`{scheme}://127.0.0.1:PORT/oauth/callback/{provider}`, never the
configured `.test` URL — the fallback chain deliberately ignores the
scheme + host from `app.url` because the redirect must always land on
the loopback IP; honouring `https://beatrax.test` verbatim would
produce a URI the gmail/microsoft providers reject.

Port resolution order: (1) `email-scan.oauth_loopback_port` config
value if set, letting the user override via the `OAUTH_LOOPBACK_PORT`
env var for `.test`/custom-port setups where `app.url` doesn't carry
the literal port the listener binds (e.g. `app.url=https://beatrax.test`
in local dev serves on 443/80, but the OAuth redirect has to land on a
separate `php artisan serve --port=8000`); (2)
`parse_url(app.url, PHP_URL_PORT)` if the host parses as `127.0.0.1`
or `localhost` (the user is running `php artisan serve` directly on
the loopback) and the port is present and positive; (3) a final
fallback of 8000, the project-wide convention.

## `DiscoveredSenderQuery`

Public read API over `discovered_senders`, scoped to the rolling
promotion window. The `/inboxes` "Discovered senders" panel reads this
surface to decide which candidates surface to the user — only those
`DiscoveryScanJob` saw at least `MIN_OCCURRENCES` (2) times within
`WITHIN_DAYS` (90) of today. A single-shot sender that turns up once
and never again deliberately stays below the threshold so the panel
never asks the user to make a call on a row that may never reappear.
`MIN_OCCURRENCES = 2` is the floor for "this is a recurring sender,
worth asking about"; `WITHIN_DAYS = 90` is a quarter of recurring
receipt traffic — long enough to catch monthly subscriptions, short
enough to age out one-off promotional senders. Both constants are
exposed as method-default parameters so a future "show all" UI toggle
can pass relaxed values without changing the query shape.

`candidatesForUser` joins to `inboxes` on both `inbox_id` and
`user_id` so a discovered row whose denormalised `user_id` somehow
disagrees with the parent inbox's `user_id` is dropped at the read
boundary — the write-side `PromoteDiscoveredSender`/
`DismissDiscoveredSender` actions already enforce the cross-user 404
invariant, and this is the read-side mirror.

## `EmlBlobStore`

Filesystem repository for per-message raw `.eml` blobs. Each blob
lives under storage `app/` at
`inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/{slug}.eml`, resolved through
`UserDataPathService` so a packaged build can retarget the storage
root. Partitioning by user + inbox + year + month keeps any one
directory tree from accumulating thousands of files (which slows
directory listings on the underlying filesystem) and lets a future
archive job tar up an entire month at a time without touching the rest.

Writes are atomic via a tmp + flock + fsync + chmod + rename sequence:
open a sibling `.tmp` file, write the raw RFC 822 bytes, fflush + fsync
(where the runtime supports it), chmod to 0600, then rename over the
canonical path. A POSIX rename is atomic, so a crash mid-write either
leaves the previous file in place or hasn't yet exposed the new one —
there's no in-between state where a partial `.eml` could be observed
by a reader. The parent directory is created on first write with mode
0700 so cohabiting OS-level users can't enumerate or read another
user's blobs. Umask is narrowed to `0077` before opening the temp file
so it's born at mode 0600 rather than the umask-0022 default of 0644 —
without this, a cohabiting OS user racing a read between `fwrite` and
the explicit `chmod` could read the message body in cleartext;
`OAuthSecretsRepository` uses the same born-narrow posture for the
same reason.

`chmodInboxChain` walks from the leaf directory upward and chmods each
level to 0700, stopping at the `storage/app/inbox/` root. Without
this, intermediate levels under `inbox/` (the per-user, per-inbox,
per-year levels) inherit the default Laravel Filesystem behaviour
(mode 0755 with a typical umask), letting a cohabiting OS user
enumerate inbox ids even though the `.eml` leaves themselves are 0600.
The `str_starts_with` scoping guard (with a trailing directory
separator on both sides of the prefix check, so a hypothetical sibling
like `storage/app/inbox-staging/` can't satisfy the match) pins the
walk to the inbox subtree so unrelated parents are never silently
narrowed; the walk also caps at 32 iterations defensively in case
`dirname()` loops on a malformed input.

`pathFor` rejects `provider_message_id`s whose characters fall outside
the URL-safe base64 + `=` + `.` + `_` + `-` set (matching the Graph
spec for both legacy ids and ImmutableId-prefixed values, and the
short hex shape Gmail returns), or that exceed 512 bytes. The slug on
disk is derived from a sha256 hash of the full id (32 hex chars) plus
a sanitised prefix of the first 40 characters, with `+`, `/`, `=`
collapsed to `-` — two distinct provider ids therefore cannot collide
on disk even on case-insensitive filesystems, while the source of
truth for the id itself stays the unique key on
`(inbox_id, provider_message_id)` in `inbox_messages`.

Public surface so the matcher consumer in the Receipts module can
resolve `.eml` paths for messages persisted by the EmailScan fetcher
without crossing the Internal namespace boundary enforced by
`App\PhpStan\Rules\BoundaryRule` and the `pinnedCrossModuleInternalImports`
arch invariant.

## `OAuthSecretsRepository`

The single dependency-injected touchpoint to the per-user OAuth
credentials store backed by the `oauth_secrets` SQLite table. Each
authenticated user owns at most one row per provider (`gmail` or
`microsoft`). A row carries the provider client credentials
(`client_id`, `client_secret`, `redirect_uri`) plus a `tokens_blob`
holding the rotation tokens for every inbox connected through that
provider, keyed by inbox id. `client_secret` and `tokens_blob` are
encrypted at rest (aes256-cbc) via the `OAuthSecret` model's
`encrypted` cast keyed on `APP_KEY` — a raw column read returns
ciphertext only. Every read filters by the current user's id and every
write stamps it, so two users sharing the SQLite file never see each
other's credentials; the current user's id is resolved fresh on every
call (never cached) so a guard swap is honoured immediately. Writes go
through Eloquent saves, which are transactional and replace the
encrypted blob in a single statement.

`saveProviderClient` and `encodeInboxes` additionally pass the secret
through `SecretShield::protect()` before the model's own `APP_KEY`
column encryption — a keychain-style shield layer that is identity on
web/mobile and on desktop for legacy unshielded rows (`reveal()`
returns the input unchanged when it isn't ciphertext), and only
actually shields on the desktop bundle. `encodeInboxes` applies the
shield uniformly across all three write paths
(`saveInboxRefreshToken`, `rotateRefreshToken`, `removeInbox`);
`decodeInboxes` reveals on the way back.

`saveInboxRefreshToken` removes any stale copy of the inbox under a
different provider and writes the fresh entry inside one transaction,
so a re-provider'd inbox can never momentarily exist under two
providers or vanish entirely. `persist()` wraps every Eloquent save
in a typed `SecretsWriteFailed` on any DB-layer failure, so callers
have one write-failure contract whose message never carries the
credential payload. `decodeInboxes`'s `tokens_blob` is a JSON object
keyed by inbox id; PHP coerces those numeric-string keys to int array
keys, so the declared key type is `array-key` (`int|string`), matching
`encodeInboxes()`.

## Demo seeding

`DemoEmailScanSeeder` materialises a complete dataset for the primary
demo user so every visible surface has data on a fresh demo install:
2 `inboxes` rows (one Gmail, one Microsoft 365), 2 `inbox_scan_state`
rows with realistic resume cursors and `last_scan_at` stamped, 2
`oauth_secrets` rows (one per provider), 2 `known_senders` rows
stacking on the migration-seeded global rows, 3 `discovered_senders`
rows spanning candidate/added/dismissed states, and 3 `inbox_messages`
rows so the message feed renders.

Every row keys on an application-visible UNIQUE so re-running the
seeder upserts rather than duplicates: inboxes on `(user_id, email)`,
scan state on `(inbox_id, folder)`, known senders on
`(user_id, email_pattern)`, discovered senders on
`(user_id, inbox_id, sender_email)`, inbox messages on
`(inbox_id, provider_message_id)`, and OAuth secrets on
`(user_id, provider)`.

The seeded OAuth secret rows carry plaintext placeholders that flow
through the `encrypted` cast at write time and round-trip cleanly —
real credentials never land in a demo seed; the contributor inspecting
the surface sees the per-provider connection shape, not a usable
secret.

## ICS statement-ready sender seeding

`IcsStatementSenderSeeder` seeds the system `known_senders` row(s)
that let `IncrementalScanJob` actually fetch the ICS "statement ready"
email in the first place. `known_senders` already carries a system row
for `@ics.nl`, seeded by that table's own creation migration for the
ICS *receipts* sender — but not for `icscards.nl`, the second domain
`Modules\Receipts\Internal\Matchers\IcsReceiptMatcher::ICS_DOMAINS`
already claims for receipt parsing. Without a `known_senders` row for
whichever domain the statement-ready email actually arrives from, the
message never lands in `inbox_messages` and `DetectIcsStatementReadyJob`
has nothing to detect.

The seeder reads the sender allow-list from the same tunable config
block the detector job reads
(`email-scan.ics_statement_ready.sender_domains`), so a config-only
correction also updates which sender the primary fetch filter watches
for — one source of truth, not two that can drift.

## API client contracts

`GmailApiClientContract` and `GraphApiClientContract` are the seam
between the module's jobs (discovery, incremental scan, backfill) and
the live provider APIs. The production `GmailApiClient` /
`GraphApiClient` wrap live HTTP calls; `FakeGmailApiClient` /
`FakeGraphApiClient` replay synthesised JSON fixtures against the same
interface so background-job tests drive the pipeline end-to-end
without a real OAuth grant.

A fake that cannot express a shape hides every bug that lives in it.
`FakeGraphApiClient::deltaPage` used to read `delta-baseline.json` for
every call — an empty `value` with no `nextLink` — so no test anywhere
persisted a message from a Microsoft delta page, and the missing
`nextLink` walk was invisible. It now answers a baseline call
(`$deltaLink === null`) with that empty body and a cursor walk with
`delta-page-1.json`, which carries a real allow-listed message, and
`queueDeltaResponse()` queues explicit multi-page deltas. Likewise
`listSenderMessagesPaged` hard-coded `messages-page-2-empty.json` for
any non-null `nextLink`, and that fixture's empty page is always last,
so a mid-walk empty page was unproducible; `queueSenderPage()` and
`queueSenderPageRateLimit()` now express both that and an interruption
at a chosen page. `messages-get-raw-private.json` +
`eml/private/sample-private-mail.eml` supply the one thing the Gmail
fixtures never did — a sender that is *not* on the allow-list, which is
what the incremental filter has to be tested against. Both contracts share three error
sentinels: `RateLimitedException` (HTTP 429 / 403 quota errors,
`retryAfterSeconds` carries the provider-suggested back-off),
`CursorExpiredException` (Gmail 404 historyId expiry / Graph 410
`syncStateNotFound`, caller falls back to a date-bounded re-scan), and
the invariant that token payloads never appear in a thrown exception
message.

**Gmail** (`GmailApiClientContract`): `listSenderMessagesPaged` issues
`users.messages.list` with a `from:(...)` server-side filter, paged at
100 messages via `nextPageToken`; a non-null `$windowStart` adds
Gmail's `after:` operator so the cursor-expiry fallback walk can be
date-bounded to `last_scan_at - 7 days` rather than the full
allow-list history. `getRawMessage` calls
`users.messages.get?format=raw` and decodes the base64url payload.
`listHistory` calls `users.history.list` with
`historyTypes=messageAdded` and unpacks each `History` record into the
`messagesAdded[].message.id` shape `ScanMessageMapper` reads; the other
record kinds carry no id the fetcher could pull bytes for. It follows
`nextPageToken` to the end of the walk and then reports the mailbox's
current `historyId`. A walk stopped early by `HISTORY_PAGE_CAP` reports
the last record's own id instead, because the mailbox's current
historyId would carry the cursor over records the walk never read —
unless the capped walk consumed no records at all, which Gmail answers
whenever `historyTypes=messageAdded` matched nothing on a page
(`nextPageToken` present, `history` absent). There is no watermark to
resume from there, so the mailbox's own historyId is reported: without
it the cursor never moved and every later tick burned the same 25 API
calls re-reading the same pages, forever, with `status=idle`.
`listDiscoveryCandidates`
runs a broad `subject:(...)` keyword query minus the known-sender
allow-list, bounded at `MAX_DISCOVERY_QUERY_LENGTH` (1800 characters)
— the exclude list grows by one entry for every sender ever promoted
or dismissed, and past Gmail's `q=` ceiling every discovery call would
400. Dropping the overflow is safe because `DiscoveryScanJob`
re-applies the same exclude list client-side before any upsert. The
call pairs the list request with a per-message
`users.messages.get?format=metadata&metadataHeaders=['From','Date']`
fetch so the response carries sender + date without the full RFC 822
body — no `.eml` blob is ever persisted from this path. Discovered
entries are typed `array<string, mixed>` (not a fixed shape) so the
caller defensively narrows each field at the foreach boundary and a
future response-shape drift cannot crash the daily scan.

**Microsoft Graph** (`GraphApiClientContract`): mirrors the Gmail
seam so both providers rebind identically via
`$this->app->instance(...)` in tests. `listSenderMessagesPaged` issues
`GET /me/messages?$filter=(from/emailAddress/address eq 'a' or ...) and receivedDateTime ge {windowStart}&$orderby=receivedDateTime desc&$top=100&$select=id,from,subject,receivedDateTime`
on the first page and follows `@odata.nextLink` verbatim afterward.
`getRawMessage` calls `GET /me/messages/{id}/$value`, which returns
raw RFC 822 bytes directly (unlike Gmail's base64url `raw` field).

"Verbatim" is load-bearing and was once only aspirational: Guzzle's
`query` request option *replaces* the URI's own query string rather
than merging into it, and an empty array still counts as set. Every
`@odata.nextLink` / `@odata.deltaLink` call passed one, so
`$skiptoken`, `$deltatoken`, `$filter`, `$select`, `$top` and `$search`
were all stripped off the URL before it went out. The option is now
omitted entirely unless there is a composed query to send.
`deltaPage` establishes or walks the `$delta` cursor: a null
`$deltaLink` is the post-backfill baseline call, returning an empty
`value` plus the first `@odata.deltaLink` to persist into
`inbox_scan_state.last_delta_link`; the baseline's `receivedDateTime`
floor defaults to the implementation's injected Clock but accepts a
`$sinceOverride` anchor to close the multi-hour-backfill race window
(messages arriving between walk-start and baseline-establish would
otherwise be skipped by both the walk and the incremental cursor). A
non-null `$deltaLink` follows the URL verbatim (`$sinceOverride` is
ignored — the filter is already embedded); 410/`syncStateNotFound`
throws `CursorExpiredException::graph()`. `listDiscoveryCandidatesPaged`
walks `/me/messages?$search="subject:(receipt OR ...)"&$top=100&...`
via `@odata.nextLink`; Graph's `$filter` has no `contains` support
against subject, so `$search` is used instead, which is mutually
exclusive with `$orderby` (deliberately omitted to stay within Graph's
documented compatibility envelope — see
<https://learn.microsoft.com/en-us/graph/search-query-parameter>).
`$search` has no server-side from-address exclusion, so the exclude
list is applied client-side before returning the page.

**Why thin Guzzle for Graph, not the Kiota SDK?** The
`microsoft/microsoft-graph` Kiota SDK is deliberately not a dependency
here — nothing imported it, and its ~36k generated request-builder/model
files inflated the shipped bundle (and the Windows installer's
antivirus-scan time). Its entry point
(`Microsoft\Graph\GraphServiceClient`) expects a Kiota authentication
provider wrapping a `TokenRequestContext`, a two-layer abstraction
designed for delegated MSAL flows where the SDK refreshes tokens
itself. This project's OAuth surface already owns the refresh cycle
via `MicrosoftOAuthProvider::refreshAccessToken` and the chmod-600 JSON
repository, so reusing it keeps token storage in one place and avoids
the SDK's request-builder hierarchy entirely for the four read-only
endpoints `GraphApiClient` needs. Direct Guzzle gives: one HTTP
boundary to audit for `Authorization: Bearer` header leaks (catch
blocks strip the header before re-throwing), explicit control over the
OData `$filter` string and the `@odata.nextLink` walk semantics, and
explicit control over the `Retry-After` header read on 429 (the SDK
swallows it into a generic exception object).

`GraphApiClient::buildSenderFilter` doubles single quotes inside each
sender pattern per OData string-literal escaping (`o'brien` →
`o''brien`) before composing a clause like:

```
(from/emailAddress/address eq 'service@paypal.com' or
 from/emailAddress/address eq 'noreply@ics.nl') and
receivedDateTime ge 2026-02-17T00:00:00Z
```

`GraphApiClient::assertAllowedUrl` is the SSRF guard: it fires for both
the first-page URL (built from the constant base URI + an OData query)
and the `@odata.nextLink`/`@odata.deltaLink` pagination URLs (returned
verbatim by Graph and followed without reconstruction) — the
pagination case is load-bearing, since a malformed response
substituting an attacker-controlled host would otherwise see a valid
`Mail.Read` bearer token attached. Failure is a typed
`RuntimeException` so the caller's existing catch-`Throwable`
transition to `inbox_scan_state.status = 'error'` fires, which is
strictly preferable to silent token exfiltration. Future regional
clouds (`graph.microsoft.de`, `graph.microsoft.us`) are deliberately
excluded from the v1 host allow-list — adding one is a reviewed
config-flip decision, not something silently permitted here.

**Production error mapping:** both `GmailApiClient` (through
`GmailInboxResources`) and `GraphApiClient` rebuild their HTTP client
around a freshly-refreshed access token on every call
(`ensureFreshAccessToken` refreshes when the cached token is missing or
within 60 seconds of its stamped expiry), so a stale cached token never
reaches the wire. Quota-shaped provider errors become `RateLimitedException` so the
caller can transition the inbox state and let the queue worker
reschedule. For Gmail that is the legacy
`rateLimitExceeded`/`userRateLimitExceeded`/`dailyLimitExceeded`
reason the SDK unpacks into `getErrors()`, plus HTTP 429 and the newer
`error.status` shape (`RESOURCE_EXHAUSTED`/`UNAVAILABLE`) Google
returns with no `error.errors[]` array at all. For Graph it is 429,
503 and 509 — Microsoft documents all three as throttling, each
carrying `Retry-After`; mapping only 429 flipped a throttled inbox to
`error` (a red badge, not "rate limited"), never bumped
`retry_attempts`, and discarded the provider's own delay.
`users.history.list` 404 / Graph `$delta` 410 become
`CursorExpiredException`; a `users.messages.get` 404 becomes
`MessageUnavailableException`, which is the signal the incremental scan
uses to skip that id rather than stall the cursor behind it. Token payloads never appear in a thrown
exception message. The discovery surface deliberately never calls
`getRawMessage` — only the `format=metadata` / minimal-field fetch —
so no `.eml` blob is ever persisted from a discovery walk.
