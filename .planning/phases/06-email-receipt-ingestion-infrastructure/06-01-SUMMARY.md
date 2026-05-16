---
phase: 06-email-receipt-ingestion-infrastructure
plan: 01
subsystem: infra
tags: [email, gmail, microsoft-graph, oauth, fixtures, boundary-tests, composer-conflict]

requires:
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    provides: Modules/Chains module shape (composer.json + ServiceProvider + Public/Internal split) that Modules/EmailScan mirrors; BoundaryArchTest rule shapes (Internal containment, facade carve-out, per-table-write grep, single-state-mutator grep)
provides:
  - Empty Modules/EmailScan bounded module skeleton registered as a service provider
  - Per-module Pest TestCase bound in tests/Pest.php foreach map
  - composer.json top-level conflict block locking out webklex/laravel-imap, webklex/php-imap, ddeboer/imap
  - tests/Contracts/NoExtImapTest extended with composer.lock package-name grep
  - tests/Contracts/BoundaryArchTest extended with Internal containment, facade carve-out (3 EmailScan jobs), noTransactionWritesFromEmailScan, noOtherInboxScanStateMutator, noOAuthTokensInEmailScanSchema rules
  - Three synthesised .eml fixtures (PayPal Q-encoded subject, ICS, Google Play) using D-120 system seed sender addresses
  - 10 JSON API-response fixtures covering Gmail + Graph list/get/cursor-expiry/rate-limit shapes
  - FakeGmailApiClient + FakeGraphApiClient + CursorExpiredException + RateLimitedException (Wave 0 contract surface)
  - scenario.md narrative documenting the expected post-fetch inbox_messages state
affects: [06-02 OAuth secrets repository + schema migrations, 06-03 OAuth callback + Connect-Inbox flow, 06-05 BackfillInboxJob, 06-06 IncrementalScanJob, 06-07 InboxScanStateMachine + Inboxes page, 06-08 DiscoveryScanJob]

tech-stack:
  added: []
  patterns:
    - "Module skeleton mirrors Modules/Chains shape (composer.json + service provider with conditional loadMigrations/Routes/Views + inert per-module Pest.php)"
    - "Synthesised .eml + API-response fixtures + Fake clients land Wave 0 BEFORE any production code so downstream plans test without real OAuth"
    - "Q-encoded subject in PayPal fixture exercises future MIME header parser on the spec-compliant edge case"
    - "Gmail raw fixtures store base64url-encoded CRLF-normalised .eml; Graph raw fixtures read .eml verbatim (provider returns RFC 822 directly)"
    - "Composer conflict block + composer.lock grep test = belt-and-braces enforcement of the PLT-05 IMAP ban"
    - "Boundary tests describe rationale in plain technical language with no D-codes / REQ-IDs (Phase 5 D-101 lesson — codebase stays GSD-agnostic)"

key-files:
  created:
    - Modules/EmailScan/composer.json
    - Modules/EmailScan/Providers/EmailScanServiceProvider.php
    - Modules/EmailScan/Routes/web.php
    - Modules/EmailScan/tests/TestCase.php
    - Modules/EmailScan/tests/Pest.php
    - Modules/EmailScan/Internal/Clients/CursorExpiredException.php
    - Modules/EmailScan/Internal/Clients/RateLimitedException.php
    - Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php
    - Modules/EmailScan/Internal/Clients/FakeGraphApiClient.php
    - Modules/EmailScan/tests/fixtures/eml/paypal/sample-receipt.eml
    - Modules/EmailScan/tests/fixtures/eml/ics/sample-statement-notice.eml
    - Modules/EmailScan/tests/fixtures/eml/googleplay/sample-purchase.eml
    - Modules/EmailScan/tests/fixtures/api-responses/gmail/messages-list-page-1.json
    - Modules/EmailScan/tests/fixtures/api-responses/gmail/messages-list-page-2-empty.json
    - Modules/EmailScan/tests/fixtures/api-responses/gmail/messages-get-raw-paypal.json
    - Modules/EmailScan/tests/fixtures/api-responses/gmail/messages-get-raw-ics.json
    - Modules/EmailScan/tests/fixtures/api-responses/gmail/messages-get-raw-googleplay.json
    - Modules/EmailScan/tests/fixtures/api-responses/gmail/history-list-404.json
    - Modules/EmailScan/tests/fixtures/api-responses/gmail/rate-limit-403.json
    - Modules/EmailScan/tests/fixtures/api-responses/graph/messages-page-1.json
    - Modules/EmailScan/tests/fixtures/api-responses/graph/messages-page-2-empty.json
    - Modules/EmailScan/tests/fixtures/api-responses/graph/delta-baseline.json
    - Modules/EmailScan/tests/fixtures/api-responses/graph/delta-410.json
    - Modules/EmailScan/tests/fixtures/api-responses/graph/throttle-429.json
    - Modules/EmailScan/tests/fixtures/scenario.md
  modified:
    - composer.json
    - composer.lock
    - phpunit.xml
    - tests/Pest.php
    - bootstrap/providers.php
    - bootstrap/cache/services.php
    - tests/Contracts/BoundaryArchTest.php
    - tests/Contracts/NoExtImapTest.php

key-decisions:
  - "FakeGmailApiClient.fixtureSlug() maps `paypal-sample-receipt` → `paypal` via the first dash-segment so the fixture filename pattern stays self-documenting (one fixture per upstream sender)"
  - "Fake clients live under Modules/EmailScan/Internal/Clients/ rather than Modules/EmailScan/tests/Doubles/ — the future real GmailApiClient / GraphApiClient will land beside them and the production ServiceProvider will swap the binding in tests via $this->app->instance(...)"
  - "CursorExpiredException uses named factory methods (::gmail / ::graph) instead of an enum sentinel parameter — clearer call sites + greppable provenance"
  - "RateLimitedException carries `public readonly int $retryAfterSeconds` rather than a generic int so a future provider-driver caller can read the suggested back-off without re-parsing the message"
  - "Composer conflict block placed between require-dev and autoload per common Composer ordering; `composer update --lock --no-install` re-locked the file without changing any package versions"
  - "Boundary tests use plain English in failure messages — no D-codes or REQ-IDs reach the boundary-test source"

patterns-established:
  - "Wave 0 fixture-first: every new module ships synthesised fixtures + Fake API clients in its first plan so downstream plans have green-CI guard rails"
  - "Composer conflict block + composer.lock grep test together = forward-resistant package ban (regression must clear both gates)"
  - "Per-module Pest.php is inert; root tests/Pest.php foreach map is load-bearing — new modules add one row + one phpunit.xml testsuite block + one composer autoload-dev row + one bootstrap/providers.php import"

requirements-completed: [PLT-05, EML-03]

duration: ~25min
completed: 2026-05-16
---

# Phase 6 Plan 01: Module Bootstrap + Synthesised Fixtures + Boundary Invariants Summary

**EmailScan module skeleton + three-sender .eml + JSON fixture corpus + FakeGmailApiClient + FakeGraphApiClient + Composer/lock IMAP ban + four EmailScan-specific BoundaryArchTest rules — Wave 0 green-CI groundwork for the entire phase.**

## Performance

- **Duration:** ~25 min (worktree run, includes one-time composer install of dependencies)
- **Started:** 2026-05-16T22:25:30Z (approximate — first action against the worktree)
- **Completed:** 2026-05-16T22:50:41Z
- **Tasks:** 3
- **Files created:** 25 (module scaffold + fixtures + Fake clients + sentinel JSON)
- **Files modified:** 8 (composer.json + lock + phpunit.xml + tests/Pest.php + bootstrap providers + cache + 2 contract tests)

## Accomplishments

- Modules/EmailScan/ bounded module exists, registered, and discoverable by Pest + phpunit
- Composer-level IMAP regression is now hard-blocked at install time AND fingerprinted in the lockfile via a contract test
- noTransactionWritesFromEmailScan + noOtherInboxScanStateMutator + noOAuthTokensInEmailScanSchema + Modules\EmailScan\Internal containment + facade carve-out for the three EmailScan jobs all land at once
- Three synthesised .eml fixtures + 10 API-response JSON fixtures + scenario.md narrative deliver the downstream-plan test corpus
- FakeGmailApiClient + FakeGraphApiClient + CursorExpiredException + RateLimitedException define the future-real-client contract via their public method shapes
- Full test suite + PHPStan level 10 strict + Laravel Pint stay green

## Task Commits

1. **Task 1: scaffold Modules/EmailScan bounded module skeleton** — `3c01e0b` (feat)
2. **Task 2: synthesise three-sender .eml + JSON fixtures + Fake clients** — `b96c328` (feat)
3. **Task 3: lock IMAP regressions + EmailScan transaction-write boundary** — `3edb7cf` (feat)

## Files Created/Modified

### Module scaffold (Task 1)
- `Modules/EmailScan/composer.json` — Module manifest, PSR-4 + autoload-dev
- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` — Service provider shell (empty register(); boot() conditionally loads migrations / routes / views with namespace `email-scan`)
- `Modules/EmailScan/Routes/web.php` — Empty `auth+web` middleware group ready for plans 03+
- `Modules/EmailScan/tests/TestCase.php` — Abstract module-local TestCase extending Tests\TestCase
- `Modules/EmailScan/tests/Pest.php` — Inert per-module Pest.php (mirrors Modules/Chains shape)
- `Modules/EmailScan/tests/{Unit,Feature,Contracts,Integration}/.gitkeep` — Empty test dirs so phpunit testsuites resolve

### Cross-cutting registration (Task 1)
- `composer.json` — `Modules\\EmailScan\\Tests\\` PSR-4 autoload-dev row
- `phpunit.xml` — EmailScanUnit / EmailScanFeature / EmailScanContracts / EmailScanIntegration testsuites
- `tests/Pest.php` — `Modules/EmailScan => Modules\EmailScan\Tests\TestCase::class` foreach row
- `bootstrap/providers.php` — EmailScanServiceProvider alphabetically between Chains + Transfers
- `bootstrap/cache/services.php` — auto-regenerated to reflect the new provider

### Fixtures + Fake clients (Task 2)
- `Modules/EmailScan/Internal/Clients/CursorExpiredException.php` — Typed cursor-expired sentinel with `::gmail()` / `::graph()` factories
- `Modules/EmailScan/Internal/Clients/RateLimitedException.php` — `public readonly int $retryAfterSeconds`
- `Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php` — `listSenderMessages` / `getRawMessage` / `listHistory` / `listDiscoveryCandidates` / `simulateRateLimit` / `getRequestedCalls`
- `Modules/EmailScan/Internal/Clients/FakeGraphApiClient.php` — `listSenderMessagesPaged` / `getRawMessage` / `deltaPage` / `listDiscoveryCandidatesPaged` / `simulateRateLimit` / `simulateCursorExpired` / `getRequestedCalls`
- `Modules/EmailScan/tests/fixtures/eml/{paypal,ics,googleplay}/*.eml` — Three synthesised RFC 822 messages
- `Modules/EmailScan/tests/fixtures/api-responses/gmail/*.json` — 7 Gmail wire-shape fixtures (3 raw envelopes, page-1, empty page-2, 404, 403)
- `Modules/EmailScan/tests/fixtures/api-responses/graph/*.json` — 5 Graph wire-shape fixtures (page-1, empty page-2, baseline delta, 410, 429)
- `Modules/EmailScan/tests/fixtures/scenario.md` — Expected-state narrative

### Boundary + IMAP guard rails (Task 3)
- `composer.json` — Top-level `conflict` block listing webklex/laravel-imap, webklex/php-imap, ddeboer/imap
- `composer.lock` — Re-locked (no package changes; metadata refresh only)
- `tests/Contracts/NoExtImapTest.php` — New third test grepping `composer.lock` for the same three package names; skip-predicate for fresh checkouts without an installed lock
- `tests/Contracts/BoundaryArchTest.php` — Five new boundary additions (Modules\EmailScan\Internal containment + facade carve-out extension covering the three EmailScan jobs + noTransactionWritesFromEmailScan + noOtherInboxScanStateMutator + noOAuthTokensInEmailScanSchema)

## Decisions Made

- **Q-encoded subject lives on the PayPal fixture only.** The plan required at least one Q-encoded subject; placing it on PayPal keeps the ICS + Google Play subjects readable in raw form (most-likely shape they'll actually arrive in) while still exercising the MIME header parser on the spec-compliant edge case.
- **Gmail raw fixture stores CRLF-normalised .eml.** When the Wave 0 fixtures were authored, the bare LF-terminated `.eml` files on disk were transformed to CRLF before base64url encoding so the Fake's `getRawMessage()` round-trips bytes the real Gmail `format=raw` endpoint would return. Verified via `php -r` smoke that the decoded payload starts with `Return-Path:` and contains `\r\n`.
- **Graph raw fixture re-reads the matching .eml directly.** Graph's `/$value` returns raw RFC 822 with no transport wrapper, so the Graph Fake reads the same `.eml` file the Gmail Fake's base64url payload was generated from — single source of truth, no double-encoding risk.
- **Fake clients live in `Modules/EmailScan/Internal/Clients/`, not `tests/Doubles/`.** The future real `GmailApiClient` will land beside them. Production wiring binds the real class; test wiring uses `$this->app->instance(GmailApiClient::class, $fake)`. Keeps the swap site one line at test setup.
- **noOAuthTokensInEmailScanSchema added beyond the plan's four boundary rules.** Plan-text listed (a)–(d); a fifth (e) was already documented in the action block (PLT-03 baseline invariant). Implemented as part of Task 3.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] One-time `composer install` to materialise vendor dependencies**
- **Found during:** Task 1 (autoload verification)
- **Issue:** The worktree spawned without `vendor/illuminate/*` populated — `composer dump-autoload -o` ran successfully but the resulting autoloader could not resolve `Illuminate\Support\ServiceProvider`. Reflection on `EmailScanServiceProvider` failed with a fatal class-not-found at the parent-class boundary.
- **Fix:** Ran `composer install --no-interaction` once in the worktree to populate vendor. After install, `ReflectionClass` resolves the provider cleanly.
- **Files modified:** none (vendor/ is .gitignored)
- **Verification:** `php -r "require 'vendor/autoload.php'; new ReflectionClass(Modules\\EmailScan\\Providers\\EmailScanServiceProvider::class);"` exits 0.

**2. [Rule 3 - Blocking] Copied `.env` from main repo into worktree**
- **Found during:** Task 1 (test-suite warning check)
- **Issue:** `Tests\Contracts\NoExtImapTest` reported phpdotenv warnings (`Failed to open stream: No such file or directory`) when invoked from the worktree because `.env` was not present.
- **Fix:** `cp /Users/wesselverheij/Development/diederik/.env .env`.
- **Files modified:** none (.env is .gitignored)
- **Verification:** Re-running `vendor/bin/pest tests/Contracts/NoExtImapTest.php` produces `2 passed (3 assertions)` instead of `2 warnings (3 assertions)`.

**3. [Rule 1 - Bug] Larastan strict-rules cleanup in both Fake clients**
- **Found during:** Task 3 (post-implementation full `phpstan analyse` gate)
- **Issue:** Initial Fake-client drafts produced 9 strict-rules errors (`cast.int`, `cast.string`, `cast.useless`, `return.unusedType`). The mixed-typed JSON reads relied on inline `(int) (... ?? 0)` and `(string) (... ?? '')` patterns which strict-rules rejects, and an `unusedTypeReference()` method had been added as a poor-man's "hold the DateTimeImmutable import alive" hack.
- **Fix:** Replaced inline casts with explicit `isset($a['k']) && is_int($a['k'])` / `is_string(...)` predicates; dropped the unused method + the `DateTimeImmutable` import on the Gmail Fake (Graph keeps it because `listSenderMessagesPaged` takes a `DateTimeImmutable` window-start parameter).
- **Files modified:** `Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php`, `Modules/EmailScan/Internal/Clients/FakeGraphApiClient.php`
- **Verification:** `vendor/bin/phpstan analyse --memory-limit=1G` exits with `[OK] No errors` (204 files).
- **Committed in:** `3edb7cf` (Task 3 commit — bundled with the boundary-test additions so the full phpstan gate passes in the same atomic commit)

---

**Total deviations:** 3 auto-fixed (2 blocking, 1 bug)
**Impact on plan:** Two blockers were pure worktree-environment bootstrapping (no production code touched). The one strict-rules bug was self-introduced and fixed in the same plan cycle. No scope creep.

## Issues Encountered

- `php artisan tinker` is not installed in the dev dependencies of this project; the plan suggested `php artisan tinker --execute='echo class_exists(...)'` as a verification step. Replaced with `php -r "require 'vendor/autoload.php'; new ReflectionClass(...)"` — equivalent autoload-resolution evidence without the missing dependency.
- Composer reported "your composer.lock has some errors — not up to date" after the conflict block landed. Resolved with `composer update --lock --no-install` (single re-lock with no package version changes). Subsequent `composer validate --no-check-publish` exits clean.

## Output-spec narrative

The plan's `<output>` block asked five specific questions. Answers:

1. **Module scaffold files created:** Listed verbatim above under `key-files.created`.
2. **Q-encoded subject zbateson round-trip:** Not attempted — `zbateson/mail-mime-parser` is not yet a project dependency (lands when `MimeHeaderParser` ships in a later plan). The fixture provides the raw Q-encoded byte sequence; the round-trip will be exercised in the plan that introduces the parser.
3. **noTransactionWritesFromEmailScan failure-mode probe:** **Yes** — caught a sentinel `Modules/EmailScan/Internal/Hack.php` carrying a `Transaction::create([])` call. Failure message named the offender precisely (`...Modules/EmailScan/Internal/Hack.php`). Sentinel deleted before commit; the same file was then modified to test `noOtherInboxScanStateMutator` against a `->table('inbox_scan_state')->update([...])` call — also caught. File deleted again before Task 3 commit.
4. **Composer validate after conflict block insertion:** Yes, re-ran. First pass reported the lock-file-not-up-to-date warning; `composer update --lock --no-install` resolved it without changing package versions. Second `composer validate --no-check-publish` exits clean.
5. **Deviations from PATTERNS.md analog file structure:** None of substance. The EmailScan service provider's `boot()` signature includes `LivewireManager $livewire` to match the Chains analog even though Wave 0 registers no Livewire components yet — the param is silenced via `unset($livewire)` so phpstan stays clean. Later plans amend the body, not the signature.

## Next Phase Readiness

- Module skeleton + Fake clients + fixture corpus + boundary invariants are ready for plans 06-02 (OAuth secrets repository + schema migrations) and beyond. The downstream plans can wire the production pipeline against the Fake clients without touching real OAuth.
- noOAuthTokensInEmailScanSchema is currently trivially satisfied (zero migrations); plan 06-02 must respect it when the first schema migration lands.
- noOtherInboxScanStateMutator + noTransactionWritesFromEmailScan are currently trivially satisfied; both will start binding when plan 06-05 / 06-07 introduce production jobs + the state machine.

## Self-Check: PASSED

Verified after writing this SUMMARY:

- **Created files exist:** All 25 paths under `Modules/EmailScan/` and `.planning/phases/06-email-receipt-ingestion-infrastructure/06-01-SUMMARY.md` resolve on disk.
- **Commits exist:** `git log --oneline -3` shows `3edb7cf b96c328 3c01e0b` in reverse-chronological order on the current worktree branch.
- **Test suite green:** `vendor/bin/pest tests/Contracts/BoundaryArchTest.php tests/Contracts/NoExtImapTest.php` reports 20 passed (43 assertions); `vendor/bin/phpstan analyse` exits with `[OK] No errors`; `vendor/bin/pint --test` reports `passed`; `composer validate --no-check-publish` exits clean.

---

*Phase: 06-email-receipt-ingestion-infrastructure*
*Completed: 2026-05-16*
