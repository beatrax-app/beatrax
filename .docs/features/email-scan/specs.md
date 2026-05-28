# `EmailScan` — specs

The behavioural contract for the `EmailScan` module.

## Behavioral contracts

- **No IMAP path is supported.** Only Gmail API and Microsoft Graph.
  The project pins PHP 8.3.x partly to avoid the PHP 8.4 ext-imap
  removal; this module is the contractual reason.
- **OAuth secrets are encrypted at rest.** The
  `OAuthSecret::$client_secret` and `OAuthSecret::$tokens_blob`
  columns carry ciphertext via Laravel's `encrypted` cast; plaintext
  exists only inside the Eloquent attribute layer.
- **`OAuthSecretsRepository` is the SOLE sanctioned read/write
  path for `oauth_secrets`.** No code outside the repository may
  read or write the table; the safe-write sequence (`.bak`,
  `.new`, atomic rename) is centralised here.
  (`tests/Unit/Services/OAuthSecretsRepositoryTest.php`,
  `tests/Feature/OAuthSecretsCrossUserTest.php`)
- **`InboxScanStateMachine` is the SOLE sanctioned mutator of
  `inbox_scan_state.status`.** The arch invariant
  `noInboxScanStateWritesOutsideMachine` blocks any other writer.
  Allowed lifecycle:
  `idle → discovering → scanning → idle`; `* → error`;
  `error → idle` on retry. (`tests/Unit/InboxScanStateMachineTest.php`)
- **Per-inbox jobs never overlap.** `IncrementalScanJob`,
  `BackfillInboxJob`, and `DiscoveryScanJob` are
  `ShouldBeUniqueUntilProcessing` keyed on `inboxId`.
  (`tests/Integration/ConcurrentBackfillTest.php`)
- **A failed-token refresh transitions the inbox to `error` AND
  raises `InboxTokenFailed`.** The job's own `failed(Throwable,
  InboxScanStateMachine)` hook drives the transition; the listener
  writes a single de-duped `system_alerts` row of kind
  `oauth_reconsent_required`.
  (`tests/Unit/RaiseReconsentAlertOnTokenFailureTest.php`,
  `tests/Feature/InvalidGrantToastTest.php`)
- **The OAuth callback validates the CSRF state.** A mismatched
  `state` parameter throws `InvalidStateException`; the surface
  returns a generic error without revealing which user (if any)
  the original state belonged to.
  (`tests/Unit/OAuth/StateMismatchTest.php`)
- **Cross-user reads / writes return 404, not 403.** Every Public
  query and action filters by `(id, user_id)`; a foreign user's
  inbox / secret is invisible.
  (`tests/Feature/CrossUserInboxIsolationTest.php`,
  `tests/Feature/OAuthSecretsCrossUserTest.php`)
- **`.eml` blobs are written `chmod 0600`.** Only the running user
  can read; the chmod chain is verified in
  `tests/Unit/EmlBlobStoreChmodChainTest.php`.
- **A cursor that expires is replaced with a baseline scan.**
  `CursorExpiredException` is caught by the calling job; the next
  scan re-anchors from a deterministic point.
  (`tests/Integration/GmailCursorExpiryFallbackTest.php`,
  `tests/Integration/GraphCursorExpiryFallbackTest.php`)
- **Rate-limit responses trigger backoff.** `RateLimitedException`
  carries the suggested retry delay; the job's backoff schedule
  honours it. (`tests/Integration/GmailRateLimitBackoffTest.php`,
  `tests/Integration/GraphRateLimitBackoffTest.php`)
- **Backfills are chunked.** A user-triggered backfill spanning
  months breaks into chunks the worker can process between heartbeats.
  (`tests/Integration/BackfillChunkedJobTest.php`,
  `tests/Integration/BackfillPerInboxJobTest.php`)
- **Incremental scans skip messages already in
  `inbox_messages`.** The provider message id is the dedup key;
  re-fetching a known message is idempotent.
  (`tests/Integration/ReFetchIdempotentTest.php`,
  `tests/Integration/IncrementalSkipAlreadyFetchedTest.php`)
- **Discovery scan does not store `.eml` blobs.** Only sender
  metadata writes to `discovered_senders`; the user reviews the
  list before any deeper scan stores blobs for matching.
  (`tests/Integration/DiscoveryScanNoEmlBlobsTest.php`)
- **The Graph two-phase scan handles the documented baseline-then-
  delta cycle.** (`tests/Integration/GraphTwoPhaseScanTest.php`)
- **The Graph client refuses SSRF-shaped outbound URLs.** Any
  redirect to a non-Microsoft host is rejected.
  (`tests/Unit/GraphApiClientSsrfTest.php`)
- **The OAuth loopback redirect URI uses the configured port.**
  The `OAUTH_LOOPBACK_PORT` env var overrides the default;
  `LoopbackRedirectUri` composes the URI consistently across the
  connect + callback controllers.
  (`tests/Unit/LoopbackRedirectUriTest.php`)
- **Orphan `.eml` blobs are reaped.** When an `inbox_messages`
  row is deleted (e.g. the inbox is removed), the blob is deleted
  by the cleanup pass. (`tests/Integration/EmlOrphanCleanupTest.php`)

## Edge cases

- **A `.bak` file already exists at `oauth_secrets.json` write
  time** — the safe-write sequence handles it; tests cover the
  scenario (`tests/Feature/OAuthClientWizardSecretsWriteFailedTest.php`).
- **The OAuth callback arrives with no state row in the
  repository** — `InvalidStateException` raised; the user sees a
  generic error page.
- **A new inbox added while a scan is mid-flight** — different
  unique keys; no conflict.
- **A `discovered_senders` row promoted while a scan is reading
  it** — the promote action runs in its own transaction; the scan
  reads consistent state.
- **A worker dying with the inbox in `scanning`** — the `failed()`
  hook does not fire on a worker crash (it fires on final-retry
  exhaustion). The state stays `scanning`; an operator inspects
  via `/dev/queue` and either retries the job or transitions the
  state manually via tinker (which still goes through the
  sanctioned state-machine API).
- **Token refresh succeeds on retry after one failure** — no
  alert is raised; the listener's de-dup guard observes no
  previous open alert for the inbox.
- **A user revokes consent at the provider before re-consenting in
  the app** — the next refresh raises
  `ReconsentRequiredException`; the alert appears; the user
  re-clicks Connect.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/specs.md) — `User`, `Clock`,
    `UserDataPathService` (`.eml` blob path),
    `LockStore::forUniqueJobs`, `SystemAlert` writes.
  - [`Auth`](../auth/specs.md) — the `oauth_secrets` migration
    (table predates this module's split-out).
  - [`DevMode`](../dev-mode/specs.md) — `OAuthScrubSet` reads
    `OAuthSecret` literals to scrub them from logs / audit rows.
- **Depended on by**
  - [`Receipts`](../receipts/specs.md) — reads the `.eml` blobs
    via `EmlBlobStore` to match them against canonical
    transactions.
  - [`Core`](../core/specs.md) `SystemAlertsBanner` — renders the
    `oauth_reconsent_required` system alert.
  - The top-nav layout — reads `InboxesBadgeCount` via the badge
    composer.

## Configuration + feature flags

- `config('email-scan.OAUTH_LOOPBACK_PORT')` (via env
  `OAUTH_LOOPBACK_PORT`) — the loopback port used for the OAuth
  redirect URI. Default a fixed value; user can override.
- `OAuthSecret::$client_secret` / `$tokens_blob` — encrypted at
  rest via the `encrypted` cast.
- The incremental-scan cadence (every ~15 min) is fixed in the
  scheduler binding; no per-user cadence today.
- No per-user opt-out for the discovery scan; the user does not see
  a discovered sender until they review the panel.
