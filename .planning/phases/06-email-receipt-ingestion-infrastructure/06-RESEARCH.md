# Phase 6: Email Receipt Ingestion Infrastructure - Research

**Researched:** 2026-05-16
**Domain:** OAuth2-driven email ingestion (Gmail API + Microsoft Graph) on a local Laravel 13 + Horizon stack, with raw `.eml` persistence, resumable provider cursors, rate-limit-safe queued backfill, macOS launchd supervision
**Confidence:** HIGH — most material verified against official Google / Microsoft docs and Packagist registry; one HIGH-risk CONTEXT.md decision (redirect URI scheme in D-114) is verifiably incorrect against current Google/Azure validation rules and the planner MUST resolve it before any wizard work begins

## Summary

Phase 6 is unblocked by the slopcheck audit: the four candidate libraries the SDK-strategy question (D-130) hinges on — `google/apiclient ^2.19`, `microsoft/microsoft-graph ^3.1`, `league/oauth2-google ^5.0`, `thenetworg/oauth2-azure ^2.2` — together with the natural mail-parser pick `zbateson/mail-mime-parser ^4.0` and the foundation `league/oauth2-client ^2.9` all pass slopcheck, all resolve cleanly under the project's PHP 8.5 constraint, and an empirical `composer require` followed by recursive grep against the resulting `vendor/` tree confirms **zero** transitive pulls of `ext-imap` or `webklex/laravel-imap`. PLT-05 is preserved by either SDK strategy. [VERIFIED: slopcheck install -e packagist + empirical vendor/ grep, 2026-05-16]

The load-bearing finding the planner must internalise before writing any task: **CONTEXT.md D-114's redirect URI `https://diederik.test/oauth/callback/{provider}` is rejected by both Google Cloud Console and Azure App Registration validation**. Google's official OAuth 2.0 native-app guidance allows only `http://127.0.0.1:port/path` or `http://[::1]:port/path` (with `localhost` "supported but with firewall caveats"); arbitrary HTTPS domains on non-public TLDs are explicitly forbidden, and Azure mirrors this restriction (HTTP scheme only allowed for the literal `localhost` host, never subdomains under `.localhost`, never `.test`). [CITED: developers.google.com/identity/protocols/oauth2/native-app, learn.microsoft.com/en-us/entra/identity-platform/reply-url] The planner has three real options: switch the project to bind on a fixed loopback port (e.g. `http://127.0.0.1:8000/oauth/callback/{provider}`), or accept the public-TLD rule and use `http://localhost:8000/oauth/callback/{provider}` (with documented firewall caveat), or run an ephemeral local loopback server only during the OAuth dance. This is a Plan-phase decision but the Wizard copy + every `redirect_uri` constant in the codebase depends on it.

The second load-bearing finding: **CONTEXT.md D-111's "refresh tokens issued to a test user in Testing mode don't expire" is wrong in current Google policy**. Google explicitly publishes that External + Testing publishing-status OAuth clients issue refresh tokens that **expire in 7 days** unless the scopes are a strict subset of `name/email/profile` — and `gmail.readonly` (D-113) is not a subset. [CITED: developers.google.com/identity/protocols/oauth2 — "A Google Cloud Platform project with an OAuth consent screen configured for an external user type and a publishing status of 'Testing' is issued a refresh token expiring in 7 days, unless the only OAuth scopes requested are a subset of name, email address, and user profile."] The bring-your-own architecture (D-111) survives but the bring-your-own *Testing-mode* posture is structurally unworkable: every 7 days the user would have to re-consent through the wizard. The fix is to instruct the user to flip their own GCP project from "Testing" to "In production" — that path is free, requires no Google verification when the only test user is themselves, and unlocks the standard "6 months of inactivity" expiry. The OAuth-client wizard (D-114) must include this step.

**Primary recommendation:** Adopt D-130 path (a) **with a tactical narrowing** — `google/apiclient ^2.19` for Gmail and `microsoft/microsoft-graph ^3.1` for Graph, both vendored at the Internal/Clients/ boundary behind `GmailApiClient` / `GraphApiClient` Public-shaped interfaces — but skip the official SDKs' OAuth helpers entirely and use `league/oauth2-google` + `thenetworg/oauth2-azure` (both on `league/oauth2-client`) for the OAuth dance because their refresh-token ergonomics, `Retry-After` handling, and `invalid_grant` exception model are dramatically cleaner than the vendor-locked OAuth flow embedded in the Google SDK. This stack passes the PLT-05 audit, gives a single OAuth abstraction surface across both providers, and isolates the SDK choice behind interfaces so a future migration to thin Guzzle is one-file-per-client.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| OAuth-client registration UX (per-provider wizard) | Frontend Server (Livewire SFC) | — | Form + paste-back fields are purely server-rendered; no client-side OAuth state |
| OAuth user-authorization dance (consent → callback → token exchange) | Backend (`OAuthCallbackController` + `league/oauth2-*`) | Browser (redirect only) | Token exchange MUST happen server-side; refresh_token never touches the browser |
| OAuth secrets persistence (client_id, client_secret, refresh_token) | Filesystem (chmod-600 JSON via `OAuthSecretsRepository`) | — | PLT-03 forbids DB; D-112 mandates atomic write-tmp + fsync + rename |
| Provider API calls (`messages.list`, `messages.get`, `history.list`, `/messages/delta`, `/messages/{id}/$value`) | Backend (`GmailApiClient` / `GraphApiClient` injected with `Guzzle\Client` + `OAuthSecretsRepository`) | — | Per-provider SDK wrapper; planner picks SDK strategy |
| Raw `.eml` blob persistence | Filesystem (`storage/app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/`) | — | D-116; date-partitioned by `internal_date` |
| `inbox_messages` thin index | Database (SQLite) | — | D-117; the Phase 6/7 handoff contract |
| Resumable scan cursors (`historyId` / `deltaLink`) | Database (`inbox_scan_state` table) | — | D-123 |
| Backfill orchestration (chunked, single-flight, rate-limit-safe) | Backend (`BackfillInboxJob` on Horizon) | — | Inherits Phase 5 Horizon supervisor |
| Incremental scan orchestration (hourly cron) | Backend (`IncrementalScanJob` on Horizon, dispatched by Laravel `Schedule`) | — | D-137 |
| Sender discovery (broad query, threshold-based promotion) | Backend (`DiscoveryScanJob` daily) + Frontend Server (`/inboxes` discovered-senders panel) | — | D-120 / D-121 |
| `/inboxes` page rendering (table, modal, hero, progress strip) | Frontend Server (Livewire 4 SFC + Flux components + Volt) | Browser (Alpine for purely-client toast/show-hide) | UI-05 calm aesthetic, matches Phase 5 `/chains/review` shape |
| Dashboard "Email scan health" tile | Frontend Server (Blade partial driven by `ThisPeriodAtAGlanceQuery::emailScanHealth()`) | — | D-125 mirrors Phase 5 `nextIcsSettlement()` shape |
| Top-nav "Inboxes" badge | Frontend Server (View Factory composer) | — | D-126 mirrors Phase 5 issue #12 fix exactly |
| Background-worker process supervision | OS (macOS `launchd` LaunchAgents) | — | PLT-04 / D-131; survives reboot |

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| EML-01 | User authorizes Gmail via OAuth2 (Gmail API) | OAuth-client wizard (D-114) + `league/oauth2-google` provider + `OAuthCallbackController` → `OAuthSecretsRepository::saveInboxRefreshToken()`. Verified: scope `gmail.readonly` + `https://www.googleapis.com/auth/userinfo.email` for the email address read-back. Loopback redirect URI per Google native-app spec (see "OAuth Flow Mechanics" + "Common Pitfalls"). |
| EML-02 | User authorizes Microsoft 365 / Outlook via OAuth2 (Graph) | Same flow, `thenetworg/oauth2-azure` provider. Scope `Mail.Read offline_access User.Read`. `User.Read` is needed to read `userPrincipalName` (the inbox's email address). [CITED: learn.microsoft.com/en-us/graph/permissions-reference] |
| EML-03 | Multiple inboxes per provider; scanner runs against all | `inboxes` table is the registry; `BackfillInboxJob` + `IncrementalScanJob` both dispatch per-`inbox_id`. `ShouldBeUniqueUntilProcessing` keyed on `inbox_id` (D-122) lets different inboxes run in parallel. |
| EML-04 | Configurable historical backfill window (1–12 months, default 3); queued background job | D-128 backfill-window modal + D-122 chunked job. Hard cap 12 months enforced both at the modal slider and inside `BackfillInboxJob::handle()` as a defensive `min(12, $request->months)` (cross-user 404 invariant style). |
| EML-06 | Inbox scan state persisted per-inbox per-provider for incremental resume | `inbox_scan_state` table (D-123) carries `last_history_id` (Gmail), `last_delta_link` (Graph), `last_scan_at`, `status` enum, `retry_attempts`. `ScanCursor` value object normalises the two provider cursors behind one read/write surface. |
| EML-08 | Rate-limit failures retry with exponential backoff; persistent failures surface in health view | `BackfillInboxJob` + `IncrementalScanJob` honour `Retry-After` header on 429 (Graph) / 403-`rateLimitExceeded` (Gmail), transition `inbox_scan_state.status` to `rate_limited` with `retry_attempts++` and a configurable backoff schedule `[60, 300, 900, 3600]` (matches Phase 5 D-103 shape). Status visible on `/inboxes` row badge + dashboard tile. |
| PLT-03 | OAuth secrets in chmod-600 config file outside DB | D-112 single-file `email-oauth.json` + atomic rotation via `OAuthSecretsRepository` (write tmp + fsync + rename). |
| PLT-04 | Background workers via macOS launchd, health visible in UI | Three launchd plists (D-131) + `php artisan diederik:install --launchd` + `inboxes.last_scan_at`-fed dashboard tile + `/inboxes` health badges. |

## User Constraints (from CONTEXT.md)

### Locked Decisions

D-111 through D-132 (CONTEXT.md "Implementation Decisions") are locked. Verbatim summary of the load-bearing constraints the planner MUST honor:

- D-111: **Bring-your-own OAuth client per install** — no shipped diederik `client_id`; user registers their own GCP project + Azure App. *(Research note: D-111's "refresh tokens issued to a test user in Testing mode don't expire" claim is contradicted by current Google policy — see Pitfall 1 below. The bring-your-own architecture survives; the Testing-mode posture does not. Wizard must instruct user to flip to "In production".)*
- D-112: **Single chmod-600 JSON at `storage/app/secrets/email-oauth.json`** holds both per-provider `client_id`/`client_secret` and per-inbox `refresh_token`. Atomic rotation: write-tmp + fsync + rename. `OAuthSecretsRepository` is the only DI surface that touches this file.
- D-113: **Read-only scopes** — Gmail `gmail.readonly`; Graph `Mail.Read` + `offline_access`. Never `gmail.modify` / `Mail.ReadWrite`.
- D-114: **First-launch per-provider modal wizard** for OAuth-client registration. *(Research note: the redirect URI `https://diederik.test/oauth/callback/{provider}` is invalid for both Google and Azure — see Pitfall 2 below. Planner must pick a loopback alternative before wizard copy is locked.)*
- D-115: **Revoked-grant recovery via manual reconnect** — `inbox_scan_state.status='needs_reauth'` on `invalid_grant`; `/inboxes` red badge + `Reconnect` button; existing `.eml` + cursor preserved.
- D-116: **Raw `.eml` blobs on disk** at `storage/app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/{provider_message_id}.eml`. Kept forever.
- D-117: **`inbox_messages` table is the Phase 6/7 handoff contract**. Status enum: `fetched | parsed | skipped | unmatched`. Phase 6 only writes `fetched`.
- D-118: **Sender + subject extracted at fetch time** from RFC 822 headers.
- D-119: **Server-side `from:sender` filter** for primary scan. *(Research note: Microsoft Graph delta-query `$filter` only supports `receivedDateTime` — `from/emailAddress/address` filtering must run via a separate non-delta `/messages?$filter=...` walk during backfill, with delta used only for incremental scanning AFTER the first cursor is established. See "Architecture Patterns" §"Two-Phase Graph Scan".)*
- D-120: **DB-backed mutable sender lists** — `known_senders` (with `source` enum) + `discovered_senders` (with `state` enum). Seed system rows for PayPal / ICS / Google Play.
- D-121: **`DiscoveryScanJob` runs daily** with a broad keyword filter; promotion threshold default 2 occurrences within 90 days (D-135 tunable).
- D-122: **Chunked `BackfillInboxJob`** with per-inbox single-flight (`ShouldBeUniqueUntilProcessing` keyed on `inbox_id`). 100 messages per page, write `.eml` first then DB row, cleanup-on-rollback. UI progress via `wire:poll.2s`.
- D-123: **`inbox_scan_state` table** with `last_history_id` (Gmail) + `last_delta_link` (Graph) + `status` enum + `retry_attempts` for exponential backoff (EML-08).
- D-124..D-128: **`/inboxes` page UX shape** — connected table + add-inbox cards + discovered-senders panel + empty-state hero + post-callback backfill-window modal.
- D-129: **`Modules/EmailScan/` bounded module** with Public/ surface (`InboxQuery`, `KnownSenderQuery`, `OAuthSecretsRepository`, `InboxMessageQuery`).
- D-130: **External Composer deps strategy** — planner picks path (a) official SDKs vs (b) thin Guzzle wrappers. *(Research recommendation: hybrid — SDKs at the API client boundary + `league/oauth2-*` at the OAuth boundary. See "Standard Stack".)*
- D-131: **launchd plists ship in Phase 6** — horizon + scheduler + optional redis. `php artisan diederik:install --launchd`.
- D-132: **Phase 6/7 boundary** — `BoundaryArchTest::noTransactionWritesFromEmailScan` invariant; no transaction-table writes from `Modules/EmailScan/`.

### Claude's Discretion

- D-133: Exact SDK version pins, transitive-dep audit findings, retry/backoff knobs (start + ceiling) per EML-08.
- D-134: OAuth-client modal "edit existing" mode shape; reset-vs-purge policy interaction with already-connected inboxes.
- D-135: Exact `discovered_senders` promotion threshold (default 2 in 90 days).
- D-136: UI-SPEC pass locks exact Flux components for `/inboxes`.
- D-137: Default scan cadence — suggested hourly incremental + daily discovery + on-demand backfill. Planner verifies against Gmail/Graph quotas.
- D-138: Whether OAuth `client_secret` + `refresh_token` get an additional Laravel `Crypt::encrypt()` layer on top of chmod-600.
- D-139: Sender-email normalisation at write time vs query time.
- D-140: Wave 0 synthesised fixture corpus + `FakeGmailApiClient` / `FakeGraphApiClient`.

### Deferred Ideas (OUT OF SCOPE)

- Verified-app distribution path (single shipped client_id; future v2).
- Gmail Watch / Graph subscription push notifications (require public webhook URL — local-only forbids).
- iCloud Mail (no API; Phase 7 `.eml`/`.mbox` drop-in covers it).
- OAuth password / app-password fallback.
- Encryption-at-rest on the chmod-600 JSON (D-138 may add it as defense-in-depth — defer).
- macOS Keychain integration (v2 per REQUIREMENTS.md).
- Multi-folder scan support (Phase 6 ships `INBOX`-only; schema is multi-folder-ready).
- `+plus` alias normalisation at write-time (D-139 likely yes; defer to plan-phase).
- Per-inbox throttling overrides.
- Drag-to-reorder inbox priority.
- OAuth-client wizard "edit existing" mode (D-134).
- Manual "Scan now" with ad-hoc filters (Phase 6 ships re-run-now only).
- Tunneling-based push delivery (ngrok / cloudflared).
- `.eml` / `.mbox` drop-in import path (Phase 7 EML-07).
- Per-sender template matchers + transaction creation (Phase 7 EML-05).

## Project Constraints (from CLAUDE.md + STATE.md memory)

- **PHP 8.5 + Laravel 13** (project pinned; deviates from research/STACK.md's 8.3/12.x recommendation — that's already locked).
- **Livewire 4 + Volt + Flux** for the UI surface.
- **SQLite single-writer in WAL mode** — `inbox_scan_state` writes contend with `transactions` writes; document the WAL implication for parallel backfills (see "Pitfalls" §"SQLite single-writer contention").
- **Horizon + Predis + Redis** already running from Phase 5; Phase 6 dispatches new jobs onto the existing supervisor — NO new queue plumbing.
- **Laravel DI-only** — no `auth()`, no `view()`, no `config()`, no facade calls. Constructor injection only. Eloquent models direct OK. Raw `DatabaseManager` injected for whereBetween/whereIn/orderBy.
- **One permitted facade exception** in module code: `Cache::driver('redis')` inside `*Job::uniqueVia()` (Laravel's queue infrastructure calls `uniqueVia()` before constructor DI completes). BoundaryArchTest carve-out per `tests/Contracts/BoundaryArchTest.php`. New Phase 6 jobs that use `ShouldBeUniqueUntilProcessing` (`BackfillInboxJob`, `IncrementalScanJob`, `DiscoveryScanJob`) get carve-out entries.
- **Codebase stays GSD-agnostic** — no `.planning/` / `PLAN.md` / `RESEARCH.md` references in source, PHPDocs, or comments. Rationale described in plain technical language.
- **Docs describe CURRENT state, never history** — no "we changed this because" comments; PHPDocs reflect what code does now.
- **Larastan level 10 strict** + Pint + Pest gate every PR. Specific implications: `staticMethod.dynamicCall` strict rule forbids `Model::query()->exists()` — use raw `$db->connection()->table()->count() > 0` (Phase 4/5 precedent).
- **Multi-user-ready** — every new domain table carries nullable `user_id` + `BelongsToUser` trait. All `/inboxes` actions assert `$inbox->user_id === $currentUser->id` defensively + via `where('user_id', ...)`.
- **Provider APIs only** — no IMAP anywhere. `composer.json conflict` block recommendation: explicitly conflict `webklex/laravel-imap` + `webklex/php-imap` + `ddeboer/imap` to make a regression hard-fail at install. (PLT-05 belt-and-braces.)
- **iCloud Mail explicitly OUT.**

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `google/apiclient` | ^2.19 (2.19.3, May 4 2026) | Gmail API REST client | Official Google SDK; auto-generated against `apiclient-services ~0.350`; battle-tested wrapper over `messages.list`, `messages.get`, `history.list`. Composer audit clean (no `ext-imap` / `webklex/*`). [VERIFIED: packagist.org/packages/google/apiclient + slopcheck + recursive vendor/ grep, 2026-05-16] |
| `microsoft/microsoft-graph` | ^3.1 (3.1.0, May 7 2026) | Microsoft Graph REST client (Mail subset) | Official Microsoft Graph SDK v2 (Kiota-generated). Pulls `microsoft/microsoft-graph-core` ^2.4 + `microsoft/kiota-*` ^1.5 + Guzzle. Composer audit clean. [VERIFIED: packagist.org/packages/microsoft/microsoft-graph + slopcheck + recursive vendor/ grep, 2026-05-16] |
| `league/oauth2-client` | ^2.9 (2.9.0, Nov 25 2025) | OAuth 2.0 client foundation | 123.5M installs; the standard PHP OAuth2 base; PHP 7.1–8.5. Composer audit clean. [VERIFIED: packagist.org/packages/league/oauth2-client + slopcheck, 2026-05-16] |
| `league/oauth2-google` | ^5.0 (5.0.0, Mar 23 2026) | Google OAuth2 provider | PHP League official; 22.2M installs; pre-configured Google endpoints + `accessType=offline` for refresh tokens. PHP 8.0–8.5. [VERIFIED: packagist.org/packages/league/oauth2-google + slopcheck, 2026-05-16] |
| `thenetworg/oauth2-azure` | ^2.2 (2.2.5, Feb 26 2026) | Microsoft Identity / Azure OAuth2 provider | 10.2M installs; supports v2.0 endpoint + `common` tenant for personal + work/school accounts; explicit `offline_access` support. PHP 7.1+. Composer audit clean. [VERIFIED: packagist.org/packages/thenetworg/oauth2-azure + slopcheck, 2026-05-16] |
| `zbateson/mail-mime-parser` | ^4.0 (4.0.1, Mar 11 2026) | RFC 822 header + MIME parser | 51M installs; pure-PHP; no `ext-imap` requirement; emits typed `Message`/`Header` objects from a raw `.eml` byte stream. Used to populate `inbox_messages.sender_email` + `sender_name` + `subject` + `internal_date` from headers. [VERIFIED: packagist.org/packages/zbateson/mail-mime-parser + slopcheck, 2026-05-16] |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `predis/predis` | ^3.4 (already installed Phase 5) | Redis client | Inherited from Phase 5 — `Cache::driver('redis')` for `ShouldBeUniqueUntilProcessing::uniqueVia()`. No new install. |
| `laravel/horizon` | ^5.46 (already installed Phase 5) | Queue supervisor | Inherited — new Phase 6 jobs register against existing Horizon supervisor. |
| `spatie/laravel-data` | ^4 (already installed) | Typed Public DTOs | `InboxHealthDto`, `KnownSenderDto`, `InboxMessageDto`, `InboxCredentials`, `EmailScanHealthTile`. |
| `livewire/livewire` | ^4 (already installed) | `/inboxes` SFC + wire:poll | Inherited. |
| `livewire/flux` | ^2 (already installed) | Modal, table, slider, badge, drawer | Inherited. UI-SPEC plan-phase (D-136) locks the exact components. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `google/apiclient` (full SDK, ~80 MB vendored) | Thin Guzzle wrapper around `https://gmail.googleapis.com/gmail/v1/users/me/...` | Saves ~80 MB, ~150 transitive packages; loses pre-baked retry/backoff, batch support, and request signing helpers. **Recommendation: stick with SDK** — the maintenance cost of hand-rolling the Gmail HTTP layer + tracking Google's quota-unit changes outweighs vendor size on a local-only single-user app. |
| `microsoft/microsoft-graph` (full SDK, Kiota-generated) | Thin Guzzle wrapper around `https://graph.microsoft.com/v1.0/me/messages/...` | Same as above. The Kiota-generated SDK is large but the Mail subset (`/me/messages`, `/me/mailFolders/inbox/messages/delta`, `/me/messages/{id}/$value`) is the only surface used; isolate behind `GraphApiClient` so a future migration is mechanical. |
| `league/oauth2-google` + `thenetworg/oauth2-azure` | Use the official SDKs' own OAuth helpers | Official SDKs bundle their own OAuth flow; using them keeps the surface inside one vendor per provider, but their refresh-token + `invalid_grant` exception ergonomics are inconsistent. `league/oauth2-client` gives ONE OAuth abstraction across both providers — same `getAccessToken('refresh_token')` call, same `IdentityProviderException::class` on revoked grants. **Recommendation: hybrid** — official SDKs for API calls + `league/oauth2-*` for the OAuth dance. |
| `zbateson/mail-mime-parser` | PHP-native `imap_rfc822_parse_headers()` | `imap_rfc822_parse_headers` lives in `ext-imap` — explicitly banned (PLT-05). Pure-PHP `zbateson/mail-mime-parser` is the only viable RFC 822 parser in this stack. |
| `zbateson/mail-mime-parser` | Hand-rolled header regex | RFC 822 headers can fold across lines, contain Q-encoded UTF-8 (`=?UTF-8?Q?...?=`), and the `From:` header can have quoted display names with commas. Hand-rolling correctly is a known time-sink. 51M installs of zbateson speaks for itself. |

### Installation

```bash
composer require \
    google/apiclient:^2.19 \
    microsoft/microsoft-graph:^3.1 \
    league/oauth2-client:^2.9 \
    league/oauth2-google:^5.0 \
    thenetworg/oauth2-azure:^2.2 \
    zbateson/mail-mime-parser:^4.0
```

After install, the planner MUST run a recursive grep to confirm PLT-05 is still met:

```bash
grep -r "ext-imap" vendor/ | grep -v node_modules
find vendor -type d -name "webklex"     # must be empty
grep -rn '"ext-imap"' composer.lock     # must be zero
```

I performed all three checks empirically during research (composer require → grep → revert): **zero hits across all six new packages**. [VERIFIED: 2026-05-16]

**Version verification (empirical, 2026-05-16):**
- `google/apiclient`: 2.19.3 published 2026-05-04 → constraint `^2.19` resolves to 2.19.3.
- `microsoft/microsoft-graph`: 3.1.0 published 2026-05-07 → constraint `^3.1` resolves to 3.1.0.
- `league/oauth2-client`: 2.9.0 published 2025-11-25 → constraint `^2.9`.
- `league/oauth2-google`: 5.0.0 published 2026-03-23 → constraint `^5.0`.
- `thenetworg/oauth2-azure`: 2.2.5 published 2026-02-26 → constraint `^2.2`.
- `zbateson/mail-mime-parser`: 4.0.1 published 2026-03-11 → constraint `^4.0`.

## Package Legitimacy Audit

Empirical slopcheck run executed 2026-05-16 against the Packagist ecosystem:

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `google/apiclient` | Packagist | 13 yrs | ~120M total | github.com/googleapis/google-api-php-client | [OK] | Approved |
| `microsoft/microsoft-graph` | Packagist | 8 yrs | ~50M total | github.com/microsoftgraph/msgraph-sdk-php | [OK] | Approved |
| `league/oauth2-client` | Packagist | 11 yrs | 123.5M total | github.com/thephpleague/oauth2-client | [OK] | Approved |
| `league/oauth2-google` | Packagist | 9 yrs | 22.2M total | github.com/thephpleague/oauth2-google | [OK] | Approved |
| `thenetworg/oauth2-azure` | Packagist | 9 yrs | 10.2M total | github.com/TheNetworg/oauth2-azure | [OK] | Approved |
| `zbateson/mail-mime-parser` | Packagist | 9 yrs | 51M total | github.com/zbateson/mail-mime-parser | [OK] | Approved |

**Packages removed due to slopcheck [SLOP] verdict:** none.
**Packages flagged as suspicious [SUS]:** none.

**Transitive `ext-imap` / `webklex` audit:** I ran `composer require` against all six packages on this branch, did a recursive grep over the resulting `vendor/` tree for `ext-imap` and `webklex`, then reverted. Result: **zero hits**. PLT-05 invariant verified at the empirical level. [VERIFIED: empirical install + grep + revert, 2026-05-16]

**Belt-and-braces recommendation:** add an explicit `conflict` block to root `composer.json`:

```json
"conflict": {
    "webklex/laravel-imap": "*",
    "webklex/php-imap": "*",
    "ddeboer/imap": "*"
}
```

This makes a future accidental regression hard-fail at `composer install` time. Pair with the existing PLT-05 ext-imap lint (the CI grep gate established Phase 1) — two layers of defence.

## Architecture Patterns

### System Architecture Diagram

```
                            ┌─────────────────────────────────────────────────────┐
                            │                /inboxes  (Livewire SFC)             │
                            │  ┌─────────────┐  ┌───────────────┐  ┌───────────┐  │
                            │  │ Empty hero  │  │ Connected     │  │ Discovered│  │
                            │  │ (no inboxes)│  │ inboxes table │  │ senders   │  │
                            │  │ + 2 buttons │  │ + status     │  │ panel     │  │
                            │  └──────┬──────┘  │ badges        │  │           │  │
                            │         │         │ + Scan-Now    │  └────┬──────┘  │
                            │         │         │ + Reconnect   │       │         │
                            │         │         │ + Window-edit │       │         │
                            │         │         └───────┬───────┘       │         │
                            └─────────┼─────────────────┼───────────────┼─────────┘
                                      │                 │               │
              "Connect Gmail/MS365"   │                 │               │ "Add" / "Dismiss"
                                      │                 │               │
                                      ▼                 ▼               ▼
                           ┌──────────────────┐ ┌─────────────────┐ ┌─────────────────────┐
                           │ OAuthClient      │ │ POST            │ │ POST                │
                           │ WizardModal      │ │ /inboxes/{id}/  │ │ /senders/{id}/      │
                           │ (D-114)          │ │ {scan-now|reconnect│ │ {promote|dismiss}│
                           │                  │ │ |window}        │ │                     │
                           │ OAuthSecrets-    │ └────────┬────────┘ └──────────┬──────────┘
                           │ Repository:save  │          │                     │
                           │ providerClient() │          ▼                     ▼
                           └─────────┬────────┘    ┌─────────────────┐  ┌──────────────────┐
                                     │             │ Dispatch        │  │ Mutate           │
                                     ▼             │ BackfillInboxJob│  │ known_senders /  │
                            ┌────────────────┐     │ /Incremental-   │  │ discovered_      │
                            │ Redirect to    │     │ ScanJob         │  │ senders          │
                            │ provider's     │     └────────┬────────┘  └──────────────────┘
                            │ consent screen │              │
                            └────────┬───────┘              │
                                     │                      │
                                     ▼                      │
                            ┌────────────────────────┐      │
                            │ http://127.0.0.1:PORT/ │      │
                            │ oauth/callback/{prov}  │      │
                            │                        │      │
                            │ OAuthCallbackController│      │
                            │  - exchange code→token │      │
                            │  - read user email     │      │
                            │  - insert inboxes row  │      │
                            │  - save refresh_token  │      │
                            │  - open backfill modal │      │
                            └────────────┬───────────┘      │
                                         │                  │
                                         ▼                  │
                                ┌────────────────┐          │
                                │ Backfill modal │          │
                                │ (1-12 month    │          │
                                │ slider) → POST │          │
                                │ dispatch       │──────────┘
                                │ BackfillInbox- │
                                │ Job(window)    │
                                └────────────────┘

                            ───── Async: Horizon-supervised queue ─────

  ┌─────────────────────┐    ┌─────────────────────┐    ┌──────────────────┐
  │ BackfillInboxJob    │    │ IncrementalScanJob  │    │ DiscoveryScanJob │
  │ (one-shot per       │    │ (hourly via         │    │ (daily via       │
  │  inbox-add or       │    │  Schedule)          │    │  Schedule)       │
  │  window-extend)     │    │                     │    │                  │
  │                     │    │  reads last_history_│    │  broad keyword   │
  │  chunked, 100/page  │    │  id / last_delta_   │    │  query, no .eml  │
  │  ShouldBeUnique-    │    │  link from inbox_   │    │  blobs           │
  │  UntilProcessing    │    │  scan_state         │    │                  │
  │  keyed inbox_id     │    │                     │    │                  │
  └──────────┬──────────┘    └──────────┬──────────┘    └────────┬─────────┘
             │                          │                        │
             ▼                          ▼                        ▼
  ┌────────────────────────────────────────────────────────────────────────┐
  │  Strategy dispatch on inbox.provider                                   │
  │                                                                         │
  │  Gmail              ┌─────────────────┐    Microsoft Graph             │
  │  ──────────         │   ScanCursor    │    ─────────────────           │
  │  GmailApiClient ◄───┤  value object   ├───► GraphApiClient             │
  │  · messages.list    │  (normalises    │    · GET /me/messages?$filter  │
  │    q=from:(...)     │   historyId vs  │      (backfill primary)        │
  │  · messages.get     │   delta-link    │    · GET /me/messages/$value   │
  │    format=raw       │   semantics)    │      → raw MIME .eml           │
  │    → base64url      └─────────────────┘    · GET /me/mailFolders/      │
  │  · history.list                              inbox/messages/delta       │
  │    startHistoryId=                           (incremental)              │
  └────────────────────────────────────────────────────────────────────────┘
             │                                                  │
             │   OAuth bearer token (refreshed on 401 via       │
             │   league/oauth2-* refresh_token grant)           │
             │                                                  │
             ▼                                                  ▼
  ┌──────────────────────────────────────────────────────────────────────┐
  │  ┌────────────────┐  ┌─────────────────┐  ┌─────────────────────┐    │
  │  │ Decode + parse │  │ Write .eml      │  │ Insert inbox_       │    │
  │  │ MIME headers   │  │ blob to disk    │  │ messages row        │    │
  │  │ (zbateson)     │  │ inbox/{user_id}/│  │ status='fetched'    │    │
  │  │ → From/Subject/│  │ {inbox_id}/     │  │                     │    │
  │  │   Date         │  │ {YYYY}/{MM}/    │  │ Update inbox_       │    │
  │  │                │  │ {provider_id}   │  │ scan_state cursor + │    │
  │  │                │  │ .eml            │  │ last_scan_at        │    │
  │  └────────────────┘  └─────────────────┘  └─────────────────────┘    │
  │                                                                       │
  │  Atomicity: .eml write → DB tx (cleanup-on-rollback erases .eml)     │
  └──────────────────────────────────────────────────────────────────────┘

                            ───── Phase 7 reads ─────
                                       │
                                       ▼
                       inbox_messages WHERE status='fetched'
                       → matcher pipeline (NOT in this phase)
```

### Recommended Project Structure

```
Modules/EmailScan/
├── composer.json                          # diederik/email-scan, autoload Modules\EmailScan\
├── Providers/
│   └── EmailScanServiceProvider.php       # bindings + livewire registration + view composer
├── Public/
│   ├── Services/
│   │   ├── InboxQuery.php                 # forCurrentUser(): array<InboxHealthDto>
│   │   ├── KnownSenderQuery.php           # all(): array<KnownSenderDto>
│   │   ├── InboxMessageQuery.php          # forStatus(string): iterable<InboxMessageDto>
│   │   └── OAuthSecretsRepository.php     # ONLY surface that touches email-oauth.json
│   ├── Dto/
│   │   ├── InboxHealthDto.php
│   │   ├── KnownSenderDto.php
│   │   ├── InboxMessageDto.php
│   │   ├── InboxCredentials.php
│   │   ├── ScanCursor.php                 # normalises Gmail historyId + Graph deltaLink
│   │   └── EmailScanHealthTile.php        # dashboard tile DTO
│   └── Contracts/                         # if any future cross-module hook is needed
├── Internal/
│   ├── Clients/
│   │   ├── GmailApiClient.php             # wraps google/apiclient Gmail service
│   │   ├── GraphApiClient.php             # wraps microsoft/microsoft-graph
│   │   ├── FakeGmailApiClient.php         # Wave 0 test fake (autoload-dev)
│   │   └── FakeGraphApiClient.php
│   ├── OAuth/
│   │   ├── GoogleOAuthProvider.php        # thin wrapper over league/oauth2-google
│   │   └── MicrosoftOAuthProvider.php     # thin wrapper over thenetworg/oauth2-azure
│   ├── MimeHeaderParser.php               # zbateson facade for the 3 header values we use
│   ├── InboxScanStateMachine.php          # ONLY mutator of inbox_scan_state.status
│   ├── Jobs/
│   │   ├── BackfillInboxJob.php           # ShouldBeUniqueUntilProcessing(inbox_id)
│   │   ├── IncrementalScanJob.php         # hourly via Schedule
│   │   └── DiscoveryScanJob.php           # daily via Schedule
│   ├── EmlBlobStore.php                   # write/read storage/app/inbox/...
│   └── Http/
│       ├── Controllers/
│       │   └── OAuthCallbackController.php  # /oauth/callback/{provider}
│       └── Livewire/
│           ├── InboxesPage.php            # /inboxes SFC
│           ├── OAuthClientWizardModal.php # D-114 first-launch wizard
│           └── BackfillWindowModal.php    # D-128 1–12 mo picker
├── Database/Migrations/
│   ├── *_create_inboxes_table.php
│   ├── *_create_inbox_scan_state_table.php
│   ├── *_create_inbox_messages_table.php
│   ├── *_create_known_senders_table.php   # + system seeds
│   └── *_create_discovered_senders_table.php
├── Models/
│   ├── Inbox.php
│   ├── InboxScanState.php
│   ├── InboxMessage.php
│   ├── KnownSender.php
│   └── DiscoveredSender.php
├── Routes/
│   └── web.php                            # /inboxes + /oauth/callback/* + actions
├── Resources/
│   └── views/
│       └── livewire/
│           ├── inboxes-page.blade.php
│           ├── oauth-client-wizard-modal.blade.php
│           └── backfill-window-modal.blade.php
└── tests/
    ├── TestCase.php
    ├── Pest.php                           # inert (top-level Pest.php wires it)
    ├── fixtures/
    │   ├── eml/                           # synthesised PayPal/ICS/Google Play .eml
    │   └── api-responses/                 # synthesised Gmail/Graph JSON
    ├── Unit/
    ├── Feature/
    └── Contracts/
```

Three coordinated changes register the new test PSR-4 suite (per Phase 4 D-80b precedent):
1. `composer.json autoload-dev` adds `"Modules\\EmailScan\\Tests\\": "Modules/EmailScan/tests/"`.
2. `phpunit.xml` adds new testsuite entries.
3. `tests/Pest.php` adds `'Modules/EmailScan' => Modules\EmailScan\Tests\TestCase::class` to the foreach map.

### Pattern 1: Hybrid SDK + OAuth-Client Separation

**What:** API calls go through the official SDKs (`google/apiclient`, `microsoft/microsoft-graph`); OAuth dance (consent → callback → token refresh) goes through `league/oauth2-*` providers. Both are wired up inside the same `GmailApiClient` / `GraphApiClient` constructor.

**When to use:** Always, for both providers. Keeps the OAuth surface uniform across Gmail + Graph while letting the API surface use the well-typed vendor SDKs.

**Example shape:**

```php
// Source: synthesised from league/oauth2-google + google/apiclient docs
namespace Modules\EmailScan\Internal\Clients;

final class GmailApiClient
{
    public function __construct(
        private readonly \Google\Client $googleClient,
        private readonly \League\OAuth2\Client\Provider\Google $oauthProvider,
        private readonly OAuthSecretsRepository $secrets,
        private readonly Clock $clock,
    ) {}

    public function listSenderMessages(Inbox $inbox, array $senderPatterns, ?string $pageToken = null): array
    {
        $this->ensureFreshToken($inbox);
        $gmail = new \Google\Service\Gmail($this->googleClient);
        $q = 'from:(' . implode(' OR ', $senderPatterns) . ')';
        return $gmail->users_messages->listUsersMessages('me', [
            'q' => $q,
            'pageToken' => $pageToken,
            'maxResults' => 100,
        ])->toSimpleObject();
    }

    public function getRawMessage(Inbox $inbox, string $providerMessageId): string
    {
        $this->ensureFreshToken($inbox);
        $gmail = new \Google\Service\Gmail($this->googleClient);
        $msg = $gmail->users_messages->get('me', $providerMessageId, ['format' => 'raw']);
        // Gmail returns raw RFC 822 base64url-encoded; decode before write.
        return self::base64UrlDecode($msg->getRaw());
    }

    private function ensureFreshToken(Inbox $inbox): void
    {
        $creds = $this->secrets->loadInbox($inbox->id);
        // ... if expires_at within 60s of now, refresh via $this->oauthProvider->getAccessToken('refresh_token', [...])
        // ... on IdentityProviderException with error=invalid_grant → InboxScanStateMachine::markNeedsReauth
        $this->googleClient->setAccessToken($creds->access_token);
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
```

### Pattern 2: ScanCursor Value Object

**What:** A single immutable value object that wraps either `{provider: 'gmail', historyId: '12345'}` or `{provider: 'microsoft', deltaLink: 'https://graph.microsoft.com/...'}`. The job orchestration code never branches on provider — it asks the cursor for the "next page" call and writes whatever cursor the provider returned.

**When to use:** Every read/write of `inbox_scan_state.last_history_id` / `inbox_scan_state.last_delta_link`. Phase 7's matchers never see provider-specific cursor semantics — they only see "fetched" messages.

**Why it matters:** Both providers have different expiry behaviours (Gmail's `historyId` "typically valid for at least a week, in rare circumstances only a few hours"; Microsoft Graph delta-link "no fixed lifetime but eventually returns 410 Gone"). The cursor object centralises the expired-cursor recovery logic.

```php
namespace Modules\EmailScan\Public\Dto;

final readonly class ScanCursor
{
    private function __construct(
        public string $provider,    // 'gmail' | 'microsoft'
        public ?string $historyId,  // Gmail only
        public ?string $deltaLink,  // Graph only
    ) {}

    public static function gmail(string $historyId): self
    {
        return new self('gmail', $historyId, null);
    }

    public static function microsoft(string $deltaLink): self
    {
        return new self('microsoft', null, $deltaLink);
    }

    public static function emptyFor(string $provider): self
    {
        return new self($provider, null, null);
    }

    public function isExpired(): bool { /* set by ApiClient on 404 (Gmail) / 410 (Graph) */ }
}
```

### Pattern 3: Atomic .eml Write + DB Insert (D-122 "cleanup-on-rollback")

**What:** For each fetched message: (1) write `.eml` to disk; (2) open DB transaction; (3) insert `inbox_messages` row + update `inbox_scan_state` cursor; (4) commit. If step 3 or 4 throws, the catch unlinks the .eml.

**When to use:** Every fetch in `BackfillInboxJob` and `IncrementalScanJob`.

```php
$emlPath = $emlBlobStore->pathFor($inbox->user_id, $inbox->id, $internalDate, $providerMessageId);
$emlBlobStore->put($emlPath, $rawMime);
try {
    $db->connection()->transaction(function () use ($db, /* ... */) {
        $db->connection()->table('inbox_messages')->insertOrIgnore([/* ... */]);
        // bump cursor inside same tx
        $db->connection()->table('inbox_scan_state')->where('inbox_id', $inbox->id)->update([/* ... */]);
    });
} catch (\Throwable $e) {
    $emlBlobStore->delete($emlPath);
    throw $e;
}
```

UNIQUE constraint `(inbox_id, provider_message_id)` on `inbox_messages` makes the `insertOrIgnore` an idempotent no-op on retry — re-fetching the same `provider_message_id` after a partial failure converges.

### Pattern 4: Two-Phase Graph Scan (D-119 correction)

**What:** Microsoft Graph `/me/mailFolders/inbox/messages/delta` does NOT support `$filter` for arbitrary properties — only `receivedDateTime+ge+{value}`. The naïve "delta + from-filter" approach D-119 implies for Graph cannot be implemented as one call.

**The pattern:**

- **Backfill phase (initial):** Use the non-delta endpoint `/me/messages?$filter=(from/emailAddress/address eq 'paypal.com' or from/emailAddress/address eq '...') and receivedDateTime ge {window_start}&$orderby=receivedDateTime+desc&$top=100`. Walk `@odata.nextLink` pagination until done. At the end, perform ONE `/me/mailFolders/inbox/messages/delta?$filter=receivedDateTime+ge+{now}` request to obtain a baseline `deltaLink`. Store it.
- **Incremental phase (hourly):** Use the stored `deltaLink`. Process all returned messages, then for each one, post-filter client-side on `from/emailAddress/address` against `known_senders`. (Server-side `from:` filter is not available on delta.) Update the cursor to the new `deltaLink`.

**Why this matters:** Without this two-phase split, the incremental Graph scan would have to download every inbox message and discard ~95% client-side — burning bandwidth and Graph quota.

[CITED: learn.microsoft.com/en-us/graph/delta-query-messages "The only supported `$filter` expressions are `$filter=receivedDateTime+ge+{value}` or `$filter=receivedDateTime+gt+{value}`."]

### Pattern 5: View Factory Top-Nav Badge (issue #12 fix carry-forward)

**What:** D-126 mandates the top-nav "Inboxes" badge be fed by a View Factory composer — never by the `view()` global helper.

**Phase 5 precedent** (`Modules/Chains/Providers/ChainsServiceProvider.php::registerTopNavBadgeComposer`) shows the exact shape:

```php
$factory = $app->make(ViewFactoryContract::class);
$factory->composer('core::livewire.top-nav', function (View $compose) use ($app): void {
    $currentUser = $app->make(CurrentUser::class);
    if (! $currentUser->isAuthenticated()) {
        $compose->with('inboxesBadgeCount', 0);
        return;
    }
    $query = $app->make(InboxQuery::class);
    $compose->with('inboxesBadgeCount', $query->reviewBadgeCount($currentUser->user()));
});
```

`reviewBadgeCount(User): int` returns `count(discovered_senders WHERE state='candidate' AND user_id=?) + count(inbox_scan_state WHERE status='needs_reauth' AND user_id=?)`.

### Pattern 6: ShouldBeUniqueUntilProcessing keyed on inbox_id (Phase 5 D-122 mirror)

Phase 5 `ResolveChainLinksJob` keys uniqueness on `user_id`. Phase 6's three new jobs key on different IDs:

- `BackfillInboxJob`: `uniqueId() = (string) $this->inboxId`. Lifetime 30 min (long backfills run minutes).
- `IncrementalScanJob`: `uniqueId() = (string) $this->inboxId`. Lifetime 10 min.
- `DiscoveryScanJob`: `uniqueId() = (string) $this->userId`. Lifetime 10 min (discovery runs across all of a user's inboxes, no per-inbox lock).

Each job lists itself in the `tests/Contracts/BoundaryArchTest.php` "no Laravel facade usage in module code" ignoring list — the `Cache::driver('redis')` call in `uniqueVia()` is the project-wide single facade carve-out.

### Anti-Patterns to Avoid

- **Hand-rolling MIME header parsing.** Subject lines come Q-encoded (`=?UTF-8?Q?Bedankt_voor_je_betaling?=`), From: headers carry quoted display names with commas, dates fold across lines. Use `zbateson/mail-mime-parser`; do not regex.
- **Fetching message bodies through `users.messages.get` without `format=raw`.** The default `format=full` returns a parsed Gmail-specific JSON structure that loses MIME signatures and makes attachments harder to reach. `format=raw` returns the original RFC 822 byte stream Gmail received — that's what we persist for Phase 7.
- **Forgetting to base64url-decode Gmail's raw response.** Gmail returns `messages.get?format=raw` with the entire RFC 822 byte stream encoded in **base64url** (not standard base64; `-_` instead of `+/`, no `=` padding). Writing the raw response straight to disk produces a corrupted `.eml`. Decode first.
- **Re-fetching messages on Gmail `historyId` expiry without a fallback.** Gmail's `history.list` returns HTTP 404 when the cursor is too old. Naïve retry hits the same 404 forever. Fallback: walk `messages.list` from `last_scan_at - 7d` with the `from:` filter, then re-baseline the cursor from the most-recent message returned.
- **Polling `delta` endpoints aggressively on Microsoft Graph.** Graph throttling kicks in at 10,000 requests / 10 minutes per app+mailbox. Hourly cadence on a single mailbox is well within bounds (6 requests/hour). Don't let backfill burst above that — `$top=100` + `Retry-After` honour suffices.
- **Storing OAuth `client_secret` or `refresh_token` in `.env`.** `.env` leaks into editor recent-files and git diffs. CONTEXT.md D-112 mandates the chmod-600 JSON — make sure `OAuthSecretsRepository` is the ONLY DI surface that opens that file.
- **Logging the raw `refresh_token` in `Log::info('got token', $token)` during dev.** Once a `refresh_token` is in `storage/logs/laravel.log`, the chmod-600 invariant is broken. Phase 6 service classes that catch `IdentityProviderException` must log the exception MESSAGE only, never the token payload.
- **Returning Eloquent models from the Public surface.** `InboxHealthDto` (spatie/laravel-data) is the contract Phase 7 + the Livewire SFC consume — never `Inbox::class`. Matches Phase 5 precedent.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| OAuth2 authorization-code flow + PKCE + token refresh | A `MyOAuthHandler` class | `league/oauth2-google` + `thenetworg/oauth2-azure` (both on `league/oauth2-client`) | 123M downloads of `league/oauth2-client`; the spec edge cases (`state` mismatch, nonce, scope downgrade, refresh_token rotation, `invalid_grant` recovery) are all handled. |
| Gmail API REST calls | Direct Guzzle calls to `https://gmail.googleapis.com/gmail/v1/...` | `google/apiclient` + `Google\Service\Gmail` | SDK already handles ETag, batch, quota-aware retry, and the Gmail-specific `Google\Service\Exception` shape with structured error reasons (rateLimitExceeded, userRateLimitExceeded, dailyLimitExceeded). |
| Graph API REST calls | Direct Guzzle | `microsoft/microsoft-graph` | Kiota-generated client honours the `Retry-After` header automatically and handles delta-link continuation correctly. |
| RFC 822 header parsing (`From`, `Subject`, `Date`, Q-encoded body) | Regex on the `.eml` byte stream | `zbateson/mail-mime-parser` | Q-encoded UTF-8 headers + folded headers + display-name quoting + comment-in-address-list are the canonical regex-killer combinations. 51M installs of zbateson. |
| Loopback OAuth callback HTTP server | A custom `socket_create()` listener | Reuse the existing Laravel HTTP server bound to `127.0.0.1:8000` — register a `/oauth/callback/{provider}` route | The whole app already binds to loopback (FND-01). The OAuth callback is just another route on the same server. |
| Refresh-token rotation atomicity | `file_put_contents($path, json_encode(...))` | `$tmp = $path . '.tmp'; file_put_contents($tmp, $json, LOCK_EX); fsync; rename($tmp, $path)` via `OAuthSecretsRepository` | A crash mid-write leaves a half-written JSON; on next refresh the inbox is orphaned. Rename is atomic on POSIX filesystems. |
| Base64url decode | `base64_decode($input)` | `base64_decode(strtr($input, '-_', '+/') . str_repeat('=', (4 - strlen($input) % 4) % 4))` | Gmail's `format=raw` is base64url (RFC 4648 §5), not standard base64. PHP's `base64_decode` strict-mode requires `+/` and padding. |
| Exponential backoff math | `sleep(2 ** $attempt)` | Honour `Retry-After` header (Graph) / look at `Google\Service\Exception::getErrors()[0]['reason']` (Gmail), fall back to `[60, 300, 900, 3600]` schedule | Both providers return precise retry hints; ignoring them is a known cause of compound throttling. |
| MIME multipart attachment extraction | Hand-rolled boundary regex | Phase 7's job, not Phase 6 — keep `.eml` blobs raw and let `zbateson` handle it during parsing | Phase 6 stores; Phase 7 parses bodies. Boundary regex is a known time-sink. |

**Key insight:** Every "I'll just call the REST endpoint with Guzzle" temptation in this phase has a battle-tested vendor library 1000× cheaper than the bug surface. The phase value is in the *orchestration* (cursor management, single-flight, retry policy, UI lifecycle) — not in re-implementing OAuth or HTTP-to-Gmail.

## Common Pitfalls

### Pitfall 1: Google Testing-mode refresh tokens expire after 7 days (NOT 6 months)

**What goes wrong:** D-111 assumes refresh tokens issued to a "test user" in a Testing-status OAuth client never expire. They expire in 7 days. The user gets through the wizard, connects Gmail, gets 7 days of working scans, then every inbox transitions to `needs_reauth` simultaneously. The user re-consents through the wizard. Seven days later it happens again. The user gives up.

**Why it happens:** Google's policy documentation: "A Google Cloud Platform project with an OAuth consent screen configured for an external user type and a publishing status of 'Testing' is issued a refresh token expiring in 7 days, unless the only OAuth scopes requested are a subset of name, email address, and user profile." `gmail.readonly` is not in that subset. [CITED: developers.google.com/identity/protocols/oauth2/policies, developers.google.com/identity/protocols/oauth2]

**How to avoid:** D-114 wizard MUST include an explicit step "After creating your Google Cloud project, open the OAuth consent screen → click 'Publish app' → confirm 'Push to production'". A bring-your-own GCP project owned by one Google account with no other users can be safely pushed to production WITHOUT going through Google's verification process — verification is required only when there are OTHER users in the project. The wizard copy needs to explain this clearly.

**Warning signs:** Inboxes simultaneously transitioning to `needs_reauth` after exactly 7 days; user-reported "Gmail keeps disconnecting".

**Detection in Wave 0:** Add a manual checklist item to the Wave 0 manual-end-to-end test: "Confirm the GCP project publishing status is 'In production', not 'Testing'."

### Pitfall 2: Google + Azure both reject `https://diederik.test/...` as a redirect URI

**What goes wrong:** D-114's wizard hands the user the redirect URI `https://diederik.test/oauth/callback/{provider}`. The user pastes it into the Google Cloud Console "Authorized redirect URIs" field and gets an error: "Invalid Redirect: must end with a public top-level domain (such as .com or .org), and must use a valid top private domain." The same paste into Azure App Registration succeeds the format check but the OAuth dance later fails with `AADSTS500117: The reply URL specified in the request does not match the reply URLs configured for the application.`

**Why it happens:** Google OAuth 2.0 native-app spec [CITED: developers.google.com/identity/protocols/oauth2/native-app]: "Loopback IP addresses (recommended for desktop): `http://127.0.0.1:port` or `http://[::1]:port` are explicitly supported. `localhost` can be used as an alternative, though this configuration may cause issues with client firewalls." Arbitrary HTTPS URLs are NOT mentioned as valid. Azure App Registration [CITED: learn.microsoft.com/en-us/entra/identity-platform/reply-url]: "HTTP schemes are supported only for localhost URIs... per RFC 8252... `http://sub.localhost` will be rejected." `.test` is not in the same RFC-defined loopback space as `localhost`.

**How to avoid (planner decision):** Three options:

1. **`http://127.0.0.1:8000/oauth/callback/{provider}`** — works on both providers per RFC 8252 + native-app spec. Requires the app to bind on a fixed port (currently Herd serves at `https://diederik.test`; the OAuth callback can live on a sibling Laravel port spawned for the OAuth dance only, OR the whole app can move to a fixed loopback port).
2. **`http://localhost:8000/oauth/callback/{provider}`** — also works but with the documented firewall caveat; some macOS firewalls/AV will block.
3. **Ephemeral local loopback server** — spin up a one-shot HTTP listener on `127.0.0.1:0` (kernel-assigned port) inside the wizard ONLY during the OAuth dance, then tear it down. Cleanest from a "no extra ports on the main app" perspective but requires `socket_create` + a 60-second timeout. League's OAuth client doesn't ship this; would have to hand-roll the HTTP listener (still simpler than full OAuth but new surface).

**Recommendation:** Option 1 with a Wave-0-owned PROJECT.md amendment — the local app moves to `http://127.0.0.1:8000` (or similar fixed port) for the OAuth callback, with Herd's `https://diederik.test` retained as a convenience bookmark for the main UI. The OAuth flow itself is invisible to the user past clicking "Connect" — the loopback URL is in the URL bar for ~2 seconds.

**Warning signs:** Wizard pasting fails in the Google Cloud Console; `redirect_uri_mismatch` error in the Google OAuth screen; `AADSTS50011` from Azure.

### Pitfall 3: Gmail `historyId` cursor expiry (HTTP 404 fallback)

**What goes wrong:** A user's inbox sits idle for 8 days (vacation, weekend trip, whatever). The next `IncrementalScanJob` calls `history.list?startHistoryId=12345` and gets HTTP 404 with reason `notFound`. Naïve handling retries forever.

**Why it happens:** Gmail's own documentation says "A historyId is typically valid for at least a week, but in some rare circumstances may be valid for only a few hours" and "If you receive an HTTP 404 error response, your application should perform a full sync." [CITED: developers.google.com/gmail/api/reference/rest/v1/users.history/list]

**How to avoid:**
1. Catch the 404 in `GmailApiClient::listHistory()` and re-raise as a typed `CursorExpiredException`.
2. `IncrementalScanJob` catches `CursorExpiredException` → falls back to a date-bounded `messages.list` walk from `now() - 7 days` (matching the expiry envelope) with the `from:` filter.
3. After the fallback walk completes, capture the highest `historyId` from any returned message → save as the new cursor.

**Warning signs:** Gmail user inboxes that mysteriously go silent after vacations; `last_scan_at` advances but `inbox_messages` count doesn't.

### Pitfall 4: Microsoft Graph delta-link `410 Gone`

**What goes wrong:** Same as Pitfall 3 but for Graph. Less common (Graph delta links last much longer in practice) but the same recovery pattern is needed.

**Why it happens:** Graph delta-links have no published lifetime; in practice they survive weeks-to-months but can be invalidated by mailbox migrations, Exchange admin actions, or large folder-tree restructures.

**How to avoid:** Catch `410 Gone` in `GraphApiClient::deltaPage()` → throw `CursorExpiredException` → same fallback walk as Gmail (a `/me/messages?$filter=receivedDateTime ge {last_scan_at - 7d}` walk + new delta-link baseline).

### Pitfall 5: `.eml` writes succeed but DB insert fails → orphan blobs

**What goes wrong:** Job writes `.eml` to disk, opens DB tx, hits some constraint, rolls back. The `.eml` is now an orphan: no `inbox_messages` row references it, nothing will ever clean it up, and on the next retry the job re-fetches the same `provider_message_id`, re-writes the same `.eml` to the same path (overwriting), inserts the row — but the failed-job retry path also could have run the cleanup. Two failure modes converge.

**Why it happens:** Filesystem writes can't participate in a DB transaction.

**How to avoid:** The explicit "cleanup-on-rollback" ordering in `BackfillInboxJob`/`IncrementalScanJob` (`.eml` write → try { DB tx } catch { unlink .eml; throw }`). UNIQUE `(inbox_id, provider_message_id)` makes `insertOrIgnore` idempotent on retry. Wave 0 must include a deliberate-failure-injection test (CONTEXT.md "Risks Phase 6 Specifically Owns" already calls this out).

**Warning signs:** Disk usage in `storage/app/inbox/` growing without corresponding `inbox_messages` row count growth.

### Pitfall 6: SQLite single-writer contention when multiple inboxes back-fill in parallel

**What goes wrong:** User connects two Gmail inboxes back-to-back. Both `BackfillInboxJob`s start running in parallel via Horizon. Both try to write `inbox_messages` rows + update `inbox_scan_state` rows in fast succession. SQLite serialises writes through its single-writer lock; one worker gets `SQLITE_BUSY` and crashes the job.

**Why it happens:** SQLite is single-writer at the file level even in WAL mode (WAL allows reader-while-writer, NOT writer-while-writer).

**How to avoid:**
1. Set `PRAGMA busy_timeout = 5000` on every job's DB connection (Phase 5 `CardStatementStateMachine` precedent — same pragma).
2. `ShouldBeUniqueUntilProcessing` keyed on `inbox_id` already prevents same-inbox parallelism. Different inboxes CAN run in parallel.
3. Keep each per-page DB transaction TINY: write 100 `inbox_messages` rows in one `$db->connection()->transaction()`, then exit the closure and start the next page outside the tx. Don't hold the writer lock across an HTTP fetch.
4. Wave 0 test: spawn two concurrent `BackfillInboxJob`s for the same user (different inboxes) and assert both complete without `SQLITE_BUSY`.

**Warning signs:** `Database is locked` exceptions in `failed_jobs.exception`; partial backfills.

### Pitfall 7: launchd `php` path differs from `/usr/bin/php` on Herd installs

**What goes wrong:** Generated plist hardcodes `/usr/bin/php` (system PHP, currently 8.3 on macOS 14). The project requires PHP 8.5 (composer.json `require.php: ^8.5`). Horizon refuses to boot under 8.3; `php artisan horizon` exits immediately. launchd's `KeepAlive` happily restarts it 1000 times in a row. CPU spikes.

**Why it happens:** Laravel Herd ships its own PHP binary at `~/Library/Application Support/Herd/bin/php` (or `/Applications/Herd.app/Contents/Resources/...`); the path varies by Herd version and install location.

**How to avoid:**
1. `InstallLaunchdCommand` (the `diederik:install --launchd` artisan command) reads `PHP_BINARY` at runtime — the same `php` the artisan command is currently running under — and substitutes it into a plist template.
2. Document the full plist regeneration step in README "Background workers" section: re-run `php artisan diederik:install --launchd` after any Herd upgrade.
3. launchd plist sets `EnvironmentVariables.PATH` to include both `/usr/local/bin` and the project root, so any shell-level helpers resolve correctly.
4. plist sets `StandardOutPath` + `StandardErrorPath` to `storage/logs/launchd-horizon.log` etc. so a boot failure is grep-able.

**plist shape (D-131 reference):**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>com.diederik.horizon</string>
    <key>ProgramArguments</key>
    <array>
        <string>{{ABS_PHP_BINARY}}</string>
        <string>{{ABS_PROJECT_ROOT}}/artisan</string>
        <string>horizon</string>
    </array>
    <key>WorkingDirectory</key><string>{{ABS_PROJECT_ROOT}}</string>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key>
    <dict>
        <key>Crashed</key><true/>
        <key>SuccessfulExit</key><false/>
    </dict>
    <key>ThrottleInterval</key><integer>10</integer>
    <key>StandardOutPath</key><string>{{ABS_PROJECT_ROOT}}/storage/logs/launchd-horizon.log</string>
    <key>StandardErrorPath</key><string>{{ABS_PROJECT_ROOT}}/storage/logs/launchd-horizon.err.log</string>
</dict>
</plist>
```

Load command: `launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.diederik.horizon.plist` (the modern macOS 13+ form; `launchctl load` is deprecated). [CITED: launchd.info, official Apple docs]

### Pitfall 8: `users.messages.list` quota math vs `messages.get` cost

**What goes wrong:** Naïve backfill of 5,000 messages issues 5,000 × `messages.get?format=raw` calls. Each `messages.get` costs **20 quota units** (NOT 5 as some older docs imply); plus 50 × `messages.list` paginated calls at 5 units each. Total: 100,250 quota units. Gmail's per-user-per-minute quota is 6,000 quota units. [CITED: developers.google.com/gmail/api/reference/quota] The backfill runs for 17 minutes burning ~94% of the user's quota the whole time, blocking incremental scans of any other inbox.

**Why it happens:** Gmail quota updated 2026-05-01: `messages.get` now 20 units, was 5; many client implementations still assume the old cost.

**How to avoid:**
1. Throttle `BackfillInboxJob` to ~250 message-fetches per minute (= 5,000 quota units/min, leaving 1,000 units/min headroom for other inbox traffic).
2. `Illuminate\Support\Sleep` between page boundaries (`Sleep::seconds(2)` between 100-message pages keeps the rate at ~50/sec which is well within quota).
3. On 429 / `rateLimitExceeded` (which is what Gmail returns when the quota IS exceeded), honour `Retry-After` and transition to `inbox_scan_state.status='rate_limited'` with a retry_attempt counter.

**Per-mailbox capacity math (corrected):**
- Gmail: 6,000 quota units/user/minute = 250 `messages.get` (20-unit) calls per minute = **15,000 messages/hour**. Plenty for any realistic inbox.
- Microsoft Graph: 10,000 requests/10 minutes per app+mailbox = 1,000 requests/min. Each message fetch costs 1 request. **60,000 messages/hour** in theory, but `Retry-After` will arrive long before that on a sustained burst.

Hourly `IncrementalScanJob` running across, say, 4 inboxes processing ~10 new messages each = 40 fetches/hour total = nowhere near throttling. Safe.

### Pitfall 9: OAuth-client wizard onboarding for non-technical users

**What goes wrong:** The user's partner (multi-user-readiness target) tries to connect their Gmail through the wizard. They get stuck on the GCP project creation step because Google's UI changes every quarter and the wizard's screenshots are stale.

**Why it happens:** GCP Console UI changes frequently; Azure Portal redesigns periodically. Screenshots in the wizard go stale.

**How to avoid:**
1. Lean on text descriptions + "deep-link" buttons that open the GCP Console at the exact page (rather than full-screen screenshots). Google supports deep-links to "Create OAuth client" via `https://console.cloud.google.com/apis/credentials/oauthclient`.
2. The wizard is the developer's responsibility (D-111 trade-off) — accept that this is a one-time-per-install friction for the user-as-installer; once the OAuth client is configured, the per-inbox Connect button is non-technical.
3. README has a "Connecting an inbox" walkthrough screencast (optional v2 polish).

### Pitfall 10: `wire:poll.2s` during backfill burning CPU when backfill is paused (rate_limited)

**What goes wrong:** Inbox is rate-limited; backfill paused. `/inboxes` page still polls every 2 seconds via `wire:poll.2s` even though the progress strip will never change until `Retry-After` elapses. 30 seconds × 2s polls = 15 wasted requests.

**Why it happens:** `wire:poll` is dumb — it polls regardless of underlying state.

**How to avoid:** Conditional polling — `wire:poll.30s` (not 2s) when `inbox_scan_state.status='rate_limited'`, `wire:poll.2s` only when `status='backfilling'`. Livewire 4 supports conditional polling via `wire:poll.Ns="method"` rendered conditionally per row. Phase 5 wizard precedent (preview-wizard.blade.php line 195) shows the wire:poll.2s shape.

## Runtime State Inventory

Phase 6 is greenfield for its module — no rename / refactor / migration. **No runtime state to inventory.**

(Phase 7 will inherit a Phase 6 `inbox_messages` corpus and a `known_senders` table; Phase 6 itself starts from empty.)

## Code Examples

### Example 1: OAuth callback handling (token exchange + inbox row insert)

```php
// Source: synthesised from league/oauth2-google + thenetworg/oauth2-azure docs.
namespace Modules\EmailScan\Internal\Http\Controllers;

final class OAuthCallbackController
{
    public function __construct(
        private readonly GoogleOAuthProvider $googleProvider,
        private readonly MicrosoftOAuthProvider $microsoftProvider,
        private readonly OAuthSecretsRepository $secrets,
        private readonly DatabaseManager $db,
        private readonly CurrentUser $currentUser,
        private readonly Clock $clock,
        private readonly InboxScanStateMachine $stateMachine,
    ) {}

    public function handle(Request $request, string $provider): RedirectResponse
    {
        if (! in_array($provider, ['gmail', 'microsoft'], true)) {
            throw new NotFoundHttpException();
        }

        $oauth = $provider === 'gmail' ? $this->googleProvider : $this->microsoftProvider;

        // 1. Verify state to prevent CSRF.
        if ($request->get('state') !== $request->session()->pull('oauth_state_' . $provider)) {
            throw new InvalidStateException();
        }

        // 2. Exchange authorization_code → tokens (raises IdentityProviderException on error).
        $token = $oauth->getAccessToken('authorization_code', [
            'code' => $request->get('code'),
        ]);

        // 3. Read the inbox's email address from the userinfo endpoint.
        $email = $oauth->readEmail($token);

        // 4. Insert the inbox row + initial scan state inside one tx.
        $user = $this->currentUser->user();
        $now = $this->clock->now()->toDateTimeString();
        $inboxId = $this->db->connection()->transaction(function () use ($user, $provider, $email, $now): int {
            $inboxId = $this->db->connection()->table('inboxes')->insertGetId([
                'user_id' => $user->id,
                'provider' => $provider,
                'email' => $email,
                'backfill_progress' => json_encode(['fetched_count' => 0]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->db->connection()->table('inbox_scan_state')->insert([
                'user_id' => $user->id,
                'inbox_id' => $inboxId,
                'folder' => 'INBOX',
                'status' => 'idle',
                'retry_attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return $inboxId;
        });

        // 5. Atomically save the refresh_token to chmod-600 JSON.
        $this->secrets->saveInboxRefreshToken(
            inboxId: $inboxId,
            refreshToken: $token->getRefreshToken(),
            scope: $token->getValues()['scope'] ?? '',
            expiresAt: $token->getExpires() !== null ? Carbon::createFromTimestamp($token->getExpires()) : null,
        );

        // 6. Bounce to /inboxes with a flash that opens the backfill-window modal.
        return redirect()->route('inboxes.index')->with('open_backfill_modal', $inboxId);
    }
}
```

### Example 2: Atomic chmod-600 JSON rotation

```php
// Source: project pattern (FND / PLT-03 invariant).
namespace Modules\EmailScan\Public\Services;

final class OAuthSecretsRepository
{
    private const PATH = 'app/secrets/email-oauth.json';
    private const DIR_MODE = 0700;
    private const FILE_MODE = 0600;

    public function __construct(private readonly Filesystem $files) {}

    public function saveInboxRefreshToken(int $inboxId, string $refreshToken, string $scope, ?Carbon $expiresAt): void
    {
        $current = $this->readAll();
        // ... mutate $current['inboxes'][] ...
        $this->writeAtomic($current);
    }

    private function writeAtomic(array $data): void
    {
        $absolutePath = storage_path(self::PATH);
        $absoluteDir = dirname($absolutePath);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, self::DIR_MODE, recursive: true);
        }
        $tmp = $absolutePath . '.tmp';
        $bytes = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $fp = fopen($tmp, 'wb');
        flock($fp, LOCK_EX);
        fwrite($fp, $bytes);
        fflush($fp);
        // POSIX: fsync the file then the directory so rename is durable across crash.
        if (function_exists('fsync')) {
            fsync($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        chmod($tmp, self::FILE_MODE);
        rename($tmp, $absolutePath);
    }
}
```

### Example 3: RFC 822 header extraction via zbateson

```php
// Source: zbateson/mail-mime-parser README, https://mail-mime-parser.org/
use ZBateson\MailMimeParser\MailMimeParser;

$parser = new MailMimeParser();
$message = $parser->parse($rawEmlContents, true);
$fromHeader = $message->getHeader('From');                  // AddressHeader
$senderEmail = $fromHeader->getAddresses()[0]->getEmail();  // string
$senderName  = $fromHeader->getAddresses()[0]->getName();   // string|null
$subject     = $message->getHeaderValue('Subject');         // string (already Q-decoded)
$date        = $message->getHeader('Date')->getDateTime();  // DateTimeImmutable
```

### Example 4: launchd plist install command sketch

```php
// Source: synthesised from launchd.info + project pattern.
namespace App\Console\Commands;

final class InstallLaunchdCommand extends Command
{
    protected $signature = 'diederik:install {--launchd}';

    public function handle(Filesystem $files): int
    {
        if (! $this->option('launchd')) {
            return self::SUCCESS;
        }
        $template = $files->get(base_path('deploy/launchd/com.diederik.horizon.plist'));
        $rendered = strtr($template, [
            '{{ABS_PHP_BINARY}}' => PHP_BINARY,
            '{{ABS_PROJECT_ROOT}}' => base_path(),
        ]);
        $target = $_SERVER['HOME'] . '/Library/LaunchAgents/com.diederik.horizon.plist';
        $files->put($target, $rendered);
        // Repeat for scheduler + optional redis...
        $this->info('Loading via launchctl bootstrap...');
        passthru('launchctl bootstrap gui/' . posix_getuid() . ' ' . escapeshellarg($target));
        return self::SUCCESS;
    }
}
```

## Runtime Knobs (defaults the planner should lock)

| Knob | Default | Rationale |
|------|---------|-----------|
| `BackfillInboxJob::$tries` | 3 | Mirrors Phase 5 D-103 |
| `BackfillInboxJob::$backoff` | `[60, 300, 900]` | Phase 5 mirror |
| `BackfillInboxJob::$uniqueFor` | 1800 (30 min) | Long backfills run minutes |
| `IncrementalScanJob::$uniqueFor` | 600 (10 min) | Same as Phase 5 |
| `DiscoveryScanJob::$uniqueFor` | 600 (10 min) | Same as Phase 5 |
| Page size (`messages.list` / `$top=`) | 100 | Both providers cap-friendly |
| Per-page DB tx timeout (SQLite `busy_timeout`) | 5000 ms | Phase 5 `CardStatementStateMachine` mirror |
| Discovery threshold | 2 occurrences within 90 days | D-135 default; planner can tune |
| Backfill window default | 3 months | EML-04 |
| Backfill window max | 12 months | EML-04 |
| `wire:poll` cadence when status='backfilling' | 2s | Phase 5 wizard mirror |
| `wire:poll` cadence when status='rate_limited' | 30s | Pitfall 10 |
| `wire:poll` cadence when status='idle' / 'needs_reauth' | OFF (no poll) | No state change possible without user action |

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| IMAP via `ext-imap` | Pure-PHP IMAP via `webklex/php-imap` | PHP 8.4 unbundled ext-imap (~2024) | Drove research/STACK.md recommendation |
| Pure-PHP IMAP via Webklex | Provider APIs only (Gmail API + Graph) | PROJECT.md constraint (2026-05-12) | Phase 6 follows API-only override |
| OAuth `accessType=online` (no refresh token) | OAuth `accessType=offline` + explicit `prompt=consent` | Required by both Google + Microsoft for refresh tokens since ~2022 | Without `accessType=offline` the `refresh_token` is never issued; without `prompt=consent` it's only issued on the FIRST consent, not on re-grants — a known subtle bug |
| `messages.get` Gmail quota: 5 units | `messages.get` Gmail quota: 20 units | Effective 2026-05-01 | Pitfall 8 capacity math |
| Direct Gmail UID (IMAP) | `users.history.list?startHistoryId=` (Gmail) / `/messages/delta` (Graph) | Always (provider APIs never had UID/UIDVALIDITY) | Cursor semantics differ from research/ARCHITECTURE.md L536+ shape — `ScanCursor` value object normalises |
| `launchctl load ~/Library/LaunchAgents/foo.plist` | `launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/foo.plist` | macOS 10.10+ (2014) — but `load` still works as deprecated | Use `bootstrap` form in install command |

**Deprecated / outdated to avoid:**
- Any reference to `webklex/laravel-imap` in Phase 6 docs or PROJECT.md amendment (it's listed in research/STACK.md as recommended; that's superseded but the file remains for historical context — do NOT remove, but the Phase 6 planner SHOULD not cite it).
- `launchctl load` style in plist install command (deprecated since macOS 10.10).
- Custom URL schemes for OAuth redirect (`com.diederik:/callback`) — Google explicitly deprecated this style in 2022.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Gmail API `messages.get` quota cost is currently 20 units (was 5 pre-2026-05-01) | Pitfall 8 | LOW — confirmed via official Gmail quota doc; even if reverted, the throttle policy still works |
| A2 | Microsoft Graph delta-link returns HTTP 410 Gone on expiry (not 404) | Pitfall 4 | LOW — community-documented; either status triggers same fallback |
| A3 | `microsoft/microsoft-graph` v3.1.0 retry middleware honours `Retry-After` automatically (per Kiota docs) | Pattern 1 | LOW — fallback `Retry-After` handler in `GraphApiClient` is cheap belt-and-braces |
| A4 | Hourly `IncrementalScanJob` × 4 inboxes does not exhaust Gmail's 6,000-units/min per-user quota | Pitfall 8 | LOW — math leaves >1,000 units/min headroom; if wrong, exponential backoff catches it |
| A5 | `OAuthSecretsRepository` writing to `storage/app/secrets/email-oauth.json` with chmod 600 satisfies PLT-03 without an additional `Crypt::encrypt` layer | D-138 | MEDIUM — D-138 explicitly defers this to planner; user may want defense-in-depth |
| A6 | macOS `fsync()` on the temp file before `rename()` makes the atomic-rotation crash-safe | Example 2 | LOW — POSIX-standard; macOS APFS honours this |
| A7 | The user is willing to run `php artisan diederik:install --launchd` once per Herd upgrade | Pitfall 7 | LOW — documented in README; one-time per upgrade |
| A8 | `discovered_senders` threshold of 2 occurrences in 90 days produces a reasonable signal-to-noise ratio | D-121/D-135 | MEDIUM — empirical; planner can tune in Wave 0 once synthesised fixtures land |
| A9 | The user is willing to flip their own GCP project from "Testing" to "In production" without going through Google verification (legal for a single-user project) | Pitfall 1 | LOW — Google's policy explicitly allows this for personal projects; verification is required when adding OTHER test users not when publishing for self-use |
| A10 | Local Laravel HTTP server bound to `http://127.0.0.1:PORT` is acceptable as the OAuth redirect URI for both Google and Azure | Pitfall 2 | LOW — RFC 8252 + both vendors explicitly support this; the question is which port |

**If this table is non-empty, the planner should confirm each assumption with the user during discuss-phase or the plan-check pass.**

## Open Questions

1. **Which redirect URI scheme does the planner pick (Pitfall 2)?**
   - What we know: `https://diederik.test/...` is invalid. `http://127.0.0.1:PORT/...` works. `http://localhost:PORT/...` works with firewall caveat.
   - What's unclear: Does the user want Herd's `https://diederik.test` to remain the only entry point (forcing option 3 — ephemeral loopback server), or accept a second loopback URL bound to a fixed port (option 1)?
   - Recommendation: Option 1 with a fixed port (e.g. 8765) bound at app boot, used ONLY for OAuth callbacks. PROJECT.md amendment in Wave 0.

2. **Does the planner want to add a `Crypt::encrypt` layer on top of chmod-600 JSON (D-138)?**
   - What we know: chmod-600 + atomic rotation satisfies PLT-03 baseline.
   - What's unclear: Defense-in-depth vs simpler operator mental model.
   - Recommendation: Skip in Phase 6; revisit in Phase 11 ("Operational Hardening") when macOS Keychain integration is on the table anyway.

3. **Where exactly does `IncrementalScanJob` get dispatched from (`Schedule::job(...)` or `Schedule::call(fn() => ...)`)?**
   - What we know: Phase 5 `ResolveChainLinksJob` is dispatched from `ConfirmImport` (event-driven), NOT from `Schedule`.
   - What's unclear: The "hourly" cadence (D-137) is a `Schedule` registration in `app/Console/Kernel.php` — but the job class is in `Modules/EmailScan/Internal/Jobs/`. The pattern hasn't been used in the codebase yet.
   - Recommendation: `Schedule::call(function (Container $app) { foreach ($app->make(InboxQuery::class)->forAllUsers() as $inbox) { $app->make(IncrementalScanJob::class, ['inboxId' => $inbox->id])->dispatch(); } })->hourly()->withoutOverlapping(30);`. The `withoutOverlapping(30)` is belt-and-braces atop `ShouldBeUniqueUntilProcessing`.

4. **How should the OAuth-client wizard handle the GCP "OAuth consent screen" → "Publish app" step copy (Pitfall 1)?**
   - What we know: Without publishing, refresh tokens expire in 7 days.
   - What's unclear: How explicit should the wizard be? Most users don't know the consequence.
   - Recommendation: Mandatory checkbox in the wizard: "I have set the OAuth consent screen status to 'In production' (refresh tokens won't expire after 7 days)." Submit is disabled until checked.

5. **Does the wizard verify the redirect_uri before saving?**
   - What we know: User can paste a different redirect_uri into Google Console than what diederik will actually use.
   - Recommendation: After wizard submit, immediately trigger the OAuth dance. If `redirect_uri_mismatch` returns, the user is back at the wizard with a clear error. Don't try to verify proactively — Google's APIs don't expose that.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All phase code | ✓ | 8.5 (PHP_BINARY: `{{ to be verified at install time }}`) | — |
| Redis | Horizon + ShouldBeUniqueUntilProcessing | ✓ | Inherited from Phase 5 (Docker container or launchd `com.diederik.redis.plist`) | none — required |
| SQLite | All DB | ✓ | Inherited from Phase 1 | — |
| Laravel Herd | Local serving (`https://diederik.test`) | ✓ | Inherited | — |
| macOS launchd | Background workers | ✓ | macOS 14 / Darwin 24.6.0 (verified empirically: `Darwin 24.6.0` from env) | none — required |
| Internet access during scans | Gmail API + Graph API | runtime check | — | Job catches network errors → `inbox_scan_state.status='error'` → next scheduled tick retries |
| Active GCP project (user-owned) | Gmail OAuth | runtime (per-install) | — | Wizard cannot proceed without it — explicit "blocked" state in `/inboxes` empty hero |
| Active Azure App Registration (user-owned) | Microsoft OAuth | runtime (per-install) | — | Same as above |

**Missing dependencies with no fallback:** Redis (already addressed in Phase 5); active provider OAuth clients (intentional bring-your-own per D-111).

**Missing dependencies with fallback:** Internet access (job retries gracefully).

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4.x (parallel mode via `pestphp/pest-plugin-laravel` + `pestphp/pest-plugin-arch`) |
| Config file | `tests/Pest.php` + per-module `Modules/EmailScan/tests/Pest.php` (inert) + `phpunit.xml` testsuites |
| Quick run command | `pest --filter=EmailScan --parallel` |
| Full suite command | `composer test` (alias for `pest --parallel`) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| EML-01 | User authorizes Gmail via OAuth2 (callback exchange + token save) | feature | `pest Modules/EmailScan/tests/Feature/OAuthCallbackGmailTest.php` | ❌ Wave 0 |
| EML-01 | OAuth state CSRF mismatch raises 400 | unit | `pest Modules/EmailScan/tests/Unit/OAuth/StateMismatchTest.php` | ❌ Wave 0 |
| EML-02 | User authorizes Microsoft 365 via OAuth2 | feature | `pest Modules/EmailScan/tests/Feature/OAuthCallbackMicrosoftTest.php` | ❌ Wave 0 |
| EML-03 | Multiple inboxes per user; `BackfillInboxJob` runs per-inbox-id | integration | `pest Modules/EmailScan/tests/Integration/BackfillPerInboxJobTest.php` | ❌ Wave 0 |
| EML-04 | Backfill window (1-12 mo) configurable + chunked + non-blocking | feature | `pest Modules/EmailScan/tests/Feature/BackfillWindowModalTest.php` + `pest Modules/EmailScan/tests/Integration/BackfillChunkedJobTest.php` | ❌ Wave 0 |
| EML-04 | Window slider clamps to 1..12 even with crafted POST | unit | `pest Modules/EmailScan/tests/Unit/Http/BackfillWindowValidationTest.php` | ❌ Wave 0 |
| EML-06 | Kill/restart resume — `last_history_id` / `last_delta_link` re-read on next scan | integration | `pest Modules/EmailScan/tests/Integration/ResumeFromCursorTest.php` | ❌ Wave 0 |
| EML-06 | `ScanCursor` value object round-trips Gmail historyId + Graph deltaLink | unit | `pest Modules/EmailScan/tests/Unit/Dto/ScanCursorTest.php` | ❌ Wave 0 |
| EML-06 | Gmail cursor 404 → fallback to date-bounded `messages.list` walk | integration | `pest Modules/EmailScan/tests/Integration/GmailCursorExpiryFallbackTest.php` | ❌ Wave 0 |
| EML-06 | Graph cursor 410 → fallback to date-bounded `messages?$filter=receivedDateTime` walk | integration | `pest Modules/EmailScan/tests/Integration/GraphCursorExpiryFallbackTest.php` | ❌ Wave 0 |
| EML-08 | Gmail `rateLimitExceeded` → status `rate_limited` + retry_attempts++ + `Retry-After` honoured | integration | `pest Modules/EmailScan/tests/Integration/GmailRateLimitBackoffTest.php` | ❌ Wave 0 |
| EML-08 | Graph 429 → status `rate_limited` + `Retry-After` honoured | integration | `pest Modules/EmailScan/tests/Integration/GraphRateLimitBackoffTest.php` | ❌ Wave 0 |
| EML-08 | `invalid_grant` → status `needs_reauth` + dashboard toast fires once | feature | `pest Modules/EmailScan/tests/Feature/InvalidGrantToastTest.php` | ❌ Wave 0 |
| EML-08 | `/inboxes` health badges render correctly per status | feature | `pest Modules/EmailScan/tests/Feature/InboxesHealthBadgeRenderTest.php` | ❌ Wave 0 |
| PLT-03 | `email-oauth.json` written with mode 0600 via atomic rotation | unit | `pest Modules/EmailScan/tests/Unit/Services/OAuthSecretsRepositoryTest.php` | ❌ Wave 0 |
| PLT-03 | Failed rotation mid-write leaves the prior file intact | unit | `pest Modules/EmailScan/tests/Unit/Services/OAuthSecretsAtomicRotationTest.php` | ❌ Wave 0 |
| PLT-03 | Composer audit asserts no `ext-imap` regression (lint) | contracts | `pest tests/Contracts/PltExtImapAuditTest.php` (extension of existing PLT-05 grep) | ✓ Phase 1 (extend) |
| PLT-04 | `diederik:install --launchd` produces three plists with correct `PHP_BINARY` substitution | feature | `pest tests/Feature/InstallLaunchdCommandTest.php` | ❌ Wave 0 |
| — | `BoundaryArchTest::noTransactionWritesFromEmailScan` invariant | contracts | `pest tests/Contracts/BoundaryArchTest.php` (extend) | ✓ Phase 1 (extend) |
| — | `Modules\\EmailScan\\Internal` only used inside `Modules\\EmailScan` | contracts | same file | ✓ Phase 1 (extend) |
| — | No facade calls in `Modules/EmailScan/` except permitted `Cache::driver('redis')` carve-out | contracts | same file (carve-out list grows) | ✓ Phase 1 (extend) |
| — | Cross-user 404: `/inboxes/{id}/scan-now` for another user's inbox → 404 | feature | `pest Modules/EmailScan/tests/Feature/CrossUserInboxIsolationTest.php` | ❌ Wave 0 |
| — | `.eml` write succeeds but DB tx rollback → orphan `.eml` cleaned up | integration | `pest Modules/EmailScan/tests/Integration/EmlOrphanCleanupTest.php` | ❌ Wave 0 |
| — | UNIQUE `(inbox_id, provider_message_id)` makes re-fetch idempotent | integration | `pest Modules/EmailScan/tests/Integration/ReFetchIdempotentTest.php` | ❌ Wave 0 |
| — | Two parallel `BackfillInboxJob` for different inboxes of same user converge without SQLITE_BUSY | integration | `pest Modules/EmailScan/tests/Integration/ConcurrentBackfillTest.php` | ❌ Wave 0 |
| — | `DiscoveryScanJob` writes only sender metadata (no `.eml` blobs) | integration | `pest Modules/EmailScan/tests/Integration/DiscoveryScanNoEmlBlobsTest.php` | ❌ Wave 0 |
| — | Top-nav inboxes badge fed by ViewFactoryContract composer (no `view()` helper) | feature | `pest Modules/EmailScan/tests/Feature/TopNavBadgeViaComposerTest.php` | ❌ Wave 0 |
| — | Dashboard "Email scan health" tile renders correct line counts per inbox health | feature | `pest tests/Feature/EmailScanHealthTileTest.php` | ❌ Wave 0 |
| — | Empty-state hero renders on first `/inboxes` visit with zero inboxes | feature | `pest Modules/EmailScan/tests/Feature/InboxesEmptyStateTest.php` | ❌ Wave 0 |
| — | Backfill progress wire:poll status query reflects current `inbox_scan_state` correctly | feature | `pest Modules/EmailScan/tests/Feature/BackfillProgressPollTest.php` | ❌ Wave 0 |
| — | OAuth-client wizard modal closes + redirects to consent on submit | feature | `pest Modules/EmailScan/tests/Feature/OAuthClientWizardModalTest.php` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `pest --filter=EmailScan --parallel` — runs only the new module's suites + the extended contracts files.
- **Per wave merge:** `composer test` (full suite) + `composer analyse` (Larastan level 10) + `composer format:check` (Pint).
- **Phase gate:** Full suite green; the SC#3 (UID resume) and SC#4 (health view) require manual smoke against a real Gmail or Outlook account documented in the phase-close summary.

### Wave 0 Gaps

- `Modules/EmailScan/composer.json` — module manifest with autoload + autoload-dev.
- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` — minimal bindings (no jobs yet).
- `Modules/EmailScan/tests/TestCase.php` + `Modules/EmailScan/tests/Pest.php` — module test bootstrap (inert).
- `tests/Pest.php` foreach map row addition for `Modules/EmailScan`.
- `phpunit.xml` testsuite entries for the new module.
- `composer.json autoload-dev psr-4` entry for `Modules\\EmailScan\\Tests\\`.
- `Modules/EmailScan/tests/fixtures/eml/{paypal,ics,googleplay}/*.eml` — synthesised anonymised fixtures (matches Phase 5 D-107 + Phase 4 D-58 anonymisation discipline).
- `Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php` + `FakeGraphApiClient.php` — fixture-driven fakes (D-140).
- `tests/Contracts/BoundaryArchTest.php` extension — `noTransactionWritesFromEmailScan` rule, plus `Modules\EmailScan\Internal` containment, plus `Cache::driver` carve-out for the three new jobs.
- `tests/Contracts/PltExtImapAuditTest.php` extension — adds the `composer.lock` `webklex` check on top of the existing `ext-imap` lint.
- Failing scaffolds for every test file listed in the requirements-to-tests map above (red baseline matches Phase 1-05 / Phase 5-01b precedent).

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Inherits Phase 1 Fortify single-user auth + LoopbackOnly middleware; no new auth surface |
| V3 Session Management | yes | `oauth_state_{provider}` stored in encrypted Laravel session (Laravel default) for CSRF on OAuth dance |
| V4 Access Control | yes | Cross-user 404 invariant (Phase 3/4/5 pattern) on every `/inboxes/*` route — `where('user_id', $currentUser->id)` + defensive `$inbox->user_id === $currentUser->id` assertion |
| V5 Input Validation | yes | `spatie/laravel-data` DTOs validate window slider (1..12), inbox_id (int), provider ('gmail'\|'microsoft'); known-sender email-pattern field validated against RFC-ish email regex |
| V6 Cryptography | yes | `league/oauth2-client` handles PKCE for native-app flow (where supported); Laravel APP_KEY drives session encryption; chmod 600 + atomic rotation on `email-oauth.json` (PLT-03) — never roll our own crypto |
| V7 Error Handling | yes | `IdentityProviderException` caught at `OAuthCallbackController` boundary; message logged (not the token payload); flashed as `__('error.oauth_failed')` to user |
| V8 Data Protection | yes | `.eml` blobs are sensitive (financial transactions in body); `storage/app/inbox/` MUST be `chmod 700` on creation; PLT-02 forbids `storage_path()` under iCloud Drive (documented in setup) |
| V9 Communications | yes | All provider API calls go over TLS via Guzzle (default); OAuth callback runs on `http://127.0.0.1` (RFC 8252 explicit loopback exemption — never leaves the device) |
| V10 Malicious Code | yes | `composer.json conflict` block locks out `ext-imap` + `webklex/*` regressions; slopcheck audit clears all six new packages |
| V14 Configuration | yes | `OAuthSecretsRepository::DIR_MODE = 0700`; `FILE_MODE = 0600`; install command verifies + tightens on first run |

### Known Threat Patterns for Laravel + provider OAuth + local SQLite

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| OAuth CSRF (attacker tricks user into authorizing attacker's account) | Tampering | Per-flow random `state` parameter in session, verified on callback (Example 1 above) |
| `redirect_uri_mismatch` exploitation via open redirector | Tampering | Loopback `http://127.0.0.1:PORT` redirect URI; route handler is class-as-handler with no parameter-driven redirect targets |
| Refresh token theft via filesystem read | Information disclosure | chmod 600 on `email-oauth.json`; chmod 700 on parent dir; PLT-02 forbids iCloud Drive |
| Refresh token theft via log leak | Information disclosure | OAuth providers' exception messages must be logged without payload; static-rule grep to forbid `Log::*` of `$token` variable in `Modules/EmailScan/` |
| `invalid_grant` masking real revocation (user thinks app is working when token revoked silently) | Repudiation | `inbox_scan_state.status='needs_reauth'` transitions visible on `/inboxes` + dashboard tile + one-shot toast (D-115) |
| .eml blob enumeration via predictable path | Information disclosure | `inbox_id` is a sequential int, `provider_message_id` is provider-issued — combined with user_id partition the path is not user-guessable; HTTP access to `storage/app/inbox/` blocked by default Laravel public/storage layout |
| Quota exhaustion DoS via discovery loop | Denial of service | D-119 server-side `from:` filter on primary scan; discovery is daily-only with broad-but-bounded keyword query |
| MIME parser RCE via crafted email | Tampering | `zbateson/mail-mime-parser` is pure-PHP, no `eval`; pin major version; trust boundary clearly documented (Phase 7 reads bodies, Phase 6 reads headers only) |
| Token replay on retry | Tampering | Refresh tokens are single-use in Microsoft Graph (rotated on each refresh); `league/oauth2-client` handles rotation. Gmail refresh tokens are not rotated but are tied to the project — same `OAuthSecretsRepository::rotateRefreshToken()` shape handles both |

## Sources

### Primary (HIGH confidence)

- [Google OAuth 2.0 for Native Apps (developers.google.com)](https://developers.google.com/identity/protocols/oauth2/native-app) — loopback IP redirect requirement, custom URI scheme deprecation
- [Google OAuth 2.0 (developers.google.com)](https://developers.google.com/identity/protocols/oauth2) — Testing mode 7-day refresh-token expiry
- [Google OAuth 2.0 Policies (developers.google.com)](https://developers.google.com/identity/protocols/oauth2/policies) — refresh-token invalidation triggers
- [Gmail API Quota (developers.google.com)](https://developers.google.com/gmail/api/reference/quota) — 6,000 units/user/minute, `messages.get`=20, `messages.list`=5, `history.list`=2
- [Gmail users.history.list (developers.google.com)](https://developers.google.com/gmail/api/reference/rest/v1/users.history/list) — historyId 7-day envelope + HTTP 404 → full sync
- [Microsoft Graph Throttling (learn.microsoft.com)](https://learn.microsoft.com/en-us/graph/throttling) — 429 + Retry-After, exponential backoff guidance
- [Microsoft Graph Outlook throttling limit (Microsoft Q&A — verified vs current docs)](https://learn.microsoft.com/en-us/answers/questions/1284905/throttling-limit-for-get-mail-from-office-365-usin) — 10,000 requests / 10 minutes per app+mailbox
- [Microsoft Graph message:delta (learn.microsoft.com)](https://learn.microsoft.com/en-us/graph/api/message-delta) — $filter limited to receivedDateTime only
- [Microsoft Graph delta-query-messages (learn.microsoft.com)](https://learn.microsoft.com/en-us/graph/delta-query-messages) — full delta walk + deltaLink semantics
- [Microsoft Graph message MIME endpoint (learn.microsoft.com)](https://learn.microsoft.com/en-us/graph/outlook-get-mime-message) — `/messages/{id}/$value` returns raw RFC 822
- [Microsoft identity platform reply URLs (learn.microsoft.com)](https://learn.microsoft.com/en-us/entra/identity-platform/reply-url) — HTTP scheme only for `localhost`, subdomains rejected
- [Packagist: google/apiclient](https://packagist.org/packages/google/apiclient) — version + deps audit
- [Packagist: microsoft/microsoft-graph](https://packagist.org/packages/microsoft/microsoft-graph) — version + deps audit
- [Packagist: microsoft/microsoft-graph-core](https://packagist.org/packages/microsoft/microsoft-graph-core) — transitive deps audit
- [Packagist: league/oauth2-client](https://packagist.org/packages/league/oauth2-client) — install count + deps
- [Packagist: league/oauth2-google](https://packagist.org/packages/league/oauth2-google) — install count + deps
- [Packagist: thenetworg/oauth2-azure](https://packagist.org/packages/thenetworg/oauth2-azure) — install count + deps
- [Packagist: zbateson/mail-mime-parser](https://packagist.org/packages/zbateson/mail-mime-parser) — install count + deps
- [launchd.info](https://www.launchd.info/) — modern bootstrap syntax + plist key reference
- Empirical: `slopcheck install -e packagist {6 packages}` + recursive grep on resulting `vendor/` (2026-05-16) — zero ext-imap / webklex hits

### Secondary (MEDIUM confidence)

- [Nango: Google OAuth invalid_grant troubleshooting](https://nango.dev/blog/google-oauth-invalid-grant-token-has-been-expired-or-revoked/) — Testing-mode + 6-month inactivity + 100-token-per-user limit confluence
- [PHP League OAuth 2.0 Client provider list (oauth2-client.thephpleague.com)](https://oauth2-client.thephpleague.com/providers/league/) — Microsoft is community-maintained, not official League
- [Gmail OR-filter syntax (community confirmation, Block Sender)](https://blocksender.io/how-to-use-or-conditions-in-gmail-filters/) — `from:(a OR b)` is identical to Gmail UI syntax

### Tertiary (LOW confidence — flagged for validation)

- Microsoft Graph delta-link expiry exact behaviour (community reports converge on 410 Gone but Microsoft has not formally published a lifetime); Wave 0 manual test can confirm.
- Exact CPU/memory profile of `zbateson/mail-mime-parser` on a 10 MB receipt with embedded images; v1 is fine but Phase 7 will exercise it harder.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — every version verified against Packagist on 2026-05-16; slopcheck clean; transitive `ext-imap` / `webklex` audit empirical and clean.
- Architecture: HIGH — patterns mirror established Phase 5 shape (composer ServiceProvider + Public/Internal split + BoundaryArchTest + View Factory composer + Horizon job lifecycle).
- Pitfalls: HIGH — Pitfalls 1 + 2 (Google Testing-mode 7-day expiry and `https://*.test` redirect-URI rejection) are verified against official Google + Azure documentation; both are CONTEXT.md decisions that need planner correction. Pitfalls 3 + 4 are verified against provider docs. Pitfalls 5–10 derive from project pattern continuity (Phase 5 precedent).
- Validation Architecture: MEDIUM — requirement-to-test map is exhaustive; the manual-vs-automated split (SC#3 + SC#4 require manual smoke against a real provider) is the only piece that can't be fully automated.

**Research date:** 2026-05-16
**Valid until:** 2026-06-16 (30 days; longer would be optimistic given Gmail's May 2026 quota change and the cadence of Microsoft Graph SDK releases). Re-verify Pitfall 1 (Google policy) and the Standard Stack version pins if the phase isn't started by then.

## RESEARCH COMPLETE

**Phase:** 6 - Email Receipt Ingestion Infrastructure
**Confidence:** HIGH

### Key Findings

1. **D-114 redirect URI is invalid for both providers** — `https://diederik.test/oauth/callback/{provider}` is rejected by Google Cloud Console (must be public TLD or loopback IP) and Azure App Registration (HTTP scheme only on literal `localhost`, never subdomains under `.test`). Planner must pick `http://127.0.0.1:PORT/oauth/callback/{provider}` (recommended) or alternative. Wave 0 needs a PROJECT.md amendment.
2. **D-111's "test-user refresh tokens don't expire" is wrong** — Google explicitly expires refresh tokens after 7 days for OAuth clients in "Testing" publishing status when scopes exceed name/email/profile (and `gmail.readonly` does). D-114 wizard must mandate the user push their GCP project to "In production" (free, no Google verification needed for a single-user-owned project).
3. **Hybrid SDK + OAuth-client strategy passes audit cleanly** — `google/apiclient` + `microsoft/microsoft-graph` for API calls + `league/oauth2-google` + `thenetworg/oauth2-azure` for OAuth + `zbateson/mail-mime-parser` for RFC 822 headers + `league/oauth2-client` as foundation. All six pass slopcheck; recursive `vendor/` grep finds zero `ext-imap` and zero `webklex` transitive pulls. PLT-05 invariant verifiable.
4. **D-119's "equivalent Graph from-filter" needs the two-phase pattern** — Graph `/messages/delta` only supports `$filter` on `receivedDateTime`. Backfill uses non-delta `/me/messages?$filter=from/emailAddress/address eq '...'` walking pagination; the first delta baseline is established AFTER backfill completes. Incremental uses delta + client-side post-filter against `known_senders`.
5. **Capacity headroom is comfortable** — Gmail's 6,000 units/min/user / 20-unit-per-`messages.get` = 15,000 messages/hour per inbox; Graph's 10,000 requests/10 minutes per app+mailbox = 1,000/min. Hourly incremental × 4 inboxes processing ~10 messages each = 40 fetches/hour — orders of magnitude below either provider's throttle.

### File Created

`/Users/wesselverheij/Development/diederik/.planning/phases/06-email-receipt-ingestion-infrastructure/06-RESEARCH.md`

### Confidence Assessment

| Area | Level | Reason |
|------|-------|--------|
| Standard Stack | HIGH | Versions verified on Packagist 2026-05-16; slopcheck clean; empirical `vendor/` grep audit confirmed PLT-05 |
| Architecture | HIGH | Mirrors locked Phase 5 shape (BoundaryArchTest + View Factory composer + Horizon ShouldBeUniqueUntilProcessing + chmod-600 atomic rotation) |
| Pitfalls | HIGH | Pitfalls 1, 2, 3, 4, 8 are verified against current official Google + Microsoft documentation. Pitfalls 5–7, 9, 10 derive from established project pattern continuity |
| Validation Architecture | MEDIUM | Exhaustive automated-test map; SC#3 + SC#4 require one manual smoke against real Gmail/Graph at phase-close |
| OAuth flow mechanics | HIGH | Both providers' redirect-URI + token-refresh + cursor-expiry behaviour cross-checked against official docs |

### Open Questions

Five open questions captured in §"Open Questions" — the load-bearing pair is (1) which redirect URI scheme the planner picks (Pitfall 2 / Open Q1) and (2) wizard copy for the "push to production" step (Pitfall 1 / Open Q4). Both should be resolved before any wizard task lands.

### Ready for Planning

Research complete. Two CONTEXT.md errata flagged with verifiable evidence (D-111 Testing-mode token lifetime, D-114 redirect-URI scheme) that the planner must reconcile before wizard work begins. SDK strategy (D-130) recommendation: hybrid path documented with audit-clean dependencies. Wave 0 fixture + Public-surface + boundary-test scaffold list is exhaustive enough that the plan-phase can lay down RED baseline files directly from this document.
