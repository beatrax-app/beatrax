---
phase: 6
slug: email-receipt-ingestion-infrastructure
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-16
---

# Phase 6 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Source: distilled from `06-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.x (parallel) + `pestphp/pest-plugin-laravel` + `pestphp/pest-plugin-arch` |
| **Config file** | `tests/Pest.php` + `Modules/EmailScan/tests/Pest.php` (inert) + `phpunit.xml` testsuites |
| **Quick run command** | `pest --filter=EmailScan --parallel` |
| **Full suite command** | `composer test` (alias for `pest --parallel`) |
| **Estimated runtime** | ~25s quick / ~120s full (project trend) |

---

## Sampling Rate

- **After every task commit:** `pest --filter=EmailScan --parallel`
- **After every plan wave:** `composer test` + `composer analyse` (Larastan level 10) + `composer format:check` (Pint)
- **Before `/gsd:verify-work`:** Full suite must be green; manual smoke against a real Gmail or Outlook account covers SC#3 + SC#4 (documented in phase-close summary)
- **Max feedback latency:** 30s

---

## Per-Task Verification Map

| Plan | Wave | Requirement | Secure Behavior | Test Type | Automated Command | File Exists |
|------|------|-------------|-----------------|-----------|-------------------|-------------|
| 01 (Wave 0) | 0 | — | Module manifest + autoload registered | contracts | `pest tests/Contracts/ModuleManifestTest.php` | ❌ W0 |
| 01 (Wave 0) | 0 | PLT-05 | Composer audit asserts no `ext-imap` / `webklex/*` | contracts | `pest tests/Contracts/NoExtImapTest.php` | ✅ extend |
| 01 (Wave 0) | 0 | — | `BoundaryArchTest::noTransactionWritesFromEmailScan` invariant | contracts | `pest tests/Contracts/BoundaryArchTest.php` | ✅ extend |
| 01 (Wave 0) | 0 | — | `Modules\EmailScan\Internal` only used inside `Modules\EmailScan` | contracts | `pest tests/Contracts/BoundaryArchTest.php` | ✅ extend |
| 01 (Wave 0) | 0 | — | No facade calls in `Modules/EmailScan/` (Cache::driver('redis') carve-out only) | contracts | `pest tests/Contracts/BoundaryArchTest.php` | ✅ extend |
| 02 (Schema) | 1 | EML-03 | Migrations create `inboxes`, `inbox_messages`, `inbox_scan_state`, `known_senders`, `discovered_senders` with user-scope FKs | integration | `pest Modules/EmailScan/tests/Integration/MigrationsTest.php` | ❌ W0 |
| 02 (Schema) | 1 | — | UNIQUE `(inbox_id, provider_message_id)` makes re-fetch idempotent | integration | `pest Modules/EmailScan/tests/Integration/ReFetchIdempotentTest.php` | ❌ W0 |
| 03 (Secrets) | 1 | PLT-03 | `email-oauth.json` written with mode 0600 via atomic rotation | unit | `pest Modules/EmailScan/tests/Unit/Services/OAuthSecretsRepositoryTest.php` | ❌ W0 |
| 03 (Secrets) | 1 | PLT-03 | Failed rotation mid-write leaves prior file intact | unit | `pest Modules/EmailScan/tests/Unit/Services/OAuthSecretsAtomicRotationTest.php` | ❌ W0 |
| 03 (Secrets) | 1 | PLT-03 | Parent dir `storage/app/secrets/` is `chmod 700` on creation | unit | `pest Modules/EmailScan/tests/Unit/Services/OAuthSecretsDirModeTest.php` | ❌ W0 |
| 04 (OAuth Gmail) | 2 | EML-01 | User authorizes Gmail via OAuth2 (callback exchange + token save) | feature | `pest Modules/EmailScan/tests/Feature/OAuthCallbackGmailTest.php` | ❌ W0 |
| 04 (OAuth Gmail) | 2 | EML-01 | OAuth state CSRF mismatch raises 400 | unit | `pest Modules/EmailScan/tests/Unit/OAuth/StateMismatchTest.php` | ❌ W0 |
| 04 (OAuth Gmail) | 2 | EML-08 | `invalid_grant` → status `needs_reauth` + dashboard toast fires once | feature | `pest Modules/EmailScan/tests/Feature/InvalidGrantToastTest.php` | ❌ W0 |
| 05 (OAuth MS) | 2 | EML-02 | User authorizes Microsoft 365 via OAuth2 | feature | `pest Modules/EmailScan/tests/Feature/OAuthCallbackMicrosoftTest.php` | ❌ W0 |
| 06 (Wizard) | 2 | D-114/EML-01 | OAuth-client wizard modal closes + redirects to consent on submit | feature | `pest Modules/EmailScan/tests/Feature/OAuthClientWizardModalTest.php` | ❌ W0 |
| 07 (Backfill) | 3 | EML-04 | Backfill window (1–12 mo) configurable + chunked + non-blocking | feature+integration | `pest Modules/EmailScan/tests/Feature/BackfillWindowModalTest.php`, `pest Modules/EmailScan/tests/Integration/BackfillChunkedJobTest.php` | ❌ W0 |
| 07 (Backfill) | 3 | EML-04 | Window slider clamps to 1..12 even with crafted POST | unit | `pest Modules/EmailScan/tests/Unit/Http/BackfillWindowValidationTest.php` | ❌ W0 |
| 07 (Backfill) | 3 | EML-03 | `BackfillInboxJob` runs per-inbox-id with `ShouldBeUniqueUntilProcessing` | integration | `pest Modules/EmailScan/tests/Integration/BackfillPerInboxJobTest.php` | ❌ W0 |
| 07 (Backfill) | 3 | — | `.eml` write succeeds but DB tx rollback → orphan `.eml` cleaned up | integration | `pest Modules/EmailScan/tests/Integration/EmlOrphanCleanupTest.php` | ❌ W0 |
| 07 (Backfill) | 3 | — | Two parallel `BackfillInboxJob` for different inboxes converge without SQLITE_BUSY | integration | `pest Modules/EmailScan/tests/Integration/ConcurrentBackfillTest.php` | ❌ W0 |
| 08 (Incremental) | 3 | EML-06 | Kill/restart resume — `last_history_id` / `last_delta_link` re-read on next scan | integration | `pest Modules/EmailScan/tests/Integration/ResumeFromCursorTest.php` | ❌ W0 |
| 08 (Incremental) | 3 | EML-06 | `ScanCursor` value object round-trips Gmail historyId + Graph deltaLink | unit | `pest Modules/EmailScan/tests/Unit/Dto/ScanCursorTest.php` | ❌ W0 |
| 08 (Incremental) | 3 | EML-06 | Gmail cursor 404 → fallback to date-bounded `messages.list` walk | integration | `pest Modules/EmailScan/tests/Integration/GmailCursorExpiryFallbackTest.php` | ❌ W0 |
| 08 (Incremental) | 3 | EML-06 | Graph cursor 410 → fallback to date-bounded walk | integration | `pest Modules/EmailScan/tests/Integration/GraphCursorExpiryFallbackTest.php` | ❌ W0 |
| 08 (Incremental) | 3 | EML-08 | Gmail `rateLimitExceeded` → status `rate_limited` + `Retry-After` honoured | integration | `pest Modules/EmailScan/tests/Integration/GmailRateLimitBackoffTest.php` | ❌ W0 |
| 08 (Incremental) | 3 | EML-08 | Graph 429 → status `rate_limited` + `Retry-After` honoured | integration | `pest Modules/EmailScan/tests/Integration/GraphRateLimitBackoffTest.php` | ❌ W0 |
| 09 (Discovery) | 3 | EML-06 | `DiscoveryScanJob` writes only sender metadata (no `.eml` blobs) | integration | `pest Modules/EmailScan/tests/Integration/DiscoveryScanNoEmlBlobsTest.php` | ❌ W0 |
| 10 (UI /inboxes) | 4 | EML-08 | `/inboxes` health badges render correctly per status | feature | `pest Modules/EmailScan/tests/Feature/InboxesHealthBadgeRenderTest.php` | ❌ W0 |
| 10 (UI /inboxes) | 4 | — | Empty-state hero renders on first `/inboxes` visit with zero inboxes | feature | `pest Modules/EmailScan/tests/Feature/InboxesEmptyStateTest.php` | ❌ W0 |
| 10 (UI /inboxes) | 4 | — | Backfill progress wire:poll status query reflects current `inbox_scan_state` | feature | `pest Modules/EmailScan/tests/Feature/BackfillProgressPollTest.php` | ❌ W0 |
| 10 (UI /inboxes) | 4 | — | Cross-user 404: `/inboxes/{id}/scan-now` for another user's inbox → 404 | feature | `pest Modules/EmailScan/tests/Feature/CrossUserInboxIsolationTest.php` | ❌ W0 |
| 10 (UI /inboxes) | 4 | — | Top-nav inboxes badge fed by ViewFactoryContract composer (no `view()` helper) | feature | `pest Modules/EmailScan/tests/Feature/TopNavBadgeViaComposerTest.php` | ❌ W0 |
| 11 (Dashboard tile) | 4 | EML-08 | Dashboard "Email scan health" tile renders correct line counts per inbox health | feature | `pest tests/Feature/EmailScanHealthTileTest.php` | ❌ W0 |
| 12 (launchd) | 4 | PLT-04 | `diederik:install --launchd` produces three plists with correct `PHP_BINARY` substitution | feature | `pest tests/Feature/InstallLaunchdCommandTest.php` | ❌ W0 |

*Status legend: ✅ exists / extend · ❌ W0 = stub created in Wave 0 · ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `Modules/EmailScan/composer.json` — module manifest with autoload + autoload-dev
- [ ] `Modules/EmailScan/Providers/EmailScanServiceProvider.php` — minimal bindings (no jobs yet)
- [ ] `Modules/EmailScan/tests/TestCase.php` + `Modules/EmailScan/tests/Pest.php` — module test bootstrap (inert)
- [ ] `tests/Pest.php` foreach map row addition for `Modules/EmailScan`
- [ ] `phpunit.xml` testsuite entries for the new module
- [ ] `composer.json autoload-dev psr-4` entry for `Modules\\EmailScan\\Tests\\`
- [ ] `Modules/EmailScan/tests/fixtures/eml/{paypal,ics,googleplay}/*.eml` — synthesised anonymised fixtures (matches Phase 5 D-107 + Phase 4 D-58 anonymisation discipline)
- [ ] `Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php` + `FakeGraphApiClient.php` — fixture-driven fakes (D-140)
- [ ] `tests/Contracts/BoundaryArchTest.php` extension — `noTransactionWritesFromEmailScan` rule, plus `Modules\EmailScan\Internal` containment, plus `Cache::driver` carve-out for the three new jobs
- [ ] `tests/Contracts/NoExtImapTest.php` extension — adds the `composer.lock` `webklex` check on top of the existing `ext-imap` lint
- [ ] Failing scaffolds for every test file listed in the per-task verification map (RED baseline matches Phase 1-05 / Phase 5-01b precedent)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| User completes the Google Cloud Console OAuth-client registration wizard end-to-end | EML-01 / D-114 | Requires interactive GCP console + push-to-production step | Document in phase-close summary: register a fresh GCP project, run the in-app wizard, confirm `email-oauth.json` updated, confirm consent flow lands and inbox row appears |
| User completes the Azure App Registration wizard end-to-end | EML-02 / D-114 | Requires interactive Azure portal flow | Same as above for Azure |
| Real-Gmail UID-resume smoke (SC#3) | EML-06 | Requires multi-day-running scanner against a real account; cursor expiry path tested by simulating but verified only against the real provider | Connect a Gmail inbox; let `IncrementalScanJob` run for 30+ days; verify cursor still resumes after restart and after the historyId envelope expires |
| Real-Outlook UID-resume smoke (SC#3) | EML-06 | Same — Graph delta-link expiry is not formally published | Same as above for Outlook |
| Health view freshness (SC#4) | EML-08 | Time-window assertions on "last scan: N hours ago" are time-sensitive | Manual: connect inbox, observe tile renders "just now", wait 1h, refresh, confirm "1 hour ago"; pause scanner, wait 24h, confirm tile dot turns gray; revoke OAuth grant, confirm dot turns red |
| OAuth client secrets + refresh tokens live outside DB (SC#5) | PLT-03 | Visual filesystem verification is the auditable artifact | `ls -la storage/app/secrets/` shows `0700` dir + `0600` file; `sqlite3 .../diederik.sqlite '.tables'` confirms no `oauth_*` tables |
| Background workers run via `launchd` (SC#5) | PLT-04 | macOS-level service registration is OS-side state | After `php artisan diederik:install --launchd`: `launchctl list \| grep com.diederik` shows three entries; reboot Mac; confirm Horizon resumes within 30s |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
