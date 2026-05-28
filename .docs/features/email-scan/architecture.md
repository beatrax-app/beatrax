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
  Graph (see [tech stack](../../../CLAUDE.md#email-integration)).
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
    discovered sender into the `known_senders` allow-list.
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
  `GmailApiClientContract`, `GraphApiClient` +
  `GraphApiClientContract`, the typed exceptions
  (`CursorExpiredException`, `RateLimitedException`), and the
  in-test `Fake*ApiClient` fakes.
- **Internal/Jobs/** — `DiscoveryScanJob` (the initial sender
  discovery), `IncrementalScanJob` (the on-cadence message pull),
  `BackfillInboxJob` (the user-triggered historical backfill).
- **Internal/InboxScanStateMachine** — the SOLE sanctioned mutator
  of `inbox_scan_state.status`. Throws
  `InvalidStateTransitionException` on any illegal transition.
- **Internal/Listeners/** — `RaiseReconsentAlertOnTokenFailure`
  (writes the system_alerts row), `EmitOAuthReauthRequiredAlert`
  (per-request belt-and-braces from the top-nav composer).
- **Internal/Http/Controllers/** — `OAuthConnectController`
  (begins the OAuth handshake; PKCE + state are owned here),
  `OAuthCallbackController` (handles the redirect, exchanges the
  code, writes the encrypted tokens).
- **Internal/Http/Livewire/** — `InboxesPage` (`/inboxes`),
  `OAuthClientWizardModal` (Connect-an-inbox wizard),
  `BackfillWindowModal` (date-range picker for the backfill).
- **Internal/LoopbackRedirectUri** — composes the loopback OAuth
  redirect URI; the loopback port is configurable via the
  `OAUTH_LOOPBACK_PORT` env var so a user with a busy port can
  override.

## Key services + events

- `OAuthSecretsRepository::store($userId, $provider, $credentials)`
  / `retrieveFor($userId, $provider)` — the encrypted-at-rest
  read/write. Safe-write sequence: write `.bak`, write `.new`,
  atomic rename. On failure throws `SecretsWriteFailed`.
- `EmlBlobStore::put($messageId, $bytes)` / `path($messageId)` —
  per-message `.eml` blob persistence under the user-data path.
  Files are written `chmod 0600` so only the running user can read.
- `InboxScanStateMachine::transition($state, $next, $message)` —
  single sanctioned mutator of `inbox_scan_state.status`. Allowed
  transitions enforce the lifecycle:
  `idle → discovering → scanning → idle`,
  `* → error`, `error → idle` (on retry).
- `GmailApiClient::messagesSince($cursor, $credentials)` /
  `GraphApiClient::messagesSince($cursor, $credentials)` — the
  paginated fetch. On token-refresh failure raises
  `ReconsentRequiredException`; the calling job catches it and
  fires `InboxTokenFailed`.
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
