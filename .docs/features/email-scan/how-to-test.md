# `EmailScan` — how to test

Practical recipes for exercising the `EmailScan` module in isolation.

## Unit tests

- **Location:** `Modules/EmailScan/tests/Unit/`
- **What they test:**
  - The state machine + invalid-transition exception
    (`InboxScanStateMachineTest`).
  - The CSRF state-mismatch path (`OAuth/StateMismatchTest`).
  - The loopback redirect URI composition
    (`LoopbackRedirectUriTest`).
  - The MIME header parser + `SafeMessage` field coercion
    (`MimeHeaderParserTest`, `SafeMessageTest`).
  - The `OAuthSecret` model's encrypted casts
    (`Models/OAuthSecretTest`).
  - The `OAuthSecretsRepository` happy + safe-write-failure paths
    (`Services/OAuthSecretsRepositoryTest`).
  - The `EmlBlobStore` `chmod 0600` chain
    (`EmlBlobStoreChmodChainTest`, `EmlBlobStoreTest`).
  - The `InboxMessageQuery` paging shape
    (`Services/InboxMessageQueryTest`).
  - The Graph SSRF refusal (`GraphApiClientSsrfTest`).
  - The DTOs (`Dto/ScanCursorTest`).
  - The backfill-window validation
    (`Http/BackfillWindowValidationTest`).
  - The re-consent alert listener
    (`RaiseReconsentAlertOnTokenFailureTest`).

## Feature tests

- **Location:** `Modules/EmailScan/tests/Feature/`
- **What they test:**
  - The OAuth-connect controller's redirect (`OAuthConnectControllerTest`).
  - The OAuth-callback controller for Gmail + Microsoft
    (`OAuthCallbackGmailTest`, `OAuthCallbackMicrosoftTest`).
  - The OAuth-client wizard happy + failure paths
    (`OAuthClientWizardModalTest`,
    `OAuthClientWizardModalMicrosoftTest`,
    `OAuthClientWizardSecretsWriteFailedTest`).
  - The `/inboxes` page in empty + populated states
    (`InboxesEmptyStateTest`, `InboxesPageOpenWizardTest`,
    `InboxesPageReconnectParamTest`).
  - The health-tile renders (`EmailScanHealthTileTest`,
    `InboxesHealthBadgeRenderTest`).
  - The cross-user isolation (`CrossUserInboxIsolationTest`,
    `OAuthSecretsCrossUserTest`).
  - The discovered-senders panel
    (`DiscoveredSendersPanelTest`).
  - The legacy OAuth migration (`OAuthLegacyMigrationTest`).
  - The backfill modal + progress polling
    (`BackfillWindowModalTest`, `BackfillProgressPollTest`).
  - The Scan-Now action (`ScanNowActionTest`).
  - The invalid-grant toast (`InvalidGrantToastTest`).
  - The top-nav badge composer (`TopNavBadgeViaComposerTest`).

## Integration tests

- **Location:** `Modules/EmailScan/tests/Integration/`
- **What they test:** the job pipeline end-to-end with a Fake
  client bound, exercising cursor expiry, rate-limit backoff,
  pagination, two-phase scan (Graph), chunked backfill, concurrent
  backfill safety, baseline anchor, re-fetch idempotency,
  incremental skip, orphan `.eml` cleanup, the `JobFailed`
  listener, and the migration suite as a whole.

## Contract / arch invariants

- `noInboxScanStateWritesOutsideMachine` — only
  `Internal\InboxScanStateMachine` may write
  `inbox_scan_state.status`.
- `noOAuthSecretsWritesOutsideRepository` — only
  `Public\Services\OAuthSecretsRepository` may write
  `oauth_secrets`.
- `noImapImportsAnywhere` — no class anywhere in `Modules/` may
  import a `webklex/php-imap` or PHP-ext-imap symbol. The path is
  blocked at the dependency layer; the arch test is the secondary
  guard.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/EmailScan/tests

# Just the OAuth + state-machine units
vendor/bin/pest Modules/EmailScan/tests/Unit --filter "OAuth|State"

# Just the integration suite (slowest)
vendor/bin/pest Modules/EmailScan/tests/Integration

# Stop on first failure
vendor/bin/pest Modules/EmailScan/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **An inbox stuck in `error` after a transient failure** — the
  state machine moves `error → idle` on retry (the next scheduled
  scan does the transition before fetching). If the inbox never
  transitions, walk the `IncrementalScanJob::handle()` entry path;
  the most common cause is a fresh `ReconsentRequiredException`
  on every refresh (the user needs to Reconnect).
- **`InboxTokenFailed` event firing repeatedly** — the listener's
  de-dup guard is supposed to coalesce; check
  `system_alerts` for an existing unacknowledged
  `oauth_reconsent_required` row for the inbox; a missing row
  means the listener thinks the alert was acknowledged when it
  wasn't.
- **A `.eml` blob present on disk but no `inbox_messages` row** —
  the orphan cleanup pass reaps these; if the blob persists, the
  cleanup did not run or the row was deleted outside the cleanup's
  knowledge (e.g. raw SQL via dev console).
- **`tests/Integration/ConcurrentBackfillTest` flaky** — the
  uniqueness lock uses the `LockStore::forUniqueJobs` cache store;
  flakes are typically a stale cache row from a previous test.
  Confirm `RefreshDatabase` clears the cache too (it does by
  default in this project's TestCase).
- **OAuth redirect lands on the wrong port** — the
  `OAUTH_LOOPBACK_PORT` env var was overridden in one place but
  not the other (connect vs callback). Both must read through
  `LoopbackRedirectUri::compose()`.
- **`OAuthSecret` plaintext visible in a log line** — the
  `OAuthScrubSet` from [`DevMode`](../dev-mode/specs.md) busts on
  every save; if a recently-rotated secret leaks, the scrub set
  did not bust (the Eloquent observer in `DevMode` is the
  invalidation path; confirm it is registered).
