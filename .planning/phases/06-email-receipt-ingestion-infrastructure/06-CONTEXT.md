# Phase 6: Email Receipt Ingestion Infrastructure - Context

**Gathered:** 2026-05-16
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 6 ships the email-ingestion plumbing only — Phase 7 owns the receipt-to-transaction parsing. The deliverable is: connect Gmail and Microsoft 365 inboxes via OAuth2, fetch the messages that match a curated "known receipt sender" list, persist them as raw `.eml` files plus a thin DB index, run all that as queued background work with resumable scan-state cursors, and surface the connection lifecycle (add, reauth, scan health, backfill progress) in a calm `/inboxes` page plus a dashboard tile.

**What Phase 6 delivers (vertical):**
- An interactive OAuth-client-registration wizard so the user can register their own Google Cloud project + Azure App without leaving the app (Google verification gate is intentionally avoided — see D-111).
- A per-inbox OAuth user-authorization flow (the "Connect Gmail" button) that runs against `https://diederik.test/oauth/callback/{provider}` (loopback redirect — no public webhook URL needed) and stores the refresh token in a chmod-600 JSON file.
- A `Modules/EmailScan/` bounded module owning all email plumbing — public read API (`InboxQuery`, `KnownSenderQuery`, `OAuthSecretsRepository`) for downstream phases to consume.
- Two queued jobs on Horizon (which is already running from Phase 5): `BackfillInboxJob` (chunked, resumable, per-inbox single-flight) and `IncrementalScanJob` (hourly, walks Gmail `historyId` / Graph `$delta` cursors).
- A `DiscoveryScanJob` that runs a broader keyword query daily to surface candidate receipt senders not yet in the known-senders list, with an in-UI promotion loop.
- Raw `.eml` blobs persisted to disk under `storage/app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/` plus a tiny `inbox_messages` index table whose status enum is the Phase 6/Phase 7 handoff contract.
- A `/inboxes` top-nav page that hosts: empty-state hero, connected-inboxes table with per-row health badge + Scan-Now + Reconnect + Window-edit, "Add inbox" cards that trigger the OAuth-client wizard on first use, and a "Discovered senders" panel.
- A dashboard "Email scan health" tile mirroring the Phase 5 "Next ICS settlement" tile pattern.
- macOS `launchd` plists for Horizon + scheduler (and optionally Redis), packaged under `deploy/launchd/` and installable via an artisan command (PLT-04).

**What Phase 6 does NOT deliver:**
- Per-sender template matchers (EML-05, Phase 7).
- `.eml` / `.mbox` file-import drop-in path (EML-07, Phase 7).
- Any code that turns a fetched message into a `Transaction` row. The `inbox_messages.status` enum stays at `fetched` after Phase 6; Phase 7 owns the transitions to `parsed` / `skipped` / `unmatched`.
- IMAP anywhere. Provider APIs only — `ext-imap` ban (PLT-05) is the project constraint that motivates this entire approach.
- Real-time push notifications (Gmail Watch + Graph subscriptions both require a public webhook URL — not available on a local-only install).

**Architectural override from project constraints:**
The research doc (`.planning/research/PITFALLS.md` Pitfall 5, `.planning/research/ARCHITECTURE.md` §"Sync model") recommended `webklex/laravel-imap` + IMAP. PROJECT.md explicitly overrides this: "Email integration: Provider APIs only (Gmail API, Microsoft Graph) — Avoids any dependency on `ext-imap` and the IMAP library churn. iCloud Mail is explicitly out of scope." Phase 6 honors the project constraint, not the research doc. iCloud Mail is out of scope; PayPal / ICS / Google Play receipts come through Gmail or Microsoft 365 only.

</domain>

<decisions>
## Implementation Decisions

### OAuth Client Provisioning

- **D-111:** **Bring-your-own OAuth client per install.** Each diederik installation registers its own Google Cloud project + Azure App Registration. No single shipped diederik `client_id`. Google's `gmail.readonly` is a sensitive scope and Microsoft Graph's `Mail.Read` is similarly gated — verification of a single shipped client would require a paid security review, demo video, privacy-policy URL, domain verification, and weeks of back-and-forth. Bring-your-own bypasses the verification gate (each install is its own Google Cloud project, owned by the user, in Testing mode with the user as the registered test user — refresh tokens issued to a test user in Testing mode don't expire). Trade-off: the installer must do the one-time GCP/Azure setup. Verification of a published "diederik" app is a future option (not in Phase 6) if multi-household distribution ever matters.
- **D-112:** **Single chmod-600 JSON at `storage/app/secrets/email-oauth.json`** holds both the OAuth client_id/secret per provider AND the per-inbox refresh tokens. Shape:
  ```
  {
    "providers": {
      "gmail":     { "client_id": "...", "client_secret": "...", "redirect_uri": "..." },
      "microsoft": { "client_id": "...", "client_secret": "...", "redirect_uri": "..." }
    },
    "inboxes": [
      { "id": <uuid>, "provider": "gmail", "email": "...", "refresh_token": "...", "scope": "...", "expires_at": "..." }
    ]
  }
  ```
  Rotation rewrites the file atomically: write to `email-oauth.json.tmp`, `fsync`, `rename`. A single `OAuthSecretsRepository` Public service is the only DI surface that touches this file. PLT-03 invariant: both client secrets and refresh tokens live outside the database — no exception.
- **D-113:** **OAuth scopes = read-only with body access** plus `offline_access` (required by both providers to issue a refresh token). Gmail: `https://www.googleapis.com/auth/gmail.readonly`. Microsoft Graph: `Mail.Read` + `offline_access`. Sufficient for Phase 7's template matchers to parse merchant + amount + currency from receipt bodies. Explicitly NOT `gmail.modify` / `Mail.ReadWrite` — diederik never labels, moves, or deletes messages.
- **D-114:** **First-launch per-provider modal wizard for OAuth-client registration.** When the user clicks "Add Gmail" or "Add Microsoft 365" on `/inboxes` and no client is configured for that provider, a Flux modal opens with numbered steps + screenshots + "Open Google Cloud Console" / "Open Azure Portal" deep-link buttons + paste-back fields for `client_id`, `client_secret`, plus a copy-to-clipboard for the exact redirect URI Google/Microsoft requires (`https://diederik.test/oauth/callback/{provider}`). Submit writes the chmod-600 JSON via `OAuthSecretsRepository`. After registration completes, the modal closes and the user is redirected directly into the provider's OAuth consent flow without an extra click. The wizard never reappears once that provider has a client configured (until the user explicitly resets via `/settings`).
- **D-115:** **Revoked-grant recovery via manual reconnect.** When Google/Graph returns `invalid_grant` on a refresh attempt (user changed password, manually revoked from `myaccount.google.com`, refresh token expired after 6-month inactivity), the inbox transitions to `inbox_scan_state.status = 'needs_reauth'`. `/inboxes` shows a red badge on that row + a `Reconnect` button. Background scans pause silently for that inbox. A one-shot toast notification fires on the dashboard the first time the state is detected. Clicking `Reconnect` runs the same per-inbox OAuth consent flow; the new refresh token replaces the old one in the chmod-600 JSON. Existing `inbox_messages` rows + on-disk `.eml` blobs + `last_history_id` cursor are preserved — no forced full backfill.

### Raw Message Persistence

- **D-116:** **Raw `.eml` blobs on disk, plus a thin DB index.** Each fetched message is stored as raw RFC 822 `.eml` (Gmail `users.messages.get?format=raw`, Graph `/messages/{id}/$value` MIME endpoint) at:
  ```
  storage/app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/{provider_message_id}.eml
  ```
  Date partitioning by `internal_date` keeps any single directory under ~5k files for fast `ls`. Attachments stay embedded in the `.eml` (Gmail/Graph return them inline in the raw format). Path is derived from `inbox_messages.internal_date` + `provider_message_id` — both are columns Phase 7 reads to locate the file. Kept forever per project's "history retained forever" constraint; recurring-detection (Phase 8) and subscription-drift (Phase 9) both depend on long-tail history.
- **D-117:** **`inbox_messages` table is the Phase 6/Phase 7 handoff contract.** Columns: `id`, `user_id` (FND-03), `inbox_id`, `provider_message_id`, `internal_date`, `sender_email`, `sender_name` (nullable, parsed from RFC 822 `From` header), `subject` (nullable), `status` enum, `fetched_at`, timestamps. `UNIQUE (inbox_id, provider_message_id)` — same `provider_message_id` re-fetched is a no-op (Phase 6 idempotency contract). Status enum: `fetched` (Phase 6 wrote the `.eml`), `parsed` (Phase 7 matcher created a transaction), `skipped` (Phase 7 matcher recognized but produced no transaction — e.g., "PayPal login from a new device" notification), `unmatched` (no matcher claimed it; eligible for re-parse after Phase 7 matcher additions). Phase 6 only writes `status='fetched'`; Phase 7 owns the rest of the lifecycle.
- **D-118:** **Sender + subject extraction at fetch time, not at parse time.** Phase 6 parses just enough of the `.eml` header (`From`, `Subject`, `Date`) to populate the index row. Avoids Phase 7 having to open every `.eml` just to filter by sender. Phase 7 still re-parses bodies via its own matcher pipeline — the index is a search/filter layer, not a parsed-state cache.

### Backfill Scope + Discovery Loop

- **D-119:** **Server-side `from:sender` filter for primary scan.** Phase 6's primary fetch query is `q=from:(<expanded known_senders OR-list>)` on Gmail and an equivalent `$filter=from/emailAddress/address in (...)` on Graph. Only messages from known receipt senders are fetched and `.eml`-persisted. Drastically smaller bandwidth than fetching every message — a 50k-message Gmail account typically has 50–500 receipts per year out of the full inbox. Pushes the filter to the provider so we never download the bulk of personal mail.
- **D-120:** **DB-backed mutable sender lists with system + user provenance.** Two new tables, both user-scoped per FND-03:
  - `known_senders`: `id`, `user_id`, `email_pattern` (e.g., `paypal.com` or `noreply@paypal.com`), `label` (e.g., "PayPal NL"), `source` enum (`system` for shipped seeds, `user` for promoted/manually-added), `added_at`, timestamps. Migration seeds the `system` rows: PayPal (`paypal.com`), ICS Cards (`@ics.nl` / `@icscards.nl`), Google Play (`googleplay-noreply@google.com`). Seed list is intentionally small + conservative; the discovery loop is the mechanism that grows it per-user.
  - `discovered_senders`: `id`, `user_id`, `inbox_id`, `sender_email`, `sender_name`, `occurrence_count`, `last_seen_at`, `sample_message_id` (nullable FK to `inbox_messages.id`), `state` enum (`candidate` / `added` / `dismissed`), timestamps. `UNIQUE (user_id, inbox_id, sender_email)`.
- **D-121:** **DiscoveryScanJob runs daily with a broad keyword filter.** Query shape: `subject:(receipt OR factuur OR betaling OR invoice OR order OR bevestiging) -from:(<already-known-or-dismissed>)` on Gmail; equivalent on Graph. Writes only sender metadata to `discovered_senders` (no `.eml` blobs). Promotion threshold (planner-tunable, default: 2 occurrences within 90 days) keeps single-shot senders out of the candidate list. User reviews candidates on the `/inboxes` page → clicking "Add" inserts a `user`-sourced row into `known_senders` and transitions the discovered row to `state='added'`. Clicking "Dismiss" sets `state='dismissed'` so the discovery query excludes it on future runs.
- **D-122:** **Chunked `BackfillInboxJob` with per-inbox single-flight.** Job processes Gmail/Graph results in pages of ~100 messages per page. After each page: write `inbox_messages` rows + `.eml` blobs + update `inboxes.backfill_progress` JSON (`{ total_estimated, fetched_count, last_message_date, last_history_id }`) within a single DB transaction wrapping the row writes (the `.eml` filesystem writes happen first, with cleanup-on-rollback). `ShouldBeUniqueUntilProcessing` keyed on `inbox_id` — different inboxes back-fill in parallel, same inbox never double-runs. On HTTP 429 / Gmail quota: catch, sleep per `Retry-After` header, resume. UI surfaces progress via `wire:poll.2s` on `/inboxes` ("Backfilling Gmail: 1,234 / ~5,200 messages…"). Extending the window (3 → 12 mo) re-queues the same job starting from current-oldest-fetched walking backwards — never re-fetches already-persisted messages.
- **D-123:** **`inbox_scan_state` table holds the resume cursor + lifecycle.** Columns: `id`, `user_id`, `inbox_id`, `folder` (default `INBOX`; multi-folder supported by Gmail labels / Graph folders as a v2 extension), `last_history_id` (string, Gmail cursor), `last_delta_link` (string, Graph cursor URL), `last_scan_at`, `status` enum (`idle` / `backfilling` / `scanning` / `rate_limited` / `needs_reauth` / `error`), `error_message` (nullable), `retry_attempts` (int, exponential-backoff state per EML-08), timestamps. `UNIQUE (inbox_id, folder)`. Phase 6 SC#3 ("After a kill / restart, the scanner resumes from the last successful UID per inbox+folder") is met by reading `last_history_id` / `last_delta_link` on each `IncrementalScanJob` run. "UID" in the roadmap language maps to Gmail's `historyId` and Graph's delta-link cursor — Phase 6 normalizes both behind a single `ScanCursor` value object.

### Inbox Connection UX

- **D-124:** **New `/inboxes` top-nav item, sibling of `/transactions` and `/chains/review`.** Page sections, top-to-bottom: connected-inboxes table (one row per inbox with email + provider + last-scan badge + status badge + inline Scan-Now + Reconnect + Window-edit actions), an active-backfill progress strip when one is running (wire:poll-driven), an "Add inbox" card with two big "Connect Gmail" / "Connect Microsoft 365" buttons, a "Discovered senders" panel listing `discovered_senders` rows with `state='candidate'` and Add/Dismiss chips per row. Matches the calm Linear/Notion aesthetic (UI-05) — single coherent page that owns email ingestion.
- **D-125:** **Dashboard "Email scan health" tile** alongside Phase 5's "Next ICS settlement" tile. Compact tile shows: "Gmail: last scanned 3 h ago" + "Microsoft 365: last scanned 8 h ago" (one line per connected inbox; max 3 lines, then truncates with "+N more"). Tile-level status dot: red when ANY inbox is in `needs_reauth`, gray when stale (>24 h since last successful scan on any inbox), green when all inboxes are healthy. Tile click → `/inboxes`. Tile is hidden entirely when no inboxes are connected (no "Connect your email" CTA on the dashboard — that lives on `/inboxes`).
- **D-126:** **Top-nav badge on the "Inboxes" item** — count of (unreviewed `discovered_senders` with `state='candidate'` + inboxes with `inbox_scan_state.status='needs_reauth'`). Same rendering pattern as Phase 5's `/chains/review` nav badge fed by a View Factory composer (carry-forward of issue #12 fix: no `view()` global helper; uses `$this->app->make(ViewFactoryContract::class)->composer(...)`).
- **D-127:** **Empty-state hero on first visit to `/inboxes`.** When no inboxes are connected, the page renders as a centered hero with a short explainer ("Connect your email to import receipts from PayPal, ICS, Google Play, and other merchants") plus two big buttons "Connect Gmail" / "Connect Microsoft 365". Clicking either button triggers the OAuth-client-registration modal (D-114) if no client is configured for that provider yet, otherwise jumps directly into the OAuth consent flow. After the first inbox is connected, the page switches to its normal table-driven layout.
- **D-128:** **Backfill window picker = modal after OAuth callback completes, plus inline edit.** After the OAuth consent redirect returns and the inbox row is freshly inserted, a Flux modal opens: "How far back should we look?" with a 1–12 month slider (default 3 per EML-04). Submit → `BackfillInboxJob` queued with that window. The inbox row on `/inboxes` thereafter shows "Window: 3 months [Edit]" — clicking Edit re-opens the same modal. Extending the window (3 → 12 mo) re-queues `BackfillInboxJob` starting from the current-oldest-fetched message walking backwards (per D-122). Shrinking the window does NOT delete already-fetched messages — diederik keeps everything that was ever fetched per the "history retained forever" constraint.

### Module Shape + Integration

- **D-129:** **New `Modules/EmailScan/` bounded module** with Public/ surface from day one (Phase 7 immediately consumes it). Public surface:
  - `InboxQuery::forCurrentUser(): array<InboxHealthDto>` — connected inboxes + last_scan_at + status + backfill progress.
  - `KnownSenderQuery::all(): array<KnownSenderDto>` — used by Phase 7's matcher registry to know which sender patterns to handle. Phase 7 ADDS a `matcher_key` column (nullable in Phase 6 schema) when its matchers register.
  - `OAuthSecretsRepository::loadInbox(int $inboxId): ?InboxCredentials` / `rotateRefreshToken(int $inboxId, string $newToken): void` — the only allowed touchpoint to the chmod-600 JSON.
  - `InboxMessageQuery::forStatus(string $status): iterable<InboxMessageDto>` — Phase 7 iterates `status='fetched'` rows.
  Internal/: `GmailApiClient`, `GraphApiClient`, OAuth-callback Livewire handler, `BackfillInboxJob`, `IncrementalScanJob`, `DiscoveryScanJob`, `InboxScanStateMachine`, `/inboxes` Livewire SFC, OAuth-client wizard modal. Follows the Phase 5 `Modules/Chains/` shape (composer.json, ServiceProvider, Public/Internal split, BoundaryArchTest extension).
- **D-130:** **External Composer dependencies — planner picks the SDK strategy.** Two real options, both viable:
  - **(a)** Official SDKs: `google/apiclient` ^2.x + `microsoft/microsoft-graph` ^2.x. Most coverage, well-tested OAuth helpers, larger transitive dep footprint. Composer install audit MUST confirm no transitive pulls of `ext-imap` (PLT-05 invariant) and no `webklex/laravel-imap` regressions.
  - **(b)** Thin custom Guzzle wrappers around the REST endpoints. Smaller dep footprint, more code to maintain, more empirical OAuth-edge-case handling.
  Plan-phase researcher loads both into the trade-off and picks. The Public surface is identical either way.
- **D-131:** **launchd plists ship in Phase 6 per PLT-04.** Three plists, packaged under `deploy/launchd/`:
  - `com.diederik.horizon.plist` — `php artisan horizon` (queue supervisor).
  - `com.diederik.scheduler.plist` — `php artisan schedule:work` (the scheduler that dispatches `IncrementalScanJob` hourly + `DiscoveryScanJob` daily via Laravel `Schedule`).
  - `com.diederik.redis.plist` — optional, only when the user isn't running Docker Desktop on login (`docker run` for the loopback Redis container per Phase 5 D-102).
  Phase 6 ships `php artisan diederik:install --launchd` that copies the plists to `~/Library/LaunchAgents/` and runs `launchctl bootstrap gui/$(id -u)`. README amendment under "Setup" lists the new commands.
- **D-132:** **Phase 6/Phase 7 boundary** — Phase 6 lands `inbox_messages` rows + `.eml` blobs; Phase 7 reads them. The contract is: Phase 7 receives a stream of `status='fetched'` `inbox_messages` rows + a deterministic path to the `.eml` on disk. Phase 6 includes the `known_senders` table (so the primary scan filter has a list to read) but does NOT define matchers, does NOT touch `transactions`, does NOT trigger transitions out of `status='fetched'`. A new `BoundaryArchTest::noTransactionWritesFromEmailScan` rule mirrors the Phase 4 `noPaypalApiRoute` / Phase 5 `noTransactionMutationsFromChains` invariants — asserts no `Modules/EmailScan/` file calls `Transaction::create()` / raw `insert` against `transactions`.

### Claude's Discretion

- **D-133:** Exact Google + Microsoft SDK version pins (D-130 path a vs b), transitive-dep audit findings, retry/backoff knobs (exponential start + ceiling) per EML-08 — planner verifies against research + composer-audit and picks.
- **D-134:** Whether the OAuth-client modal supports "edit existing client_id" (re-paste) or "reset only", and how a reset interacts with already-connected inboxes (likely policy: reset → mark all child inboxes as `needs_reauth` rather than purge them).
- **D-135:** Exact `discovered_senders` promotion threshold (default: 2 occurrences within 90 days) — planner can tune against the synthesised-inbox fixture in Wave 0.
- **D-136:** UI-SPEC pass during plan-phase locks the exact Flux components (table, modal, drawer-or-not for inbox-row detail, slider, segmented toggle) for `/inboxes`. Matches the UI-SPEC discipline established in Phase 5.
- **D-137:** Default scan cadence — suggested: `IncrementalScanJob` hourly, `DiscoveryScanJob` daily, `BackfillInboxJob` runs once on inbox-add + on window-extend. Planner verifies hourly cadence against Gmail's 250 quota-units-per-user-per-second + 2.5 GB/day bandwidth limits for realistic inbox sizes.
- **D-138:** Whether OAuth `client_secret` + `refresh_token` strings get a Laravel `Crypt::encrypt()` layer on top of the chmod-600 file (defense-in-depth) — defer to planner; chmod-600 alone satisfies PLT-03. If layered, the encryption key lives in `.env` per Laravel standard (already chmod-600-equivalent on a single-user macOS install).
- **D-139:** Whether `inbox_messages.sender_email` is normalized at write time (lowercase, strip `+plus` aliases) or stored verbatim and normalized at query time. Pattern-match precedent: Phase 1 `merchant_memories.normalized_merchant` is normalized at write — same pattern likely applies.
- **D-140:** Wave 0 fixture synthesis — Phase 6 has no real corpus until the user connects a Gmail inbox. Wave 0 should ship a synthesised set of `.eml` files (anonymized real PayPal / ICS / Google Play receipts under `Modules/EmailScan/tests/fixtures/`) plus a stub `FakeGmailApiClient` / `FakeGraphApiClient` that the test harness uses to exercise the full ingest pipeline without touching real APIs. Same shape as Phase 5's synthesised cross-source fixtures (D-107).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-Level
- `.planning/PROJECT.md` — Project constraints. Critical here: "Email integration: Provider APIs only (Gmail API, Microsoft Graph) — Avoids any dependency on `ext-imap` (removed from PHP 8.4 core) and the IMAP library churn. iCloud Mail is explicitly out of scope" + "Scan all connected inboxes for any known sender pattern (no per-source inbox config required)". DI-only, no facades/helpers (constructor injection invariant). `nwidart/laravel-modules` bounded modules. Larastan level 10 strict + Pint + Pest CI gates. Calm aesthetic (Linear/Notion).
- `.planning/REQUIREMENTS.md` — Phase 6 covers EML-01, EML-02, EML-03, EML-04, EML-06, EML-08, PLT-03, PLT-04. Phase 7 covers EML-05 + EML-07.
- `.planning/ROADMAP.md` §"Phase 6" — Goal + five success criteria (OAuth connect, configurable 1–12 mo backfill, kill/restart resume, health view with `last scan: X hours ago`, chmod-600 secrets + launchd workers).

### Prior Phase Artefacts (read for continuity — same patterns apply)
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-CONTEXT.md` — Module split, DI-only, wizard preview-then-confirm pattern, BelongsToUser invariant.
- `.planning/phases/03-ics-cards-multi-currency-display/03-CONTEXT.md` — `/settings` page extension pattern + Route::view + class-as-handler convention (used by D-114 wizard modal placement and the D-127 onboarding hero).
- `.planning/phases/04-paypal-ingestion-transfer-detection/04-CONTEXT.md` — `Modules/Transfers/` Public/Internal module shape that `Modules/EmailScan/` mirrors. `BoundaryArchTest::noPaypalApiRoute` pattern that D-132 mirrors. Symmetric-write + raw `DatabaseManager` query-builder pattern for strict-rules compliance.
- `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-CONTEXT.md` — Horizon + Redis queue infrastructure that Phase 6 INHERITS (no new queue plumbing). `ShouldBeUniqueUntilProcessing` per-user-key pattern that D-122 mirrors per-inbox. Wizard polling (`wire:poll.2s`) that D-122 mirrors for backfill progress. Failed-job toast pattern (D-103) that D-115 mirrors for revoked-grant first-detection. View Factory composer pattern (issue #12 fix) for the top-nav badge (D-126). Atomic PROJECT.md amendment pattern for stack changes.

### Research
- `.planning/research/SUMMARY.md` — §"Phase 6: Email Receipt Ingestion Infrastructure" identifies Gmail/Graph rate-limit thresholds on backfill as the load-bearing risk (`STATE.md` "Blockers/Concerns" carries this forward).
- `.planning/research/ARCHITECTURE.md` §"Sync model: manual-triggered + scheduled cron, no IMAP IDLE" (L521+) — confirms polling over push (Phase 6 keeps polling because Gmail Watch / Graph subscriptions need a public webhook URL). §"IMAPScanner" (L93+) and §"InboxScanState" (L536+) describe the pre-override IMAP shape — Phase 6 replaces with provider APIs but keeps the per-inbox cursor + folder model.
- `.planning/research/PITFALLS.md` — Pitfall 5 (PHP 8.4 ext-imap removal) is the load-bearing motivation for PROJECT.md's API-only override. Pitfall 6 (IMAP rate-limiting + lockout on backfill) translates to D-122 sequential-page-with-Retry-After. Pitfall 14 (`.env` and IMAP credentials leaking) translates to D-112 chmod-600 file + atomic rotation. Pitfall 13 (scheduler/queue silent failures) is the motivation for D-131 launchd plists + health-view-as-truth (D-124/D-125).
- `.planning/research/STACK.md` — Webklex/laravel-imap recommendation is SUPERSEDED by PROJECT.md API-only override. `database` queue driver is SUPERSEDED by Phase 5's Horizon + Redis (Phase 6 inherits, no new amendment).

### External Documentation (Phase 6's research targets)
- Gmail API documentation — https://developers.google.com/gmail/api — `users.messages.list` (the `q=` parameter syntax for `from:` filters), `users.messages.get?format=raw`, `users.history.list` (the `historyId` cursor model), OAuth 2.0 for desktop apps (loopback redirect URI), `gmail.readonly` scope reference.
- Microsoft Graph documentation — https://learn.microsoft.com/en-us/graph/api/resources/mail-api-overview — `/me/messages` (with `$filter` + `$delta` semantics), MIME `$value` endpoint, `Mail.Read` + `offline_access` scope reference, native-client OAuth 2.0 + PKCE.
- Google Cloud Console OAuth client setup — https://support.google.com/cloud/answer/6158849 — the workflow the D-114 wizard scaffolds.
- Azure App Registration setup — https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app — the workflow the D-114 wizard scaffolds.
- Laravel Horizon docs — https://laravel.com/docs/12.x/horizon — `ShouldBeUniqueUntilProcessing`, supervisor configuration. Phase 6 reuses Phase 5's setup.
- Livewire 4 `wire:poll` docs — https://livewire.laravel.com/docs/wire-poll — D-122 backfill progress polling.
- Flux UI modal + slider components — https://fluxui.dev/ — D-114 wizard modal + D-128 backfill window picker.
- macOS `launchd` plist reference — https://www.launchd.info/ — D-131 plist authoring.

### Existing Source (read before extending)
- `composer.json` — Phase 6 will add `google/apiclient` (or thin Guzzle wrappers per D-130). Composer audit must confirm no `ext-imap` regression (PLT-05).
- `config/queue.php` — Redis driver already wired; Phase 6 just registers new jobs.
- `Modules/Chains/composer.json` + `Modules/Chains/Providers/ChainsServiceProvider.php` — Reference for the new `Modules/EmailScan/` ServiceProvider + composer.json shape.
- `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` — Reference for the `ShouldBeUniqueUntilProcessing` + `dispatch()` + JobFailed listener pattern that D-122 mirrors.
- `Modules/Chains/Internal/CardStatementStateMachine.php` — Reference for the locked-state-mutator pattern that `InboxScanStateMachine` mirrors (D-123 status transitions).
- `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` — Extended in Phase 5 with `nextIcsSettlement()`; Phase 6 adds an analogous `emailScanHealth(): ?EmailScanHealthTile` method for the D-125 dashboard tile.
- `tests/Pest.php` — New `Modules\\EmailScan\\Tests\\` PSR-4 entry needs adding (3-step pattern documented in Phase 4 D-80b → composer.json autoload-dev + phpunit.xml testsuite + Pest.php).
- `Modules/Core/Public/Contracts/CurrentUser.php` — DI-only contract every Phase 6 service injects.
- `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` — Reference for the Livewire SFC + `view-extends('layouts.app')` pattern that `/inboxes` mirrors.
- `app/Console/Kernel.php` — Phase 6 registers `IncrementalScanJob` (hourly) + `DiscoveryScanJob` (daily) here via Laravel `Schedule`.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Horizon + Redis + `failed_jobs` table from Phase 5** — Phase 6 dispatches new jobs onto the same queue. No new queue infrastructure.
- **`ShouldBeUniqueUntilProcessing` pattern from Phase 5's `ResolveChainLinksJob`** — D-122 `BackfillInboxJob` mirrors it keyed on `inbox_id` instead of `user_id`.
- **Wizard polling pattern from Phase 5 (`wire:poll.2s` against a status query)** — D-122 backfill progress strip mirrors the chain-resolution wizard polling shape.
- **Failed-job toast pattern from Phase 4/5 (`$this->dispatch('toast', message: $message)` + Alpine `x-on:toast.window`)** — D-115 revoked-grant first-detection notification reuses this exact shape.
- **View Factory composer pattern from Phase 5 (issue #12 fix)** — D-126 `/inboxes` top-nav badge wiring; no `view()` global helper.
- **Toast-and-badge nav pattern from Phase 5's `/chains/review` route** — D-126 mirrors structurally.
- **`/settings` page pattern from Phase 3** — Route::view + Livewire SFC + page-level Blade wrapper convention applies to the new `/inboxes` page.
- **DI-only invariant + raw `DatabaseManager` for whereBetween/whereIn/orderBy** — Locked across the codebase; every new Phase 6 service follows it.
- **BelongsToUser trait + nullable `user_id` on every domain table (FND-03)** — Applies to `inbox_messages`, `inbox_scan_state`, `inboxes`, `known_senders`, `discovered_senders`.
- **Cross-user 404 invariant (Phase 3-07 + Phase 4-04 + Phase 5-04 pattern)** — All `/inboxes` actions assert `$inbox->user_id === $currentUser->id` defensively + via `where('user_id', ...)` clauses.
- **Atomic PROJECT.md amendment pattern (Phase 4 ROADMAP SC#2 + Phase 5 STACK flip)** — If D-130 path (a) brings in major external SDKs, the Wave-0-equivalent plan owns the README + PROJECT.md edit (e.g., adding `google/apiclient` to the recommended stack section, calling out the transitive-dep audit).
- **GSD-agnostic code comments (Phase 5 lesson)** — No D-numbers / REQ-IDs in runtime code or PHPDocs; rationale described in plain technical language.

### New Code Surface (Phase 6 adds)
- **`Modules/EmailScan/` bounded module** — composer.json, ServiceProvider, Public/Internal split, dedicated tests dir.
- **`Modules/EmailScan/Public/Services/`** — `InboxQuery`, `KnownSenderQuery`, `OAuthSecretsRepository`, `InboxMessageQuery`.
- **`Modules/EmailScan/Public/Dto/`** — `InboxHealthDto`, `KnownSenderDto`, `InboxMessageDto`, `InboxCredentials`, `EmailScanHealthTile`.
- **`Modules/EmailScan/Internal/Clients/`** — `GmailApiClient`, `GraphApiClient` (constructor-injected `Http\Client` + `OAuthSecretsRepository`).
- **`Modules/EmailScan/Internal/Jobs/`** — `BackfillInboxJob`, `IncrementalScanJob`, `DiscoveryScanJob`.
- **`Modules/EmailScan/Internal/InboxScanStateMachine.php`** — only allowed mutator of `inbox_scan_state.status`.
- **`Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php`** — `/inboxes` SFC.
- **`Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php`** — D-114 first-launch modal.
- **`Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php`** — handles `https://diederik.test/oauth/callback/{provider}` redirect-URI hits.
- **`Modules/EmailScan/Database/Migrations/*_create_inboxes_table.php`** — inboxes (id, user_id, provider, email, backfill_progress JSON, created_at, updated_at).
- **`Modules/EmailScan/Database/Migrations/*_create_inbox_scan_state_table.php`** — D-123.
- **`Modules/EmailScan/Database/Migrations/*_create_inbox_messages_table.php`** — D-117.
- **`Modules/EmailScan/Database/Migrations/*_create_known_senders_table.php`** — seeded by migration with PayPal/ICS/Google Play system rows.
- **`Modules/EmailScan/Database/Migrations/*_create_discovered_senders_table.php`** — D-120.
- **`deploy/launchd/com.diederik.horizon.plist` + `com.diederik.scheduler.plist` + `com.diederik.redis.plist`** — D-131.
- **`app/Console/Commands/InstallLaunchdCommand.php`** — `diederik:install --launchd` artisan command.
- **`Modules/EmailScan/tests/fixtures/` + `FakeGmailApiClient` + `FakeGraphApiClient`** — D-140 synthesised inbox + stub clients.

### Established Patterns
- **DI-only — every new service injects collaborators via constructor.** `BackfillInboxJob` injects `DatabaseManager` + `Filesystem` + `GmailApiClient`/`GraphApiClient` + `OAuthSecretsRepository` + `InboxScanStateMachine`.
- **Public/ vs Internal/ split from day one** — Phase 7 immediately reads `Modules/EmailScan/Public/` (D-129), so the surface ships in Phase 6 rather than being promoted later.
- **Eloquent direct OK, no facades** — `Inbox::query()->where('user_id', $user->id)` allowed; `DB::table(...)` forbidden; raw `DatabaseManager` injected via constructor for whereBetween/whereIn shapes (Phase 4/5 pattern).
- **BoundaryArchTest invariants** — D-132 (no transaction writes from EmailScan), no facade calls in `Modules/EmailScan/`, no helper calls (`auth()`, `request()`, `config()`, etc.).
- **Pest test layout** — unit tests next to the code (`Modules/EmailScan/tests/Unit/...`); feature tests for `/inboxes` under `tests/Feature/`; cross-module idempotency / cross-user safety tests under `tests/Contracts/`.
- **Synthesised fixture-first Wave 0** — Phase 5 D-107 precedent. Real email corpus only arrives when the user connects their actual Gmail; Phase 6 must ship green CI without that.

### Integration Points
- **Composer** — D-130 path (a) adds two external SDKs. Plan-phase MUST run a Composer audit + confirm no `ext-imap` regression (PLT-05). PROJECT.md amendment owned by Wave 0 if the SDK strategy is approved.
- **Queue infrastructure** — INHERITED from Phase 5; no changes. Three new job classes register against the existing Horizon supervisor.
- **Schema** — FIVE new migrations: `inboxes`, `inbox_scan_state`, `inbox_messages`, `known_senders` (+ system seed), `discovered_senders`. ZERO changes to `transactions` (D-132 invariant). The Phase 1 `merchant_memories` table is NOT touched by Phase 6.
- **Filesystem** — NEW `storage/app/inbox/` tree. Needs to be added to `.gitignore` (likely already covered by `storage/app/*` pattern). The `storage/app/secrets/` directory is NEW + must be created on first OAuth-client save with chmod-700 on the dir and chmod-600 on the file.
- **launchd** — NEW `deploy/launchd/` directory shipping the three plists. README + PROJECT.md "Setup" section gain a "Background workers" subsection.
- **Top-nav** — NEW "Inboxes" item between existing nav entries. Position locked by UI-SPEC plan-phase pass.
- **Dashboard** — NEW "Email scan health" tile (D-125). `ThisPeriodAtAGlanceQuery` gains `emailScanHealth(): ?EmailScanHealthTile` (or a sibling query is added — planner decides; pattern precedent from Phase 5's `nextIcsSettlement()`).
- **Routes** — NEW `GET /inboxes`, `GET /oauth/callback/{provider}`, `POST /inboxes/{id}/scan-now`, `POST /inboxes/{id}/reconnect`, `POST /inboxes/{id}/window`, `POST /senders/{id}/promote`, `POST /senders/{id}/dismiss`. All gated by the Phase 1 LoopbackOnly + Fortify auth.

### Risks Phase 6 Specifically Owns
- **OAuth-client setup friction** — The D-114 wizard is the only thing standing between a user and a usable Gmail connection. Wave 0 must validate the wizard text + screenshots + deep-links against an actual GCP/Azure setup walkthrough (manual end-to-end test on the developer's own machine).
- **Refresh-token rotation atomicity** — A crash mid-rotation of the chmod-600 JSON could orphan an inbox. D-112 mandates write-tmp + fsync + rename; tests assert the file is never partially written.
- **`.eml` filesystem writes vs DB row commits** — D-122 says `.eml` writes happen first, with cleanup-on-rollback. A failure between `.eml` write and DB commit leaves an orphan `.eml`. Wave 0 covers this with a deliberate-failure-injection test that confirms orphans are cleaned up (and an idempotency test that a re-run after orphan cleanup produces the correct state).
- **Gmail historyId / Graph delta-link cursor expiry** — Both provider cursors can become invalid after long inactivity (Gmail says 7 days for historyId in some docs; Graph delta links eventually 410). Phase 6 must handle the cursor-expired error by falling back to a date-bounded re-scan from `last_scan_at - 7d` and re-baselining the cursor.
- **Quota exhaustion mid-backfill** — Gmail's 2.5 GB/day cap can hit mid-backfill on a large inbox. D-122 `Retry-After` handling + per-inbox single-flight prevents thrashing, but the user must see a clear "Backfill paused — quota will reset in N hours" message via `inbox_scan_state.status='rate_limited'`.
- **OAuth-client wizard onboarding for non-technical users** — The wizard is the project's hard accessibility limit: GCP/Azure Portal navigation cannot be hidden behind the wizard's deep-links. PROJECT.md "the app should be usable by non-technical users" is honored ONLY past the OAuth-client step (the per-inbox Connect button IS non-technical). Plan-phase researcher should evaluate whether a future "verified diederik app" path is worth queuing as a v2 work item.
- **`.eml` retention vs disk size** — A user with 10 years of receipts at ~50 KB each is ~25 MB — well within tolerance. A misconfigured discovery-scan that accidentally fetches everything would blow up disk usage; D-119 server-side `from:` filter is the load-bearing safeguard.
- **launchd plist UX for non-technical install** — Even with `php artisan diederik:install --launchd`, the user has to grant Terminal accessibility / Full Disk Access permissions on macOS for launchd to spawn `php`. README must walk this step-by-step.

</code_context>

<specifics>
## Specific Ideas

- **PROJECT.md's API-only override is the load-bearing pivot.** The research doc recommends `webklex/laravel-imap` (pure-PHP IMAP, bypassing the PHP 8.4 ext-imap removal); PROJECT.md says "provider APIs only". Phase 6 follows PROJECT.md. The win: no IMAP server compatibility surface, no UID/UIDVALIDITY weirdness, OAuth-only auth (no app-passwords), iCloud explicitly out-of-scope from day one.
- **Bring-your-own OAuth client is intentional, not a workaround.** Single-install OAuth verification for diederik would cost the developer a paid CASA-style audit + weeks of back-and-forth with Google + a privacy-policy domain. Bring-your-own bypasses the gate entirely. The cost is the OAuth-client wizard (D-114) — front-loaded one-time setup. The benefit is that diederik stays a side-project that never has to maintain a published-app posture.
- **The "non-technical user" constraint applies AFTER the OAuth client wizard, not during.** The developer / installer does the wizard once; thereafter, anyone (including the partner per the multi-user-readiness constraint) can connect their own inbox with a single button click that opens Google's own consent screen. Non-technical use case = the inbox connection, the scan health, the discovered-senders review — NOT the GCP/Azure registration.
- **Polling, not push.** Gmail Watch / Graph subscriptions both require a public webhook URL — diederik is local-only and never has one. Polling via Horizon-supervised scheduled jobs is the only viable model. `IncrementalScanJob` hourly is a reasonable starting cadence for a personal-use receipt scanner; emails take hours to land anyway.
- **Server-side `from:` filter is the privacy + bandwidth invariant.** A naive "fetch everything in the window" approach would pull megabytes of unrelated mail off Gmail's servers, persist it locally, and burn the user's Gmail bandwidth quota in a single backfill. Server-side filtering keeps the fetched corpus to a few hundred messages per year — small enough that disk + .eml retention is a non-issue.
- **The discovery loop is the antidote to a too-narrow seed list.** Seed `known_senders` ships small (PayPal, ICS, Google Play). The discovery scan finds candidates the user didn't know they had. The user promotes (or dismisses), and the primary scan's filter widens organically. This is the calm version of "auto-detect every sender that looks like a receipt" — never auto-acts, always asks.
- **`/inboxes` is a single coherent page.** Onboarding hero (empty state) + connected-inboxes table + add-inbox cards + discovered-senders panel all live on one route. No multi-tab settings page, no separate /senders /health /backfill routes. Matches the calm-aesthetic discipline: one domain → one page.
- **Dashboard tile follows the Phase 5 "Next ICS settlement" tile pattern exactly.** Same `ThisPeriodAtAGlanceQuery`-extension shape, same conditional render (hide tile when no inboxes), same tile click → relevant page navigation. The dashboard becomes a daily-glance surface that summarizes every async system's health.
- **The phase deliberately does NOT touch the `transactions` table.** Phase 6's job ends at `inbox_messages.status='fetched'`. Phase 7 owns the transition from "raw email on disk" to "canonical transaction". A `BoundaryArchTest` invariant (D-132) makes this structurally impossible to violate, mirroring Phase 4/5 boundary tests.
- **The scan state model normalizes Gmail's historyId and Graph's delta-link behind a single `ScanCursor` value object.** "UID-resume" in the ROADMAP language maps to provider-specific cursors that work fundamentally the same way: read the cursor, ask the provider "what's new since then", get back changes + a new cursor. Phase 6 hides the provider difference behind the `InboxScanStateMachine` so Phase 7's matchers never see it.
- **Wave 0 ships synthesised fixtures + fake API clients.** Same precedent as Phase 5 D-107. Real Gmail / Graph integration is exercised via a manual end-to-end test on the developer's own machine, separately from CI.

</specifics>

<deferred>
## Deferred Ideas

- **Verified-app distribution path** — Phase 6 ships bring-your-own OAuth client. If multi-household distribution becomes a goal (v2+), pursue Google + Microsoft OAuth verification: privacy-policy URL on a verifiable domain, demo video, domain verification, etc. Architecture supports the flip — `OAuthSecretsRepository` just gets a hardcoded `client_id`/`secret` instead of paste-from-wizard.
- **Gmail Watch + Cloud Pub/Sub push notifications** — Requires a public webhook URL. Out of scope for local-only.
- **Microsoft Graph change-notification subscriptions** — Same constraint as Gmail Watch.
- **iCloud Mail integration** — Explicitly out of scope per PROJECT.md ("No public API; would force IMAP back into the stack"). The `.eml`/`.mbox` drop-in path in Phase 7 covers anyone who wants iCloud receipts ingested.
- **OAuth password / app-password fallback** — Killed by Google for free accounts; intentionally out of scope. OAuth2 only.
- **Encryption-at-rest on the chmod-600 JSON** — D-138 leaves this to the planner. chmod-600 + APP_KEY-based encryption is a defense-in-depth nice-to-have, not a Phase 6 requirement.
- **macOS Keychain integration for secret storage** — Listed in `.planning/REQUIREMENTS.md` v2 Requirements (Deferred). Phase 6 ships chmod-600 file; Keychain via `security` CLI shellout is a future enhancement.
- **Multi-folder scan support** — Phase 6 ships `INBOX`-only (the default for both providers). Multi-folder support (Gmail label + sub-folder filtering, Graph mail-folder selection) is a v2 refinement; `inbox_scan_state` schema is already multi-folder-ready (`folder` column).
- **`+plus` alias normalization for `sender_email`** — D-139 defers to planner. Likely yes (apply normalization at write-time, mirroring `merchant_memories.normalized_merchant`).
- **Per-inbox throttling overrides** — Phase 6 uses a single backoff policy per provider. Per-inbox custom rate-limit knobs are a v2 refinement; nothing in the schema blocks adding them later.
- **Inbox priority / ordering on `/inboxes`** — Phase 6 sorts inboxes by `created_at`. Drag-to-reorder is a v2 nicety.
- **OAuth-client wizard "edit existing" mode** — D-134 defers to planner. Likely policy: reset → mark all child inboxes as `needs_reauth`, no purge.
- **PROJECT.md amendment to acknowledge `google/apiclient` + `microsoft/microsoft-graph` if D-130 path (a) wins** — Owned by Wave 0 plan, atomically with the new composer deps.
- **Manual "Scan now" with custom date range / sender filter** — Phase 6 ships "Scan now" as "re-run IncrementalScanJob immediately". Ad-hoc filtered scans (e.g., "scan only PayPal for last week") are a v2 power-user feature.
- **Push-style real-time email delivery via a tunneling service (ngrok / cloudflared)** — Local-only constraint forbids any external tunnel. Polling is the only path.
- **Multi-account-per-Google-account workspace selection** — Gmail OAuth always lands one inbox per consent flow. Users with personal + workspace Gmail just run the consent flow twice. No special handling needed.
- **`.eml` / `.mbox` drop-in import path** — Phase 7 (EML-07). Mentioned here only to clarify the boundary.
- **Per-sender template matchers + transaction creation** — Phase 7 (EML-05). Mentioned here only to clarify the boundary.
- **Annual cadence inference for backfill** — Phase 6 defaults backfill to 3 mo (EML-04); annual recurring detection (Phase 8) requires ≥13 mo of history. Users who want yearly subscriptions surfaced should set backfill to 12 mo at connection time.

</deferred>

---

*Phase: 6-Email Receipt Ingestion Infrastructure*
*Context gathered: 2026-05-16*
