---
phase: 06-email-receipt-ingestion-infrastructure
reviewed: 2026-05-17T00:00:00Z
depth: standard
files_reviewed: 53
files_reviewed_list:
  - Modules/Core/Internal/Console/InstallCommand.php
  - Modules/Core/Internal/Http/Livewire/Dashboard.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/top-nav.blade.php
  - Modules/EmailScan/Database/Migrations/2026_05_16_020001_create_inboxes_table.php
  - Modules/EmailScan/Database/Migrations/2026_05_16_020002_create_inbox_scan_state_table.php
  - Modules/EmailScan/Database/Migrations/2026_05_16_020003_create_inbox_messages_table.php
  - Modules/EmailScan/Database/Migrations/2026_05_16_020004_create_known_senders_table.php
  - Modules/EmailScan/Database/Migrations/2026_05_16_020005_create_discovered_senders_table.php
  - Modules/EmailScan/Internal/Clients/CursorExpiredException.php
  - Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php
  - Modules/EmailScan/Internal/Clients/FakeGraphApiClient.php
  - Modules/EmailScan/Internal/Clients/GmailApiClient.php
  - Modules/EmailScan/Internal/Clients/GmailApiClientContract.php
  - Modules/EmailScan/Internal/Clients/GraphApiClient.php
  - Modules/EmailScan/Internal/Clients/GraphApiClientContract.php
  - Modules/EmailScan/Internal/Clients/RateLimitedException.php
  - Modules/EmailScan/Public/Services/EmlBlobStore.php
  - Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php
  - Modules/EmailScan/Internal/Http/Controllers/OAuthConnectController.php
  - Modules/EmailScan/Internal/Http/Livewire/BackfillWindowModal.php
  - Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php
  - Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php
  - Modules/EmailScan/Internal/InboxScanStateMachine.php
  - Modules/EmailScan/Internal/InvalidStateTransitionException.php
  - Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php
  - Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php
  - Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php
  - Modules/EmailScan/Internal/LoopbackRedirectUri.php
  - Modules/EmailScan/Internal/MimeHeaderParser.php
  - Modules/EmailScan/Internal/OAuth/AccessTokenWithEmail.php
  - Modules/EmailScan/Internal/OAuth/GoogleOAuthProvider.php
  - Modules/EmailScan/Internal/OAuth/InvalidGrantException.php
  - Modules/EmailScan/Internal/OAuth/InvalidStateException.php
  - Modules/EmailScan/Internal/OAuth/MicrosoftOAuthProvider.php
  - Modules/EmailScan/Internal/OAuth/OAuthExchangeFailed.php
  - Modules/EmailScan/Internal/OAuth/OAuthStateRepository.php
  - Modules/EmailScan/Internal/ParsedMessageHeaders.php
  - Modules/EmailScan/Internal/SafeMessage.php
  - Modules/EmailScan/Models/DiscoveredSender.php
  - Modules/EmailScan/Models/Inbox.php
  - Modules/EmailScan/Models/InboxMessage.php
  - Modules/EmailScan/Models/InboxScanState.php
  - Modules/EmailScan/Models/KnownSender.php
  - Modules/EmailScan/Providers/EmailScanServiceProvider.php
  - Modules/EmailScan/Public/Actions/DismissDiscoveredSender.php
  - Modules/EmailScan/Public/Actions/PromoteDiscoveredSender.php
  - Modules/EmailScan/Public/Dto/DiscoveredSenderDto.php
  - Modules/EmailScan/Public/Dto/EmailScanHealthTile.php
  - Modules/EmailScan/Public/Dto/InboxCredentials.php
  - Modules/EmailScan/Public/Dto/InboxHealthDto.php
  - Modules/EmailScan/Public/Dto/InboxHealthLine.php
  - Modules/EmailScan/Public/Dto/InboxMessageDto.php
  - Modules/EmailScan/Public/Dto/KnownSenderDto.php
  - Modules/EmailScan/Public/Dto/ScanCursor.php
  - Modules/EmailScan/Public/Services/DiscoveredSenderQuery.php
  - Modules/EmailScan/Public/Services/InboxMessageQuery.php
  - Modules/EmailScan/Public/Services/InboxQuery.php
  - Modules/EmailScan/Public/Services/InboxesBadgeCount.php
  - Modules/EmailScan/Public/Services/KnownSenderQuery.php
  - Modules/EmailScan/Public/Services/OAuthSecretsRepository.php
  - Modules/EmailScan/Public/Services/SecretsWriteFailed.php
  - Modules/EmailScan/Resources/views/livewire/backfill-window-modal.blade.php
  - Modules/EmailScan/Resources/views/livewire/email-scan-health-tile.blade.php
  - Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php
  - Modules/EmailScan/Resources/views/livewire/oauth-client-wizard-modal.blade.php
  - Modules/EmailScan/Routes/web.php
  - Modules/EmailScan/composer.json
  - Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php
  - bootstrap/providers.php
  - config/email-scan.php
  - deploy/launchd/com.diederik.horizon.plist
  - deploy/launchd/com.diederik.redis.plist
  - deploy/launchd/com.diederik.scheduler.plist
  - routes/console.php
findings:
  critical: 0
  warning: 0
  info: 2
  total: 2
status: clean
---

# Phase 6: Code Review Report (Iteration 3 — Final)

**Reviewed:** 2026-05-17
**Depth:** standard
**Files Reviewed:** 53 (production source only — tests excluded per config)
**Status:** clean (with two defensive-narrowing observations recorded for future iteration)

## Summary

Iteration 3 is the cap pass. The 33 findings closed across the prior two
iterations (20 in iter-1 + 13 in iter-2) all hold under fresh adversarial
re-reading; no regression was introduced by the iter-2 fix pass and no
previously-missed pre-existing defect surfaced on this read.

The iter-2 fixes were audited end-to-end against their original finding
shape:

- **CR-01 (Graph SSRF allow-list)** — `GraphApiClient::assertAllowedUrl`
  fires at the HTTP boundary in both `getJson()` (line 407) and the
  `getRawMessage()` path (line 165) before any bearer token is attached.
  The check uses `parse_url(..., PHP_URL_HOST)` with `strict: true`
  on `in_array`, an explicit `https` scheme assertion, and a constant
  allow-list of `['graph.microsoft.com']`. Attempted bypass vectors
  (userinfo-prefix `https://graph.microsoft.com@evil.com/...`,
  fragment-suffix `https://evil.com#graph.microsoft.com`, case-folded
  `GRAPH.MICROSOFT.COM`, suffix `graph.microsoft.com.evil.com`) all
  parse to a host that the allow-list rejects via exact match. The
  allow-list is a private const, not a config value — there is no
  misconfiguration vector that would silently widen it. Future regional
  clouds (`graph.microsoft.de`, `graph.microsoft.us`) are deliberately
  excluded per the docblock; that decision is reviewable in code.

- **CR-02 (Reconnect provider parity)** — `OAuthConnectController`
  (line 84) compares the URL `{provider}` against the existing inbox's
  `provider` column and throws `NotFoundHttpException` on mismatch.
  Response shape is identical to the cross-user 404 path (no leaked
  provider mismatch signal); a phishing or URL-tampering attempt
  surfaces the same generic "Inbox not found" wording. The
  enumeration concern flagged in the iter-3 brief (whether a 422 leaks
  information) does not apply — the fix uses 404, not 422.

- **WR-01 (Gmail backfill window)** — `runGmailBackfill` now threads
  the user-selected `$windowMonths` value through to
  `GmailApiClient::listSenderMessages` via the `$windowStart`
  parameter (line 306). The Gmail `after:` operator receives a unix
  timestamp (line 81 of `GmailApiClient`); the integer transport
  removes URL-encoding concerns entirely. The 1-12 month clamp lands
  before any provider call (line 198 of `BackfillInboxJob`); a crafted
  POST carrying `windowMonths=999` is clamped to 12. No month-boundary
  off-by-one — `modify('-N months')` is a Carbon-native subtraction.

- **WR-02 (Microsoft delta baseline anchor)** — The pre-walk anchor
  `$walkStartedAt` is captured on line 386 of `BackfillInboxJob`
  before any provider call. The anchor is threaded into the
  `deltaPage($inboxId, null, $walkStartedAt)` baseline (line 465) via
  the new `?DateTimeImmutable $sinceOverride` parameter on
  `GraphApiClientContract::deltaPage` (line 106), and the Fake mirrors
  the signature (line 137 of `FakeGraphApiClient`). On retry the
  anchor recomputes (different value); the cursor write is idempotent
  (the `recordCursor` surface issues an `UPDATE`); the baseline
  endpoint is itself idempotent (Graph accepts a fresh baseline at any
  time). No retry-corruption risk.

- **WR-03 (DiscoveryScanJob pagination)** — Both provider branches
  walk a `do/while` cursor loop bounded by `DISCOVERY_MAX_PAGES = 10`
  (lines 290-328 for Gmail, 332-377 for Microsoft). The hard cap
  caps a misbehaving provider at 1000 candidates per inbox per day —
  large enough to cover busy inboxes, bounded enough that an infinite-
  nextPageToken loop cannot exhaust the worker heap. The cap shape
  mirrors `IncrementalScanJob::FALLBACK_WALK_HARD_CAP` so the two
  per-inbox walks share the same defence-in-depth posture.

- **WR-04 (OAuthSecretsRepository umask)** — `umask(0077)` narrows
  immediately before `fopen` (line 300), and the `finally` block on
  line 349-354 restores the prior umask whether the try succeeds OR
  any nested operation throws. The early-exit `if ($fp === false)`
  branch (line 303-308) manually restores umask before throwing —
  belt-and-braces matching the finally-block restoration. The temp
  file is born mode 0600; the existing explicit `chmod` on line 326
  stays as belt-and-braces against umask churn from other libs.

- **WR-05 (EmlBlobStore umask + chmod chain)** — Same `umask(0077)`
  pattern lands at line 155 of `EmlBlobStore::put` with the matching
  `finally` restore at line 205. The new `chmodInboxChain` private
  helper (line 222-242) walks the directory tree from the leaf upward
  and chmods every level to 0700, but the `str_starts_with($current,
  $root)` guard plus the `is_dir` check stops the walk at the
  `storage/app/inbox` root so unrelated parents are never narrowed.
  The 32-iteration cap defends against a malformed dirname() loop.

- **WR-06 (DiscoveryScanJob busy_timeout)** — `PRAGMA busy_timeout =
  5000` is set once at handle-entry (line 185 of `DiscoveryScanJob`).
  The pragma is per-connection so every subsequent query inherits the
  timeout. The catch-all comment at lines 174-185 documents the
  rationale — without the timeout, a contended write throws
  SQLITE_BUSY mid-loop and the catch-Throwable on line 256 silently
  aborts the per-user pass.

- **WR-07 (Clock injection)** — `MimeHeaderParser::parseHeaders()`
  (the no-fallback overload) was removed; the surface is now the
  single `parseHeadersWithFallbackDate($rawEml, $fallbackDate)`
  method that forces every caller to resolve the fallback at the call
  site. `BackfillInboxJob::walkAndPersist` line 581 routes through
  `$clock->now()` when the per-provider closure returns null;
  `IncrementalScanJob::runGmailIncremental` line 354-356 does the
  same via `$clock->now()->toDateTimeImmutable()`. No production-path
  `new DateTimeImmutable('now')` remains.

- **WR-08 (SecretsWriteFailed catch)** — `OAuthClientWizardModal::submit`
  (line 132-145) catches `SecretsWriteFailed` and sets
  `$this->errorMessage` to an actionable string ("Could not save your
  OAuth client to disk — check storage/app/secrets/ permissions and
  try again."). The credential wipe before the call (lines 127-130)
  is preserved as the intentional security posture; the user re-pastes
  the secret with a clear cause in hand rather than a Livewire generic
  toast.

- **IN-02 (needs_reauth skip)** — `routes/console.php` lines 43-53
  LEFT JOIN `inbox_scan_state` on `(inbox_id, folder='INBOX')` and
  filter `whereNull('inbox_scan_state.status') OR status !=
  'needs_reauth'`. The `whereNull` branch covers the transient
  brand-new-inbox window where `OAuthCallbackController` has inserted
  the inbox but the scan-state row has not landed yet (in practice the
  callback inserts both in a single transaction — line 174-182 of the
  controller — so this is defensive). The InboxesPage Blade still
  renders the `needs_reauth` badge + Reconnect button (lines 174-178
  of `inboxes-page.blade.php`) for any inbox in that state, so a
  user is never deprived of visibility into a broken inbox; only the
  scheduler-dispatch cycle is short-circuited.

Quality posture is strong:

- Constructor DI is uniform; the only facade carve-out is
  `Cache::driver('redis')` inside the three `uniqueVia()` hooks
  (BackfillInboxJob / IncrementalScanJob / DiscoveryScanJob), which
  the BoundaryArchTest exempts.
- Every domain table carries `user_id` with `cascadeOnDelete`; the
  cross-user JOIN guard in `DiscoveredSenderQuery::candidatesForUser`
  (line 93-95) holds.
- Provider enum invariants are enforced at the migration layer via
  paired BEFORE INSERT / BEFORE UPDATE triggers on every typed string
  column (inboxes.provider, inbox_scan_state.status,
  inbox_messages.status, known_senders.source, discovered_senders.state).
- PLT-05 (no ext-imap / no webklex) is satisfied — both packages plus
  `ddeboer/imap` are pinned in the root `composer.json` conflict block.
- OAuth state binding includes CSRF + per-user id binding + 10-minute
  expiry; the consume call is single-use via `Session::pull`.

The two observations below are recorded as Info rather than Warning
because both are pre-existing minor defensive-narrowing gaps that the
iter-2 fix pass did not regress and that the production code's
single-tenant macOS deployment posture renders practically irrelevant.
They are noted here so a future multi-tenant or shared-host iteration
has a starting point.

## Info

### IN-01: `EmlBlobStore::chmodInboxChain` prefix match accepts sibling directories

**File:** `Modules/EmailScan/Public/Services/EmlBlobStore.php:230-234`
**Issue:** The chain walk uses `str_starts_with($current, $root)` to
scope the chmod operation, but `$root` is `storage_path('app/inbox')`
without a trailing path separator. If a sibling directory such as
`storage/app/inbox-staging/` ever existed alongside `storage/app/inbox/`,
the prefix check would also accept paths under the sibling. The
project does not currently create any such sibling, so this is a
defensive-narrowing observation rather than a live defect.
**Fix:** Append `DIRECTORY_SEPARATOR` to the root when comparing:

```php
$root = storage_path('app/inbox').DIRECTORY_SEPARATOR;
$current = $leafDir.DIRECTORY_SEPARATOR;
while (str_starts_with($current, $root) && is_dir(rtrim($current, DIRECTORY_SEPARATOR))) {
    @chmod(rtrim($current, DIRECTORY_SEPARATOR), self::DIR_MODE);
    // ... rest unchanged ...
}
```

---

### IN-02: `IncrementalScanJob` Microsoft fallback baseline is not anchored

**File:** `Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php:440`
**Issue:** After a `CursorExpiredException` on the Microsoft delta
endpoint, the job runs a 7-day fallback walk and then re-baselines via
`$graph->deltaPage($this->inboxId, null)` — without passing a
`$sinceOverride` anchor. Between the fallback-walk completion and the
baseline call, messages arriving in the gap window could fall outside
both the walk's filter and the baseline's lower bound, mirroring the
race window WR-02 closed for `BackfillInboxJob`. The window is much
smaller here (the fallback walk completes in seconds for the 500-
message hard cap, vs. multi-hour multi-year backfills); under realistic
arrival rates the practical loss surface is one or two messages at
most. Noted for symmetry with the WR-02 fix posture rather than as a
live correctness gap.
**Fix:** Capture `$walkStartedAt = $clock->now()->toDateTimeImmutable()`
before invoking `graphFallbackWalk`, then pass it as the
`$sinceOverride` argument:

```php
} catch (CursorExpiredException) {
    $walkStartedAt = $clock->now()->toDateTimeImmutable();
    $messages = $this->graphFallbackWalk($graph, $stateRow, $senderPatterns, $clock);
    $baseline = $graph->deltaPage($this->inboxId, null, $walkStartedAt);
    $newDeltaLink = $baseline['deltaLink'];
}
```

The contract already accepts the optional `$sinceOverride` parameter
(added during the WR-02 fix), so no contract change is needed.

---

_Reviewed: 2026-05-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
_Iteration: 3 (final cap)_
