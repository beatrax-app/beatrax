---
phase: 06-email-receipt-ingestion-infrastructure
plan: 09
subsystem: email
tags: [discovery-loop, broad-keyword-scan, gmail-metadata-fetch, graph-search, discovered-senders-panel, promote-dismiss, launchd, install-command, plist-templates]

requires:
  - phase: 06-email-receipt-ingestion-infrastructure
    provides: DiscoveryScanJob stub + listDiscoveryCandidates / listDiscoveryCandidatesPaged stubs + KnownSenderQuery + InboxScanStateMachine + InboxesPage Livewire SFC + InboxesBadgeCount + EmailScanServiceProvider (Plans 01–08); InstallCommand from Plan 01-02
provides:
  - DiscoveryScanJob (Internal/Jobs): ShouldBeUniqueUntilProcessing(userId, uniqueFor=600); walks every connected inbox with the locked keyword filter (receipt OR factuur OR betaling OR invoice OR order OR bevestiging) minus already-known + already-dismissed senders; upserts discovered_senders rows (NO .eml blob persistence — the discovery-loop invariant); registered in EmailScanServiceProvider as a singleton; daily Schedule::call closure dispatches one job per user
  - GmailApiClient::listDiscoveryCandidates real implementation — `users.messages.list` with `subject:(...)` keyword filter + `-from:(...)` exclude clause + maxResults=100; per-message `users.messages.get?format=metadata&metadataHeaders=['From','Date']` fetch so headers ONLY cross the wire (body never lands on the worker)
  - GraphApiClient::listDiscoveryCandidatesPaged real implementation — Graph `$search` clause with `subject:(<quoted OR-list>)` + `$top=100` + `$select=id,from,subject,receivedDateTime`; client-side exclude-sender filter applied AFTER the server response (Graph `$search` does not honour a `not from/...` predicate in combination); `$search` deliberately omits `$orderby` (mutually exclusive per Graph docs)
  - DiscoveredSenderDto (Public/Dto) — minimal data carrier for the panel (id, userId, inboxId, senderEmail, senderName, occurrenceCount, lastSeenAt, state)
  - DiscoveredSenderQuery (Public/Services) — candidatesForUser returns rows with occurrence_count >= MIN_OCCURRENCES (=2) AND last_seen_at within last WITHIN_DAYS (=90), sorted by count DESC + last_seen DESC, capped at PANEL_PAGE_SIZE (=25); candidatesCountForUser mirror for the badge feed
  - PromoteDiscoveredSender (Public/Actions) — inserts a user-sourced known_senders row + transitions discovered_senders.state to 'added' inside one DB transaction with PRAGMA busy_timeout=5000 + lockForUpdate; cross-user 404 via (id, user_id) scoped lookup; idempotent on non-candidate state
  - DismissDiscoveredSender (Public/Actions) — same cross-user 404 + idempotency posture; state-transition-only (no known_senders write)
  - InboxesPage Livewire SFC — promoteSender + dismissSender method-DI actions emit toast ('Sender added.' / 'Sender dismissed.'); render() now injects DiscoveredSenderQuery + passes $discoveredCandidates to the view
  - inboxes-page.blade — appended "Discovered senders" section per UI-SPEC § Discovered-senders panel (locked verbatim copy); renders only when count > 0; per-row primary line = sender_email; tabular-nums "Seen N times" chip; Add (emerald-600) + Dismiss (slate-100) buttons with focus-visible rings + aria-labels
  - InboxesBadgeCount — tightened to apply the same 2/90 threshold as the panel (Clock added to constructor DI); without this tightening, single-shot senders would inflate the badge but never surface in the panel
  - Three deploy/launchd/*.plist files (horizon + scheduler + redis) with {{ABS_PHP_BINARY}} + {{ABS_PROJECT_ROOT}} placeholders the InstallCommand substitutes at install time; KeepAlive.Crashed=true + ThrottleInterval prevent crash-loop spin
  - InstallCommand --launchd flag (+ --without-redis modifier) — refuses to run on non-Darwin; ensures ~/Library/LaunchAgents/ exists with chmod 700; reads each template; substitutes PHP_BINARY + base_path(); writes via the injected Filesystem; bootstraps via launchctl. resolveLaunchAgentsDir + bootstrapPlist are protected hooks so tests can override
  - README "Background workers via macOS launchd" section under "Background workers (Phase 6+)"
  - 3 new test files (15 cases / 93 new assertions): DiscoveryScanNoEmlBlobsTest (1 / 25), DiscoveredSendersPanelTest (10 / 38), InstallLaunchdCommandTest (4 / 30)
affects:
  - Phase 7 parser stage will read known_senders rows the discovery loop promotes (the matcher registry only acts on senders the user has opted into)
  - Phase 6 close-out: ROADMAP SC#5 ("OAuth secrets live in a chmod-600 local config file + macOS launchd plists for Horizon and scheduler") is now demonstrably met via launchd plists + diederik:install --launchd; discovery loop completes the EML-03 functional surface

tech-stack:
  added: []
  patterns:
    - "Daily-discovery-job shape: ShouldBeUniqueUntilProcessing(userId) + 10-min uniqueFor + tries=3 + backoff=[60,300,900]. Walks every inbox with a broad keyword query + persists only sender metadata. Distinct from the per-inbox BackfillInboxJob / IncrementalScanJob which serialize on inboxId; the daily cadence + the user-keyed lock are deliberately decoupled from the per-inbox state machine."
    - "Discovery-loop NO-.eml-blob invariant: enforced at three layers — (1) the GmailApiClient discovery method uses format=metadata + metadataHeaders so the body never crosses the wire, (2) the GraphApiClient discovery method uses $search + $select=id,from,subject,receivedDateTime so the body is not selected, (3) DiscoveryScanJob never calls $blobStore->put / $client->getRawMessage. The integration test asserts the invariant via the storage/app/inbox count + a defensive grep over the Fake's call log."
    - "Promotion-threshold (2 occurrences within 90 days) locked at the query layer (DiscoveredSenderQuery::MIN_OCCURRENCES + WITHIN_DAYS public consts). Both the panel feed AND the top-nav badge feed (InboxesBadgeCount) read the same constants so badge and panel are guaranteed to stay in lockstep — a badge > 0 means at least one row WILL appear on the panel."
    - "Cross-user 404 via invokable Public Action (Promote/Dismiss) — mirrors the Chains module's ConfirmChainLink / RejectChainLink shape. The (id, user_id)-scoped lookup raises NotFoundHttpException explicitly so unit tests can assert against the canonical 404 outside the HTTP boundary."
    - "Idempotent Promote/Dismiss: a row already in state='added' or 'dismissed' is a silent no-op (no DB mutation, no exception). Mirrors the Chains module's auto-promotion learning loop posture — re-clicking Add on an already-promoted candidate must not surface as an error or duplicate the known_senders row."
    - "launchd plist template + substitution pattern: deploy/launchd/com.diederik.{name}.plist files carry {{ABS_PHP_BINARY}} + {{ABS_PROJECT_ROOT}} placeholders; the InstallCommand --launchd path reads each template, substitutes via strtr against PHP_BINARY + base_path(), and writes to ~/Library/LaunchAgents/. KeepAlive.Crashed=true + SuccessfulExit=false + ThrottleInterval=10 (30 for Redis) prevent a misconfigured plist from spinning in a tight crash-restart loop."
    - "Testable launchd bootstrap: InstallCommand::bootstrapPlist + resolveLaunchAgentsDir are protected hooks (not private) so the test can subclass + override them to redirect the sandbox path + capture the bootstrap call without invoking launchctl on the developer's real machine. Class is no longer 'final' for this exact reason — same pattern OAuthSecretsRepository uses for performRename in Plan 02."

key-files:
  created:
    - Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php
    - Modules/EmailScan/Public/Dto/DiscoveredSenderDto.php
    - Modules/EmailScan/Public/Services/DiscoveredSenderQuery.php
    - Modules/EmailScan/Public/Actions/PromoteDiscoveredSender.php
    - Modules/EmailScan/Public/Actions/DismissDiscoveredSender.php
    - Modules/EmailScan/tests/Integration/DiscoveryScanNoEmlBlobsTest.php
    - Modules/EmailScan/tests/Feature/DiscoveredSendersPanelTest.php
    - tests/Feature/InstallLaunchdCommandTest.php
    - deploy/launchd/com.diederik.horizon.plist
    - deploy/launchd/com.diederik.scheduler.plist
    - deploy/launchd/com.diederik.redis.plist
    - .planning/phases/06-email-receipt-ingestion-infrastructure/06-09-SUMMARY.md
  modified:
    - Modules/EmailScan/Internal/Clients/GmailApiClient.php
    - Modules/EmailScan/Internal/Clients/GmailApiClientContract.php
    - Modules/EmailScan/Internal/Clients/GraphApiClient.php
    - Modules/EmailScan/Internal/Clients/GraphApiClientContract.php
    - Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php
    - Modules/EmailScan/Internal/Clients/FakeGraphApiClient.php
    - Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php
    - Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php
    - Modules/EmailScan/Public/Services/InboxesBadgeCount.php
    - Modules/EmailScan/Providers/EmailScanServiceProvider.php
    - Modules/EmailScan/tests/Feature/TopNavBadgeViaComposerTest.php
    - Modules/Core/Internal/Console/InstallCommand.php
    - phpstan.neon
    - routes/console.php
    - README.md

key-decisions:
  - "Extended the GmailApiClientContract::listDiscoveryCandidates return shape from list<array{id, threadId}> to list<array{id, fromAddress, fromName, internalDate}> — the original Plan 05 stub only carried what users.messages.list returns natively; discovery needs the sender metadata. The contract change is structural (the Fake + the real client + the consumer all moved together in one commit) so test-time wiring stays drop-in compatible. The Graph contract already returned list<array<string, mixed>> which is permissive enough; only the documentation tightened."
  - "Microsoft Graph discovery uses $search NOT $filter. Graph's OData $filter does not support contains() against the subject field for the messages collection — confirmed against https://learn.microsoft.com/en-us/graph/search-query-parameter. The $search clause is the only supported way to keyword-match subject. $search is mutually exclusive with $orderby (Graph rejects the combination); the implementation deliberately omits $orderby and accepts the server's default ordering. Server-side from-address exclusion is also unsupported in combination with $search, so the exclude-senders filter runs CLIENT-SIDE after the response — bounded by the daily cadence + $top=100 page size."
  - "Promotion threshold (2 occurrences in 90 days) lives as PUBLIC consts on DiscoveredSenderQuery (MIN_OCCURRENCES=2, WITHIN_DAYS=90, PANEL_PAGE_SIZE=25). Both the panel feed (candidatesForUser) and the top-nav badge feed (InboxesBadgeCount) read the same constants so badge and panel are guaranteed to stay aligned. The consts are exposed as method-default parameters on candidatesForUser/candidatesCountForUser so a future 'show all' UI toggle can pass relaxed values without changing the query shape."
  - "InboxesBadgeCount constructor now injects Clock alongside DatabaseManager (it previously only needed DatabaseManager). Required for the rolling 90-day window threshold; without Clock the badge could not apply the same time-bounded filter the panel uses. The auto-wiring binding picks up Clock from the Core module's existing singleton binding so no caller code needed adjusting beyond the singleton registration."
  - "InstallCommand class declaration is no longer 'final' — InstallLaunchdCommandTest needs to subclass it to override resolveLaunchAgentsDir (redirect into a per-test sandbox path) + bootstrapPlist (capture the bootstrap call without invoking launchctl). The protected hook pair is the load-bearing test seam; the alternative (mocking passthru via output sniffing) is fragile and would tie the test to PHP's internal command-execution layering. Same pattern OAuthSecretsRepository uses for performRename in Plan 02."
  - "DiscoveryScanJob's keyword list is locked as a private const DISCOVERY_KEYWORDS = ['receipt', 'factuur', 'betaling', 'invoice', 'order', 'bevestiging'] inside the class. The mix of English + Dutch terms reflects CONTEXT.md D-121 verbatim. A future plan that needs to tune the keyword list (Phase 7 matcher discovery?) can promote this to a Public const + parameter without changing the job's shape."
  - "DiscoveryScanJob silently aborts the entire user run on the first RateLimitedException + silently continues past any other Throwable per-inbox. Discovery is daily and best-effort — burning the per-inbox state machine on a transient quota hit would cost more than just retrying tomorrow with a fresh exclude list. There are no .eml blobs to clean up so the abort cost is zero."
  - "DiscoveryScanJob upsert exclusion is two-layered: (1) the provider query receives the full exclude list as a `-from:(...)` clause / client-side filter; (2) the handler's persistence loop ALSO re-checks each sender against the exclude list before writing. The second layer is defence-in-depth — even if a provider's query syntax fails to honour the exclude list (e.g. Gmail tolerating an oversized -from list), the handler still drops the message before upserting. The integration test exercises this via the dismissed-sender flow: a row flipped to state='dismissed' is correctly excluded from the next run's exclude clause AND the handler-level re-filter."
  - "Redis plist mirrors the Phase 5 README's docker-run line verbatim (redis:7-alpine + 127.0.0.1:6379:6379 + named volume + --save 60 1) — drops only the `-d` flag because launchd runs the container in the foreground for supervision. The plist hard-codes /usr/local/bin/docker as the Docker binary path with PATH env var covering /opt/homebrew/bin as a fallback; users with Docker installed at a non-standard location can edit the ProgramArguments before bootstrap or skip the plist via --without-redis and let Docker Desktop autostart handle the container instead. Documented in the plist's leading comment."

patterns-established:
  - "Per-module discovery loop: a daily Schedule::call closure enumerates users + dispatches one job per user; the job walks every connected inbox + populates a per-user discovered_senders feed via a broad keyword query. The pattern decouples per-inbox state machinery from the daily walk — discovery has no bearing on inbox_scan_state (no rate_limited/needs_reauth transitions from this surface). Future per-user daily-cadence jobs (e.g. an annual-subscription-drift report) can follow the same enumerate-and-dispatch shape."
  - "Test-safe macOS launchd install: the production InstallCommand exposes resolveLaunchAgentsDir + bootstrapPlist as protected hooks; the test subclass redirects the sandbox path + captures the bootstrap intent without invoking launchctl on the developer's real machine. This is the second protected-hook test seam in the project (OAuthSecretsRepository::performRename is the first); future filesystem-mutating or system-mutating Public surfaces should follow the same shape."
  - "Defence-in-depth sender exclusion: both the provider query AND the persistence-layer handler re-check sender exclusion. The provider query is the primary bandwidth saver; the handler check is the correctness backstop. Tests exercise both paths via a dismissed-sender flow that asserts the dismissed row does not have its count bumped on the next run."

requirements-completed: [EML-03, PLT-04]

duration: ~28min
completed: 2026-05-17
---

# Phase 6 Plan 09: Discovery Loop + macOS launchd Packaging Summary

**Daily DiscoveryScanJob with NO-.eml-blob invariant + /inboxes "Discovered senders" panel with Add/Dismiss chips + three macOS launchd plists installable via `php artisan diederik:install --launchd` — meets ROADMAP SC#5 (background workers via launchd) + closes the discovery feedback loop so the primary scan filter grows organically as the user reviews candidates. 15 new test cases pass; 167 EmailScan tests pass overall; 211 tests across EmailScan + Contracts + InstallLaunchd; Larastan level 10 strict + Pint + all 17 BoundaryArchTest invariants stay clean; full-project pest run reports 919 passed (1 pre-existing known-failure baseline).**

## Performance

- **Duration:** ~28 min (worktree run, 3 tasks)
- **Started:** 2026-05-17T02:59:24Z
- **Completed:** 2026-05-17T03:26:16Z
- **Tasks:** 3 (each landed as a separate commit)
- **Files created:** 12 (1 job class + 1 DTO + 1 service + 2 actions + 2 integration/feature tests + 1 root feature test + 3 plist files + this SUMMARY)
- **Files modified:** 14 (6 client / contract files + 1 Livewire SFC + 1 Blade view + 1 badge service + 1 ServiceProvider + 1 existing feature test + 1 Console command + 1 README + 1 phpstan config + 1 routes/console.php)

## Accomplishments

- A user with one or more connected inboxes now has a background discovery loop that quietly populates `discovered_senders` once per day: the daily Schedule::call fires DiscoveryScanJob per user, the job walks every connected inbox with the locked keyword filter (`receipt OR factuur OR betaling OR invoice OR order OR bevestiging`) minus already-known + already-dismissed senders, and the candidate rows accumulate in the per-user `discovered_senders` table. The invariant: NO `.eml` body bytes are persisted — Gmail uses `format=metadata` + `metadataHeaders=['From','Date']`; Graph uses `$search` + `$select=id,from,subject,receivedDateTime`. The integration test asserts this via a recursive file count over `storage/app/inbox/` (must stay zero throughout the three test runs) + a defensive grep over the Fake's call log (must have zero `getRawMessage` entries).
- The `/inboxes` page now renders a "Discovered senders" panel below the connected-inboxes table + add-inbox cards. The panel surfaces ONLY candidate rows above the 2-occurrences-in-90-days threshold (single-shot senders deliberately stay below the floor so the panel never asks the user to make a call on a row that may never reappear). Each row has an Add (emerald-600) and Dismiss (slate-100) button: Add inserts a user-sourced known_senders row + transitions the discovered row to state='added'; Dismiss transitions to state='dismissed' so the next discovery run excludes the sender server-side. The top-nav "Inboxes" badge applies the same 2/90 threshold so the badge value mirrors what the panel renders.
- macOS launchd packaging is complete (ROADMAP SC#5 met). Three plist templates ship under `deploy/launchd/` with `{{ABS_PHP_BINARY}}` + `{{ABS_PROJECT_ROOT}}` placeholders; `php artisan diederik:install --launchd` substitutes both at install time + writes the rendered plists to `~/Library/LaunchAgents/` + runs `launchctl bootstrap gui/{uid}`. The Redis plist is optional (skipped via `--without-redis` for Docker Desktop autostart users). The README's new "Background workers (Phase 6+)" section walks the user through the install, the first-run permission grant (Terminal accessibility / Full Disk Access on macOS), the log locations, the re-run-after-Herd-upgrade workflow, the verification (`launchctl list | grep diederik`), and the per-plist stop (`launchctl bootout`).
- Test coverage: 15 new cases land. DiscoveryScanNoEmlBlobsTest drives the integration end-to-end (Gmail + Graph mixed inbox seed, three sequential dispatches, dismissal flow, defensive call-log grep). DiscoveredSendersPanelTest drives every panel + action surface (threshold rendering, sort order, panel-hidden-when-empty, promote happy path + label fallback, dismiss happy path, cross-user 404 on both actions, idempotency on both actions, badge threshold alignment). InstallLaunchdCommandTest drives the launchd install path against a per-test sandbox (placeholder substitution, --without-redis branch, all-three-plists branch, Wrote-line output) — non-Darwin hosts skip cleanly.

## Task Commits

1. **Task 1: DiscoveryScanJob + real Gmail/Graph discovery clients + daily Schedule** — `f9180d9` (feat)
2. **Task 2: discovered senders panel + Promote/Dismiss actions + 2/90 threshold** — `b542349` (feat)
3. **Task 3: macOS launchd plists + InstallCommand --launchd + README setup section** — `f6b6dd2` (feat)

## Files Created/Modified

### Production code (Task 1)

- `Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php` — `final class DiscoveryScanJob implements ShouldBeUniqueUntilProcessing, ShouldQueue`. Constructor `(public readonly int $userId)`. uniqueFor=600. handle() reads the exclude list (known_senders + dismissed/added discovered_senders), enumerates connected inboxes, dispatches per-provider to `runDiscoveryForInbox()`. The handler-level loop re-checks each sender against the exclude list before upserting (defence-in-depth). No `.eml` blob calls anywhere in the class — the static grep `grep -E "$blobStore->put|->getRawMessage" Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php` returns zero hits.
- `Modules/EmailScan/Internal/Clients/GmailApiClient.php` — `listDiscoveryCandidates()` real implementation. Builds the `subject:(<quoted OR-list>)` keyword filter + `-from:(<safe excludes>)` exclude clause; calls `users.messages.list` with `maxResults=100`; iterates response messages + fetches per-message headers via `users.messages.get?format=metadata&metadataHeaders=['From','Date']`; parses the From header via a private `parseFromHeader()` helper that accepts both `"Name" <addr>` and bare addr forms; returns the locked `{id, fromAddress, fromName, internalDate}` shape.
- `Modules/EmailScan/Internal/Clients/GraphApiClient.php` — `listDiscoveryCandidatesPaged()` real implementation. Builds the Graph `$search="subject:(<quoted OR-list>)"` clause + `$top=100` + `$select=id,from,subject,receivedDateTime`; deliberately omits `$orderby` (mutually exclusive with `$search` per Graph docs); follows `@odata.nextLink` verbatim on subsequent pages; applies the client-side exclude-sender filter AFTER the server response (Graph rejects `$search + not from/...`).
- `Modules/EmailScan/Internal/Clients/GmailApiClientContract.php` + `GraphApiClientContract.php` — return-shape documentation tightened to reflect the rich-metadata payload; Gmail contract's return type extended from `list<array{id, threadId}>` to `list<array{id, fromAddress, fromName, internalDate}>` (Graph contract was already permissive enough).
- `Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php` + `FakeGraphApiClient.php` — `queueDiscoveryResponse([...])` helpers + matching `$queuedDiscoveryResponses` queues + `maybeThrowRateLimit($inboxId)` integration so the existing `simulateRateLimit` pool also covers the discovery surface; default-fixture replay synthesises the three Wave 0 sender entries with the new rich-metadata shape so any caller calling discovery without queueing keeps seeing a stable surface.
- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` — registered `DiscoveryScanJob` as a singleton; import added.
- `routes/console.php` — uncommented + materialised the daily DiscoveryScanJob schedule entry. `.name('email-scan.discovery')` BEFORE `.daily()->withoutOverlapping(30)` (CallbackEvent::withoutOverlapping requires description set first, same shape as the hourly entry).
- `phpstan.neon` — `DiscoveryScanJob` added to the Cache facade carve-out alongside BackfillInboxJob + IncrementalScanJob.

### Production code (Task 2)

- `Modules/EmailScan/Public/Dto/DiscoveredSenderDto.php` — `final class … extends Data` with readonly constructor properties (id, userId, inboxId, senderEmail, senderName, occurrenceCount, lastSeenAt, state).
- `Modules/EmailScan/Public/Services/DiscoveredSenderQuery.php` — public consts `MIN_OCCURRENCES=2`, `WITHIN_DAYS=90`, `PANEL_PAGE_SIZE=25`. `candidatesForUser(User $user, int $minOccurrences = self::MIN_OCCURRENCES, int $withinDays = self::WITHIN_DAYS): array<DiscoveredSenderDto>` SELECTs from `discovered_senders` filtered by user_id + state='candidate' + occurrence_count >= threshold + last_seen_at >= now-Nd, ordered by count DESC + last_seen DESC, LIMIT PANEL_PAGE_SIZE. `candidatesCountForUser()` mirror for the badge.
- `Modules/EmailScan/Public/Actions/PromoteDiscoveredSender.php` — `__invoke(int $discoveredSenderId, User $user)` wraps in a tx with `PRAGMA busy_timeout=5000` + `lockForUpdate` on the (id, user_id)-scoped read; inserts a `(user_id, email_pattern=sender_email, label=sender_name ?? sender_email, source='user')` row into known_senders; transitions discovered_senders.state to 'added'. Cross-user 404 via NotFoundHttpException; idempotent on state != 'candidate'.
- `Modules/EmailScan/Public/Actions/DismissDiscoveredSender.php` — mirror of Promote; state transition to 'dismissed' only (no known_senders write).
- `Modules/EmailScan/Public/Services/InboxesBadgeCount.php` — constructor now injects Clock alongside DatabaseManager. forCurrentUser applies the Plan 09 2/90 threshold (occurrence_count >= MIN_OCCURRENCES AND last_seen_at >= now-WITHIN_DAYS) before summing with the needs_reauth count.
- `Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php` — `promoteSender + dismissSender` method-DI actions wire the Public Actions and emit a toast ('Sender added.' / 'Sender dismissed.'). render() now injects DiscoveredSenderQuery + passes `$discoveredCandidates` to the view.
- `Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php` — appended "Discovered senders" section per UI-SPEC § Discovered-senders panel (locked verbatim copy). Renders only when count > 0; per-row primary line = `{senderEmail}`; secondary = `{senderName ?? localPart} · last seen {humanDiff}`; tabular-nums `Seen N times` chip; Add (`bg-emerald-600`) + Dismiss (`bg-slate-100`) buttons with `focus-visible:ring-2` + `aria-label` matching the per-row sender.
- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` — registered DiscoveredSenderQuery + PromoteDiscoveredSender + DismissDiscoveredSender as singletons; imports added.

### Production code (Task 3)

- `deploy/launchd/com.diederik.horizon.plist` — `php artisan horizon` LaunchAgent. KeepAlive.Crashed=true + SuccessfulExit=false + ThrottleInterval=10. Log paths land under `{{ABS_PROJECT_ROOT}}/storage/logs/launchd-horizon.{log,err.log}`.
- `deploy/launchd/com.diederik.scheduler.plist` — `php artisan schedule:work` LaunchAgent; same shape as horizon plist.
- `deploy/launchd/com.diederik.redis.plist` — optional Redis container LaunchAgent. Mirrors the Phase 5 README's `docker run` line verbatim (redis:7-alpine, 127.0.0.1:6379:6379 loopback bind, named volume, --save 60 1) — drops `-d` because launchd runs the container in the foreground. ThrottleInterval=30 reflects container crash semantics.
- `Modules/Core/Internal/Console/InstallCommand.php` — `--launchd` + `--without-redis` options added to `$signature`. `installLaunchdPlists()` refuses to run on non-Darwin; ensures `~/Library/LaunchAgents/` exists with chmod 700; reads each template via the injected Filesystem; substitutes {{ABS_PHP_BINARY}} + {{ABS_PROJECT_ROOT}} via strtr; writes the rendered plist; bootstraps via launchctl. `resolveLaunchAgentsDir` + `bootstrapPlist` are protected hooks (class no longer `final`). Filesystem added to constructor DI.
- `README.md` — added "Background workers (Phase 6+)" section under existing "Running the app" header. Documents the `diederik:install --launchd` install path + the first-run permission grant (Terminal accessibility / Full Disk Access on macOS) + the log locations + the re-run-after-Herd-upgrade workflow + the `launchctl list | grep diederik` verification + the per-plist stop command.

### Tests (Tasks 1–3)

- `Modules/EmailScan/tests/Integration/DiscoveryScanNoEmlBlobsTest.php` — 1 case / 25 assertions. Seeds user + Gmail + Microsoft inboxes + the three system known_senders (paypal.com / @ics.nl / googleplay-noreply@google.com); queues mixed payloads (3 new + 1 known on Gmail; 1 new + 1 known on Graph) for the first run; asserts `storage/app/inbox/` is empty before AND after the run; asserts only the 3 new candidate rows landed; runs a second pass and asserts `occurrence_count` bumps to 2 for repeating senders, stays at 1 for non-repeaters; flips one row to state='dismissed', runs a third pass with the dismissed sender + a fresh new sender, asserts dismissed row does NOT have its count bumped while the fresh sender lands as a new candidate; defensive grep over all six Fake call logs asserts zero `getRawMessage` calls were made.
- `Modules/EmailScan/tests/Feature/DiscoveredSendersPanelTest.php` — 10 cases / 38 assertions. Renders panel with above-threshold rows only + verifies sort order via `strpos` on the rendered HTML; verifies 90-day window cap excludes 100-day-old rows; verifies panel hidden when count = 0; promote happy path (asserts known_senders insert with source='user' + state='added' + label from sender_name); promote label fallback (sender_email when sender_name is null); dismiss happy path (state='dismissed' + no known_senders write); cross-user 404 on both Promote (Alice tries to promote Bob's row → 404) and Dismiss; idempotency on both actions (re-promote/re-dismiss on a non-candidate state is a silent no-op); badge threshold alignment (single-shot + old + dismissed rows don't count; only above-threshold candidates contribute to the badge).
- `Modules/EmailScan/tests/Feature/TopNavBadgeViaComposerTest.php` — helper updated to seed `occurrence_count=2` instead of 1 so the existing combined-count + 99+-cap cases stay green under the Plan 09 tightening.
- `tests/Feature/InstallLaunchdCommandTest.php` — 4 cases / 30 assertions. CaptureBootstrapInstallCommand subclass overrides resolveLaunchAgentsDir (redirects to a `sys_get_temp_dir()/diederik-launchd-test-{random}` sandbox) + bootstrapPlist (captures `[uid, plistPath]` instead of invoking launchctl). Test #1 verifies --without-redis lands horizon + scheduler only + placeholders substituted; #2 verifies all three plists land without --without-redis; #3 verifies the absolute artisan path appears in the ProgramArguments XML; #4 verifies a "Wrote {path}" line for each plist. Skipped on non-Darwin.

## Decisions Made

See frontmatter `key-decisions` for the full list. Highlights:

- GmailApiClientContract::listDiscoveryCandidates return shape extended (id/threadId → id/fromAddress/fromName/internalDate). Required for the discovery loop to populate discovered_senders without re-parsing raw .eml.
- Microsoft Graph discovery uses `$search` not `$filter` — Graph rejects `contains(subject, ...)` on the messages collection. `$search` is mutually exclusive with `$orderby`; the implementation omits `$orderby` and accepts default ordering. Exclude-sender filter runs client-side because Graph rejects `$search + not from/...`.
- Promotion threshold (2 occurrences in 90 days) locked at DiscoveredSenderQuery as public consts; both the panel feed AND the top-nav badge feed read the same constants so the two stay aligned.
- InstallCommand class no longer `final` — InstallLaunchdCommandTest needs the protected hook pair (`resolveLaunchAgentsDir` + `bootstrapPlist`) to redirect the sandbox + capture the bootstrap intent without touching the developer's real launchd.
- DiscoveryScanJob silently aborts on RateLimitedException + silently continues past per-inbox Throwable. Daily best-effort surface; no .eml blobs to clean up.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree environment bootstrap (vendor + .env + Vite manifest + cache_locks)**

- **Found during:** Pre-Task-1 setup
- **Issue:** The worktree spawned without `vendor/`, without `.env`, and without `public/build/manifest.json`. The pre-existing `<known_failure>` documented this pattern; the standard fix is to copy the artefacts from the main repo. Additionally, `php artisan schedule:list` requires the `cache_locks` table (for the `withoutOverlapping` mutex storage); created via `php artisan cache:table` + `migrate`. The generated `database/migrations/2026_05_17_051101_create_cache_table.php` was an environment-only artefact (the main repo does not have this migration yet) and was removed before staging.
- **Fix:** `cp /main/.env .env`; `composer install`; `cp -r /main/public/build public/build`; `touch database/database.sqlite`; `php artisan cache:table && php artisan migrate --force`; deleted the generated cache migration before staging.
- **Files modified:** none (all changes to gitignored paths; the cache migration was deleted before staging).
- **Verification:** `vendor/bin/pest --filter='it walks the gmail historyId cursor'` (the Plan 07 ResumeFromCursorTest) re-greens. `php artisan schedule:list | grep email-scan` shows both `email-scan.incremental` (hourly) and `email-scan.discovery` (daily).
- **Committed in:** Not committed — environment-only.

**2. [Rule 1 - Bug] Larastan strict-rules violations (cast.useless + cast.int + facade carve-out)**

- **Found during:** Task 1 Larastan run
- **Issue:** Initial DiscoveryScanJob draft had four strict-rules errors:
  1. `Cache::driver('redis')` facade use needed a phpstan.neon carve-out (mirrors BackfillInboxJob + IncrementalScanJob).
  2. `(string) $s->emailPattern` on a KnownSenderDto::$emailPattern field that is already typed `string` — cast.useless.
  3. `(int) $existing->occurrence_count` cast on a mixed value from query-builder stdClass — cast.int.
  4. GmailApiClient strict checks against typed Google SDK return values were already-narrowed (is_string on a String-typed getter return, !== null on a non-nullable getter return).
- **Fix:**
  1. Added DiscoveryScanJob to phpstan.neon's Cache-facade ignoreErrors block.
  2. Dropped the redundant `(string)` cast — the DTO field is already typed.
  3. Added a private `self::toInt()` static helper following the project's established `toInt`/`toString` shape (mirrors InboxScanStateMachine + KnownSenderQuery).
  4. Dropped the is_string + null-check guards on Google SDK getters that have typed PHPDoc returns.
- **Files modified:** `phpstan.neon`, `Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php`, `Modules/EmailScan/Internal/Clients/GmailApiClient.php`.
- **Verification:** `vendor/bin/phpstan analyse --memory-limit=1G` exits `[OK] No errors` over 254 files.
- **Committed in:** `f9180d9` (Task 1 commit).

**3. [Rule 1 - Bug] Pre-existing TopNavBadgeViaComposerTest fixture used count=1 below the new Plan 09 threshold**

- **Found during:** Task 2 verification (full EmailScan + Contracts suite)
- **Issue:** TopNavBadgeViaComposerTest's `tnbcSeedDiscoveredCandidates` helper seeds `occurrence_count=1`, which was correct before Plan 09 but falls below the new 2/90 threshold InboxesBadgeCount applies. Two cases broke: "combined count when needs_reauth + 1 discovered = 2" and "cap at 99+" when 100 candidates seeded at count=1 now contribute 0 to the badge.
- **Fix:** Updated the helper to seed at `occurrence_count=2`. Added an inline comment documenting the Plan 09 tightening so future maintainers understand why the helper is at the lower threshold floor.
- **Files modified:** `Modules/EmailScan/tests/Feature/TopNavBadgeViaComposerTest.php`.
- **Verification:** All 6 TopNavBadgeViaComposerTest cases re-green; full EmailScan suite reports 167 passed (was 161 before Plan 09 — the +6 is DiscoveredSendersPanelTest 10 cases minus the 4 pre-existing case-count delta from Plan 08's count).
- **Committed in:** `b542349` (Task 2 commit).

**4. [Rule 3 - Blocking] Pint auto-formatting (FQCN imports + unary spacing + EOF blank line)**

- **Found during:** Task 1 + Task 2 Pint check
- **Issue:** Initial test drafts inlined `\Modules\EmailScan\Public\Services\InboxesBadgeCount::class` and used `!$var` (unary operator without space). Pint's `fully_qualified_strict_types` + `no_unused_imports` + `unary_operator_spaces` + `single_blank_line_at_eof` fixers correct these mechanically.
- **Fix:** Ran `vendor/bin/pint <path>` on the affected files.
- **Files modified:** `Modules/EmailScan/tests/Integration/DiscoveryScanNoEmlBlobsTest.php`, `Modules/EmailScan/tests/Feature/DiscoveredSendersPanelTest.php`.
- **Verification:** `vendor/bin/pint --test` reports `passed` over the full project.
- **Committed in:** part of the same task commits (Task 1 + Task 2).

---

**Total deviations:** 4 auto-fixed (1 Rule 1 bug, 2 Rule 3 blockers, 1 environment-only)
**Impact on plan:** None of the deviations required architectural changes or new dependencies. The pre-Plan-09 test fixture update (#3) is an expected ripple from the Plan 09 spec ("InboxesBadgeCount applies the same 2/90 threshold for consistency"). No scope creep; the plan shipped exactly as specified.

## Issues Encountered

- **Worktree initial bootstrap:** as documented (same pattern as Plan 07 + Plan 08 SUMMARY files). No new pattern.
- **Known baseline failure:** `Modules/Ledger/tests/Unit/TransactionTypeTest.php` continues to fail as documented in the orchestrator's `<known_failure>` block. Verified to be pre-existing (unrelated to Plan 09 changes); full-project `vendor/bin/pest` reports 919 passed, 5 skipped, 1 failed.

## Output Questions

The plan's `<output>` block asked seven specific questions. Answers:

- **Was the Gmail format=metadata fetch correctly avoiding the .eml-blob persistence path?** Yes. The DiscoveryScanJob class never imports `EmlBlobStore` or `GmailApiClientContract::getRawMessage`; the only client call from the discovery handler is `listDiscoveryCandidates` which uses `format=metadata`. The static grep `grep -E "$blobStore->put|->getRawMessage" Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php` returns zero hits. The integration test additionally asserts (a) `storage/app/inbox/` stays empty across three sequential runs and (b) a defensive grep over the Fake's call log finds zero `getRawMessage` entries.
- **Was Microsoft Graph's $search endpoint usable with the keyword OR query?** Yes. The implementation uses `$search="subject:(<quoted OR-list>)"` + `$top=100` + `$select=id,from,subject,receivedDateTime`. The plan-time research correctly identified that `$filter contains(subject, ...)` is rejected for the messages collection (per https://learn.microsoft.com/en-us/graph/search-query-parameter) and that `$search` is mutually exclusive with `$orderby`; the implementation omits `$orderby` and accepts the server's default ordering. Exclude-senders filtering happens client-side because Graph rejects `$search + not from/...` combinations.
- **Did the daily Schedule entry actually appear in `php artisan schedule:list` with name `email-scan.discovery`?** Yes. After running `php artisan cache:table && php artisan migrate --force` (one-time per environment to set up `cache_locks` for the `withoutOverlapping` mutex), `php artisan schedule:list | grep email-scan` prints:
  ```
  0 * * * *  email-scan.incremental ............ Next Due: 48 minutes from now
  0 0 * * *  email-scan.discovery ................ Next Due: 18 hours from now
  ```
- **Was InstallLaunchdCommandTest correctly skipped on non-Darwin hosts?** Yes — the test's `beforeEach` calls `$this->markTestSkipped('launchd plist install is macOS-only; skipping on '.PHP_OS_FAMILY)` when `PHP_OS_FAMILY !== 'Darwin'`. The current run host (`darwin`) exercises all 4 cases (30 assertions); a Linux/Windows CI host would skip cleanly.
- **Did `launchctl bootstrap` get correctly avoided during the test?** Yes — the test subclasses `InstallCommand` and overrides the protected `bootstrapPlist(int $uid, string $plistPath)` method to push `['uid' => $uid, 'plistPath' => $plistPath]` onto a static array instead of invoking `launchctl`. The test asserts the capture array has the expected number of entries (2 with `--without-redis`, 3 without) and contains the expected sandbox paths. The developer's real `~/Library/LaunchAgents/` is not touched.
- **Was the Redis plist's `docker run` line confirmed against the Phase 5 Redis setup?** Yes. The README's Phase 5 docker-run line (`docker run --name diederik-redis -p 127.0.0.1:6379:6379 -v diederik-redis-data:/data -d redis:7-alpine redis-server --save 60 1`) was lifted verbatim, dropping only the `-d` flag because launchd runs the container in the foreground for supervision. The plist hard-codes `/usr/local/bin/docker` as the Docker binary path; users on Apple Silicon (Docker installed at a Homebrew-Apple-Silicon-prefix path) may need to edit the path before bootstrap. The plist's leading comment documents this.
- **Were any UI-SPEC copy strings deviated from?** None. Every locked copy string for the Discovered senders panel landed verbatim per UI-SPEC § Copywriting Contract: "Discovered senders" (heading), "Senders that look like they send receipts but aren't on your known-receipts list yet. Add the ones you want diederik to scan; dismiss the rest." (subheading), `Seen {N} times` (occurrence chip with tabular-nums), `{senderName} · last seen {humanDiff}` (secondary line with localPart fallback), `Add` / `Dismiss` (chip labels), aria-label `Add {senderEmail}` / `Dismiss {senderEmail}`.
- **Phase 6 close-out gate: are all 5 ROADMAP success criteria now demonstrably met?**
  - SC#1 (Connect Gmail / Microsoft 365 inboxes via OAuth) — met by Plans 03 + 04 (OAuth flows + wizard); the discovery panel's Promote action also exercises the known_senders surface end-to-end.
  - SC#2 (configurable 1–12 month backfill) — met by Plan 05 BackfillInboxJob + the backfill window modal.
  - SC#3 (kill/restart resume) — met by Plan 07 IncrementalScanJob's cursor-walk + the persistent inbox_scan_state row. Verified via ResumeFromCursorTest.
  - SC#4 (health view) — met by Plan 08 EmailScanHealthTile + InboxesHealthBadge + reauth toast.
  - **SC#5 (chmod-600 secrets + macOS launchd plists for Horizon and scheduler) — NOW MET by this plan.** Chmod-600 secrets storage was met by Plan 02 OAuthSecretsRepository; macOS launchd plists are now shipped under `deploy/launchd/` and installable via `php artisan diederik:install --launchd`. InstallLaunchdCommandTest's 4 cases drive the install path.

## Next Phase Readiness

- **Phase 6 closes here.** All five ROADMAP success criteria for the phase are met. The `known_senders` allow-list is now both system-seeded (PayPal / ICS / Google Play from Plan 02) and user-growable via the Promote action on the discovered senders panel.
- **Phase 7 (parser stage) is unblocked.** The Phase 7 matcher registry reads `KnownSenderQuery::all(User)` to know which sender patterns to handle; the Promote action's user-sourced known_senders rows flow into that read path automatically.
- **One known follow-up:** the Redis plist's Docker binary path is hard-coded to `/usr/local/bin/docker`. Users on Apple Silicon with Docker installed at a different path may need to either (a) edit the plist's ProgramArguments before bootstrap or (b) skip the Redis plist via `--without-redis` and rely on Docker Desktop autostart. The plist's leading comment documents this; a future operational-hardening plan could auto-detect the Docker binary path via `which docker` at install time.

## Self-Check: PASSED

**Created files (verified exist):**

- FOUND: Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php
- FOUND: Modules/EmailScan/Public/Dto/DiscoveredSenderDto.php
- FOUND: Modules/EmailScan/Public/Services/DiscoveredSenderQuery.php
- FOUND: Modules/EmailScan/Public/Actions/PromoteDiscoveredSender.php
- FOUND: Modules/EmailScan/Public/Actions/DismissDiscoveredSender.php
- FOUND: Modules/EmailScan/tests/Integration/DiscoveryScanNoEmlBlobsTest.php
- FOUND: Modules/EmailScan/tests/Feature/DiscoveredSendersPanelTest.php
- FOUND: tests/Feature/InstallLaunchdCommandTest.php
- FOUND: deploy/launchd/com.diederik.horizon.plist
- FOUND: deploy/launchd/com.diederik.scheduler.plist
- FOUND: deploy/launchd/com.diederik.redis.plist

**Modified files (verified exist):**

- FOUND: Modules/EmailScan/Internal/Clients/GmailApiClient.php
- FOUND: Modules/EmailScan/Internal/Clients/GmailApiClientContract.php
- FOUND: Modules/EmailScan/Internal/Clients/GraphApiClient.php
- FOUND: Modules/EmailScan/Internal/Clients/GraphApiClientContract.php
- FOUND: Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php
- FOUND: Modules/EmailScan/Internal/Clients/FakeGraphApiClient.php
- FOUND: Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php
- FOUND: Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php
- FOUND: Modules/EmailScan/Public/Services/InboxesBadgeCount.php
- FOUND: Modules/EmailScan/Providers/EmailScanServiceProvider.php
- FOUND: Modules/EmailScan/tests/Feature/TopNavBadgeViaComposerTest.php
- FOUND: Modules/Core/Internal/Console/InstallCommand.php
- FOUND: phpstan.neon
- FOUND: routes/console.php
- FOUND: README.md

**Commits (verified exist):**

- FOUND: f9180d9 — feat(06-09): DiscoveryScanJob + real Gmail/Graph discovery clients + daily schedule
- FOUND: b542349 — feat(06-09): discovered senders panel + Promote/Dismiss actions + 2/90 threshold
- FOUND: f6b6dd2 — feat(06-09): macOS launchd plists + diederik:install --launchd + README setup section

---

*Phase: 06-email-receipt-ingestion-infrastructure*
*Completed: 2026-05-17*
