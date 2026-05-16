---
phase: 06-email-receipt-ingestion-infrastructure
plan: 02
subsystem: infra
tags: [email, schema, dto, oauth, atomic-rotation, chmod-600, scan-cursor, idempotency]

requires:
  - phase: 06-email-receipt-ingestion-infrastructure
    provides: Modules/EmailScan module skeleton + boundary tests (noOAuthTokensInEmailScanSchema, noOtherInboxScanStateMutator, noTransactionWritesFromEmailScan) + fixture corpus + Fake API clients
provides:
  - Five user-scoped tables (inboxes, inbox_scan_state, inbox_messages, known_senders, discovered_senders) with FND-03 nullable user_id + cascade-on-delete
  - Three seeded known_senders system rows (paypal.com, @ics.nl, googleplay-noreply@google.com) carrying user_id=NULL + source=system
  - inbox_messages.UNIQUE (inbox_id, provider_message_id) re-fetch idempotency contract
  - ScanCursor value object normalising Gmail historyId + Microsoft Graph deltaLink behind one provider-discriminated DTO
  - Six Public DTOs (ScanCursor, InboxCredentials, InboxHealthDto, KnownSenderDto, InboxMessageDto, EmailScanHealthTile) + supporting InboxHealthLine
  - InboxMessageQuery public read-side surface — forStatus(string) streams inbox_messages rows via generator + cursor
  - OAuthSecretsRepository — single DI surface to storage/app/secrets/email-oauth.json with atomic tmp+fsync+rename + chmod 0600 + dir 0700
  - SecretsWriteFailed typed exception whose message never leaks credential material
affects: [06-03 Gmail OAuth, 06-04 Microsoft OAuth, 06-05 BackfillInboxJob, 06-06 IncrementalScanJob, 06-07 InboxScanStateMachine + /inboxes page, 06-08 DiscoveryScanJob, Phase 7 parser stage]

tech-stack:
  added: []
  patterns:
    - "Phase 6 migrations mirror the Phase 5 Chains shape verbatim — Container::getInstance()->make() memoised DatabaseManager + Blueprint columns + paired BEFORE INSERT / BEFORE UPDATE enum triggers"
    - "FND-03 nullable user_id + cascadeOnDelete on every domain table — UserIdColumnArchTest extended with the five new table names so the multi-user-readiness invariant continues to cover everything"
    - "Eloquent models use the existing Modules\\Core\\Public\\Concerns\\BelongsToUser trait verbatim — same pattern as Modules/Chains/Models/ChainLink + CardStatement"
    - "Spatie Data DTOs ship as `final class ... extends Data` (or `final readonly class` where appropriate) with readonly constructor properties and no methods beyond the constructor — matches the Phase 5 Chains DTO shape"
    - "OAuthSecretsRepository is the first atomic-chmod-600 filesystem repository in the codebase — performRename hook is extracted as a protected method so tests can simulate a failed rename without destructive filesystem operations"
    - "SecretsWriteFailed exception's message NEVER carries the payload — only the absolute path and a generic description — so logging surfaces above the repository can never accidentally leak credential material"
    - "InboxMessageQuery streams rows via PHP generators + the query builder's cursor() so the parser stage can iterate large fetched backlogs without ever materialising the full set into memory"

key-files:
  created:
    - Modules/EmailScan/Database/Migrations/2026_05_16_020001_create_inboxes_table.php
    - Modules/EmailScan/Database/Migrations/2026_05_16_020002_create_inbox_scan_state_table.php
    - Modules/EmailScan/Database/Migrations/2026_05_16_020003_create_inbox_messages_table.php
    - Modules/EmailScan/Database/Migrations/2026_05_16_020004_create_known_senders_table.php
    - Modules/EmailScan/Database/Migrations/2026_05_16_020005_create_discovered_senders_table.php
    - Modules/EmailScan/Models/Inbox.php
    - Modules/EmailScan/Models/InboxScanState.php
    - Modules/EmailScan/Models/InboxMessage.php
    - Modules/EmailScan/Models/KnownSender.php
    - Modules/EmailScan/Models/DiscoveredSender.php
    - Modules/EmailScan/Public/Dto/ScanCursor.php
    - Modules/EmailScan/Public/Dto/InboxCredentials.php
    - Modules/EmailScan/Public/Dto/InboxHealthDto.php
    - Modules/EmailScan/Public/Dto/InboxHealthLine.php
    - Modules/EmailScan/Public/Dto/KnownSenderDto.php
    - Modules/EmailScan/Public/Dto/InboxMessageDto.php
    - Modules/EmailScan/Public/Dto/EmailScanHealthTile.php
    - Modules/EmailScan/Public/Services/InboxMessageQuery.php
    - Modules/EmailScan/Public/Services/OAuthSecretsRepository.php
    - Modules/EmailScan/Public/Services/SecretsWriteFailed.php
    - Modules/EmailScan/tests/Unit/Dto/ScanCursorTest.php
    - Modules/EmailScan/tests/Unit/Services/InboxMessageQueryTest.php
    - Modules/EmailScan/tests/Unit/Services/OAuthSecretsRepositoryTest.php
    - Modules/EmailScan/tests/Unit/Services/OAuthSecretsAtomicRotationTest.php
    - Modules/EmailScan/tests/Unit/Services/OAuthSecretsDirModeTest.php
    - Modules/EmailScan/tests/Integration/MigrationsTest.php
    - Modules/EmailScan/tests/Integration/ReFetchIdempotentTest.php
  modified:
    - Modules/EmailScan/Providers/EmailScanServiceProvider.php
    - tests/Contracts/UserIdColumnArchTest.php

key-decisions:
  - "Phase 6 models use `protected $fillable = [...]` (explicit allow-list) rather than `$guarded = ['id']` — Phase 5 Chains models (CardStatement + ChainLink) ship explicit $fillable so the new EmailScan models mirror that exact shape for cross-module consistency"
  - "OAuthSecretsRepository is NOT declared final — the AtomicRotationTest substitutes a subclass with an overridden performRename() hook to simulate a failed rename. The contract is enforced via the protected method + the singleton binding, not the final modifier"
  - "OAuth secret literals (refresh_token / client_secret / access_token) appear in the OAuthSecretsRepository source code as plain string keys — the noOAuthTokensInEmailScanSchema boundary test grep is scoped to Modules/EmailScan/Database/Migrations/ only, so the Public/Services/ path is structurally exempt and no string indirection is required"
  - "InboxMessageQuery::forStatus returns a typed `Generator` rather than a plain `iterable` — the generator type makes the laziness contract visible at the type level and lets the lazy-iteration test explicitly assert `$gen->valid()` partway through the stream"
  - "Integration + Unit tests that need RefreshDatabase declare `uses(RefreshDatabase::class)` explicitly at the top — the root tests/Pest.php foreach map only binds RefreshDatabase to Feature + Contracts directories, not Integration or Unit, so the explicit uses() keeps test bootstrap deterministic"

patterns-established:
  - "Migration enum-trigger pattern reused verbatim from Phase 5 chain_links.kind — five new enum columns (inboxes.provider, inbox_scan_state.status, inbox_messages.status, known_senders.source, discovered_senders.state) each get a paired BEFORE INSERT / BEFORE UPDATE trigger that RAISE(ABORT) on unknown values"
  - "Atomic-chmod-600 filesystem repository pattern — the only DI touchpoint to a credentials-bearing file is a singleton-bound Public service; writes go through tmp+flock+fwrite+fflush+fsync+chmod+rename; the rename hook is extracted as a protected method so failure-injection tests stay non-destructive"
  - "Typed exceptions for credential-write failures NEVER carry the payload in the message — the AtomicRotationTest enforces this with an explicit `str_contains($e->getMessage(), $secret) === false` assertion"

requirements-completed: [EML-03, EML-06, PLT-03]

duration: ~25min
completed: 2026-05-16
---

# Phase 6 Plan 02: Wave 1 Schema + Public Surface + Atomic Secrets Repository Summary

**Five user-scoped migrations + five Eloquent models + seven Spatie Data DTOs (incl. ScanCursor value object normalising Gmail + Graph cursors) + InboxMessageQuery streaming Public surface + OAuthSecretsRepository's atomic chmod-600 JSON I/O — Wave 1 makes the Phase 6/7 schema contract + the PLT-03 invariant structurally enforced.**

## Performance

- **Duration:** ~25 min (worktree run; includes one-time composer install + Vite manifest copy to clear pre-existing worktree-bootstrap blockers)
- **Started:** 2026-05-16T22:56:23Z
- **Completed:** 2026-05-16T23:21:22Z
- **Tasks:** 3
- **Files created:** 27 (5 migrations + 5 models + 7 DTOs + 3 services + 7 tests)
- **Files modified:** 2 (EmailScanServiceProvider + UserIdColumnArchTest)

## Accomplishments

- Five Phase 6 tables exist on disk with full FND-03 + enum-trigger discipline; `migrate:fresh` lands them cleanly and `known_senders` carries the three seeded system rows
- `inbox_messages.UNIQUE (inbox_id, provider_message_id)` makes a re-fetch idempotent — proven via the ReFetchIdempotentTest pair (insertOrIgnore is no-op; raw insert raises SQLITE_CONSTRAINT)
- Seven Public DTOs + InboxMessageQuery + OAuthSecretsRepository + SecretsWriteFailed all ship as singleton-bound services that Phase 7 (parser stage) can consume on day one
- OAuthSecretsRepository is the first atomic-chmod-600 filesystem repository in the codebase; failure-injection test proves prior content is byte-for-byte intact after a mid-write rename failure, and the SecretsWriteFailed exception never leaks credential payload
- Boundary invariants stay green: noOAuthTokensInEmailScanSchema (migrations carry no credential column), noOtherInboxScanStateMutator (no production writes against inbox_scan_state outside the future state machine), noTransactionWritesFromEmailScan (still trivially satisfied)
- PLT-03 invariant (OAuth secrets live outside the DB) is now structurally enforced: the only DI touchpoint is OAuthSecretsRepository, which only writes to storage/app/secrets/email-oauth.json
- Full PHPStan level 10 strict green; Laravel Pint green; 40 EmailScan tests pass + the 20 cross-cutting contract tests still pass

## Task Commits

1. **Task 1: Five migrations + system seed + Eloquent models + UserIdColumnArchTest extension** — `cf3bc00` (feat)
2. **Task 2: Public DTOs (ScanCursor + InboxCredentials + InboxHealthDto + KnownSenderDto + InboxMessageDto + EmailScanHealthTile + InboxHealthLine) + InboxMessageQuery + 4 tests** — `f85e045` (feat)
3. **Task 3: OAuthSecretsRepository + atomic chmod-600 JSON I/O + SecretsWriteFailed + 3 unit tests** — `2b29a6b` (feat)

## Files Created/Modified

### Schema + models (Task 1)

- `Modules/EmailScan/Database/Migrations/2026_05_16_020001_create_inboxes_table.php` — inboxes (id, user_id, provider [enum-trigger gmail/microsoft], email, backfill_window_months, backfill_progress JSON, timestamps) + indexes on (user_id, provider) and (user_id, created_at)
- `Modules/EmailScan/Database/Migrations/2026_05_16_020002_create_inbox_scan_state_table.php` — inbox_scan_state (id, user_id, inbox_id, folder default 'INBOX', last_history_id, last_delta_link text, last_scan_at, status [enum-trigger idle/backfilling/scanning/rate_limited/needs_reauth/error], error_message text, retry_attempts) + UNIQUE (inbox_id, folder) + index (user_id, status)
- `Modules/EmailScan/Database/Migrations/2026_05_16_020003_create_inbox_messages_table.php` — inbox_messages (id, user_id, inbox_id, provider_message_id 128, internal_date, sender_email 320, sender_name nullable, subject 998 nullable, status [enum-trigger fetched/parsed/skipped/unmatched], fetched_at) + UNIQUE (inbox_id, provider_message_id) + indexes (user_id, status) + (inbox_id, internal_date)
- `Modules/EmailScan/Database/Migrations/2026_05_16_020004_create_known_senders_table.php` — known_senders (id, user_id nullable, email_pattern 320, label 100, source [enum-trigger system/user], added_at) + indexes (user_id) + (source) + 3-row system seed
- `Modules/EmailScan/Database/Migrations/2026_05_16_020005_create_discovered_senders_table.php` — discovered_senders (id, user_id, inbox_id, sender_email 320, sender_name nullable, occurrence_count, last_seen_at, sample_message_id nullable nullOnDelete FK to inbox_messages, state [enum-trigger candidate/added/dismissed]) + UNIQUE (user_id, inbox_id, sender_email) + indexes (user_id, state) + (user_id, occurrence_count)
- `Modules/EmailScan/Models/Inbox.php` + `InboxScanState.php` + `InboxMessage.php` + `KnownSender.php` + `DiscoveredSender.php` — Eloquent models, `final class … extends Model`, `BelongsToUser` trait, explicit `$fillable`, `immutable_datetime` casts on timestamps, BelongsTo relationships where applicable
- `tests/Contracts/UserIdColumnArchTest.php` — extended with `discovered_senders`, `inbox_messages`, `inbox_scan_state`, `inboxes`, `known_senders` (alphabetically inserted into the existing list)

### Public DTOs + InboxMessageQuery (Task 2)

- `Modules/EmailScan/Public/Dto/ScanCursor.php` — `final class … extends Data`, three named factories (`gmail`, `microsoft`, `emptyFor`), `isEmpty()` helper, provider + payload validation
- `Modules/EmailScan/Public/Dto/InboxCredentials.php` + `InboxHealthDto.php` + `InboxHealthLine.php` + `KnownSenderDto.php` + `InboxMessageDto.php` + `EmailScanHealthTile.php` — pure data DTOs with readonly constructor properties
- `Modules/EmailScan/Public/Services/InboxMessageQuery.php` — `final readonly class`, constructor-injected DatabaseManager, `forStatus(string): Generator<int, InboxMessageDto>` streams rows via the query builder's `cursor()` + a lazy generator; status validation
- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` — registered `InboxMessageQuery` (and later `OAuthSecretsRepository`) as singletons

### OAuthSecretsRepository (Task 3)

- `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` — `class` (not final — failure-injection test subclasses it), constructor-injected `Illuminate\Filesystem\Filesystem`, public surface (`hasProviderClient`, `saveProviderClient`, `loadProviderClient`, `loadInbox`, `saveInboxRefreshToken`, `rotateRefreshToken`, `removeInbox`), `protected function performRename(string $tmp, string $final): bool` hook, private `writeAtomic(array): void` implementing tmp+fwrite+fflush+fsync+chmod+rename
- `Modules/EmailScan/Public/Services/SecretsWriteFailed.php` — typed `RuntimeException` subclass; constructor takes a message that NEVER carries the JSON payload
- `Modules/EmailScan/tests/Unit/Services/OAuthSecretsRepositoryTest.php` — 8 happy-path tests including file-mode + dir-mode + rejection assertions
- `Modules/EmailScan/tests/Unit/Services/OAuthSecretsAtomicRotationTest.php` — 2 failure-injection tests: prior file intact after rename failure + exception message carries no payload
- `Modules/EmailScan/tests/Unit/Services/OAuthSecretsDirModeTest.php` — 2 dir-mode tests for first-write creation + idempotent re-write

## Decisions Made

- **Phase 5 Chains models ship explicit `$fillable` — Phase 6 follows that.** Verified via `Modules/Chains/Models/CardStatement.php` (line 44-54) and `ChainLink.php` (line 49-59). Could have gone with `$guarded = ['id']` but consistency with the prior wave's precedent wins.
- **OAuthSecretsRepository is NOT `final`.** The atomic-rotation test extends it to override `performRename` returning false (simulating a rename failure) — far less destructive than mutating the filesystem mid-test. The contract is enforced via the protected method + the singleton binding.
- **No string indirection for `refresh_token` / `client_secret` / `access_token` in the OAuthSecretsRepository source.** Initially considered an indirection helper to keep the strings out of grepable source. After reading the boundary test (`noOAuthTokensInEmailScanSchema` at `tests/Contracts/BoundaryArchTest.php` line 330-370), the grep is scoped to `Modules/EmailScan/Database/Migrations/` only, so the Public/Services/ source is structurally exempt and indirection would be cargo-cult.
- **`forStatus` returns a typed `Generator` rather than a plain `iterable`.** Makes laziness explicit at the type level and lets the lazy-iteration test directly call `$gen->valid()` and `$gen->next()` to prove the cursor isn't buffering the full result set.
- **`uses(RefreshDatabase::class)` explicit in Integration + Unit tests that need it.** The root tests/Pest.php foreach map only binds RefreshDatabase to Feature + Contracts directories (lines 32-54). The new MigrationsTest + ReFetchIdempotentTest + InboxMessageQueryTest live under Integration and Unit respectively and explicitly declare the trait so the bootstrap stays deterministic.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Re-bootstrapped worktree environment (composer install + .env copy + public/build copy)**

- **Found during:** Task 1 (initial pest invocation)
- **Issue:** The worktree spawned without `vendor/`, without `.env`, and without `public/build/manifest.json`. Running the existing UserIdColumnArchTest baseline immediately produced fatal class-not-found / phpdotenv / Vite-manifest errors.
- **Fix:**
  1. `cp /Users/wesselverheij/Development/diederik/.env .env` (clears phpdotenv warning + DB_CONNECTION env var resolution).
  2. `composer install --no-interaction --no-progress` (populates vendor/illuminate/* + spatie/* etc.).
  3. `cp -r /Users/wesselverheij/Development/diederik/public/build public/build` (clears Vite-manifest errors in HTTP-rendering tests like Chains/NextIcsSettlementTileTest).
  4. `touch database/database.sqlite` (the file is required by PHPStan's bootstrap step that runs the SqliteOptimizationsProvider's PRAGMA journal_mode=WAL).
- **Files modified:** none (every fix touches gitignored paths; `.env`, `vendor/`, `public/build/`, `database/database.sqlite` are all in `.gitignore`).
- **Verification:** UserIdColumnArchTest baseline green (1 passed, 12 assertions), then re-green after the migration additions (1 passed, 22 assertions). Full Pest suite runs in ~32 s with only the documented pre-existing `Modules\Ledger\tests\Unit\TransactionTypeTest` failure (covered by `<known_failure>` in the executor prompt).

**2. [Rule 1 - Bug] PHPStan strict-rules cleanup in OAuthSecretsRepository array handling**

- **Found during:** Task 3 (full phpstan analyse gate after writing OAuthSecretsRepository)
- **Issue:** Initial draft produced 9 strict-rules errors. The mixed-typed JSON reads relied on `array<string, mixed>` PHPDoc annotations that PHPStan couldn't infer from `json_decode(..., true)` (returns `array<mixed, mixed>` in strict mode). `findInboxEntry`'s return type was annotated as `array<int, array<string, mixed>>` when it actually returns a single entry's `array<string, mixed>|null`. `asInboxList`'s `array_values()` call on a `list<...>` typed array tripped the `arrayValues.list` rule.
- **Fix:**
  1. `readAll()` now narrows the json_decode result via an explicit foreach with `(string) $key` cast — produces `array<string, mixed>`.
  2. `findInboxEntry`'s return type corrected to `array<string, mixed>|null`.
  3. `asInboxList` returns `list<array<string, mixed>>` and the redundant `array_values()` calls in `saveInboxRefreshToken` + `rotateRefreshToken` removed.
- **Files modified:** `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` (single-file change).
- **Verification:** `vendor/bin/phpstan analyse --memory-limit=1G` exits `[OK] No errors` across 219 files.
- **Committed in:** `2b29a6b` (Task 3 commit — bundled with the implementation so the full phpstan gate passes in the atomic commit).

**3. [Rule 1 - Bug] OAuthSecretsRepositoryTest had a syntax error in null-safe array access**

- **Found during:** Task 3 (initial pest invocation after writing the three test files)
- **Issue:** `$loaded?['client_id']` is not valid PHP — null-safe array access doesn't exist as a language construct (only `?->` for object access). Caused a fatal ParseError at line 56 of OAuthSecretsAtomicRotationTest.php.
- **Fix:** Replaced with the two-line idiom `expect($loaded)->not->toBeNull(); expect($loaded['client_id'])->toBe(...)`.
- **Files modified:** `Modules/EmailScan/tests/Unit/Services/OAuthSecretsAtomicRotationTest.php` (single-file change).
- **Verification:** Re-ran the three Task 3 test files (12 passed, 37 assertions).
- **Committed in:** `2b29a6b` (Task 3 commit).

---

**Total deviations:** 3 auto-fixed (1 blocking, 2 bugs)
**Impact on plan:** One blocker was pure worktree-environment bootstrapping (no production code touched, all changes to gitignored paths). Two bugs were self-introduced and fixed in the same plan cycle. No scope creep.

## Issues Encountered

- **PHPStan needed `database/database.sqlite` present at bootstrap time** because `SqliteOptimizationsProvider` runs `PRAGMA journal_mode = WAL` on connection-established events. Worked around with `touch database/database.sqlite` — same approach the orchestrator would use on a clean checkout. The fix is environment-only (gitignored path).
- **Full Pest suite initially reported 39 failures on first run** before the `public/build/` manifest was copied. Every failure was the same `Vite manifest not found` shape on HTTP-rendering tests. After the one-time copy, the full suite is green except for the documented `<known_failure>` (`Modules\Ledger\tests\Unit\TransactionTypeTest::it rejects an invalid transaction type at the DB layer`).

## User Setup Required

None — Wave 1 ships infrastructure only. The first user-facing surface (OAuth consent flow + /inboxes page) lands in Plans 03 / 04 / 07.

## Output-spec narrative

The plan's `<output>` block asked five specific questions. Answers:

1. **Verbatim final column lists per table:** Inboxes — id, user_id (nullable), provider (string 16), email (string 320), backfill_window_months (unsignedTinyInteger default 3), backfill_progress (json nullable), timestamps. Inbox_scan_state — id, user_id (nullable), inbox_id, folder (string 64 default 'INBOX'), last_history_id (string 64 nullable), last_delta_link (text nullable), last_scan_at (timestamp nullable), status (string 32 default 'idle'), error_message (text nullable), retry_attempts (unsignedInteger default 0), timestamps. Inbox_messages — id, user_id (nullable), inbox_id, provider_message_id (string 128), internal_date (timestamp), sender_email (string 320), sender_name (string 320 nullable), subject (string 998 nullable), status (string 16 default 'fetched'), fetched_at (timestamp), timestamps. Known_senders — id, user_id (nullable), email_pattern (string 320), label (string 100), source (string 16 default 'user'), added_at (timestamp), timestamps. Discovered_senders — id, user_id (nullable), inbox_id, sender_email (string 320), sender_name (string 320 nullable), occurrence_count (unsignedInteger default 1), last_seen_at (timestamp), sample_message_id (foreignId nullable nullOnDelete to inbox_messages), state (string 16 default 'candidate'), timestamps.
2. **`$fillable` vs `$guarded`:** Phase 5 Chains models ship explicit `$fillable`. Phase 6 mirrors that exact shape — `Inbox::$fillable` and the other four models list every non-id column explicitly. No model uses `$guarded`.
3. **chmod-600 file-mode test result:** `OAuthSecretsRepositoryTest::it persists the JSON file with chmod 0600 and dir 0700` reads `fileperms($this->path) & 0777` and `decoct(...)` returns `'600'`; the parent dir returns `'700'`. Both assertions pass on macOS 24.6.0 (PHP_OS_FAMILY=Darwin).
4. **`function_exists('fsync')`:** Returns `true` on the host PHP runtime (verified inline via `php -r`). The repository unconditionally calls `@fsync($fp)` inside the `function_exists()` guard so the path is exercised; on platforms without fsync the call is gracefully skipped.
5. **`SecretsWriteFailed` payload-leak grep:** `OAuthSecretsAtomicRotationTest::it SecretsWriteFailed message never carries the JSON payload` calls `saveInboxRefreshToken(..., 'super-secret-token', ...)` against a repository whose `performRename` always returns false, catches the thrown `SecretsWriteFailed`, and asserts `str_contains($e->getMessage(), 'super-secret-token') === false` AND `str_contains($e->getMessage(), 'leak@test.local') === false`. Both assertions pass; the exception message only carries the absolute path + a generic "atomic rename failed" description.
6. **Deviation from PATTERNS.md analog structure:** None. The five migrations + five models are byte-similar to their Chains analogs (just different column lists). The OAuthSecretsRepository is genuinely new (no codebase analog — RESEARCH.md Example 2 was the template).

## Next Phase Readiness

- Wave 1 schema + Public DTOs + OAuthSecretsRepository land cleanly. Plan 03 (Gmail OAuth) and Plan 04 (Microsoft OAuth) can now persist refresh tokens via `OAuthSecretsRepository::saveInboxRefreshToken(...)` without touching the file directly.
- Plan 05 (BackfillInboxJob) can write `inbox_messages` rows via `insertOrIgnore` against the UNIQUE (inbox_id, provider_message_id) constraint — re-fetch idempotency is structurally enforced.
- Plan 07 (InboxScanStateMachine) will be the only legal mutator of `inbox_scan_state.status`; the boundary test is already in place and currently trivially satisfied.
- Phase 7's parser stage can consume `app(InboxMessageQuery::class)->forStatus('fetched')` from day one — the streaming generator is the contract.

## Self-Check: PASSED

Verified after writing this SUMMARY:

- **Created files exist:** All 27 paths under `Modules/EmailScan/` and the two modified files (`Modules/EmailScan/Providers/EmailScanServiceProvider.php`, `tests/Contracts/UserIdColumnArchTest.php`) resolve on disk.
- **Commits exist:** `git log --oneline -3` shows `2b29a6b f85e045 cf3bc00` in reverse-chronological order on the current worktree branch.
- **Test suite green:** `vendor/bin/pest Modules/EmailScan` reports 40 passed (137 assertions); `vendor/bin/pest tests/Contracts` reports 21 passed (65 assertions); `vendor/bin/phpstan analyse --memory-limit=1G` exits `[OK] No errors` over 219 files; `vendor/bin/pint --test Modules/EmailScan` reports `passed`; full `vendor/bin/pest` reports 778 passed, 5 skipped, 1 failed (the pre-existing `Modules\Ledger\tests\Unit\TransactionTypeTest` documented in the executor's `<known_failure>`).

---

*Phase: 06-email-receipt-ingestion-infrastructure*
*Completed: 2026-05-16*
