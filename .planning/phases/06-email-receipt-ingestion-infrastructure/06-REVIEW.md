---
phase: 06-email-receipt-ingestion-infrastructure
reviewed: 2026-05-17T00:00:00Z
depth: standard
files_reviewed: 60
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
  - Modules/EmailScan/Internal/EmlBlobStore.php
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
  - Modules/EmailScan/Internal/MimeHeaderParser.php
  - Modules/EmailScan/Internal/OAuth/AccessTokenWithEmail.php
  - Modules/EmailScan/Internal/OAuth/GoogleOAuthProvider.php
  - Modules/EmailScan/Internal/OAuth/InvalidGrantException.php
  - Modules/EmailScan/Internal/OAuth/InvalidStateException.php
  - Modules/EmailScan/Internal/OAuth/MicrosoftOAuthProvider.php
  - Modules/EmailScan/Internal/OAuth/OAuthExchangeFailed.php
  - Modules/EmailScan/Internal/OAuth/OAuthStateRepository.php
  - Modules/EmailScan/Internal/ParsedMessageHeaders.php
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
  - composer.json
  - deploy/launchd/com.diederik.horizon.plist
  - deploy/launchd/com.diederik.redis.plist
  - deploy/launchd/com.diederik.scheduler.plist
  - phpstan.neon
  - phpunit.xml
  - routes/console.php
  - tests/Contracts/BoundaryArchTest.php
  - tests/Contracts/NoExtImapTest.php
  - tests/Contracts/UserIdColumnArchTest.php
  - tests/Pest.php
findings:
  critical: 5
  warning: 9
  info: 6
  total: 20
status: issues_found
---

# Phase 6: Code Review Report

**Reviewed:** 2026-05-17T00:00:00Z
**Depth:** standard
**Files Reviewed:** 60 production files (tests excluded per scope config)
**Status:** issues_found

## Summary

The EmailScan phase delivers a substantial OAuth + ingestion stack: per-user OAuth client wizard, Gmail / Graph clients, backfill + incremental + discovery scan jobs, an `InboxScanStateMachine` sole-mutator, chmod-600 atomic secrets repository, and a Livewire `/inboxes` surface. Boundary discipline (no facades except the documented `Cache::driver('redis')` carve-out, no transactions writes, sole-mutator for `inbox_scan_state`) holds in spirit and is backed by arch tests.

The review surfaces five **BLOCKER** defects and nine **WARNING** defects that affect correctness, security, and idempotency:

- **OAuth callback does not bind the consent flow to the originating user.** A logged-in user A can complete a consent flow that User B started, attaching B's Google/Microsoft tokens to A's account — or A can capture B's `state` token from an injected redirect and forge an attack. The session-scoped state repository has no `user_id` binding (CR-01).
- **OAuth callback writes secrets AFTER the DB commit; a write failure leaves a "ghost" inbox row without credentials, and the inbox is later mistreated by every code path that reads `OAuthSecretsRepository::loadInbox()`.** (CR-02)
- **Provider message ID validation in `EmlBlobStore` rejects real Microsoft Graph IDs.** Graph message IDs contain `=`, `+`, `/` (base64), `:` (immutable IDs), and frequently exceed 200 characters — production fetches will throw `InvalidArgumentException` on the first real message (CR-03).
- **`OAuthClientWizardModal::clientSecret` is bound via `wire:model.live`** which round-trips the secret over the wire on every keystroke and persists it in component state visible in the rendered HTML payload (CR-04).
- **`Sleep::sleep(2)` runs inside a Laravel database transaction in the Gmail historical-bug path** — and the entire backfill walks pages with a 2 s sleep between pages while the per-message `insertOrIgnore` happens in its own transaction; cumulative latency and SQLite contention are not the problem here, but the *unique-lock holds Redis for the worker's entire backfill window*. (WR-05). See CR-05 for the real issue: the `BackfillInboxJob` releases `ShouldBeUniqueUntilProcessing` the moment the worker starts, so a queued duplicate then runs concurrently and corrupts the same inbox's page cursor with two parallel walks.

Additional findings include redirect-URI host/scheme drift, session-driver coupling, OAuth scope drift between authorization and refresh, Eloquent N+1 from the Discovered-senders panel, and several quality-of-life issues called out below.

---

## Structural Findings (fallow)

_No `<structural_findings>` block was provided with this review request — only narrative findings are surfaced._

---

## Narrative Findings (AI reviewer)

## Critical Issues

### CR-01: OAuth callback does not bind state to the initiating user — cross-user account-attach attack

**File:** `Modules/EmailScan/Internal/OAuth/OAuthStateRepository.php:34-79`, `Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php:107-157`
**Issue:**
`OAuthStateRepository::issueState()` stores only `state`, `inbox_id`, and `issued_at` in the session. `consumeState()` only compares the state value via `hash_equals`. The callback then writes the new inbox row (and the chmod-600 credentials) under whichever user happens to be authenticated at the moment the IdP redirect lands — `CurrentUser::user()` is read fresh in `OAuthCallbackController::__invoke`.

This is a session-binding gap, not a CSRF gap. The attack shape is:
- The state is stored in the Laravel session, so an attacker cannot forge it cross-session.
- BUT — because the project supports a future second user (CLAUDE.md "Multi-user readiness"), and because nothing in `consumeState()` cross-checks against `$currentUser->user()->id`, any concurrent session reuse / shared browser / privilege change between authorize and callback attaches the new inbox to the *current* `auth.user`, not the user who initiated the connect.
- In the reconnect path (`existingInboxId > 0`), the controller only checks `where('id', $existingInboxId)->where('user_id', $userId)` — if the current user has changed since `OAuthConnectController` issued the state, `$affected === 0` and a `NotFoundHttpException` is thrown, but the secret-write at line 162+ has already been partially issued for the WRONG user (see CR-02 for ordering). For the new-inbox path the row is silently written under the wrong user.

The state issue itself also has no expiry check beyond what the session driver supplies. `issued_at` is stored but never read — a state token issued days ago against the same provider is still valid as long as the session entry survives.

**Fix:**
1. Bind the state entry to the user_id at issue time and verify on consume:

```php
public function issueState(string $provider, int $userId, ?int $existingInboxId = null): string
{
    $this->assertProvider($provider);
    $state = bin2hex(random_bytes(32));
    $this->session->put($this->sessionKey($provider), [
        'state' => $state,
        'user_id' => $userId,
        'inbox_id' => $existingInboxId,
        'issued_at' => $this->clock->now()->toDateTimeString(),
    ]);
    return $state;
}

public function consumeState(string $provider, string $candidateState, int $currentUserId): ?int
{
    // ...pull entry...
    if (! is_array($entry)) return null;
    $storedState = $entry['state'] ?? null;
    if (! is_string($storedState) || ! hash_equals($storedState, $candidateState)) return null;
    if (($entry['user_id'] ?? null) !== $currentUserId) return null;
    // Optional: enforce a 10-minute issue-window via $entry['issued_at']
    $inboxId = $entry['inbox_id'] ?? null;
    return is_int($inboxId) ? $inboxId : 0;
}
```

2. Update `OAuthConnectController` and `OAuthCallbackController` to pass `$currentUser->user()->id`.
3. Reject expired state by parsing `issued_at` against a configurable maximum (e.g. 10 min).

---

### CR-02: OAuth callback writes DB row before chmod-600 secret — partial-failure leaves ghost inbox with NO credentials

**File:** `Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php:114-180`
**Issue:**
The docblock at lines 159–161 claims: *"The chmod-600 JSON write happens AFTER the DB commit so a failure to write secrets cannot leave behind an orphaned inboxes row pointing at a credential we never persisted."* That ordering is exactly backwards from what would be safe. A failure of `OAuthSecretsRepository::saveInboxRefreshToken()` (line 172) after the DB transaction has committed produces:

- An `inboxes` row pointing at the new id.
- An `inbox_scan_state` row pointing at the new id.
- NO entry in `email-oauth.json` for the inbox.
- An uncaught `SecretsWriteFailed` exception bubbling up to the user as HTTP 500.

When the scheduler subsequently fires `IncrementalScanJob` (every hour) or `DiscoveryScanJob` (daily) against this orphan inbox, `GmailApiClient::ensureFreshAccessToken()` / `GraphApiClient::ensureFreshAccessToken()` throws `RuntimeException("no OAuth credentials persisted for inbox {$inboxId}")` which lands in the job's `Throwable` catch, transitioning the inbox to `error` status — but the inbox row remains visible on the `/inboxes` page indefinitely with no way for the user to repair it short of clicking Reconnect.

Worse: for the reconnect path (lines 162–170), if `rotateRefreshToken()` fails, the prior refresh token is *destroyed* by the DB-transaction having already updated the email; the user has no way to recover except by going through consent again.

Additionally, `saveInboxRefreshToken` at line 172–179 swallows the `$refreshToken === null` case by writing an empty string `refresh_token: ''` — `ensureFreshAccessToken()` then sends an empty string to the OAuth provider on the next refresh attempt, which returns `invalid_grant`, marking the inbox `needs_reauth`. The new-inbox branch should refuse to persist when `$refreshToken` is null and instead surface a flash error telling the user the provider declined to issue a refresh token (typically because the Google consent screen is still in Testing mode — the wizard's `publishedConfirmed` checkbox guards this on the Google side, but Microsoft has its own quirks).

**Fix:**
Either (a) write the secret FIRST under a try block that rolls back the DB transaction on failure, or (b) keep the current order but compensate on failure by rolling back the DB rows in a `try/catch` around the secret write:

```php
$inboxId = $this->db->connection()->transaction(/* ... insert rows ... */);

try {
    if ($existingInboxId > 0) {
        if ($refreshToken !== null) {
            $this->secrets->rotateRefreshToken(
                inboxId: $inboxId,
                newRefreshToken: $refreshToken,
                newAccessToken: $tokenWithEmail->accessToken,
                expiresAt: $expiresAt,
            );
        }
    } else {
        if ($refreshToken === null || $refreshToken === '') {
            // Compensating rollback — delete the rows we just inserted.
            $this->db->connection()->transaction(function () use ($inboxId, $userId): void {
                $this->db->connection()->table('inbox_scan_state')
                    ->where('inbox_id', $inboxId)->where('user_id', $userId)->delete();
                $this->db->connection()->table('inboxes')
                    ->where('id', $inboxId)->where('user_id', $userId)->delete();
            });
            return $this->redirector->route('inboxes.index')->with(
                'oauth_failed',
                'Provider did not return a refresh token. For Google, publish the OAuth consent screen to "In production".',
            );
        }
        $this->secrets->saveInboxRefreshToken(
            inboxId: $inboxId, provider: $provider, email: $email,
            refreshToken: $refreshToken, scope: $tokenWithEmail->scope, expiresAt: $expiresAt,
        );
    }
} catch (SecretsWriteFailed $e) {
    // Compensating rollback.
    if ($existingInboxId === 0) {
        $this->db->connection()->transaction(function () use ($inboxId, $userId): void {
            $this->db->connection()->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)->where('user_id', $userId)->delete();
            $this->db->connection()->table('inboxes')
                ->where('id', $inboxId)->where('user_id', $userId)->delete();
        });
    }
    return $this->redirector->route('inboxes.index')->with('oauth_failed', $e->getMessage());
}
```

The docblock at lines 159–161 also needs to be corrected (it claims the current order *prevents* the orphan; in fact it *creates* one — see "Docs describe current state" feedback rule in MEMORY).

---

### CR-03: `EmlBlobStore::MESSAGE_ID_PATTERN` rejects real Microsoft Graph message IDs — every Graph fetch will throw

**File:** `Modules/EmailScan/Internal/EmlBlobStore.php:43`, line 64-69
**Issue:**
The allow-list is `/^[A-Za-z0-9._-]{1,200}$/`. Real Microsoft Graph message IDs are URL-safe base64 strings that:

1. Often contain `=` padding (e.g. `AAMkADYwYTYwOWY3LWQxMjEtNDNiYi05ZWI4LTM1OTcxZTllZGMwOQBGAAAAAACGiYUxK2KCT...AAA=`).
2. Routinely run 100–180 characters; immutable-id (`Prefer: IdType="ImmutableId"`) variants can hit 200+ characters.
3. May contain `+` and `/` in non-URL-safe base64 variants depending on Graph API version.

The Gmail-side allow-list narrowly accepts Gmail's short hex IDs but the same pattern is applied to Graph IDs — `BackfillInboxJob::walkAndPersist()` and `IncrementalScanJob::runMicrosoftIncremental()` both call `$blobStore->pathFor($userId, $this->inboxId, ..., $messageId)` with the Graph-supplied ID verbatim. The first real Graph message that contains `=` or exceeds 200 chars throws `InvalidArgumentException` from `pathFor`, the surrounding job's `Throwable` handler transitions the inbox to `error`, the worker rethrows, Horizon retries 3× with the same payload, all 3 fail identically, and the inbox sits in `error` permanently.

`GraphApiClient::MESSAGE_ID_PATTERN` at line 68 of `GraphApiClient.php` uses `/^[A-Za-z0-9._%=+\-]{1,512}$/` — which is closer to reality. The two regexes contradict each other; the blob-store is the stricter / wronger one.

The Gmail fixtures use synthetic short IDs (`paypal-sample-receipt`) so the Fake-driven test suite passes; no test exercises a realistic Graph message ID.

**Fix:**
Align `EmlBlobStore::MESSAGE_ID_PATTERN` with the Graph reality and add a hash fallback for safety:

```php
private const MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._%=+\-]{1,512}$/';
// ...
public function pathFor(int $userId, int $inboxId, DateTimeImmutable $internalDate, string $providerMessageId): string
{
    if (preg_match(self::MESSAGE_ID_PATTERN, $providerMessageId) !== 1) {
        throw new InvalidArgumentException('EmlBlobStore: provider_message_id failed allow-list validation.');
    }
    // Hash to a filesystem-safe short slug to defend against case-collapsing FSes
    // and OS path-length limits. The unique key on (inbox_id, provider_message_id)
    // in inbox_messages remains the source of truth for the ID itself.
    $slug = substr(hash('sha256', $providerMessageId), 0, 32).'_'.strtr(substr($providerMessageId, 0, 40), '+/=', '-_-');
    return storage_path(sprintf(
        'app/inbox/%d/%d/%04d/%02d/%s.eml',
        $userId, $inboxId,
        (int) $internalDate->format('Y'), (int) $internalDate->format('m'),
        $slug,
    ));
}
```

A backfill test that exercises the Graph branch end-to-end with a realistic ID (e.g. `AAMkADYwYTYwOWY3LWQxMjEtNDNiYi05ZWI4LTM1OTcxZTllZGMwOQBGAAAAAACG...AAA=`) is required to keep this regression from re-appearing.

---

### CR-04: OAuth client secret round-tripped on every keystroke via `wire:model.live`

**File:** `Modules/EmailScan/Resources/views/livewire/oauth-client-wizard-modal.blade.php:110-114, 192-196`, `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php:56`
**Issue:**
Both the Gmail and Microsoft variants of the wizard bind `clientSecret` with `wire:model.live`:

```blade
<input type="password" wire:model.live="clientSecret" placeholder="GOCSPX-..." class="..." />
```

`wire:model.live` issues a Livewire round-trip on every input event. The full intermediate value of the secret is therefore POSTed to the server many times during typing AND echoed back inside the component's serialised property snapshot in every server-rendered fragment (Livewire includes the public property values in the `wire:snapshot` attribute of the root component element when the component re-renders). For a `password`-typed input the browser will not auto-fill it back into the DOM, but the Livewire snapshot stored in the page's `wire:snapshot` JSON attribute is unencrypted plain text.

Anyone with a viewing window onto the user's screen (screen recorder, support tool, browser dev tools shared on a Zoom call) sees the secret in the network panel and the DOM inspector. For a *truly* local-only app this is a smaller concern than it would be on a public host, but the project specifically wants to send "secrets … never leave your machine" — round-tripping a client secret on every keystroke through Livewire's snapshot mechanism is the opposite of that posture.

The `clientId` is fine for `wire:model.live` (not secret), but the secret should be `wire:model.blur` or `wire:model` (defer) so the value is only sent once when the field loses focus or the form submits, AND the field should be excluded from Livewire snapshots after submission.

**Fix:**
1. Change `wire:model.live="clientSecret"` to `wire:model.blur="clientSecret"` (single round-trip on blur) or `wire:model.defer="clientSecret"` (single round-trip on submit).
2. Inside `OAuthClientWizardModal::submit()`, zero out `$this->clientSecret = ''` after the successful `saveProviderClient()` call so the property is not preserved in subsequent renders.
3. Consider `protected $listeners` / `#[Locked]` attribute or split `clientSecret` into a transient local-only variable that never lives on the component (use a hidden form post + Livewire's intercept-form pattern). The simplest fix is the wipe-after-submit pattern.

```php
public function submit(OAuthSecretsRepository $secrets, ConfigRepository $config): mixed
{
    // ... validation ...
    $redirectUri = $this->computeLoopbackRedirectUri($provider, $config);
    $clientSecret = $this->clientSecret;
    $this->clientSecret = ''; // wipe BEFORE any external call so a thrown
                              // exception cannot leave the secret on the property
    $secrets->saveProviderClient($provider, $this->clientId, $clientSecret, $redirectUri);
    $this->clientId = '';
    // ...
    return $this->redirectRoute('oauth.connect', ['provider' => $provider]);
}
```

---

### CR-05: `BackfillInboxJob` single-flight lock releases BEFORE backfill completes — concurrent duplicates corrupt the page walk

**File:** `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php:101, 132-139`
**Issue:**
The job implements `ShouldBeUniqueUntilProcessing` (NOT `ShouldBeUnique`). The class docblock at lines 36–40 is explicit about this choice:

> "the lock releases as soon as `handle()` begins so a re-dispatch can sit in the queue while the prior pass finishes."

That is precisely backwards for the safety story. The job's per-inbox backfill walk:

1. Opens a multi-page Gmail/Graph walk with provider rate-limit sleep(2) between pages — total runtime is minutes for a 12-month window.
2. Writes per-message `.eml` blobs + `inbox_messages` rows via `insertOrIgnore`.
3. Writes the cursor (`recordCursor`) only after the walk completes.

If the user clicks "Edit window" → "Start backfill" twice in quick succession (or if a Livewire double-render fires the wire-click twice), the second job lands in the queue, BUT — because `ShouldBeUniqueUntilProcessing` releases the lock the moment the first worker picks it up — the second worker starts handling immediately upon any available slot. Both workers then walk the SAME inbox's first page concurrently. Both pages get the same first-page result. The `insertOrIgnore` saves us from duplicate index rows, BUT:

- Both workers race on `backfill_progress` — `inboxes.backfill_progress = json_encode(['fetched_count' => $fetched])` — the per-worker `$fetched` counter is local; the strip-render value flaps backwards.
- Both workers race on `recordCursor()`; whichever finishes second silently overwrites the more recent cursor.
- `Sleep::sleep(2)` between pages doubles per-page latency without preventing the race.
- The `applyStatus('backfilling')` call at the start of each worker contends, but only one succeeds; the SECOND one throws `InvalidStateTransitionException` because `backfilling → backfilling` is NOT in `ALLOWED_TRANSITIONS['backfilling']`. The job's `Throwable` handler at line 318–325 catches it, transitions the inbox to `error`, and rethrows — even though the FIRST worker is still healthily walking pages. The inbox surface flips to `error` while the real backfill continues silently in the background.

The fix is to use `ShouldBeUnique` (NOT ...UntilProcessing) keyed on `inboxId`, with a `uniqueFor` ceiling that exceeds the expected worst-case walk time (30 min today is OK if you also actually hold the lock for that long).

**Fix:**
Switch the implements clause to `ShouldBeUnique`:

```php
final class BackfillInboxJob implements ShouldBeUnique, ShouldQueue
{
    // ...
    public function uniqueFor(): int { return 1800; } // unchanged
    public function uniqueId(): string { return (string) $this->inboxId; }
    public function uniqueVia(): Repository { /* unchanged */ }
}
```

Same fix applies to `IncrementalScanJob` and `DiscoveryScanJob` — the docblocks for both also claim "release on pickup" is intentional, but as written they admit the same race.

Additionally, remove the `applyStatus('backfilling')` re-entry from the failure path — for a single-flight job this transition should be idempotent ('backfilling → backfilling' should be allowed in `ALLOWED_TRANSITIONS`).

---

## Warnings

### WR-01: Loopback redirect URI ignores host/scheme — wizard / connect / callback may disagree

**File:** `Modules/EmailScan/Internal/Http/Controllers/OAuthConnectController.php:85-93`, `Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php:187-195`, `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php:143-151`
**Issue:**
All three sites compute the redirect URI as `http://127.0.0.1:{port}/oauth/callback/{provider}` by parsing `app.url` for ONLY the port and discarding the host and scheme. In Laravel Herd the default for a project is `https://diederik.test` — port is null (443 for HTTPS, but `parse_url` returns null for omitted ports) → the `?: 8000` fallback then makes the URI `http://127.0.0.1:8000/oauth/callback/...`. Meanwhile the app is actually served at `https://diederik.test` via Herd's HTTPS proxy, so:

1. The user-facing app runs at HTTPS on the `.test` domain.
2. The OAuth client registered in Google/Microsoft is told `http://127.0.0.1:8000/oauth/callback/gmail`.
3. The IdP redirect lands at `http://127.0.0.1:8000/oauth/callback/gmail` — which is NOT the same origin/session as `https://diederik.test`. The session cookie does not transfer; `OAuthStateRepository::consumeState` returns null; the callback throws `InvalidStateException` (HTTP 500).

For a user running `php artisan serve` at port 8000 the URI happens to be right. For Herd users — which the STACK section calls "the recommended local dev environment" — the URI is wrong every single time.

A second issue: the hardcoded `127.0.0.1` is fine for Google's loopback-IP redirect semantics, but Microsoft Entra rejects `127.0.0.1` redirect URIs for the consumer / multi-tenant `common` endpoint unless `Web` platform is configured AND the URI is HTTPS (since 2021). The wizard's instructions tell users to choose "Web" platform — Azure will reject `http://127.0.0.1:8000/...` at registration time.

**Fix:**
Make the URI computation honour the configured `app.url` for both scheme and host, and document the Herd vs `serve` case explicitly:

```php
private function computeLoopbackRedirectUri(string $provider): string
{
    $appUrl = $this->config->get('app.url');
    $appUrlString = is_string($appUrl) ? $appUrl : 'http://127.0.0.1:8000';
    $parts = parse_url($appUrlString);
    $scheme = is_array($parts) && isset($parts['scheme']) ? (string) $parts['scheme'] : 'http';
    $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '127.0.0.1';
    $port = is_array($parts) && isset($parts['port']) ? ':'.((int) $parts['port']) : '';
    return $scheme.'://'.$host.$port.'/oauth/callback/'.$provider;
}
```

Then surface the computed URI in the wizard for the user to register, exactly as it appears. (The wizard already does this — but if it's wrong, registration succeeds and runtime fails silently.)

---

### WR-02: OAuth scope drift between authorize and exchange — Google branch silently re-quotes scope

**File:** `Modules/EmailScan/Internal/OAuth/GoogleOAuthProvider.php:55-66, 99`
**Issue:**
`getAuthorizationUrl()` requests `[GMAIL_READONLY_SCOPE, USERINFO_EMAIL_SCOPE]` (two scopes). `exchangeAuthorizationCode()` builds the returned `AccessTokenWithEmail` with `scope: self::GMAIL_READONLY_SCOPE` — dropping `userinfo.email` from the persisted scope string. The `refreshAccessToken()` path then constructs the league provider without specifying scope, so the refresh uses whatever Google returns. If a user later revokes the userinfo grant out-of-band, the readEmail call against the cached access token returns 403; the wrapper catches it and throws `OAuthExchangeFailed`, which the state machine surfaces as `error`, not `needs_reauth`. The user is told "OAuth exchange failed" with no recovery hint.

The same shape applies on the Microsoft side BUT inverted: the constant `SCOPE_STRING` is passed correctly to both authorize and refresh, so Microsoft is internally consistent. The drift is Gmail-specific.

**Fix:**
Persist the FULL scope string Google actually returned in the token (`$token->getValues()['scope']` if league exposes it; otherwise reconstruct the requested list):

```php
return new AccessTokenWithEmail(
    accessToken: $accessTokenString,
    refreshToken: is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
    expiresAt: $expiresAt,
    scope: self::GMAIL_READONLY_SCOPE.' '.self::USERINFO_EMAIL_SCOPE,
    email: $email,
);
```

---

### WR-03: Discovered-senders panel render does NOT load the sample-message relationship; per-row `senderName` fallback walks `strstr` in Blade

**File:** `Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php:234-241`
**Issue:**
The Blade computes `$fallbackName = strstr($cand->senderEmail, '@', true)` inside the foreach. This is correct but tiny; the larger concern is that the panel does not surface the sample message subject (per UI-SPEC the candidate panel should show a representative subject — `DiscoveredSender.sample_message_id` exists in the schema but `DiscoveredSenderQuery` never loads it and the Blade never renders it). Either the UI spec needs to drop the requirement or the query needs to JOIN to `inbox_messages` once and surface the subject.

Separately, `InboxesPage::render()` calls `$inboxQuery->forCurrentUser($user)` and `$discoveredQuery->candidatesForUser($user)` on every wire-poll cycle (2 s for the progress strip). Each call issues 2 COUNT queries + 1 SELECT against `discovered_senders` and 1 SELECT join against `inboxes + inbox_scan_state`. At 30 polls/min during an active backfill that is ~120 queries/min for a page that may have nothing to update.

**Fix:**
1. Consider a Livewire computed property cached for the duration of the poll cycle.
2. If the spec requires sample subjects, add a JOIN to `DiscoveredSenderQuery` and a `sampleSubject` field on the DTO + Blade render.

---

### WR-04: `DiscoveredSenderQuery::candidatesForUser` ignores `inbox_id` cross-user race; relies entirely on `user_id` filter

**File:** `Modules/EmailScan/Public/Services/DiscoveredSenderQuery.php:69-104`
**Issue:**
The query filters by `user_id` only; the `inbox_id` column is denormalised. If a user A's `inbox_id` reference somehow lands in a `discovered_senders` row owned by user B (a future bug, a malicious foreign-key insert, an ORM lifecycle race), the SELECT happily returns it — the UI then shows user A's email address on user B's panel. The cross-user 404 invariant in `PromoteDiscoveredSender::__invoke()` saves the write-side, but the read-side leaks.

Add a JOIN to `inboxes` on `(discovered_senders.inbox_id = inboxes.id AND inboxes.user_id = discovered_senders.user_id)` so the row is only surfaced if both denormalised columns agree.

**Fix:**
```php
$rows = $this->db->connection()
    ->table('discovered_senders')
    ->join('inboxes', function ($join): void {
        $join->on('inboxes.id', '=', 'discovered_senders.inbox_id')
            ->on('inboxes.user_id', '=', 'discovered_senders.user_id');
    })
    ->where('discovered_senders.user_id', $user->id)
    ->where('discovered_senders.state', 'candidate')
    // ... rest unchanged ...
```

---

### WR-05: `BackfillInboxJob` sleeps inside a tight loop without honouring queue cancellation

**File:** `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php:579`
**Issue:**
`Sleep::sleep(2)` per page is reasonable as a quota guard but the loop never checks whether the worker has been signalled (e.g. `php artisan queue:restart` sends a signal that workers honour on the NEXT job; in-flight sleeps stay running). For a year-long Gmail backfill this can mean an unkillable 5+ minute hang per `queue:restart`. Not a correctness issue but worth documenting.

Also: `Sleep::sleep(2)` blocks the worker; consider releasing the job back to the queue with `release(60)` after each page to free the worker for other tenants. For a single-user app the trade-off is fine, but the multi-user readiness goal makes this worth flagging.

**Fix:** No code change required for v1. Add a TODO and a note in the class docblock acknowledging the trade-off.

---

### WR-06: `IncrementalScanJob` Gmail incremental walk re-fetches messages already on disk without checking `inbox_messages`

**File:** `Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php:326-364`
**Issue:**
The Gmail incremental walk calls `$gmail->getRawMessage($this->inboxId, $messageId)` for every message id pulled from `listHistory` — including messages that may already exist in `inbox_messages` from a prior backfill / fallback walk. The `.eml` is then written via `$blobStore->put()`, which atomic-renames OVER the existing file (no harm beyond wasted IO), and `insertOrIgnore` short-circuits the DB write. But the **Graph API call burns quota** for every duplicate fetch.

A pre-check `inbox_messages` exists query before the `getRawMessage` call would skip the duplicate fetch entirely. The fallback walk path is even more exposed — it can re-fetch up to `FALLBACK_WALK_HARD_CAP = 500` messages every hour after a cursor expiry.

**Fix:**
```php
$exists = $connection->table('inbox_messages')
    ->where('inbox_id', $this->inboxId)
    ->where('provider_message_id', $messageId)
    ->exists();
if ($exists) continue;
$rawEml = $gmail->getRawMessage($this->inboxId, $messageId);
// ... rest of the loop ...
```

This is also true of the Microsoft incremental walk (line 422-486) and the backfill walk in `BackfillInboxJob::walkAndPersist()` (line 499+).

---

### WR-07: `gmailFallbackWalk` deliberately discards window cap; walks the entire allow-list history

**File:** `Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php:540-565`
**Issue:**
The method signature accepts `$stateRow` and `$clock` but immediately discards both with `unset($stateRow, $clock); // window-cap inputs reserved for production walk shape`. The fallback walk then has only the 500-message hard cap as a safety guard — it cannot date-bound the walk because the production `GmailApiClientContract::listSenderMessages` does not accept a `since` parameter.

Result: a cursor expiry for a user with > 500 receipts in the allow-list quietly truncates the recovery at 500 messages, oldest-first based on Gmail's default ordering. The docblock at line 119 promises "capped at the last_scan_at minus 7 days" but the implementation cannot deliver that promise. The class-level docblock at lines 62-66 makes the same promise.

This is a design / implementation mismatch — either:
- Add a `since` parameter to `GmailApiClientContract::listSenderMessages` and threading it through (preferred — matches the docblock contract).
- OR amend both docblocks to admit the 500-cap is the only safety net.

Either way the current "promise of date-bound, deliver only count-bound" state is misleading future maintainers (and the test suite).

**Fix:** Add `?DateTimeImmutable $windowStart` to the Gmail contract — same shape as the Graph contract already has.

---

### WR-08: `EmlBlobStore::put` calls `@chmod($dir, ...)` unconditionally — corrupts permissions on shared parent dirs

**File:** `Modules/EmailScan/Internal/EmlBlobStore.php:90-92`
**Issue:**
Every `put()` call runs `@chmod($dir, self::DIR_MODE)` after `ensureDirectoryExists`. For the **first** write to a `YYYY/MM/` directory this is correct. For every subsequent write, it re-chmods the directory — and if the directory's permissions have been intentionally widened by an admin (e.g. for `tar`-archive backup tooling), every fetched message silently narrows it back to 0700. Same pattern in `OAuthSecretsRepository::writeAtomic` line 268, also `@chmod` on every write.

This is defensive and consistent — fine for security — but it should be done ONCE on creation, not on every write. The `@` suppression also hides genuine `chmod` failures.

**Fix:** Track-and-set on first-create only:
```php
$exists = $this->files->isDirectory($dir);
$this->files->ensureDirectoryExists($dir, self::DIR_MODE, recursive: true);
if (! $exists && ! @chmod($dir, self::DIR_MODE)) {
    throw new RuntimeException("EmlBlobStore: failed to chmod created directory {$dir}.");
}
```

---

### WR-09: `JobFailed` listener uses fragile regex over PHP serialised payload — false positives + false negatives

**File:** `Modules/EmailScan/Providers/EmailScanServiceProvider.php:217-231`
**Issue:**
The extractor uses `preg_match('/inboxId[^0-9-]+(-?\d+)/', $command, $matches)` against the serialised job payload. This has two failure modes:

1. **False positive**: A future job class that happens to contain the string `inboxId` followed by a number in any property name (e.g. `notInboxIdButSomethingElse`) will match. Today only the two jobs use `$inboxId`, so unlikely — but the regex is unanchored and order-dependent on PHP's serialiser output.
2. **False negative**: The regex consumes `-?\d+` so a NEGATIVE id (which should never exist but PHP serialisation could produce one on a corrupted payload) is parsed as a negative inbox id. The downstream `$sm->applyStatus($inboxId, 'error', ...)` then queries `inbox_scan_state` for a negative id, finds nothing, throws `RuntimeException` from the state machine — and the entire listener throws, which is then *caught* by the listener's own `Throwable` guard (line 199-204), silently swallowing the failure log.

The serialised payload format is also Laravel-version-dependent. A Laravel 13.x bump could change the serialiser output and silently break the listener.

A cleaner approach is to add a `failed(Throwable $e)` method to each job class — Laravel calls it directly with the resolved job instance, so `$this->inboxId` is available without regex parsing.

**Fix:**
```php
// In BackfillInboxJob + IncrementalScanJob:
public function failed(Throwable $exception, InboxScanStateMachine $sm): void
{
    try {
        $sm->applyStatus($this->inboxId, 'error', substr($exception->getMessage(), 0, 500));
    } catch (Throwable) {
        // Swallow — invalid transition is acceptable in the failed-hook surface.
    }
}
```

This eliminates the `JobFailed` listener, the regex, and the serialiser coupling entirely.

---

## Info

### IN-01: `ScanCursor::microsoft` rejects German / Chinese Graph endpoints

**File:** `Modules/EmailScan/Public/Dto/ScanCursor.php:60-67`
**Issue:** The factory rejects delta links that do not start with `https://graph.microsoft.com/` — but Graph has regional endpoints `graph.microsoft.de` (Germany), `microsoftgraph.chinacloudapi.cn` (China), and `graph.microsoft.us` (GCC High). The project explicitly targets only personal Outlook.com today, so the constraint is fine for v1, but the rejection message should call this out so the eventual EU-cloud move is obvious.

**Fix:** Document in the docblock that only the global cloud endpoint is supported; the constraint will need to relax when regional cloud support is added.

---

### IN-02: `safeMessage()` line-cap is duplicated three times verbatim

**File:** `Modules/EmailScan/Internal/OAuth/GoogleOAuthProvider.php:224-232`, `Modules/EmailScan/Internal/OAuth/MicrosoftOAuthProvider.php:250-258`, `Modules/EmailScan/Internal/Clients/GraphApiClient.php:523-528`
**Issue:** Three near-identical 8-line `safeMessage()` helpers across three classes. Extract a shared `Modules\EmailScan\Internal\OAuth\SafeMessage::cap(string $raw, int $max = 300): string` utility.

**Fix:** Static utility class in `Modules\EmailScan\Internal\`.

---

### IN-03: `BackfillInboxJob` writes `backfill_progress` outside the `InboxScanStateMachine` even though it's a per-inbox lifecycle column

**File:** `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php:560-569, 591-596`
**Issue:**
`inboxes.backfill_progress` is technically owned by the `inboxes` table not `inbox_scan_state`, so the `noOtherInboxScanStateMutator` arch test does not catch it. But the column is read by `InboxQuery::makeDto()` and used as a per-inbox lifecycle signal — same shape as `inbox_scan_state.status`. Updating it inside the job directly leaves the state-machine-as-sole-mutator pattern incomplete. Consider extending the state machine surface with a `recordBackfillProgress(int $inboxId, ?array $progress)` method so all per-inbox lifecycle writes flow through one class.

**Fix:** Refactor as suggested; not blocking.

---

### IN-04: `DiscoveryScanJob::runDiscoveryForInbox` uses 'now' string literal for missing receivedDateTime

**File:** `Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php:281`
**Issue:**
`$internalDate = is_string($received) && $received !== '' ? $received : 'now';` — the fallback `'now'` then flows into `safeParseDate()` which calls `new DateTimeImmutable($raw)` and accepts `'now'` happily. This works, but uses an implicit string-literal fallback that masks the missing-data case in the discovered_senders row's `last_seen_at`. Better to use the clock and document the intent:

```php
$internalDate = is_string($received) && $received !== ''
    ? $this->safeParseDate($received, $clock)
    : $clock->now()->toDateTimeImmutable();
```

This collapses the two parsing paths (one for the early branch, one inside `safeParseDate`) into one shape, and gets rid of the `'now'` magic string.

**Fix:** As above.

---

### IN-05: `migrate:fresh` re-inserts canonical known_senders rows; `known_senders` migration is not idempotent on re-run via seeder

**File:** `Modules/EmailScan/Database/Migrations/2026_05_16_020004_create_known_senders_table.php:72-101`
**Issue:** The migration inserts three system seed rows inline (PayPal, ICS Cards, Google Play) during `up()`. For a `migrate:fresh` this is fine. For any user who manually adds a `paypal.com` row (via the future seeder system or the discovered-senders Promote flow), there is no UNIQUE constraint on `(user_id, email_pattern)` — so a user's promoted PayPal sender can coexist with the system seed, and `KnownSenderQuery::all()` returns both. The downstream allow-list `$senderPatterns` then has a duplicate `'paypal.com'` — harmless but noisy.

**Fix:** Add a UNIQUE index on `(user_id, email_pattern)` to the migration. Note: this allows `(null, 'paypal.com')` to coexist with `(1, 'paypal.com')` since `NULL ≠ NULL` in SQL UNIQUE semantics, which is the desired behaviour.

---

### IN-06: `InboxesBadgeCount` issues two separate COUNT queries — could be one UNION ALL

**File:** `Modules/EmailScan/Public/Services/InboxesBadgeCount.php:40-58`
**Issue:** The badge runs a `discovered_senders` COUNT and an `inbox_scan_state` COUNT, then sums them in PHP. Composed via a UNION ALL on a single subquery these would be one round-trip:

```sql
SELECT
  (SELECT COUNT(*) FROM discovered_senders WHERE ...) +
  (SELECT COUNT(*) FROM inbox_scan_state WHERE ...)
AS total
```

The View composer fires on every page render that surfaces the top-nav — at 5 second poll cadence on the dashboard that is ~12 queries / minute / user where 6 would suffice. Not blocking but easy to collapse.

**Fix:** Single `selectRaw` with two subqueries summed.

---

_Reviewed: 2026-05-17T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
