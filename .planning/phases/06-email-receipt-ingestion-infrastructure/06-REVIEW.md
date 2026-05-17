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
  critical: 2
  warning: 8
  info: 3
  total: 13
status: issues_found
---

# Phase 6: Code Review Report (Iteration 2)

**Reviewed:** 2026-05-17
**Depth:** standard
**Files Reviewed:** 53 (production source only — tests excluded per config)
**Status:** issues_found

## Summary

The iter-1 round closed 21 of the 20 prior findings cleanly: chmod-600 atomic writes, compensating rollback on secret-write failure, ShouldBeUnique single-flight on all three jobs, OAuth state CSRF + user-id binding + 10-minute expiry, EmlBlobStore SHA-256 collision-resistant slug, MimeHeaderParser SafeMessage normaliser, and OAuthClientWizardModal `wire:model.blur` on the secret input are all in place and exercised by integration tests. The state-machine matrix correctly includes the `backfilling→backfilling` and `scanning→scanning` re-entrant edges.

This iteration surfaces two **BLOCKER** defects that the iter-1 review did not catch (both pre-existed and survived the fix pass): a bearer-token-leak SSRF via unvalidated `@odata.nextLink` / `@odata.deltaLink` URL chasing in `GraphApiClient`, and a provider/inbox mismatch on the reconnect path that silently re-binds a Microsoft inbox to Gmail OAuth credentials (or vice-versa). Eight additional **WARNING** items cover defects in the Gmail backfill window clamp (the user-selected window is discarded), the Microsoft delta-baseline race window, the discovery-scan single-page truncation, and a handful of robustness gaps.

Quality posture is otherwise strong: constructor DI is consistent (the only facade carve-out is `Cache::driver('redis')` inside the three `uniqueVia()` hooks), every domain table carries `user_id` with `cascadeOnDelete`, and the cross-user JOIN guard in `DiscoveredSenderQuery::candidatesForUser` is solid defense-in-depth. PLT-05 (no ext-imap / no webklex) is satisfied — both packages are pinned in `composer.json`'s `conflict` block.

## Critical Issues

### CR-01: Bearer-token leak via unvalidated `@odata.nextLink` / `@odata.deltaLink` URL chasing

**File:** `Modules/EmailScan/Internal/Clients/GraphApiClient.php:100-106, 184-186, 245-249, 369-376`
**Issue:** `GraphApiClient::getJson()` unconditionally attaches `Authorization: Bearer {$accessToken}` to whatever URL it is handed (line 372). The caller-supplied URL on the pagination + delta-resume paths is a raw `@odata.nextLink` / `@odata.deltaLink` string returned by Graph in the prior response (lines 105, 185, 249). The implementation explicitly follows these URLs **verbatim** ("Follow the prior page's @odata.nextLink URL verbatim — Graph embeds the skip token + the original filter inside the URL").

If a Graph response is ever substituted (MITM, compromised CA, hostile proxy in transit, or — more plausibly — a server-side bug at Microsoft that returns a malformed nextLink), the next request goes to the attacker-controlled host **with a valid bearer token attached**. The attacker captures the bearer and now has Mail.Read access to the user's inbox until the access token expires (~1 hour); the refresh chain remains intact via rotation.

The `ScanCursor::microsoft()` factory has a `https://graph.microsoft.com/` prefix check, but that gate only fires when persisting the **resumed** `deltaLink` into `inbox_scan_state.last_delta_link` via `InboxScanStateMachine::recordCursor`. The raw `nextLink` between pages of one backfill walk is never persisted and never validated. Likewise, `deltaPage()` follows a stored deltaLink — but the deltaLink could be tampered en route before the cursor write happens (`runMicrosoftBackfill` calls `deltaPage(null)` to get the baseline link, then immediately writes it without re-validating the host).

**Fix:** Re-apply the `https://graph.microsoft.com/` allow-list in `GraphApiClient::getJson()` itself before issuing the request:

```php
private function getJson(
    GuzzleClient $client,
    string $accessToken,
    string $url,
    array $query = [],
    bool $expectsDelta = false,
): array {
    // Defense-in-depth: refuse to forward the bearer token to any
    // host outside the global Graph endpoint. The OData $nextLink /
    // $deltaLink contract embeds the cursor token in a graph.microsoft.com
    // URL; any deviation indicates tampering or a hostile-proxy MITM.
    $host = parse_url($url, PHP_URL_HOST);
    if ($host !== 'graph.microsoft.com') {
        throw new RuntimeException(
            'GraphApiClient: refusing to send bearer token to non-Graph host: '
            .(is_string($host) ? $host : '(unparseable)')
        );
    }
    // ... existing request() call ...
}
```

The check belongs at the HTTP boundary (not at the cursor-persist layer) because the attack surface is **every** request, not just the cursor-write moment. Mirror the same guard in `getRawMessage()` (line 146).

---

### CR-02: Reconnect path allows cross-provider rebind, leaving inbox row decoupled from credentials

**File:** `Modules/EmailScan/Internal/Http/Controllers/OAuthConnectController.php:50-83` (and `OAuthCallbackController.php:142-185`)
**Issue:** `OAuthConnectController::__invoke($request, string $provider)` accepts both a `{provider}` URL segment and an `inbox_id` query parameter. For the reconnect path it looks up the inbox via `$this->inboxQuery->findForUser($candidate, $this->currentUser->user())` (line 69) — which does NOT check that `$inbox->provider === $provider`. A crafted request to `/oauth/connect/gmail?inbox_id={microsoft_inbox_id}` therefore proceeds with `$existingInboxId = $candidate`, issues a Gmail consent dance, and the callback's UPDATE on `inboxes` (controller line 149-155) only sets `email` + `updated_at`, leaving `provider = 'microsoft'`. The chmod-600 JSON file at the same path now stores a Gmail refresh token under the inbox id whose schema row claims Microsoft.

On the next `IncrementalScanJob` tick, the handler reads `provider = 'microsoft'` from the inboxes row, dispatches into the Microsoft branch, calls `GraphApiClient::deltaPage()` which calls `MicrosoftOAuthProvider::refreshAccessToken($creds->refreshToken)` — passing a Gmail refresh token to Azure. The provider returns `invalid_grant`, the job transitions to `needs_reauth`, and the inbox is permanently stuck on the wrong provider. The user can no longer scan the inbox until they delete and re-add it.

This is reachable by a clumsy user (clicking Reconnect from the wrong place), by a phishing link, or by simple URL tampering — there is no authorization barrier requiring the URL `{provider}` to match the existing inbox's provider.

**Fix:** Validate provider parity in `OAuthConnectController` before issuing the state:

```php
if ($candidate > 0) {
    $inbox = $this->inboxQuery->findForUser($candidate, $this->currentUser->user());
    if ($inbox === null) {
        throw new NotFoundHttpException('Inbox not found.');
    }
    if ($inbox->provider !== $provider) {
        // Reconnect flow must target the same provider — silently
        // ignoring the mismatch would leave the row's provider column
        // and the persisted refresh token decoupled, breaking every
        // subsequent IncrementalScanJob.
        throw new NotFoundHttpException('Inbox not found.');
    }
    $existingInboxId = $candidate;
}
```

`NotFoundHttpException` rather than a more specific error keeps the cross-user 404 invariant uniform (a leaked provider mismatch would otherwise be enumerable). Add a feature test that asserts the response shape matches the `inbox_id` not-belonging-to-user case.

## Warnings

### WR-01: Gmail backfill ignores user-selected window — walks the full sender-allow-list history

**File:** `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php:217-230, 263-310`
**Issue:** The handler clamps `$window = max(1, min(12, $this->windowMonths))` at line 198 and passes it to `runMicrosoftBackfill()`. But `runGmailBackfill()` is called without `$window` (line 217-227) and the closure-based `fetchNextPage` on line 293-306 calls `$gmail->listSenderMessages($this->inboxId, $senderPatterns, $cursor)` with the optional `$windowStart` parameter omitted (defaulting to `null`).

When `$windowStart` is null, `GmailApiClient::listSenderMessages()` (line 75) builds a `q=from:(...)` filter with NO `after:` operator, so Gmail returns every matching message back to the dawn of the inbox. The user sets "3 months" in the backfill window picker; Gmail walks 10 years. Provider quota burns, the user's "fetched_count / total_estimated" progress strip is bewildering, and the FALLBACK_WALK_HARD_CAP=500 message ceiling in `IncrementalScanJob` does not apply here (this is the backfill path).

**Fix:** Plumb `$window` into the Gmail branch and use it to set `$windowStart` analogously to the Microsoft branch:

```php
if ($provider === 'gmail') {
    $this->runGmailBackfill(
        $connection, $clock, $gmail, $blobStore, $mime, $sm,
        $userId, $senderPatterns, $window,   // ← pass through
    );
    return;
}

// inside runGmailBackfill:
$windowStart = $clock->now()->modify("-{$windowMonths} months");
// ... in the closure ...
$page = $gmail->listSenderMessages(
    $this->inboxId, $senderPatterns, $cursor, $windowStart,
);
```

The `GmailApiClientContract::listSenderMessages` signature already has `?DateTimeImmutable $windowStart = null`, so no contract change is needed.

---

### WR-02: Microsoft delta baseline window is racy — messages arriving during the backfill walk can be permanently lost

**File:** `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php:435-446` and `GraphApiClient.php:185-196`
**Issue:** `runMicrosoftBackfill` walks `listSenderMessagesPaged` page-by-page until the nextLink terminates, then issues a SINGLE `deltaPage(null)` baseline call to capture the post-walk delta cursor. The baseline call builds `$filter=receivedDateTime ge {now}` (Graph client line 192), where `{now}` is the wall-clock time at the moment of the baseline call — AFTER the walk has finished.

For a multi-hour backfill of a year's history, messages that arrived BETWEEN the walk's filter's upper bound (no upper bound — Graph returns newest-first within the from-allow-list and `receivedDateTime ge windowStart`) and the post-walk `{now}` baseline are in a gap:

- If they arrive before the walk's first page is fetched: included in the walk (good).
- If they arrive while the walk's nextLink chain is being followed: MAY appear on a later page (Graph's skip-token cursor is point-in-time; the walk's filter is `receivedDateTime ge windowStart`, no upper bound, so newly-arrived messages CAN show up on later pages of the same walk — but they're ordered by `receivedDateTime desc`, so once the walker has paged past their position, they're missed).
- If they arrive after the walk completes but before the deltaPage(null) baseline: missed by the walk AND excluded from the baseline (the baseline's `ge {now}` lower bound is set AFTER they arrived).

The `IncrementalScanJob`'s next tick will pick up everything from the delta cursor onward, so the worst case is roughly `(walk_duration)` worth of messages from sender-allow-list addresses go unfetched until a manual reconnect or cursor-expiry triggers a fallback walk. The inbox_messages UNIQUE constraint protects against double-insertion of duplicates; it does NOT cover under-fetch.

**Fix:** Capture the baseline `{now}` BEFORE the walk begins and apply it as the lower bound for the baseline filter:

```php
private function runMicrosoftBackfill(...): void {
    $sm->applyStatus($this->inboxId, 'backfilling');
    $walkStartedAt = $clock->now();    // ← captured before any provider call
    $windowStart = $walkStartedAt->modify("-{$windowMonths} months");

    // ... walk loop unchanged ...

    // Baseline call uses walkStartedAt, not "now", as the lower bound.
    // The deltaPage signature would need a windowStart param OR Graph
    // client would need to accept an explicit start instead of clock.
    $baseline = $graph->deltaPage($this->inboxId, null, $walkStartedAt);
    // ...
}
```

Requires adding a `?DateTimeImmutable $sinceOverride = null` parameter to `GraphApiClientContract::deltaPage()` and threading it into the baseline filter clause. The Fake honours the same.

---

### WR-03: `DiscoveryScanJob` only fetches the first 100 candidates per inbox — pagination is dropped

**File:** `Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php:256-309`
**Issue:** The Gmail branch (line 258) calls `$gmail->listDiscoveryCandidates($inboxId, ...)` exactly once and ignores `nextPageToken`. The Microsoft branch (line 272) calls `$graph->listDiscoveryCandidatesPaged($inboxId, ..., nextLink: null)` once and ignores the returned `nextLink`. The Gmail client's `maxResults=100` and Graph's `$top=100` cap each surface at 100 candidates.

For a busy inbox the daily discovery scan therefore only ever surveys the first 100 newest broad-keyword matches. A sender whose receipts always sit further down the page than the latest 100 promotional emails will never make it into `discovered_senders`. The discovery scan silently fails to do its job for any inbox above the threshold.

**Fix:** Wrap each branch in a do/while page loop that walks until the cursor terminates. Add a defensive hard cap (e.g. 10 pages = 1000 candidates per inbox per day) to match `IncrementalScanJob::FALLBACK_WALK_HARD_CAP` semantics so a misbehaving provider cannot exhaust the heap.

---

### WR-04: `OAuthSecretsRepository::writeAtomic` leaves the `.tmp` file world-readable until chmod

**File:** `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php:291-314`
**Issue:** `@fopen($tmp, 'wb')` on line 291 creates the temp file with mode `0666 & ~umask`. With the default macOS umask of 022 the file is created mode `0644` — readable by other OS-level users. The full plaintext JSON payload (refresh tokens, client secrets) is written via `fwrite` on line 300; chmod to `0600` does not happen until line 314, AFTER write + flush + fsync complete.

A cohabiting user with read access who races a `cat /Users/$you/code/diederik/storage/app/secrets/email-oauth.json.tmp` during the brief window (potentially microseconds, but a slow disk + a large payload widens it) sees the refresh tokens in cleartext. On a single-user macOS box this is mostly academic; on shared dev hosts or in CI it is exposed.

The parent directory creation at line 267-271 sets `DIR_MODE = 0700`, which mitigates the risk by gating directory traversal — BUT only ONCE on first create. If the user (or a backup tool) ever widens `storage/app/secrets/` to 0750, all bets are off.

**Fix:** Open the temp file under a tighter umask so it is born mode 0600:

```php
$tmp = $absolute.'.tmp';
$prevUmask = umask(0077);
try {
    $fp = @fopen($tmp, 'wb');
    // ... rest unchanged ...
} finally {
    umask($prevUmask);
}
```

`umask(0077)` ensures every file created in this block lands as `0600 & ~0077 = 0600` — no chmod race because the file is born with the right mode. Apply the same pattern in `EmlBlobStore::put()` for symmetry.

---

### WR-05: `EmlBlobStore::put` only chmods the LEAF directory, leaving parent path traversable

**File:** `Modules/EmailScan/Public/Services/EmlBlobStore.php:130-144`
**Issue:** `ensureDirectoryExists($dir, self::DIR_MODE, recursive: true)` creates `storage/app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/` recursively. On first create the `chmod 0700` on line 139 applies ONLY to `$dir` (the leaf `{MM}` directory). Intermediate directories (`storage/app/inbox/{user_id}/`, `storage/app/inbox/{user_id}/{inbox_id}/`, `.../{YYYY}/`) inherit the default Laravel Filesystem behaviour — which on macOS with default umask 022 creates dirs with mode `0755`.

A cohabiting OS user can therefore `ls storage/app/inbox/1/` and enumerate inbox ids, then `ls storage/app/inbox/1/{guessed-inbox-id}/` and enumerate year/month, and finally try to read individual `.eml` files (which ARE chmod 0600 by the atomic-write block, so the file content is protected). But directory enumeration itself leaks the inbox structure — how many inboxes exist, when they were active, and the total fetched-message volume.

**Fix:** Walk the directory chain and chmod every level under the `storage/app/inbox/` root:

```php
$pathParts = explode('/', $dir);
$accum = '';
foreach ($pathParts as $part) {
    if ($part === '') { $accum = '/'; continue; }
    $accum = ($accum === '/' ? '/' : $accum.'/').$part;
    if (str_starts_with($accum, storage_path('app/inbox'))) {
        @chmod($accum, self::DIR_MODE);
    }
}
```

The scoping `str_starts_with` check stops the chmod walk at the `storage/app/inbox` root so the project's `storage/app/` directory itself doesn't get narrowed.

---

### WR-06: `DiscoveryScanJob` per-inbox loop has no SQLite contention guard

**File:** `Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php:204-237, 350-395`
**Issue:** The per-inbox upsert loop (line 350-395) issues `insert` and `update` queries directly against `discovered_senders` without wrapping in a `transaction(...)` block or setting `PRAGMA busy_timeout = 5000`. Every other write path in this module sets `busy_timeout` (`BackfillInboxJob`, `IncrementalScanJob`, `InboxScanStateMachine`, `OAuthCallbackController`, the two discovered-sender actions) precisely because SQLite raises `SQLITE_BUSY` instantly without it under contention.

The daily discovery scan runs concurrently with the hourly incremental scan (different `ShouldBeUnique` keys: discovery is per-user, incremental is per-inbox). If both jobs land on the same SQLite writer slot, the discovery scan will throw `SQLITE_BUSY` mid-loop, the `catch (Throwable)` on line 231 swallows it, the per-user pass aborts halfway through, and the user sees partial discovery results.

**Fix:** Set the pragma once at the top of `handle()` so every subsequent query on the same connection inherits the timeout:

```php
public function handle(...): void {
    // ... existing arg-touching ...
    $connection = $db->connection();
    $connection->statement('PRAGMA busy_timeout = 5000');
    // ... rest unchanged ...
}
```

The pragma is per-connection so setting it once at the handler entry covers every subsequent query on the same connection.

---

### WR-07: `MimeHeaderParser::parseHeaders` uses `new DateTimeImmutable('now')` instead of the project Clock contract

**File:** `Modules/EmailScan/Internal/MimeHeaderParser.php:47-49`
**Issue:** `parseHeaders($rawEml)` is the no-fallback overload that defaults `internalDate` to `new DateTimeImmutable('now')` — bypassing the project's `Clock` contract. Tests that fake time via the Clock contract (the established project pattern) will see the wall-clock value instead of the fake clock when the parser is used directly.

This is only reached when a `.eml` is missing its `Date:` header AND the caller did not pass a provider-stamped fallback. In the production pipeline `BackfillInboxJob` always uses the fallback overload for Microsoft (provider stamps `receivedDateTime`); for Gmail it calls `parseHeaders` (no fallback) because Gmail's `users.messages.list` does not return per-message internalDate. So a malformed Gmail message with no Date header silently lands `inbox_messages.internal_date = wall_clock_now`. This is the explicitly-documented design trade-off, but it's still a real correctness gap: ordering Gmail messages by `internal_date` may produce a now-cluster when the inbox has malformed-header receipts.

**Fix:** Either (a) accept a `Clock $clock` parameter on `parseHeaders` and route it through, or (b) deprecate the no-fallback overload and force every caller through `parseHeadersWithFallbackDate` with an explicit provider date or `$clock->now()`. Option (b) is cleaner — the Gmail branch should resolve the fallback at the call site so the choice is visible to the caller, not hidden inside the parser.

```php
// In BackfillInboxJob.runGmailBackfill, change:
extractInternalDate: static fn (array $msgMeta): ?DateTimeImmutable => null,
// to:
extractInternalDate: static fn (array $msgMeta): ?DateTimeImmutable
    => $clock->now()->toDateTimeImmutable(),
```

Then drop the no-fallback `parseHeaders()` method entirely.

---

### WR-08: `OAuthClientWizardModal::submit` has no catch for `SecretsWriteFailed` — user retypes six-step wizard on disk-write failure

**File:** `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php:118-136`
**Issue:** The submit handler captures `clientSecret` into a local, wipes the property, then calls `$secrets->saveProviderClient($provider, $clientId, $clientSecret, $redirectUri)` (line 131). If `saveProviderClient` throws `SecretsWriteFailed` (disk full, EACCES on the temp file, etc.), the exception bubbles up to Livewire's default handler which surfaces a generic "Server error" toast. The user's `clientId` and `clientSecret` properties have already been wiped (lines 128-129) BEFORE the write attempt, so the user must re-paste BOTH values AND walk through the entire six-step wizard preamble again. There is no UX path back to the populated form.

The wipe-before-call is intentional and correct for security (a snapshot round-trip of the secret would expose it via the wire payload), so the fix is NOT to keep the values on the component — it's to catch the exception, set `$this->errorMessage`, and let the modal re-render with the explanation. The user will still have to re-paste, but they'll know why.

**Fix:**

```php
try {
    $secrets->saveProviderClient($provider, $clientId, $clientSecret, $redirectUri);
} catch (SecretsWriteFailed $e) {
    $this->errorMessage = 'Could not save your OAuth client to disk — check storage/app/secrets/ permissions and try again.';
    return null;
}
$this->dispatch('modal-hide', name: 'oauth-client-wizard-'.$provider);
return $this->redirectRoute('oauth.connect', ['provider' => $provider]);
```

## Info

### IN-01: `inboxes-page.blade.php` uses the `session()` global helper twice

**File:** `Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php:21, 27`
**Issue:** The Blade reads `session()->has('oauth_canceled')` and `session()->has('oauth_failed')` via the global `session()` helper. The CLAUDE.md DI-only invariant explicitly bans global helpers in app code; Blade is more permissive in practice, but the rest of the module (e.g. `Dashboard.php`, `OAuthStateRepository.php`, `InboxesPage::mount`) consistently injects the `Session` contract. The same pattern would be to pull the flash values into the Livewire component via the `mount()` hook and pass them as props.

**Fix:** Read the flash in `InboxesPage::mount(Request $request, ...)`:

```php
if ($request->hasSession()) {
    $session = $request->session();
    $this->oauthCanceledMessage = $session->pull('oauth_canceled') ?? null;
    $this->oauthFailedMessage = $session->pull('oauth_failed') ?? null;
}
```

Then surface as public properties to the Blade. Use `pull` (single-use) rather than `has` + `get` (which leaks across renders).

---

### IN-02: `Schedule::call(...)` closures enumerate all inboxes / all users with no filter

**File:** `routes/console.php:33-38, 50-55`
**Issue:** The hourly `email-scan.incremental` closure does `$db->connection()->table('inboxes')->pluck('id')` — no filter on `provider`, no exclusion of `needs_reauth` rows, no limit. Same for the daily discovery scan over `users`. Today's single-user, low-inbox-count behaviour is fine. As the project moves toward multi-user, each tick will dispatch one job per inbox in the system; the `ShouldBeUnique` lock collapses duplicate dispatches harmlessly, but the queue still receives N jobs per minute where N grows linearly with inbox count.

Cheap improvement: exclude `needs_reauth` inboxes from the hourly dispatch (the job's early-exit #1 handles it anyway, but skipping the dispatch saves the queue worker cycle):

```php
$inboxIds = $db->connection()
    ->table('inboxes')
    ->leftJoin('inbox_scan_state', function ($join): void {
        $join->on('inbox_scan_state.inbox_id', '=', 'inboxes.id')
            ->where('inbox_scan_state.folder', '=', 'INBOX');
    })
    ->where(function ($q): void {
        $q->whereNull('inbox_scan_state.status')
          ->orWhere('inbox_scan_state.status', '!=', 'needs_reauth');
    })
    ->pluck('inboxes.id');
```

---

### IN-03: `DiscoveryScanJob` Gmail branch reads `$msg['internalDate']` without `is_string` narrowing

**File:** `Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php:262-269`
**Issue:** Line 262 reads `$rawDate = $msg['internalDate'];` directly — the array shape promised by `GmailApiClientContract::listDiscoveryCandidates` types `internalDate` as `string`, but PHPStan's strict mode only enforces the declared shape at the boundary, not at runtime. A future Fake variant or a production-side response shape drift could return `null` or an int. The next line then does `$rawDate !== ''`, which evaluates `true` for both `null` and any non-empty string, and only the latter case is safe for `safeParseDate`. The Microsoft branch (line 289-298) correctly guards with `is_string($received) && $received !== ''`.

**Fix:** Mirror the Microsoft branch's guard:

```php
$rawDate = $msg['internalDate'];
$internalDate = is_string($rawDate) && $rawDate !== ''
    ? $this->safeParseDate($rawDate, $clock)
    : $clock->now()->toDateTimeImmutable();
$messages[] = [
    'sender_email' => strtolower($msg['fromAddress']),
    'sender_name' => $msg['fromName'],
    'internalDate' => $internalDate,
];
```

While there, mirror the same guard for `$msg['fromAddress']` — it's typed `string` in the contract but the same drift-resilience argument applies.

---

_Reviewed: 2026-05-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
_Iteration: 2_
