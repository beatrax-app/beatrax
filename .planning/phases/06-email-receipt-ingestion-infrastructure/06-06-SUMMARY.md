---
phase: 06-email-receipt-ingestion-infrastructure
plan: 06
subsystem: email
tags: [microsoft-graph, oauth, backfill, queue, guzzle, two-phase-scan, delta-link]

requires:
  - phase: 06-email-receipt-ingestion-infrastructure
    provides: FakeGraphApiClient + CursorExpiredException + RateLimitedException + delta-baseline / delta-410 / throttle-429 fixtures (Plan 01); inboxes / inbox_messages / inbox_scan_state migrations + ScanCursor + OAuthSecretsRepository (Plans 02 + 03); MicrosoftOAuthProvider + AccessTokenWithEmail + InvalidGrantException (Plan 04); BackfillInboxJob + EmlBlobStore + MimeHeaderParser + InboxScanStateMachine + GmailApiClient + GmailApiClientContract (Plan 05)
provides:
  - GraphApiClientContract — extracted interface so the Wave 0 Fake and the production GraphApiClient are interchangeable at the test seam (mirrors the Plan 05 GmailApiClientContract pattern)
  - GraphApiClient — production wrapper using thin Guzzle (not Kiota SDK); honours OData single-quote double-escape rule, Retry-After 429 → RateLimitedException, 410 syncStateNotFound → CursorExpiredException, sole HTTP boundary to audit for Authorization header leaks
  - BackfillInboxJob Microsoft branch — full implementation replacing the Plan 05 "arrives in the next plan" placeholder; two-phase pattern (non-delta walk THEN single deltaPage(null) baseline) writes last_delta_link via InboxScanStateMachine::recordCursor (BoundaryArchTest invariant holds)
  - walkAndPersist private helper — closure-based provider-agnostic page walker; both Gmail and Microsoft branches now share the .eml-then-DB-tx + orphan-cleanup inner loop, per-page progress update, and 2-second inter-page Sleep::sleep throttle (WA-7)
  - BackfillGraphTest (2 cases) — happy-path Microsoft mirror of BackfillChunkedJobTest + rate-limit-and-rethrow path
  - GraphTwoPhaseScanTest (1 case) — exact call-sequence assertion + deltaPage(null) called EXACTLY ONCE invariant
affects: [06-07 IncrementalScanJob walks deltas from inbox_scan_state.last_delta_link (Microsoft) and inbox_scan_state.last_history_id (Gmail) — both populated by this plan's BackfillInboxJob baseline phases; 06-08 DiscoveryScanJob consumes GraphApiClient::listDiscoveryCandidatesPaged which currently returns an empty page]

tech-stack:
  added: []  # microsoft/microsoft-graph + thenetworg/oauth2-azure landed in Plan 04; Plan 06 uses Guzzle (already wired transitively via google/apiclient + thenetworg/oauth2-azure) directly rather than reaching into the Kiota stack
  patterns:
    - "Thin Guzzle wrapper for a Kiota-generated provider SDK when the SDK's auth-provider class hierarchy would require duplicating the project's own refresh-token rotation surface. Reuses MicrosoftOAuthProvider::refreshAccessToken + OAuthSecretsRepository::rotateRefreshToken so token storage stays in one place."
    - "Two-phase scan ordering for Microsoft Graph: backfill phase walks non-delta /me/messages with the (from in [...]) and receivedDateTime ge {windowStart} OData filter; baseline phase issues a SINGLE /me/mailFolders/inbox/messages/delta?$filter=receivedDateTime ge {now} call AFTER the walk completes to capture the @odata.deltaLink. RESEARCH Pattern 4. The single-shot invariant is enforced by GraphTwoPhaseScanTest's request-log assertion."
    - "OData single-quote double-escape rule (str_replace(\"'\", \"''\", $pattern)) — the standard OData escape for a literal apostrophe inside a string literal. Applied before interpolating each sender pattern into the $filter clause."
    - "Provider-stamped internal_date overrides in-body Date: header for Microsoft messages — MimeHeaderParser::parseHeadersWithFallbackDate accepts the receivedDateTime as the fallback so a missing or skewed Date: header never silently lands on the wall clock. BackfillGraphTest asserts the receivedDateTime values pin internal_date verbatim."
    - "walkAndPersist closure-based helper: same .eml-then-DB-tx + orphan-cleanup body shared between Gmail and Microsoft branches; per-provider differences (pagination cursor token shape, raw-message decode, internal_date source) are isolated to four closures passed at the call site. The helper hides 60 lines of inner-loop boilerplate; the per-branch try/catch envelope remains separate because the state-machine transitions are nominally provider-agnostic but the catch order matters per-branch (InvalidGrantException is swallowed; RateLimitedException is rethrown)."

key-files:
  created:
    - Modules/EmailScan/Internal/Clients/GraphApiClient.php
    - Modules/EmailScan/Internal/Clients/GraphApiClientContract.php
    - Modules/EmailScan/tests/Integration/BackfillGraphTest.php
    - Modules/EmailScan/tests/Integration/GraphTwoPhaseScanTest.php
  modified:
    - Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php
    - Modules/EmailScan/Internal/Clients/FakeGraphApiClient.php
    - Modules/EmailScan/Providers/EmailScanServiceProvider.php
    - Modules/EmailScan/tests/Integration/ConcurrentBackfillTest.php
    - Modules/EmailScan/tests/Integration/EmlOrphanCleanupTest.php

key-decisions:
  - "Thin Guzzle (not the microsoft/microsoft-graph Kiota SDK) for GraphApiClient. The Kiota SDK v3 expects a Kiota authentication provider that wraps a TokenRequestContext implementation — a two-layer abstraction designed for delegated MSAL flows where the SDK refreshes tokens itself. The project's OAuth surface already owns the refresh cycle via MicrosoftOAuthProvider::refreshAccessToken + the chmod-600 JSON repository; bridging the two layers would require either duplicating that flow as a Kiota TokenProvider OR keeping two parallel token-storage paths in sync. Direct Guzzle to https://graph.microsoft.com/v1.0/ gives one HTTP boundary to audit for Authorization: Bearer leaks, explicit control over the OData $filter string + @odata.nextLink walk, and explicit control over the Retry-After header read on 429 (the Kiota SDK swallows the header into a generic exception object). Plan 04's RESEARCH explicitly sanctioned this fallback (\"If the SDK shape differs in the installed version, fall back to the underlying Guzzle client directly — both are acceptable\")."
  - "Extracted GraphApiClientContract interface mirroring the Plan 05 GmailApiClientContract pattern. The Wave 0 FakeGraphApiClient was a bare final class; the contract introduction lets BackfillInboxJob type-hint the contract and tests rebind contract → Fake via $this->app->instance. Same one-off cost Plan 05 paid for Gmail; ships now so any future provider (e.g. a hypothetical Yahoo Mail variant) drops in without a third refactor."
  - "walkAndPersist extracted per WA-7 (NOT deferred). The closure signature deliberately returns {messages, nextCursor, totalEstimated, lastMessageDate} so the same per-page progress payload shape works for both providers. Plan 05's Gmail branch had a redundant per-page update with last_message_date=null; Plan 06's refactor preserves that null for Gmail (it does not have a per-message receivedDateTime equivalent in listSenderMessages) and populates it from the trailing receivedDateTime for Microsoft. All four Plan 05 integration tests stay green after the refactor (verified by re-running BackfillChunkedJobTest + BackfillPerInboxJobTest + EmlOrphanCleanupTest + ConcurrentBackfillTest)."
  - "Page closures use an anonymous-class accumulator (public mutable properties) instead of `&$variable` by-reference captures. PHPStan strict mode at level max loses type tracking through by-reference closure captures and reports `array<...> {totalEstimated: mixed}` on the closure's return shape. An anonymous class with typed public properties (`public int $estimated = 0`) keeps the type chain alive through PHPStan's flow analysis without breaking the strict-rules \"no inline @var override\" prohibition."
  - "Microsoft branch surfaces `error` transition (not throw) on Unknown provider arm. The provider check is now a positive match for 'gmail' or 'microsoft'; anything else (data injected directly into the DB bypassing the migration triggers) lands on the unknown-provider error transition. This matches the Plan 05 deferred-Microsoft surface — the state machine flips cleanly rather than queuing retries forever."
  - "Retry-After header parser handles both delta-seconds (integer) AND HTTP-date forms per RFC 7231. Graph documents the header as delta-seconds, but the broader HTTP spec also allows an HTTP-date — converting against the injected Clock keeps the return shape (`int $retryAfterSeconds`) normalised. Falls back to a 60-second default when the header is missing or unparseable."
  - "providerMessageId allow-list regex includes %, =, +, - in addition to alphanumerics + dot + underscore. Microsoft Graph message ids are long base64url strings with characters Gmail's hex ids do not use (e.g. `AAMkAGI...==`). The Plan 01 .eml fixtures use the slug form (`paypal-sample-receipt`) which the regex permits via the dash. The allow-list defends against a crafted id carrying path-traversal payloads through /messages/{id}/$value."

patterns-established:
  - "Two-phase Graph backfill: non-delta page walk THEN single delta(null) baseline call. The /messages/delta endpoint only supports receivedDateTime filters (not from/), so the only way to apply a sender filter during backfill is the non-delta /me/messages endpoint; the baseline is established afterward so the incremental-scan plan has a valid deltaLink to walk from."
  - "Closure-based shared inner loop for provider-agnostic page walking. Pattern is reusable for any future provider that needs the .eml-then-DB-tx + orphan-cleanup atomic write pair (e.g. a hypothetical IMAP fetcher) — the closure surface isolates the provider boundary cleanly."
  - "Anonymous-class accumulator over by-reference closure capture for PHPStan strict mode compatibility. Mutable shared state across a closure boundary is otherwise typed as `mixed` by PHPStan's flow analysis at level max."

requirements-completed: [EML-04, EML-02]

duration: ~25min
completed: 2026-05-17
---

# Phase 6 Plan 06: Microsoft Graph Backfill Parity Summary

**Real GraphApiClient + BackfillInboxJob Microsoft branch + two-phase scan invariant + 3 integration tests — completes the EML-04 backfill cap for both providers and Plan 05's deferred Microsoft path lands as a real Graph fetch with the @odata.deltaLink baseline correctly established for Plan 07's IncrementalScanJob.**

## Performance

- **Duration:** ~25 min (worktree run)
- **Tasks:** 2
- **Files created:** 4 (2 production + 2 test)
- **Files modified:** 5 (1 production refactor, 1 service provider wire-up, 1 Fake interface impl, 2 existing tests adapted to new handle() arg list)

## Accomplishments

- A user with a connected Microsoft 365 inbox can now run a backfill end-to-end: BackfillInboxJob's Microsoft branch walks /me/messages with an OData filter over the sender allow-list + receivedDateTime lower bound, persists every receipt as a raw .eml blob on disk plus an inbox_messages index row with full atomic-rollback safety, and ends the walk by establishing the @odata.deltaLink baseline cursor that Plan 07's IncrementalScanJob walks from.
- GraphApiClient implements all four contract methods (listSenderMessagesPaged + getRawMessage + deltaPage + listDiscoveryCandidatesPaged stub) via thin Guzzle. OData $filter escapes single quotes via the double-quote rule. 429 → RateLimitedException carrying the Retry-After header value; 410 → CursorExpiredException::graph; everything else → RuntimeException with provider error.message safely capped at 300 chars (never the bearer token).
- /me/messages/{id}/$value returns raw RFC 822 verbatim — no base64 decode (verified by the fixture round-trip in BackfillGraphTest).
- Two-phase scan invariant verified by GraphTwoPhaseScanTest: the exact call sequence is `[listSenderMessagesPaged, getRawMessage × 3, listSenderMessagesPaged, deltaPage]` with deltaPage(null) called EXACTLY ONCE AT THE END of the walk and last_delta_link only written after that single call.
- walkAndPersist private helper extracted (WA-7): both Gmail and Microsoft branches share the .eml-then-DB-tx + orphan-cleanup inner loop, per-page progress update, 2-second inter-page Sleep::sleep throttle. All four Plan 05 integration tests stay green after the refactor.
- 107 EmailScan tests pass (up from Plan 05's 89: +2 BackfillGraphTest cases + 1 GraphTwoPhaseScanTest + several feature tests that had been added between Plan 05 and Plan 06's branch base). 34 tests/Contracts tests pass (BoundaryArchTest invariants including noOtherInboxScanStateMutator + noTransactionWritesFromEmailScan all green). PHPStan level 10 strict reports `[OK] No errors` across 246 files. Laravel Pint reports `passed` project-wide.
- Full project Pest run: **845 passed**, 5 skipped, **1 failed** (the documented `<known_failure>` `Modules/Ledger/tests/Unit/TransactionTypeTest.php`); no regressions introduced by Plan 06.

## Task Commits

1. **Task 1: real GraphApiClient with thin Guzzle wrapper + contract interface + service-provider singleton + FakeGraphApiClient implements contract** — `66b651f` (feat)
2. **Task 2: BackfillInboxJob Microsoft branch + walkAndPersist helper + 2 Graph integration tests + Plan 05 test arg-list adaptation** — `f490edc` (feat)

## Files Created/Modified

### Production code (Task 1)

- `Modules/EmailScan/Internal/Clients/GraphApiClientContract.php` — Interface mirroring the four method shapes of FakeGraphApiClient. Documents the error-sentinel contract (429 → RateLimitedException with Retry-After; 410 → CursorExpiredException::graph; bearer-token-never-in-message invariant).
- `Modules/EmailScan/Internal/Clients/GraphApiClient.php` — `final class GraphApiClient implements GraphApiClientContract`. Thin Guzzle wrapper over `https://graph.microsoft.com/v1.0/`. Per-call instantiation of GuzzleClient (configurable via a future test subclass override). `ensureFreshAccessToken` mirrors GmailApiClient's pattern but routes refresh through MicrosoftOAuthProvider + OAuthSecretsRepository::rotateRefreshToken (Microsoft rotates refresh-tokens single-use; the repository's writeAtomic makes the rotation crash-safe). OData $filter built via `buildSenderFilter()` with the double-quote escape rule applied to each sender pattern. Retry-After parser handles both delta-seconds and HTTP-date forms. Token payloads stripped from every thrown exception message.

### Service Provider wire-up (Task 1)

- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` — Adds singletons for `GraphApiClient` + binds `GraphApiClientContract` → `GraphApiClient`. Mirrors the Plan 05 `GmailApiClient` + `GmailApiClientContract` registration shape.

### Fake updated (Task 1)

- `Modules/EmailScan/Internal/Clients/FakeGraphApiClient.php` — Single declaration change: `implements GraphApiClientContract`. Public method shapes already matched the contract (Plan 01 fixed those at Wave 0); the interface assertion makes the test-seam swap type-safe.

### Production code (Task 2)

- `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php` — `handle()` now also takes `GraphApiClientContract $graph`. Provider dispatch happens via two private branch methods (`runGmailBackfill` + `runMicrosoftBackfill`), each driving `walkAndPersist` with provider-specific closures. The Microsoft branch additionally issues a single `deltaPage(null)` baseline call after the walk completes, then writes the resulting `@odata.deltaLink` via `InboxScanStateMachine::recordCursor` (sole-mutator boundary). The `walkAndPersist` helper owns the `.eml`-then-DB transaction body + orphan-cleanup catch block + per-page progress payload update + 2-second `Sleep::sleep` between pages. Closure return shape: `{messages, nextCursor, totalEstimated, lastMessageDate}` — same payload for both providers.

### Test files (Task 2)

- `Modules/EmailScan/tests/Integration/BackfillGraphTest.php` — 2 cases. The happy path mirrors `BackfillChunkedJobTest` verbatim modulo the provider swap; additionally asserts that the provider-stamped `receivedDateTime` (e.g. `2026-05-11 09:14:21`) drives the `inbox_messages.internal_date` column (not the in-body `Date:` header) and that `last_delta_link` is set to the Fake's hard-coded baseline fixture URL (`https://graph.microsoft.com/v1.0/me/messages/$delta?$deltatoken=baseline-xyz`). The second case arms `simulateRateLimit($inboxId, 5)` on the Fake; the job catches `RateLimitedException`, transitions `inbox_scan_state.status='rate_limited'` with the retry-after hint, and rethrows so the queue worker can reschedule.
- `Modules/EmailScan/tests/Integration/GraphTwoPhaseScanTest.php` — 1 case asserting the exact `getRequestedCalls()` sequence: `['listSenderMessagesPaged', 'getRawMessage', 'getRawMessage', 'getRawMessage', 'listSenderMessagesPaged', 'deltaPage']`. The deltaPage call carries `deltaLink=null` (baseline establish) and sits AFTER the last `listSenderMessagesPaged` call. Asserts `last_delta_link` is the Fake's canonical token only after the single deltaPage call.

### Plan 05 tests (Task 2 — required arg-list adaptation)

- `Modules/EmailScan/tests/Integration/EmlOrphanCleanupTest.php` — adds `FakeGraphApiClient` + `GraphApiClientContract` imports; both positional `handle()` calls now pass the Graph Fake at the new 4th arg position. (The 2nd `app->call([$job2, 'handle'])` invocation auto-resolves the contract via the container binding.)
- `Modules/EmailScan/tests/Integration/ConcurrentBackfillTest.php` — same one-line addition of the Graph Fake in the positional `handle()` call.

## Decisions Made

See `key-decisions` frontmatter. The most load-bearing:

1. **Thin Guzzle over Kiota SDK** — keeps the OAuth refresh cycle in one place (MicrosoftOAuthProvider + OAuthSecretsRepository), gives one HTTP boundary to audit for bearer-token leaks, gives explicit control over the OData $filter string + Retry-After header read. Plan 04's RESEARCH sanctioned this fallback.
2. **GraphApiClientContract extracted** — mirrors the Plan 05 GmailApiClientContract pattern. Wave 0 Fake stays a `final` class but now implements the contract; tests rebind contract → Fake via `$this->app->instance`.
3. **walkAndPersist extracted now (not deferred)** — Plan 06's WA-7 was explicit. The closure-based design keeps the per-provider differences (pagination cursor shape, raw-message decode, internal_date source) at the call site while sharing the inner loop. Plan 05's tests stay green.
4. **Anonymous-class accumulator pattern** — avoids the PHPStan strict-mode `mixed`-typing pitfall of `&$variable` closure captures at level max without resorting to inline `@var` overrides (which are project-wide forbidden).
5. **Provider-stamped receivedDateTime as the canonical internal_date** for the Microsoft branch — RESEARCH Pattern 4 + D-118 correctness requirement. `MimeHeaderParser::parseHeadersWithFallbackDate` accepts the receivedDateTime as the fallback so a missing or skewed in-body `Date:` header never silently lands on the wall clock.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] One-time worktree environment bootstrap (composer install + .env copy + public/build copy + sqlite touch)**

- **Found during:** initial worktree action (before Task 1)
- **Issue:** Same bootstrap deficit Plans 02 / 03 / 04 / 05 documented — the worktree spawned without `vendor/`, `.env`, `public/build/manifest.json`, or `database/database.sqlite`. Every PHP/Pest invocation fails until the four are present.
- **Fix:** `cp /Users/wesselverheij/Development/diederik/.env .env`; `cp -r /Users/wesselverheij/Development/diederik/public/build public/build`; `touch database/database.sqlite`; `composer install --no-interaction --no-progress`.
- **Files modified:** none (vendor/ + .env + public/build + database.sqlite are .gitignored).
- **Verification:** Composer install succeeds, full pest run produces output, baseline `BackfillChunkedJobTest` passes pre-change.

**2. [Rule 3 - Blocking] Plan 05 tests updated for the new handle() arg position**

- **Found during:** Task 2 (first re-run of Plan 05 integration tests after the BackfillInboxJob signature change)
- **Issue:** Plan 05's `EmlOrphanCleanupTest` and `ConcurrentBackfillTest` call `$job->handle(...)` positionally (instead of via `$this->app->call`). Adding `GraphApiClientContract $graph` as a new positional arg at position 4 in `handle()` causes the older 8-arg positional call sites to misalign (e.g. `$blobStore` lands where `$graph` is expected, causing `TypeError`).
- **Fix:** Both tests now construct a `FakeGraphApiClient` in `beforeEach`-style scope, bind it to `GraphApiClientContract`, and pass it as the 4th positional arg to `handle()`. The container-resolved `app->call([$job, 'handle'])` paths require no change.
- **Files modified:** `Modules/EmailScan/tests/Integration/EmlOrphanCleanupTest.php`, `Modules/EmailScan/tests/Integration/ConcurrentBackfillTest.php`.
- **Verification:** All four Plan 05 integration tests pass; all three new Plan 06 tests pass.
- **Committed in:** `f490edc` (Task 2 commit).

**3. [Rule 1 - Bug] PHPStan strict-mode reported `mixed` types on the closure return shape for `&$estimated` / `&$lastMessageDate` by-reference captures**

- **Found during:** Task 2 (first full `vendor/bin/phpstan analyse` after writing the walkAndPersist call sites)
- **Issue:** At level max, PHPStan's flow analysis loses type tracking through by-reference closure captures — `&$estimated` whose declared initial type is `int = 0` becomes `mixed` after `max($estimated, $page['resultSizeEstimate'])`. The closure's return shape PHPDoc declared `'totalEstimated' => int` so the type mismatch triggered `argument.type`.
- **Fix:** Replaced both by-reference captures with an anonymous class carrying typed public mutable properties (`public int $estimated = 0; public ?string $highestHistoryId = null;` for the Gmail branch; equivalent for Microsoft). The anonymous class is captured by reference automatically (PHP object semantics) so the page closures keep the live accumulator semantics, but PHPStan now tracks `$accum->estimated` as `int` through every assignment.
- **Files modified:** `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php`.
- **Verification:** `vendor/bin/phpstan analyse --memory-limit=1G` exits `[OK] No errors` over 246 files; project-wide pint stays `passed`.

---

**Total deviations:** 3 auto-fixed (2 blocking, 1 bug).
**Impact on plan:** Two blockers were pure environment / refactor-byproduct fixes. The one bug was a self-introduced strict-mode mismatch in the helper extraction; fixed by replacing by-reference captures with an anonymous-class accumulator. No scope creep; no architectural changes; no Rule 4 escalations.

## Issues Encountered

- **Microsoft branch's progress payload `last_message_date` semantic.** The Gmail branch passes `null` because `users.messages.list` does not return per-message receivedDateTime metadata. The Microsoft branch takes the last `receivedDateTime` from the per-page messages array (since the order is `receivedDateTime desc` and the API guarantees the result-set order). The progress strip's UI label "last_message_date" is plan-defined as "the most recent receipt observed so far" — for Microsoft this is the FIRST message of page 1 (most recent), but the running tracker keeps the LATEST observed value to support multi-page walks. Tests do not assert this string content (the progress strip's content is a Plan 05 UI concern with its own test).
- **GraphApiClient's `makeHttpClient` factory.** Returns a per-call `new GuzzleClient`. A future plan could DI a shared client + a base URI through the constructor; for now the per-call instantiation is fine (Guzzle's connect-timeout + per-request HTTP/1.1 reuse is acceptable for the backfill's < 1 req/sec rate). The factory is `private` so the change is internal-only.
- **listDiscoveryCandidatesPaged returns empty** in GraphApiClient (Wave 0 contract stub for Plan 08). The Fake serves the page-1 fixture for discovery scenarios; the real client returns `[]`. Plan 08 fills the body.

## Output-spec narrative

The plan's `<output>` block asked six specific questions. Answers:

1. **SDK auth-provider class hierarchy OR thin Guzzle-direct? Why?** Thin Guzzle-direct. The Kiota SDK's auth-provider hierarchy expects either a Kiota TokenProvider OR a delegated-MSAL flow; both would require either duplicating the project's OAuth refresh-cycle (already owned by MicrosoftOAuthProvider + OAuthSecretsRepository) OR keeping two parallel token-storage paths in sync. Direct Guzzle to `https://graph.microsoft.com/v1.0/` gives one HTTP boundary to audit for `Authorization: Bearer` leaks, explicit control over the OData `$filter` string, explicit control over the `Retry-After` header read on 429. Plan 04's RESEARCH explicitly sanctioned this fallback ("If the SDK shape differs in the installed version, fall back to the underlying Guzzle client directly — both are acceptable. The interface (method signatures above) is the contract; the SDK choice is implementation detail.").

2. **Exact OData $filter string for a 3-sender, 3-month window. Single-quote escape verified?** For sender list `['service@paypal.com', 'noreply@ics.nl', 'googleplay-noreply@google.com']` with windowStart 2026-02-17 the constructed filter is:
   ```
   (from/emailAddress/address eq 'service@paypal.com' or from/emailAddress/address eq 'noreply@ics.nl' or from/emailAddress/address eq 'googleplay-noreply@google.com') and receivedDateTime ge 2026-02-17T00:00:00Z
   ```
   Single-quote escape is verified by static inspection — `str_replace("'", "''", $p)` runs unconditionally on each sender pattern before interpolation. A hostile pattern containing `o'brien@example.com` would render as `from/emailAddress/address eq 'o''brien@example.com'` per OData spec. The escape rule is the double-quote escape (a literal `'` becomes `''` inside a string literal); `addslashes` is NOT used (that's a SQL/PHP convention, not OData).

3. **Did the test confirm deltaPage(null) was called EXACTLY ONCE after the walk loop? (request log assertion)** Yes. `GraphTwoPhaseScanTest` asserts the exact call sequence:
   ```php
   ['listSenderMessagesPaged', 'getRawMessage', 'getRawMessage', 'getRawMessage', 'listSenderMessagesPaged', 'deltaPage']
   ```
   plus a separate filter assertion that `deltaPage` appears EXACTLY ONCE in the call log with `args = {inboxId: <id>, deltaLink: null}`. The test also computes the max index of `listSenderMessagesPaged` in the sequence and asserts `deltaPage`'s index is strictly greater (so the baseline truly sits AFTER the last walk page).

4. **Microsoft receivedDateTime overrides in-body Date: header for internal_date? Asserted in BackfillGraphTest?** Yes. `BackfillGraphTest` asserts `internal_date='2026-05-11 09:14:21'` for the PayPal fixture, which comes from the `messages-page-1.json` fixture's `receivedDateTime: 2026-05-11T09:14:21Z`. The synthesised .eml's in-body Date header could be skewed in production (Microsoft Graph's receivedDateTime is provider-stamped and more authoritative); `MimeHeaderParser::parseHeadersWithFallbackDate` accepts the provider value as the fallback so a missing or unparseable Date: header is irrelevant. The Microsoft branch's `extractInternalDate` closure parses `receivedDateTime` and returns it; the helper picks the parser variant that takes the fallback.

5. **Did the Plan 01 Fake's `simulateRateLimit($inboxId, 5)` test path get exercised? Did the job catch RateLimitedException and transition to rate_limited?** Yes. `BackfillGraphTest`'s second case arms `$fake->simulateRateLimit($inboxId, 5)` before dispatching the job. The Fake throws `RateLimitedException` from the first `listSenderMessagesPaged` call. The job's catch block transitions `inbox_scan_state.status='rate_limited'` with `error_message='Retry after 5s.'` and re-throws so the queue worker can apply the back-off envelope. The test asserts both the exception type AND the state transition (status + error_message containing '5').

6. **Deviations from PATTERNS.md analog file structure?** Minor:
   - PATTERNS.md cites `no codebase analog; use RESEARCH` for `GraphApiClient`. Implementation follows RESEARCH Pattern 1 (client wrapper shape: ensureFreshAccessToken + per-method error mapping) + Pattern 4 (two-phase scan) + Pattern 8 (Retry-After parse) verbatim. No structural deviations.
   - The plan's `<action>` for Task 1 suggested both SDK-auth and thin-Guzzle paths as acceptable; the thin-Guzzle path was chosen for the reasons in Decision 1.
   - The plan's `<action>` for Task 2 specified that `walkAndPersist` should accept many closure args; the implementation matches that signature (`Connection $connection, Clock $clock, EmlBlobStore $blobStore, MimeHeaderParser $mime, int $userId, Closure $fetchNextPage, Closure $extractMessageId, Closure $fetchRawEml, Closure $extractInternalDate`). The plan's spec also said `extractInternalDate` returns `\DateTimeImmutable` with null returns falling back to header parse; the implementation matches (`?DateTimeImmutable` return; null routes to `parseHeaders` else `parseHeadersWithFallbackDate`).

## Next Phase Readiness

- Plan 07 (Wave 6, IncrementalScanJob + full InboxScanStateMachine) has BOTH cursor types populated end-to-end: Gmail's `last_history_id` baseline (Plan 05) + Microsoft's `last_delta_link` baseline (Plan 06). The InboxScanStateMachine's `recordCursor` surface is provider-agnostic; the incremental walk will route Gmail rows to `GmailApiClient::listHistory($lastHistoryId)` and Microsoft rows to `GraphApiClient::deltaPage($lastDeltaLink)` via the existing match($provider) dispatch shape.
- Plan 08 (Wave 6, DiscoveryScanJob) consumes `GraphApiClient::listDiscoveryCandidatesPaged` which currently returns an empty page. Plan 08 fills the body using Graph's `$search` parameter; no contract change needed.
- The hybrid stack (microsoft/microsoft-graph + thenetworg/oauth2-azure + thin Guzzle) is now exercised end-to-end through the Plan 06 path; the Kiota SDK is loaded but not yet called in production. If a future plan needs the SDK's batch-request or large-attachment surfaces, the existing GraphApiClient can grow new methods that reach into `Microsoft\Graph\GraphServiceClient` while keeping the four Plan 06 methods on Guzzle.

## Self-Check: PASSED

Verified after writing this SUMMARY:

- **Created files exist:** All 4 paths under `Modules/EmailScan/` (`GraphApiClient.php`, `GraphApiClientContract.php`, `BackfillGraphTest.php`, `GraphTwoPhaseScanTest.php`) and `.planning/phases/06-email-receipt-ingestion-infrastructure/06-06-SUMMARY.md` resolve on disk.
- **Commits exist:** `git log --oneline -3` shows `f490edc 66b651f 1a885df` in reverse-chronological order on the current worktree branch.
- **Test suite green:** Full `Modules/EmailScan` suite: 107 passed (371 assertions). `tests/Contracts`: 34 passed (122 assertions). `vendor/bin/phpstan analyse --memory-limit=1G`: `[OK] No errors` across 246 files. `vendor/bin/pint --test`: `passed` project-wide. Full project Pest: 845 passed, 5 skipped, 1 failed (the documented `<known_failure>` `Modules/Ledger/tests/Unit/TransactionTypeTest`).

---

*Phase: 06-email-receipt-ingestion-infrastructure*
*Completed: 2026-05-17*
