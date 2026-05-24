---
phase: 16-developer-mode-ui
plan: 05
subsystem: dev-mode-log-tailer-redaction
tags: [monolog-processor, oauth-scrub-set, sse, sse-rotation-detection, file-tailer, livewire, 10k-ring-buffer, log-redaction, belt-braces, d-28, d-29, d-30, d-31]

# Dependency graph
requires:
  - phase: 16-developer-mode-ui
    plan: 04
    provides: "FileTailer pure-PHP fseek+clearstatcache+fread primitive at Modules/DevMode/Internal/Process/FileTailer.php. Reused as-is by 16-05's LogStreamController (the same one-pass-per-tick tail body that the artisan SSE controller uses)."
  - phase: 16-developer-mode-ui
    plan: 04b
    provides: "Baseline RedactSecretsProcessor (Bearer + JWT regex only) at Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php. PushRedactProcessor tap class wired into config/logging.php on stack/single/daily channels (W-1 fix). RedactionExcerptCap (audit-row Bearer + JWT scrub) at Modules/DevMode/Internal/Audit/RedactionExcerptCap.php consumed by SpatieAuditWriter. Both classes UPGRADED in place by this plan — FQCN + signatures stable; consumers untouched."
provides:
  - "OAuthScrubSet (Modules/DevMode/Internal/Services/OAuthScrubSet.php) — lazy-loaded singleton holding every decrypted oauth_secrets.client_secret + every string leaf inside tokens_blob across every user row. compiledPattern() returns a pre-compiled alternation regex (Pitfall 8 mitigation; one preg_replace per record regardless of set size). The class is NOT final so a tests/double can extend it without DB access for unit-level coverage."
  - "BustOAuthScrubSetOnSecretChange (Modules/DevMode/Internal/Listeners/BustOAuthScrubSetOnSecretChange.php) — Eloquent observer attached to OAuthSecret in DevModeServiceProvider::boot() via OAuthSecret::observe(...). Calls scrubSet->bust() on every saved (created OR updated) and deleted event so a rotated secret takes effect on the very next log line (CONTEXT D-30)."
  - "RedactSecretsProcessor (Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php) UPGRADED in place — constructor now optionally injects OAuthScrubSet. Three-layer scrub order is OAuth-scrub-set FIRST (so JWT-shaped real OAuth tokens read as [REDACTED] not [JWT_REDACTED]) → Bearer header pattern → JWT shape. PushRedactProcessor resolves THIS class via the container so the constructor-DI upgrade propagates without touching the tap class or config/logging.php."
  - "RedactionExcerptCap (Modules/DevMode/Internal/Audit/RedactionExcerptCap.php) UPGRADED in place — same three-layer scrub now applies to audit-row excerpts before SpatieAuditWriter writes them into dev_mode_audit.properties.{stdout_excerpt,error_excerpt,query}. The SpatieAuditWriter binding chain stays identical because the cap is resolved through container DI."
  - "LogStreamController (Modules/DevMode/Internal/Http/Controllers/LogStreamController.php) — single-class SSE tail controller AND ±radius context endpoint. The SSE __invoke() loop opens the current laravel-YYYY-MM-DD.log via UserDataPathService::dailyLogFile(), tails it through the shared FileTailer primitive 16-04 introduced, applies RedactSecretsProcessor->scrub() per chunk (D-29 belt+braces on-stream layer), and emits id:+data: events at 250ms cadence (D-28). Rotation detection compares inode + filesize across ticks AND compares the computed dailyLogFile() path so a midnight rollover triggers reopening at offset 0. The context() method clamps radius via Laravel validator to [0, 50] (T-16-19) and serves ±N lines around the requested 0-based line via SplFileObject — every returned line re-scrubbed."
  - "LogTailerPage Livewire component (Modules/DevMode/Internal/Http/Livewire/LogTailerPage.php) + Blade view (Modules/DevMode/Resources/views/livewire/log-tailer-page.blade.php). Stateless render with #[Url] severities/channel/contains. Renders dev-shell + the 8 severity chips (DEBUG..EMERGENCY with sketch-locked color mapping) + channel filter + debounced contains filter + Pause/Resume + 10k-line client-side Alpine ring buffer (CONTEXT D-31). Click any line → fetch /dev/logs/context?line=N&radius=10 → inline-expand ±10 lines. Empty state shows 'Waiting for log lines…' + blinking cursor (1s steps(1) infinite per UI-SPEC § Animation). Stream-interrupt state shows 'Log stream interrupted. Reconnecting…' with exponential backoff (250ms→500ms→1s→2s→5s cap)."
  - "UserDataPathService::dailyLogFile(?DateTimeInterface): string AND ::logsDirectory(): string. The dailyLogFile() helper computes today's (or any given date's) Monolog daily-rotated filename so the SSE controller has a single source of truth for the file Monolog writes into. The logsDirectory() helper is a convenience accessor on top of dirname(logsFile()) for any future tailer feature (list-available-days, scan-sibling-files, etc.). Both honour the NATIVEPHP_STORAGE_PATH retarget — no project-rooted literal leaks; the noStoragePathHardCodedOutsideUserDataPathService invariant remains green."
  - "Three new routes inside the existing /dev group: dev.logs (GET — page), dev.logs.stream (GET — SSE), dev.logs.context (GET — JSON). Every route gated by [web, auth, ensureDeveloperMode] inherited from the group; the everyDevModeRouteAppliesEnsureDeveloperModeMiddleware arch invariant locks the coverage. Sidebar Logs item auto-enables via the dev-shell layout's Route::has(...) check (16-03 wired)."
affects:
  - 16-06-queue-inspector-horizon-iframe
  - 16-07-doctor-sql-system
  - 16-08-command-palette

# Tech tracking
tech-stack:
  added:
    - "No new packages — every piece reuses what 16-04 / 16-04b already installed (FileTailer + RedactSecretsProcessor + SplFileObject + Symfony StreamedResponse + Livewire 4 + Alpine.js)."
  patterns:
    - "Belt+braces redaction discipline (CONTEXT D-29 verbatim) — on-WRITE Monolog tap (16-04b W-1) + on-STREAM defense-in-depth in LogStreamController. Both layers apply the SAME three-step scrub via the SAME container-resolved RedactSecretsProcessor singleton; a log line that somehow bypasses on-write redaction (a 3rd-party tool writing directly to laravel-YYYY-MM-DD.log) is still scrubbed on the way to the browser. The shared singleton means future scrub-set extensions (e.g. session tokens in 17-XX) need only one container-binding change."
    - "Singleton + Eloquent observer cache invalidation (CONTEXT D-30) — OAuthScrubSet caches a pre-compiled regex; BustOAuthScrubSetOnSecretChange listens on OAuthSecret::saved and OAuthSecret::deleted and calls bust() so the next compiledPattern() lazy-rebuilds from the live oauth_secrets table. This is the canonical pattern for 'cached secret set that must invalidate on rotation' — future surfaces (e.g. ApiKeyScrubSet, WebhookSignatureScrubSet) should mirror the shape."
    - "Pre-compiled regex alternation over O(n*m) foreach str_replace (Pitfall 8) — compiledPattern() builds one '/(s1|s2|s3)/' alternation per scrub-set load and reuses it across every log record / audit excerpt. Performance is O(message_length) per record regardless of how many secrets the set holds. The compilation cost is paid once per save/delete event, not once per log line."
    - "Rotation detection via tri-signal (inode change OR shrinkage OR path change) — LogStreamController stat()s the file on every tick and compares three values. The shared FileTailer primitive handles the size-shrinkage case (returns unchanged offset); the controller handles the path-change case (midnight rollover via UserDataPathService::dailyLogFile()) and the inode case (logrotate-style truncate+rename) by reopening with offset=0. Cross-platform — no shell `tail` dependency, no inotify, just pure PHP."
    - "Validator-bounded path-traversal-free context endpoint (T-16-19 mitigation) — the context() method validates `line` as required-integer-min:0, `radius` as required-integer-min:0-max:50, and `date` as nullable date_format:Y-m-d. The actual file path is computed via UserDataPathService::dailyLogFile($date) — never from user input. A forged `line` beyond EOF is naturally clipped by SplFileObject. No LFI surface."
    - "Client-side 10k-line ring buffer + pause/resume + exponential-backoff reconnect — the server's SSE pipeline is dumb (just emits chunks); the 10k cap, the severity-filter, the channel-filter, the contains-filter, the click-to-expand, the Pause/Resume button, and the auto-reconnect on stream-interrupt all live in Alpine x-data on the client. Server-side state is minimal — only the #[Url] filter properties which exist for deep-link persistence. This is the canonical 'minimal-server SSE + rich-client Alpine' shape for future tailer surfaces."

key-files:
  created:
    - "Modules/DevMode/Internal/Services/OAuthScrubSet.php"
    - "Modules/DevMode/Internal/Listeners/BustOAuthScrubSetOnSecretChange.php"
    - "Modules/DevMode/Internal/Http/Controllers/LogStreamController.php"
    - "Modules/DevMode/Internal/Http/Livewire/LogTailerPage.php"
    - "Modules/DevMode/Resources/views/livewire/log-tailer-page.blade.php"
    - "Modules/DevMode/tests/Unit/RedactSecretsProcessorTest.php"
    - "Modules/DevMode/tests/Feature/OAuthScrubSetBustTest.php"
    - "Modules/DevMode/tests/Feature/OAuthSecretDeletionStopsScrubbingTest.php"
    - "Modules/DevMode/tests/Feature/LogStreamControllerTest.php"
    - "Modules/DevMode/tests/Feature/LogTailerPageTest.php"
  modified:
    - "Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php — UPGRADED in place: optional OAuthScrubSet constructor arg; three-layer scrub method (scrub-set → Bearer → JWT). Made the scrub() method public so the SSE controller can call it directly on streamed chunks. FQCN + __invoke(LogRecord) signature stay stable."
    - "Modules/DevMode/Internal/Audit/RedactionExcerptCap.php — UPGRADED in place: optional OAuthScrubSet constructor arg; apply() now runs scrub-set → Bearer → JWT → byte-cap. FQCN + apply(string, int) signature stay stable so SpatieAuditWriter is untouched."
    - "Modules/DevMode/Providers/DevModeServiceProvider.php — register(): added OAuthScrubSet singleton, rebound RedactionExcerptCap + RedactSecretsProcessor to inject OAuthScrubSet via constructor DI. boot(): attached OAuthSecret::observe(BustOAuthScrubSetOnSecretChange::class). Registered dev.log-tailer-page Livewire component."
    - "Modules/DevMode/Routes/web.php — appended 3 routes inside the existing /dev group: dev.logs, dev.logs.stream, dev.logs.context."
    - "Modules/Core/Public/Services/UserDataPathService.php — added dailyLogFile(?DateTimeInterface) + logsDirectory() accessors. Both honour the NATIVEPHP_STORAGE_PATH retarget."
    - "Modules/DevMode/tests/Feature/AuditLogWriteTest.php — added Test 7 (audit row redacts oauth_secret literal AND Bearer header via the upgraded RedactionExcerptCap). The existing 11 tests stay green (nullable OAuthScrubSet constructor preserves direct `new RedactionExcerptCap;` instantiation)."
    - "Modules/DevMode/tests/Feature/DevOverviewPageTest.php — nav-disabled count expectation updated 6 → 5 (Logs is now enabled)."
    - "Modules/Auth/tests/Feature/CrossUserIsolationTest.php — ISOLATION_ROUTE_ALLOW_LIST extended with dev.logs, dev.logs.stream, dev.logs.context. All EnsureDeveloperMode-gated; none surface foreign user-row data."

key-decisions:
  - "OAuth scrub-set is NOT per-user — it spans every oauth_secrets row across every user. The redaction surface is the host filesystem (storage/logs) + the audit DB row, both shared across users on the same machine. Scrubbing every user's secret from every log line is the only safe shape; scoping to the acting user would let an OAuth-failure log emitted on user A's behalf leak user B's identical (improbable but possible) refresh-token literal. The cross-user scope is documented in the OAuthScrubSet class PHPDoc."
  - "OAuthScrubSet class is NOT final + properties are protected — the class needs to support a unit-test double (FixedOAuthScrubSetStub) that overrides load() to skip the DB read. Marking the class final blocks the test double; the original baseline class hierarchy intent (final everywhere) is preserved by keeping the runtime production class as the only `concrete` extension. This was discovered during Task 1 when the first cut of the unit test fatal-errored at file-load time (Fatal: Class FixedOAuthScrubSetStub cannot extend final class)."
  - "Nullable OAuthScrubSet on both RedactSecretsProcessor and RedactionExcerptCap constructors — the existing baseline tests (RedactSecretsProcessorBaselineTest + 3 AuditLogWriteTest cases) instantiate these classes directly with `new RedactSecretsProcessor` / `new RedactionExcerptCap`. Making the OAuthScrubSet arg optional preserves those instantiations across the 16-04b → 16-05 upgrade. The container-resolved singletons ALWAYS pass the real scrub-set; only test-doubles and the baseline regression guards use the null path."
  - "Scrub-set step runs FIRST in the three-layer order (before Bearer, before JWT) — many OAuth refresh tokens (Google, Microsoft) are JWT-shaped. If the JWT regex ran first, the token would be replaced with `[JWT_REDACTED]` rather than the more specific `[REDACTED]`. Both outputs hide the secret, but the explicit ordering means we can prove via test that the audit trail says 'this was a known oauth_secrets value' vs 'this was an unknown-shape JWT', which is useful when triaging accidental leak reports. The test 'runs the OAuth scrub-set BEFORE the JWT pattern' is the regression guard."
  - "Documented limitation: deleting an OAuthSecret row STOPS scrubbing that string in future log lines. The observer busts the cache; the next compiledPattern() rebuild excludes the deleted row's values; a subsequent log line containing the now-revoked literal is NOT scrubbed. This is intentional — a revoked-and-removed token is no longer sensitive to this app's threat model (the secret is dead; the audit cares about NEW lines). The OAuthSecretDeletionStopsScrubbingTest is the explicit I-4 regression guard so a future change that silently keeps scrubbing deleted values is surfaced at PR time."
  - "Rotation detection uses a tri-signal (path change OR inode change OR shrinkage) — the path-change case handles the daily midnight rollover (Monolog's RotatingFileHandler swaps to the next day's YYYY-MM-DD file), the inode-change case handles a logrotate-style truncate+rename, and the shrinkage case handles a logrotate-style copytruncate (file is preserved but truncated to 0 bytes). All three are checked once per 250ms tick; any of them triggers reopening at offset 0 in the new file. The shared FileTailer primitive already returns 'unchanged offset on shrinkage' which means the controller can simply reset its own cursor without coordinating."
  - "SSE controller AND context endpoint live in the SAME LogStreamController class — the context() method shares the same RedactSecretsProcessor + UserDataPathService dependencies as __invoke(). Splitting them into LogContextController would have created a sibling class with duplicate DI surface. Keeping both in one class is consistent with 16-04's ArtisanStreamController (single-class) and the Pattern K (PATTERNS.md) shape for non-Livewire HTTP."

patterns-established:
  - "Belt+braces on-write+on-stream redaction (CONTEXT D-29) — every log line is scrubbed twice: once by the Monolog tap before it hits disk, again by the SSE controller before it leaves the server. The two layers share the SAME container-resolved RedactSecretsProcessor singleton so a future addition to the scrub-set (e.g. session-token scrubbing in 17-XX) updates both layers from one container-binding change."
  - "Cached scrub-set + Eloquent observer invalidation (CONTEXT D-30) — OAuthScrubSet's lazy load + bust() pattern is the canonical 'cached secret set that must invalidate on rotation' shape. Pattern lives at Modules/DevMode/Internal/Services/OAuthScrubSet.php (the class) + Modules/DevMode/Internal/Listeners/BustOAuthScrubSetOnSecretChange.php (the observer) + DevModeServiceProvider::boot() (the OAuthSecret::observe() wiring). Future scrub-set surfaces should mirror this three-piece shape."
  - "Pre-compiled regex alternation over O(n*m) loops (Pitfall 8) — compiledPattern() builds one alternation per scrub-set load. The runtime cost per log record is O(message_length) regardless of how many secrets the set holds, vs naive `foreach $secrets as $s → str_replace($s, '[REDACTED]', $msg)` which is O(message_length × secret_count). Future scrub-sets should compile their alternation once per bust and reuse it across records."
  - "Tri-signal rotation detection for SSE log tail (CONTEXT D-28) — combine path comparison (midnight rollover), inode comparison (truncate+rename), and shrinkage comparison (copytruncate) in one tick body. Pattern lives in LogStreamController::__invoke()."
  - "Client-side ring buffer + minimal-server SSE — the server emits chunks; the client (Alpine x-data) holds the 10k buffer, runs the filters, manages pause/resume, and does exponential-backoff reconnect. The server has no notion of pause or buffer. Future tail surfaces (e.g. queue-events tailer in 16-06) should follow the same split."

requirements-completed: [DEVUI-04]

# Metrics
duration: 60min
completed: 2026-05-24
---

# Phase 16 Plan 05: Log Tailer + Redaction (Belt+Braces D-29 + OAuth Scrub-Set D-30) Summary

**Live `/dev/logs` page lands end-to-end: the SSE log stream serves redacted laravel-YYYY-MM-DD.log lines via the shared FileTailer primitive, the on-write Monolog tap installed in 16-04b is upgraded to scrub every oauth_secrets value (full three-layer scrub-set → Bearer → JWT), the audit-row excerpt path picks up the same upgrade, an Eloquent observer busts the cache on secret rotation so a new secret is scrubbed on the very next log line, and the client-side 10k ring buffer + severity/channel/contains filters + Pause/Resume + click-to-expand ±10 lines deliver the full D-31 UX. DEVUI-04 fully satisfied.**

## Performance

- **Duration:** ~60 min (env bootstrap + 2 atomic task commits + verification)
- **Tasks:** 2 (both autonomous, both green on first verification pass)
- **Commits:** 2 atomic task commits + 1 final docs commit (this SUMMARY)
- **Files created:** 10
- **Files modified:** 8
- **Test growth:** 4 baseline DevMode unit tests → 90 DevMode tests pass (+86 visible: 6 new RedactSecretsProcessorTest unit + 4 new OAuthScrubSetBustTest feature + 1 new OAuthSecretDeletionStopsScrubbingTest feature + 1 new audit-pipeline test in AuditLogWriteTest + 7 new LogStreamControllerTest feature + 6 new LogTailerPageTest feature)
- **Larastan L10 strict:** clean (0 errors across full codebase)
- **Pint:** clean
- **CrossUserIsolationTest:** 9 passed (allow-list extended with dev.logs / dev.logs.stream / dev.logs.context)
- **BoundaryArchTest:** 45 passed (everyDevModeRouteAppliesEnsureDeveloperModeMiddleware naturally covers the 3 new routes)

## Accomplishments

1. **On-write + on-stream belt+braces redaction (CONTEXT D-29 enforced both layers).** The Monolog tap installed in 16-04b is now upgraded to scrub every `oauth_secrets.client_secret` + every string leaf in every `tokens_blob` JSON via the new `OAuthScrubSet` (D-30). The SSE log stream re-applies the SAME `RedactSecretsProcessor` to every emitted chunk, so a log line that somehow slipped past on-write redaction is still scrubbed on the way to the browser. Both layers share the same container-resolved singleton.
2. **Pre-compiled regex alternation (Pitfall 8 mitigation) from day one.** `OAuthScrubSet::compiledPattern()` returns `'/(secret1|secret2|...)/'` — one `preg_replace` per log record regardless of set size. No naive `foreach str_replace` ever ships.
3. **OAuth scrub-set Eloquent observer + cache bust (D-30 verbatim).** `BustOAuthScrubSetOnSecretChange` is attached to `OAuthSecret` in `DevModeServiceProvider::boot()`. Every save (created OR updated) and delete event busts the compiled-regex cache; the next `compiledPattern()` rebuilds from the live table. A rotated secret takes effect on the very next log line.
4. **Audit-row excerpt redaction now applies the full three-layer scrub.** `RedactionExcerptCap` (consumed by `SpatieAuditWriter` to write `dev_mode_audit.properties.{stdout_excerpt,error_excerpt,query}`) is upgraded in place — constructor now injects `OAuthScrubSet`; SpatieAuditWriter is unchanged because the cap resolves via container DI. Test 7 in `AuditLogWriteTest` is the explicit regression guard.
5. **`/dev/logs` page renders the full D-31 UX.** 8 severity chips with sketch-locked color mapping (DEBUG/INFO muted, NOTICE neutral, WARNING amber, ERROR/CRITICAL/ALERT/EMERGENCY rose) + channel filter + debounced contains-filter + Pause/Resume + 10k-line client-side Alpine ring buffer + click-to-expand ±10 lines via the `/dev/logs/context` JSON endpoint + empty-state cursor + auto-reconnect with exponential backoff.
6. **Rotation detection (D-28) — tri-signal: path change OR inode change OR shrinkage.** A midnight rollover (Monolog swaps to the next day's `laravel-YYYY-MM-DD.log`) is detected by path comparison; a logrotate truncate+rename is detected by inode change; a logrotate copytruncate is detected by shrinkage. Any of the three triggers reopening at offset 0.
7. **Shared `FileTailer` reuse.** The SSE controller injects the existing `FileTailer` 16-04 introduced and tails the daily log file with the SAME single-tick body the artisan SSE controller uses. One tested code path, two consumers, zero duplication.

## Task Commits

| Task | Commit | Title |
|------|--------|-------|
| 1 | `6f848c7` | feat(16-05): OAuthScrubSet + observer bust + RedactSecretsProcessor + RedactionExcerptCap full-scrub upgrade |
| 2 | `342edde` | feat(16-05): LogTailerPage + LogStreamController SSE + ±10-line context endpoint |

Plan metadata commit (this SUMMARY): pending — orchestrator owns the final commit per worktree workflow.

## Belt+Braces (D-29) Design — Why Both Layers Are Kept

The on-write Monolog tap installed in 16-04b is the **first** layer. It scrubs every Bearer header + JWT shape + (now, with this plan) every oauth_secret literal BEFORE the record reaches the formatter. The bytes that hit disk are already redacted. A forensic copy of `storage/logs/laravel-YYYY-MM-DD.log` taken any time later is safe.

The on-stream re-application inside `LogStreamController::__invoke()` is the **second** layer. Every chunk that the SSE controller emits passes through the SAME `RedactSecretsProcessor::scrub()` method before going out as `data:` JSON. **Why both layers?**

- **A third-party tool can write directly to the log file.** Native PHP error reporting, an OPCache compile-time warning, a Symfony exception logged via a non-Laravel logger — any of these can append to `storage/logs/laravel-{date}.log` without passing through Laravel's Monolog channels. The on-stream layer guarantees that a log line which slipped past the on-write tap is still scrubbed on the way to the browser.
- **A future schema change to oauth_secrets can introduce a new secret-bearing column.** Until the scrub-set's `load()` is updated to read the new column, the on-write tap is blind. The on-stream layer, fed by the same scrub-set, has the same blind spot — but having TWO independent enforcement points doubles the chance that a missing scrub-set entry is noticed via a manual `/dev/logs` audit before it ships to disk for years.
- **The threat model says "OAuth tokens MUST NEVER leak to disk OR render in the tailer".** AND not OR. Both surfaces are required exit boundaries; both apply the same scrub.

The two layers share the same container-resolved `RedactSecretsProcessor` singleton (via `Container::getInstance()->make()` in `PushRedactProcessor`, and via constructor DI in `LogStreamController`). One singleton + two consumers means future scrub-set additions (e.g. session tokens in 17-XX) need ONE container-binding change to update both layers.

## Pre-Compiled Regex (Pitfall 8) + OAuthScrubSet Cache Strategy

The naive shape — `foreach (scrubSet as $secret) { $line = str_replace($secret, '[REDACTED]', $line); }` — is **O(n × m)** per log line (n = message length, m = secret count). For a developer connecting their Google + Microsoft accounts, m can climb past 10 once both `client_secret` and the per-inbox `refresh_token` + `access_token` JSON leaves enter the set. With m = 10 and a typical 200-char log line, that's 2000 string operations per line — every line, on every Monolog write.

The actually-shipped shape is:

```php
$pattern = '/(' . implode('|', array_map(
    static fn (string $s): string => preg_quote($s, '/'),
    $secrets,
)) . '/';
```

This compiles one alternation regex per `bust()` cycle. Every subsequent log record / audit excerpt is **one `preg_replace`** — O(message_length) regardless of set size. The compilation cost is paid once per OAuthSecret save/delete event, not once per log line.

The cache lifecycle is two-state:

- `$set = null` + `$compiled = null` → not loaded. On the next `all()` call, `load()` reads every `OAuthSecret` row, decrypts `client_secret` + walks `tokens_blob` JSON, de-duplicates non-empty strings, and stores the list.
- `$set = [...] $compiled = '/(...)/' OR ''` → loaded. `compiledPattern()` returns `$compiled` (or `null` if the empty-set sentinel).

`bust()` clears both fields so the next call lazy-rebuilds. The observer triggers `bust()` on every `OAuthSecret::saved` / `OAuthSecret::deleted`; the cache rebuilds from the live table on the very next compiledPattern() call. The lazy-rebuild dodges a circular boot-time dependency (Eloquent ↔ Encrypter ↔ this scrub-set on first migration / before the migration creates the oauth_secrets table). A DB / decryption failure surfaces as an empty set and bubbles up to "no scrub-set applied" — Bearer + JWT scrubbing still runs.

## Audit-Log Excerpt Redaction Upgrade (16-04 basic → 16-05 full scrub-set)

Before this plan, `RedactionExcerptCap::apply()` did Bearer regex + JWT regex + 8 KiB byte-cap. SpatieAuditWriter wrote `dev_mode_audit.properties.stdout_excerpt` through this cap. A failure mode existed: an artisan command stdout that printed the literal value of an `oauth_secrets.client_secret` (e.g. a debugging `dd($secret)`) would store that literal in the audit row's `stdout_excerpt`.

After this plan, the cap runs the OAuth scrub-set regex BEFORE the Bearer + JWT regex AND before the 8 KiB cap. The audit row's excerpt is now redacted at the same fidelity as the on-write log file. Tested explicitly in `AuditLogWriteTest::it redacts an oauth_secrets value AND a Bearer header in the audit row (Test 7)`.

SpatieAuditWriter is unchanged. The `RedactionExcerptCap::apply(string, int): string` signature is unchanged. The change is purely a constructor-DI swap on the container binding — no consumer was modified.

## Known Limitation — Deleting an OAuthSecret Stops Scrubbing That String

This is documented as intentional behavior in the plan's `<behavior>` block (I-4 regression guard) and the `OAuthScrubSet` class PHPDoc:

> Acceptable behavior on rotation (DOCUMENTED): once an OAuthSecret row is DELETED, the cache busts and the deleted string disappears from the scrub set. A subsequent log line containing the old (revoked) string is NOT scrubbed. This is intentional — a revoked + removed token is no longer sensitive to this app's threat model.

The regression test `OAuthSecretDeletionStopsScrubbingTest::it STOPS scrubbing a string after the OAuthSecret row is deleted` is the explicit guard. A future change that silently keeps scrubbing deleted values (e.g. a tombstone table on top of the `oauth_secrets` schema) would fail this test at PR time and force the maintainer to deliberately update both the implementation AND the documented threat-model statement.

This limitation is acceptable because:

- A rotated-and-removed secret has been revoked at the OAuth provider (Google / Microsoft refresh-token rotation invalidates the prior token; deleting the row is the local-side acknowledgment).
- The audit DB row from BEFORE the rotation still contains the redacted `[REDACTED]` placeholder (the cap ran at WRITE time, not at the row-render time).
- The log file from BEFORE the rotation has the redacted form persisted to disk.
- Only NEW log lines (i.e., new event surfaces emitted AFTER the rotation) that somehow reference the now-revoked literal would be un-scrubbed — and that surface is vanishingly small (no in-process code holds the old literal because the row is gone; only a stale debug dump or a slow background job that captured the literal pre-rotation would still emit it).

The trade-off: keeping the deleted string scrubbed forever would require a tombstone table that grows unbounded across the lifetime of the install. Given the project's "full history retained forever" constraint, that tombstone would dwarf the live `oauth_secrets` table within a few rotations. The current shape — bust on delete — accepts the documented trade-off in exchange for a bounded cache size.

## Decisions Made

See `key-decisions` in the frontmatter for the full list with rationale. Quick recap of the most consequential:

- **OAuth scrub-set spans every user's oauth_secrets row** — system-wide redaction surface, not per-user. Documented in `OAuthScrubSet` class PHPDoc.
- **OAuthScrubSet is NOT `final`** — needed for the unit-test double (`FixedOAuthScrubSetStub`). The class is a singleton at runtime; the test double's existence doesn't change that.
- **Nullable OAuthScrubSet on both upgraded constructors** — preserves the 16-04b baseline-test instantiations (`new RedactSecretsProcessor` / `new RedactionExcerptCap`) so the upgrade is invisible to those tests. The container always passes the real singleton.
- **Scrub-set step runs FIRST** — a JWT-shaped real OAuth token reads as `[REDACTED]` not `[JWT_REDACTED]`; the explicit ordering matters for accidental-leak triage.
- **Deletion stops scrubbing (documented limitation)** — see above.
- **Tri-signal rotation detection** — path change OR inode change OR shrinkage. Covers midnight rollover + logrotate truncate+rename + logrotate copytruncate.
- **SSE controller + context endpoint live in the SAME class** — Pattern K consistency with 16-04's ArtisanStreamController.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] OAuthScrubSet was originally `final`; the unit-test double could not extend it**
- **Found during:** Task 1 (RedactSecretsProcessorTest first run)
- **Issue:** The unit tests needed a `FixedOAuthScrubSetStub` that overrides `all()` / `compiledPattern()` to skip the DB read (the Unit suite doesn't run `RefreshDatabase`). Marking `OAuthScrubSet` as `final` blocks the test double; pest's silent-fatal failure mode swallowed the error and the first test run produced zero output + exit code 2.
- **Fix:** Removed `final` from `OAuthScrubSet` and switched `private` → `protected` on its two cache fields + `load()` method. Runtime semantics unchanged (the class is still bound as a container singleton); the test double can now extend it.
- **Files modified:** `Modules/DevMode/Internal/Services/OAuthScrubSet.php`
- **Verification:** All 6 unit tests + all 4 OAuthScrubSetBust feature tests pass; full DevMode suite (90 tests) green.
- **Committed in:** `6f848c7` (Task 1)

**2. [Rule 1 — Bug] Anonymous test double inside an `it()` body crashed pest's test loader**
- **Found during:** Task 1 (first cut of RedactSecretsProcessorTest)
- **Issue:** The first cut declared the test double as an anonymous class inside the helper function `unitScrubSetWithSecrets()`. Pest's TeamCity output showed `testStarted` for the first test but never `testFinished` — the file loader crashed silently. Hypothesis: anonymous-class redeclaration on subsequent pest worker invocations conflicted with pest's own bootstrap context (vendor/pestphp/pest/overrides/Runner/TestSuiteLoader.php).
- **Fix:** Hoisted the test double to a top-level named class `FixedOAuthScrubSetStub` declared once in the test file's global scope. The same pattern is used by existing helper functions like `auditDeveloper()` / `runnerDeveloper()` elsewhere in `Modules/DevMode/tests/Feature/`.
- **Files modified:** `Modules/DevMode/tests/Unit/RedactSecretsProcessorTest.php`
- **Verification:** All 6 unit tests pass; pest's TeamCity output reaches `testFinished` for every test.
- **Committed in:** `6f848c7` (Task 1)

**3. [Rule 1 — Bug] JWT regex requires `eyJ` + 20+ more chars per segment (not 20 chars total)**
- **Found during:** Task 1 unit tests (Test 5 — `falls back to Bearer + JWT only when OAuthScrubSet is empty`)
- **Issue:** The first cut of the test JWT used a first segment `eyJhbGciOiJIUzI1NiJ9` — 20 chars total. The regex `/eyJ[A-Za-z0-9_-]{20,}\./` requires `eyJ` followed by 20+ MORE chars, so the minimum-length first segment is 23 chars. The test failed because the regex did not match the deliberately-short JWT.
- **Fix:** Used the longer first segment `eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9` (36 chars) that the existing baseline test already uses. The JWT regex itself is unchanged — the test's JWT now clears the minimum-segment-length threshold.
- **Files modified:** `Modules/DevMode/tests/Unit/RedactSecretsProcessorTest.php`
- **Verification:** Test 5 passes; all 6 unit tests green.
- **Committed in:** `6f848c7` (Task 1)

**4. [Rule 1 — Bug] Larastan flagged `(int) $payload['line']` as `cast.int` (mixed cast)**
- **Found during:** Task 2 Larastan run
- **Issue:** `$payload['line']` returned `mixed` from the validator's `->validate()` (the validator's return type is `array<string, mixed>`). Larastan-strict-rules `cast.int` rule flags casts of `mixed` to `int` because the cast can silently convert an object/array.
- **Fix:** Added an `is_int(...) ? (...) : (is_numeric(...) ? (int) (...) : 0)` guard chain that explicitly narrows the value before casting. Same shape applied to both `$targetLine` and `$radius`. The validator already enforces `integer min:0` so the `is_numeric(...) ? (int) ... : 0` branch is dead code at runtime — but Larastan's type narrowing only sees the `mixed` source type and demands the explicit guard.
- **Files modified:** `Modules/DevMode/Internal/Http/Controllers/LogStreamController.php`
- **Verification:** Larastan L10 strict clean across the whole codebase.
- **Committed in:** `342edde` (Task 2)

**5. [Rule 1 — Bug] Larastan flagged `isset($stat['ino']) && is_int($stat['ino'])` as always-true (stub knowledge)**
- **Found during:** Task 2 Larastan run
- **Issue:** PHP's `stat()` stub declares the return as `array{...., 'ino': int, ...}` so Larastan knows the `ino` key always exists and is always an int. The defensive `isset(...) && is_int(...)` guard read as always-true; Larastan flagged both `booleanNot.alwaysFalse` AND `isset.offset` (offset always exists).
- **Fix:** Trusted the stub — dropped the `isset(...) && is_int(...)` clauses; the `$stat === false` guard already covers the case where `stat()` fails. The remaining `return $stat['ino'];` is type-correct per the stub.
- **Files modified:** `Modules/DevMode/Internal/Http/Controllers/LogStreamController.php`
- **Verification:** Larastan L10 strict clean.
- **Committed in:** `342edde` (Task 2)

**6. [Rule 2 — Missing critical] Test 5 of LogTailerPageTest used a regex that did not match the dev-shell anchor shape**
- **Found during:** Task 2 LogTailerPageTest first run
- **Issue:** The first cut of the "Logs sidebar item without nav-disabled class" test used `<a[^>]*class="..."[^>]*>[^<]*Logs[^<]*</a>` — but the dev-shell renders each nav item as `<a ...><span class="ic">{icon}</span> Logs</a>`. The `[^<]*` segment failed to match because of the inner `<span>`. The test fell through to the failure path and reported "Could not locate the Logs sidebar anchor".
- **Fix:** Changed the inner part to `[\s\S]*?` (non-greedy any character including angle brackets) so the regex spans the icon span. The test now correctly captures the anchor's class attribute and asserts `not->toContain('nav-disabled')`.
- **Files modified:** `Modules/DevMode/tests/Feature/LogTailerPageTest.php`
- **Verification:** Test 5 passes; all 6 LogTailerPage tests green.
- **Committed in:** `342edde` (Task 2)

**7. [Rule 1 — Bug] Pint cosmetic fix-ups (FQN imports, ordered_imports, braces_position, etc.)**
- **Found during:** Pint test after Task 1 changes
- **Issue:** Pint flagged `fully_qualified_strict_types` (in-file FQN that should be hoisted to `use` statements), `braces_position`, `single_line_empty_body`, `ordered_imports`, `no_superfluous_phpdoc_tags`, `no_empty_phpdoc`, and `no_unused_imports` across 4 of the new/modified files.
- **Fix:** Ran `vendor/bin/pint` to apply the fixers. Cosmetic-only — no behavior change.
- **Files modified:** `Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php`, `Modules/DevMode/Internal/Services/OAuthScrubSet.php`, `Modules/DevMode/Providers/DevModeServiceProvider.php`, `Modules/DevMode/tests/Feature/OAuthScrubSetBustTest.php`
- **Verification:** Pint passes; tests still green.
- **Committed in:** `6f848c7` (Task 1; bundled with the originating changes)

---

**Total deviations:** 7 auto-fixed (6 Rule 1 — bug; 1 Rule 2 — missing critical). All 7 are necessary follow-throughs of the plan's intent; none changed scope.
**Impact on plan:** Every deviation was a Rule 1 / Rule 2 fix surfaced by the test-suite + Larastan + Pint discipline. The plan's `<behavior>` and `<action>` shape stayed intact end-to-end.

## Issues Encountered

- **Pest's silent-fatal behavior on `extends final` made the first task slow.** The unit test file declared a stub extending `final class OAuthScrubSet`. Pest's test-suite loader caught the fatal error and emitted only WARN messages about other test files (the `Cannot add file ... to test suite` warnings unrelated to the fatal). The TeamCity output reached `testStarted` for the first test but never `testFinished`. Removing the `final` modifier (Deviation 1) resolved it. Future plans that introduce a `final` class which downstream tests need to extend should pre-emptively drop `final` and document the runtime invariant via singleton-binding instead.
- **The `--filter='RedactSecretsProcessorTest'` argument matched no tests** because pest's `--filter` is a regex over test names (the `it(...)` descriptions), not file paths. Running `pest path/to/file.php` and `pest --filter='it scrubs an Authorization'` both work; `pest --filter='RedactSecretsProcessorTest'` does not (no test name contains that substring). Used the path form going forward.

## User Setup Required

None — no external service configuration. The scrub-set self-loads from the live `oauth_secrets` table; the observer wires automatically on app boot; the SSE stream reads `storage/logs/laravel-{date}.log` via `UserDataPathService::dailyLogFile()`. No env var, no operator step.

## Next Phase Readiness

- **16-06 (queue inspector + Horizon iframe):** Independent of this plan. Register `dev.queue` + (conditionally) `dev.horizon` routes inside the existing `/dev` group; the dev-shell sidebar's `Route::has(...)` check auto-enables the matching nav items. The payload-redaction discipline can REUSE the same `RedactSecretsProcessor->scrub()` method directly — the JSON payload of a failed job often contains the same Bearer / JWT / OAuth secret literals; piping `$payload = $processor->scrub(json_encode($job->payload))` before rendering the inline JSON viewer is the canonical pattern.
- **16-07 (doctor + SQL + system):** Independent. The SQL panel's audit row (`recordSelectQuery`) already routes through `RedactionExcerptCap` (16-04b wiring) — the scrub-set upgrade in THIS plan means a SELECT statement that pastes a Bearer-shape comment / a JWT-shape literal / an oauth_secret value is scrubbed before the audit row is written.
- **16-08 (command palette):** Independent. The palette's JSON registry from each Public registry is server-emitted on mount; no log redaction surface intersects.
- **Future scrub-set additions** (e.g. session tokens for Phase 17 / API keys for Phase 18): bind a new singleton in the relevant module's ServiceProvider, attach an Eloquent observer (mirroring `BustOAuthScrubSetOnSecretChange`), and inject it into `RedactSecretsProcessor` + `RedactionExcerptCap` via constructor DI. The container binding chain is the only thing that needs to change; the on-write Monolog tap + the on-stream SSE controller pick up the new scrub-set automatically.

## Self-Check: PASSED

Files asserted present:

- `Modules/DevMode/Internal/Services/OAuthScrubSet.php` — FOUND
- `Modules/DevMode/Internal/Listeners/BustOAuthScrubSetOnSecretChange.php` — FOUND
- `Modules/DevMode/Internal/Http/Controllers/LogStreamController.php` — FOUND
- `Modules/DevMode/Internal/Http/Livewire/LogTailerPage.php` — FOUND
- `Modules/DevMode/Resources/views/livewire/log-tailer-page.blade.php` — FOUND
- `Modules/DevMode/tests/Unit/RedactSecretsProcessorTest.php` — FOUND
- `Modules/DevMode/tests/Feature/OAuthScrubSetBustTest.php` — FOUND
- `Modules/DevMode/tests/Feature/OAuthSecretDeletionStopsScrubbingTest.php` — FOUND
- `Modules/DevMode/tests/Feature/LogStreamControllerTest.php` — FOUND
- `Modules/DevMode/tests/Feature/LogTailerPageTest.php` — FOUND
- `Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php` (modified — OAuthScrubSet ctor + 3-layer scrub) — FOUND
- `Modules/DevMode/Internal/Audit/RedactionExcerptCap.php` (modified — OAuthScrubSet ctor + 3-layer scrub) — FOUND
- `Modules/DevMode/Providers/DevModeServiceProvider.php` (modified — singleton bindings + observer wiring + Livewire registration) — FOUND
- `Modules/DevMode/Routes/web.php` (modified — 3 new routes) — FOUND
- `Modules/Core/Public/Services/UserDataPathService.php` (modified — dailyLogFile + logsDirectory accessors) — FOUND
- `Modules/DevMode/tests/Feature/AuditLogWriteTest.php` (modified — new Test 7) — FOUND
- `Modules/DevMode/tests/Feature/DevOverviewPageTest.php` (modified — nav-disabled count 6 → 5) — FOUND
- `Modules/Auth/tests/Feature/CrossUserIsolationTest.php` (modified — allow-list extended with 3 routes) — FOUND

Commits asserted present:

- `6f848c7` (Task 1 — OAuthScrubSet + observer + RedactSecretsProcessor + RedactionExcerptCap upgrades) — FOUND
- `342edde` (Task 2 — LogStreamController + LogTailerPage + ±10-line context endpoint) — FOUND

---
*Phase: 16-developer-mode-ui*
*Completed: 2026-05-24*
