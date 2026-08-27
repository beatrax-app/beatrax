# `EmailScan` — code

The file-level map for the module.

## Directory layout

```
Modules/EmailScan/
├── Public/
│   ├── Actions/
│   │   ├── PromoteDiscoveredSender.php
│   │   └── DismissDiscoveredSender.php
│   ├── Dto/
│   │   ├── DiscoveredSenderDto.php
│   │   ├── EmailScanHealthTile.php
│   │   ├── InboxCredentials.php
│   │   ├── InboxHealthDto.php
│   │   ├── InboxHealthLine.php
│   │   ├── InboxMessageDto.php
│   │   ├── KnownSenderDto.php
│   │   └── ScanCursor.php
│   ├── Events/
│   │   └── InboxTokenFailed.php
│   └── Services/
│       ├── DiscoveredSenderQuery.php
│       ├── EmlBlobStore.php
│       ├── InboxMessageQuery.php
│       ├── InboxQuery.php
│       ├── InboxesBadgeCount.php
│       ├── KnownSenderQuery.php
│       ├── OAuthSecretsRepository.php
│       └── SecretsWriteFailed.php
├── Internal/
│   ├── OAuth/
│   │   ├── GoogleOAuthProvider.php
│   │   ├── MicrosoftOAuthProvider.php
│   │   ├── OAuthStateRepository.php
│   │   ├── AccessTokenWithEmail.php
│   │   ├── InvalidGrantException.php
│   │   ├── InvalidStateException.php
│   │   ├── ReconsentRequiredException.php
│   │   └── OAuthExchangeFailed.php
│   ├── Clients/
│   │   ├── GmailApiClient.php
│   │   ├── GmailApiClientContract.php
│   │   ├── GraphApiClient.php
│   │   ├── GraphApiClientContract.php
│   │   ├── FakeGmailApiClient.php
│   │   ├── FakeGraphApiClient.php
│   │   ├── CursorExpiredException.php
│   │   ├── MessageUnavailableException.php
│   │   └── RateLimitedException.php
│   ├── Jobs/
│   │   ├── DiscoveryScanJob.php
│   │   ├── IncrementalScanJob.php
│   │   └── BackfillInboxJob.php
│   ├── InboxScanStateMachine.php
│   ├── InvalidStateTransitionException.php
│   ├── LoopbackRedirectUri.php
│   ├── MimeHeaderParser.php
│   ├── ParsedMessageHeaders.php
│   ├── SafeMessage.php
│   ├── Listeners/
│   │   ├── RaiseReconsentAlertOnTokenFailure.php
│   │   └── EmitOAuthReauthRequiredAlert.php
│   └── Http/
│       ├── Controllers/
│       │   ├── OAuthConnectController.php
│       │   └── OAuthCallbackController.php
│       └── Livewire/
│           ├── InboxesPage.php
│           ├── OAuthClientWizardModal.php
│           └── BackfillWindowModal.php
├── Models/
│   ├── Inbox.php
│   ├── InboxMessage.php
│   ├── InboxScanState.php
│   ├── KnownSender.php
│   ├── DiscoveredSender.php
│   └── OAuthSecret.php
├── Database/
│   ├── Migrations/
│   └── Factories/
│       └── OAuthSecretFactory.php
├── Routes/
│   └── web.php
├── Resources/views/
├── Providers/
│   └── EmailScanServiceProvider.php
└── tests/
    ├── Unit/
    ├── Feature/
    └── Integration/
```

## Public API

- **Services/**
  - `OAuthSecretsRepository::hasProviderClient($provider)`,
    `saveProviderClient($provider, $clientId, $clientSecret,
    $redirectUri)`, `loadProviderClient($provider)`,
    `loadInbox($inboxId): ?InboxCredentials`,
    `saveInboxRefreshToken(...)`, `rotateRefreshToken(...)`,
    `removeInbox($inboxId)`. Single sanctioned read/write path for
    `oauth_secrets`, which is an Eloquent-backed table — not the
    `.bak` + `.new` + atomic-rename JSON file it started life as.
    Takes no `$userId`: the reader comes from `CurrentUser`. Throws
    `SecretsWriteFailed` when the save fails.
  - `EmlBlobStore::put($messageId, $bytes)`, `pathFor($messageId)`,
    `exists($messageId)`, `delete($messageId)`. Per-message blob
    persistence under the user-data path. Writes `chmod 0600`.
  - `InboxQuery::forCurrentUser($user)`, `findForUser($inboxId,
    $user)`, `reviewBadgeCount($user)` — read-side queries returning
    `InboxHealthDto`s.
  - `InboxMessageQuery::forStatus($status)` — a `Generator` over the
    messages in one `InboxMessageStatus`; throws
    `InvalidArgumentException` on a status outside the enum.
  - `KnownSenderQuery::all($user)` — the allow-list.
  - `DiscoveredSenderQuery::candidatesForUser($user, $minOccurrences,
    $withinDays)` — the panel data.
  - `InboxesBadgeCount::forCurrentUser($user)` — single COUNT for
    the sidebar badge.
- **Actions/**
  - `PromoteDiscoveredSender::__invoke($senderId, $user)`,
    `DismissDiscoveredSender::__invoke($senderId, $user)`.
- **DTOs/**
  - `InboxCredentials` — typed OAuth credentials.
  - `ScanCursor` — per-inbox typed cursor.
  - `InboxMessageDto` / `KnownSenderDto` /
    `DiscoveredSenderDto` / `InboxHealthDto` / `InboxHealthLine` /
    `EmailScanHealthTile`.
- **Events/**
  - `InboxTokenFailed` — `(inboxId, userId, reason)`.
- `LoopbackRedirectUri::forProvider($provider, $scheme = 'http')` —
  sits at the root of `Public/`, not in a subdirectory. Composes the
  OAuth redirect URI
  (`<scheme>://127.0.0.1:<port>/oauth/callback/<provider>`). The
  loopback port is configurable via the `OAUTH_LOOPBACK_PORT` env
  var; unset, it falls back to the port in `app.url` when that host
  is `127.0.0.1` or `localhost`, and to `8000` otherwise.

## Internal services

- `Internal/OAuth/GoogleOAuthProvider` /
  `MicrosoftOAuthProvider` — concrete OAuth handshake +
  refresh-token flow. Each throws the typed exceptions on the
  documented failure modes.
- `Internal/OAuth/OAuthStateRepository::issueState($provider,
  $userId, $existingInboxId)` / `consumeState($provider,
  $candidateState, $currentUserId): ?int` — session-backed CSRF state
  for the OAuth redirect. `consume` `pull()`s, so a state is
  single-use, and one older than `MAX_AGE_SECONDS` (600) is refused.
  The same store
  holds the PKCE verifier (`storePkceVerifier` /
  `consumePkceVerifier`) and the client-wizard success handoff
  (`issueClientWizardSuccess`).
- `Internal/Clients/GmailApiClient` /
  `Internal/Clients/GraphApiClient` — concrete API clients. Both
  implement their respective `*Contract` so tests rebind to
  `FakeGmailApiClient` / `FakeGraphApiClient` via
  `$this->app->instance(...)`.
- `Internal/InboxScanStateMachine::transition($state, $next,
  $message)` — SOLE sanctioned mutator of
  `inbox_scan_state.status`. The arch invariant
  `noInboxScanStateWritesOutsideMachine` blocks any other writer.
- `Internal/Jobs/DiscoveryScanJob::handle()` — initial sender
  discovery; writes `discovered_senders`.
- `Internal/Jobs/IncrementalScanJob::handle()` — on-cadence pull
  since the cursor. `ShouldBeUniqueUntilProcessing` keyed on
  inbox id. Defines its own `failed(Throwable,
  InboxScanStateMachine)` so the state machine flips the row to
  `error` on final-retry exhaustion.
- `Internal/Jobs/BackfillInboxJob::handle()` — user-triggered
  historical pull, chunked. Same `failed(...)` hook.
- `Internal/Listeners/RaiseReconsentAlertOnTokenFailure::handle($event)`
  — writes a single de-duped `system_alerts` row per inbox.
- `Internal/Listeners/EmitOAuthReauthRequiredAlert::handle()` —
  per-request belt-and-braces; runs from the sidebar composer.
- `Internal/MimeHeaderParser`, `Internal/ParsedMessageHeaders`,
  `Internal/SafeMessage` — parsing utilities used by the
  receipt-handing-off path. `SafeMessage` ensures untrusted MIME
  fields are coerced to safe strings before reaching domain code.

## Models + migrations

- `Models/Inbox` — `(user_id, provider, email_address, ...)`.
  Uses `BelongsToUser`.
- `Models/InboxScanState` — `(inbox_id, status, last_cursor, ...)`.
  Status enforced by paired triggers; state machine is the sole
  mutator.
- `Models/InboxMessage` — `(inbox_id, provider_message_id,
  received_at, sender, subject, ...)`.
- `Models/KnownSender` — the per-user allow-list.
- `Models/DiscoveredSender` — sender candidates pending the user's
  promote / dismiss decision.
- `Models/OAuthSecret` — `(user_id, provider, client_secret,
  tokens_blob, ...)`. `client_secret` and `tokens_blob` cast as
  `encrypted`.

Migrations:

- `2026_05_16_020001_create_inboxes_table.php`
- `2026_05_16_020002_create_inbox_scan_state_table.php`
- `2026_05_16_020003_create_inbox_messages_table.php`
- `2026_05_16_020004_create_known_senders_table.php`
- `2026_05_16_020005_create_discovered_senders_table.php`

The `oauth_secrets` table itself is owned by [`Auth`'s migration](../auth/code.md)
(`2026_05_19_000005_create_oauth_secrets_table.php`) — the table
predates this module's split-out and the migration sits in `Auth`
historically.

## Provider wiring

`EmailScanServiceProvider::register()`:

- Singletons every Public service + every Internal collaborator
  used by the job pipeline.
- Binds `GmailApiClientContract` → `GmailApiClient` and
  `GraphApiClientContract` → `GraphApiClient` so tests can rebind
  to fakes via `$this->app->instance(...)`.

`EmailScanServiceProvider::boot()`:

- Subscribes `RaiseReconsentAlertOnTokenFailure` to
  `InboxTokenFailed`.
- Loads migrations, routes, views (file-/dir-existence guarded).
- Registers three Livewire components under the `email-scan.*`
  namespace.
- Registers the sidebar badge composer via the ViewFactory
  contract (no `view()` helper). The composer also invokes
  `EmitOAuthReauthRequiredAlert::handle()` per render as a
  belt-and-braces seam for the per-request re-consent prompt.
