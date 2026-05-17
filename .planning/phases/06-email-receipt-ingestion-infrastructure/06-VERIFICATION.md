---
phase: 06-email-receipt-ingestion-infrastructure
verified: 2026-05-17T10:00:00Z
status: human_needed
score: 10/10 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Complete Gmail OAuth round trip end-to-end"
    expected: "User completes Google Cloud Project setup, OAuth consent screen pushed to 'In production', client_id/secret pasted into wizard, consent flow completes, /inboxes shows Gmail inbox with email + idle status"
    why_human: "Requires a real GCP project and live Google OAuth consent dance; cannot mock the IdP redirect from localhost"
  - test: "Complete Microsoft 365 OAuth round trip end-to-end"
    expected: "User completes Azure App Registration, client_id (UUID) and secret pasted into wizard, consent flow completes, /inboxes shows Microsoft inbox with email + idle status"
    why_human: "Requires a real Azure App Registration and live Microsoft consent dance"
  - test: "Kill-and-restart resume: SC#3"
    expected: "After kill + restart, IncrementalScanJob reads last_history_id / last_delta_link from inbox_scan_state and resumes without re-fetching already-indexed messages"
    why_human: "Requires a live Horizon worker, an actual connected inbox, and a kill signal; cannot replicate purely in unit tests"
  - test: "Backfill progress strip renders and disappears: SC#2"
    expected: "After connecting an inbox, the backfill window modal opens, user picks 1-12 months, progress strip appears on /inboxes with wire:poll counting up, disappears when backfill completes; N inbox_messages rows + N .eml blobs exist on disk"
    why_human: "Requires real Gmail/Graph credentials and a running Horizon worker to observe progress strip behavior"
  - test: "macOS launchd auto-start: SC#5"
    expected: "Running 'php artisan diederik:install --launchd' copies plists to ~/Library/LaunchAgents/ and background workers start on macOS login; Horizon + scheduler visible in launchctl list"
    why_human: "Requires running on the user's actual macOS machine with launchctl; cannot test in CI"
  - test: "Health view 'last scan: X hours ago' per inbox: SC#4"
    expected: "After a successful incremental scan, the /inboxes page and dashboard tile show the correct 'last scanned N hours ago' text per inbox"
    why_human: "Requires a live scan run; diffForHumans output depends on actual scan timing"
  - test: "Rate-limit exponential backoff visible in health view"
    expected: "When Gmail/Graph returns a rate-limit error, inbox transitions to rate_limited status with retry_attempts incrementing; /inboxes shows the rate_limited badge; Horizon honours [60,300,900] backoff envelope"
    why_human: "Requires triggering a real rate-limit event; quota exhaustion cannot be safely faked end-to-end in CI"
  - test: "Discovery loop: reviewed senders panel + promote/dismiss"
    expected: "After DiscoveryScanJob runs daily, candidate senders appear on /inboxes discovery panel (above promotion threshold of 2 occurrences in 90 days); Add inserts known_senders row; Dismiss marks dismissed"
    why_human: "Requires a real inbox with receipt-like emails and a day-scale observation window"
---

# Phase 6: Email Receipt Ingestion Infrastructure Verification Report

**Phase Goal:** User can connect Gmail and/or Microsoft 365 inboxes via OAuth2 and have the app scan them on a schedule (and on demand) for transaction receipts, with a configurable backfill window, rate-limit-safe sequential fetching, UID-based resume, and visible scan health.
**Verified:** 2026-05-17T10:00:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| #  | Truth                                                                                                   | Status     | Evidence                                                                                                                         |
|----|---------------------------------------------------------------------------------------------------------|------------|----------------------------------------------------------------------------------------------------------------------------------|
| 1  | User can authorize Gmail and Microsoft 365 accounts via OAuth2 and see them listed as connected inboxes | VERIFIED*  | `OAuthConnectController` + `OAuthCallbackController` dispatch via `match($provider)` to `GoogleOAuthProvider` / `MicrosoftOAuthProvider`; inboxes + inbox_scan_state inserted on callback; `/inboxes` page renders via `InboxesPage` SFC; OAuth wizard modal implemented for both providers. *End-to-end requires live OAuth — see human items.          |
| 2  | User can configure a 1-12 month backfill window; backfill runs as a queued background job               | VERIFIED*  | `BackfillWindowModal` SFC has 1-12 slider with server-side clamp; `BackfillInboxJob` implements `ShouldBeUnique` keyed on `inboxId`; dispatched via Livewire `Bus`; progress strip renders via `wire:poll.2s`. *Full end-to-end requires live credentials.                  |
| 3  | After kill + restart, scanner resumes from last successful UID per inbox+folder                         | VERIFIED*  | `IncrementalScanJob` reads `last_history_id` / `last_delta_link` from `inbox_scan_state` at job entry; cursor writes funnel through `InboxScanStateMachine::recordCursor()`; `noOtherInboxScanStateMutator` arch test enforces this invariant; `ResumeFromCursorTest` covers the behavior. *Live kill-restart test requires human.                            |
| 4  | Health view shows "last scan: X hours ago" per inbox; persistent failures surface with backoff retry    | VERIFIED*  | `/inboxes` page renders `lastScanAt` via `CarbonImmutable::diffForHumans()`; status badge matrix covers all 6 states; `email-scan-health-tile.blade.php` on dashboard; `InboxScanStateMachine::applyRateLimited` increments `retry_attempts`; `backoff = [60, 300, 900]` on jobs. *Observing real rate-limit requires human.                               |
| 5  | OAuth secrets and refresh tokens live in a chmod-600 file outside the database                          | VERIFIED   | `OAuthSecretsRepository::writeAtomic()` uses `chmod(0600)` + parent dir `chmod(0700)`; `SecretsWriteFailed` typed exception; `noOAuthTokensInEmailScanSchema` arch test enforces no token columns in DB; `PLT-03` structurally satisfied. Tests: `OAuthSecretsRepositoryTest`, `OAuthSecretsAtomicRotationTest`, `OAuthSecretsDirModeTest`.                |
| 6  | Background workers run via macOS launchd on login                                                       | VERIFIED*  | Three plists exist at `deploy/launchd/` (`com.diederik.horizon.plist`, `com.diederik.scheduler.plist`, `com.diederik.redis.plist`) with `{{ABS_PHP_BINARY}}` + `{{ABS_PROJECT_ROOT}}` placeholders; `InstallCommand --launchd` copies + bootstraps via `launchctl`; `InstallLaunchdCommandTest` covers the command. *Actual `launchctl load` requires the user's machine.    |
| 7  | Discovery feedback loop: user reviews discovered senders, promotes or dismisses                         | VERIFIED*  | `DiscoveryScanJob` runs daily, writes only sender metadata (no `.eml` blobs); `DiscoveredSenderQuery` surfaces candidates above 2-occurrence threshold; `/inboxes` discovered-senders panel with Add/Dismiss chips wired to `promoteSender()` / `dismissSender()` Livewire actions; `PromoteDiscoveredSender` inserts into `known_senders`; `DismissDiscoveredSender` transitions state. *Real discovery requires a live inbox.           |
| 8  | Modules/EmailScan is a bounded module with correct architecture invariants                              | VERIFIED   | Module registered in `bootstrap/providers.php`; 5 boundary rules in `BoundaryArchTest` (`noTransactionWritesFromEmailScan`, `noOtherInboxScanStateMutator`, `noOAuthTokensInEmailScanSchema`, internal containment, facade carve-outs); composer conflict block locks out webklex/ddeboer; `NoExtImapTest` covers composer.lock.                            |
| 9  | PLT-05 ext-imap ban holds; no transitive pulls of IMAP libraries                                        | VERIFIED   | `composer.json` conflict block present for `webklex/laravel-imap`, `webklex/php-imap`, `ddeboer/imap`; `NoExtImapTest` greps both `composer.lock` package names and PHP source; all 6 OAuth/MIME packages installed without IMAP transitive deps.                                                                                                            |
| 10 | Idempotency: re-fetching the same message is a no-op                                                    | VERIFIED   | `UNIQUE (inbox_id, provider_message_id)` on `inbox_messages` table; `BackfillInboxJob` and `IncrementalScanJob` use `insertOrIgnore`; pre-check skips `getRawMessage` API call if row already exists (WR-06 fix); `ReFetchIdempotentTest` covers UNIQUE constraint enforcement.                                                                               |

**Score:** 10/10 truths verified (8 require human confirmation for live behavior; 2 fully verifiable via codebase alone)

---

### Required Artifacts

| Artifact                                                                          | Expected                                                        | Status   | Details                                                                      |
|-----------------------------------------------------------------------------------|-----------------------------------------------------------------|----------|------------------------------------------------------------------------------|
| `Modules/EmailScan/composer.json`                                                 | Module manifest with PSR-4 + autoload-dev                       | VERIFIED | Exists; `name: diederik/email-scan`                                          |
| `Modules/EmailScan/Providers/EmailScanServiceProvider.php`                        | Service provider registered in bootstrap/providers.php           | VERIFIED | Exists; registered alphabetically                                            |
| `Modules/EmailScan/Database/Migrations/` (5 files)                               | Five user-scoped tables + enum triggers + system seeds           | VERIFIED | All 5 migrations present; UNIQUE constraints + trigger pairs confirmed        |
| `Modules/EmailScan/Models/` (5 models)                                            | Eloquent models with BelongsToUser trait                         | VERIFIED | Inbox, InboxScanState, InboxMessage, KnownSender, DiscoveredSender all exist |
| `Modules/EmailScan/Public/Dto/ScanCursor.php`                                     | Final readonly VO with gmail/microsoft/emptyFor factories        | VERIFIED | Exists with all three factory methods                                         |
| `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php`                    | Atomic chmod-600 JSON; only touchpoint for secrets file          | VERIFIED | Exists; `writeAtomic()` with flock+fsync+rename+chmod; `SecretsWriteFailed`  |
| `Modules/EmailScan/Internal/OAuth/GoogleOAuthProvider.php`                        | League OAuth2 wrapper with authorization URL + token exchange    | VERIFIED | Exists; `getAuthorizationUrl`, `exchangeAuthorizationCode`, `refreshAccessToken` |
| `Modules/EmailScan/Internal/OAuth/MicrosoftOAuthProvider.php`                     | Azure OAuth wrapper mirroring Google shape                       | VERIFIED | Exists; same method surface; `tenant=common` + `Mail.Read offline_access`    |
| `Modules/EmailScan/Internal/OAuth/OAuthStateRepository.php`                       | Per-flow random state with user_id binding (CR-01 fix)          | VERIFIED | Exists; `issueState(provider, userId, ...)` + `consumeState(..., currentUserId)` + `hash_equals` |
| `Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php`         | Invokable; state verify + code exchange + DB insert + secret save| VERIFIED | Exists; `match($provider)` dispatch; compensating-rollback (CR-02 fix)        |
| `Modules/EmailScan/Internal/Http/Controllers/OAuthConnectController.php`          | Invokable; issues state + redirects to consent URL               | VERIFIED | Exists; `match($provider)` dispatch                                           |
| `Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php`                        | /inboxes SFC with empty-state hero + table + discovery panel     | VERIFIED | Exists; `scanNow()`, `promoteSender()`, `dismissSender()` actions            |
| `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php`             | Google + Microsoft variants with validation                      | VERIFIED | Exists; Google: GOCSPX- prefix + publishedConfirmed; Microsoft: UUID v4       |
| `Modules/EmailScan/Internal/Http/Livewire/BackfillWindowModal.php`                | 1-12 month slider; dispatches BackfillInboxJob                   | VERIFIED | Exists; server-side clamp `max(1, min(12, $this->months))`                   |
| `Modules/EmailScan/Internal/EmlBlobStore.php`                                     | .eml blob store with slug hashing                                | VERIFIED | Exists; `MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._%=+\-]{1,512}$/'` (CR-03 fix); sha256 slug |
| `Modules/EmailScan/Internal/MimeHeaderParser.php`                                  | zbateson facade for header extraction                            | VERIFIED | Exists                                                                       |
| `Modules/EmailScan/Internal/Clients/GmailApiClient.php`                           | Real Gmail SDK wrapper                                           | VERIFIED | Exists; `GmailApiClientContract` bound in ServiceProvider                    |
| `Modules/EmailScan/Internal/Clients/GraphApiClient.php`                            | Real Graph SDK wrapper                                           | VERIFIED | Exists; `GraphApiClientContract` bound in ServiceProvider                    |
| `Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php`                       | Fixture-driven fake for tests                                    | VERIFIED | Exists with all Wave 0 methods                                               |
| `Modules/EmailScan/Internal/Clients/FakeGraphApiClient.php`                       | Fixture-driven fake for tests                                    | VERIFIED | Exists with all Wave 0 methods                                               |
| `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php`                             | ShouldBeUnique + chunked + .eml-then-DB ordering                 | VERIFIED | `ShouldBeUnique` (CR-05 fix); `EmlBlobStore` DI; `insertOrIgnore`            |
| `Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php`                           | ShouldBeUnique + cursor-expiry fallback + rate-limit backoff     | VERIFIED | `ShouldBeUnique`; `CursorExpiredException` catch + fallback; `applyRateLimited` |
| `Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php`                             | Daily; no .eml blobs; writes only discovered_senders             | VERIFIED | Exists; `ShouldBeUnique` keyed on `userId`                                   |
| `Modules/EmailScan/Internal/InboxScanStateMachine.php`                             | Sole mutator for inbox_scan_state + backfill_progress            | VERIFIED | `applyStatus()`, `recordCursor()`, `recordBackfillProgress()` all present    |
| `Modules/EmailScan/Internal/LoopbackRedirectUri.php`                               | Centralised loopback URI computation (WR-01 fix)                 | VERIFIED | Exists; `OAUTH_LOOPBACK_PORT` env var override                               |
| `Modules/EmailScan/Internal/SafeMessage.php`                                       | Shared message cap utility (IN-02 fix)                           | VERIFIED | Exists as static utility class                                               |
| `Modules/EmailScan/Public/Services/InboxQuery.php`                                 | forCurrentUser + findForUser                                     | VERIFIED | Exists                                                                       |
| `Modules/EmailScan/Public/Services/InboxesBadgeCount.php`                          | Single correlated subquery (IN-06 fix)                           | VERIFIED | `selectOne()` with two correlated subqueries                                 |
| `Modules/EmailScan/Public/Services/KnownSenderQuery.php`                           | all(User): array<KnownSenderDto>                                 | VERIFIED | Exists                                                                       |
| `Modules/EmailScan/Public/Services/InboxMessageQuery.php`                          | forStatus(string): iterable<InboxMessageDto>                     | VERIFIED | Exists                                                                       |
| `Modules/EmailScan/Public/Services/DiscoveredSenderQuery.php`                      | candidatesForUser with JOIN isolation (WR-04 fix)                | VERIFIED | Exists; JOIN on `(inboxes.id, inboxes.user_id)`                              |
| `Modules/EmailScan/Public/Actions/PromoteDiscoveredSender.php`                     | Inserts known_senders + transitions discovered to 'added'        | VERIFIED | Exists; confirmed via grep                                                   |
| `Modules/EmailScan/Public/Actions/DismissDiscoveredSender.php`                     | Transitions discovered to 'dismissed'                            | VERIFIED | Exists                                                                       |
| `Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php`               | Full /inboxes page                                               | VERIFIED | Empty-state hero + table + Scan-Now + Reconnect + discovered senders panel   |
| `Modules/EmailScan/Resources/views/livewire/oauth-client-wizard-modal.blade.php`   | Google + Microsoft variants (wire:model.blur on secret)          | VERIFIED | `wire:model.blur="clientSecret"` confirmed (CR-04 fix)                        |
| `Modules/EmailScan/Resources/views/livewire/email-scan-health-tile.blade.php`      | Dashboard tile with per-inbox status lines                       | VERIFIED | Exists; `diffForHumans()` for lastScanAt                                     |
| `Modules/EmailScan/Resources/views/livewire/backfill-window-modal.blade.php`       | 1-12 month slider                                                | VERIFIED | Exists                                                                       |
| `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php`                      | emailScanHealth(User): ?EmailScanHealthTile extension            | VERIFIED | Method exists at line 292                                                    |
| `Modules/Core/Resources/views/livewire/top-nav.blade.php`                          | Inboxes link with numeric badge                                  | VERIFIED | `route('inboxes.index')` + `$inboxesBadgeCount` confirmed                    |
| `Modules/Core/Resources/views/livewire/dashboard.blade.php`                        | Email scan health tile included                                  | VERIFIED | `@include('email-scan::livewire.email-scan-health-tile')` confirmed           |
| `Modules/Core/Internal/Console/InstallCommand.php`                                  | Extended with --launchd option                                   | VERIFIED | `{--launchd}` option; `{{ABS_PHP_BINARY}}` + `launchctl bootstrap`           |
| `deploy/launchd/com.diederik.horizon.plist`                                         | Horizon worker plist with PHP_BINARY placeholder                 | VERIFIED | Exists; `{{ABS_PHP_BINARY}}` placeholder confirmed                           |
| `deploy/launchd/com.diederik.scheduler.plist`                                       | Scheduler worker plist                                           | VERIFIED | Exists                                                                       |
| `deploy/launchd/com.diederik.redis.plist`                                           | Optional Redis plist                                             | VERIFIED | Exists                                                                       |
| `routes/console.php`                                                                 | Hourly IncrementalScanJob + daily DiscoveryScanJob scheduled     | VERIFIED | Both schedule entries confirmed at lines 34 + 51                             |
| `tests/Contracts/BoundaryArchTest.php`                                               | Phase 6 boundary rules                                           | VERIFIED | 5 rules: noTransactionWritesFromEmailScan, noOtherInboxScanStateMutator, noOtherBackfillProgressMutator, noOAuthTokensInEmailScanSchema, internal containment |
| `tests/Contracts/NoExtImapTest.php`                                                  | ext-imap + webklex/ddeboer package ban                           | VERIFIED | Third test greps composer.lock for all three banned package names            |
| `tests/Contracts/UserIdColumnArchTest.php`                                           | Covers 5 new Phase 6 tables                                      | VERIFIED | discovered_senders, inbox_messages, inbox_scan_state, inboxes, known_senders |

---

### Key Link Verification

| From                                        | To                                              | Via                                           | Status   | Details                                                              |
|---------------------------------------------|-------------------------------------------------|-----------------------------------------------|----------|----------------------------------------------------------------------|
| `bootstrap/providers.php`                   | `EmailScanServiceProvider`                      | `EmailScanServiceProvider::class` in array    | VERIFIED | Confirmed present                                                    |
| `tests/Pest.php`                            | `Modules\EmailScan\Tests\TestCase`              | foreach map entry at line 26                  | VERIFIED | `'Modules/EmailScan' => ...TestCase::class`                          |
| `Modules/EmailScan/Routes/web.php`          | `GET /oauth/callback/{provider}`                | `OAuthCallbackController::class`              | VERIFIED | Route registered at line 30                                          |
| `OAuthCallbackController`                   | `inboxes` table                                 | `table('inboxes')->insertGetId`               | VERIFIED | Insert inside DB transaction                                         |
| `OAuthCallbackController`                   | `OAuthSecretsRepository::saveInboxRefreshToken` | Call after DB commit with compensating rollback| VERIFIED | CR-02 fix: compensating-delete on `SecretsWriteFailed`               |
| `OAuthClientWizardModal::submit()`          | `OAuthSecretsRepository::saveProviderClient`    | Direct call; client_secret wiped after save   | VERIFIED | CR-04 fix: wipe-after-submit; `wire:model.blur`                      |
| `BackfillInboxJob`                          | `EmlBlobStore::put()`                           | Constructor DI; .eml-write-before-DB ordering | VERIFIED | `EmlBlobStore` injected; `$blobStore->put()` before `insertOrIgnore` |
| `BackfillInboxJob`                          | `inbox_messages` UNIQUE constraint              | `insertOrIgnore` on every message             | VERIFIED | `insertOrIgnore` confirmed                                           |
| `BackfillWindowModal`                       | `BackfillInboxJob`                              | `$bus->dispatch(new BackfillInboxJob(...))`   | VERIFIED | Line 91 confirmed                                                    |
| `IncrementalScanJob`                        | `InboxScanStateMachine::applyStatus()`          | Constructor DI; every status transition       | VERIFIED | `applyStatus` calls at lines 222, 253, 262, 267, 275                |
| `IncrementalScanJob`                        | `CursorExpiredException` fallback               | `catch(CursorExpiredException)` + fallback    | VERIFIED | Gmail + Graph fallback paths confirmed                               |
| `routes/console.php`                        | `IncrementalScanJob` hourly                     | `Schedule::call` closure dispatching per-inbox| VERIFIED | Confirmed at line 34                                                 |
| `routes/console.php`                        | `DiscoveryScanJob` daily                        | `Schedule::call` closure dispatching per-user | VERIFIED | Confirmed at line 51                                                 |
| `EmailScanServiceProvider::boot()`          | `core::livewire.top-nav` View composer          | `ViewFactoryContract->composer(...)` binding  | VERIFIED | `registerTopNavBadgeComposer()` at line 127 confirmed                |
| `Dashboard::render()`                       | `ThisPeriodAtAGlanceQuery::emailScanHealth()`   | render() reads DTO + passes to Blade partial  | VERIFIED | emailScanHealth() method exists at line 292                          |
| `BackfillInboxJob`                          | `InboxScanStateMachine::recordCursor()`         | End-of-backfill cursor baseline write         | VERIFIED | Funnel confirmed; `noOtherInboxScanStateMutator` arch test enforces  |
| `BackfillInboxJob`                          | `InboxScanStateMachine::recordBackfillProgress()`| Progress writes via state machine (IN-03 fix) | VERIFIED | `recordBackfillProgress()` method confirmed; arch test guard added   |
| `OAuthStateRepository`                      | user_id binding on state issue/consume          | issueState passes userId; consumeState checks | VERIFIED | CR-01 fix confirmed                                                  |
| `InboxesPage::scanNow()`                    | `IncrementalScanJob`                            | `$bus->dispatch(new IncrementalScanJob($id))` | VERIFIED | Confirmed at line 156                                                |
| `PromoteDiscoveredSender`                   | `known_senders` table insert                    | `table('known_senders')->insert([...])`       | VERIFIED | Insert + state transition to 'added' confirmed                       |

---

### Data-Flow Trace (Level 4)

| Artifact                                       | Data Variable       | Source                                      | Produces Real Data | Status  |
|------------------------------------------------|---------------------|---------------------------------------------|--------------------|---------|
| `InboxesPage` (connected inboxes table)        | `$inboxes`          | `InboxQuery::forCurrentUser()` → DB JOIN    | Yes — DB query     | FLOWING |
| `InboxesPage` (discovery panel)                | `$discoveredSenders`| `DiscoveredSenderQuery::candidatesForUser()`| Yes — DB query + JOIN | FLOWING |
| `dashboard.blade.php` (email scan tile)        | `$emailScanHealth`  | `ThisPeriodAtAGlanceQuery::emailScanHealth()`| Yes — DB query    | FLOWING |
| `top-nav.blade.php` (Inboxes badge)            | `$inboxesBadgeCount`| `InboxesBadgeCount::forCurrentUser()` → selectOne | Yes — DB query | FLOWING |
| `email-scan-health-tile.blade.php`             | `$tile->lines`      | `EmailScanHealthTile` DTO from query        | Yes — DB-backed DTO| FLOWING |
| `BackfillWindowModal`                          | Dispatched job      | `Bus::dispatch(new BackfillInboxJob(...))`  | Yes — dispatches real job | FLOWING |

---

### Behavioral Spot-Checks

| Behavior                                                    | Command                                                                          | Result                                | Status |
|-------------------------------------------------------------|----------------------------------------------------------------------------------|---------------------------------------|--------|
| EmailScan module registers without error                    | `php -r "require 'vendor/autoload.php'; new ReflectionClass(...)"`              | Classes autoloadable                  | PASS   |
| composer.json has conflict block for IMAP packages          | `grep -n "webklex" composer.json`                                                | Lines 29-31 confirmed                 | PASS   |
| Six OAuth/MIME packages present in composer.json require    | `grep google/apiclient composer.json`                                            | All 6 confirmed at lines 11,16,17,20,25,26 | PASS |
| .eml fixtures with Q-encoded subject exist                  | `grep -c "=?UTF-8?Q?" eml/paypal/sample-receipt.eml`                            | Returns 1                             | PASS   |
| 5 migrations exist                                          | `ls Modules/EmailScan/Database/Migrations/`                                      | 5 files confirmed                     | PASS   |
| 3 launchd plists exist                                      | `ls deploy/launchd/`                                                             | 3 plists confirmed                    | PASS   |
| ShouldBeUnique (not UntilProcessing) on all 3 jobs          | `grep "ShouldBeUnique" Jobs/BackfillInboxJob.php`                                | `implements ShouldBeUnique` confirmed | PASS   |
| OAuthStateRepository binds user_id in session entry         | `grep "user_id" OAuthStateRepository.php`                                        | `'user_id' => $userId` at line 59     | PASS   |
| EmlBlobStore accepts wide pattern for Graph IDs             | `grep MESSAGE_ID_PATTERN EmlBlobStore.php`                                       | `/^[A-Za-z0-9._%=+\-]{1,512}$/`     | PASS   |
| clientSecret uses wire:model.blur not live                  | `grep "wire:model.blur.*clientSecret" oauth-client-wizard-modal.blade.php`       | Lines 110, 192 confirmed              | PASS   |
| Hourly + daily scheduler entries in routes/console.php      | `grep "IncrementalScanJob\|DiscoveryScanJob" routes/console.php`                 | Both imports + dispatches confirmed   | PASS   |
| top-nav Inboxes link wired to inboxes.index route           | `grep "inboxes.index" top-nav.blade.php`                                         | Line 37 confirmed                     | PASS   |
| emailScanHealth() method on ThisPeriodAtAGlanceQuery        | `grep "emailScanHealth" ThisPeriodAtAGlanceQuery.php`                            | Lines 14, 292, 374 confirmed          | PASS   |
| Jobs have public function failed() method                   | `grep "public function failed" BackfillInboxJob.php`                             | Line 634 confirmed                    | PASS   |
| SafeMessage utility class exists                            | `cat SafeMessage.php`                                                            | File readable, correct class          | PASS   |

---

### Probe Execution

Step 7c: SKIPPED — no conventional `scripts/*/tests/probe-*.sh` probes found; phase is not a migration/tooling phase. Behavioral spot-checks above cover the equivalent verification.

---

### Requirements Coverage

| Requirement | Source Plan(s) | Description                                                                      | Status       | Evidence                                                                         |
|-------------|----------------|----------------------------------------------------------------------------------|--------------|----------------------------------------------------------------------------------|
| EML-01      | 06-03          | User can authorize a Gmail account via OAuth2                                    | SATISFIED    | `GoogleOAuthProvider` + `OAuthCallbackController` gmail branch; wizard modal     |
| EML-02      | 06-04, 06-06   | User can authorize a Microsoft 365 account via OAuth2                            | SATISFIED    | `MicrosoftOAuthProvider` + controller microsoft branch; wizard UUID validation    |
| EML-03      | 06-01 through 06-09 | Multiple inboxes of each provider type; scanner runs against all           | SATISFIED    | `inboxes` table is multi-row; scheduler iterates all connected inboxes           |
| EML-04      | 06-05, 06-06   | 1-12 month backfill window; queued background job                                | SATISFIED    | `BackfillWindowModal` slider; `BackfillInboxJob` implementation                  |
| EML-06      | 06-07          | Scan state persisted per-inbox per-provider; incremental scans resume cleanly    | SATISFIED    | `inbox_scan_state` table; `InboxScanStateMachine::recordCursor()`                |
| EML-08      | 06-07, 06-08   | Rate-limit failures retry with exponential backoff; surface in health view       | SATISFIED    | `backoff=[60,300,900]`; `applyRateLimited()`; status badge matrix on /inboxes   |
| PLT-03      | 06-02          | OAuth2 credentials live in chmod-600 config file outside the DB                  | SATISFIED    | `OAuthSecretsRepository`; `noOAuthTokensInEmailScanSchema` arch test             |
| PLT-04      | 06-09          | Background workers run via macOS launchd; health visible in UI                   | SATISFIED*   | 3 plists + `--launchd` artisan option; health tile on dashboard. *launchctl load requires human |

All 8 Phase 6 requirement IDs (EML-01, EML-02, EML-03, EML-04, EML-06, EML-08, PLT-03, PLT-04) are claimed by plans and have implementation evidence. No orphaned requirements found.

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| No TBD/FIXME/XXX/HACK/PLACEHOLDER markers found in Phase 6 files | — | — | — | — |

Full grep over the EmailScan module and touched files confirmed zero unresolved debt markers. The `WR-05` warning in the review (Sleep::sleep cooperative-shutdown trade-off) was resolved by documenting in a class docblock per the reviewer's guidance — no code stub left behind.

---

### Human Verification Required

#### 1. Gmail OAuth End-to-End (SC#1 — Gmail path)

**Test:** Create a Google Cloud project, enable Gmail API, configure OAuth consent screen, push to "In production", add `http://127.0.0.1:8000/oauth/callback/gmail` as redirect URI, paste `client_id` (ends in `.apps.googleusercontent.com`) + `client_secret` (starts with `GOCSPX-`) + check `publishedConfirmed`, click Submit.
**Expected:** Modal closes, browser redirects to Google consent screen, consent granted, browser lands at `/inboxes` with backfill window modal open, Gmail inbox row visible with email + idle status.
**Why human:** Requires a real GCP project and live Google IdP; the loopback `http://127.0.0.1:PORT` scheme requires an actual browser redirect.

#### 2. Microsoft 365 OAuth End-to-End (SC#1 — Microsoft path)

**Test:** Register Azure App at `https://entra.microsoft.com/`, add redirect URI, grant Mail.Read + offline_access, create client secret, paste UUID client_id + secret into the wizard modal, click "Save and connect".
**Expected:** Modal closes, browser redirects to Microsoft consent screen, consent granted, `/inboxes` shows Microsoft inbox with email + idle status.
**Why human:** Requires a real Azure App Registration and live Microsoft IdP.

#### 3. Kill-and-Restart Resume (SC#3)

**Test:** Connect a Gmail inbox, start a backfill, kill the Horizon worker process mid-backfill (`kill -9 <pid>`), restart the worker, observe that scanning resumes from the last persisted cursor without re-fetching already-indexed messages.
**Expected:** `inbox_messages` row count continues to increase from where it stopped; no duplicate rows created; `last_history_id` / `last_delta_link` in `inbox_scan_state` reflects the resumed position.
**Why human:** Requires a live Horizon worker and a real inbox with messages.

#### 4. Backfill Progress Strip (SC#2)

**Test:** After connecting an inbox, pick a 3-month window in the backfill modal, watch `/inboxes` (wire:poll.2s), observe the progress strip "Backfilling Gmail: N / ~M messages" count up, confirm it disappears when backfill completes, and N inbox_messages rows + N .eml blobs exist under `storage/app/inbox/`.
**Expected:** Real-time progress visible; final count matches fetched emails from known senders.
**Why human:** Requires live credentials and a running Horizon worker.

#### 5. Health View "Last Scan: X Hours Ago" (SC#4)

**Test:** After IncrementalScanJob completes successfully, visit `/inboxes` and the dashboard. Confirm each inbox row shows accurate human-readable last-scan time (e.g. "last scanned 2 hours ago") and the dashboard tile reflects the same.
**Expected:** `lastScanAt` populated in `inbox_scan_state`; `diffForHumans()` rendering correctly.
**Why human:** Requires a completed real scan run to populate `last_scan_at`.

#### 6. Rate-Limit Backoff Visible in Health View (SC#4)

**Test:** Trigger or simulate a rate-limit event (Gmail quota exhaustion or Graph 429 response) and observe the inbox status transition to `rate_limited` with the retry_attempts count and error message surfaced on `/inboxes`.
**Expected:** Status badge shows "Rate limited"; retry_attempts increments on each attempt; Horizon honours [60, 300, 900] backoff schedule.
**Why human:** Cannot safely exhaust real quota in CI; simulation via integration test covers code path but not the Horizon backoff timer.

#### 7. macOS launchd Auto-Start (SC#5)

**Test:** Run `php artisan diederik:install --launchd`, verify plists are copied to `~/Library/LaunchAgents/` with correct PHP_BINARY and project root substituted, grant any required macOS permissions (Full Disk Access for Terminal), log out and back in, verify `launchctl list | grep diederik` shows horizon + scheduler plists loaded.
**Expected:** Background workers start automatically on macOS login without manual `php artisan` invocations.
**Why human:** Requires the user's macOS machine with launchctl; permission grants cannot be automated.

#### 8. Discovery Loop End-to-End

**Test:** Connect a real inbox that has received emails from unknown senders with receipt-like subjects (e.g. "receipt", "invoice", "betaling"). Wait for the daily DiscoveryScanJob to run (or dispatch it manually via `php artisan queue:work`). Visit `/inboxes` and observe candidate senders appearing in the discovered-senders panel above the 2-occurrence threshold. Click "Add" for a sender; confirm a `known_senders` row appears with `source='user'`. Click "Dismiss" for another; confirm subsequent discovery job excludes it.
**Expected:** Discovery loop populates candidate rows; promotion and dismissal work correctly; dismissed senders do not reappear.
**Why human:** Requires a real inbox with appropriate email history over a 90-day window.

---

### Gaps Summary

No automated gaps found. All must-have truths, artifacts, and key links are verified as present, substantive, and wired in the codebase.

The 20 code-review findings (5 critical, 9 warning, 6 info) were all resolved in 21 atomic fix commits before this verification was run. The test suite went from 919 to 956 passing tests (+37 tests added by fixes). Larastan level 10 strict + Pint formatting reported clean.

The 8 human verification items above represent behaviors that are architecturally sound per codebase inspection but require live OAuth credentials and a running Horizon environment to confirm end-to-end. These are the inherent nature of OAuth-based features rather than implementation gaps.

---

_Verified: 2026-05-17T10:00:00Z_
_Verifier: Claude (gsd-verifier)_
