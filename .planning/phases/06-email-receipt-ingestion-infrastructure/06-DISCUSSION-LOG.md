# Phase 6: Email Receipt Ingestion Infrastructure - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in `06-CONTEXT.md` — this log preserves the alternatives considered.

**Date:** 2026-05-16
**Phase:** 6-Email Receipt Ingestion Infrastructure
**Areas discussed:** OAuth client provisioning, Raw message persistence, Backfill scope, Inbox connection UX

---

## OAuth Client Provisioning

### Q1 — Who registers the Google Cloud project + Azure app registration that holds the OAuth client_id/secret?

| Option | Description | Selected |
|--------|-------------|----------|
| User brings their own | Each install registers its own GCP project + Azure App; paste client_id/secret into chmod-600 config. | ✓ (initial) |
| Pre-baked diederik client_id | Ship single client_id/secret embedded in repo. Cannot pass Google verification for `gmail.readonly`. | |
| Hybrid: scaffolded setup | Bring-your-own + `oauth:install` artisan command + README walkthrough. | |

**User's choice:** User brings their own (initial), then refined to in-app wizard form (Q4 below).
**Notes:** User noted "the app should be usable by non-technical users", which forced the follow-up Q4 to find the right balance.

### Q2 — Where do the OAuth client_id/secret + per-inbox refresh tokens live on disk?

| Option | Description | Selected |
|--------|-------------|----------|
| Single JSON file | `storage/app/secrets/email-oauth.json` chmod 600, gitignored, atomic-rename rotation. | ✓ |
| Split files per provider | Separate `email-oauth-gmail.json` + `email-oauth-microsoft.json`. | |
| Env-style flat KV | `email-oauth.env`-shaped file parsed at boot. | |

**User's choice:** Single JSON file.
**Notes:** Locked the chmod-600 + atomic-rename invariant for D-112.

### Q3 — What OAuth scopes does Phase 6 request from each provider?

| Option | Description | Selected |
|--------|-------------|----------|
| Read-only body access | `gmail.readonly` + `Mail.Read` + `offline_access`. Sufficient for Phase 7 matchers. | (implicit, accepted on recap) |
| Metadata-only + on-demand body | `gmail.metadata` initially; escalate per-message. Adds second consent flow. | |
| Send-allowed scope | `gmail.modify` / `Mail.ReadWrite`. Expanded attack surface. | |

**User's choice:** Dismissed initially with "What did we need this for again?" — clarification provided (Phase 7 matchers need body access to extract amounts). Read-only body access was then accepted as part of the locked-decisions recap on the "More questions" branch transition without re-asking.
**Notes:** Final scope set locked as `gmail.readonly` + `Mail.Read` + `offline_access` (D-113).

### Q4 — Who installs diederik and uses it — and therefore who does the one-time OAuth client registration?

| Option | Description | Selected |
|--------|-------------|----------|
| One install, two users | Technical installer does GCP/Azure setup once; partner connects own inbox via in-app per-inbox button (non-technical). | |
| Distribute as verified app | Verify diederik with Google + Microsoft; non-technical users install with zero setup. Weeks of verification work. | |
| In-app wizard for client registration | Phase 6 ships modal wizard that guides installer through GCP/Azure portal step-by-step. | ✓ |

**User's choice:** In-app wizard for client registration.
**Notes:** Maximises hand-holding without forcing the developer through Google's verification gate. Resolved the "non-technical users" constraint as: wizard hand-holding is best-effort, but GCP/Azure Portal navigation remains intrinsically technical past Phase 6.

### Q5 — How does the in-app OAuth-client-registration wizard surface itself, and when?

| Option | Description | Selected |
|--------|-------------|----------|
| First-launch + per-provider modal | Modal opens when user clicks Add Gmail/Microsoft on `/inboxes` and no client is configured. | ✓ |
| One-shot install wizard | `php artisan diederik:install --email` walks both providers in sequence. | |
| Settings page only | Static form on `/settings/inboxes`. No deep-links, no screenshots. | |

**User's choice:** First-launch + per-provider modal.
**Notes:** Locks the modal as the only OAuth-client-setup surface (D-114). Wizard never reappears once configured.

### Q6 — When an inbox's refresh token gets revoked / expires, what happens?

| Option | Description | Selected |
|--------|-------------|----------|
| Surface + manual reconnect | `needs_reauth` state + red badge + Reconnect button + one-time toast. Scans pause silently. Existing state preserved. | ✓ |
| Auto-reconnect attempt then surface | App auto-redirects to consent on next login. Breaks user's current task. | |
| Delete inbox + force re-add | Mark deleted; lose scan_state; force full backfill. | |

**User's choice:** Surface + manual reconnect.
**Notes:** Locks D-115. Scan_state + .eml blobs + history-id cursor are preserved on reconnect.

---

## Raw Message Persistence

### Q1 — How are fetched messages stored between Phase 6 (fetch) and Phase 7 (parse)?

| Option | Description | Selected |
|--------|-------------|----------|
| Disk .eml blobs + DB index | `storage/app/inbox/{user_id}/{inbox_id}/{message_id}.eml` + `inbox_messages` table. | ✓ |
| DB-only | Full body_html + body_text + headers_json in DB BLOB columns. | |
| Metadata DB + parsed JSON on disk | Phase 6 pre-parses to normalized JSON; loses raw fidelity. | |

**User's choice:** Disk .eml blobs + DB index.
**Notes:** Locks D-116. Phase 7's `.eml`/`.mbox` import path will share the same matcher pipeline against the same .eml format.

### Q2 — What does the `inbox_messages` index row's status lifecycle look like, and what's the idempotency key?

| Option | Description | Selected |
|--------|-------------|----------|
| Status enum + provider-id unique | Single table, status enum (fetched/parsed/skipped/unmatched), UNIQUE (inbox_id, provider_message_id). | ✓ |
| Two tables: raw + parsed | `inbox_messages` (Phase 6) + `inbox_parse_attempts` (Phase 7). More JOINs. | |
| Status + parse_attempts JSON | Single table + JSON column with append-only parse attempt history. | |

**User's choice:** Status enum + provider-id unique.
**Notes:** Locks D-117. Phase 6 only writes `fetched`; Phase 7 owns the rest of the state machine.

### Q3 — How are the .eml files laid out on disk, and what's the retention policy?

| Option | Description | Selected |
|--------|-------------|----------|
| Date-partitioned, keep forever | `{YYYY}/{MM}/` partitions; attachments embedded in .eml; kept forever. | ✓ |
| Flat per-inbox, keep forever | Single dir per inbox; could hit 100k+ files. | |
| Date-partitioned, evict after parse | Same layout, delete on successful parse. Loses audit trail. | |

**User's choice:** Date-partitioned, keep forever.
**Notes:** Locks D-116 path layout. Matches the "history retained forever" project constraint.

---

## Backfill Scope

### Q1 — Which messages does Phase 6 actually fetch during backfill + ongoing scan?

| Option | Description | Selected |
|--------|-------------|----------|
| Server-side from:sender list | `q=from:(...)` filter pushed to Gmail/Graph. Only matching messages fetched. | ✓ (with refinement) |
| Everything in window, filter post-fetch | Fetch every message, persist all .eml, filter on parse. Bandwidth-prohibitive. | |
| Hybrid: broad keyword filter | Server-side keyword (`subject:(receipt OR ...)`). Captures unknown merchants but many false positives. | |

**User's choice:** Server-side from:sender list — refined: ALSO flag other possibilities based on keywords; allow user to promote candidates into the default list.
**Notes:** Refinement triggered Q2 below (discovery loop). The hybrid keyword approach is incorporated as the DiscoveryScanJob's query, separated from the primary scan.

### Q2 — How does the discovery / promotion flow work for finding receipt senders not yet in the known list?

| Option | Description | Selected |
|--------|-------------|----------|
| DB-backed mutable list + discovery scan | `known_senders` + `discovered_senders` tables; daily DiscoveryScanJob with keyword query; user promotes via UI. | ✓ |
| Hardcoded list + discovery flag | Senders in code; discovery dumps to notifications; user edits code to add. | |
| Single table with state machine | One `senders` table with state enum. Marginal saving. | |

**User's choice:** DB-backed mutable list + discovery scan.
**Notes:** Locks D-120 + D-121. Discovery scan writes only sender metadata (no .eml).

### Q3 — How does the backfill job report progress and handle rate limits during the initial fetch?

| Option | Description | Selected |
|--------|-------------|----------|
| Chunked + progress in DB | Pages of 100 messages, ShouldBeUniqueUntilProcessing per inbox_id, progress JSON, wire:poll UI. | ✓ |
| Single long-running job | One job through the whole window. Single failure forces re-fetch. | |
| Page-jobs (one job per page) | N small jobs orchestrated. Harder to enforce per-inbox single-flight. | |

**User's choice:** Chunked + progress in DB.
**Notes:** Locks D-122. Per-inbox single-flight allows parallel backfill across providers but not within one inbox.

---

## Inbox Connection UX

### Q1 — Where does the inbox-connection + health surface live in the UI?

| Option | Description | Selected |
|--------|-------------|----------|
| Dedicated /inboxes top-nav page | New top-nav item; single page owns everything. Status badge per row. | |
| Settings page extension | Extend `/settings` with an Inboxes section. | |
| Inboxes top-nav + dashboard tile | Dedicated /inboxes page PLUS dashboard "Email scan health" tile. | ✓ |

**User's choice:** Inboxes top-nav + dashboard tile.
**Notes:** Locks D-124 + D-125. Dashboard tile follows the Phase 5 "Next ICS settlement" tile pattern.

### Q2 — How do unreviewed discovered senders + failed inboxes surface to draw the user's attention?

| Option | Description | Selected |
|--------|-------------|----------|
| Top-nav badge + tile badge | Nav count badge + tile-level status dot (red/gray/green). No spammy toasts. | ✓ |
| Top-nav badge only | Just the nav badge; calmer but less visible. | |
| Toast on first discovery + nav badge | Toast on each first-detection. Risk of toast fatigue during backfill. | |

**User's choice:** Top-nav badge + tile badge.
**Notes:** Locks D-126. Mirrors Phase 5's `/chains/review` badge via View Factory composer (issue #12 fix carry-forward).

### Q3 — What does the /inboxes page show on first visit when no OAuth client is configured and no inboxes exist?

| Option | Description | Selected |
|--------|-------------|----------|
| Onboarding-style hero | Centered hero with two big Connect buttons; OAuth-client modal on first click. | ✓ |
| Always-show table layout | Empty table + Add inbox card. Less inviting. | |
| Wizard route | `/inboxes/setup` multi-step wizard. Extra route to maintain. | |

**User's choice:** Onboarding-style hero.
**Notes:** Locks D-127.

### Q4 — Where does the user pick the backfill window (1–12 months, default 3) per EML-04?

| Option | Description | Selected |
|--------|-------------|----------|
| After OAuth + editable in row | Modal opens after OAuth callback; inline edit on each inbox row thereafter. | ✓ |
| Always default to 3, edit later | No post-OAuth modal; user might miss the option. | |
| Embedded in OAuth-completion screen | Picker inline on the OAuth callback success page. | |

**User's choice:** After OAuth + editable in row.
**Notes:** Locks D-128. Extending window re-queues backfill from current-oldest-fetched backwards.

---

## Claude's Discretion

Areas where the planner takes the next decision (captured as D-133 through D-140 in CONTEXT.md):

- Google + Microsoft SDK selection (path a: official SDKs; path b: thin Guzzle wrappers) — D-133
- OAuth-client modal "edit existing" vs "reset only" semantics — D-134
- DiscoveryScanJob promotion threshold tuning — D-135
- UI-SPEC pass on `/inboxes` Flux components — D-136
- Default scan cadence (`IncrementalScanJob` hourly + `DiscoveryScanJob` daily suggested) — D-137
- Whether to layer Laravel `Crypt::encrypt()` on the chmod-600 secrets file — D-138
- Whether `sender_email` is normalized at write or query time — D-139
- Wave 0 synthesised fixtures + fake API clients — D-140

## Deferred Ideas

Captured in `06-CONTEXT.md` `<deferred>` section. Highlights:

- Verified-app distribution path (v2+, if multi-household distribution becomes a goal)
- Gmail Watch / Graph push subscriptions (require public webhook URL; impossible on local-only)
- iCloud Mail integration (out of scope per PROJECT.md)
- OAuth password / app-password fallback (killed by Google for free accounts)
- Encryption-at-rest on chmod-600 JSON (defense-in-depth; planner picks)
- macOS Keychain integration (v2 enhancement; chmod-600 file suffices for v1)
- Multi-folder scan support (schema is multi-folder-ready; default ships INBOX-only)
- Per-inbox throttling overrides (single backoff policy per provider for v1)
- Drag-to-reorder inboxes on `/inboxes` (v2 nicety)
- Manual "Scan now" with ad-hoc filters (v2 power-user feature)
- Push-style real-time delivery via ngrok / cloudflared tunnel (local-only constraint forbids)
- `.eml` / `.mbox` drop-in import path (Phase 7, EML-07)
- Per-sender template matchers + transaction creation (Phase 7, EML-05)
