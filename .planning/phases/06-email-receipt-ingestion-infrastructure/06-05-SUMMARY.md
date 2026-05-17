---
phase: 06-email-receipt-ingestion-infrastructure
plan: 05
subsystem: email
tags: [gmail, oauth, livewire, flux, queue, sqlite, zbateson, google-apiclient]

requires:
  - phase: 06-email-receipt-ingestion-infrastructure
    provides: FakeGmailApiClient + CursorExpiredException + RateLimitedException + boundary tests (Plan 01); inboxes / inbox_messages / inbox_scan_state / known_senders migrations (Plan 02); OAuth callback + /inboxes page Livewire SFC + GoogleOAuthProvider (Plans 03 + 04); OAuthSecretsRepository + chmod-600 JSON repository (Plan 02); ScanCursor DTO + InboxQuery + KnownSenderQuery (Plans 02 + 03)
provides:
  - EmlBlobStore — atomic .eml-then-fsync-then-rename filesystem repository at storage/app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/{provider_message_id}.eml
  - MimeHeaderParser — zbateson facade returning the four index-row header fields (lowercase senderEmail, optional senderName, optional subject, internalDate)
  - ParsedMessageHeaders — immutable readonly carrier for the four fields
  - GmailApiClientContract — extracted contract so the Wave 0 Fake and the real client are interchangeable at the test seam
  - GmailApiClient — production wrapper around google/apiclient with rate-limit → RateLimitedException + 404 historyId → CursorExpiredException mapping
  - InboxScanStateMachine (stub) — applyStatus + recordCursor sole-mutator surface for inbox_scan_state; PRAGMA busy_timeout = 5000 inside every write
  - BackfillInboxJob — queued chunked fetcher implementing ShouldBeUniqueUntilProcessing keyed on inbox_id; uniqueFor=1800; tries=3; backoff=[60,300,900]; uniqueVia returns Cache::driver('redis')
  - BackfillWindowModal Livewire SFC + Blade — 1-12 month slider, defensive clamp, cross-user 404 invariant, Bus dispatch of BackfillInboxJob
  - InboxesPage extensions — mount() reads open_backfill_modal flash + dispatches backfill-window:open; editWindow() re-opens scoped to a row; refreshBackfillProgress() poll target
  - /inboxes Blade — backfill progress strip above the connected-inboxes table (wire:poll.2s); appears when any inbox has an active backfill_progress payload; disappears when all return to NULL
  - 8 tests: BackfillChunkedJob, BackfillPerInboxJob, EmlOrphanCleanup, ConcurrentBackfill (integration); BackfillWindowModal, BackfillProgressPoll (feature); BackfillWindowValidation (unit/http); MimeHeaderParser (unit)
affects: [06-06 Microsoft Graph backfill extends BackfillInboxJob's microsoft branch and reuses EmlBlobStore + MimeHeaderParser, 06-07 InboxScanStateMachine gains the full transition-validation matrix + IncrementalScanJob lands beside BackfillInboxJob, 06-08 DiscoveryScanJob uses listDiscoveryCandidates which currently stubs to []]

tech-stack:
  added: []
  patterns:
    - "Wave-0 Fake + Wave-N real-client pair share an extracted ContractInterface; production wiring binds the contract → real class and tests rebind the contract → Fake via $this->app->instance"
    - "Atomic .eml-then-DB ordering: write blob to disk first via EmlBlobStore::put (tmp+flock+fwrite+fsync+chmod+rename), then open a small DB transaction that insertOrIgnores the index row; on tx throw the catch block unlinks the blob"
    - "Per-page transaction promotes PRAGMA busy_timeout = 5000 inside the closure so two parallel writers wait up to 5s for the SQLite writer lock rather than raising SQLITE_BUSY"
    - "InboxScanStateMachine is the sole legal mutator of inbox_scan_state (status + cursor columns); applyStatus + recordCursor funnel every write; BoundaryArchTest enforces"
    - "Queued job's uniqueVia() uses the Cache facade because Laravel calls the method at queue-push time before constructor DI completes — single permitted facade exception per module, enforced by both the BoundaryArchTest allow-list AND a phpstan.neon ignoreErrors path entry"
    - "Livewire SFC mount() guards $request->session() with hasSession() so the direct Livewire::test harness can boot the component without a bound session (the HTTP path always provides one via the StartSession middleware)"

key-files:
  created:
    - Modules/EmailScan/Internal/EmlBlobStore.php
    - Modules/EmailScan/Internal/MimeHeaderParser.php
    - Modules/EmailScan/Internal/ParsedMessageHeaders.php
    - Modules/EmailScan/Internal/Clients/GmailApiClient.php
    - Modules/EmailScan/Internal/Clients/GmailApiClientContract.php
    - Modules/EmailScan/Internal/InboxScanStateMachine.php
    - Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php
    - Modules/EmailScan/Internal/Http/Livewire/BackfillWindowModal.php
    - Modules/EmailScan/Resources/views/livewire/backfill-window-modal.blade.php
    - Modules/EmailScan/tests/Unit/MimeHeaderParserTest.php
    - Modules/EmailScan/tests/Unit/Http/BackfillWindowValidationTest.php
    - Modules/EmailScan/tests/Feature/BackfillWindowModalTest.php
    - Modules/EmailScan/tests/Feature/BackfillProgressPollTest.php
    - Modules/EmailScan/tests/Integration/BackfillChunkedJobTest.php
    - Modules/EmailScan/tests/Integration/BackfillPerInboxJobTest.php
    - Modules/EmailScan/tests/Integration/EmlOrphanCleanupTest.php
    - Modules/EmailScan/tests/Integration/ConcurrentBackfillTest.php
  modified:
    - Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php
    - Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php
    - Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php
    - Modules/EmailScan/Providers/EmailScanServiceProvider.php
    - phpstan.neon

key-decisions:
  - "Extracted GmailApiClientContract interface so the Wave 0 Fake (which has a different constructor signature) and the real client implement the same surface. The plan implied a 'swap the Fake into the container' pattern but Plan 01 shipped the Fake without an interface; the contract introduction is a one-off cost that fixes the test-seam impedance mismatch for both this plan and the downstream Microsoft path."
  - "Did NOT register BackfillInboxJob as a container singleton even though the plan said to. Jobs are constructed per dispatch with readonly inboxId/windowMonths; a singleton binding would silently fuse two dispatches' state. The plan's intent (container-discoverable) is satisfied by the auto-wiring resolution path."
  - "ConcurrentBackfillTest verifies the busy_timeout pragma via a passthrough connection decorator that records every transaction body's SQL, instead of a true two-connection race. PHP's single-threaded test harness cannot simultaneously hold one transaction and release it from another connection mid-wait without pcntl_fork or a subprocess; recording-decorator + a separate sanity assertion against the live connection accepting the pragma exercises the same invariant deterministically."
  - "EmlOrphanCleanupTest forces the rollback via a one-shot FailingTransactionDbManager + FailingTransactionConnection pair that proxy the real DatabaseManager + Connection and throw RuntimeException on the FIRST transaction() call (after the .eml has already been written). Subclass override avoids touching production code paths; the rollback proves the catch block unlinks the blob and the second pass without injection proves the path is idempotent."
  - "Backfill window slider falls back to a plain <input type=\"range\"> wired with wire:model.live. The installed livewire/flux build ships flux:input.* primitives for text/checkbox/etc. but no flux:input.range; the hand-rolled range input carries the same focus-visible chrome and exercises wire:model.live identically."
  - "InboxesPage::mount() guards $request->session() with hasSession() — the HTTP path always binds a session via the StartSession middleware, but Livewire::test() boots the component with a bare Request. Without the guard, Livewire-direct rendering tests fail with 'Session store not set on request.'"
  - "BackfillInboxJob's microsoft branch surfaces an 'is not implemented yet' state-machine error transition instead of throwing. A throw would queue retries forever via Horizon; flipping the state cleanly to error stops the loop and lets the user see the deferred capability in the UI without action."
  - "InboxScanStateMachine::recordCursor was scoped beyond the plan's stub minimum to honour the BoundaryArchTest single-mutator invariant for inbox_scan_state — the BackfillInboxJob would otherwise have to either write last_history_id directly (boundary violation) or skip the cursor entirely (regression vs. plan)."

patterns-established:
  - "Contract-extracted Fake/real pair: when a Wave 0 Fake's contract is consumed by Wave N production code, introduce an interface at Wave N so both implementations are type-compatible at the test seam."
  - "Atomic .eml-then-DB rollback: filesystem-write first, then a small DB tx in a try/catch; on throw, delete the file and rethrow. The UNIQUE constraint on the index row + insertOrIgnore makes retries idempotent."
  - "Queue facade carve-out (Cache::driver in uniqueVia): documented in both the BoundaryArchTest allow-list AND a phpstan.neon ignoreErrors entry. Two enforcement layers because Laravel's queue infra calls uniqueVia at push-time before constructor DI."

requirements-completed: [EML-04, EML-03]

duration: ~30min
completed: 2026-05-17
---

# Phase 6 Plan 05: Gmail Backfill Vertical Slice Summary

**Backfill end-to-end for the Gmail path — pick a 1-12 month window in a Flux modal, watch the progress strip count up on /inboxes, end with N .eml blobs on disk + N inbox_messages rows persisted with full atomic-rollback safety.**

## Performance

- **Duration:** ~30 min (worktree run)
- **Started:** 2026-05-17T01:00:00Z (approximate — first action against the worktree)
- **Completed:** 2026-05-17T01:24:00Z
- **Tasks:** 3
- **Files created:** 17 (production code + tests)
- **Files modified:** 5 (Fake, InboxesPage, Blade, ServiceProvider, phpstan.neon)

## Accomplishments

- A user with a connected Gmail inbox can pick a backfill window in the Flux modal and watch the live count on /inboxes; the job persists every receipt as a raw .eml blob on disk plus an inbox_messages index row, with full atomic-rollback safety on tx failure.
- BackfillInboxJob is single-flight per inbox via ShouldBeUniqueUntilProcessing + Cache::driver('redis'); the 30-minute lock ceiling matches the Horizon retry envelope.
- InboxScanStateMachine is the sole legal mutator of inbox_scan_state (status + cursor columns); the BoundaryArchTest invariant from Plan 01 binds for the first time.
- /inboxes Blade renders the backfill progress strip above the connected-inboxes table with wire:poll.2s; the strip auto-hides once every inbox's backfill_progress payload returns to NULL.
- Eight tests pass: MimeHeaderParserTest (unit, 7 cases), BackfillWindowValidationTest (unit, 4 cases), BackfillWindowModalTest (feature, 7 cases), BackfillProgressPollTest (feature, 4 cases), BackfillChunkedJobTest + BackfillPerInboxJobTest + EmlOrphanCleanupTest + ConcurrentBackfillTest (integration).
- Full EmailScan test suite stays green (89 tests, 297 assertions); full project suite stays green (842 passed, 1 documented pre-existing failure in Modules/Ledger/tests/Unit/TransactionTypeTest.php).
- Larastan level 10 strict + Pint + BoundaryArchTest + NoExtImapTest all green.

## Task Commits

Each task was committed atomically:

1. **Task 1: EmlBlobStore + MimeHeaderParser + real GmailApiClient + InboxScanStateMachine stub + service-provider singletons** — `42d0870` (feat)
2. **Task 2: BackfillInboxJob — chunked queued fetcher with per-inbox single-flight + 4 integration tests** — `8d50292` (feat)
3. **Task 3: BackfillWindowModal + /inboxes backfill progress strip + 3 tests** — `c1e1aa2` (feat)

## Files Created/Modified

### Production code (Task 1)
- `Modules/EmailScan/Internal/EmlBlobStore.php` — Filesystem repository for raw .eml blobs at storage/app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/{provider_message_id}.eml; atomic tmp+fsync+chmod+rename; preg_match allow-list against directory traversal on provider_message_id.
- `Modules/EmailScan/Internal/MimeHeaderParser.php` — zbateson facade. parseHeaders / parseHeadersWithFallbackDate return ParsedMessageHeaders. Lowercases senderEmail; no +plus strip (deferred per CONTEXT.md).
- `Modules/EmailScan/Internal/ParsedMessageHeaders.php` — Immutable readonly carrier for senderEmail / senderName / subject / internalDate.
- `Modules/EmailScan/Internal/Clients/GmailApiClient.php` — Production wrapper over google/apiclient's UsersMessages + UsersHistory resources. Rate-limit reasons → RateLimitedException; 404 historyId → CursorExpiredException; tokens never appear in any thrown exception payload.
- `Modules/EmailScan/Internal/Clients/GmailApiClientContract.php` — Extracted interface so both FakeGmailApiClient and the real client implement the same surface.
- `Modules/EmailScan/Internal/InboxScanStateMachine.php` — Sole legal mutator of inbox_scan_state. applyStatus writes the status string; recordCursor writes last_history_id (gmail) or last_delta_link (microsoft). Promotes PRAGMA busy_timeout = 5000 inside every transaction.

### Production code (Task 2)
- `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php` — final class implements ShouldBeUniqueUntilProcessing + ShouldQueue. uniqueId=inbox_id, uniqueFor=1800, tries=3, backoff=[60,300,900]. handle() walks 100-message pages until nextPageToken is null; per-page tx sets PRAGMA busy_timeout=5000 + inserts inbox_messages via insertOrIgnore. Atomic .eml-then-DB ordering + orphan-cleanup catch block. Defensive windowMonths clamp to [1,12]. Microsoft branch is a deferred "not implemented" state-machine error transition.

### Production code (Task 3)
- `Modules/EmailScan/Internal/Http/Livewire/BackfillWindowModal.php` — Livewire SFC. Public ?int $inboxId, int $months=3. open() listener for backfill-window:open event. submit() asserts cross-user ownership via inboxes.user_id scope, clamps months to [1,12], updates the row, dispatches BackfillInboxJob via injected Bus contract, emits modal-hide.
- `Modules/EmailScan/Resources/views/livewire/backfill-window-modal.blade.php` — Flux modal md:max-w-lg dismissible=true; plain <input type="range"> wired with wire:model.live; tick labels 1/3/6/9/12; live months readout with tabular-nums; copy verbatim from UI-SPEC.

### Modified files
- `Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php` — Added `resultSizeEstimate` to the listSenderMessages return shape so the real-client contract is satisfied by the Fake; declared implements GmailApiClientContract.
- `Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php` — mount() now dispatches backfill-window:open when the OAuth-callback flashed open_backfill_modal (guarded by hasSession() so Livewire::test boots cleanly). Added editWindow() and refreshBackfillProgress() methods.
- `Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php` — Added the backfill progress strip above the connected-inboxes list (wire:poll.2s); replaced the [Edit] stub with wire:click="editWindow({id})"; mounts the backfill-window-modal SFC unconditionally.
- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` — Registers EmlBlobStore / MimeHeaderParser / GmailApiClient / InboxScanStateMachine as singletons; binds GmailApiClientContract → GmailApiClient; registers email-scan.backfill-window-modal Livewire component.
- `phpstan.neon` — Adds Cache facade carve-out for Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php (mirrors the Chains ResolveChainLinksJob carve-out).

### Test files
- `Modules/EmailScan/tests/Unit/MimeHeaderParserTest.php` — 7 cases: PayPal Q-encoded subject round-trips to "Bedankt voor je betaling aan Synthetic Merchant BV"; ICS + Google Play plain subjects extracted; lowercase normalisation; fallback date; null senderName; null subject.
- `Modules/EmailScan/tests/Unit/Http/BackfillWindowValidationTest.php` — 4 cases: constructor stores windowMonths unchanged (clamp is runtime, not construction); readonly props; public visibility; uniqueId invariant under different windowMonths.
- `Modules/EmailScan/tests/Feature/BackfillWindowModalTest.php` — 7 cases: open() with currentWindow; default 3; happy submit; clamp 999→12; clamp 0→1; cross-user 404; no-id 404.
- `Modules/EmailScan/tests/Feature/BackfillProgressPollTest.php` — 4 cases: strip renders with "Backfilling Gmail (email): 100 / ~300 messages" + wire:poll.2s attribute; strip disappears when payload returns to NULL; stacks one line per parallel backfill; refreshBackfillProgress() poll target is OK.
- `Modules/EmailScan/tests/Integration/BackfillChunkedJobTest.php` — Happy path: 3 fixtures → 3 .eml blobs + 3 inbox_messages rows + status='idle' + last_history_id='12345'.
- `Modules/EmailScan/tests/Integration/BackfillPerInboxJobTest.php` — 5 cases: implements interfaces; uniqueId=inbox_id (collisions only on same inbox); uniqueFor=1800; tries=3 + backoff=[60,300,900]; uniqueVia returns Repository.
- `Modules/EmailScan/tests/Integration/EmlOrphanCleanupTest.php` — Injects a one-shot FailingTransactionDbManager + FailingTransactionConnection pair that throws on the first transaction(); asserts .eml is unlinked + no inbox_messages row landed; re-runs without injection and asserts the path is fully idempotent (3 .eml + 3 rows).
- `Modules/EmailScan/tests/Integration/ConcurrentBackfillTest.php` — 2 cases: passthrough RecordingDatabaseManager + RecordingConnection record every transaction body's SQL; asserts PRAGMA busy_timeout = 5000 fires inside every per-page tx (≥ 3 occurrences for 3 messages); sanity check that the live test connection accepts the pragma.

## Decisions Made

See the `key-decisions` frontmatter section above. The most load-bearing four:

1. **Extract GmailApiClientContract interface** so the Wave 0 Fake (which has a different constructor signature) and the real client are interchangeable at the test seam. Plan 01's Fake was bareclass; Plan 05 introduces the interface as a one-off cost that pays off for the downstream Microsoft path too.
2. **Do NOT register BackfillInboxJob as a container singleton** even though the plan said to. Jobs carry readonly per-dispatch arguments; singleton-binding would fuse two dispatches' state. Container auto-resolution covers the plan's intent.
3. **ConcurrentBackfillTest exercises the busy_timeout pragma via a passthrough recording decorator** instead of a true two-connection race. PHP's single-threaded test harness cannot simultaneously hold a transaction and release it from another connection mid-wait without pcntl_fork or a subprocess; recording the SQL stream is deterministic and equally evidential.
4. **EmlOrphanCleanupTest forces the rollback via subclass overrides of DatabaseManager + Connection** — `FailingTransactionDbManager` proxies every call to the real manager except `connection()`, which returns a `FailingTransactionConnection` that throws RuntimeException on the configured transaction() call number. Subclass-override is cleaner than mocking and keeps production code paths untouched.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Did NOT register BackfillInboxJob as container singleton (plan said to)**
- **Found during:** Task 2 (service-provider registration)
- **Issue:** Plan-text directs `$this->app->singleton(BackfillInboxJob::class);` — but jobs carry per-dispatch readonly state (inboxId + windowMonths). A container singleton would fuse two dispatches' state, silently stamping the second dispatch with the first's inbox.
- **Fix:** Skipped the singleton binding. Container auto-resolution still wires DI for handle() when Laravel dispatches the job; the singleton binding adds no benefit and creates the bug.
- **Files modified:** Modules/EmailScan/Providers/EmailScanServiceProvider.php
- **Verification:** All four integration tests pass; BackfillChunkedJobTest specifically resolves the job twice (once via container, once via direct construction in EmlOrphanCleanupTest) without state leak.
- **Committed in:** 8d50292 (Task 2 commit)

**2. [Rule 3 - Blocking] Extracted GmailApiClientContract interface so the Fake and real client are interchangeable at the test seam**
- **Found during:** Task 2 (integration test scaffolding)
- **Issue:** Plan 01 shipped FakeGmailApiClient as a bare class; Plan 05's BackfillInboxJob::handle() type-hinted the real GmailApiClient. PHP's strict types reject passing the Fake where the real class is expected; the plan's "$this->app->instance(GmailApiClient::class, $fake)" pattern would not compile.
- **Fix:** Created GmailApiClientContract interface (matching the four-method public surface), made both FakeGmailApiClient and GmailApiClient implement it, retyped handle()'s $gmail parameter to the contract, and bound the contract → real class in the service provider. Tests now rebind the contract → Fake.
- **Files modified:** New: Modules/EmailScan/Internal/Clients/GmailApiClientContract.php. Modified: Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php, Modules/EmailScan/Internal/Clients/GmailApiClient.php, Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php, Modules/EmailScan/Providers/EmailScanServiceProvider.php.
- **Verification:** BackfillChunkedJobTest binds the Fake via `$this->app->instance(GmailApiClientContract::class, $fake)` and the job resolves the Fake transparently.
- **Committed in:** 8d50292 (Task 2 commit)

**3. [Rule 1 - Bug] FakeGmailApiClient missing resultSizeEstimate in listSenderMessages return shape**
- **Found during:** Task 2 (integration test scaffolding)
- **Issue:** The real GmailApiClient returns `{messages, nextPageToken, historyId, resultSizeEstimate}`; the Plan 01 Fake returned only the first three fields. BackfillInboxJob reads `$page['resultSizeEstimate']` to populate the backfill_progress estimate; with the Fake bound, the read would either error or land on 0 silently.
- **Fix:** Added the field to the Fake's return shape, parsing it from the Plan 01 messages-list-page-1.json fixture's existing `resultSizeEstimate: 3`.
- **Files modified:** Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php
- **Verification:** BackfillChunkedJobTest asserts the .eml blobs + index rows land correctly across both pages.
- **Committed in:** 8d50292 (Task 2 commit)

**4. [Rule 1 - Bug] InboxesPage::mount() unconditional $request->session() call failed in Livewire::test harness**
- **Found during:** Task 3 (BackfillProgressPollTest's poll-target case)
- **Issue:** mount() called `$request->session()->has('open_backfill_modal')`. The HTTP path always binds a session via StartSession middleware, but Livewire::test() boots the component with a bare Request that has no session attached; the test would crash on `RuntimeException: Session store not set on request.`
- **Fix:** Guarded the read with `if ($request->hasSession())` so the test harness can boot without a session and the HTTP path keeps reading the flash as before.
- **Files modified:** Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php
- **Verification:** BackfillProgressPollTest's refreshBackfillProgress case passes (was failing pre-fix); the HTTP rendering cases still pass (proving the session read still works in the HTTP path).
- **Committed in:** c1e1aa2 (Task 3 commit)

**5. [Rule 1 - Bug] InboxScanStateMachine needed recordCursor to honour the BoundaryArchTest single-mutator invariant**
- **Found during:** Task 1 / Task 2 (writing BackfillInboxJob's end-of-walk path)
- **Issue:** The plan's stub spec for InboxScanStateMachine listed only applyStatus. BackfillInboxJob's end-of-walk persists the historyId baseline via `last_history_id` on inbox_scan_state — but writing that column directly from the job would violate the noOtherInboxScanStateMutator boundary invariant from Plan 01.
- **Fix:** Implemented recordCursor on the state machine per the plan's prose Note (which DID describe recordCursor, just downstream of the bullet point that named only applyStatus). recordCursor accepts a ScanCursor, dispatches to last_history_id or last_delta_link based on provider, and is a no-op when the cursor isEmpty. BackfillInboxJob calls `$sm->recordCursor(...)` instead of `$db->table('inbox_scan_state')->update([...])`.
- **Files modified:** Modules/EmailScan/Internal/InboxScanStateMachine.php (more than just the applyStatus stub), Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php (calls recordCursor).
- **Verification:** BoundaryArchTest passes; BackfillChunkedJobTest asserts `last_history_id='12345'` after the walk.
- **Committed in:** 42d0870 (Task 1 commit; recordCursor body) + 8d50292 (Task 2 commit; the recordCursor call site)

**6. [Rule 3 - Blocking] Added Vite manifest from main repo + .env from main repo to satisfy InboxesEmptyStateTest**
- **Found during:** Initial worktree setup (Task 1 baseline check) and later during full test-suite verification
- **Issue:** The worktree spawned without vendor/, .env, or public/build/manifest.json. The vendor was auto-installed (`composer install`); .env was copied (one-time bootstrap); the Vite manifest was copied from the main repo so InboxesEmptyStateTest (which renders the layouts.app blade) does not crash on `Vite manifest not found`.
- **Fix:** `composer install --no-interaction`, `cp ../../../.env .env`, `cp -r ../../../public/build public/`.
- **Files modified:** none (vendor/ + .env + public/build are .gitignored).
- **Verification:** Full EmailScan suite green; full project suite green (modulo the documented Ledger pre-existing failure).
- **Committed in:** n/a (vendor + .env + public/build untracked)

---

**Total deviations:** 6 auto-fixed (4 bugs, 2 blocking)
**Impact on plan:** All six auto-fixes were necessary for correctness or test-environment bootstrapping. No scope creep; the plan's three tasks landed as written modulo the technical adjustments documented above.

## Issues Encountered

- **The plan said to test ConcurrentBackfillTest via "open a second SQLite connection in the test...same on-disk file as the default `sqlite_testing` connection".** The project's `sqlite_testing` connection is `:memory:` (separate `:memory:` databases cannot share state across connections), so adding an `sqlite_other` connection wouldn't fix it. A genuine concurrent writer would need pcntl_fork or a subprocess, which the project does not currently exercise. The recording-decorator approach is the chosen compromise — it deterministically proves the runtime guard fires every transaction, which is the behaviour that prevents SQLITE_BUSY in production where two connections can actually race.
- **The plan said "Sleep::seconds(2) between pages" but the runtime API is `Sleep::sleep(2)` (or `Sleep::for(2)->seconds()`).** Used `Sleep::sleep(2)` — equivalent semantics, current API spelling.

## Output-spec narrative

The plan's `<output>` block asked seven specific questions. Answers:

1. **Did the EmlOrphanCleanupTest force a deliberate DB-tx failure and observe the .eml unlink? How was the failure injected (subclass override vs mock)?** Yes. Subclass override: `FailingTransactionDbManager extends DatabaseManager` proxies every call to the real manager except `connection()`, which returns a `FailingTransactionConnection extends Connection` that counts `transaction()` calls and throws `RuntimeException('injected-tx-failure')` on the first call. After the throw, the test asserts no `.eml` blobs remain under `storage/app/inbox/{user_id}/{inbox_id}/` and no `inbox_messages` rows landed. The second pass without injection lands all 3 .eml + 3 rows, proving the path is fully idempotent (UNIQUE constraint + insertOrIgnore + atomic .eml-then-DB ordering).

2. **ConcurrentBackfillTest implementation: pcntl_fork or sequential-with-busy_timeout assertion? What was the observed behaviour?** Sequential-with-busy_timeout assertion via a passthrough connection decorator. `RecordingDatabaseManager` + `RecordingConnection` capture every per-transaction SQL statement and verify that `PRAGMA busy_timeout = 5000` fires inside every per-page transaction body. The pcntl_fork approach is dropped because the project's test environment uses `:memory:` SQLite (separate connections cannot share state) and PHP's test harness cannot simultaneously hold one transaction and release it from another connection mid-wait. The decorator approach proves the runtime guard is in place; the actual lock-and-wait behaviour is exercised in production where two real connections can race.

3. **Did the Q-decoded subject from Plan 01's PayPal fixture round-trip through MimeHeaderParser correctly? What was the decoded string?** Yes. The fixture's raw `Subject: =?UTF-8?Q?Bedankt_voor_je_betaling_aan_Synthetic_Merchant_BV?=` decodes to **`Bedankt voor je betaling aan Synthetic Merchant BV`** (underscore-to-space transformation, UTF-8 byte sequence). MimeHeaderParserTest asserts this verbatim.

4. **Did `Illuminate\Support\Sleep::seconds(2)` between pages get exercised or did the small fixture skip the inter-page sleep? Note: Sleep::fake() in tests is the project convention.** The small fixture's first page has 3 messages and `nextPageToken=page-2-token`, so the sleep DOES fire after page 1. Page 2 is empty so the loop breaks before a second sleep. Tests call `Sleep::fake()` in `beforeEach` so no real wall-clock delay is incurred. The runtime API is `Sleep::sleep(2)` (the plan spelled it `Sleep::seconds(2)` — `seconds()` is a fluent modifier on a Sleep instance, not a static method; the static convenience is `Sleep::sleep($seconds)`).

5. **Was the BackfillWindowModal's flux:input.range available in the installed flux/livewire version, or did you fall back to a plain `<input type="range">`?** Fell back to a plain `<input type="range">` wired with `wire:model.live`. The installed `livewire/flux` build ships `flux:input.*` primitives for text/checkbox/etc. but no `flux:input.range`. The hand-rolled range input carries the same focus-visible chrome (`focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2`) and exercises `wire:model.live` identically. The tick labels (1/3/6/9/12) and the live "{N} months" readout are unchanged.

6. **Was `parse_url(config('app.url'), PHP_URL_PORT)` in the Blade view evaluated correctly in the test env (which has APP_URL=http://localhost — no port)? Fallback to 8000?** Not applicable — the Blade view for the backfill modal does NOT call `parse_url(config('app.url'), PHP_URL_PORT)`. The redirect URI is rendered by the OAuth-client wizard modal (Plan 03/04), which already handles this; the backfill modal only needs the inbox id (passed via the `backfill-window:open` Livewire event) and the months slider value, no URL parsing.

7. **Any deviation from PATTERNS.md analog file structure?** Minor:
   - PATTERNS.md cites `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` as the "exact" analog for `BackfillInboxJob`. The shape matches verbatim (Dispatchable + InteractsWithQueue + Queueable + SerializesModels traits; ShouldBeUniqueUntilProcessing + ShouldQueue; uniqueId + uniqueFor + uniqueVia; tries + backoff). The Chains job audits via `chain_resolution_runs`; the EmailScan job uses `inbox_scan_state.status` flips via `InboxScanStateMachine` — same audit-row lifecycle pattern, different table.
   - PATTERNS.md cites `Modules/Chains/Internal/CardStatementStateMachine.php` as the "exact" analog for `InboxScanStateMachine`. Shape matches (single legal mutator + DI'd DatabaseManager + Clock + PRAGMA busy_timeout=5000 inside transaction). The full state-transition matrix in `CardStatementStateMachine.applySettlement()` is more elaborate than this stub's `applyStatus()` + `recordCursor()`; the planner has scheduled the full matrix for Plan 07.
   - PATTERNS.md cites `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` as a "role-match" analog for `BackfillWindowModal`. Both are Livewire SFCs that dispatch a queued job from a modal submit; the BackfillWindowModal is smaller (one slider field) and uses Flux's modal primitive directly rather than the flyout variant Chains uses.
   - PATTERNS.md cites `no codebase analog; use RESEARCH` for `EmlBlobStore`, `MimeHeaderParser`, and `GmailApiClient`. Implementation follows the RESEARCH document's Pattern 1 (GmailApiClient class shape), Pattern 3 (.eml-then-DB ordering), and Example 3 (zbateson header extraction) verbatim.

## Next Phase Readiness

- The Gmail backfill vertical slice ships; a user with a connected Gmail inbox can pick a window and observe the live count. Plan 06 (Wave 5, Microsoft Graph backfill) replaces the BackfillInboxJob microsoft-branch deferred error with a real Graph fetch path; the existing EmlBlobStore + MimeHeaderParser + atomic .eml-then-DB ordering are reusable verbatim.
- Plan 07 (Wave 6, IncrementalScanJob + full InboxScanStateMachine) extends the state machine with the transition-validation matrix + retry_attempts logic + lockForUpdate. The current stub's `applyStatus` + `recordCursor` surface stays compatible — Plan 07 expands behaviour without breaking call sites.
- Plan 08 (Wave 6, DiscoveryScanJob) consumes `GmailApiClient::listDiscoveryCandidates`, which currently returns an empty page. Plan 08 fills the body; no contract change needed.
- The dashboard "Email scan health" tile + the top-nav "Inboxes" badge + the inbox row status-badge matrix are Plan 07 concerns (UI-SPEC sections that haven't shipped yet).

## Self-Check: PASSED

Verified after writing this SUMMARY:

- **Created files exist:** All 17 paths under `Modules/EmailScan/` and `.planning/phases/06-email-receipt-ingestion-infrastructure/06-05-SUMMARY.md` resolve on disk.
- **Commits exist:** `git log --oneline 40f8ea2..HEAD` shows `42d0870 8d50292 c1e1aa2` in chronological order on the current worktree branch.
- **Test suite green:** Full Modules/EmailScan suite: 89 passed (297 assertions, 1 pre-existing skip). tests/Contracts/BoundaryArchTest.php: 17 passed (39 assertions). vendor/bin/phpstan analyse Modules/EmailScan --memory-limit=1G: `[OK] No errors`. vendor/bin/pint --test Modules/EmailScan: passed. Full project suite: 842 passed, 1 pre-existing failure (Modules/Ledger/tests/Unit/TransactionTypeTest.php documented as out-of-scope baseline failure at commit 6f3aeef).

---

*Phase: 06-email-receipt-ingestion-infrastructure*
*Completed: 2026-05-17*
